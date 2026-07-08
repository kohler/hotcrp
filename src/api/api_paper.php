<?php
// api_paper.php -- HotCRP paper API call
// Copyright (c) 2008-2026 Eddie Kohler; see LICENSE.

class Paper_API_Status implements JsonSerializable {
    /** @var bool */
    public $valid;
    /** @var list<string> */
    public $change_list;
    /** @var bool */
    public $conflict;
    /** @var null|int|'new' */
    public $pid;

    function __construct($valid = false, $change_list = [], $conflict = false, $pid = null) {
        $this->valid = $valid;
        $this->change_list = $change_list;
        $this->conflict = $conflict;
        $this->pid = $pid;
    }

    #[\ReturnTypeWillChange]
    function jsonSerialize() {
        $u = ["valid" => $this->valid, "change_list" => $this->change_list];
        if ($this->conflict) {
            $u["conflict"] = $this->conflict;
        }
        if ($this->pid !== null) {
            $u["pid"] = $this->pid;
        }
        return $u;
    }
}

class Paper_API extends MessageSet {
    /** @var Conf
     * @readonly */
    public $conf;
    /** @var Contact
     * @readonly */
    public $user;
    /** @var bool */
    private $dry_run = false;
    /** @var bool */
    private $notify = true;
    /** @var bool */
    private $notify_authors = true;
    /** @var ?string */
    private $notify_reason;
    /** @var bool */
    private $disable_users = false;
    /** @var ?int */
    private $if_unmodified_since;
    /** @var bool */
    private $single = false;
    /** @var DocumentLocator */
    private $docloc;

    /** @var list<Paper_API_Status> */
    private $status_list = [];
    /** @var list<object> */
    private $papers = [];
    /** @var int */
    private $npapers = 0;
    /** @var null|int|string */
    private $landmark;


    const PIDFLAG_IGNORE_PID = 1;
    const PIDFLAG_MATCH_TITLE = 2;

    function __construct(Contact $user) {
        $this->conf = $user->conf;
        $this->user = $user;
    }

    /** @return JsonResult */
    static private function run_get_one(Contact $user, Qrequest $qreq, PaperInfo $prow) {
        $pj = (new PaperExport($user))->paper_json($prow);
        assert(!!$pj);
        return new JsonResult(["ok" => true, "paper" => $pj]);
    }

    /** @param array<string,mixed> $args
     * @return array{PaperSearch,PaperInfoSet} */
    static function make_search(Contact $user, Qrequest $qreq, $args = []) {
        $qreq->t = $qreq->t ?? "viewable";
        $srch = new PaperSearch($user, $qreq);
        if (friendly_boolean($qreq->warn_missing)) {
            $srch->set_warn_missing(true);
        }
        $prows = $srch->user->paper_set([
            "paperId" => $srch->paper_ids(),
            "options" => true, "topics" => true, "allConflictType" => true
        ] + $args);
        $prows->sort_by_search($srch);
        return [$srch, $prows];
    }

    /** @return JsonResult */
    static private function run_get_multi(Contact $user, Qrequest $qreq) {
        if (!isset($qreq->q)) {
            return JsonResult::make_missing_error("q");
        }
        list($srch, $prows) = self::make_search($user, $qreq);

        $pex = new PaperExport($user);
        $pjs = [];
        foreach ($prows as $prow) {
            if (($pj = $pex->paper_json($prow)))
                $pjs[] = $pj;
        }

        return new JsonResult([
            "ok" => true,
            "message_list" => $srch->message_list_with_default_field("q"),
            "papers" => $pjs
        ]);
    }

    /** @param 1|2|3 $mode
     * @return JsonResult */
    private function run_post(Qrequest $qreq, ?PaperInfo $prow, $mode) {
        // check `p` parameter
        if (($mode & DocumentLocator::M_ONE) !== 0 && isset($qreq->p)) {
            $mode = DocumentLocator::M_ONE;
            if ($qreq->p === "") {
                unset($qreq->p);
            } else {
                $qreq->p = (string) $qreq->p;
                if (!ctype_digit($qreq->p) && $qreq->p !== "new") {
                    return JsonResult::make_parameter_error("p");
                }
            }
        }

        // set parameters
        $this->set_post_param($qreq);
        $this->docloc = new DocumentLocator;

        // check Content-Type
        if (Mimetype::is_form($qreq->body_content_type())
            && !$this->post_form_is_json($qreq)) {
            // handle form-encoded data
            if (($mode & DocumentLocator::M_ONE) === 0) {
                return JsonResult::make_error(400, "<0>Unexpected content type");
            }
            return $this->run_post_form_data($qreq, $prow);
        }

        // extract uploaded JSON
        list($jp, $mode) = $this->docloc->parse_json_request($qreq, $mode);
        if ($mode === DocumentLocator::M_ONE) {
            return $this->run_post_single_json($prow, $jp, $qreq->p);
        } else if (!$this->user->privChair) {
            return JsonResult::make_permission_error();
        } else if ($mode === DocumentLocator::M_MATCH) {
            return $this->run_post_match_json($qreq, $jp);
        }
        return $this->run_post_multi_json($jp);
    }

