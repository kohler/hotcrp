<?php
// assign.php -- HotCRP per-paper assignment/conflict management page
// HotCRP is Copyright (c) 2006-2016 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

require_once("src/initweb.php");
require_once("src/papertable.php");
require_once("src/reviewtable.php");
if ($Me->is_empty())
    $Me->escape();
$_REQUEST["forceShow"] = 1;
$Error = array();
// ensure site contact exists before locking tables
Contact::site_contact();


// header
function confHeader() {
    global $paperTable;
    PaperTable::do_header($paperTable, "assign", "assign");
}

function errorMsgExit($msg) {
    confHeader();
    $msg && Conf::msg_error($msg);
    Conf::$g->footer();
    exit;
}


// grab paper row
function loadRows() {
    global $prow, $rrows, $Conf, $Me;
    $Conf->paper = $prow = PaperTable::paperRow($whyNot);
    if (!$prow)
        errorMsgExit(whyNotText($whyNot, "view"));
    if (($whyNot = $Me->perm_request_review($prow, false))) {
        $wnt = whyNotText($whyNot, "request reviews for");
        error_go(hoturl("paper", array("p" => $prow->paperId, "ls" => @$_REQUEST["ls"])), $wnt);
    }
    $rrows = $Conf->reviewRow(array('paperId' => $prow->paperId, 'array' => 1), $whyNot);
}

function rrow_by_reviewid($rid) {
    global $rrows;
    foreach ($rrows as $rr)
        if ($rr->reviewId == $rid)
            return $rr;
    return null;
}


// forceShow
loadRows();



if (isset($_REQUEST["post"]) && $_REQUEST["post"] && !count($_POST)
    && !isset($_REQUEST["retract"]) && !isset($_REQUEST["add"])
    && !isset($_REQUEST["deny"]))
    $Conf->post_missing_msg();



