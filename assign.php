<?php
// assign.php -- HotCRP per-paper assignment/conflict management page
// Copyright (c) 2006-2018 Eddie Kohler; see LICENSE.

require_once("src/initweb.php");
require_once("src/papertable.php");
require_once("src/reviewtable.php");
if (!$Me->email)
    $Me->escape();
$Me->add_overrides(Contact::OVERRIDE_CONFLICT);
// ensure site contact exists before locking tables
$Conf->site_contact();

// header
function confHeader() {
    global $paperTable, $Qreq;
    PaperTable::do_header($paperTable, "assign", "assign", $Qreq);
}

function errorMsgExit($msg) {
    confHeader();
    $msg && Conf::msg_error($msg);
    Conf::$g->footer();
    exit;
}


// grab paper row
function loadRows() {
    global $prow, $Conf, $Me, $Qreq;
    $Conf->paper = $prow = PaperTable::paperRow($Qreq, $whyNot);
    if (!$prow)
        errorMsgExit(whyNotText($whyNot + ["listViewable" => true]));
    if (($whyNot = $Me->perm_request_review($prow, false))) {
        $wnt = whyNotText($whyNot);
        error_go(hoturl("paper", ["p" => $prow->paperId]), $wnt);
    }
}


loadRows();



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
    $result2 = Dbl::qe("select * from ReviewRequest where paperId=? and email=?", $prow->paperId, $email);
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
        $Conf->confirmMsg("Retracted request that " . Text::user_html($row ? $row : $row2) . " review paper #$prow->paperId.");
}

if (isset($Qreq->retract) && $Qreq->post_ok()) {
    retractRequest($Qreq->retract, $prow);
    $Conf->qe("unlock tables");
    if ($Conf->setting("rev_tokens") === -1)
        $Conf->update_rev_tokens_setting(0);
    SelfHref::redirect($Qreq);
    loadRows();
}


// change PC assignments
function pcAssignments($qreq) {
    global $Conf, $Me, $prow;

    $reviewer = $qreq->reviewer;
    if (($rname = $Conf->sanitize_round_name($qreq->rev_round)) === "")
        $rname = "unnamed";
    $round = CsvGenerator::quote(":" . (string) $rname);

    $t = ["paper,action,email,round\n"];
    foreach ($Conf->pc_members() as $cid => $p) {
        if ($reviewer
            && strcasecmp($p->email, $reviewer) != 0
            && (string) $p->contactId !== $reviewer)
            continue;

        if (isset($qreq["assrev{$prow->paperId}u{$cid}"]))
            $revtype = $qreq["assrev{$prow->paperId}u{$cid}"];
        else if (isset($qreq["pcs{$cid}"]))
            $revtype = $qreq["pcs{$cid}"];
        else
            continue;
        $revtype = cvtint($revtype, null);
        if ($revtype === null)
            continue;

        $myround = $round;
        if (isset($qreq["rev_round{$prow->paperId}u{$cid}"])) {
            $x = $Conf->sanitize_round_name($qreq["rev_round{$prow->paperId}u{$cid}"]);
            if ($x !== false)
                $myround = $x === "" ? "unnamed" : CsvGenerator::quote($x);
        }

        $user = CsvGenerator::quote($p->email);
        if ($revtype >= 0)
            $t[] = "{$prow->paperId},clearconflict,$user\n";
        if ($revtype <= 0)
            $t[] = "{$prow->paperId},clearreview,$user\n";
        if ($revtype == REVIEW_META)
            $t[] = "{$prow->paperId},metareview,$user,$myround\n";
        else if ($revtype == REVIEW_PRIMARY)
            $t[] = "{$prow->paperId},primary,$user,$myround\n";
        else if ($revtype == REVIEW_SECONDARY)
            $t[] = "{$prow->paperId},secondary,$user,$myround\n";
        else if ($revtype == REVIEW_PC || $revtype == REVIEW_EXTERNAL)
            $t[] = "{$prow->paperId},pcreview,$user,$myround\n";
        else if ($revtype < 0)
            $t[] = "{$prow->paperId},conflict,$user\n";
    }

    $aset = new AssignmentSet($Me, true);
    $aset->enable_papers($prow);
    $aset->parse(join("", $t));
    if ($aset->execute()) {
        if ($qreq->ajax)
            json_exit(["ok" => true]);
        else {
            $Conf->confirmMsg("Assignments saved." . $aset->errors_div_html());
            SelfHref::redirect($qreq);
            // NB normally SelfHref::redirect() does not return
            loadRows();
        }
    } else {
        if ($qreq->ajax)
            json_exit(["ok" => false, "error" => join("<br />", $aset->errors_html())]);
        else
            $Conf->errorMsg(join("<br />", $aset->errors_html()));
    }
}

if (isset($Qreq->update) && $Me->allow_administer($prow) && $Qreq->post_ok())
    pcAssignments($Qreq);