    private function set_post_param(Qrequest $qreq) {
        $this->dry_run = friendly_boolean($qreq->dry_run) ?? false;
        if ($this->user->privChair) {
            if (friendly_boolean($qreq->disable_users)) {
                $this->disable_users = true;
            }
            if (friendly_boolean($qreq->add_topics)) {
                $this->conf->topic_set()->set_auto_add(true);
                $this->conf->options()->refresh_topics();
            }
        }
        // these record requests to suppress notifications; whether they are
        // honored is decided per paper (execute_save())
        $this->notify = friendly_boolean($qreq->notify) !== false;
        $this->notify_authors = friendly_boolean($qreq->notify_authors) !== false;
        $this->notify_reason = $qreq->reason ?? "";
        // parse single-paper precondition
        if (isset($qreq->if_unmodified_since)) {
            $t = self::parse_if_unmodified_since($qreq->if_unmodified_since, $this->conf);
            if ($t === false) {
                JsonResult::make_parameter_error("if_unmodified_since")->complete();
            }
            $this->if_unmodified_since = $t;
        }
    }

    /** Parse an `if_unmodified_since` value (a Unix timestamp or a parsable
     * time string; `0` is valid) into a timestamp.
     * @param int|string $v
     * @return int|false false on a malformed value */
    static function parse_if_unmodified_since($v, Conf $conf) {
        if (is_int($v)) {
            $t = $v;
        } else if (ctype_digit($v)) {
            $t = intval($v);
        } else {
            $t = $conf->parse_time($v, Conf::$now);
        }
        return $t === false || $t < 0 ? false : $t;
    }

    /** Fold the flat `if_unmodified_since` parameter into a paper's
     * `status.if_unmodified_since`, so PaperStatus performs the check. It is a
     * per-paper backup: an explicit value in the paper's own JSON wins. Applied
     * to every paper of a single, multi, or match request.
     * @param object $jp */
    private function apply_if_unmodified_since($jp) {
        if ($this->if_unmodified_since !== null
            && !isset($jp->if_unmodified_since)
            && (!is_object($jp->status ?? null) || !isset($jp->status->if_unmodified_since))) {
            $jp->if_unmodified_since = $this->if_unmodified_since;
        }
    }


    /** @return bool */
    private function post_form_is_json(Qrequest $qreq) {
        if (!isset($qreq->json) && !isset($qreq->upload)) {
            return false;
        }
        foreach ($qreq as $k => $v) {
            if (str_starts_with($k, "has_") || str_starts_with($k, "status:")) {
                return false;
            }
        }
        return true;
    }

    /** @return JsonResult */
    private function run_post_form_data(Qrequest $qreq, ?PaperInfo $prow) {
        if (!$prow) {
            if (!isset($qreq->p)) {
                return JsonResult::make_missing_error("p");
            } else if ($qreq->p === "new") {
                $pid = null;
            } else {
                $pid = intval($qreq->p);
                if ((string) $pid !== $qreq->p
                    || $pid === 0
                    || $pid > PaperInfo::PID_MAX) {
                    return JsonResult::make_parameter_error("p");
                }
            }
            if (isset($qreq->sclass)
                && !$this->conf->submission_round_by_tag($qreq->sclass, true)) {
                return JsonResult::make_message_list(MessageItem::error($this->conf->_("<0>{Submission} class ‘{}’ not found", $qreq->sclass)));
            }
            $prow = PaperInfo::make_new($this->user, $qreq->sclass);
            if ($pid !== null) {
                $prow->set_prop("paperId", $pid);
            }
        }

        $this->single = true;
        if ($this->if_unmodified_since !== null
            && $qreq["if_unmodified_since"] === null
            && $qreq["status:if_unmodified_since"] === null) {
            $qreq->set("if_unmodified_since", (string) $this->if_unmodified_since);
        }
        $ps = $this->paper_status();
        $ok = $ps->prepare_save_paper_web($qreq, $prow);
        $this->execute_save($ok, $ps);
        return $this->post_result();
    }

