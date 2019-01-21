<?php
// api_requestreview.php -- HotCRP user-related API calls
// Copyright (c) 2008-2019 Eddie Kohler; see LICENSE.

class RequestReview_API {
    static function requestreview($user, $qreq, $prow) {
        global $Now;
        $round = null;
        if ((string) $qreq->round !== ""
            && ($rname = $user->conf->sanitize_round_name($qreq->round)) !== false)
            $round = (int) $user->conf->round_number($rname, false);

        if (($whyNot = $user->perm_request_review($prow, $round, true)))
            return new JsonResult(403, ["ok" => false, "error" => whyNotText($whyNot)]);
        if (!isset($qreq->email))
            return new JsonResult(400, "Bad request.");
        $email = $qreq->email = trim($qreq->email);
        if ($email === "" || $email === "newanonymous")
            return self::requestreview_anonymous($user, $qreq, $prow);

        $reviewer = $user->conf->user_by_email($email);
        if (!$reviewer && ($email === "" || !validate_email($email)))
            return self::error_result(400, "email", "Invalid email address.");

        $name_args = Text::analyze_name_args([(object) ["firstName" => $qreq->firstName, "lastName" => $qreq->lastName, "name" => $qreq->name, "affiliation" => $qreq->affiliation, "email" => $email]]);
        $reason = trim($qreq->reason);

        // check proposal:
        // - check existing review
        if ($reviewer && $prow->review_of_user($reviewer))
            return self::error_result(400, "email", htmlspecialchars($email) . " is already a reviewer.");
        // - check existing request
        $request = $user->conf->fetch_first_object("select * from ReviewRequest where paperId=? and email=?", $prow->paperId, $email);
        if ($request && !$user->allow_administer($prow))
            return self::error_result(400, "email", htmlspecialchars($email) . " is already a requested reviewer.");
        // - check existing refusal
        if ($reviewer)
            $refusal = get($prow->review_refusals_of_user($reviewer), 0);
        else
            $refusal = get($prow->review_refusals_of_email($email), 0);
        if ($refusal && (!$user->can_administer($prow) || !$qreq->override)) {
            $errf = ["email" => true];
            if ($reviewer
                && ($refusal->refusedBy == $reviewer->contactId
                    || ($refusal->refusedBy === null && $refusal->reason !== "request denied by chair")))
                $msg = Text::name_html($reviewer) . " has declined to review this submission.";
            else
                $msg = "An administrator denied a previous request for " . htmlspecialchars($email) . " to review this submission.";
            if ($refusal->reason !== "" && $refusal->reason !== "request denied by chair")
                $msg .= " They offered this reason: <blockquote>" . htmlspecialchars($refusal->reason) . "</blockquote>";
            if ($user->allow_administer($prow))
                $errf["override"] = true;
            return self::error_result(400, $errf, $msg);
        }
        // - check conflict
        if ($reviewer
            && ($prow->has_conflict($reviewer)
                || ($reviewer->isPC && !$reviewer->can_accept_review_assignment($prow))))
            return self::error_result(400, "email", htmlspecialchars($email) . " cannot be asked to review this submission.");

        // check for potential conflict
        $xreviewer = $reviewer;
        if (!$xreviewer)
            $xreviewer = $user->conf->contactdb_user_by_email($email);
        if (!$xreviewer)
            $xreviewer = new Contact($name_args, $user->conf);
        $potconflict = $prow->potential_conflict_html($xreviewer);
        if ($potconflict
            && $user->can_administer($prow)
            && !$qreq->override)
            return self::error_result(400, "override", "<p>" . Text::user_html($xreviewer) . " has a potential conflict with this submission, so the request has not been made.</p>" . $potconflict[1]);

        // check whether to make a proposal
        $extrev_chairreq = $user->conf->setting("extrev_chairreq");
        if (!$user->can_administer($prow)
            && ($extrev_chairreq === 1
                || ($extrev_chairreq === 2 && $potconflict))) {
            $result = $prow->conf->qe("insert into ReviewRequest set paperId=?, email=?, firstName=?, lastName=?, affiliation=?, requestedBy=?, timeRequested=?, reason=?, reviewRound=? on duplicate key update paperId=paperId",
                $prow->paperId, $email, $xreviewer->firstName, $xreviewer->lastName,
                $xreviewer->affiliation, $user->contactId, $Now, $reason, $round);
            if ($extrev_chairreq === 2) {
                $msg = "<p>" . Text::user_html($xreviewer) . " has a potential conflict with this submission, so an administrator must approve your proposed external review before it can take effect.</p>";
                if ($user->can_view_authors($prow))
                    $msg .= $potconflict[1];
            } else
                $msg = "Proposed an external review from " . Text::user_html($xreviewer) . ". An administrator must approve this proposal for it to take effect.";
            $user->log_activity("Logged proposal for $email to review", $prow);
            return new JsonResult(["ok" => true, "action" => "propose", "response" => $msg]);
        }

        // if we get here, we will (try to) assign a review

        // create account
        if (!$reviewer)
            $reviewer = Contact::create($user->conf, $user, $xreviewer);
        if (!$reviewer)
            return new JsonResult(400, "Error while creating account.");

        // check requester
        $requester = null;
        if ($request
            && $user->can_administer($prow))
            $requester = $user->conf->cached_user_by_id($request->requestedBy);
        $requester = $requester ? : $user;

        // assign review
        $user->assign_review($prow->paperId, $reviewer->contactId, REVIEW_EXTERNAL,
                             ["mark_notify" => true,
                              "requester_contact" => $requester,
                              "requested_email" => $reviewer->email,
                              "round_number" => $round]);

        // send confirmation mail
        HotCRPMailer::send_to($reviewer, "@requestreview", $prow,
                              ["requester_contact" => $requester,
                               "reason" => $reason]);

        return new JsonResult(["ok" => true, "action" => "request", "response" => "Requested an external review from " . Text::user_html($reviewer) . "."]);
    }

