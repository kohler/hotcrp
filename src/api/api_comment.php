<?php
// api_comment.php -- HotCRP comment API call
// Copyright (c) 2008-2026 Eddie Kohler; see LICENSE.

class Comment_API extends MessageSet {
    /** @var Conf */
    private $conf;
    /** @var Contact */
    private $user;
    /** @var PaperInfo */
    private $prow;

    /** @var ?int */
    private $qreq_cid;
    /** @var ?ResponseRound */
    private $qreq_rrd;

    /** @var int */
    private $status = 200;
    /** @var bool */
    private $dry_run = false;
    /** @var bool */
    private $ok = true;
    /** @var bool */
    private $valid = false;
    /** @var ?int */
    private $if_unmodified_since;
    /** @var ?list<string> */
    private $change_list;

    // information about current request
    /** @var ?ResponseRound */
    private $rrd;
    /** @var string */
    private $uccmttype;
    /** @var string */
    private $lccmttype;


    const M_ONE = 1;
    const M_MULTI = 2;

    const RESPONSE_REPLACED = 492;

    function __construct(Contact $user, PaperInfo $prow, Qrequest $qreq) {
        $this->conf = $user->conf;
        $this->user = $user;
        $this->prow = $prow;

        // analyze $qreq `c` and `response` parameters
        $c = $qreq->c;
        $response = $qreq->response;
        if ($c === null || $c === "") {
            if ($qreq->is_post() && !isset($response)) {
                $this->qreq_cid = 0;
            }
        } else if ($c === "new") {
            $this->qreq_cid = 0;
        } else if (ctype_digit($c) && !str_starts_with($c, "0")) {
            $this->qreq_cid = stoi($c);
            if ($this->qreq_cid === null) {
                JsonResult::make_not_found_error("c", "<0>Comment not found")->complete();
            }
        } else if (isset($c)) {
            if ($c === "response") {
                $response = "1";
            } else if (str_ends_with($c, "response")) {
                $response = substr($c, 0, -8);
            } else if (str_starts_with($c, "response")) {
                $response = substr($c, 8);
            } else {
                JsonResult::make_not_found_error("c", "<0>Comment not found")->complete();
            }
            if (isset($qreq->response)
                && strcasecmp($response, (string) $qreq->response) !== 0) {
                JsonResult::make_parameter_error("response", "<0>Parameter conflict with `c`")->complete();
            }
        }
        if (isset($response) && $response !== "") {
            $this->qreq_rrd = $this->conf->response_round($response);
            if (!$this->qreq_rrd) {
                JsonResult::make_not_found_error("response", "<0>Response not found")->complete();
            }
        }
    }

    private function run_get_one(Qrequest $qreq) {
        // a GET with no `c`/`response` behaves like `GET /comments`
        // (backward compat)
        if (!isset($qreq->c) && !isset($qreq->response)) {
            return self::run_get_multi($this->user, $qreq, $this->prow);
        }

        // find comment, check visibility and parameter conflict
        $crow = $this->resolve_comment();
        if (!$crow) {
            return JsonResult::make_not_found_error(isset($qreq->c) ? "c" : "response", "<0>{$this->uccmttype} not found");
        } else if (!$this->user->can_view_comment($this->prow, $crow, true)) {
            if ($this->user->can_view_submitted_review($this->prow)) {
                return JsonResult::make_error(403, "<0>You aren’t allowed to view that {$this->lccmttype}");
            }
            return JsonResult::make_error(404, "<0>{$this->uccmttype} not found");
        } else if ($this->status === self::RESPONSE_REPLACED) {
            return JsonResult::make_parameter_error("response", "<0>Parameter conflict with `c`");
        }

        // render and return
        $jr = new JsonResult($this->status, ["ok" => $this->ok && $this->status <= 299]);
        $no_content = friendly_boolean($qreq->content) === false;
        $jr["comment"] = $crow->unparse_json($this->user, $no_content);
        return $jr;
    }

    /** @return JsonResult */
    static private function run_get_multi(Contact $user, Qrequest $qreq, ?PaperInfo $prow) {
        $no_content = friendly_boolean($qreq->content) === false;
        if (isset($qreq->q)) {
            if (isset($qreq->p)) {
                return JsonResult::make_parameter_error("p", "<0>Parameter conflict with `q`");
            }
            list($srch, $prows) = Paper_API::make_search($user, $qreq);
            $ml = $srch->message_list_with_default_field("q");
        } else if ($prow) {
            $prows = PaperInfoSet::make_singleton($prow);
            $ml = [];
        } else if (isset($qreq->p)) {
            return Conf::paper_error_json_result($qreq->annex("paper_whynot"));
        } else {
            return JsonResult::make_missing_error("q");
        }

        $comments = [];
        foreach ($prows as $prow) {
            foreach ($prow->viewable_comments($user) as $crow) {
                $comments[] = $crow->unparse_json($user, $no_content);
            }
        }

        return new JsonResult([
            "ok" => true,
            "message_list" => $ml,
            "comments" => $comments
        ]);
    }


