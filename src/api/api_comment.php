<?php
// api_comment.php -- HotCRP comment API call
// Copyright (c) 2008-2022 Eddie Kohler; see LICENSE.

class Comment_API {
    /** @return ?CommentInfo */
    static private function find_comment($query, PaperInfo $prow) {
        $cmts = $prow->fetch_comments($query);
        reset($cmts);
        return empty($cmts) ? null : current($cmts);
    }
    /** @return ?CommentInfo */
    static private function find_response($round, PaperInfo $prow) {
        return $round === false ? null : self::find_comment("(commentType&" . CommentInfo::CT_RESPONSE . ")!=0 and commentRound=" . (int) $round, $prow);
    }
    /** @return MessageItem */
    static private function save_success_message(CommentInfo $xcrow) {
        $action = $xcrow->commentId ? "saved" : "deleted";
        if (!$xcrow->is_response()) {
            $cname = "Comment";
        } else {
            $cname = $xcrow->conf->resp_round_text($xcrow->commentRound);
            $cname = $cname ? "$cname response" : "Response";
            if ($xcrow->commentId && !($xcrow->commentType & CommentInfo::CT_DRAFT)) {
                $action = "submitted";
            }
        }
        return new MessageItem(null, "<0>{$cname} {$action}", MessageSet::SUCCESS);
    }
    /** @param ?CommentInfo $crow
     * @param list<MessageItem> &$mis
     * @return array{?CommentInfo,int} */
    static function run_post(Contact $user, Qrequest $qreq, PaperInfo $prow, $crow, &$mis) {
        // check response
        $round = false;
        if ($qreq->response) {
            $round = $prow->conf->resp_round_number($qreq->response);
            if ($round === false) {
                $mis[] = new MessageItem(null, "<0>No such response round", MessageSet::ERROR);
                return [null, 404];
            } else if ($crow && (!$crow->is_response() || $crow->commentRound != $round)) {
                $mis[] = new MessageItem(null, "<0>Improper response", MessageSet::ERROR);
                return [null, 400];
            }
        } else if ($crow && $crow->is_response()) {
            $mis[] = new MessageItem(null, "<0>Improper response", MessageSet::ERROR);
            return [null, 400];
        }

        // create skeleton
        if ($crow) {
            $xcrow = $crow;
        } else if ($round === false) {
            $xcrow = new CommentInfo(null, $prow);
        } else {
            $xcrow = CommentInfo::make_response_template($round, $prow);
        }

        // request skeleton
        $ok = true;
        $response = $xcrow->is_response();
        $req = [
            "visibility" => $qreq->visibility,
            "submit" => $response && !$qreq->draft,
            "text" => rtrim(cleannl((string) $qreq->text)),
            "blind" => $qreq->blind,
            "docs" => $crow ? $crow->attachments()->as_list() : []
        ];

        // check if response changed
        $changed = true;
        if ($response
            && $req["text"] === rtrim(cleannl((string) ($xcrow->commentOverflow ? : $xcrow->comment)))) {
            $changed = false;
        }

        // tags
        if (!$response) {
            $req["tags"] = $qreq->tags;
        }

        // attachments in request
        for ($i = count($req["docs"]) - 1; $i >= 0; --$i) {
            if ($qreq["cmtdoc_{$req["docs"][$i]->paperStorageId}_{$i}:remove"]) {
                array_splice($req["docs"], $i, 1);
                $changed = true;
            }
        }
        for ($i = 1; $qreq["has_cmtdoc_new_$i"] && count($req["docs"]) < 1000; ++$i) {
            if (($doc = DocumentInfo::make_request($qreq, "cmtdoc_new_$i", $prow->paperId, DTYPE_COMMENT, $prow->conf))) {
                if ($doc->save()) {
                    $req["docs"][] = $doc;
                    $changed = true;
                } else {
                    $mis[] = new MessageItem(null, "Error uploading attachment", 2);
                    $ok = false;
                    break;
                }
            }
        }

        // empty
        if ($ok
            && $req["text"] === ""
            && empty($req["docs"])) {
            if (!$qreq->delete && (!$xcrow->commentId || !isset($qreq->text))) {
                $mis[] = new MessageItem(null, "Comment text required", 2);
                $ok = false;
            } else {
                $qreq->delete = true;
                $changed = true;
            }
        }

        // check permission, other errors
        $whyNot = $user->perm_comment($prow, $xcrow, true);
        if ($whyNot && ($changed || !$user->can_finalize_comment($prow, $xcrow))) {
            $mis[] = new MessageItem(null, "<5>" . $whyNot->unparse_html(), 2);
            return [null, 403];
        }

        // save
        if ($ok) {
            // check for review token
            $suser = $user;
            if (($token = $qreq->review_token)
                && ($token = decode_token($token, "V"))
                && in_array($token, $user->review_tokens())
                && ($rrow = $prow->review_by_token($token))) {
                $suser = $prow->conf->user_by_id($rrow->contactId);
            }

            // check for delete
            if ($qreq->delete) {
                $req["text"] = false;
                $req["docs"] = [];
            }

            // save
            $ok = $xcrow->save_comment($req, $suser);

            // check for response simultaneity
            if (!$ok
                && $xcrow->is_response()
                && ($ocrow = self::find_response((int) $xcrow->commentRound, $prow))) {
                if ($ocrow->comment === $req["text"]
                    && $ocrow->attachment_ids() == $xcrow->attachment_ids()) {
                    $xcrow = $ocrow;
                    $ok = true;
                } else {
                    $mis[] = new MessageItem(null, "A response was entered concurrently by another user; reload to see it", 2);
                }
            }

            // generate save response
            if ($ok) {
                $mis[] = self::save_success_message($xcrow);
                if ($xcrow && $xcrow->saved_mentions) {
                    $mis[] = new MessageItem(null, $user->conf->_("<5>Notified mentioned users %#s", array_values($xcrow->saved_mentions)), MessageSet::SUCCESS);
                }
                if ($xcrow && $xcrow->saved_mentions_missing) {
                    $mis[] = new MessageItem(null, $user->conf->_("<0>Some users mentioned in the comment cannot see the comment yet, so they were not notified."), MessageSet::WARNING_NOTE);
                }
            }
        }

        return [$xcrow, $ok ? 200 : 400];
    }

