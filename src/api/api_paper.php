<?php
// api_paper.php -- HotCRP paper API call
// Copyright (c) 2008-2024 Eddie Kohler; see LICENSE.

class Paper_API extends MessageSet {
    /** @var Conf
     * @readonly */
    public $conf;
    /** @var Contact
     * @readonly */
    public $user;
    /** @var bool */
    private $notify = true;
    /** @var bool */
    private $disable_users = false;
    /** @var bool */
    private $dry_run = false;
    /** @var bool */
    private $single = false;
    /** @var ?ZipArchive */
    private $ziparchive;
    /** @var ?Qrequest */
    private $attachment_qreq;
    /** @var ?string */
    private $docdir;

    /** @var bool */
    private $ok = true;
    /** @var list<list<string>> */
    private $change_lists = [];
    /** @var list<object> */
    private $papers = [];
    /** @var list<bool> */
    private $valid = [];
    /** @var int */
    private $npapers = 0;
    /** @var null|int|string */
    private $landmark;


    const M_ONE = 1;
    const M_MULTI = 2;
    const M_MATCH = 4;

    const PIDFLAG_IGNORE_PID = 1;
    const PIDFLAG_MATCH_TITLE = 2;

    function __construct(Contact $user) {
        $this->conf = $user->conf;
        $this->user = $user;
    }

    /** @return array{PaperSearch,PaperInfoSet} */
    static private function make_search(Contact $user, Qrequest $qreq) {
        $qreq->t = $qreq->t ?? "viewable";
        $srch = new PaperSearch($user, $qreq);
        if (friendly_boolean($qreq->warn_missing)) {
            $srch->set_warn_missing(true);
        }
        $prows = $srch->user->paper_set([
            "paperId" => $srch->paper_ids(),
            "options" => true, "topics" => true, "allConflictType" => true
        ]);
        if ($srch->nontrivial_sort()) {
            $pidmap = array_flip($srch->sorted_paper_ids());
            $prows->sort_by(function ($a, $b) use ($pidmap) {
                return $pidmap[$a->paperId] <=> $pidmap[$b->paperId];
            });
        }
        return [$srch, $prows];
    }

