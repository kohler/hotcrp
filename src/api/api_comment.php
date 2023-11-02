<?php
// api_comment.php -- HotCRP comment API call
// Copyright (c) 2008-2022 Eddie Kohler; see LICENSE.

class Comment_API {
    /** @var Conf */
    private $conf;
    /** @var Contact */
    private $user;
    /** @var PaperInfo */
    private $prow;
    /** @var int */
    private $status = 200;
    /** @var MessageSet */
    private $ms;

    const RESPONSE_REPLACED = 492;

    function __construct(Contact $user, PaperInfo $prow) {
        $this->conf = $user->conf;
        $this->user = $user;
        $this->prow = $prow;
        $this->ms = new MessageSet;
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

    /** @param ?ResponseRound $rrd
     * @param ?CommentInfo $crow
     * @return ?CommentInfo */
    private function run_post(Qrequest $qreq, $rrd, $crow) {
        // create skeleton
        if ($crow) {
            $xcrow = $crow;
        } else if ($rrd === null) {
            $xcrow = CommentInfo::make_new_template($this->user, $this->prow);
        } else {
            $xcrow = CommentInfo::make_response_template($rrd, $this->prow);
        }

        // request skeleton
        $response = $xcrow->is_response();
        $req = [
            "visibility" => $qreq->visibility,
            "topic" => $qreq->topic,
            "submit" => $response && !$qreq->draft,
            "text" => rtrim(cleannl((string) $qreq->text)),
            "blind" => $qreq->blind
        ];

        // tags
        if (!$response) {
            $req["tags"] = $qreq->tags;
        }

        // attachments in request
        $docs = Attachments_PaperOption::parse_qreq_prefix(
            $this->prow, $qreq, "attachment", DTYPE_COMMENT,
            $crow ? $crow->attachments()->as_list() : [],
            $this->ms
        );
        foreach ($docs as $doc) {
            if ($doc->paperStorageId === 0 && !$doc->save()) {
                $this->status = 400;
                $this->ms->error_at(null, "<0>Error uploading attachment");
                return null;
            }
        }

        // empty
        if ($req["text"] === "" && empty($docs)) {
            if (!$qreq->delete && (!$xcrow->commentId || !isset($qreq->text))) {
                $this->status = 400;
                $this->ms->error_at(null, "<0>Refusing to save empty comment");
                return null;
            } else {
                $qreq->delete = true;
            }
        }

        // check permission, other errors
        $newctype = $xcrow->requested_type($req);
        $whyNot = $this->user->perm_edit_comment($this->prow, $xcrow, $newctype);
        if ($whyNot) {
            $this->status = 403;
            $whyNot->append_to($this->ms, null, 2);
            return null;
        }

        // check for review token
        $suser = $this->user;
        if (($token = $qreq->review_token)
            && ($token = decode_token($token, "V"))
            && in_array($token, $this->user->review_tokens())
            && ($rrow = $this->prow->review_by_token($token))) {
            $suser = $this->conf->user_by_id($rrow->contactId);
        }

        // check for delete
        if ($qreq->delete) {
            $req["text"] = false;
            $req["docs"] = [];
        } else {
            $req["docs"] = $docs;
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
                $this->ms->error_at(null, "<0>Error saving comment");
                return null;
            }
        }

        // save success messages
        $this->ms->append_item(self::save_success_message($xcrow));

        $aunames = $mentions = [];
        $mentions_missing = false;
        foreach ($xcrow->notifications ?? [] as $n) {
            if (($n->types & NotificationInfo::CONTACT) !== 0 && $n->sent) {
                $aunames[] = $n->user->name_h(NAME_EB);
            }
            if (($n->types & NotificationInfo::MENTION) !== 0) {
                if ($n->sent) {
                    $mentions[] = $n->user_html ?? $suser->reviewer_html_for($n->user);
                } else if ($xcrow->timeNotified === $xcrow->timeModified) {
                    $mentions_missing = true;
                }
            }
        }
        if ($aunames && !$this->prow->has_author($suser)) {
            if ($this->user->allow_view_authors($this->prow)) {
                $this->ms->success($this->conf->_("<5>Notified {submission} contacts {:nblist}", $aunames));
            } else {
                $this->ms->success($this->conf->_("<0>Notified {submission} contact(s)"));
            }
        }
        if ($mentions) {
            $this->ms->success($this->conf->_("<5>Notified mentioned users {:nblist}", $mentions));
        }
        if ($mentions_missing) {
            $this->ms->msg_at(null, $this->conf->_("<0>Some mentioned users cannot currently see this comment, so they were not notified."), MessageSet::WARNING_NOTE);
        }
        return $xcrow;
    }

