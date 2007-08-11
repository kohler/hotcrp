<?php
// PC/reviewprefs.php -- HotCRP review preference global settings page
// HotCRP is Copyright (c) 2006-2007 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

require_once('../Code/header.inc');
require_once('../Code/paperlist.inc');
require_once('../Code/search.inc');
$Me = $_SESSION["Me"];
$Me->goIfInvalid();
$Me->goIfNotPC('../index.php');
$reviewer = cvtint($_REQUEST["reviewer"]);
if ($reviewer <= 0 || !$Me->privChair)
    $reviewer = $Me->contactId;

$Conf->header("Review Preferences", "revpref", actionBar());

function savePreferences($reviewer) {
    global $Conf, $Me, $reviewTypeName;

    $setting = array();
    $error = false;
    $pmax = 0;
    foreach ($_REQUEST as $k => $v)
	if ($k[0] == 'r' && substr($k, 0, 7) == "revpref"
	    && ($p = cvtint(substr($k, 7))) > 0) {
	    if (($v = cvtpref($v)) >= -1000000 && $v <= 1000000) {
		if ($v != 0)
		    $setting[$p] = $v;
		$pmax = max($pmax, $p);
	    } else
		$error = true;
	}

    if ($error)
	$Conf->errorMsg("Bad preference setting.");
    if ($pmax == 0 && !$error)
	$Conf->errorMsg("No reviewer preferences to update.");
    if ($pmax == 0)
	return;

    $while = "while saving review preferences";
    $result = $Conf->qe("lock tables PaperReviewPreference write", $while);
    if (!$result)
	return $result;

    $delete = "delete from PaperReviewPreference where contactId=$reviewer and (";
    $orjoin = "";
    for ($p = 1; $p <= $pmax; $p++)
	if (isset($setting[$p])) {
	    $delete .= $orjoin;
	    if (!isset($setting[$p + 1]))
		$delete .= "paperId=$p";
	    else {
		$delete .= "paperId between $p and ";
		for ($p++; isset($setting[$p + 1]); $p++)
		    /* nada */;
		$delete .= $p;
	    }
	    $orjoin = " or ";
	}
    $Conf->qe($delete . ")", $while);

    $q = "";
    for ($p = 1; $p <= $pmax; $p++)
	if (isset($setting[$p]))
	    $q .= "($p, $reviewer, $setting[$p]), ";
    if (strlen($q))
	$Conf->qe("insert into PaperReviewPreference (paperId, contactId, preference) values " . substr($q, 0, strlen($q) - 2), $while);

    $Conf->qe("unlock tables", $while);
}
if (isset($_REQUEST["update"]))
    savePreferences($reviewer);


$rf = reviewForm();
if (count($rf->topicName))
    $topicnote = " and their topics.  You have high
interest in <span class='topic2'>bold topics</span>, and low interest in <span
class='topic0'>grey topics</span>.  \"Topic score\" is higher the more you
are interested in the paper's topics";
else
    $topicnote = "";

$Conf->infoMsg("<p>Review preferences are integers.
The higher your preference, the more you want to review a paper.
0 means you don't care either way; use negative numbers for papers you don't want to review, and &minus;100 or less for conflicts.
Multiple papers can have the same preference.
The system will try to assign your reviews in preference order.</p>

<p>The list shows all submitted papers$topicnote.
Click on a column heading to sort by
that column.  You may also enter preferences on the paper pages, which 
show abstracts and other information.  Access a paper page by clicking
the paper title.</p>");


if ($Me->privChair) {
    echo "<form method='get' action='reviewprefs.php' name='selectReviewer'>
  <b>Showing preferences for:&nbsp;</b>
  <select name='reviewer' onchange='document.selectReviewer.submit()'>\n";

    $query = "select ContactInfo.contactId, firstName, lastName,
		count(preference) as preferenceCount
		from ContactInfo
		join PCMember using (contactId)
		left join PaperReviewPreference on (ContactInfo.contactId=PaperReviewPreference.contactId)
		group by contactId
		order by lastName, firstName, email";
    $result = $Conf->qe($query);
    while (($row = edb_orow($result))) {
	echo "<option value='$row->contactId'";
	if ($row->contactId == $reviewer)
	    echo " selected='selected'";
	echo ">", contactHtml($row);
	if ($row->preferenceCount <= 0)
	    echo " (no preferences)";
	echo "</option>";
    }
    echo "</select>\n</form>\n<hr />\n\n";
}

$searchType = ($Conf->setting("pc_seeall") > 0 ? "all" : "s");
$paperList = new PaperList(true, "list", new PaperSearch($Me, array("t" => $searchType, "c" => $reviewer, "urlbase" => "PC/reviewprefs.php?reviewer=$reviewer")));
unset($_SESSION["matchPreg"]);

echo "<form name='prefform' method='post' action=\"${ConfSiteBase}paper.php\" enctype='multipart/form-data'>
  <input type='hidden' name='paperId' value='' />
  <input type='hidden' name='revpref' value='' />\n";
if ($Me->privChair)
    echo "  <input type='hidden' name='contactId' value='$reviewer' />\n";
echo "</form>\n\n";

echo "<form class='assignpc' method='post' action=\"reviewprefs.php?reviewer=$reviewer&amp;post=1\" enctype='multipart/form-data'>\n";
echo $paperList->text("editReviewPreference", $Me);
echo "<div class='smgap'></div><table class='center'><tr><td><input class='button' type='submit' name='update' value='Save preferences' /></td></tr></table>\n";
echo "</form>\n";

$Conf->footer();
