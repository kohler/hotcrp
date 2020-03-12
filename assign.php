<?php
// assign.php -- HotCRP per-paper assignment/conflict management page
// Copyright (c) 2006-2020 Eddie Kohler; see LICENSE.

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
    if (!($prow = PaperTable::fetch_paper_request($Qreq, $Me))) {
        errorMsgExit(whyNotText($Qreq->annex("paper_whynot") + ["listViewable" => true]));
    }
    if (($whynot = $Me->perm_request_review($prow, null, false))) {
        error_go($prow->hoturl(), whyNotText($whynot));
    }
}


loadRows();


// change PC assignments
function pcAssignments($qreq) {
    global $Conf, $Me, $prow;

    $reviewer = $qreq->reviewer;
    if (($rname = $Conf->sanitize_round_name($qreq->rev_round)) === "") {
        $rname = "unnamed";
    }
    $round = CsvGenerator::quote(":" . (string) $rname);

    $t = ["paper,action,email,round\n"];
    foreach ($Conf->pc_members() as $cid => $p) {
        if ($reviewer
            && strcasecmp($p->email, $reviewer) != 0
            && (string) $p->contactId !== $reviewer) {
            continue;
        }

        if (isset($qreq["assrev{$prow->paperId}u{$cid}"])) {
            $revtype = $qreq["assrev{$prow->paperId}u{$cid}"];
        } else if (isset($qreq["pcs{$cid}"])) {
            $revtype = $qreq["pcs{$cid}"];
        } else {
            continue;
        }
        $revtype = cvtint($revtype, null);
        if ($revtype === null) {
            continue;
        }

        $myround = $round;
        if (isset($qreq["rev_round{$prow->paperId}u{$cid}"])) {
            $x = $Conf->sanitize_round_name($qreq["rev_round{$prow->paperId}u{$cid}"]);
            if ($x !== false) {
                $myround = $x === "" ? "unnamed" : CsvGenerator::quote($x);
            }
        }

        $user = CsvGenerator::quote($p->email);
        if ($revtype >= 0) {
            $t[] = "{$prow->paperId},clearconflict,$user\n";
        }
        if ($revtype <= 0) {
            $t[] = "{$prow->paperId},clearreview,$user\n";
        }
        if ($revtype == REVIEW_META) {
            $t[] = "{$prow->paperId},metareview,$user,$myround\n";
        } else if ($revtype == REVIEW_PRIMARY) {
            $t[] = "{$prow->paperId},primary,$user,$myround\n";
        } else if ($revtype == REVIEW_SECONDARY) {
            $t[] = "{$prow->paperId},secondary,$user,$myround\n";
        } else if ($revtype == REVIEW_PC || $revtype == REVIEW_EXTERNAL) {
            $t[] = "{$prow->paperId},pcreview,$user,$myround\n";
        } else if ($revtype < 0) {
            $t[] = "{$prow->paperId},conflict,$user\n";
        }
    }

    $aset = new AssignmentSet($Me, true);
    $aset->enable_papers($prow);
    $aset->parse(join("", $t));
    if ($aset->execute()) {
        if ($qreq->ajax) {
            json_exit(["ok" => true]);
        } else {
            $Conf->confirmMsg("Assignments saved." . $aset->errors_div_html());
            $Conf->self_redirect($qreq);
            // NB normally does not return
            loadRows();
        }
    } else {
        if ($qreq->ajax) {
            json_exit(["ok" => false, "error" => join("<br />", $aset->errors_html())]);
        } else {
            $Conf->errorMsg(join("<br />", $aset->errors_html()));
        }
    }
}

if (isset($Qreq->update) && $Me->allow_administer($prow) && $Qreq->post_ok()) {
    pcAssignments($Qreq);
} else if (isset($Qreq->update) && $Qreq->ajax) {
    json_exit(["ok" => false, "error" => "Only administrators can assign papers."]);
}


