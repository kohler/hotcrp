<?php
// api_comment.php -- HotCRP comment API call
// Copyright (c) 2008-2019 Eddie Kohler; see LICENSE.

class Comment_API {
    static private function find_comment($query, $prow) {
        $cmts = $prow->fetch_comments($query);
        reset($cmts);
        return empty($cmts) ? null : current($cmts);
    }
    static private function save_success_message($xcrow) {
        $what = $xcrow->commentId ? "saved" : "deleted";
        if (!$xcrow->is_response()) {
            return Ht::msg("Comment $what.", "confirm");
        } else {
            $rname = $xcrow->conf->resp_round_text($xcrow->commentRound);
            $rname = $rname ? "$rname response" : "Response";
            if ($xcrow->commentId && !($xcrow->commentType & COMMENTTYPE_DRAFT))
                return Ht::msg("$rname submitted.", "confirm");
            else
                return Ht::msg("$rname $what.", "confirm");
        }
    }

    static function run(Contact $user, Qrequest $qreq, $prow) {
        // check parameters
        if (!isset($qreq->text) && !isset($qreq->delete))
            return new JsonResult(400, "Bad request.");

        // check response
        $round = false;
        if ($qreq->response) {
            $round = $prow->conf->resp_round_number($qreq->response);
            if ($round === false)
                return new JsonResult(404, "No such response round.");
            // XXX backwards compat; assertion 16-08-2019
            assert(!str_ends_with((string) $qreq->c, "response"));
        }

        // find comment
        $crow = null;
        if ($qreq->c && $qreq->c !== "new") {
            if (ctype_digit($qreq->c))
                $crow = self::find_comment("commentId=" . intval($qreq->c), $prow);
            if (!$crow)
                return new JsonResult(404, "No such comment.");
        }

        // create skeleton
        $xcrow = $crow;
        if (!$xcrow) {
            if ($round === false)
                $xcrow = new CommentInfo(null, $prow);
            else
                $xcrow = CommentInfo::make_response_template($round, $prow);
        }

        // request skeleton
        $ok = true;
        $msg = false;
        $response = $xcrow->is_response();
        $req = [
            "visibility" => $qreq->visibility,
            "submit" => $response && !$qreq->draft,
            "text" => rtrim(cleannl((string) $qreq->text)),
            "blind" => $qreq->blind,
            "docs" => $crow ? $crow->attachments() : []
        ];

        // check if response changed
        $changed = true;
        if ($response
            && $req["text"] === rtrim(cleannl($xcrow->commentOverflow ? : $xcrow->comment)))
            $changed = false;

        // tags
        if (!$response)
            $req["tags"] = $qreq->tags;

        // attachments in request
        for ($i = count($req["docs"]) - 1; $i >= 0; --$i) {
            if ($qreq["remove_cmtdoc_{$req["docs"][$i]->paperStorageId}_{$i}"]) {
                array_splice($req["docs"], $i, 1);
                $changed = true;
            }
        }
        for ($i = 1; $qreq["has_cmtdoc_new_$i"] && count($req["docs"]) < 1000; ++$i) {
            if (($f = $qreq->file("cmtdoc_new_$i"))) {
                $doc = DocumentInfo::make_file_upload($prow->paperId, DTYPE_COMMENT, $f, $prow->conf);
                if ($doc->save()) {
                    $req["docs"][] = $doc;
                    $changed = true;
                } else {
                    $msg = Ht::msg("Error uploading attachment.", 2);
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
                $msg = Ht::msg("Empty comment.", 2);
                $ok = false;
            } else {
                $qreq->delete = true;
                $changed = true;
            }
        }

        // check permission, other errors
        $submit_value = $response && !$changed ? 2 : true;
        if (($whyNot = $user->perm_comment($prow, $xcrow, $submit_value)))
            return new JsonResult(403, ["ok" => false, "msg" => whyNotText($whyNot)]);
        if (($xcrow->is_response() && $round === false)
            || (!$xcrow->is_response() && $round !== false)
            || ($round !== false && $round != $xcrow->commentRound))
            return new JsonResult(400, "Improper response.");

        // save
        if ($ok) {
            // check for review token
            $suser = $user;
            if (($token = $qreq->review_token)
                && ($token = decode_token($token, "V"))
                && in_array($token, $user->review_tokens())
                && ($rrow = $prow->review_of_token($token))) {
                $suser = $prow->conf->user_by_id($rrow->contactId);
            }

            // check for delete
            if ($qreq->delete) {
                $req["text"] = false;
                $req["docs"] = [];
            }

            // save
            $ok = $xcrow->save($req, $suser);

            // check for response simultaneity
            if (!$ok && $xcrow->is_response()) {
                $ocrow = self::find_comment("(commentType&" . COMMENTTYPE_RESPONSE . ")!=0 and commentRound=$round", $prow);
                if ($ocrow
                    && $ocrow->comment === $req["text"]
                    && $ocrow->attachment_ids() == $xcrow->attachment_ids()) {
                    $xcrow = $ocrow;
                    $ok = true;
                } else {
                    $msg = Ht::msg("A response was entered concurrently by another user. Reload to see it.", 2);
                }
            }

            // generate save response
            if ($ok) {
                $msg = self::save_success_message($xcrow);
            }
        }

        $j = ["ok" => $ok];
        if ($xcrow->commentId) {
            $j["cmt"] = $xcrow->unparse_json($user);
        }
        if ($msg) {
            $j["msg"] = $msg;
        }
        return $j;
    }
}
