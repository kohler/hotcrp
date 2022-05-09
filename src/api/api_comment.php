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
    /** @var list<MessageItem> */
    private $ms = [];

    const RESPONSE_REPLACED = 492;

    function __construct(Contact $user, PaperInfo $prow) {
        $this->conf = $user->conf;
        $this->user = $user;
        $this->prow = $prow;
    }

    /** @return ?CommentInfo */
    private function find_comment($query) {
        $cmts = $this->prow->fetch_comments($query);
        reset($cmts);
        return empty($cmts) ? null : current($cmts);
    }

    /** @param int $round
     * @return ?CommentInfo */
    private function find_response($round) {
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
            "blind" => $qreq->blind,
            "docs" => $crow ? $crow->attachments()->as_list() : []
        ];

        // tags
        if (!$response) {
            $req["tags"] = $qreq->tags;
        }

        // attachments in request
        for ($i = count($req["docs"]) - 1; $i >= 0; --$i) {
            if ($qreq["cmtdoc_{$req["docs"][$i]->paperStorageId}_{$i}:remove"]) {
                array_splice($req["docs"], $i, 1);
            }
        }
        for ($i = 1; $qreq["has_cmtdoc_new_$i"] && count($req["docs"]) < 1000; ++$i) {
            if (($doc = DocumentInfo::make_request($qreq, "cmtdoc_new_$i", $this->prow->paperId, DTYPE_COMMENT, $this->conf))) {
                if ($doc->save()) {
                    $req["docs"][] = $doc;
                } else {
                    $this->status = 400;
                    $this->ms[] = MessageItem::error("<0>Error uploading attachment");
                    return null;
                }
            }
        }

        // empty
        if ($req["text"] === "" && empty($req["docs"])) {
            if (!$qreq->delete && (!$xcrow->commentId || !isset($qreq->text))) {
                $this->status = 400;
                $this->ms[] = MessageItem::error("<0>Comment text required");
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
            $this->ms[] = MessageItem::error("<5>" . $whyNot->unparse_html());
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
        }

        // save
        $ok = $xcrow->save_comment($req, $suser);

        // save errors; check for reentering same response
        if (!$ok) {
            if ($xcrow->is_response()
                && ($ocrow = $this->find_response((int) $xcrow->commentRound))) {
                if ($ocrow->comment !== $req["text"]
                    || $ocrow->attachment_ids() != $xcrow->attachment_ids()) {
                    $this->status = self::RESPONSE_REPLACED;
                    return $ocrow;
                }
                $xcrow = $ocrow;
            } else {
                $this->status = 400;
                $this->ms[] = MessageItem::error("<0>Error saving comment");
                return null;
            }
        }

        // save success messages
        $this->ms[] = self::save_success_message($xcrow);
        if ($xcrow->notified_authors
            && !$this->prow->has_author($suser)) {
            if ($this->user->allow_view_authors($this->prow)) {
                $this->ms[] = MessageItem::success($this->conf->_("<0>Notified submission authors", count($this->prow->author_list())));
            } else {
                $this->ms[] = MessageItem::success($this->conf->_("<0>Notified submission author(s)"));
            }
        }
        if ($xcrow->saved_mentions) {
            $this->ms[] = MessageItem::success($this->conf->_("<5>Notified mentioned users %#s", array_values($xcrow->saved_mentions)));
        }
        if ($xcrow->saved_mentions_missing) {
            $this->ms[] = new MessageItem(null, $this->conf->_("<0>Some users mentioned in the comment cannot see the comment yet, so they were not notified."), MessageSet::WARNING_NOTE);
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
                return JsonResult::make_error("<0>Invalid response request");
            }
            $rcrow = $this->find_response($rrd->number);
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
            if (!empty($this->ms)) {
                $jr["message_list"] = $this->ms;
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