// add review requests
if ((isset($Qreq->requestreview) || isset($Qreq->approvereview))
    && $Qreq->post_ok()) {
    $result = RequestReview_API::requestreview($Me, $Qreq, $prow);
    $result = JsonResult::make($result);
    if ($result->content["ok"]) {
        if ($result->content["action"] === "token") {
            $Conf->confirmMsg("Created a new anonymous review. The review token is " . $result->content["review_token"] . ".");
        } else if ($result->content["action"] === "propose") {
            $Conf->warnMsg($result->content["response"]);
        } else {
            $Conf->confirmMsg($result->content["response"]);
        }
        unset($Qreq->email, $Qreq->firstName, $Qreq->lastName, $Qreq->affiliation, $Qreq->round, $Qreq->reason, $Qreq->override);
        $Conf->self_redirect($Qreq);
    } else {
        if (isset($result->content["errf"])
            && isset($result->content["errf"]["override"])) {
            $result->content["error"] .= "<p>To request a review anyway, either retract the refusal or submit again with “Override” checked.</p>";
        }
        $result->export_errors();
        loadRows();
    }
}

// deny review request
if ((isset($Qreq->deny) || isset($Qreq->denyreview))
    && $Qreq->post_ok()) {
    $result = RequestReview_API::denyreview($Me, $Qreq, $prow);
    $result = JsonResult::make($result);
    if ($result->content["ok"]) {
        $Conf->confirmMsg("Proposed reviewer denied.");
        unset($Qreq->email, $Qreq->firstName, $Qreq->lastName, $Qreq->affiliation, $Qreq->round, $Qreq->reason, $Qreq->override, $Qreq->deny, $Qreq->denyreview);
        $Conf->self_redirect($Qreq);
    } else {
        $result->export_errors();
        loadRows();
    }
}

// retract review request
if (isset($Qreq->retractreview)
    && $Qreq->post_ok()) {
    $result = RequestReview_API::retractreview($Me, $Qreq, $prow);
    $result = JsonResult::make($result);
    if ($result->content["ok"]) {
        if ($result->content["notified"]) {
            $Conf->confirmMsg("Review retracted. The reviewer was notified that they do not need to complete their review.");
        } else {
            $Conf->confirmMsg("Review request retracted.");
        }
        unset($Qreq->email, $Qreq->firstName, $Qreq->lastName, $Qreq->affiliation, $Qreq->round, $Qreq->reason, $Qreq->override, $Qreq->retractreview);
        $Conf->self_redirect($Qreq);
    } else {
        $result->export_errors();
        loadRows();
    }
}

// retract review request
if (isset($Qreq->undeclinereview)
    && $Qreq->post_ok()) {
    $result = RequestReview_API::undeclinereview($Me, $Qreq, $prow);
    $result = JsonResult::make($result);
    if ($result->content["ok"]) {
        $email = $Qreq->email ? : "You";
        $Conf->confirmMsg("Review refusal retracted. " . htmlspecialchars($email) . " may now be asked again to review this submission.");
        unset($Qreq->email, $Qreq->firstName, $Qreq->lastName, $Qreq->affiliation, $Qreq->round, $Qreq->reason, $Qreq->override, $Qreq->unrefusereview);
        $Conf->self_redirect($Qreq);
    } else {
        $result->export_errors();
        loadRows();
    }
}



// paper table
$paperTable = new PaperTable($prow, $Qreq, "assign");
$paperTable->initialize(false, false);
$paperTable->resolveReview(false);

confHeader();


// begin form and table
$paperTable->paptabBegin();


// reviewer information
$t = review_table($Me, $prow, $paperTable->all_reviews(), null, "assign");
if ($t !== "") {
    echo '<hr class="papcard-sep"><h3>Reviews</h3>', $t;
}


