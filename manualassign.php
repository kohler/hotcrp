<?php
// manualassign.php -- HotCRP chair's paper assignment page
// HotCRP is Copyright (c) 2006-2014 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

require_once("src/initweb.php");
require_once("src/papersearch.php");
if (!$Me->privChair)
    $Me->escape();

// paper selection
if (!isset($_REQUEST["q"]) || trim($_REQUEST["q"]) == "(All)")
    $_REQUEST["q"] = "";
if (!isset($_REQUEST["t"]))
    $_REQUEST["t"] = ($Conf->has_managed_submissions() ? "unm" : "s");

$kind = defval($_REQUEST, "kind", "a");
if ($kind != "a" && $kind != "c")
    $kind = "a";
if (!isset($_REQUEST["p"]) && isset($_REQUEST["pap"]))
    $_REQUEST["p"] = $_REQUEST["pap"];
if (isset($_REQUEST["p"]) && is_string($_REQUEST["p"]))
    $_REQUEST["p"] = preg_split('/\s+/', $_REQUEST["p"]);
if (isset($_REQUEST["p"]) && is_array($_REQUEST["p"]) && $kind == "c") {
    foreach ($_REQUEST["p"] as $p)
        if (($p = cvtint($p)) > 0)
            $_REQUEST["assrev$p"] = -1;
}
if (isset($_REQUEST["papx"]) && is_string($_REQUEST["papx"]))
    $_REQUEST["papx"] = preg_split('/\s+/', $_REQUEST["papx"]);
if (isset($_REQUEST["papx"]) && is_array($_REQUEST["papx"])) {
    foreach ($_REQUEST["papx"] as $p)
        if (($p = cvtint($p)) > 0 && !isset($_REQUEST["assrev$p"]))
            $_REQUEST["assrev$p"] = 0;
}

// set review round
if (!isset($_REQUEST["rev_roundtag"]))
    $rev_roundtag = $Conf->setting_data("rev_roundtag");
else if (($rev_roundtag = $_REQUEST["rev_roundtag"]) == "(None)")
    $rev_roundtag = "";
$Error = $Warning = array();
if ($rev_roundtag && !preg_match('/^[a-zA-Z0-9]+$/', $rev_roundtag)) {
    $Error["rev_roundtag"] = true;
    $Conf->errorMsg("The review round must contain only letters and numbers.");
    $rev_roundtag = "";
}
if ($rev_roundtag)
    $Conf->save_setting("rev_roundtag", 1, $rev_roundtag);
else
    $Conf->save_setting("rev_roundtag", null);


$abar = "<div class='vbar'><table class='vbar'><tr><td><table><tr>\n";
$abar .= actionTab("Automatic", hoturl("autoassign"), false);
$abar .= actionTab("Manual", hoturl("manualassign"), true);
$abar .= actionTab("Upload", hoturl("bulkassign"), false);
$abar .= "</tr></table></td>\n<td class='spanner'></td>\n<td class='gopaper nowrap'>" . goPaperForm() . "</td></tr></table></div>\n";


$Conf->header("Review Assignments", "assignpc", $abar);


$pcm = pcMembers();
$reviewer = rcvtint($_REQUEST["reviewer"]);
if ($reviewer <= 0)
    $reviewer = $Me->contactId;
if ($reviewer <= 0 || !@$pcm[$reviewer])
    $reviewer = 0;