    static function requestreview_anonymous($user, $qreq, $prow) {
        if (trim((string) $qreq->firstName) !== ""
            || trim((string) $qreq->lastName) !== "")
            return new JsonResult(400, "An email address is required to request a review.");
        if (!$user->allow_administer($prow))
            return new JsonResult(403, "Only administrators can request anonymous reviews.");
        $aset = new AssignmentSet($user, true);
        $aset->enable_papers($prow);
        $aset->parse("paper,action,user\n{$prow->paperId},review,newanonymous\n");
        if ($aset->execute()) {
            $aset_csv = $aset->unparse_csv();
            assert(count($aset_csv->data) === 1);
            return new JsonResult(["ok" => true, "action" => "token", "review_token" => $aset_csv->data[0]["review_token"]]);
        } else
            return new JsonResult(400, ["ok" => false, "error" => $aset->errors_div_html()]);
    }

    static function denyreview($user, $qreq, $prow) {
        global $Now;
        if (!$user->allow_administer($prow))
            return new JsonResult(403, "Permission error.");
        $email = trim((string) $qreq->email);
        if ($email === "")
            return self::error_result(400, "email", "Invalid email address.");
        $user->conf->qe("lock tables ReviewRequest write, PaperReviewRefused write, ContactInfo read");
        // Need to be careful and not expose inappropriate information:
        // this email comes from the chair, who can see all, but goes to a PC
        // member, who can see less.
        if (($request = $user->conf->fetch_first_object("select * from ReviewRequest where paperId=? and email=?", $prow->paperId, trim($qreq->email)))) {
            $requester = $user->conf->user_by_id($request->requestedBy);
            $reviewer = $user->conf->user_by_email($email);
            $reason = trim((string) $qreq->reason);

            $user->conf->qe("delete from ReviewRequest where paperId=? and email=?",
                $prow->paperId, $email);
            $user->conf->qe("insert into PaperReviewRefused set paperId=?, email=?, contactId=?, requestedBy=?, timeRequested=?, refusedBy=?, timeRefused=?, reason=?",
                $prow->paperId, $email, $reviewer ? $reviewer->contactId : 0,
                $request->requestedBy, $request->timeRequested,
                $user->contactId, $Now, $reason);
            Dbl::qx_raw("unlock tables");

            $reviewer_contact = (object) [
                "firstName" => $reviewer ? $reviewer->firstName : $request->firstName,
                "lastName" => $reviewer ? $reviewer->lastName : $request->lastName,
                "email" => $email
            ];
            HotCRPMailer::send_to($requester, "@denyreviewrequest", $prow,
                ["reviewer_contact" => $reviewer_contact]);

            $user->log_activity_for($requester, "Denied proposal for $email to review", $prow);
            return new JsonResult(["ok" => true, "action" => "deny"]);
        } else {
            Dbl::qx_raw("unlock tables");
            return self::error_result(404, "email", "No such request.");
        }
    }