else if (isset($Qreq->update) && $Qreq->ajax)
    json_exit(["ok" => false, "error" => "Only administrators can assign papers."]);


// add review requests
if ((isset($Qreq->requestreview) || isset($Qreq->approvereview))
    && $Qreq->post_ok()) {
    $result = RequestReview_API::requestreview($Me, $Qreq, $prow);
    $result = JsonResult::make($result);
    if ($result->content["ok"]) {
        if ($result->content["action"] === "token")
            $Conf->confirmMsg("Created a new anonymous review. The review token is " . $result->content["review_token"] . ".");
        else if ($result->content["action"] === "propose")
            $Conf->warnMsg($result->content["response"]);
        else
            $Conf->confirmMsg($result->content["response"]);
        unset($Qreq->email, $Qreq->firstName, $Qreq->lastName, $Qreq->affiliation, $Qreq->round, $Qreq->reason, $Qreq->override);
        SelfHref::redirect($Qreq);
    } else {
        $error = $result->content["error"];
        if (isset($result->content["errf"]) && isset($result->content["errf"]["override"]))
            $error .= "<div>To request a review anyway, submit again with the “Override” checkbox checked.</div>";
        Conf::msg_error($error);
        if (isset($result->content["errf"])) {
            foreach ($result->content["errf"] as $f => $x)
                Ht::error_at($f);
        }
        Ht::error_at("need-override-requestreview-" . $Qreq->email);
        loadRows();
    }
}


// deny review request
if ((isset($Qreq->deny) || isset($Qreq->denyreview))
    && $Me->allow_administer($prow)
    && $Qreq->post_ok()
    && ($email = trim($Qreq->email))) {
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
                              array("reviewer_contact" => (object) array("fullName" => trim($Qreq->name), "email" => $email)));

        $Conf->confirmMsg("Proposed reviewer denied.");
    } else
        Conf::msg_error("No one has proposed that " . htmlspecialchars($email) . " review this paper.");
    Dbl::qx_raw("unlock tables");
    unset($Qreq->email, $Qreq->name);
}


// paper table
$paperTable = new PaperTable($prow, $Qreq, "assign");
$paperTable->initialize(false, false);
$paperTable->resolveReview(false);

confHeader();


// begin form and table
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
    $result = $Conf->qe_apply("select * from ReviewRequest where paperId=?$q", $qv);
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
        Ht::form(hoturl_post("assign", "p=$prow->paperId"), array("id" => "ass")),
        '<p>';
    Ht::stash_script('hiliter_children("#ass", true)');

    if ($Conf->has_topics())
        echo "<p>Review preferences display as “P#”, topic scores as “T#”.</p>";
    else
        echo "<p>Review preferences display as “P#”.</p>";

    echo '<div class="pc_ctable has-assignment-set need-assignment-change"';
    $rev_rounds = array_keys($Conf->round_selector_options(false));
    echo ' data-review-rounds="', htmlspecialchars(json_encode($rev_rounds)), '"',
        ' data-default-review-round="', htmlspecialchars($Conf->assignment_round_name(false)), '">';
    $tagger = new Tagger($Me);
    $show_possible_conflicts = $Me->allow_view_authors($prow);

    foreach ($Conf->full_pc_members() as $pc) {
        $p = $pcx[$pc->contactId];
        if (!$pc->can_accept_review_assignment_ignore_conflict($prow))
            continue;

        // first, name and assignment
        $conflict_type = $prow->conflict_type($pc);
        $rrow = $prow->review_of_user($pc);
        if ($conflict_type >= CONFLICT_AUTHOR)
            $revtype = -2;
        else
            $revtype = $rrow ? $rrow->reviewType : 0;
        $pcconfmatch = null;
        if ($show_possible_conflicts && $revtype != -2)
            $pcconfmatch = $prow->potential_conflict_html($pc, $conflict_type <= 0);

        $color = $pc->viewable_color_classes($Me);
        echo '<div class="ctelt">',
            '<div class="ctelti', ($color ? " $color" : ""), ' has-assignment has-fold foldc" data-pid="', $prow->paperId,
            '" data-uid="', $pc->contactId,
            '" data-review-type="', $revtype;
        if ($conflict_type)
            echo '" data-conflict-type="1';
        if (!$revtype && $p->refused)
            echo '" data-assignment-refused="', htmlspecialchars($p->refused);
        if ($rrow && $rrow->reviewRound && ($rn = $rrow->round_name()))
            echo '" data-review-round="', htmlspecialchars($rn);
        if ($rrow && $rrow->reviewModified > 1)
            echo '" data-review-in-progress="';
        echo '"><div class="pctbname pctbname', $revtype, ' ui js-assignment-fold">',
            '<a class="qq taghl ui js-assignment-fold" href="">', expander(null, 0),
            $Me->name_html_for($pc), '</a>';
        if ($revtype != 0) {
            echo ' ', review_type_icon($revtype, $rrow && !$rrow->reviewSubmitted);
            if ($rrow && $rrow->reviewRound > 0)
                echo ' <span class="revround" title="Review round">',
                    htmlspecialchars($Conf->round_name($rrow->reviewRound)),
                    '</span>';
        }
        if ($revtype >= 0)
            echo unparse_preference_span($prow->reviewer_preference($pc, true));
        echo '</div>'; // .pctbname
        if ($pcconfmatch)
            echo '<div class="need-tooltip" data-tooltip-class="gray" data-tooltip="', str_replace('"', '&quot;', $pcconfmatch[1]), '">', $pcconfmatch[0], '</div>';

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
        echo "</div></div></div>\n"; // .pctbnrev .ctelti .ctelt
    }

    echo "</div>\n",
        '<div class="aab aabr aabig">',
        '<div class="aabut">', Ht::submit("update", "Save assignments", ["class" => "btn btn-primary"]), '</div>',
        '<div class="aabut">', Ht::submit("cancel", "Cancel"), '</div>',
        '<div id="assresult" class="aabut"></div>',
        '</div></form>';
}