    /** @return ?CommentInfo */
    private function find_comment($query) {
        $cmts = $this->prow->fetch_comments($query);
        return $cmts[0] ?? null;
    }

    /** @param int $round
     * @return ?CommentInfo */
    private function find_response_by_id($round) {
        assert($round > 0);
        return $this->find_comment("(commentType&" . CommentInfo::CT_RESPONSE . ")!=0 and commentRound={$round}");
    }

    /** @return ?CommentInfo */
    private function resolve_comment() {
        $this->rrd = $this->qreq_rrd;
        $this->status = 200;

        // find comment
        $crow = null;
        if ($this->qreq_cid > 0) {
            $crow = $this->find_comment("commentId={$this->qreq_cid}");
        }
        if (!$crow && $this->qreq_rrd) {
            $crow = $this->find_response_by_id($this->qreq_rrd->id);
        }

        // if `new` or numeric ID provided, must match actual comment
        // (which is always true unless `$this->qreq_rrd`)
        if ($crow) {
            assert($this->qreq_cid === $crow->commentId || $this->qreq_rrd);
            $this->rrd = $crow->response_round();
            if (($this->qreq_rrd && $this->rrd !== $this->qreq_rrd)
                || ($this->qreq_cid !== null && $this->qreq_cid !== $crow->commentId)) {
                $this->status = self::RESPONSE_REPLACED;
            }
        }

        // set comment/response name
        if ($this->rrd) {
            if ($this->rrd->unnamed) {
                $this->uccmttype = "Response";
                $this->lccmttype = "response";
            } else {
                $this->uccmttype = $this->lccmttype = "{$this->rrd->name} response";
            }
        } else {
            $this->uccmttype = "Comment";
            $this->lccmttype = "comment";
        }
        return $crow;
    }

    /** @return MessageItem */
    private function save_success_message(CommentInfo $xcrow) {
        if (!$xcrow->commentId) {
            $action = "deleted";
        } else if ($this->rrd
                   && ($xcrow->commentType & CommentInfo::CT_DRAFT) === 0) {
            $action = "submitted";
        } else {
            $action = "saved";
        }
        return MessageItem::success("<0>{$this->uccmttype} {$action}");
    }


    /** @return JsonResult */
    private function run_post(Qrequest $qreq) {
        // parse upload encoding (form, JSON body, ZIP, or upload capability)
        if (!isset($qreq->text)
            && !friendly_boolean($qreq->delete)) {
            // form POST must carry comment content
            return JsonResult::make_parameter_error("text");
        }
        $this->dry_run = friendly_boolean($qreq->dry_run) === true;
        if (isset($qreq->if_unmodified_since)) {
            $ius = Paper_API::parse_if_unmodified_since($qreq->if_unmodified_since, $this->conf);
            if ($ius === false) {
                return JsonResult::make_parameter_error("if_unmodified_since");
            }
            $this->if_unmodified_since = $ius;
        }

        // find comment
        $crow = null;
        if ($this->qreq_cid > 0) {
            $crow = $this->find_comment("commentId={$this->qreq_cid}");
        }
        if (!$crow && $this->qreq_rrd) {
            $crow = $this->find_response_by_id($this->qreq_rrd->id);
        }

        // if `new` or numeric ID provided, must match actual comment
        // (which is always true unless `$this->qreq_rrd`)
        $this->rrd = $this->qreq_rrd;
        if ($crow) {
            assert($this->qreq_cid === $crow->commentId || $this->qreq_rrd);
            $this->rrd = $crow->response_round();
            if (($this->qreq_rrd && $this->rrd !== $this->qreq_rrd)
                || ($this->qreq_cid !== null && $this->qreq_cid !== $crow->commentId)) {
                $this->status = self::RESPONSE_REPLACED;
            }
        }

        // comment/response name
        if ($this->rrd) {
            if ($this->rrd->unnamed) {
                $this->uccmttype = "Response";
                $this->lccmttype = "response";
            } else {
                $this->uccmttype = $this->lccmttype = "{$this->rrd->name} response";
            }
        } else {
            $this->uccmttype = "Comment";
            $this->lccmttype = "comment";
        }

        if (!$crow && $this->qreq_cid > 0) {
            return JsonResult::make_error(404, "<0>{$this->uccmttype} not found");
        }

        // check comment view permission
        if ($crow
            && !$this->user->can_view_comment($this->prow, $crow, true)) {
            if ($this->user->can_view_submitted_review($this->prow)) {
                return JsonResult::make_error(403, "<0>You aren’t allowed to view that {$this->lccmttype}");
            }
            return JsonResult::make_error(404, "<0>{$this->uccmttype} not found");
        }

        // optimistic-concurrency precondition on an existing comment
        if ($this->status === 200
            && $crow
            && $this->if_unmodified_since !== null
            && $this->if_unmodified_since < $crow->timeModified) {
            $this->status = self::RESPONSE_REPLACED;
            $this->error_at("if_unmodified_since", "<5><strong>Edit conflict</strong>: The {$this->lccmttype} has changed");
        }

        // check post
        if ($this->status === 200
            && $this->ok) {
            $crow = $this->do_run_post($qreq, $crow);
        }

        return $this->post_result($crow);
    }