    /** @return JsonResult */
    private function run_post_single_json(?PaperInfo $prow, $jp, $parg) {
        $this->single = true;
        if (is_string($parg) && ctype_digit($parg)) {
            $parg = intval($parg);
        }
        if ($this->set_json_landmark(0, $jp, $parg)) {
            $this->apply_if_unmodified_since($jp);
            $ps = $this->paper_status();
            $ok = $ps->prepare_save_paper_json($jp, $prow);
            $this->execute_save($ok, $ps);
        } else {
            $this->execute_fail();
        }
        return $this->post_result();
    }

    /** @param array $jps
     * @return JsonResult */
    private function run_post_multi_json($jps) {
        $this->single = false;
        foreach ($jps as $i => $jp) {
            if ($this->set_json_landmark($i, $jp, null)) {
                $this->apply_if_unmodified_since($jp);
                $ps = $this->paper_status();
                $ok = $ps->prepare_save_paper_json($jp);
                $this->execute_save($ok, $ps);
            } else {
                $this->execute_fail();
            }
        }
        return $this->post_result();
    }

    /** @param object $jp
     * @return JsonResult */
    private function run_post_match_json(Qrequest $qreq, $jp) {
        if (isset($jp->pid) || isset($jp->id)) {
            return JsonResult::make_error(400, "<0>Unexpected `pid`");
        } else if (!isset($qreq->q)) {
            return JsonResult::make_missing_error("q");
        }
        $this->single = false;
        $this->apply_if_unmodified_since($jp);
        list($srch, $prows) = self::make_search($this->user, $qreq);
        $i = 0;
        foreach ($prows as $prow) {
            $this->landmark = $i;
            $ps = $this->paper_status();
            $ok = $ps->prepare_save_paper_json($jp, $prow);
            $this->execute_save($ok, $ps);
            ++$i;
        }
        return $this->post_result();
    }


    /** @return PaperStatus */
    private function paper_status() {
        return (new PaperStatus($this->user))
            ->set_disable_users($this->disable_users)
            ->set_notify_reason($this->notify_reason)
            ->set_any_content_file(true)
            ->on_document_import([$this->docloc, "on_document_import"]);
    }

    /** @param PaperStatus $ps */
    private function execute_save($ok, $ps) {
        // A `notify`/`notify_authors` suppression request is honored only for a
        // user who administers the paper. The paper is fully resolved only once
        // prepare has run (its id may arrive in the JSON body, not `p`), so
        // apply the flags here. Management covers new papers too: a site chair
        // manages any paper, an ordinary author manages none.
        $manage = $ps->can_user_manage();
        $ps->set_notify($this->notify || !$manage)
            ->set_notify_authors($this->notify_authors || !$manage);
        if ($ok && !$this->dry_run) {
            $ok = $ps->execute_save();
        } else {
            $ps->abort_save();
        }
        foreach ($ps->message_list() as $mi) {
            if ($mi->field
                && str_ends_with($mi->field, ":context")
                && !str_starts_with($mi->field, "status:")) {
                continue;
            }
            if (!$this->single) {
                $mi->landmark = $this->landmark;
            }
            $this->append_item($mi);
        }
        if ($ok && !$this->dry_run) {
            if ($ps->has_change()) {
                $this->execute_change($ps);
            }
            $this->papers[] = (new PaperExport($this->user))
                ->paper_json($ps->saved_prow());
            ++$this->npapers;
        } else {
            $this->papers[] = null;
        }
        $this->status_list[] = new Paper_API_Status(
            $ok, $ps->changed_keys(true),
            $ps->has_error_at("if_unmodified_since"),
            $ps->saved_pid() ?? "new"
        );
    }

    /** @param PaperStatus $ps */
    private function execute_change($ps) {
        $ps->log_save_activity("via API");
        if ($ps->notify) {
            $ps->notify_followers();
        }
    }

    private function execute_fail() {
        $this->status_list[] = new Paper_API_Status;
        $this->papers[] = null;
    }

    /** @return JsonResult */
    private function post_result() {
        $ok = empty($this->status_list)
            || array_find($this->status_list, function ($x) { return !!$x->valid; });
        $jr = new JsonResult([
            "ok" => $ok,
            "message_list" => $this->message_list()
        ]);
        if ($this->dry_run) {
            $jr->content["dry_run"] = true;
        }
        if ($this->single) {
            foreach ($this->status_list[0]->jsonSerialize() as $k => $v) {
                $jr->content[$k] = $v;
            }
            if ($this->npapers > 0) {
                $jr->content["paper"] = $this->papers[0];
            }
        } else {
            $jr->content["status_list"] = $this->status_list;
            if (!$this->dry_run) {
                $jr->content["papers"] = $this->papers;
            }
        }
        return $jr;
    }


