<?php
// assign.php -- HotCRP per-paper assignment/conflict management page
// HotCRP is Copyright (c) 2006-2017 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

require_once("src/initweb.php");
require_once("src/papertable.php");
require_once("src/reviewtable.php");
if (!$Me->email)
    $Me->escape();
$Me->set_overrides($Me->overrides() | Contact::OVERRIDE_CONFLICT);
$Error = array();
// ensure site contact exists before locking tables
$Conf->site_contact();


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
        errorMsgExit(whyNotText($whyNot, "view", true));
    if (($whyNot = $Me->perm_request_review($prow, false))) {
        $wnt = whyNotText($whyNot, "request reviews for");
        error_go(hoturl("paper", ["p" => $prow->paperId]), $wnt);
    }
    $rrows = $prow->reviews_by_id();
}

function rrow_by_reviewid($rid) {
    global $rrows;
    foreach ($rrows as $rr)
        if ($rr->reviewId == $rid)
            return $rr;
    return null;
}


loadRows();



if (isset($_REQUEST["post"]) && $_REQUEST["post"] && !count($_POST)
    && !isset($_REQUEST["retract"]) && !isset($_REQUEST["add"])
    && !isset($_REQUEST["deny"]))
    $Conf->post_missing_msg();



// retract review request
function retractRequest($email, $prow, $confirm = true) {
    global $Conf, $Me;

    $Conf->qe("lock tables PaperReview write, ReviewRequest write, ContactInfo read, PaperConflict read");
    $email = trim($email);
    // NB caller unlocks tables

    // check for outstanding review
    $contact_fields = "firstName, lastName, ContactInfo.email, password, roles, preferredEmail, disabled";
    $result = $Conf->qe("select reviewId, reviewType, reviewModified, reviewSubmitted, reviewToken, requestedBy, $contact_fields
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
    if ($row) {
        $Conf->qe("delete from PaperReview where paperId=? and reviewId=?", $prow->paperId, $row->reviewId);
        $Me->update_review_delegation($prow->paperId, $row->requestedBy, -1);
    }
    if ($row2)
        $Conf->qe("delete from ReviewRequest where paperId=? and email=?", $prow->paperId, $email);

    if (get($row, "reviewToken", 0) != 0)
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
    $Conf->qe("unlock tables");
    if ($Conf->setting("rev_tokens") === -1)
        $Conf->update_rev_tokens_setting(0);
    redirectSelf();
    loadRows();
}


// change PC assignments
function pcAssignments() {
    global $Conf, $Me, $prow;

    $reviewer = req("reviewer");
    if (($rname = $Conf->sanitize_round_name(req("rev_round"))) === "")
        $rname = "unnamed";
    $round = CsvGenerator::quote(":" . (string) $rname);

    $t = ["paper,action,email,round\n"];
    foreach ($Conf->pc_members() as $cid => $p)
        if ((!$reviewer || strcasecmp($p->email, $reviewer) == 0
             || (string) $p->contactId === $reviewer)
            && isset($_REQUEST["pcs$cid"])
            && ($rtype = cvtint($_REQUEST["pcs$cid"], null)) !== null) {
            $user = CsvGenerator::quote($p->email);
            if ($rtype >= 0)
                $t[] = "{$prow->paperId},clearconflict,$user\n";
            if ($rtype <= 0)
                $t[] = "{$prow->paperId},clearreview,$user\n";
            if ($rtype == REVIEW_META)
                $t[] = "{$prow->paperId},metareview,$user,$round\n";
            else if ($rtype == REVIEW_PRIMARY)
                $t[] = "{$prow->paperId},primary,$user,$round\n";
            else if ($rtype == REVIEW_SECONDARY)
                $t[] = "{$prow->paperId},secondary,$user,$round\n";
            else if ($rtype == REVIEW_PC || $rtype == REVIEW_EXTERNAL)
                $t[] = "{$prow->paperId},pcreview,$user,$round\n";
            else if ($rtype < 0)
                $t[] = "{$prow->paperId},conflict,$user\n";
        }

    $aset = new AssignmentSet($Me, true);
    $aset->enable_papers($prow);
    $aset->parse(join("", $t));
    if ($aset->execute()) {
        if (req("ajax"))
            json_exit(["ok" => true]);
        else {
            $Conf->confirmMsg("Assignments saved.");
            redirectSelf();
            // NB normally redirectSelf() does not return
            loadRows();
        }
    } else {
        if (req("ajax"))
            json_exit(["ok" => false, "error" => join("<br />", $aset->errors_html())]);
        else
            $Conf->errorMsg(join("<br />", $aset->errors_html()));
    }
}

if (isset($_REQUEST["update"]) && $Me->allow_administer($prow) && check_post())
    pcAssignments();
else if (isset($_REQUEST["update"]) && defval($_REQUEST, "ajax"))
    json_exit(["ok" => false, "error" => "Only administrators can assign papers."]);


// add review requests
function requestReviewChecks($themHtml, $reqId) {
    global $Conf, $Me, $prow;

    // check for outstanding review request
    $result = $Conf->qe("select reviewId, firstName, lastName, email, password from PaperReview join ContactInfo on (ContactInfo.contactId=PaperReview.requestedBy) where paperId=? and PaperReview.contactId=?", $prow->paperId, $reqId);
    if (!$result)
        return false;
    else if (($row = edb_orow($result)))
        return Conf::msg_error(Text::user_html($row) . " has already requested a review from $themHtml.");

    // check for outstanding refusal to review
    $result = $Conf->qe("select paperId, '<conflict>' from PaperConflict where paperId=? and contactId=? union select paperId, reason from PaperReviewRefused where paperId=? and contactId=?", $prow->paperId, $reqId, $prow->paperId, $reqId);
    if (edb_nrows($result) > 0) {
        $row = edb_row($result);
        if ($row[1] === "<conflict>")
            return Conf::msg_error("$themHtml has a conflict registered with paper #$prow->paperId and cannot be asked to review it.");
        else if ($Me->override_deadlines($prow)) {
            Conf::msg_info("Overriding previous refusal to review paper #$prow->paperId." . ($row[1] ? "  (Their reason was “" . htmlspecialchars($row[1]) . "”.)" : ""));
            $Conf->qe("delete from PaperReviewRefused where paperId=? and contactId=?", $prow->paperId, $reqId);
        } else
            return Conf::msg_error("$themHtml refused a previous request to review paper #$prow->paperId." . ($row[1] ? " (Their reason was “" . htmlspecialchars($row[1]) . "”.)" : "") . ($Me->allow_administer($prow) ? " As an administrator, you can override this refusal with the “Override...” checkbox." : ""));
    }

    return true;
}

function requestReview($email) {
    global $Conf, $Me, $Error, $prow;

    $Them = Contact::create($Conf, ["name" => req("name"), "email" => $email]);
    if (!$Them) {
        if (trim($email) === "" || !validate_email($email)) {
            Conf::msg_error("“" . htmlspecialchars(trim($email)) . "” is not a valid email address.");
            $Error["email"] = true;
        } else
            Conf::msg_error("Error while finding account for “" . htmlspecialchars(trim($email)) . ".”");
        return false;
    }

    $reason = trim(defval($_REQUEST, "reason", ""));

    $round = null;
    if (isset($_REQUEST["round"]) && $_REQUEST["round"] != ""
        && ($rname = $Conf->sanitize_round_name($_REQUEST["round"])) !== false)
        $round = (int) $Conf->round_number($rname, false);

    // look up the requester
    $Requester = $Me;
    if ($Conf->setting("extrev_chairreq")) {
        $result = Dbl::qe("select firstName, lastName, u.email, u.contactId from ReviewRequest rr join ContactInfo u on (u.contactId=rr.requestedBy) where paperId=$prow->paperId and rr.email=?", $Them->email);
        if ($result && ($recorded_requester = Contact::fetch($result)))
            $Requester = $recorded_requester;
    }

    $Conf->qe("lock tables PaperReview write, PaperReviewRefused write, ReviewRequest write, ContactInfo read, PaperConflict read, ActionLog write, Settings write");
    // NB caller unlocks tables on error

    // check for outstanding review request
    if (!($result = requestReviewChecks(Text::user_html($Them), $Them->contactId)))
        return $result;

    // at this point, we think we've succeeded.
    // store the review request
    $Me->assign_review($prow->paperId, $Them->contactId, REVIEW_EXTERNAL,
                       ["mark_notify" => true, "requester_contact" => $Requester,
                        "requested_email" => $Them->email, "round_number" => $round]);

    Dbl::qx_raw("unlock tables");

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
    $reqId = $Conf->user_id_by_email($email);

    $Conf->qe("lock tables PaperReview write, PaperReviewRefused write, ReviewRequest write, ContactInfo read, PaperConflict read");
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

function createAnonymousReview() {
    global $Conf, $Me, $prow;
    $aset = new AssignmentSet($Me, true);
    $aset->enable_papers($prow);
    $aset->parse("paper,action,user\n{$prow->paperId},review,newanonymous\n");
    if ($aset->execute()) {
        $aset_csv = $aset->unparse_csv();
        assert(count($aset_csv->data) === 1);
        $Conf->confirmMsg("Created a new anonymous review for paper #$prow->paperId. The review token is " . $aset_csv->data[0]["review_token"] . ".");
    } else
        $Conf->errorMsg(join("<br />", $aset->errors_html()));
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
        unset($_REQUEST["reason"], $_GET["reason"], $_POST["reason"]);
        loadRows();
    } else if (trim($_REQUEST["email"]) === "")
        Conf::msg_error("An email address is required to request a review.");
    else {
        if ($Conf->setting("extrev_chairreq") && !$Me->allow_administer($prow))
            $ok = proposeReview($_REQUEST["email"], delegate_review_round());
        else
            $ok = requestReview($_REQUEST["email"]);
        if ($ok) {
            foreach (["email", "name", "round", "reason"] as $k)
                unset($_REQUEST[$k], $_GET[$k], $_POST[$k]);
            redirectSelf();
        } else
            Dbl::qx_raw("unlock tables");
        loadRows();
    }
}


// deny review request
if (isset($_REQUEST["deny"]) && $Me->allow_administer($prow) && check_post()
    && ($email = trim(defval($_REQUEST, "email", "")))) {
    $Conf->qe("lock tables ReviewRequest write, ContactInfo read, PaperConflict read, PaperReview read, PaperReviewRefused write");
    // Need to be careful and not expose inappropriate information:
    // this email comes from the chair, who can see all, but goes to a PC
    // member, who can see less.
    $result = $Conf->qe("select requestedBy from ReviewRequest where paperId=$prow->paperId and email=?", $email);
    if (($row = edb_row($result))) {
        $Requester = $Conf->user_by_id($row[0]);
        $Conf->qe("delete from ReviewRequest where paperId=$prow->paperId and email=?", $email);
        if (($reqId = $Conf->user_id_by_email($email)) > 0)
            $Conf->qe("insert into PaperReviewRefused (paperId, contactId, requestedBy, reason) values ($prow->paperId, $reqId, $Requester->contactId, 'request denied by chair')");

        // send anticonfirmation email
        HotCRPMailer::send_to($Requester, "@denyreviewrequest", $prow,
                              array("reviewer_contact" => (object) array("fullName" => trim(defval($_REQUEST, "name", "")), "email" => $email)));

        $Conf->confirmMsg("Proposed reviewer denied.");
    } else
        Conf::msg_error("No one has proposed that " . htmlspecialchars($email) . " review this paper.");
    Dbl::qx_raw("unlock tables");
    unset($_REQUEST["email"], $_GET["email"], $_POST["email"]);
    unset($_REQUEST["name"], $_GET["name"], $_POST["name"]);
}


// paper table
$paperTable = new PaperTable($prow, make_qreq(), "assign");
$paperTable->initialize(false, false);
$paperTable->resolveReview(false);

confHeader();


// begin form and table
$loginUrl = hoturl_post("assign", "p=$prow->paperId");

$paperTable->paptabBegin();


// reviewer information
$proposals = null;
if ($Conf->setting("extrev_chairreq")) {
    $qv = [$prow->paperId];
    $q = "";
    if (!$Me->allow_administer($prow)) {
        $q = " and requestedBy=?";
        $qv[] = $Me->contactId;
    }
    $result = $Conf->qe_apply("select name, email, requestedBy, reason, reviewRound from ReviewRequest where ReviewRequest.paperId=?$q", $qv);
    $proposals = edb_orows($result);
}
$t = reviewTable($prow, $paperTable->all_reviews(), null, null, "assign", $proposals);
$t .= reviewLinks($prow, $paperTable->all_reviews(), null, null, "assign", $allreviewslink);
if ($t !== "")
    echo '<hr class="papcard_sep" />', $t;


// PC assignments
if ($Me->can_administer($prow)) {
    $result = $Conf->qe("select ContactInfo.contactId, allReviews,
        exists(select paperId from PaperReviewRefused where paperId=? and contactId=ContactInfo.contactId) refused
        from ContactInfo
        left join (select contactId, group_concat(reviewType separator '') allReviews
            from PaperReview join Paper using (paperId)
            where reviewType>=" . REVIEW_PC . " and timeSubmitted>=0
            group by contactId) A using (contactId)
        where ContactInfo.roles!=0 and (ContactInfo.roles&" . Contact::ROLE_PC . ")!=0",
        $prow->paperId);
    $pcx = array();
    while (($row = edb_orow($result)))
        $pcx[$row->contactId] = $row;

    // PC conflicts row
    echo '<hr class="papcard_sep" />',
        "<h3 style=\"margin-top:0\">PC review assignments</h3>",
        Ht::form_div($loginUrl, array("id" => "ass")),
        '<p>';
    Ht::stash_script('assigntable("#ass")');

    $rev_round = (string) $Conf->sanitize_round_name(req("rev_round"));
    $rev_rounds = $Conf->round_selector_options();
    $x = array();
    if (count($rev_rounds) > 1)
        $x[] = 'Review round:&nbsp; '
            . Ht::select("rev_round", $rev_rounds, $rev_round ? : "unnamed");
    else if (!get($rev_rounds, "unnamed"))
        $x[] = 'Review round: ' . $rev_round
            . Ht::hidden("rev_round", $rev_round);
    if ($Conf->has_topics())
        $x[] = "Review preferences display as “P#”, topic scores as “T#”.";
    else
        $x[] = "Review preferences display as “P#”.";
    echo join(' <span class="barsep">·</span> ', $x), '</p>';

    echo '<div class="pc_ctable">';
    $tagger = new Tagger($Me);
    $show_possible_conflicts = $Me->allow_view_authors($prow);
    foreach ($Conf->full_pc_members() as $pc) {
        $p = $pcx[$pc->contactId];
        if (!$pc->can_accept_review_assignment_ignore_conflict($prow))
            continue;

        // first, name and assignment
        $color = $pc->viewable_color_classes($Me);
        echo '<div class="ctelt"><div class="ctelti' . ($color ? " $color" : "") . '">';
        $conflict_type = $prow->conflict_type($pc);
        if ($conflict_type >= CONFLICT_AUTHOR) {
            echo '<div class="pctbass">', review_type_icon(-2),
                Ht::img("_.gif", ">", array("class" => "next", "style" => "visibility:hidden")), '&nbsp;</div>',
                '<div id="ass' . $pc->contactId . '" class="pctbname pctbname-2 taghl">',
                $Me->name_html_for($pc), '</div>';
        } else {
            if ($conflict_type > 0)
                $revtype = $value = -1;
            else {
                $revtype = $value = $prow->review_type($pc);
                if (!$revtype && $p->refused)
                    $revtype = -3;
            }
            $title = ($p->refused ? "Review previously declined" : "Assignment");
            // NB manualassign.php also uses the "pcs$contactId" convention
            echo '<div class="pctbass">'
                . '<div id="foldass' . $pc->contactId . '" class="foldc" style="position:relative">'
                . '<a id="folderass' . $pc->contactId . '" href="#">'
                . review_type_icon($revtype, false, $title)
                . Ht::img("_.gif", ">", array("class" => "next")) . '</a>&nbsp;'
                . Ht::hidden("pcs$pc->contactId", $value, ["id" => "pcs$pc->contactId", "data-default-value" => $value])
                . '</div></div>';

            echo '<div id="ass' . $pc->contactId . '" class="pctbname pctbname' . $revtype . '">'
                . '<span class="taghl">' . $Me->name_html_for($pc) . '</span>';
            if ($conflict_type == 0)
                echo unparse_preference_span($prow->reviewer_preference($pc, true));
            echo '</div>';
            if ($show_possible_conflicts
                && ($pcconfmatch = $prow->potential_conflict_html($pc, $conflict_type <= 0)))
                echo $pcconfmatch;
        }

        // then, number of reviews
        echo '<div class="pctbnrev">';
        $numReviews = strlen($p->allReviews);
        $numPrimary = substr_count($p->allReviews, REVIEW_PRIMARY);
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
        '<div class="aab aabr aabig">',
        '<div class="aabut">', Ht::submit("update", "Save assignments", ["class" => "btn btn-default"]), '</div>',
        '<div class="aabut">', Ht::submit("cancel", "Cancel"), '</div>',
        '<div id="assresult" class="aabut"></div>',
        '</div>',
        '</div></form>';
}


echo "</div></div>\n";

// add external reviewers
$req = "Request an external review";
if (!$Me->allow_administer($prow) && $Conf->setting("extrev_chairreq"))
    $req = "Propose an external review";
echo Ht::form($loginUrl), '<div class="aahc revcard"><div class="revcard_head">',
    "<h3>", $req, "</h3>\n",
    "<div class='hint'>External reviewers may view their assigned papers, including ";
if ($Conf->setting("extrev_view") >= 2)
    echo "the other reviewers’ identities and ";
echo "any eventual decision.  Before requesting an external review,
 you should generally check personally whether they are interested.";
if ($Me->allow_administer($prow))
    echo "\nTo create an anonymous review with a review token, leave Name and Email blank.";
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
        Ht::textarea("reason", req("reason"),
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