    static function declinereview($user, $qreq, $prow) {
        global $Now;
        $xrrows = $refusals = [];
        $email = trim($qreq->email);
        if ($email === "" || $email === "me")
            $email = $user->email;
        $reason = trim($qreq->reason);
        if ($reason === "" || $reason === "Optional explanation")
            $reason = null;

        if (!$prow
            && ctype_digit($qreq->p)
            && strcasecmp($email, $user->email) === 0) {
            $xprow = $user->conf->fetch_paper(intval($qreq->p), $user);
            if ($xprow && $xprow->review_refusals_of_user($user))
                $prow = $xprow;
        }
        if (!$prow)
            return $user->conf->paper_error_json_result($qreq->annex("paper_whynot"));
        $prow->ensure_reviewer_names();

        if (!$user->can_administer($prow)
            && strcasecmp($email, $user->email) !== 0)
            return self::error_result(403, "email", "Permission error.");
        if (($u = $user->conf->cached_user_by_email($email))) {
            $xrrows = $prow->reviews_of_user($u);
            $refusals = $prow->review_refusals_of_user($u);
        } else
            $refusals = $prow->review_refusals_of_email($email);

        if (empty($xrrows) && empty($refusals))
            return self::error_result(404, null, "No reviews to decline.");

        $rrows = array_filter($xrrows, function ($rrow) {
            return !$rrow->reviewSubmitted && $rrow->reviewType < REVIEW_SECONDARY;
        });
        if (empty($rrows) && !empty($xrrows)) {
            if ($xrrows[0]->reviewSubmitted)
                return self::error_result(403, "r", "This review has already been submitted.");
            else
                return self::error_result(403, "r", "Primary and secondary reviews can’t be declined. Contact the PC chairs directly if you really cannot finish this review.");
        }

        // commit refusal to database
        $user->conf->qe_raw("lock tables PaperReview write, PaperReviewRefused write");

        $had_token = true;
        foreach ($rrows as $rrow) {
            $user->conf->qe("insert into PaperReviewRefused set paperId=?, email=?, contactId=?, requestedBy=?, timeRequested=?, refusedBy=?, timeRefused=?, reason=?
                on duplicate key update reason=coalesce(values(reason),reason)",
                $prow->paperId, $rrow->email, $rrow->contactId,
                $rrow->requestedBy, $rrow->timeRequested,
                $user->contactId, $Now, $reason);
            $user->conf->qe("delete from PaperReview where paperId=? and reviewId=?",
                $prow->paperId, $rrow->reviewId);
            if ($rrow->reviewType < REVIEW_SECONDARY && $rrow->requestedBy > 0)
                $user->update_review_delegation($prow->paperId, $rrow->requestedBy, -1);
            if ($rrow->reviewToken)
                $had_token = true;
        }
        if (empty($rrows) && $reason !== null) {
            $user->conf->qe("update PaperReviewRefused set reason=? where paperId=? and email=?",
                $reason, $prow->paperId, $email);
        }

        $user->conf->qe_raw("unlock tables");

        // send mail to requesters
        // XXX delay this mail by a couple minutes
        foreach ($rrows as $rrow) {
            $requser = null;
            if ($rrow->requestedBy > 0)
                $requser = $user->conf->user_by_id($rrow->requestedBy);
            if ($requser) {
                $reqprow = $user->conf->fetch_paper($prow->paperId, $requser);
                HotCRPMailer::send_to($requser, "@refusereviewrequest", $reqprow,
                    ["reviewer_contact" => $rrow, "reason" => $reason]);
            }
            $user->log_activity_for($rrow->contactId, "Refused review request", $prow);
        }

        if ($had_token)
            $user->conf->update_rev_tokens_setting(-1);
        $result = ["ok" => true, "action" => "decline"];
        if ($qreq->redirect)
            $user->conf->confirmMsg("Thank you for telling us that you are unable to review submission #{$prow->paperId}.");
        return new JsonResult(["ok" => true, "action" => "decline"]);
    }