// retract review request
function retractRequest($email, $prow, $confirm = true) {
    global $Conf, $Me;

    Dbl::qe_raw("lock tables PaperReview write, ReviewRequest write, ContactInfo read, PaperConflict read");
    $email = trim($email);
    // NB caller unlocks tables

    // check for outstanding review
    $contact_fields = "firstName, lastName, ContactInfo.email, password, roles, preferredEmail";
    $result = Dbl::qe("select reviewId, reviewType, reviewModified, reviewSubmitted, reviewToken, requestedBy, $contact_fields
                from ContactInfo
                join PaperReview on (PaperReview.paperId=$prow->paperId and PaperReview.contactId=ContactInfo.contactId)
                where ContactInfo.email=?", $email);
    $row = edb_orow($result);

    // check for outstanding review request
    $result2 = Dbl::qe("select name, email, requestedBy
                from ReviewRequest
                where paperId=$prow->paperId and email=?", $email);
    $row2 = edb_orow($result2);

    // act
    if (!$row && !$row2)
        return Conf::msg_error("No such review request.");
    if ($row && $row->reviewModified > 0)
        return Conf::msg_error("You can’t retract that review request since the reviewer has already started their review.");
    if (!$Me->allow_administer($prow)
        && (($row && $row->requestedBy && $Me->contactId != $row->requestedBy)
            || ($row2 && $row2->requestedBy && $Me->contactId != $row2->requestedBy)))
        return Conf::msg_error("You can’t retract that review request since you didn’t make the request in the first place.");

    // at this point, success; remove the review request
    if ($row)
        Dbl::qe_raw("delete from PaperReview where reviewId=$row->reviewId");
    if ($row2)
        Dbl::qe_raw("delete from ReviewRequest where paperId=$prow->paperId and email='" . sqlq($email) . "'");

    if (defval($row, "reviewToken", 0) != 0)
        $Conf->settings["rev_tokens"] = -1;
    // send confirmation email, if the review site is open
    if ($Conf->time_review_open() && $row) {
        $Reviewer = new Contact($row);
        HotCRPMailer::send_to($Reviewer, "@retractrequest", $prow,
                              array("requester_contact" => $Me,
                                    "cc" => Text::user_email_to($Me)));
    }

    // confirmation message
    if ($confirm)
        $Conf->confirmMsg("Removed request that " . Text::user_html($row ? $row : $row2) . " review paper #$prow->paperId.");
}

if (isset($_REQUEST["retract"]) && check_post()) {
    retractRequest($_REQUEST["retract"], $prow);
    Dbl::qe_raw("unlock tables");
    $Conf->update_rev_tokens_setting(false);
    redirectSelf();
    loadRows();
}


function handle_set_round() {
    global $Conf, $Me, $prow, $rrows;

    // check permissions
    if (!@$_REQUEST["r"] || !($rr = rrow_by_reviewid($_REQUEST["r"])))
        $Conf->ajaxExit(array("ok" => false, "error" => "No such review."));
    if (!$Me->can_administer($prow))
        $Conf->ajaxExit(array("ok" => false, "error" => "Permission denied."));
    $rname = trim((string) $_POST["round"]);
    $round = $Conf->sanitize_round_name($rname);
    if ($round === false)
        $Conf->ajaxExit(array("ok" => false, "error" => Conf::round_name_error($rname)));

    // assign round
    $rnum = $Conf->round_number($rname, true);
    Dbl::qe("update PaperReview set reviewRound=$rnum where reviewId=$rr->reviewId");
    $Conf->ajaxExit(array("ok" => true));
}

if (isset($_GET["setround"]) && check_post())
    handle_set_round();


// change PC assignments
function pcAssignments() {
    global $Conf, $Me, $prow;
    $pcm = pcMembers();

    $rname = (string) $Conf->sanitize_round_name(@$_REQUEST["rev_roundtag"]);
    $round_number = null;

    $where = array("(ContactInfo.roles&" . Contact::ROLE_PC . ")!=0");
    if (@$_REQUEST["reviewer"] && isset($pcm[$_REQUEST["reviewer"]]))
        $where[] = "ContactInfo.contactId='" . $_REQUEST["reviewer"] . "'";

    Dbl::qe_raw("lock tables PaperReview write, PaperReviewRefused write, PaperConflict write, ContactInfo read, ActionLog write, Settings write");

    // don't record separate PC conflicts on author conflicts
    $result = Dbl::qe_raw("select ContactInfo.contactId,
        PaperConflict.conflictType, reviewType, reviewModified, reviewId
        from ContactInfo
        left join PaperConflict on (PaperConflict.contactId=ContactInfo.contactId and PaperConflict.paperId=$prow->paperId)
        left join PaperReview on (PaperReview.contactId=ContactInfo.contactId and PaperReview.paperId=$prow->paperId)
        where " . join(" and ", $where));
    while (($row = edb_orow($result))) {
        $pctype = defval($_REQUEST, "pcs$row->contactId", 0);
        if ($row->conflictType >= CONFLICT_AUTHOR)
            continue;

        // manage conflicts
        if ($row->conflictType && $pctype >= 0)
            Dbl::qe_raw("delete from PaperConflict where paperId=$prow->paperId and contactId=$row->contactId");
        else if (!$row->conflictType && $pctype < 0)
            Dbl::qe_raw("insert into PaperConflict (paperId, contactId, conflictType) values ($prow->paperId, $row->contactId, " . CONFLICT_CHAIRMARK . ")");

        // manage assignments
        $pctype = max($pctype, 0);
        if ($pctype != $row->reviewType
            && ($pctype == 0 || $pctype == REVIEW_PRIMARY
                || $pctype == REVIEW_SECONDARY || $pctype == REVIEW_PC)
            && ($pctype == 0
                || $pcm[$row->contactId]->can_accept_review_assignment($prow))) {
            if ($pctype != 0 && $round_number === null)
                $round_number = $Conf->round_number($rname, true);
            $Me->assign_review($prow->paperId, $row->contactId, $pctype,
                               array("round_number" => $round_number));
        }
    }
}

if (isset($_REQUEST["update"]) && $Me->allow_administer($prow) && check_post()) {
    pcAssignments();
    Dbl::qe_raw("unlock tables");
    $Conf->update_rev_tokens_setting(false);
    if ($OK)
        $Conf->confirmMsg("Assignments saved.");
    if (defval($_REQUEST, "ajax"))
        $Conf->ajaxExit(array("ok" => $OK));
    else {
        redirectSelf();
        // NB normally redirectSelf() does not return
        loadRows();
    }
} else if (isset($_REQUEST["update"]) && defval($_REQUEST, "ajax")) {
    Conf::msg_error("Only administrators can assign papers.");
    $Conf->ajaxExit(array("ok" => 0));
}


// add review requests
function requestReviewChecks($themHtml, $reqId) {
    global $Me, $prow;

    // check for outstanding review request
    $result = Dbl::qe_raw("select reviewId, firstName, lastName, email, password from PaperReview join ContactInfo on (ContactInfo.contactId=PaperReview.requestedBy) where paperId=$prow->paperId and PaperReview.contactId=$reqId");
    if (!$result)
        return false;
    else if (($row = edb_orow($result)))
        return Conf::msg_error(Text::user_html($row) . " has already requested a review from $themHtml.");

    // check for outstanding refusal to review
    $result = Dbl::qe_raw("select paperId, '<conflict>' from PaperConflict where paperId=$prow->paperId and contactId=$reqId union select paperId, reason from PaperReviewRefused where paperId=$prow->paperId and contactId=$reqId");
    if (edb_nrows($result) > 0) {
        $row = edb_row($result);
        if ($row[1] === "<conflict>")
            return Conf::msg_error("$themHtml has a conflict registered with paper #$prow->paperId and cannot be asked to review it.");
        else if ($Me->override_deadlines($prow)) {
            Conf::msg_info("Overriding previous refusal to review paper #$prow->paperId." . ($row[1] ? "  (Their reason was “" . htmlspecialchars($row[1]) . "”.)" : ""));
            Dbl::qe_raw("delete from PaperReviewRefused where paperId=$prow->paperId and contactId=$reqId");
        } else
            return Conf::msg_error("$themHtml refused a previous request to review paper #$prow->paperId." . ($row[1] ? " (Their reason was “" . htmlspecialchars($row[1]) . "”.)" : "") . ($Me->allow_administer($prow) ? " As an administrator, you can override this refusal with the “Override...” checkbox." : ""));
    }

    return true;
}

function requestReview($email) {
    global $Conf, $Me, $Error, $prow;

    $Them = Contact::create(array("name" => @$_REQUEST["name"], "email" => $email));
    if (!$Them) {
        if (trim($email) === "" || !validate_email($email)) {
            Conf::msg_error("“" . htmlspecialchars(trim($email)) . "” is not a valid email address.");
            $Error["email"] = true;
        } else
            Conf::msg_error("Error while finding account for “" . htmlspecialchars(trim($email)) . ".”");
        return false;
    }

    $reason = trim(defval($_REQUEST, "reason", ""));

    $round = $Conf->current_round();
    if (isset($_REQUEST["round"]) && $_REQUEST["round"] != ""
        && ($rname = $Conf->sanitize_round_name($_REQUEST["round"])) !== false)
        $round = $Conf->round_number($rname, false);

    // look up the requester
    $Requester = $Me;
    if ($Conf->setting("extrev_chairreq")) {
        $result = Dbl::qe("select firstName, lastName, u.email, u.contactId from ReviewRequest rr join ContactInfo u on (u.contactId=rr.requestedBy) where paperId=$prow->paperId and rr.email=?", $Them->email);
        if ($result && ($recorded_requester = $result->fetch_object("Contact")))
            $Requester = $recorded_requester;
    }

    Dbl::qe_raw("lock tables PaperReview write, PaperReviewRefused write, ContactInfo read, PaperConflict read, ActionLog write");
    // NB caller unlocks tables on error

    // check for outstanding review request
    if (!($result = requestReviewChecks(Text::user_html($Them), $Them->contactId)))
        return $result;

    // at this point, we think we've succeeded.
    // store the review request
    $Me->assign_review($prow->paperId, $Them->contactId, REVIEW_EXTERNAL,
                       ["mark_notify" => true, "requester_contact" => $Requester,
                        "round_number" => $round]);

    Dbl::qx_raw("unlock tables");

    // delete proposed request, mark secondary as delegated
    Dbl::qe("delete from ReviewRequest where paperId=$prow->paperId and ReviewRequest.email=?", $Them->email);
    Dbl::qe_raw("update PaperReview set reviewNeedsSubmit=-1 where paperId=$prow->paperId and reviewType=" . REVIEW_SECONDARY . " and contactId=$Requester->contactId and reviewSubmitted is null and reviewNeedsSubmit=1");

    // send confirmation email
    HotCRPMailer::send_to($Them, "@requestreview", $prow,
                          array("requester_contact" => $Requester,
                                "other_contact" => $Requester, // backwards compat
                                "reason" => $reason));

    $Conf->confirmMsg("Created a request to review paper #$prow->paperId.");
    return true;
}

function delegate_review_round() {
    // Use the delegator's review round
    global $Conf, $Me, $Now, $prow, $rrows;
    $round = null;
    foreach ($rrows as $rrow)
        if ($rrow->contactId == $Me->contactId
            && $rrow->reviewType == REVIEW_SECONDARY)
            $round = (int) $rrow->reviewRound;
    return $round;
}

function proposeReview($email, $round) {
    global $Conf, $Me, $Now, $prow, $rrows;

    $email = trim($email);
    $name = trim($_REQUEST["name"]);
    $reason = trim($_REQUEST["reason"]);
    $reqId = Contact::id_by_email($email);

    Dbl::qe_raw("lock tables PaperReview write, PaperReviewRefused write, ReviewRequest write, ContactInfo read, PaperConflict read");
    // NB caller unlocks tables on error

    if ($reqId > 0
        && !($result = requestReviewChecks(htmlspecialchars($email), $reqId)))
        return $result;

    // add review request
    $result = Dbl::qe("insert into ReviewRequest set paperId={$prow->paperId},
        name=?, email=?, requestedBy={$Me->contactId}, reason=?, reviewRound=?
        on duplicate key update paperId=paperId",
                      $name, $email, $reason, $round);

    // send confirmation email
    HotCRPMailer::send_manager("@proposereview", $prow,
                               array("permissionContact" => $Me,
                                     "cc" => Text::user_email_to($Me),
                                     "requester_contact" => $Me,
                                     "reviewer_contact" => (object) array("fullName" => $name, "email" => $email),
                                     "reason" => $reason));

    // confirmation message
    $Conf->confirmMsg("Proposed that " . htmlspecialchars("$name <$email>") . " review paper #$prow->paperId.  The chair must approve this proposal for it to take effect.");
    Dbl::qx_raw("unlock tables");
    $Me->log_activity("Logged proposal for $email to review", $prow);
    return true;
}

function unassignedAnonymousContact() {
    global $rrows;
    $n = "";
    while (1) {
        $name = "anonymous$n";
        $good = true;
        foreach ($rrows as $rr)
            if ($rr->email === $name) {
                $good = false;
                break;
            }
        if ($good)
            return $name;
        $n = ($n === "" ? 2 : $n + 1);
    }
}

function createAnonymousReview() {
    global $Conf, $Me, $Now, $prow, $rrows;

    Dbl::qe_raw("lock tables PaperReview write, PaperReviewRefused write, ContactInfo write, PaperConflict read, ActionLog write");

    // find an unassigned anonymous review contact
    $contactemail = unassignedAnonymousContact();
    $result = Dbl::qe_raw("select contactId from ContactInfo where email='" . sqlq($contactemail) . "'");
    if (edb_nrows($result) == 1) {
        $row = edb_row($result);
        $reqId = $row[0];
    } else {
        $result = Dbl::qe("insert into ContactInfo set firstName='Jane Q.', lastName='Public', unaccentedName='Jane Q. Public', email=?, affiliation='Unaffiliated', password='', disabled=1, creationTime=$Now", $contactemail);
        if (!$result)
            return $result;
        $reqId = $result->insert_id;
    }

    // store the review request
    $reviewId = $Me->assign_review($prow->paperId, $reqId, REVIEW_EXTERNAL,
                                   array("mark_notify" => true, "token" => true));
    if ($reviewId) {
        $result = Dbl::ql("select reviewToken from PaperReview where reviewId=$reviewId");
        $row = edb_row($result);
        $Conf->confirmMsg("Created a new anonymous review for paper #$prow->paperId. The review token is " . encode_token((int) $row[0]) . ".");
    }

    Dbl::qx_raw("unlock tables");
    $Conf->update_rev_tokens_setting(true);
    return true;
}

if (isset($_REQUEST["add"]) && check_post()) {
    if (($whyNot = $Me->perm_request_review($prow, true)))
        Conf::msg_error(whyNotText($whyNot, "request reviews for"));
    else if (!isset($_REQUEST["email"]) || !isset($_REQUEST["name"]))
        Conf::msg_error("An email address is required to request a review.");
    else if (trim($_REQUEST["email"]) === "" && trim($_REQUEST["name"]) === ""
             && $Me->allow_administer($prow)) {
        if (!createAnonymousReview())
            Dbl::qx_raw("unlock tables");
        unset($_REQUEST["reason"]);
        loadRows();
    } else if (trim($_REQUEST["email"]) === "")
        Conf::msg_error("An email address is required to request a review.");
    else {
        if (setting("extrev_chairreq") && !$Me->allow_administer($prow))
            $ok = proposeReview($_REQUEST["email"], delegate_review_round());
        else
            $ok = requestReview($_REQUEST["email"]);
        if ($ok) {
            unset($_REQUEST["email"]);
            unset($_REQUEST["name"]);
            unset($_REQUEST["round"]);
            unset($_REQUEST["reason"]);
            redirectSelf();
        } else
            Dbl::qx_raw("unlock tables");
        loadRows();
    }
}


// deny review request
if (isset($_REQUEST["deny"]) && $Me->allow_administer($prow) && check_post()
    && ($email = trim(defval($_REQUEST, "email", "")))) {
    Dbl::qe_raw("lock tables ReviewRequest write, ContactInfo read, PaperConflict read, PaperReview read, PaperReviewRefused write");
    // Need to be careful and not expose inappropriate information:
    // this email comes from the chair, who can see all, but goes to a PC
    // member, who can see less.
    $result = Dbl::qe_raw("select requestedBy from ReviewRequest where paperId=$prow->paperId and email='" . sqlq($email) . "'");
    if (($row = edb_row($result))) {
        $Requester = Contact::find_by_id($row[0]);
        Dbl::qe_raw("delete from ReviewRequest where paperId=$prow->paperId and email='" . sqlq($email) . "'");
        if (($reqId = Contact::id_by_email($email)) > 0)
            Dbl::qe_raw("insert into PaperReviewRefused (paperId, contactId, requestedBy, reason) values ($prow->paperId, $reqId, $Requester->contactId, 'request denied by chair')");

        // send anticonfirmation email
        HotCRPMailer::send_to($Requester, "@denyreviewrequest", $prow,
                              array("reviewer_contact" => (object) array("fullName" => trim(defval($_REQUEST, "name", "")), "email" => $email)));

        $Conf->confirmMsg("Proposed reviewer denied.");
    } else
        Conf::msg_error("No one has proposed that " . htmlspecialchars($email) . " review this paper.");
    Dbl::qx_raw("unlock tables");
    unset($_REQUEST['email']);
    unset($_REQUEST['name']);
}


// add primary or secondary reviewer
if (isset($_REQUEST["addpc"]) && $Me->allow_administer($prow) && check_post()) {
    if (($pcid = cvtint(@$_REQUEST["pcid"])) <= 0)
        Conf::msg_error("Enter a PC member.");
    else if (($pctype = cvtint(@$_REQUEST["pctype"])) == REVIEW_PRIMARY
             || $pctype == REVIEW_SECONDARY || $pctype == REVIEW_PC) {
        $Me->assign_review($prow->paperId, $pcid, $pctype);
        $Conf->update_rev_tokens_setting(false);
    }
    loadRows();
}


// paper table
$paperTable = new PaperTable($prow, "assign");
$paperTable->initialize(false, false);

confHeader();


// begin form and table
$loginUrl = hoturl_post("assign", "p=$prow->paperId");

$paperTable->paptabBegin();


// reviewer information
$proposals = null;
if (setting("extrev_chairreq")) {
    if ($Me->allow_administer($prow))
        $q = "";
    else
        $q = " and requestedBy=$Me->contactId";
    $result = Dbl::qe_raw("select name, ReviewRequest.email, firstName as reqFirstName, lastName as reqLastName, ContactInfo.email as reqEmail, requestedBy, reason, reviewRound from ReviewRequest join ContactInfo on (ContactInfo.contactId=ReviewRequest.requestedBy) where ReviewRequest.paperId=$prow->paperId" . $q);
    $proposals = edb_orows($result);
}
$t = reviewTable($prow, $rrows, null, null, "assign", $proposals);
$t .= reviewLinks($prow, $rrows, null, null, "assign", $allreviewslink);
if ($t !== "")
    echo '<hr class="papcard_sep" />', $t;


// PC assignments
if ($Me->can_administer($prow)) {
    $result = Dbl::qe_raw("select ContactInfo.contactId,
        PaperConflict.conflictType,
        PaperReview.reviewType,
        coalesce(preference, 0) as reviewerPreference,
        expertise as reviewerExpertise,
        coalesce(allReviews,'') as allReviews,
        coalesce(PaperTopics.topicInterestScore,0) as topicInterestScore,
        coalesce(PRR.paperId,0) as refused
        from ContactInfo
        left join PaperConflict on (PaperConflict.contactId=ContactInfo.contactId and PaperConflict.paperId=$prow->paperId)
        left join PaperReview on (PaperReview.contactId=ContactInfo.contactId and PaperReview.paperId=$prow->paperId)
        left join PaperReviewPreference on (PaperReviewPreference.contactId=ContactInfo.contactId and PaperReviewPreference.paperId=$prow->paperId)
        left join (select PaperReview.contactId, group_concat(reviewType separator '') as allReviews from PaperReview join Paper on (Paper.paperId=PaperReview.paperId and timeWithdrawn<=0) group by PaperReview.contactId) as AllReviews on (AllReviews.contactId=ContactInfo.contactId)
        left join (select contactId, sum(" . $Conf->query_topic_interest_score() . ") as topicInterestScore from PaperTopic join TopicInterest using (topicId) where paperId=$prow->paperId group by contactId) as PaperTopics on (PaperTopics.contactId=ContactInfo.contactId)
        left join PaperReviewRefused PRR on (PRR.paperId=$prow->paperId and PRR.contactId=ContactInfo.contactId)
        where (ContactInfo.roles&" . Contact::ROLE_PC . ")!=0
        group by ContactInfo.contactId");
    $pcx = array();
    while (($row = edb_orow($result)))
        $pcx[$row->contactId] = $row;

    // PC conflicts row
    echo '<hr class="papcard_sep" />',
        Ht::form($loginUrl, array("id" => "ass")), '<div class="aahc">',
        "<h3 style=\"margin-top:0\">PC review assignments</h3>",
        '<p>';

    $rev_roundtag = (string) $Conf->sanitize_round_name(@$_REQUEST["rev_roundtag"]);
    $rev_rounds = $Conf->round_selector_options();
    $x = array();
    if (count($rev_rounds) > 1)
        $x[] = 'Review round:&nbsp; '
            . Ht::select("rev_roundtag", $rev_rounds, $rev_roundtag ? : "unnamed");
    else if (!@$rev_rounds["unnamed"])
        $x[] = 'Review round: ' . $rev_roundtag
            . Ht::hidden("rev_roundtag", $rev_roundtag);
    if ($Conf->has_topics())
        $x[] = "Review preferences display as “P#”, topic scores as “T#”.";
    else
        $x[] = "Review preferences display as “P#”.";
    echo join(' <span class="barsep">·</span> ', $x), '</p>';

    echo '<div id="assignmentselector" style="display:none">',
        Ht::select("pcs\$", array(0 => "None", REVIEW_PRIMARY => "Primary",
                                  REVIEW_SECONDARY => "Secondary",
                                  REVIEW_PC => "Optional", -1 => "Conflict"),
                   "@", array("id" => "pcs\$_selector", "size" => 5, "onchange" => "assigntable.sel(this,\$)", "onclick" => "assigntable.sel(null,\$)", "onblur" => "assigntable.sel(0,\$)")),
        '</div>';

    echo '<div class="pc_ctable">';
    $tagger = new Tagger($Me);
    foreach (pcMembers() as $pc) {
        $p = $pcx[$pc->contactId];
        if (!$pc->can_accept_review_assignment_ignore_conflict($prow))
            continue;

        // first, name and assignment
        $color = $tagger->viewable_color_classes($pc->all_contact_tags());
        echo '<div class="ctelt"><div class="pc_ctelt' . ($color ? " $color" : "") . '">';
        if ($p->conflictType >= CONFLICT_AUTHOR) {
            echo '<div class="pctbass">', review_type_icon(-2),
                Ht::img("_.gif", ">", array("class" => "next", "style" => "visibility:hidden")), '&nbsp;</div>',
                '<div id="ass' . $p->contactId . '" class="pctbname pctbname-2 taghl nw">',
                $Me->name_html_for($pc), '</div>';
        } else {
            if ($p->conflictType > 0)
                $revtype = -1;
            else if ($p->reviewType)
                $revtype = $p->reviewType;
            else
                $revtype = ($p->refused ? -3 : 0);
            $title = ($p->refused ? "Review previously declined" : "Assignment");
            // NB manualassign.php also uses the "pcs$contactId" convention
            echo '<div class="pctbass">'
                . '<div id="foldass' . $p->contactId . '" class="foldc" style="position:relative">'
                . '<a id="folderass' . $p->contactId . '" href="#" onclick="return assigntable.open(' . $p->contactId . ')">'
                . review_type_icon($revtype, false, $title)
                . Ht::img("_.gif", ">", array("class" => "next")) . '</a>'
                . '</a>&nbsp;'
                . Ht::hidden("pcs$p->contactId", $p->conflictType == 0 ? $p->reviewType : -1, array("id" => "pcs$p->contactId"))
                . '</div></div>';

            echo '<div id="ass' . $p->contactId . '" class="pctbname pctbname' . $revtype . '">'
                . '<span class="taghl nw">' . $Me->name_html_for($pc) . '</span>';
            if ($p->conflictType == 0
                && ($p->reviewerPreference || $p->reviewerExpertise
                    || $p->topicInterestScore))
                echo unparse_preference_span($p);
            echo '</div>';
        }

        // then, number of reviews
        echo '<div class="pctbnrev">';
        $numReviews = strlen($p->allReviews);
        $numPrimary = preg_match_all("|" . REVIEW_PRIMARY . "|", $p->allReviews, $matches);
        if (!$numReviews)
            echo "0 reviews";
        else {
            echo "<a class='q' href=\""
                . hoturl("search", "q=re:" . urlencode($pc->email)) . "\">"
                . plural($numReviews, "review") . "</a>";
            if ($numPrimary && $numPrimary < $numReviews)
                echo "&nbsp; (<a class='q' href=\""
                    . hoturl("search", "q=pri:" . urlencode($pc->email))
                    . "\">$numPrimary primary</a>)";
        }
        echo "</div><hr class=\"c\" /></div></div>\n";
    }
    echo "</div>\n",
        "<div class='aa' style='margin-bottom:0'>",
        Ht::submit("update", "Save assignments", array("class" => "bb")),
        " &nbsp;", Ht::submit("cancel", "Cancel"),
        " <span id='assresult' style='padding-left:1em'></span></div>\n\n",
        '</div></form>';
}


echo "</div></div>\n";

// add external reviewers
echo Ht::form($loginUrl), '<div class="aahc revcard"><div class="revcard_head">',
    "<h3>Request an external review</h3>\n",
    "<div class='hint'>External reviewers get access to their assigned papers, including ";
if (setting("extrev_view") >= 2)
    echo "the other reviewers' identities and ";
echo "any eventual decision.  Before requesting an external review,
 you should generally check personally whether they are interested.";
if ($Me->allow_administer($prow))
    echo "\nTo create a review with no associated reviewer, leave Name and Email blank.";
echo '</div></div><div class="revcard_body">';
echo "<div class='f-i'><div class='f-ix'>
  <div class='f-c'>Name</div>
  <div class='f-e'><input type='text' name='name' value=\"", htmlspecialchars(defval($_REQUEST, "name", "")), "\" size='32' tabindex='1' /></div>
</div><div class='f-ix'>
  <div class='f-c", (isset($Error["email"]) ? " error" : ""), "'>Email</div>
  <div class='f-e'><input type='text' name='email' value=\"", htmlspecialchars(defval($_REQUEST, "email", "")), "\" size='28' tabindex='1' /></div>
</div><hr class=\"c\" /></div>\n\n";

// reason area
$null_mailer = new HotCRPMailer;
$reqbody = $null_mailer->expand_template("requestreview", false);
if (strpos($reqbody["body"], "%REASON%") !== false) {
    echo "<div class='f-i'>
  <div class='f-c'>Note to reviewer <span class='f-cx'>(optional)</span></div>
  <div class='f-e'>",
        Ht::textarea("reason", @$_REQUEST["reason"],
                array("class" => "papertext", "rows" => 2, "cols" => 60, "tabindex" => 1, "spellcheck" => "true")),
        "</div><hr class=\"c\" /></div>\n\n";
}

echo "<div class='f-i'>\n",
    Ht::submit("add", "Request review", array("tabindex" => 2)),
    "</div>\n\n";


if ($Me->can_administer($prow))
    echo "<div class='f-i'>\n  ", Ht::checkbox("override"), "&nbsp;", Ht::label("Override deadlines and any previous refusal"), "\n</div>\n";

echo "</div></div></form>\n";

$Conf->footer();