    /** Assemble the response for one processed comment. Component order mirrors
     * `Paper_API::post_result`: `ok`, `message_list`, `dry_run`, `valid`,
     * `change_list`, `conflict`, `comment`. (A future multi-comment upload would
     * collect these per comment into a `status_list`, as `/papers` does.)
     * @param ?CommentInfo $crow
     * @return JsonResult */
    private function post_result($crow) {
        // an edit conflict: a concurrent edit or a failed `if_unmodified_since`
        $conflict = $this->status === self::RESPONSE_REPLACED;
        if ($conflict && !$this->has_message()) {
            $this->error_at(null, "<0>{$this->uccmttype} was edited concurrently");
        }

        $jr = new JsonResult($conflict ? 200 : $this->status, [
            "ok" => !$conflict && $this->ok && $this->status <= 299,
            "message_list" => $this->message_list()
        ]);
        if ($this->dry_run) {
            $jr->content["dry_run"] = true;
        }
        $jr->content["valid"] = $this->valid;
        if ($this->change_list !== null) {
            $jr->content["change_list"] = $this->change_list;
        }
        if ($conflict) {
            $jr->content["conflict"] = true;
        }
        if (!$this->dry_run && $crow && $crow->commentId > 0) {
            $jr->content["comment"] = $crow->unparse_json($this->user);
        }
        return $jr;
    }

    /** @return bool */
    private function post_form_is_json($qreq) {
        return false;
    }

    /** @param ?CommentInfo $crow
     * @return CommentInfo */
    private function make_skeleton($crow) {
        if ($crow) {
            return $crow;
        } else if ($this->rrd !== null) {
            return CommentInfo::make_response_template($this->rrd, $this->prow);
        }
        return CommentInfo::make_new_template($this->user, $this->prow);
    }

    /** @param ?CommentInfo $crow
     * @return ?CommentInfo */
    private function do_run_post(Qrequest $qreq, $crow) {
        $xcrow = $this->make_skeleton($crow);
        $response = $xcrow->is_response();
        assert(!!$response === !!$this->rrd);

        // boolean parameters
        $qreq_delete = friendly_boolean($qreq->delete);

        // request skeleton
        $req = [
            "visibility" => $qreq->visibility,
            "topic" => $qreq->topic,
            "submit" => $this->rrd && !friendly_boolean($qreq->draft),
            "blind" => friendly_boolean($qreq->blind)
        ];

        if (friendly_boolean($qreq->delete)) {
            $req["text"] = false;
            $req["docs"] = [];
        } else {
            $req["text"] = rtrim(cleannl((string) $qreq->text));
            if (!$this->rrd) {
                $req["tags"] = $qreq->tags;
            }

            // attachments in request
            $req["docs"] = Attachments_PaperOption::parse_qreq_prefix(
                $this->prow, $qreq, "attachment", DTYPE_COMMENT,
                $crow ? $crow->attachments()->as_list() : [],
                $this
            );
            foreach ($req["docs"] as $doc) {
                if ($doc->paperStorageId === 0 && !$doc->save()) {
                    $this->status = 400;
                    $this->error_at(null, "<0>Error uploading attachment");
                    return null;
                }
            }
        }

        return $this->finish_post($xcrow, $req, $qreq->review_token);
    }