// requested reviews
$requests = [];
foreach ($paperTable->all_reviews() as $rrow) {
    if ($rrow->reviewType < REVIEW_SECONDARY
        && $rrow->reviewModified <= 1
        && $Me->can_view_review_identity($prow, $rrow)
        && ($Me->can_administer($prow) || $rrow->requestedBy == $Me->contactId)) {
        $requests[] = [0, max((int) $rrow->timeRequestNotified, (int) $rrow->timeRequested), count($requests), $rrow];
    }
}
foreach ($prow->review_requests() as $rrow) {
    if ($Me->can_view_review_identity($prow, $rrow)) {
        $requests[] = [1, (int) $rrow->timeRequested, count($requests), $rrow];
    }
}
foreach ($prow->review_refusals() as $rrow) {
    if ($Me->can_view_review_identity($prow, $rrow)) {
        $requests[] = [2, (int) $rrow->timeRefused, count($requests), $rrow];
    }
}
usort($requests, function ($a, $b) {
    if ($a[0] !== $b[0]) {
        return $a[0] - $b[0];
    } else if ($a[1] !== $b[1]) {
        if ($a[1] === 0 || $b[1] === 0) {
            return $a[1] === 0 ? 1 : -1;
        } else {
            return $a[1] < $b[1] ? -1 : 1;
        }
    } else {
        return $a[2] - $b[2];
    }
});

