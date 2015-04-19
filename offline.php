<?php
// offline.php -- HotCRP offline review management page
// HotCRP is Copyright (c) 2006-2015 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

require_once("src/initweb.php");
if ($Me->is_empty())
    $Me->escape();
$rf = reviewForm();


// general error messages
if (defval($_REQUEST, "post") && !count($_POST))
    $Conf->errorMsg("It looks like you tried to upload a gigantic file, larger than I can accept.  The file was ignored.");


// download blank review form action
if (isset($_REQUEST["downloadForm"])) {
    $text = ReviewForm::textFormHeader("blank") . $rf->textForm(null, null, $Me, null) . "\n";
    downloadText($text, "review");
    exit;
}


// upload review form action
if (isset($_REQUEST["uploadForm"])
    && fileUploaded($_FILES["uploadedFile"])
    && check_post()) {
    $tf = $rf->beginTextForm($_FILES['uploadedFile']['tmp_name'], $_FILES['uploadedFile']['name']);
    while (($req = $rf->parseTextForm($tf))) {
	if (($prow = $Conf->paperRow($req["paperId"], $Me, $whyNot))
	    && !($whyNot = $Me->perm_submit_review($prow, null))) {
	    $rrow = $Conf->reviewRow(array("paperId" => $prow->paperId, "contactId" => $Me->contactId,
					   "rev_tokens" => $Me->review_tokens(),
					   "first" => true));
	    if ($rf->checkRequestFields($req, $rrow, $tf))
		$rf->save_review($req, $rrow, $prow, $Me, $tf);
	} else
	    $rf->tfError($tf, true, whyNotText($whyNot, "review"));
    }
    $rf->textFormMessages($tf);
    // Uploading forms may have completed the reviewer's task; recheck roles.
    $Me->update_cached_roles();
} else if (isset($_REQUEST["uploadForm"]))
    $Conf->errorMsg("Choose a file first.");


// upload tag indexes action
function saveTagIndexes($tag, $filename, &$settings, &$titles, &$linenos, &$errors) {
    global $Conf, $Me, $Error;

    $result = $Conf->qe($Conf->paperQuery($Me, array("paperId" => array_keys($settings))));
    while (($row = PaperInfo::fetch($result, $Me)))
	if ($settings[$row->paperId] !== null
            && !$Me->can_change_tag($row, $tag, null, 1)) {
	    $errors[$linenos[$row->paperId]] = "You cannot rank paper #$row->paperId.";
	    unset($settings[$row->paperId]);
	} else if ($titles[$row->paperId] !== ""
		   && strcmp($row->title, $titles[$row->paperId]) != 0
		   && strcasecmp($row->title, simplify_whitespace($titles[$row->paperId])) != 0)
	    $errors[$linenos[$row->paperId]] = "Warning: Title doesn’t match";

    if (!$tag)
	defappend($Error["tags"], "No tag defined");
    else if (count($settings)) {
        $x = array("paper,tag,lineno");
        foreach ($settings as $pid => $value)
            $x[] = "$pid,$tag#" . ($value === null ? "clear" : $value) . "," . $linenos[$pid];
        $assigner = new AssignmentSet($Me);
        $assigner->parse(join("\n", $x) . "\n", $filename);
        $assigner->report_errors();
        $assigner->execute();
    }

    $settings = $titles = $linenos = array();
}