    /** @param 1|2|3 $mode
     * @return JsonResult */
    static function run_get(Contact $user, Qrequest $qreq, ?PaperInfo $prow, $mode) {
        if (isset($qreq->p) && ($mode & self::M_ONE) !== 0) {
            if ($prow && ($pj = (new PaperExport($user))->paper_json($prow))) {
                return new JsonResult(["ok" => true, "paper" => $pj]);
            }
            $fr = $prow ? $user->perm_view_paper($prow) : $qreq->annex("paper_whynot");
            return Conf::paper_error_json_result($fr);
        } else if ($mode === self::M_ONE) {
            return JsonResult::make_missing_error("p");
        } else if (!isset($qreq->q)) {
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
            "message_list" => $srch->message_list(),
            "papers" => $pjs
        ]);
    }

    /** @param 1|2|3 $mode
     * @return JsonResult */
    private function run_post(Qrequest $qreq, ?PaperInfo $prow, $mode) {
        // check `p` parameter
        if (($mode & self::M_ONE) !== 0 && isset($qreq->p)) {
            $mode = self::M_ONE;
            if ($qreq->p === "") {
                unset($qreq->p);
            } else {
                $qreq->p = (string) $qreq->p;
                if (!ctype_digit($qreq->p) && $qreq->p !== "new") {
                    return JsonResult::make_parameter_error("p");
                }
            }
        }

        // check `q` parameter
        if ($mode === self::M_MULTI && !isset($qreq->q)) {
            return JsonResult::make_missing_error("q");
        }

        // set parameters
        if ($this->user->privChair) {
            if (friendly_boolean($qreq->disable_users)) {
                $this->disable_users = true;
            }
            if (friendly_boolean($qreq->notify) === false) {
                $this->notify = false;
            }
            if (friendly_boolean($qreq->add_topics)) {
                $this->conf->topic_set()->set_auto_add(true);
                $this->conf->options()->refresh_topics();
            }
        }
        if (friendly_boolean($qreq->dry_run ?? $qreq->dryrun)) {
            $this->dry_run = true;
        }

        // check Content-Type
        $ct = $qreq->body_content_type();
        $ct_form = $ct === "application/x-www-form-urlencoded"
            || $ct === "multipart/form-data";
        if ($ct_form && !$this->post_form_is_json($qreq)) {
            // handle form-encoded data
            if (($mode & self::M_ONE) !== 0) {
                return $this->run_post_form_data($qreq, $prow);
            } else {
                return JsonResult::make_error(400, "<0>Unexpected content type");
            }
        }

        // from here on, expect JSON
        if ($ct === "application/json") {
            $jsonstr = $qreq->body();
        } else if ($ct === "application/zip") {
            $this->ziparchive = new ZipArchive;
            $cf = $qreq->body_filename(".zip");
            if (!$cf) {
                return JsonResult::make_error(500, "<0>Uploaded content unreadable");
            }
            $ec = $this->ziparchive->open($cf);
            if ($ec !== true) {
                return JsonResult::make_error(400, "<0>ZIP error " . json_encode($ec));
            }
            list($this->docdir, $jsonname) = self::analyze_zip_contents($this->ziparchive);
            if (!$jsonname) {
                return JsonResult::make_error(400, "<0>ZIP `data.json` not found");
            }
            $jsonstr = $this->ziparchive->getFromName($jsonname);
        } else if ($ct_form) {
            $jsonstr = $qreq->json;
            $this->attachment_qreq = $qreq;
        } else {
            return JsonResult::make_error(400, "<0>Unexpected content type");
        }

        // read JSON, check format
        $jp = Json::try_decode($jsonstr);
        if (is_object($jp)) {
            if (isset($qreq->q)
                && ($mode & self::M_MATCH) !== 0) {
                $mode = self::M_MATCH;
            } else if (($mode & self::M_ONE) !== 0) {
                $mode = self::M_ONE;
            } else {
                $jp = [$jp];
                $mode = self::M_MULTI;
            }
        } else if (is_array($jp)) {
            if (($mode & self::M_MULTI) !== 0) {
                $mode = self::M_MULTI;
            } else if (($mode & self::M_ONE) !== 0
                       && count($jp) === 1
                       && is_object($jp[0])) {
                $jp = $jp[0];
                $mode = self::M_ONE;
            } else {
                return JsonResult::make_error(400, "<0>Expected object");
            }
        } else if ($jp === null) {
            return JsonResult::make_error(400, "<0>Invalid JSON (" . Json::last_error_msg() . ")");
        } else {
            return JsonResult::make_error(400, $mode === self::M_MULTI ? "<0>Expected array of objects" : "<0>Expected object");
        }

        // process result
        if ($mode === self::M_ONE) {
            return $this->run_post_single_json($prow, $jp);
        } else if (!$this->user->privChair) {
            return JsonResult::make_permission_error();
        } else if ($mode === self::M_MATCH) {
            return $this->run_post_match_json($qreq, $jp);
        } else {
            return $this->run_post_multi_json($jp);
        }
    }


    /** @return bool */
    private function post_form_is_json(Qrequest $qreq) {
        if (!isset($qreq->json)) {
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
        $ps = $this->paper_status();
        $ok = $ps->prepare_save_paper_web($qreq, $prow);
        $this->execute_save($ok, $ps);
        return $this->make_result();
    }

    /** @return JsonResult */
    private function run_post_single_json(?PaperInfo $prow, $jp) {
        $this->single = true;
        if ($this->set_json_landmark(0, $jp, $prow ? $prow->paperId : null)) {
            $ps = $this->paper_status();
            $ok = $ps->prepare_save_paper_json($jp, $prow);
            $this->execute_save($ok, $ps);
        } else {
            $this->execute_fail();
        }
        return $this->make_result();
    }

    /** @param array $jps
     * @return JsonResult */
    private function run_post_multi_json($jps) {
        $this->single = false;
        foreach ($jps as $i => $jp) {
            if ($this->set_json_landmark($i, $jp, null)) {
                $ps = $this->paper_status();
                $ok = $ps->prepare_save_paper_json($jp);
                $this->execute_save($ok, $ps);
            } else {
                $this->execute_fail();
            }
        }
        return $this->make_result();
    }

    /** @param object $jp
     * @return JsonResult */
    private function run_post_match_json(Qrequest $qreq, $jp) {
        if (isset($jp->pid) || isset($jp->id)) {
            return JsonResult::make_error(400, "<0>Unexpected `pid`");
        }
        $this->single = false;
        list($srch, $prows) = self::make_search($this->user, $qreq);
        $i = 0;
        foreach ($prows as $prow) {
            $this->landmark = $i;
            $ps = $this->paper_status();
            $ok = $ps->prepare_save_paper_json($jp, $prow);
            $this->execute_save($ok, $ps);
            ++$i;
        }
        return $this->make_result();
    }


    /** @return PaperStatus */
    private function paper_status() {
        return (new PaperStatus($this->user))
            ->set_disable_users($this->disable_users)
            ->set_notify($this->notify)
            ->set_any_content_file(true)
            ->on_document_import([$this, "on_document_import"]);
    }

    /** @param PaperStatus $ps */
    private function execute_save($ok, $ps) {
        if ($ok && !$this->dry_run) {
            $ok = $ps->execute_save();
        }
        foreach ($ps->message_list() as $mi) {
            if (!$this->single) {
                $mi->landmark = $this->landmark;
            }
            $this->append_item($mi);
        }
        $this->change_lists[] = $ps->changed_keys(true);
        if ($ok && !$this->dry_run) {
            if ($ps->has_change()) {
                $ps->log_save_activity("via API");
            }
            $pj = (new PaperExport($this->user))->paper_json($ps->saved_prow());
            $this->papers[] = $pj;
            ++$this->npapers;
        } else {
            $this->papers[] = null;
        }
        $this->valid[] = $ok;
        $this->ok = $this->ok && $ok;
    }

    private function execute_fail() {
        $this->ok = false;
        $this->change_lists[] = null;
        $this->papers[] = null;
        $this->valid[] = false;
    }

    /** @return JsonResult */
    private function make_result() {
        $jr = new JsonResult([
            "ok" => $this->ok,
            "message_list" => $this->message_list()
        ]);
        if ($this->dry_run) {
            $jr->content["dry_run"] = true;
        }
        if ($this->single) {
            $jr->content["change_list"] = $this->change_lists[0];
            $jr->content["valid"] = $this->valid[0];
            if ($this->npapers > 0) {
                $jr->content["paper"] = $this->papers[0];
            }
        } else {
            $jr->content["change_lists"] = $this->change_lists;
            $jr->content["valid"] = $this->valid;
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
            || (is_int($pid) && $pid > 0 && $pid <= PaperInfo::PID_MAX)
            || $pid === "new") {
            return $pid ?? "new";
        } else {
            return null;
        }
    }

    private function set_json_landmark($index, $jp, $expected = null) {
        $pidish = self::analyze_json_pid($this->conf, $jp, 0);
        if ($pidish && ($expected === null || $pidish === $expected)) {
            $this->landmark = $index;
            return true;
        }
        $pidkey = isset($jp->pid) || !isset($jp->id) ? "pid" : "id";
        $msg = $pidish ? "<0>ID does not match" : "<0>Format error";
        $mi = $this->error_at($pidkey, $msg);
        if (!$this->single) {
            $mi->landmark = $index;
        }
        return false;
    }


    /** @return array{string,?string} */
    static function analyze_zip_contents($zip) {
        // find common directory prefix
        $dirpfx = null;
        for ($i = 0; $i < $zip->numFiles; ++$i) {
            $name = $zip->getNameIndex($i);
            if ($dirpfx === null) {
                $xslash = (int) strrpos($name, "/");
                $dirpfx = $xslash ? substr($name, 0, $xslash + 1) : "";
            }
            while ($dirpfx !== "" && !str_starts_with($name, $dirpfx)) {
                $xslash = (int) strrpos($dirpfx, "/", -1);
                $dirpfx = $xslash ? substr($dirpfx, 0, $xslash + 1) : "";
            }
            if ($dirpfx === "") {
                break;
            }
        }

        // find JSONs
        $datas = $jsons = [];
        for ($i = 0; $i < $zip->numFiles; ++$i) {
            $name = $zip->getNameIndex($i);
            if (!str_ends_with($name, ".json")
                || strpos($name, "/", strlen($dirpfx)) !== false
                || $name[strlen($dirpfx)] === ".") {
                continue;
            }
            $jsons[] = $name;
            if (preg_match('/\G(?:|.*[-_])data\.json\z/', $name, $m, 0, strlen($dirpfx))) {
                $datas[] = $name;
            }
        }

        if (count($datas) === 1) {
            return [$dirpfx, $datas[0]];
        } else if (count($jsons) === 1) {
            return [$dirpfx, $jsons[0]];
        } else {
            return [$dirpfx, null];
        }
    }

    /** @param object $docj
     * @param string $filename
     * @return bool */
    static function apply_zip_content_file($docj, $filename, ZipArchive $zip,
                                           PaperOption $o, PaperStatus $pstatus) {
        $stat = $zip->statName($filename);
        if (!$stat) {
            $pstatus->error_at_option($o, "{$filename}: File not found");
            return false;
        }
        // use resources to store large files
        if ($stat["size"] > 50000000) {
            if (PHP_VERSION_ID >= 80200) {
                $content = $zip->getStreamIndex($stat["index"]);
            } else {
                $content = $zip->getStream($filename);
            }
        } else {
            $content = $zip->getFromIndex($stat["index"]);
        }
        if ($content === false) {
            $pstatus->error_at_option($o, "{$filename}: File not found");
            return false;
        }
        if (is_string($content)) {
            $docj->content = $content;
            $docj->content_file = null;
        } else {
            $docj->content_file = $content;
        }
        self::apply_docj_filename($docj, $filename);
        return true;
    }

    /** @param object $docj
     * @param QrequestFile $qf */
    static function apply_qrequest_file($docj, $qf) {
        if ($qf->content !== null) {
            $docj->content = $qf->content;
            $docj->content_file = null;
        } else {
            $docj->content_file = $qf->tmp_name;
        }
        if (!isset($docj->size) && isset($qf->size)) {
            $docj->size = $qf->size;
        }
        if (!isset($docj->mimetype) && isset($qf->type)) {
            $docj->mimetype = $qf->type;
        }
        if (!isset($docj->filename) && isset($qf->name)) {
            self::apply_docj_filename($docj, $qf->name);
        }
    }

    /** @param object $docj
     * @param string $filename */
    static function apply_docj_filename($docj, $filename) {
        if (!isset($docj->filename)) {
            $slash = strpos($filename, "/");
            $docj->filename = $slash !== false ? substr($filename, $slash + 1) : $filename;
        }
    }

    function on_document_import($docj, PaperOption $o, PaperStatus $pstatus) {
        if ($docj instanceof DocumentInfo
            || !isset($docj->content_file)) {
            return;
        }
        if (is_string($docj->content_file)) {
            if ($this->ziparchive) {
                return self::apply_zip_content_file($docj, $this->docdir . $docj->content_file, $this->ziparchive, $o, $pstatus);
            } else if ($this->attachment_qreq
                       && ($qf = $this->attachment_qreq->file($docj->content_file))) {
                return self::apply_qrequest_file($docj, $qf);
            }
        }
        unset($docj->content_file);
    }


    /** @return JsonResult */
    static private function run(Contact $user, Qrequest $qreq, ?PaperInfo $prow, $mode) {
        $old_overrides = $user->overrides();
        if (friendly_boolean($qreq->forceShow) !== false) {
            $user->add_overrides(Contact::OVERRIDE_CONFLICT);
        }
        if ($qreq->is_get()) {
            $jr = self::run_get($user, $qreq, $prow, $mode);
        } else {
            $jr = (new Paper_API($user))->run_post($qreq, $prow, $mode);
        }
        $user->set_overrides($old_overrides);
        if (($jr->content["message_list"] ?? null) === []) {
            unset($jr->content["message_list"]);
        }
        return $jr;
    }

    /** @return JsonResult */
    static function run_one(Contact $user, Qrequest $qreq, ?PaperInfo $prow) {
        return self::run($user, $qreq, $prow, self::M_ONE);
    }

    /** @return JsonResult */
    static function run_multi(Contact $user, Qrequest $qreq) {
        return self::run($user, $qreq, null, self::M_MULTI | self::M_MATCH);
    }
}