if ($requests) {
    echo '<hr class="papcard-sep"><h3>Review requests</h3><div class="ctable-wide">';
}
foreach ($requests as $req) {
    echo '<div class="ctelt"><div class="ctelti has-fold';
    $rrow = $req[3];
    if ($req[0] === 1
        && ($Me->can_administer($prow) || $rrow->requestedBy == $Me->contactId)) {
        echo ' foldo';
    } else {
        echo ' foldc';
    }
    echo '">';

    $rrowid = null;
    if (isset($rrow->contactId) && $rrow->contactId > 0) {
        $rrowid = $Conf->cached_user_by_id($rrow->contactId);
    } else if ($req[0] === 1) {
        $rrowid = $Conf->cached_user_by_email($rrow->email);
    }
    if ($rrowid === null) {
        if ($req[0] === 1) {
            $rrowid = new Contact($rrow, $Conf);
        } else {
            $rrowid = $rrow;
        }
    }

    $actas = "";
    if (isset($rrow->contactId) && $rrow->contactId > 0) {
        $name = $Me->reviewer_html_for($rrowid);
        if ($rrow->contactId != $Me->contactId
            && $Me->privChair
            && $Me->allow_administer($prow)) {
            $actas = ' ' . Ht::link(Ht::img("viewas.png", "[Act as]", ["title" => "Become user"]),
                $prow->reviewurl(["actas" => $rrow->email]));
        }
    } else {
        $name = Text::name_html($rrowid);
    }
    $fullname = $name;
    if ((string) $rrowid->affiliation !== "") {
        $fullname .= ' <span class="auaff">(' . htmlspecialchars($rrowid->affiliation) . ')</span>';
    }
    if ((string) $rrowid->firstName !== "" || (string) $rrowid->lastName !== "") {
        $fullname .= ' &lt;' . Ht::link(htmlspecialchars($rrowid->email), "mailto:" . $rrowid->email, ["class" => "mailto"]) . '&gt;';
    }

    $namex = '<span class="fn">' . $name . '</span>'
        . '<span class="fx">' . $fullname . '</span>'
        . $actas;
    if ($req[0] <= 1) {
        $namex .= ' ' . review_type_icon($rrowid->isPC ? REVIEW_PC : REVIEW_EXTERNAL, true);
    }
    if ($rrow->reviewRound > 0 && $Me->can_view_review_round($prow, $rrow)) {
        $namex .= '&nbsp;<span class="revround" title="Review round">'
            . htmlspecialchars($Conf->round_name($rrow->reviewRound))
            . "</span>";
    }

    echo '<div class="ui js-foldup"><a href="" class="ui js-foldup">', expander(null, 0), '</a>';
    $reason = null;

    if ($req[0] === 0) {
        $rname = "Review " . ($rrow->reviewModified > 0 ? " (accepted)" : " (not started)");
        if ($Me->can_view_review($prow, $rrow)) {
            $rname = Ht::link($rname, $prow->reviewurl(["r" => $rrow->reviewId]));
        }
        echo $rname, ': ', $namex,
            '</div><div class="f-h"><ul class="x mb-0">';
        echo '<li>requested';
        if ($rrow->timeRequested) {
            echo ' ', $Conf->unparse_time_relative((int) $rrow->timeRequested);
        }
        if ($rrow->requestedBy == $Me->contactId) {
            echo " by you";
        } else if ($Me->can_view_review_requester($prow, $rrow)) {
            echo " by ", $Me->reviewer_html_for($rrow->requestedBy);
        }
        echo '</li>';
        if ($rrow->reviewModified == 1) {
            echo '<li>accepted';
            if ($req[1]) {
                echo ' ', $Conf->unparse_time_relative($req[1]);
            }
            echo '</li>';
        }
        echo '</ul></div>';
    } else if ($req[0] === 1) {
        echo "Review proposal: ", $namex, '</div><div class="f-h"><ul class="x mb-0">';
        if ($rrow->timeRequested || $Me->can_view_review_requester($prow, $rrow)) {
            echo '<li>proposed';
            if ($rrow->timeRequested) {
                echo ' ', $Conf->unparse_time_relative((int) $rrow->timeRequested);
            }
            if ($rrow->requestedBy == $Me->contactId) {
                echo " by you";
            } else if ($Me->can_view_review_requester($prow, $rrow)) {
                echo " by ", $Me->reviewer_html_for($rrow->requestedBy);
            }
            echo '</li>';
        }
        if ($Me->allow_view_authors($prow)
            && ($pt = $prow->potential_conflict_html($rrowid, true))) {
            foreach ($pt[1] as $i => $pcx) {
                echo '<li class="fx">possible conflict: ', $pcx, '</li>';
            }
            $reason = "This reviewer appears to have a conflict with the submission authors.";
        }
        echo '</ul></div>';
        $reason = $rrow->reason;
    } else {
        echo "Declined request: ", $namex,
            '</div><div class="f-h fx"><ul class="x mb-0">';
        if ($rrow->timeRequested || $Me->can_view_review_requester($prow, $rrow)) {
            echo '<li>requested';
            if ($rrow->timeRequested) {
                echo ' ', $Conf->unparse_time_relative((int) $rrow->timeRequested);
            }
            if ($rrow->requestedBy == $Me->contactId) {
                echo " by you";
            } else if ($Me->can_view_review_requester($prow, $rrow)) {
                echo " by ", $Me->reviewer_html_for($rrow->requestedBy);
            }
            echo '</li>';
        }
        echo '<li>declined';
        if ($rrow->timeRefused) {
            echo ' ', $Conf->unparse_time_relative((int) $rrow->timeRefused);
        }
        if ($rrow->refusedBy && (!$rrow->contactId || $rrow->contactId != $rrow->refusedBy)) {
            if ($rrow->refusedBy == $Me->contactId) {
                echo " by you";
            } else {
                echo " by ", $Me->reviewer_html_for($rrow->refusedBy);
            }
        }
        echo '</li>';
        if ((string) $rrow->reason !== ""
            && $rrow->reason !== "request denied by chair") {
            echo '<li class="mb-0-last-child">', Ht::format0("reason: " . $rrow->reason), '</li>';
        }
        echo '</ul></div>';
    }

    if ($Me->can_administer($prow)
        || ($req[0] !== 2 && $Me->contactId > 0 && $rrow->requestedBy == $Me->contactId)) {
        echo Ht::form(hoturl_post("assign", ["p" => $prow->paperId, "action" => "managerequest", "email" => $rrow->email, "round" => $rrow->reviewRound]), ["class" => "fx"]);
        if (!isset($rrow->contactId) || !$rrow->contactId) {
            foreach (["firstName", "lastName", "affiliation"] as $k) {
                echo Ht::hidden($k, $rrow->$k);
            }
        }
        $buttons = [];
        if ($reason) {
            echo Ht::hidden("reason", $reason);
        }
        if ($req[0] === 1 && $Me->can_administer($prow)) {
            echo Ht::hidden("override", 1);
            $buttons[] = Ht::submit("approvereview", "Approve proposal", ["class" => "btn-sm btn-success"]);
            $buttons[] = Ht::submit("denyreview", "Deny proposal", ["class" => "btn-sm ui js-deny-review-request"]); // XXX reason
        }
        if ($req[0] === 0) {
            $buttons[] = Ht::submit("retractreview", "Retract review", ["class" => "btn-sm"]);
        } else if ($req[0] === 1 && $Me->contactId > 0 && $rrow->requestedBy == $Me->contactId) {
            $buttons[] = Ht::submit("retractreview", "Retract proposal", ["class" => "btn-sm"]);
        }
        if ($req[0] === 2) {
            $buttons[] = Ht::submit("undeclinereview", "Remove declined request", ["class" => "btn-sm"]);
            $buttons[] = '<span class="hint">(allowing review to be reassigned)</span>';
        }
        if ($buttons) {
            echo '<div class="btnp">', join("", $buttons), '</div>';
        }
        echo '</form>';
    }

    echo '</div></div>';
}
if ($requests) {
    echo '</div>';
}



