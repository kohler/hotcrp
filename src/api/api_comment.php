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
    /** @var int */
    private $status = 200;
    /** @var bool */
    private $ok = true;
    /** @var string */
    private $uccmttype;
    /** @var string */
    private $lccmttype;


    const M_ONE = 1;
    const M_MULTI = 2;

    const RESPONSE_REPLACED = 492;

    function __construct(Contact $user, PaperInfo $prow) {
        $this->conf = $user->conf;
        $this->user = $user;
        $this->prow = $prow;
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

    /** @return JsonResult */
    static function run_get_multi(Contact $user, Qrequest $qreq, ?PaperInfo $prow) {
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

    /** @return MessageItem */
    static private function save_success_message(CommentInfo $xcrow) {
        $action = $xcrow->commentId ? "saved" : "deleted";
        if (($rrd = $xcrow->response_round())) {
            $cname = $rrd->unnamed ? "Response" : "{$rrd->name} response";
            if ($xcrow->commentId && !($xcrow->commentType & CommentInfo::CT_DRAFT)) {
                $action = "submitted";
            }
        } else {
            $cname = "Comment";
        }
        return MessageItem::success("<0>{$cname} {$action}");
    }


    /** @return JsonResult */
    private function run_qreq(Qrequest $qreq) {
        // a GET with no `c`/`response` behaves like `GET /comments`
        // (backward compat)
        if ($qreq->is_getlike()
            && !isset($qreq->c)
            && !isset($qreq->response)) {
            return self::run_get_multi($this->user, $qreq, $this->prow);
        }

        // check parameters
        if ($qreq->is_post()
            && !isset($qreq->text)
            && !friendly_boolean($qreq->delete)) {
            return JsonResult::make_parameter_error("text");
        }

        // check for all-comments request
        $content = $qreq->is_post() || friendly_boolean($qreq->content ?? "1");

        // analyze response parameter
        $c = $qreq->c ?? "";
        if ($c === "response") {
            $rname = "1";
        } else if (str_ends_with($c, "response")) {
            $rname = substr($c, 0, -8);
            $c = "response";
        } else if (str_starts_with($c, "response")) {
            $rname = substr($c, 8);
            $c = "response";
        } else {
            $rname = $qreq->response;
        }
        if ($rname !== null) {
            $rrd = $this->conf->response_round($rname);
            if (!$rrd
                || (($qreq->response ?? "") !== ""
                    && (string) $qreq->response !== $rname)) {
                return JsonResult::make_error(400, "<0>Invalid response request");
            }
            $rcrow = $this->find_response_by_id($rrd->id);
        } else {
            $rrd = null;
            $rcrow = null;
        }

        // analyze `c` parameter
        if ($c === "") {
            $c = $rrd ? "response" : ($qreq->is_post() ? "new" : "");
        }
        $cn = null;
        if ($c !== "new" && $c !== "response" && ($cn = stoi($c)) === null) {
            return JsonResult::make_error(404, "<0>Comment not found");
        }
        $crow = null;
        if ($rrd !== null) {
            $crow = $rcrow;
        } else if ($cn !== null) {
            $crow = $this->find_comment("commentId={$cn}");
        }
        assert($c === "new" || $c === "response" || ctype_digit($c));

        // comment/response name
        if ($rrd !== null) {
            $this->uccmttype = $rrd->unnamed ? "Response" : "{$rrd->name} response";
            $this->lccmttype = $rrd->unnamed ? "response" : $this->uccmttype;
        } else {
            $this->uccmttype = "Comment";
            $this->lccmttype = "comment";
        }

        // comment matching
        // if GET, or numeric ID provided, comment must exist
        if (!$crow
            && ($cn !== null || !$qreq->is_post())) {
            return JsonResult::make_error(404, "<0>{$this->uccmttype} not found");
        }
        // if `new` or numeric ID provided, must match actual comment
        // (which is always true unless `$rrd`)
        assert(!$crow || $cn === $crow->commentId || $rrd);
        if ($crow
            && $cn !== $crow->commentId
            && ($cn !== null || $c === "new")) {
            $this->status = self::RESPONSE_REPLACED;
        }

        // check comment view permission
        if ($crow && !$this->user->can_view_comment($this->prow, $crow, true)) {
            if ($this->user->can_view_submitted_review($this->prow)) {
                return JsonResult::make_error(403, "<0>You aren’t allowed to view that {$this->lccmttype}");
            }
            return JsonResult::make_error(404, "<0>{$this->uccmttype} not found");
        }

        // check post
        if ($this->status === 200 && $this->ok && $qreq->is_post()) {
            $crow = $this->run_post($qreq, $rrd, $crow);
        }

        if ($this->status === self::RESPONSE_REPLACED) {
            // report response replacement error
            $jr = JsonResult::make_error(200, "<0>{$this->uccmttype} was edited concurrently");
            $jr["conflict"] = true;
        } else {
            $jr = new JsonResult($this->status, ["ok" => $this->ok && $this->status <= 299]);
            if ($this->has_message()) {
                $jr["message_list"] = $this->message_list();
            }
        }
        if ($crow && $crow->commentId > 0) {
            $jr["comment"] = $crow->unparse_json($this->user, !$content);
        }
        return $jr;
    }

    /** @param ?ResponseRound $rrd
     * @param ?CommentInfo $crow
     * @return CommentInfo */
    private function make_skeleton($rrd, $crow) {
        if ($crow) {
            return $crow;
        } else if ($rrd !== null) {
            return CommentInfo::make_response_template($rrd, $this->prow);
        }
        return CommentInfo::make_new_template($this->user, $this->prow);
    }

    /** @param ?ResponseRound $rrd
     * @param ?CommentInfo $crow
     * @return ?CommentInfo */
    private function run_post(Qrequest $qreq, $rrd, $crow) {
        $xcrow = $this->make_skeleton($rrd, $crow);
        $response = $xcrow->is_response();

        // boolean parameters
        $qreq_delete = friendly_boolean($qreq->delete);

        // request skeleton
        $req = [
            "visibility" => $qreq->visibility,
            "topic" => $qreq->topic,
            "submit" => $response && !friendly_boolean($qreq->draft),
            "blind" => friendly_boolean($qreq->blind)
        ];

        if (friendly_boolean($qreq->delete)) {
            $req["text"] = false;
            $req["docs"] = [];
        } else {
            $req["text"] = rtrim(cleannl((string) $qreq->text));
            if (!$response) {
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

        // save
        $ok = $xcrow->save_comment($req, $suser);

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

        // save success messages
        $this->append_item(self::save_success_message($xcrow));

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
                    $jr = (new Comment_API($user, $prow))->run_qreq($qreq);
                } else {
                    $jr = self::run_get_multi($user, $qreq, $prow);
                }
            } else {
                $jr = (new Comment_API($user, $prow))->run_qreq($qreq);
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