function setTagIndexes() {
    global $Conf, $Me, $Error;
    $filename = null;
    if (isset($_REQUEST["upload"]) && fileUploaded($_FILES["file"])) {
	if (($text = file_get_contents($_FILES["file"]["tmp_name"])) === false) {
	    $Conf->errorMsg("Internal error: cannot read file.");
	    return;
	}
	$filename = @$_FILES["file"]["name"];
    } else if (!($text = defval($_REQUEST, "data"))) {
	$Conf->errorMsg("Choose a file first.");
	return;
    }

    $RealMe = $Me;
    $tagger = new Tagger;
    if (($tag = defval($_REQUEST, "tag")))
        $tag = $tagger->check($tag, Tagger::NOVALUE);
    $curIndex = 0;
    $lineno = 1;
    $settings = $titles = $linenos = $errors = array();
    foreach (explode("\n", rtrim(cleannl($text))) as $l) {
	if (substr($l, 0, 4) == "Tag:" || substr($l, 0, 6) == "# Tag:") {
	    if (!$tag)
		$tag = $tagger->check(trim(substr($l, ($l[0] == "#" ? 6 : 4))), Tagger::NOVALUE);
	    ++$lineno;
	    continue;
	} else if ($l == "" || $l[0] == "#") {
	    ++$lineno;
	    continue;
	}
	if (preg_match('/\A\s*?([Xx=]|>*|\([-\d]+\))\s+(\d+)\s*(.*?)\s*\z/', $l, $m)) {
	    if (isset($settings[$m[2]]))
		$errors[$lineno] = "Paper #$m[2] already given on line " . $linenos[$m[2]];
	    if ($m[1] == "X" || $m[1] == "x")
		$settings[$m[2]] = null;
	    else if ($m[1] == "" || $m[1] == ">")
		$settings[$m[2]] = $curIndex = $curIndex + 1;
	    else if ($m[1][0] == "(")
		$settings[$m[2]] = $curIndex = substr($m[1], 1, -1);
	    else if ($m[1] == "=")
		$settings[$m[2]] = $curIndex;
	    else
		$settings[$m[2]] = $curIndex = $curIndex + strlen($m[1]);
	    $titles[$m[2]] = $m[3];
	    $linenos[$m[2]] = $lineno;
	} else if ($RealMe->privChair && preg_match('/\A\s*<\s*([^<>]*?(|<[^<>]*>))\s*>\s*\z/', $l, $m)) {
	    if (count($settings) && $Me)
		saveTagIndexes($tag, $filename, $settings, $titles, $linenos, $errors);
            $ret = ContactSearch::make_pc($m[1], $RealMe);
            $Me = null;
            if (count($ret->ids) == 1)
                $Me = $ret->contact(0);
            else if (count($ret->ids) == 0)
                $errors[$lineno] = htmlspecialchars($m[1]) . " matches no PC member";
            else
                $errors[$lineno] = htmlspecialchars($m[1]) . " matches more than one PC member, give a full email address to disambiguate";
	} else if (trim($l) !== "")
	    $errors[$lineno] = "Syntax error";
	++$lineno;
    }

    if (count($settings) && $Me)
	saveTagIndexes($tag, $filename, $settings, $titles, $linenos, $errors);
    $Me = $RealMe;

    if (count($errors)) {
	ksort($errors);
        if ($filename)
            foreach ($errors as $lineno => &$error)
                $error = '<span class="lineno">' . htmlspecialchars($filename) . ':' . $lineno . ':</span> ' . $error;
        $Error["tags"] = '<div class="parseerr"><p>' . join("</p>\n<p>", $errors) . '</p></div>';
    }
    if (isset($Error["tags"]))
	$Conf->errorMsg($Error["tags"]);
    else if (isset($_REQUEST["setvote"]))
	$Conf->confirmMsg("Votes saved.");
    else
	$Conf->confirmMsg("Ranking saved.  To view it, <a href='" . hoturl("search", "q=order:" . urlencode($tag)) . "'>search for &ldquo;order:$tag&rdquo;</a>.");
}
if ((isset($_REQUEST["setvote"]) || isset($_REQUEST["setrank"]))
    && $Me->is_reviewer() && check_post())
    setTagIndexes();


$pastDeadline = !$Conf->time_review(null, $Me->isPC, true);

if (!$Conf->time_review_open() && !$Me->privChair) {
    $Conf->errorMsg("The site is not open for review.");
    go(hoturl("index"));
}

$Conf->header("Offline Reviewing", "offline", actionBar());

if ($Me->is_reviewer()) {
    if (!$Conf->time_review_open())
	$Conf->infoMsg("The site is not open for review.");
    $Conf->infoMsg("Use this page to download a blank review form, or to upload review forms you’ve already filled out.");
    if (!$Me->can_clickthrough("review"))
        PaperTable::echo_review_clickthrough();
} else
    $Conf->infoMsg("You aren’t registered as a reviewer or PC member for this conference, but for your information, you may download the review form anyway.");


