<?php
// api_requestreview.php -- HotCRP review-request API calls
// Copyright (c) 2008-2023 Eddie Kohler; see LICENSE.

class RequestReview_API {
    /** @param Contact $user
     * @param Qrequest $qreq
     * @param PaperInfo $prow
     * @return JsonResult */
    static function requestreview($user, $qreq, $prow) {
        $round = null;
        if ((string) $qreq->round !== ""
            && ($rname = $user->conf->sanitize_round_name($qreq->round)) !== false) {
            $round = (int) $user->conf->round_number($rname);
        }

        if (($whyNot = $user->perm_request_review($prow, $round, true))) {
            return JsonResult::make_error(403, "<5>" . $whyNot->unparse_html());
        }
        if (!isset($qreq->email)) {
            return JsonResult::make_missing_error("email");
        }
        $email = $qreq->email = trim($qreq->email);
        if ($email === "" || $email === "newanonymous") {
            return self::requestreview_anonymous($user, $qreq, $prow);
        }

        $reviewer = $user->conf->user_by_email($email);
        if (!$reviewer && ($email === "" || !validate_email($email))) {
            return JsonResult::make_parameter_error("email", "<0>Invalid email address");
        }

        $name_args = Author::make_keyed([
            "firstName" => $qreq->firstName,
            "lastName" => $qreq->lastName,
            "name" => $qreq->name,
            "affiliation" => $qreq->affiliation,
            "email" => $email
        ]);
        $reason = trim($qreq->reason ?? "");

        // check proposal:
        // - check existing review
        if ($reviewer && $prow->review_by_user($reviewer)) {
            return JsonResult::make_parameter_error("email", "<0>{$email} is already a reviewer");
        }
        // - check existing request
        $request = $user->conf->fetch_first_object("select * from ReviewRequest where paperId=? and email=?", $prow->paperId, $email);
        if ($request && !$user->allow_administer($prow)) {
            return JsonResult::make_parameter_error("email", "<0>{$email} is already a requested reviewer");
        }
        // - check existing refusal
        if ($reviewer) {
            $refusal = ($prow->review_refusals_by_user($reviewer))[0] ?? null;
        } else {
            $refusal = ($prow->review_refusals_by_email($email))[0] ?? null;
        }
        if ($refusal
            && (!$user->can_administer($prow) || !$qreq->override)) {
            $ml = [];
            if ($reviewer
                && ($refusal->refusedBy == $reviewer->contactId
                    || ($refusal->refusedBy === null && $refusal->reason !== "request denied by chair"))) {
                $ml[] = new MessageItem("email", "<5>" . $reviewer->name_h(NAME_P) . " has declined to review this submission", 2);
            } else {
                $ml[] = new MessageItem("email", "<0>An administrator denied a previous request for {$email} to review this submission", 2);
            }
            if ($refusal->reason !== "" && $refusal->reason !== "request denied by chair") {
                $ml[] = new MessageItem("email", "<5>They offered this reason: “" . htmlspecialchars($refusal->reason) . "”", MessageSet::INFORM);
            }
            if ($user->allow_administer($prow)) {
                $ml[] = new MessageItem("override", "", 2);
            }
            return new JsonResult(["ok" => false, "message_list" => $ml]);
        }
        // - check conflict
        if ($reviewer
            && ($prow->has_conflict($reviewer)
                || ($reviewer->isPC && !$reviewer->can_accept_review_assignment($prow)))) {
            return JsonResult::make_parameter_error("email", "<0>{$email} cannot be asked to review this submission");
        }

        // check for potential conflict
        $xreviewer = $reviewer
            ?? $user->conf->cdb_user_by_email($email)
            ?? Contact::make_keyed($user->conf, $name_args->unparse_nea_json());
        $potconf = $prow->potential_conflict_html($xreviewer);

        // check requester
        if ($request && $user->can_administer($prow)) {
            $requester = $user->conf->user_by_id($request->requestedBy, USER_SLICE) ?? $user;
        } else {
            $requester = $user;
        }

        // check whether to make a proposal
        $extrev_chairreq = $user->conf->setting("extrev_chairreq");
        if ($user->can_administer($prow)
            ? $potconf && !$qreq->override
            : $extrev_chairreq === 1
              || ($extrev_chairreq === 2 && $potconf)) {
            $prow->conf->qe("insert into ReviewRequest set paperId=?, email=?, firstName=?, lastName=?, affiliation=?, requestedBy=?, timeRequested=?, reason=?, reviewRound=? on duplicate key update paperId=paperId",
                $prow->paperId, $email, $xreviewer->firstName, $xreviewer->lastName,
                $xreviewer->affiliation, $user->contactId, Conf::$now, $reason, $round);
            $ml = [];
            if ($user->can_administer($prow)) {
                $ml[] = new MessageItem("email", "<5>" . $xreviewer->name_h(NAME_E) . " has a potential conflict with this submission, so you must approve this request for it to take effect", MessageSet::WARNING_NOTE);
                $ml[] = new MessageItem("email", "<5>" . PaperInfo::potential_conflict_tooltip_html($potconf), MessageSet::INFORM);
            } else if ($extrev_chairreq === 2) {
                $ml[] = new MessageItem("email", "<5>" . $xreviewer->name_h(NAME_E) . " has a potential conflict with this submission, so an administrator must approve your proposed external review before it can take effect", MessageSet::WARNING_NOTE);
                if ($user->can_view_authors($prow)) {
                    $ml[] = new MessageItem("email", "<5>" . PaperInfo::potential_conflict_tooltip_html($potconf), MessageSet::INFORM);
                }
            } else {
                $ml[] = new MessageItem("email", "<5>Proposed an external review from " . $xreviewer->name_h(NAME_E), MessageSet::WARNING_NOTE);
                $ml[] = new MessageItem("email", "<0>An administrator must approve this proposal for it to take effect.", MessageSet::INFORM);
            }
            $user->log_activity("Review proposal added for $email", $prow);
            $prow->conf->update_automatic_tags($prow, "review");
            HotCRPMailer::send_administrators("@proposereview", $prow,
                                              ["requester_contact" => $requester,
                                               "reviewer_contact" => $xreviewer,
                                               "reason" => $reason]);
            return new JsonResult(["ok" => true, "action" => "propose", "message_list" => $ml]);
        }

        // if we get here, we will (try to) assign a review

        // create account
        $reviewer = $reviewer ?? $xreviewer->store(0, $user);
        if (!$reviewer) {
            return JsonResult::make_error(400, "<0>Review assignment error: Could not create account");
        } else if ($reviewer->is_disabled()) {
            return JsonResult::make_error(403, "<0>Review assignment error: The account for " . $reviewer->name(NAME_E) . " is disabled");
        }

        // assign review
        $user->assign_review($prow->paperId, $reviewer->contactId, REVIEW_EXTERNAL,
                             ["mark_notify" => true,
                              "requester_contact" => $requester,
                              "requested_email" => $reviewer->email,
                              "round_number" => $round]);

        // send confirmation mail
        HotCRPMailer::send_to($reviewer, "@requestreview", [
            "prow" => $prow, "rrow" => $prow->fresh_review_by_user($reviewer),
            "requester_contact" => $requester, "reason" => $reason
        ]);

        $mi = new MessageItem("email", "<5>Requested an external review from " . $reviewer->name_h(NAME_E), MessageSet::SUCCESS);
        return new JsonResult(["ok" => true, "action" => "request", "message_list" => [$mi]]);
    }