function saveAssignments($reviewer) {
    global $Conf, $Me, $kind;

    $result = $Conf->qe("lock tables Paper read, PaperReview write, PaperReviewRefused write, PaperConflict write" . $Conf->tagRoundLocker($kind == "a"));
    if (!$result)
        return $result;

    $result = $Conf->qe("select Paper.paperId, PaperConflict.conflictType,
        reviewId, reviewType, reviewModified
        from Paper
        left join PaperReview on (Paper.paperId=PaperReview.paperId and PaperReview.contactId=$reviewer)
        left join PaperConflict on (Paper.paperId=PaperConflict.paperId and PaperConflict.contactId=$reviewer)
        where timeSubmitted>0
        order by paperId asc, reviewId asc");

    $lastPaperId = -1;
    $del = $ins = "";
    $when = time();
    while (($row = edb_orow($result))) {
        if ($row->paperId == $lastPaperId
            || $row->conflictType >= CONFLICT_AUTHOR
            || !isset($_REQUEST["assrev$row->paperId"]))
            continue;
        $lastPaperId = $row->paperId;
        $type = cvtint($_REQUEST["assrev$row->paperId"], 0);
        if ($type >= 0 && $row->conflictType > 0 && $row->conflictType < CONFLICT_AUTHOR)
            $del .= " or paperId=$row->paperId";
        if ($type < 0 && $row->conflictType < CONFLICT_CHAIRMARK)
            $ins .= ", ($row->paperId, $reviewer, " . CONFLICT_CHAIRMARK . ")";
        if ($kind == "a")
            $Me->assign_paper($row->paperId, $row, $reviewer, $type, $when);
    }

    if ($ins)
        $Conf->qe("insert into PaperConflict (paperId, contactId, conflictType) values " . substr($ins, 2) . " on duplicate key update conflictType=greatest(conflictType,values(conflictType))");
    if ($del)
        $Conf->qe("delete from PaperConflict where contactId=$reviewer and (" . substr($del, 4) . ")");

    $Conf->qe("unlock tables");
    $Conf->updateRevTokensSetting(false);

    if ($Conf->setting("pcrev_assigntime") == $when)
        $Conf->confirmMsg("Assignments saved! You may want to <a href=\"" . hoturl("mail", "template=newpcrev") . "\">send mail about the new assignments</a>.");
}


if (isset($_REQUEST["update"]) && $reviewer > 0 && check_post())
    saveAssignments($reviewer);
else if (isset($_REQUEST["update"]))
    $Conf->errorMsg("You need to select a reviewer.");


// Help list
echo "<div class='helpside'><div class='helpinside'>
Assignment methods:
<ul><li><a href='", hoturl("autoassign"), "'>Automatic</a></li>
 <li><a href='", hoturl("manualassign"), "' class='q'><strong>Manual by PC member</strong></a></li>
 <li><a href='", hoturl("assign"), "'>Manual by paper</a></li>
 <li><a href='", hoturl("bulkassign"), "'>Upload</a></li>
</ul>
<hr class='hr' />\n";
if ($kind == "a")
    echo "Types of PC review:
<dl><dt>" . review_type_icon(REVIEW_PRIMARY) . " Primary</dt><dd>Mandatory, may not be delegated</dd>
  <dt>" . review_type_icon(REVIEW_SECONDARY) . " Secondary</dt><dd>Mandatory, may be delegated to external reviewers</dd>
  <dt>" . review_type_icon(REVIEW_PC) . " Optional</dt><dd>May be declined</dd></dl>
<hr class='hr' />\n";
echo "<dl><dt>Potential conflicts</dt><dd>Matches between PC member collaborators and paper authors, or between PC member and paper authors or collaborators</dd>\n";
if ($kind == "a")
    echo "<dt>Preference</dt><dd><a href='", hoturl("reviewprefs"), "'>Review preference</a></dd>
  <dt>Topic score</dt><dd>+4 per high interest paper topic, &minus;2 per low interest paper topic</dd>
  <dt>Desirability</dt><dd>High values mean many PC members want to review the paper</dd>\n";
echo "</dl>\nClick a heading to sort.\n</div></div>";


if ($reviewer > 0)
    echo "<h2 style='margin-top:1em'>Assignments for ", Text::name_html($pcm[$reviewer]), ($pcm[$reviewer]->affiliation ? " (" . htmlspecialchars($pcm[$reviewer]->affiliation) . ")" : ""), "</h2>\n";
else
    echo "<h2 style='margin-top:1em'>Assignments by PC member</h2>\n";


// Change PC member
echo "<table><tr><td><div class='aahc assignpc_pcsel'><form method='get' action='", hoturl("manualassign"), "' accept-charset='UTF-8' id='selectreviewerform'><div class='inform'>\n";

$result = $Conf->qe("select PCMember.contactId, count(reviewId) as reviewCount
                from PCMember
                left join PaperReview on (PCMember.contactId=PaperReview.contactId and PaperReview.reviewType>=" . REVIEW_SECONDARY . ")
                group by contactId");
$rev_count = array();
while (($row = edb_row($result)))
    $rev_count[$row[0]] = $row[1];

$rev_opt = array();
if ($reviewer <= 0)
    $rev_opt[0] = "(Select a PC member)";
foreach ($pcm as $pc)
    $rev_opt[$pc->contactId] = Text::name_html($pc) . " ("
        . plural(defval($rev_count, $pc->contactId, 0), "assignment") . ")";

echo "<table><tr><td><strong>PC member:</strong> &nbsp;</td>",
    "<td>", Ht::select("reviewer", $rev_opt, $reviewer, array("onchange" => "hiliter(this)")), "</td></tr>",
    "<tr><td colspan='2'><div class='g'></div></td></tr>\n";

// Paper selection
if ($Conf->has_managed_submissions())
    $tOpt = array("unm" => "Unmanaged submissions",
                  "s" => "All submissions");
else
    $tOpt = array("s" => "Submitted papers");
$tOpt["acc"] = "Accepted papers";
$tOpt["und"] = "Undecided papers";
$tOpt["all"] = "All papers";
if (!isset($_REQUEST["t"]) || !isset($tOpt[$_REQUEST["t"]]))
    $_REQUEST["t"] = "s";
$q = (defval($_REQUEST, "q", "") == "" ? "(All)" : $_REQUEST["q"]);
echo "<tr><td>Paper selection: &nbsp;</td>",
    "<td><input id='manualassignq' class='temptextoff' type='text' size='40' name='q' value=\"", htmlspecialchars($q), "\" onchange='hiliter(this)' title='Enter paper numbers or search terms' /> &nbsp;in &nbsp;",
    Ht::select("t", $tOpt, $_REQUEST["t"], array("onchange" => "hiliter(this)")),
    "</td></tr>\n",
    "<tr><td colspan='2'><div class='g'></div>\n";
$Conf->footerScript("mktemptext('manualassignq','(All)')");

echo Ht::radio("kind", "a", $kind == "a",
               array("onchange" => "hiliter(this)")),
    "&nbsp;", Ht::label("Assign reviews and/or conflicts"), "<br />\n",
    Ht::radio("kind", "c", $kind == "c",
               array("onchange" => "hiliter(this)")),
    "&nbsp;", Ht::label("Assign conflicts only (and limit papers to potential conflicts)"), "</td></tr>\n";

if ($kind == "a") {
    echo "<tr><td colspan='2'><div class='g'></div></td></tr>\n",
        "<tr><td>",
        (isset($Error["rev_roundtag"]) ? "<span class='error'>" : ""),
        "Review round: &nbsp;</td>",
        "<td><input id='assrevroundtag' class='temptextoff' type='text' size='15' name='rev_roundtag' value=\"", htmlspecialchars($rev_roundtag ? $rev_roundtag : "(None)"), "\" />",
        (isset($Error["rev_roundtag"]) ? "</span>" : ""),
        " &nbsp;<a class='hint' href='", hoturl("help", "t=revround"), "'>What is this?</a>\n",
        "</td></tr>";
    $Conf->footerScript("mktemptext('assrevroundtag','(None)')");
}

echo "<tr><td colspan='2'><div class='aax' style='text-align:right'>",
    Ht::submit("Go", array("class" => "bb")),
    "</div></td></tr>\n",
    "</table>\n</div></form></div></td></tr></table>\n";


function make_match_preg($str) {
    $a = $b = array();
    foreach (explode(" ", preg_quote($str)) as $word)
        if ($word != "") {
            $a[] = Text::utf8_word_regex($word);
            if (!preg_match("/[\x80-\xFF]/", $word))
                $b[] = Text::word_regex($word);
        }
    $x = (object) array("preg_utf8" => join("|", $a));
    if (count($a) == count($b))
        $x->preg_raw = join("|", $b);
    return $x;
}

// Current PC member information
if ($reviewer > 0) {
    $col = array(array(), array(), array());

    // Conflict information
    $result = $Conf->qe("select firstName, lastName, affiliation, collaborators from ContactInfo where contactId=$reviewer");
    if ($result && ($row = edb_orow($result))) {
        if ($row->collaborators)
            $col[1][] = "<div class='f-c'>Collaborators</div><div class='f-e'>"
                . nl2br(htmlspecialchars($row->collaborators))
                . "</div>";

        $useless_words = array("university" => 1, "the" => 1, "and" => 1, "of" => 1, "univ" => 1, "none" => 1, "a" => 1, "an" => 1, "jr" => 1, "sr" => 1, "iii" => 1);

        // search outline from old CRP, done here in a very different way
        preg_match_all('/[a-z&]{2,}/', strtolower($row->firstName . " " . $row->lastName . " " . $row->affiliation), $match);
        $useless = $useless_words;
        $search = array();
        $showco = "";
        foreach ($match[0] as $s)
            if (!isset($useless[$s])) {
                $search[] = "co:" . (ctype_alnum($s) ? $s : "\"$s\"");
                $showco .= $s . " ";
                $useless[$s] = 1;
            }

        preg_match_all('/[a-z&]{2,}/', strtolower($row->firstName . " " . $row->lastName . " " . $row->affiliation . " " . $row->collaborators), $match);
        $useless = $useless_words;
        $showau = "";
        foreach ($match[0] as $s)
            if (!isset($useless[$s])) {
                $search[] = "au:" . (ctype_alnum($s) ? $s : "\"$s\"");
                $showau .= $s . " ";
                $useless[$s] = 1;
            }

        if ($showau !== "")
            $col[2][] = "<div class='f-c'>Conflict search terms for paper authors</div><div class='f-e'>"
                . htmlspecialchars(rtrim($showau)) . "</div>";
        if ($showco !== "")
            $col[2][] = "<div class='f-c'>Conflict search terms for paper collaborators</div><div class='f-e'>"
                . htmlspecialchars(rtrim($showco)) . "</div>";
        $col[2][] = "<a href=\"" . hoturl("search", "q=" . urlencode(join(" OR ", $search)) . "&amp;linkto=assign") . "\">Search for potential conflicts</a>";
    }

    // Topic links
    $result = $Conf->qe("select topicId, " . $Conf->query_topic_interest() . " from TopicArea join TopicInterest using (topicId) where contactId=$reviewer");
    $interest = array(array(), array());
    while (($row = edb_row($result)))
        if ($row[1] != 0)
            $interest[$row[1] > 0 ? 1 : 0][$row[0]] = $row[1];
    if (count($interest[1]))
        $col[0][] = "<div class='f-c'>High interest topics</div><div class='f-e'>"
            . join(", ", PaperInfo::unparse_topics(array_keys($interest[1]), array_values($interest[1])))
            . "</div>";
    if (count($interest[0]))
        $col[0][] = "<div class='f-c'>Low interest topics</div><div class='f-e'>"
            . join(", ", PaperInfo::unparse_topics(array_keys($interest[0]), array_values($interest[0])))
            . "</div>";

    // Table
    if (count($col[0]) || count($col[1]) || count($col[2])) {
        echo "<table><tr>\n";
        foreach ($col as $thecol)
            if (count($thecol)) {
                echo "<td class='top'><table>";
                foreach ($thecol as $td)
                    echo "<tr><td style='padding:0 2em 1ex 0'>", $td, "</td></tr>";
                echo "</table></td>\n";
            }
        echo "</tr></table>\n";
    }

    // ajax assignment form
    echo "<form id='assrevform' method='post' action=\"", hoturl_post("assign", "update=1"), "\" enctype='multipart/form-data' accept-charset='UTF-8'><div class='clear'>",
        Ht::hidden("kind", $kind),
        Ht::hidden("p", ""),
        Ht::hidden("pcs$reviewer", ""),
        Ht::hidden("reviewer", $reviewer),
        Ht::hidden("rev_roundtag", $rev_roundtag),
        "</div></form>\n\n";

    // main assignment form
    $search = new PaperSearch($Me, array("t" => $_REQUEST["t"],
                                         "q" => $_REQUEST["q"],
                                         "urlbase" => hoturl_site_relative("manualassign", "reviewer=$reviewer")));
    $paperList = new PaperList($search, array("sort" => true, "list" => true, "reviewer" => $reviewer));
    $paperList->display .= " topics ";
    if ($kind != "c")
        $paperList->display .= "reviewers ";
    if (isset($showau)) {
        $search->overrideMatchPreg = true;
        $search->matchPreg = array();
        if ($showau)
            $search->matchPreg["authorInformation"] = make_match_preg($showau);
        if ($showco)
            $search->matchPreg["collaborators"] = make_match_preg($showco);
    }
    $a = isset($_REQUEST["sort"]) ? "&amp;sort=" . urlencode($_REQUEST["sort"]) : "";
    echo "<div class='aahc'><form class='assignpc' method='post' action=\"", hoturl_post("manualassign", "reviewer=$reviewer&amp;kind=$kind$a"),
        "\" enctype='multipart/form-data' accept-charset='UTF-8'><div>\n",
        Ht::hidden("t", $_REQUEST["t"]),
        Ht::hidden("q", $_REQUEST["q"]),
        Ht::hidden("papx", join(" ", $search->paperList())),
        "<div class=\"aa\">",
        Ht::submit("update", "Save assignments", array("class" => "bb")),
        "<span style='padding:0 0 0 2em'>",
        Ht::checkbox(false, false, true, array("id" => "assrevimmediate")),
        "&nbsp;", Ht::label("Automatically save assignments", "assrevimmediate"),
        "</span></div>\n",
        $paperList->text(($kind == "c" ? "conflict" : "reviewAssignment"),
                         array("class" => "pltable_full",
                               "header_links" => true,
                               "nofooter" => true,
                               "fold" => array("allrevtopicpref" => true))),
        "<div class='aa'>",
        Ht::submit("update", "Save assignments", array("class" => "bb")),
        "</div></div></form></div>\n";
}

echo "<div class='clear'></div>";
$Conf->footer();