    /** @return JsonResult */
    private function run_qreq(Qrequest $qreq) {
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
            $c = $c !== "" ? $c : "response";
        } else {
            $rrd = null;
            $rcrow = null;
        }

        // analyze comment parameter
        if ($rrd !== null) {
            $crow = $rcrow;
        } else if ($c === "new" || $c === "response") {
            $crow = null;
        } else if (ctype_digit($c)) {
            $crow = $this->find_comment("commentId=" . intval($c));
        } else if ($c === "" && $qreq->is_post()) {
            $c = "new";
            $crow = null;
        } else {
            return JsonResult::make_error(404, "<0>Comment not found");
        }
        assert($c === "new" || $c === "response" || ctype_digit($c));

        // comment/response name
        if ($rrd !== null) {
            $uccmttype = $rrd->unnamed ? "Response" : "{$rrd->name} response";
            $lccmttype = $rrd->unnamed ? "response" : $uccmttype;
        } else {
            $uccmttype = "Comment";
            $lccmttype = "comment";
        }

        // check for no comment or response mismatch
        // * if GET, comment must exist
        // * if ID is numeric, ID must match
        // * if POST and c=new, comment must not exist
        if (($crow === null && $qreq->is_get())
            || (ctype_digit($c) && ($crow === null || intval($c) !== $crow->commentId))
            || ($c === "new" && $qreq->is_post() && $crow !== null)) {
            if ($rrd === null || !$crow) {
                return JsonResult::make_error(404, "<0>{$uccmttype} not found");
            } else {
                $this->status = self::RESPONSE_REPLACED;
            }
        }
        // XXX editing response but c does not name response?

        // check comment view permission
        if ($crow && !$this->user->can_view_comment($this->prow, $crow, true)) {
            if ($this->user->can_view_review($this->prow, null)) {
                return JsonResult::make_error(403, "<0>You aren’t allowed to view that {$lccmttype}");
            } else {
                return JsonResult::make_error(404, "<0>{$uccmttype} not found");
            }
        }

        // check post
        if ($this->status === 200 && $qreq->is_post()) {
            $crow = $this->run_post($qreq, $rrd, $crow);
        }

        if ($this->status === self::RESPONSE_REPLACED) {
            // report response replacement error
            $jr = JsonResult::make_error(404, "<0>{uccmttype} was edited concurrently");
            $jr["conflict"] = true;
        } else {
            $jr = new JsonResult($this->status, ["ok" => $this->status <= 299]);
            if ($this->ms->has_message()) {
                $jr["message_list"] = $this->ms->message_list();
            }
        }
        if ($crow && $crow->commentId > 0) {
            $jr["cmt"] = $crow->unparse_json($this->user);
        }
        return $jr;
    }

    static function run(Contact $user, Qrequest $qreq, PaperInfo $prow) {
        // check parameters
        if ((!isset($qreq->text) && !isset($qreq->delete) && $qreq->is_post())
            || ($qreq->c === "new" && !$qreq->is_post())) {
            return JsonResult::make_error(400, "<0>Bad request");
        } else {
            return (new Comment_API($user, $prow))->run_qreq($qreq);
        }
    }
}