// PC assignments
if ($Me->can_administer($prow)) {
    $result = $Conf->qe("select ContactInfo.contactId, allReviews
        from ContactInfo
        left join (select contactId, group_concat(reviewType separator '') allReviews
            from PaperReview join Paper using (paperId)
            where reviewType>=" . REVIEW_PC . " and timeSubmitted>=0
            group by contactId) A using (contactId)
        where ContactInfo.roles!=0 and (ContactInfo.roles&" . Contact::ROLE_PC . ")!=0");
    $pcx = array();
    while (($row = edb_orow($result))) {
        $pcx[$row->contactId] = $row;
    }

    // PC conflicts row
    echo '<hr class="papcard-sep"><h3>PC assignments</h3>',
        Ht::form(hoturl_post("assign", "p=$prow->paperId"), array("id" => "ass", "class" => "need-unload-protection")),
        '<p>';
    Ht::stash_script('hiliter_children("#ass")');

    if ($Conf->has_topics()) {
        echo "<p>Review preferences display as “P#”, topic scores as “T#”.</p>";
    } else {
        echo "<p>Review preferences display as “P#”.</p>";
    }

    echo '<div class="pc-ctable has-assignment-set need-assignment-change"';
    $rev_rounds = array_keys($Conf->round_selector_options(false));
    echo ' data-review-rounds="', htmlspecialchars(json_encode($rev_rounds)), '"',
        ' data-default-review-round="', htmlspecialchars($Conf->assignment_round_option(false)), '">';
    $tagger = new Tagger($Me);
    $show_possible_conflicts = $Me->allow_view_authors($prow);

    foreach ($Conf->full_pc_members() as $pc) {
        $p = $pcx[$pc->contactId];
        if (!$pc->can_accept_review_assignment_ignore_conflict($prow)) {
            continue;
        }

        // first, name and assignment
        $conflict_type = $prow->conflict_type($pc);
        $rrow = $prow->review_of_user($pc);
        if ($conflict_type >= CONFLICT_AUTHOR) {
            $revtype = -2;
        } else {
            $revtype = $rrow ? $rrow->reviewType : 0;
        }
        $crevtype = $revtype;
        if ($crevtype == 0 && $conflict_type > 0) {
            $crevtype = -1;
        }
        $pcconfmatch = null;
        if ($show_possible_conflicts && $revtype != -2) {
            $pcconfmatch = $prow->potential_conflict_html($pc, $conflict_type <= 0);
        }

        echo '<div class="ctelt">',
            '<div class="ctelti has-assignment has-fold foldc" data-pid="', $prow->paperId,
            '" data-uid="', $pc->contactId,
            '" data-review-type="', $revtype;
        if ($conflict_type) {
            echo '" data-conflict-type="1';
        }
        if (!$revtype && $prow->review_refusals_of_user($pc)) {
            echo '" data-assignment-refused="1';
        }
        if ($rrow && $rrow->reviewRound && ($rn = $rrow->round_name())) {
            echo '" data-review-round="', htmlspecialchars($rn);
        }
        if ($rrow && $rrow->reviewModified > 1) {
            echo '" data-review-in-progress="';
        }
        echo '"><div class="pctbname pctbname', $crevtype, ' ui js-assignment-fold">',
            '<a class="qq ui js-assignment-fold" href="">', expander(null, 0),
            $Me->reviewer_html_for($pc), '</a>';
        if ($crevtype != 0) {
            echo review_type_icon($crevtype, $rrow && !$rrow->reviewSubmitted, "ml-2");
            if ($rrow && $rrow->reviewRound > 0) {
                echo ' <span class="revround" title="Review round">',
                    htmlspecialchars($Conf->round_name($rrow->reviewRound)),
                    '</span>';
            }
        }
        if ($revtype >= 0) {
            echo unparse_preference_span($prow->preference($pc, true));
        }
        echo '</div>'; // .pctbname
        if ($pcconfmatch) {
            echo '<div class="need-tooltip" data-tooltip-class="gray" data-tooltip="', str_replace('"', '&quot;', PaperInfo::potential_conflict_tooltip_html($pcconfmatch)), '">', $pcconfmatch[0], '</div>';
        }

        // then, number of reviews
        echo '<div class="pctbnrev">';
        $numReviews = strlen($p->allReviews);
        $numPrimary = substr_count($p->allReviews, REVIEW_PRIMARY);
        if (!$numReviews) {
            echo "0 reviews";
        } else {
            echo '<a class="q" href="',
                hoturl("search", "q=re:" . urlencode($pc->email)), '">',
                plural($numReviews, "review"), "</a>";
            if ($numPrimary && $numPrimary < $numReviews) {
                echo '&nbsp; (<a class="q" href="',
                    hoturl("search", "q=pri:" . urlencode($pc->email)),
                    "\">$numPrimary primary</a>)";
            }
        }
        echo "</div></div></div>\n"; // .pctbnrev .ctelti .ctelt
    }

    echo "</div>\n",
        '<div class="aab aabr aabig">',
        '<div class="aabut">', Ht::submit("update", "Save assignments", ["class" => "btn-primary"]), '</div>',
        '<div class="aabut">', Ht::submit("cancel", "Cancel"), '</div>',
        '<div id="assresult" class="aabut"></div>',
        '</div></form>';
}


echo "</div></div>\n";

// add external reviewers
$req = "Request an external review";
if (!$Me->allow_administer($prow) && $Conf->setting("extrev_chairreq")) {
    $req = "Propose an external review";
}
echo '<div class="pcard revcard">',
    Ht::form(hoturl_post("assign", "p=$prow->paperId"), ["novalidate" => true]),
    '<div class="revcard-head">',
    "<h3>", $req, "</h3></div><div class=\"revcard-body\">";

echo '<p class="papertext">', $Conf->_i("external-review-request-description", null);
if ($Me->allow_administer($prow)) {
    echo "\nTo create an anonymous review with a review token, leave Name and Email blank.";
}
echo '</p>';

if (($rrow = $prow->review_of_user($Me))
    && $rrow->reviewType == REVIEW_SECONDARY
    && ($round_name = $Conf->round_name($rrow->reviewRound))) {
    echo Ht::hidden("round", $round_name);
}
$email_class = "fullw";
if ($Me->can_lookup_user()) {
    $email_class .= " uii js-email-populate";
}
echo '<div class="papertext g">',
    '<div class="', Ht::control_class("email", "f-i"), '">',
    Ht::label("Email", "revreq_email"),
    Ht::entry("email", (string) $Qreq->email, ["id" => "revreq_email", "size" => 52, "class" => $email_class, "autocomplete" => "off", "type" => "email"]),
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
$reqbody = $null_mailer->expand_template("requestreview");
if ($reqbody && strpos($reqbody["body"], "%REASON%") !== false) {
    echo '<div class="f-i">',
        Ht::label('Note to reviewer <span class="n">(optional)</span>', "revreq_reason"),
        Ht::textarea("reason", $Qreq->reason,
                ["class" => "need-autogrow fullw", "rows" => 2, "cols" => 60, "spellcheck" => "true", "id" => "revreq_reason"]),
        "</div>\n\n";
}

if ($Me->can_administer($prow)) {
    echo '<label class="', Ht::control_class("override", "checki"), '"><span class="checkc">',
        Ht::checkbox("override"),
        ' </span>Override deadlines and declined requests</label>';
}

echo '<div class="aab aabr">',
    '<div class="aabut aabutsp">', Ht::submit("requestreview", "Request review", ["class" => "btn-primary"]), '</div>',
    '<div class="aabut"><a class="ui x js-request-review-preview-email" href="">Preview request email</a></div>',
    "</div>\n\n";

echo "</div></div></form></div></div>\n";

$Conf->footer();