    /** @param Contact $user
     * @param Qrequest $qreq
     * @param PaperInfo $prow
     * @return JsonResult */
    static function requestreview_anonymous($user, $qreq, $prow) {
        if (trim((string) $qreq->firstName) !== ""
            || trim((string) $qreq->lastName) !== "") {
            return JsonResult::make_error(400, "<0>An email address is required to request a review");
        }
        if (!$user->allow_administer($prow)) {
            return JsonResult::make_error(403, "<0>Only administrators can request anonymous reviews");
        }
        $aset = (new AssignmentSet($user))->set_override_conflicts(true);
        $aset->enable_papers($prow);
        $aset->parse("paper,action,user\n{$prow->paperId},review,newanonymous\n");
        if ($aset->execute()) {
            $aset_csv = $aset->make_acsv();
            assert($aset_csv->count() === 1);
            $row = $aset_csv->row(0);
            assert(isset($row["review_token"]));
            $token = $row["review_token"];
            $mi = MessageItem::success("<0>Created anonymous review with review token ‘{$token}’");
            return new JsonResult(["ok" => true, "action" => "token", "review_token" => $token, "message_list" => [$mi]]);
        } else {
            return new JsonResult(["ok" => false, "message_list" => $aset->message_list()]);
        }
    }

    /** @param Contact $user
     * @param Qrequest $qreq
     * @param PaperInfo $prow
     * @return JsonResult */
    static function denyreview($user, $qreq, $prow) {
        if (!$user->allow_administer($prow)) {
            return JsonResult::make_permission_error();
        }
        $email = trim((string) $qreq->email);
        if ($email === "") {
            return JsonResult::make_parameter_error("email", "<0>Invalid email address");
        }
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
            $user->conf->qe("insert into PaperReviewRefused set paperId=?, email=?, contactId=?, firstName=?, lastName=?, affiliation=?, requestedBy=?, timeRequested=?, refusedBy=?, timeRefused=?, reason=?, refusedReviewType=?, reviewRound=?",
                $prow->paperId, $email, $reviewer ? $reviewer->contactId : 0,
                $request->firstName, $request->lastName, $request->affiliation,
                $request->requestedBy, $request->timeRequested,
                $user->contactId, Conf::$now, $reason, REVIEW_EXTERNAL,
                $request->reviewRound);
            Dbl::qx_raw("unlock tables");

            $reviewer_contact = Author::make_keyed([
                "firstName" => $reviewer ? $reviewer->firstName : $request->firstName,
                "lastName" => $reviewer ? $reviewer->lastName : $request->lastName,
                "affiliation" => $reviewer ? $reviewer->affiliation : $request->affiliation,
                "email" => $email
            ]);
            HotCRPMailer::send_to($requester, "@denyreviewrequest", [
                "prow" => $prow,
                "reviewer_contact" => $reviewer_contact,
                "reason" => $reason
            ]);

            $user->log_activity_for($requester, "Review proposal denied for $email", $prow);
            $prow->conf->update_automatic_tags($prow, "review");
            return new JsonResult(["ok" => true, "action" => "deny"]);
        } else {
            Dbl::qx_raw("unlock tables");
            return JsonResult::make_not_found_error("email", "<0>No such request");
        }
    }

    /** @param Contact $user
     * @param PaperInfo $prow
     * @param ReviewInfo|ReviewRefusalInfo $remrow
     * @return bool */
    static function allow_accept_decline($user, $prow, $remrow) {
        if ($user->can_administer($prow)) {
            return true;
        } else if ($remrow instanceof ReviewInfo) {
            return $user->is_my_review($remrow);
        } else {
            return $user->contactXid === $remrow->contactId
                || ($remrow->email && strcasecmp($user->email, $remrow->email) === 0)
                || $user->capability("@ra{$prow->paperId}") == $remrow->contactId;
        }
    }

    /** @param Contact $user
     * @param Qrequest $qreq
     * @return ?PaperInfo */
    static private function review_refusal_paper($user, $qreq) {
        if (ctype_digit($qreq->p ?? "X")
            && ($prow = $user->conf->paper_by_id(intval($qreq->p), $user))
            && $prow->review_refusals_by_user($user)) {
            return $prow;
        }
        return null;
    }

    /** @param Contact $user
     * @param Qrequest $qreq
     * @param PaperInfo $prow
     * @return JsonResult */
    static function acceptreview($user, $qreq, $prow) {
        // check `p` parameter
        // maybe user can view paper because of a declined review
        $prow = $prow ?? self::review_refusal_paper($user, $qreq);
        if (!$prow) {
            return $user->conf->paper_error_json_result($qreq->annex("paper_whynot"));
        }

        // check `r` parameter, set redirect
        if (!ctype_digit($qreq->r ?? "X")) {
            return JsonResult::make_parameter_error("r");
        }
        $r = intval($qreq->r);
        $review_site_relative = $prow->conf->hoturl_raw("review", ["p" => $prow->paperId, "r" => $r], Conf::HOTURL_SITEREL);
        if ($qreq->redirect === "1") {
            $qreq->redirect = $review_site_relative;
        }

        $rrow = $prow->review_by_id($r);
        $refrow = $prow->review_refusal_by_id($r);
        if (!$rrow && !$refrow) {
            if ($user->can_administer($prow)
                || $user->can_view_review($prow, null)) {
                return JsonResult::make_not_found_error("r", "<0>No such review");
            } else {
                return JsonResult::make_permission_error("r");
            }
        } else if (!self::allow_accept_decline($user, $prow, $rrow ?? $refrow)) {
            return JsonResult::make_permission_error("r");
        }

        if (!$rrow) {
            $prow->conf->qe("insert into PaperReview set paperId=?, reviewId=?, contactId=?, requestedBy=?, timeRequested=?, reviewType=?, reviewRound=?, data=?",
                $prow->paperId, $refrow->refusedReviewId, $refrow->contactId,
                $refrow->requestedBy, $refrow->timeRequested,
                $refrow->refusedReviewType, $refrow->reviewRound, $refrow->data);
            $prow->conf->qe("delete from PaperReviewRefused where refusedReviewId=?",
                $refrow->refusedReviewId);
            $rrow = $prow->fresh_review_by_id($refrow->refusedReviewId);
        }

        if ($rrow->reviewStatus < ReviewInfo::RS_ACCEPTED) {
            $prow->conf->qe("update PaperReview set reviewModified=1, timeRequestNotified=greatest(?,timeRequestNotified)
                where paperId=? and reviewId=? and reviewModified<=0",
                Conf::$now, $prow->paperId, $rrow->reviewId); /* XXX PaperReviewHistory? */
            $user->log_activity_for($rrow->contactId, "Review {$rrow->reviewId} accepted", $prow);

            // send mail to requesters
            // XXX delay this mail by a couple minutes
            if ($rrow->requestedBy > 0
                && ($requser = $user->conf->user_by_id($rrow->requestedBy))) {
                HotCRPMailer::send_to($requser, "@acceptreviewrequest", [
                    "prow" => $prow, "reviewer_contact" => $rrow->reviewer()
                ]);
            }
        }

        return new JsonResult([
            "ok" => true,
            "action" => "accept",
            "review_site_relative" => $review_site_relative
        ]);
    }

    /** @param Contact $user
     * @param Qrequest $qreq
     * @param PaperInfo $prow
     * @return JsonResult */
    static function declinereview($user, $qreq, $prow) {
        // check `p` parameter
        // maybe user can view paper because of a declined review
        $prow = $prow ?? self::review_refusal_paper($user, $qreq);
        if (!$prow) {
            return $user->conf->paper_error_json_result($qreq->annex("paper_whynot"));
        }
        $prow->ensure_full_reviews();
        $prow->ensure_reviewer_names();

        // check `r` parameter, set redirect
        if (!ctype_digit($qreq->r ?? "X")) {
            return JsonResult::make_parameter_error("r");
        }
        $r = intval($qreq->r);
        $review_site_relative = $prow->conf->hoturl_raw("review", ["p" => $prow->paperId, "r" => $r], Conf::HOTURL_SITEREL);
        $redirect_in = $qreq->redirect;
        if ($redirect_in === "1") {
            $qreq->redirect = $review_site_relative;
        }

        $reason = trim($qreq->reason ?? "");
        if ($reason === "" || $reason === "Optional explanation") {
            $reason = null;
        }

        $rrow = $prow->review_by_id($r);
        $refrow = $prow->review_refusal_by_id($r);
        if (!$rrow && !$refrow) {
            if ($user->can_administer($prow)
                || $user->can_view_review($prow, null)) {
                return JsonResult::make_not_found_error("r", "<0>Review not found");
            } else {
                return JsonResult::make_permission_error("r");
            }
        } else if (!self::allow_accept_decline($user, $prow, $rrow ?? $refrow)) {
            return JsonResult::make_permission_error("r");
        } else if ($rrow && $rrow->reviewStatus >= ReviewInfo::RS_DELIVERED) {
            return JsonResult::make_permission_error("r", "<0>Review has already been submitted");
        } else if ($rrow && $rrow->reviewType >= REVIEW_SECONDARY) {
            $jr = JsonResult::make_permission_error("r", "<0>Primary and secondary reviews can’t be declined");
            $jr->content["message_list"][] = new MessageItem("r", "Contact the PC chairs directly if you really cannot finish this review.", MessageSet::INFORM);
            return $jr;
        }
        $rrid = $rrow ? $rrow->reviewId : $refrow->refusedReviewId;

        // commit refusal to database
        if ($rrow) {
            $prow->conf->qe("insert into PaperReviewRefused set paperId=?, email=?, contactId=?, requestedBy=?, timeRequested=?, refusedBy=?, timeRefused=?, reason=?, refusedReviewType=?, refusedReviewId=?, reviewRound=?, data=?
                on duplicate key update reason=coalesce(?,reason)",
                $prow->paperId, $rrow->reviewer()->email, $rrow->contactId,
                $rrow->requestedBy, $rrow->timeRequested,
                $user->contactId, Conf::$now, $reason, $rrow->reviewType,
                $rrid, $rrow->reviewRound, $rrow->data_string(),
                $reason);
            $prow->conf->qe("delete from PaperReview where paperId=? and reviewId=?",
                $prow->paperId, $rrid);

            if ($rrow->reviewType < REVIEW_SECONDARY && $rrow->requestedBy > 0) {
                $user->update_review_delegation($prow->paperId, $rrow->requestedBy, -1);
            }
            if ($rrow->reviewToken) {
                $prow->conf->update_rev_tokens_setting(-1);
            }
            $prow->conf->update_automatic_tags($prow, "review");
            $user->log_activity_for($rrow->contactId, "Review $rrow->reviewId declined", $prow);

            // send mail to requesters
            // XXX delay this mail by a couple minutes
            if ($rrow->requestedBy > 0
                && ($requser = $user->conf->user_by_id($rrow->requestedBy))) {
                HotCRPMailer::send_to($requser, "@declinereviewrequest", [
                    "prow" => $prow,
                    "reviewer_contact" => $rrow->reviewer(),
                    "reason" => $reason
                ]);
            }

            // maybe add capability to URL; otherwise user will immediately be
            // denied access
            if ($user->contactXid === $rrow->contactId
                && ($tok = ReviewAccept_Capability::make($rrow, true))) {
                $review_site_relative = $prow->conf->hoturl_raw("review", ["p" => $prow->paperId, "r" => $r, "cap" => $tok->salt], Conf::HOTURL_SITEREL);
                if ($redirect_in === "1") {
                    $qreq->redirect = $review_site_relative;
                }
            }
        } else if (isset($qreq->reason)) {
            $prow->conf->qe("update PaperReviewRefused set reason=? where paperId=? and refusedReviewId=?", $reason, $prow->paperId, $rrid);
        } else {
            $reason = $refrow->reason;
        }

        return new JsonResult([
            "ok" => true,
            "action" => "decline",
            "reason" => $reason,
            "review_site_relative" => $review_site_relative
        ]);
    }

    /** @param Contact $user
     * @param Qrequest $qreq
     * @param PaperInfo $prow
     * @return JsonResult */
    static function claimreview($user, $qreq, $prow) {
        if (!ctype_digit($qreq->r ?? "X")) {
            return JsonResult::make_parameter_error("r");
        }
        $r = intval($qreq->r);
        $review_site_relative = $prow->conf->hoturl_raw("review", ["p" => $prow->paperId, "r" => $r], Conf::HOTURL_SITEREL);
        $redirect_in = $qreq->redirect;
        if ($redirect_in === "1") {
            $qreq->redirect = $review_site_relative;
        }

        $rrow = $prow->review_by_id($r);
        if (!$rrow) {
            if ($user->can_administer($prow)
                || $user->can_view_review($prow, null)) {
                return JsonResult::make_not_found_error("r", "<0>Review not found");
            } else {
                return JsonResult::make_permission_error("r");
            }
        } else if (!self::allow_accept_decline($user, $prow, $rrow)) {
            return JsonResult::make_permission_error("r");
        } else if ($rrow->reviewStatus > ReviewInfo::RS_DRAFTED) {
            return JsonResult::make_permission_error("r", "<0>Reviews cannot be reassigned after they are submitted");
        }

        $email = $qreq->email;
        if (!$email
            || ($useridx = Contact::session_index_by_email($qreq, $email)) < 0) {
            return JsonResult::make_permission_error("email", "<0>Reassigning reviews is only possible for accounts to which you are currently signed in");
        }

        $destu = $user->conf->user_by_email($email, USER_SLICE)
            ?? $user->conf->cdb_user_by_email($email);
        if ($destu && !$destu->is_disabled()) {
            $destu->ensure_account_here();
        }
        if (!$destu || $destu->is_disabled() || !$destu->has_account_here()) {
            return JsonResult::make_permission_error("email", "<0>That account is not enabled here");
        }

        $prow->conf->qe("update PaperReview set contactId=? where paperId=? and reviewId=? and contactId=? and reviewSubmitted is null and timeApprovalRequested<=0",
            $destu->contactId, $prow->paperId, $rrow->reviewId, $rrow->contactId);
        $oldu = $user->conf->user_by_id($rrow->contactId, USER_SLICE);
        $user->log_activity_for($destu->contactId, "Review {$rrow->reviewId} reassigned from " . ($oldu ? $oldu->email : "<user {$rrow->contactId}>"), $prow);

        if ($destu->contactXid !== $user->contactXid) {
            $review_site_relative = "u/{$useridx}/{$review_site_relative}";
            if ($redirect_in === "1") {
                $qreq->redirect = $review_site_relative;
            }
        }
        return new JsonResult([
            "ok" => true,
            "action" => "claim",
            "review_site_relative" => $review_site_relative
        ]);
    }

    /** @param Contact $user
     * @param Qrequest $qreq
     * @param PaperInfo $prow
     * @return JsonResult */
    static function retractreview($user, $qreq, $prow) {
        $xrrows = $xrequests = [];
        $email = trim($qreq->email);
        if ($email === "") {
            return JsonResult::make_missing_error("email");
        }

        if (($u = $user->conf->user_by_email($email, USER_SLICE))) {
            $xrrows = $prow->reviews_by_user($u);
        }
        $result = $user->conf->qe("select * from ReviewRequest where paperId=? and email=?",
            $prow->paperId, $email);
        while (($req = ReviewRequestInfo::fetch($result, $user->conf))) {
            $xrequests[] = $req;
        }
        Dbl::free($result);

        $rrows = array_filter($xrrows, function ($rrow) use ($user, $prow) {
            return $rrow->reviewStatus < ReviewInfo::RS_DRAFTED
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
                    || ($user->contactId && $user->contactId == $xrrows[0]->requestedBy))) {
                return JsonResult::make_permission_error("r", "<0>This review can’t be retracted because the reviewer has already begun their work");
            } else {
                return JsonResult::make_not_found_error(null, "<0>No reviews to retract");
            }
        }

        // commit retraction to database
        foreach ($rrows as $rrow) {
            $user->conf->qe("delete from PaperReview where paperId=? and reviewId=?",
                $prow->paperId, $rrow->reviewId);
            $user->update_review_delegation($prow->paperId, $rrow->requestedBy, -1);
            if ($rrow->reviewToken) {
                $user->conf->update_rev_tokens_setting(0);
            }
            $user->log_activity_for($rrow->contactId, "Review $rrow->reviewId retracted", $prow);
        }
        foreach ($requests as $req) {
            $user->conf->qe("delete from ReviewRequest where paperId=? and email=?",
                $prow->paperId, $req->email);
            $user->log_activity("Review proposal retracted for $req->email", $prow);
        }

        $prow->conf->update_automatic_tags($prow, "review");

        // send mail to reviewer
        $notified = false;
        if ($user->conf->time_review_open()) {
            foreach ($rrows as $rrow) {
                if (($reviewer = $user->conf->user_by_id($rrow->contactId, USER_SLICE))) {
                    $cc = Text::nameo($user, NAME_MAILQUOTE|NAME_E);
                    if (($requester = $user->conf->user_by_id($rrow->requestedBy, USER_SLICE))
                        && $requester->contactId != $user->contactId) {
                        $cc .= ", " . Text::nameo($requester, NAME_MAILQUOTE|NAME_E);
                    }
                    HotCRPMailer::send_to($reviewer, "@retractrequest", [
                        "prow" => $prow,
                        "requester_contact" => $user, "cc" => $cc
                    ]);
                    $notified = true;
                }
            }
        }

        return new JsonResult(["ok" => true, "action" => "retract", "notified" => $notified]);
    }

    /** @param Contact $user
     * @param Qrequest $qreq
     * @param ?PaperInfo $prow
     * @return JsonResult */
    static function undeclinereview($user, $qreq, $prow) {
        $email = trim($qreq->email);
        if ($email === "" || $email === "me") {
            $email = $user->email;
        }

        if (!$prow
            && ctype_digit($qreq->p ?? "X")
            && strcasecmp($email, $user->email) === 0) {
            $xprow = $user->conf->paper_by_id(intval($qreq->p), $user);
            if ($xprow && $xprow->review_refusals_by_user($user)) {
                $prow = $xprow;
            }
        }
        if (!$prow) {
            return $user->conf->paper_error_json_result($qreq->annex("paper_whynot"));
        }

        // check permissions
        if (!$user->can_administer($prow)
            && strcasecmp($email, $user->email) !== 0) {
            return JsonResult::make_permission_error("email");
        }

        $refusals = $prow->review_refusals_by_email($email);
        if (empty($refusals)) {
            return JsonResult::make_not_found_error(null, "<0>No reviews declined");
        }

        if (!$user->can_administer($prow)) {
            $xrefusals = array_filter($refusals, function ($ref) use ($user) {
                return $ref->contactId == $user->contactId;
            });
            if (empty($xrefusals)) {
                return JsonResult::make_not_found_error(null, "<0>No reviews declined");
            } else if (count($xrefusals) !== count($refusals)) {
                return JsonResult::make_permission_error();
            }
        }

        // remove refusal from database
        $user->conf->qe("delete from PaperReviewRefused where paperId=? and email=?",
                $prow->paperId, $email);

        if ($refusals[0]->contactId) {
            $user->log_activity_for($refusals[0]->contactId, "Review undeclined", $prow);
        } else {
            $user->log_activity("Review undeclined for <{$refusals[0]->email}>", $prow);
        }

        return new JsonResult(["ok" => true, "action" => "undecline"]);
    }
}