    static function retractreview($user, $qreq, $prow) {
        global $Now;
        $xrrows = $xrequests = [];
        $email = trim($qreq->email);
        if ($email === "")
            return self::error_result(400, "email", "Bad request.");

        if (($u = $user->conf->cached_user_by_email($email)))
            $xrrows = $prow->reviews_of_user($u);
        $result = $user->conf->qe("select * from ReviewRequest where paperId=? and email=?",
            $prow->paperId, $email);
        while (($req = $result->fetch_object()))
            $xrequests[] = $req;
        Dbl::free($result);

        $rrows = array_filter($xrrows, function ($rrow) use ($user, $prow) {
            return $rrow->reviewModified <= 1
                && ($user->can_administer($prow)
                    || ($user->contactId && $user->contactId == $rrow->requestedBy));
        });
        $requests = array_filter($xrequests, function ($req) use ($user, $prow) {
            return $user->can_administer($prow)
                || ($user->contactId && $user->contactId == $req->requestedBy);
        });

        if (empty($rrows) && empty($requests)) {
            if (!empty($xrrows)
                && ($user->can_administer($prow)
                    || ($user->contactId && $user->contactId == $xrrows[0]->requestedBy)))
                return self::error_result(403, "r", "This review can’t be retracted because the reviewer has already begun their work.");
            else
                return self::error_result(404, null, "No reviews to retract.");
        }

        // commit retraction to database
        foreach ($rrows as $rrow) {
            $user->conf->qe("delete from PaperReview where paperId=? and reviewId=?",
                $prow->paperId, $rrow->reviewId);
            $user->update_review_delegation($prow->paperId, $rrow->requestedBy, -1);
            if ($rrow->reviewToken)
                $user->conf->update_rev_tokens_setting(0);
        }
        foreach ($requests as $req) {
            $user->conf->qe("delete from ReviewRequest where paperId=? and email=?",
                $prow->paperId, $req->email);
        }

        // send mail to reviewer
        if ($user->conf->time_review_open()) {
            foreach ($rrows as $rrow) {
                if (($reviewer = $user->conf->cached_user_by_id($rrow->contactId))) {
                    $cc = Text::user_email_to($user);
                    if (($requester = $user->conf->cached_user_by_id($rrow->requestedBy))
                        && $requester->contactId != $user->contactId)
                        $cc .= ", " . Text::user_email_to($requester);
                    HotCRPMailer::send_to($reviewer, "@retractrequest", $prow,
                        ["requester_contact" => $user, "cc" => $cc]);
                }
            }
        }

        return new JsonResult(["ok" => true, "action" => "retract"]);
    }

    static function error_result($status, $errf, $message) {
        if (is_string($errf))
            $errf = [$errf => true];
        return new JsonResult($status, ["ok" => false, "error" => $message, "errf" => $errf]);
    }
}
