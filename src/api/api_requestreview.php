<?php
// api_requestreview.php -- HotCRP user-related API calls
// Copyright (c) 2008-2018 Eddie Kohler; see LICENSE.

class RequestReview_API {
    static function requestreview($user, $qreq, $prow) {
        if (($whyNot = $user->perm_request_review($prow, true)))
            return new JsonResult(403, ["ok" => false, "error" => whyNotText($whyNot)]);
        if (!isset($qreq->email))
            return new JsonResult(400, "Parameter error.");
        $email = $qreq->email = trim($qreq->email);
        if ($email === "" || $email === "newanonymous")
            return self::requestreview_anonymous($user, $qreq, $prow);

        $reviewer = $user->conf->user_by_email($email);
        if (!$reviewer && ($email === "" || !validate_email($email)))
            return self::error_result("email", "Invalid email address.");

        $round = null;
        if ((string) $qreq->round !== ""
            && ($rname = $user->conf->sanitize_round_name($qreq->round)) !== false)
            $round = (int) $user->conf->round_number($rname, false);

        $name_args = Text::analyze_name_args([(object) ["firstName" => $qreq->firstName, "lastName" => $qreq->lastName, "name" => $qreq->name, "affiliation" => $qreq->affiliation, "email" => $email]]);
        $reason = trim($qreq->reason);

        // check proposal:
        // - check existing review
        if ($reviewer && $prow->review_of_user($reviewer))
            return self::error_result("email", htmlspecialchars($email) . " is already a reviewer.");
        // - check existing request
        $request = $user->conf->fetch_first_object("select * from ReviewRequest where paperId=? and email=?", $prow->paperId, $email);
        if ($request && !$user->allow_administer($prow))
            return self::error_result("email", htmlspecialchars($email) . " is already a requested reviewer.");
        // - check existing refusal
        if ($reviewer
            && ($refusal = $user->conf->fetch_first_object("select * from PaperReviewRefused where paperId=? and contactId=?", $prow->paperId, $reviewer->contactId))
            && (!$user->can_administer($prow) || !$qreq->override)) {
            $errf = ["email" => true];
            $msg = htmlspecialchars($email) . " has already declined to review this paper.";
            if ((string) $refusal->reason !== "")
                $msg .= " They offered this reason: <blockquote>" . htmlspecialchars($refusal->reason) . "</blockquote>";
            if ($user->allow_administer($prow))
                $errf["override"] = true;
            return self::error_result($errf, $msg);
        }
        // - check conflict
        if ($reviewer
            && ($prow->has_conflict($reviewer)
                || ($reviewer->isPC && !$reviewer->can_accept_review_assignment($prow))))
            return self::error_result("email", htmlspecialchars($email) . " cannot be asked to review this paper.");

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
            return self::error_result("override", "<p>" . Text::user_html($xreviewer) . " has a potential conflict with this paper, so the request has not been made.</p>" . $potconflict[1]);

        // check whether to make a proposal
        $extrev_chairreq = $user->conf->setting("extrev_chairreq");
        if (!$user->can_administer($prow)
            && ($extrev_chairreq === 1
                || ($extrev_chairreq === 2 && $potconflict))) {
            $result = Dbl::qe("insert into ReviewRequest set paperId=?, email=?, firstName=?, lastName=?, affiliation=?, requestedBy=?, reason=?, reviewRound=? on duplicate key update paperId=paperId",
                $prow->paperId, $email, $xreviewer->firstName, $xreviewer->lastName,
                $xreviewer->affiliation, $user->contactId, $reason, $round);
            if ($extrev_chairreq === 2) {
                $msg = "<p>" . Text::user_html($xreviewer) . " has a potential conflict with this submission, so an administrator must approve your proposed external review before it can take effect.</p>";
                if ($user->can_view_authors($prow))
                    $msg .= $potconflict[1];
            } else
                $msg = "Proposed an external review from " . Text::user_html($xreviewer) . ". An administrator must approve this proposal for it to take effect.";
            $user->log_activity("Logged proposal for $email to review", $prow);
            return ["ok" => true, "action" => "propose", "response" => $msg];
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

        return ["ok" => true, "action" => "request", "response" => "Requested an external review from " . Text::user_html($reviewer) . "."];
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
            return ["ok" => true, "action" => "token", "review_token" => $aset_csv->data[0]["review_token"]];
        } else
            return new JsonResult(400, ["ok" => false, "error" => $aset->errors_div_html()]);
    }

    static function error_result($errf, $message) {
        if (is_string($errf))
            $errf = [$errf => true];
        return new JsonResult(400, ["ok" => false, "error" => $message, "errf" => $errf]);
    }
}