echo "<table id='offlineform'>";

// Review forms
echo "<tr><td><h3>Download forms</h3>\n<div>";
if ($Me->is_reviewer()) {
    echo "<a href='", hoturl("search", "get=revform&amp;q=&amp;t=r&amp;p=all"), "'>Your reviews</a><br />\n";
    if ($Me->has_outstanding_review())
	echo "<a href='", hoturl("search", "get=revform&amp;q=&amp;t=rout&amp;p=all"), "'>Your incomplete reviews</a><br />\n";
    echo "<a href='", hoturl("offline", "downloadForm=1"), "'>Blank form</a></div>
<div class='g'></div>
<span class='hint'><strong>Tip:</strong> Use <a href='", hoturl("search", "q="), "'>Search</a> &gt; Download to choose individual papers.\n";
} else
    echo "<a href='", hoturl("offline", "downloadForm=1"), "'>Blank form</a></div>\n";
echo "</td>\n";
if ($Me->is_reviewer()) {
    $disabled = ($pastDeadline && !$Me->privChair ? " disabled='disabled'" : "");
    echo "<td><h3>Upload filled-out forms</h3>\n",
        Ht::form_div(hoturl_post("offline", "uploadForm=1")),
        Ht::hidden("postnonempty", 1),
        "<input type='file' name='uploadedFile' accept='text/plain' size='30' $disabled/>&nbsp; ",
        Ht::submit("Go", array("disabled" => !!$disabled));
    if ($pastDeadline && $Me->privChair)
	echo "<br />", Ht::checkbox("override"), "&nbsp;", Ht::label("Override&nbsp;deadlines");
    echo "<br /><span class='hint'><strong>Tip:</strong> You may upload a file containing several forms.</span>";
    echo "</div></form></td>\n";
}
echo "</tr>\n";


// Ranks
if ($Conf->setting("tag_rank") && $Me->is_reviewer()) {
    $ranktag = $Conf->setting_data("tag_rank");
    echo "<tr><td><div class='g'></div></td></tr>\n",
	"<tr><td><h3>Download ranking file</h3>\n<div>";
    echo "<a href=\"", hoturl("search", "get=rank&amp;tag=%7E$ranktag&amp;q=&amp;t=r&amp;p=all"), "\">Your reviews</a>";
    if ($Me->isPC)
	echo "<br />\n<a href=\"", hoturl("search", "get=rank&amp;tag=%7E$ranktag&amp;q=&amp;t=s&amp;p=all"), "\">All submitted papers</a>";
    echo "</div></td>\n";

    $disabled = ($pastDeadline && !$Me->privChair ? " disabled='disabled'" : "");
    echo "<td><h3>Upload ranking file</h3>\n",
        Ht::form_div(hoturl_post("offline", "setrank=1&amp;tag=%7E$ranktag")),
        Ht::hidden("upload", 1),
        "<input type='file' name='file' accept='text/plain' size='30' $disabled/>&nbsp; ",
        Ht::submit("Go", array("disabled" => !!$disabled));
    if ($pastDeadline && $Me->privChair)
	echo "<br />", Ht::checkbox("override"), "&nbsp;", Ht::label("Override&nbsp;deadlines");
    echo "<br /><span class='hint'><strong>Tip:</strong> Use “<a href='", hoturl("search", "q=" . urlencode("editsort:#~$ranktag")), "'>editsort:#~$ranktag</a>” to drag and drop your ranking.</span>";
    echo "<br /><span class='hint'><strong>Tip:</strong> “<a href='", hoturl("search", "q=order:%7E$ranktag"), "'>order:~$ranktag</a>” searches by your ranking.</span>";
    echo "</div></form></td>\n";
    echo "</tr>\n";
}

echo "</table>\n";


if (($text = $rf->webGuidanceRows($Me->permissive_view_score_bound(),
				  " initial")))
    echo "<div class='g'></div>

<table class='review'>
<tr class='id'>
  <td class='caption'></td>
  <td class='entry'><h3>Review form information</h3></td>
</tr>\n", $text, "<tr class='last'>
  <td class='caption'></td>
  <td class='entry'></td>
</tr></table>\n";

$Conf->footer();