    /** @param list<MessageItem> &$mis
     * @return ?CommentInfo */
    static private function lookup(Contact $user, Qrequest $qreq, PaperInfo $prow, &$mis) {
        if (str_ends_with($qreq->c, "response")) {
            $rname = substr($qreq->c, 0, -8);
        } else if (str_starts_with($qreq->c, "response")) {
            $rname = substr($qreq->c, 8);
        } else if ($qreq->response) {
            $rname = $qreq->response;
        } else {
            $rname = false;
        }
        $round = $rname === false ? false : $prow->conf->resp_round_number($rname);
        if ($rname !== false && $round === false) {
            $mis[] = new MessageItem(null, "<0>No such response round", MessageSet::ERROR);
            return null;
        }
        $rcrow = self::find_response($round, $prow);

        if (ctype_digit($qreq->c)) {
            $crow = self::find_comment("commentId=" . intval($qreq->c), $prow);
            if ($crow && $user->can_view_comment($prow, $crow, true)) {
                return $crow;
            } else if ($crow || $rname === false || $qreq->is_get()) {
                $mis[] = new MessageItem(null, "<0>No such comment", MessageSet::ERROR);
            } else if ($rcrow && $user->can_view_comment($prow, $rcrow)) {
                $mis[] = new MessageItem(null, "<0>The response you were editing has been deleted and a new response has been entered; reload to see it", MessageSet::ERROR);
            } else {
                $mis[] = new MessageItem("deleted", "<0>The response you were editing has been deleted. Submit again to create a new response", MessageSet::ERROR);
            }
        } else if ($round !== false) {
            if ($rcrow && $user->can_view_comment($prow, $rcrow, true)) {
                return $rcrow;
            } else {
                $mis[] = new MessageItem(null, "<0>No such response", MessageSet::ERROR);
            }
        } else {
            $mis[] = new MessageItem(null, "<0>No such comment", MessageSet::ERROR);
        }
        return null;
    }

    static function run(Contact $user, Qrequest $qreq, PaperInfo $prow) {
        // check parameters
        if ((!isset($qreq->text) && !isset($qreq->delete) && $qreq->is_post())
            || ($qreq->c === "new" && !$qreq->is_get())) {
            return new JsonResult(400, "Bad request.");
        }

        // find comment
        $crow = $response_name = null;
        $mis = [];
        if (!$qreq->c && $qreq->response && $qreq->is_get()) {
            $qreq->c = ($qreq->response === "1" ? "" : $qreq->response) . "response";
        }
        if ($qreq->c
            && $qreq->c !== "new"
            && !($crow = self::lookup($user, $qreq, $prow, $mis))) {
            return new JsonResult(404, ["ok" => false, "message_list" => $mis]);
        }

        if ($qreq->is_post()) {
            list($crow, $status) = self::run_post($user, $qreq, $prow, $crow, $mis);
        } else {
            $status = 200;
        }

        $j = ["ok" => $status <= 299];
        if ($crow && $crow->commentId) {
            // NB CommentInfo::unparse_json checks can_view_comment
            $j["cmt"] = $crow->unparse_json($user);
        }
        if ($mis) {
            $j["message_list"] = $mis;
        }
        foreach ($mis as $mi) {
            if ($mi->field)
                $j[$mi->field] = true;
        }
        return new JsonResult($status, $j);
    }
}