    /** Shared save path for the form and JSON variants.
     * @param CommentInfo $xcrow
     * @param array<string,mixed> $req
     * @param ?string $review_token
     * @return ?CommentInfo */
    private function finish_post($xcrow, $req, $review_token) {
        // empty
        if ($req["text"] === "" && empty($req["docs"])) {
            if (!$xcrow->commentId) {
                $this->ok = false;
                $this->error_at(null, "<0>Refusing to save empty {$this->lccmttype}");
                return null;
            } else {
                $req["text"] = false;
            }
        }
        if ($req["text"] === false && !$xcrow->commentId) {
            // deleting a nonexistent comment is a no-op
            return null;
        }

        // check permission, other errors
        $newctype = $xcrow->requested_type($req);
        $whynot = $this->user->perm_edit_comment($this->prow, $xcrow, $newctype);
        if ($whynot) {
            $whynot->set("expand", true)->append_to($this, null, 2);
            $this->ok = false;
            return null;
        }

        // check for review token
        $suser = $this->user;
        if ($review_token
            && ($token = decode_token($review_token, "V"))
            && in_array($token, $this->user->review_tokens(), true)
            && ($rrow = $this->prow->review_by_token($token))) {
            $suser = $this->conf->user_by_id($rrow->contactId);
        }

        // save (or, for a dry run, validate the change without committing)
        $cs = new CommentStatus($suser);
        $prepared = $cs->prepare_save($xcrow, $req);
        if ($prepared) {
            // change_list reflects what the request attempted to change, and is
            // available before commit (so a dry run can report it)
            $this->change_list = $cs->change_list();
        }

        if ($this->dry_run) {
            $cs->abort_save();
            $this->valid = $prepared;
            if (!$prepared) {
                $this->status = 400;
                $this->error_at(null, "<0>Error saving comment");
                return null;
            }
            return $xcrow;
        }

        $ok = $prepared && $cs->execute_save();
        if ($ok) {
            $cs->notify_followers();
        }

        // save errors; check for reentering same response
        if (!$ok) {
            if ($xcrow->is_response()
                && ($ocrow = $this->find_response_by_id((int) $xcrow->commentRound))) {
                if ($ocrow->comment !== $req["text"]
                    || $ocrow->attachment_ids() != $xcrow->attachment_ids()) {
                    $this->status = self::RESPONSE_REPLACED;
                    return $ocrow;
                }
                $xcrow = $ocrow;
            } else {
                $this->status = 400;
                $this->error_at(null, "<0>Error saving comment");
                return null;
            }
        }

        // a valid modification was committed
        $this->valid = true;

        // save success messages
        $this->append_item($this->save_success_message($xcrow));

        $aunames = $mentions = [];
        $mentions_missing = $mentions_censored = false;
        foreach ($xcrow->notifications ?? [] as $n) {
            if ($n->has(NotificationInfo::CONTACT | NotificationInfo::SENT)) {
                $aunames[] = $n->user->name_h(NAME_EB);
            }
            if ($n->has(NotificationInfo::MENTION)) {
                if ($n->sent()) {
                    $mentions[] = $n->user_html ?? $suser->reviewer_html_for($n->user);
                } else if ($xcrow->timeNotified === $xcrow->timeModified) {
                    $mentions_missing = true;
                }
            }
            if ($n->has(NotificationInfo::CENSORED)) {
                $mentions_censored = true;
            }
        }
        if ($aunames && !$this->prow->has_author($suser)) {
            if ($this->user->allow_view_authors($this->prow)) {
                $this->success($this->conf->_("<5>Notified {submission} contacts {:nblist}", $aunames));
            } else {
                $this->success($this->conf->_("<0>Notified {submission} contact(s)"));
            }
        }
        if ($mentions) {
            $this->success($this->conf->_("<5>Notified mentioned users {:nblist}", $mentions));
        }
        if ($mentions_missing) {
            $this->append_item(MessageItem::warning_note($this->conf->_("<0>Some mentioned users cannot currently see this comment, so they were not notified.")));
        }
        if ($mentions_censored) {
            $this->append_item(MessageItem::warning_note($this->conf->_("<0>Some notifications were censored to anonymize mentioned users.")));
        }
        return $xcrow;
    }


    /** @return JsonResult */
    static private function run(Contact $user, Qrequest $qreq, ?PaperInfo $prow, $mode) {
        $old_overrides = $user->overrides();
        if (friendly_boolean($qreq->forceShow) !== false) {
            $user->add_overrides(Contact::OVERRIDE_CONFLICT);
        }
        try {
            if ($qreq->is_getlike()) {
                if ($mode === self::M_ONE) {
                    $jr = (new Comment_API($user, $prow, $qreq))->run_get_one($qreq);
                } else {
                    $jr = self::run_get_multi($user, $qreq, $prow);
                }
            } else {
                $jr = (new Comment_API($user, $prow, $qreq))->run_post($qreq);
            }
        } catch (JsonResult $jrex) {
            $jr = $jrex;
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
    static function run_multi(Contact $user, Qrequest $qreq, ?PaperInfo $prow) {
        return self::run($user, $qreq, $prow, self::M_MULTI);
    }
}