    /** @param object $j
     * @param 0|1|2|3 $pidflags
     * @return null|int|'new' */
    static function analyze_json_pid(Conf $conf, $j, $pidflags = 0) {
        if (($pidflags & self::PIDFLAG_IGNORE_PID) !== 0) {
            if (isset($j->pid)) {
                $j->__original_pid = $j->pid;
            }
            unset($j->pid, $j->id);
        }
        if (!isset($j->pid)
            && !isset($j->id)
            && ($pidflags & self::PIDFLAG_MATCH_TITLE) !== 0
            && is_string($j->title ?? null)) {
            // XXXX multiple titles, look up only once?
            $pids = Dbl::fetch_first_columns($conf->dblink, "select paperId from Paper where title=?", simplify_whitespace($j->title));
            if (count($pids) === 1) {
                $j->pid = (int) $pids[0];
            }
        }
        $pid = $j->pid ?? $j->id ?? null;
        if ($pid === null
            || $pid === "new"
            || (is_int($pid) && $pid > 0 && $pid <= PaperInfo::PID_MAX)) {
            return $pid;
        }
        throw new ErrorException;
    }

    /** @param int $index
     * @param object $jp
     * @param ?int $expected
     * @return bool */
    private function set_json_landmark($index, $jp, $expected = null) {
        try {
            $pidish = self::analyze_json_pid($this->conf, $jp, 0);
            if ($pidish === null && $expected !== null) {
                $jp->pid = $expected;
            }
            if (($pidish ?? $expected) === ($expected ?? $pidish)) {
                $this->landmark = $index;
                return true;
            }
            $msg = "<0>ID mismatch";
        } catch (ErrorException $ex) {
            $msg = "<0>Format error";
        }
        $pidkey = isset($jp->pid) || !isset($jp->id) ? "pid" : "id";
        $mi = $this->error_at($pidkey, $msg);
        if (!$this->single) {
            $mi->landmark = $index;
        }
        return false;
    }


    /** @return JsonResult */
    private function run_delete(Qrequest $qreq, ?PaperInfo $prow) {
        if (!$prow) {
            return JsonResult::make_missing_error("p");
        }

        $this->set_post_param($qreq);
        $this->single = true;

        if (!$this->user->can_manage($prow)) {
            return JsonResult::make_permission_error(null, "<0>Only administrators can permanently delete {$this->conf->snouns[1]}");
        }

        $conflict = $this->if_unmodified_since !== null
            && $this->if_unmodified_since < $prow->timeModified;
        if ($conflict) {
            $this->error_at("if_unmodified_since", $this->conf->_("<5><strong>Edit conflict</strong>: The {submission} has changed"));
            $valid = false;
        } else if ($this->dry_run) {
            $valid = true;
        } else {
            if ($this->notify && $this->notify_authors) {
                HotCRPMailer::send_contacts("@deletepaper", $prow, [
                    "reason" => (string) $this->notify_reason,
                    "confirm_message_for" => $this->user
                ]);
            }
            $valid = $prow->delete_from_database($this->user);
        }
        $this->status_list[] = new Paper_API_Status($valid, ["delete"], $conflict, $prow->paperId);
        $this->papers[] = null;
        return $this->post_result();
    }


    /** @return JsonResult */
    static private function run(Contact $user, Qrequest $qreq, ?PaperInfo $prow, $mode) {
        $old_overrides = $user->overrides();
        if (friendly_boolean($qreq->forceShow) !== false) {
            $user->add_overrides(Contact::OVERRIDE_CONFLICT);
        }
        try {
            if ($qreq->is_getlike()) {
                if ($mode === DocumentLocator::M_ONE) {
                    $jr = self::run_get_one($user, $qreq, $prow);
                } else {
                    $jr = self::run_get_multi($user, $qreq);
                }
            } else if ($qreq->method() === "DELETE") {
                $jr = (new Paper_API($user))->run_delete($qreq, $prow);
            } else {
                $jr = (new Paper_API($user))->run_post($qreq, $prow, $mode);
            }
        } catch (JsonCompletion $jc) {
            $jr = $jc->result;
        }
        $user->set_overrides($old_overrides);
        if (($jr->content["message_list"] ?? null) === []) {
            unset($jr->content["message_list"]);
        }
        return $jr;
    }

    /** @return JsonResult */
    static function run_one(Contact $user, Qrequest $qreq, ?PaperInfo $prow) {
        return self::run($user, $qreq, $prow, DocumentLocator::M_ONE);
    }

    /** @return JsonResult */
    static function run_multi(Contact $user, Qrequest $qreq) {
        return self::run($user, $qreq, null, DocumentLocator::M_MULTI | DocumentLocator::M_MATCH);
    }
}