echo "</div></div>\n";

// add external reviewers
$req = "Request an external review";
if (!$Me->allow_administer($prow) && $Conf->setting("extrev_chairreq"))
    $req = "Propose an external review";
echo Ht::form(hoturl_post("assign", "p=$prow->paperId"), ["novalidate" => true]),
    '<div class="revcard"><div class="revcard_head">',
    "<h3>", $req, "</h3></div><div class=\"revcard_body\">";

echo '<p class="papertext f-h">External reviewers may view their assigned papers, including ';
if ($Conf->setting("extrev_view") >= 2)
    echo "the other reviewers’ identities and ";
echo "any eventual decision.  Before requesting an external review,
 you should generally check personally whether they are interested.";
if ($Me->allow_administer($prow))
    echo "\nTo create an anonymous review with a review token, leave Name and Email blank.";
echo '</p>';

if (($rrow = $prow->review_of_user($Me))
    && $rrow->reviewType == REVIEW_SECONDARY
    && ($round_name = $Conf->round_name($rrow->reviewRound)))
    echo Ht::hidden("round", $round_name);
echo '<div class="papertext g">',
    '<div class="', Ht::control_class("email", "f-i"), '">',
    Ht::label("Email", "revreq_email"),
    Ht::entry("email", (string) $Qreq->email, ["id" => "revreq_email", "size" => 52, "class" => "fullw", "autocomplete" => "off", "type" => "email"]),
    '</div>',
    '<div class="f-2col">',
    '<div class="', Ht::control_class("firstName", "f-i"), '">',
    Ht::label("First name (given name)", "revreq_firstName"),
    Ht::entry("firstName", (string) $Qreq->firstName, ["id" => "revreq_firstName", "size" => 24, "class" => "fullw", "autocomplete" => "off"]),
    '</div><div class="', Ht::control_class("lastName", "f-i"), '">',
    Ht::label("Last name (family name)", "revreq_lastName"),
    Ht::entry("lastName", (string) $Qreq->lastName, ["id" => "revreq_lastName", "size" => 24, "class" => "fullw", "autocomplete" => "off"]),
    '</div></div>',
    '<div class="', Ht::control_class("affiliation", "f-i"), '">',
    Ht::label("Affiliation", "revreq_affiliation"),
    Ht::entry("affiliation", (string) $Qreq->affiliation, ["id" => "revreq_affiliation", "size" => 52, "class" => "fullw", "autocomplete" => "off"]),
    '</div>';

// reason area
$null_mailer = new HotCRPMailer($Conf);
$reqbody = $null_mailer->expand_template("requestreview", false);
if (strpos($reqbody["body"], "%REASON%") !== false) {
    echo '<div class="f-i">',
        Ht::label('Note to reviewer <span class="n">(optional)</span>', "revreq_reason"),
        Ht::textarea("reason", $Qreq->reason,
                ["class" => "need-autogrow fullw", "rows" => 2, "cols" => 60, "spellcheck" => "true", "id" => "revreq_reason"]),
        "</div>\n\n";
}

if ($Me->can_administer($prow))
    echo '<div class="', Ht::control_class("override", "checki"), '"><label><span class="checkc">',
        Ht::checkbox("override"),
        ' </span>Override deadlines, declined requests, and potential conflicts</label></div>';

echo "<div class='f-i'>\n",
    Ht::submit("requestreview", "Request review", ["class" => "btn btn-primary"]),
    "</div>\n\n";
Ht::stash_script("\$(\"#revreq_email\").on(\"input\",revreq_email_input)");

echo "</div></div></div></form>\n";

$Conf->footer();
