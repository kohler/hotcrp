<?php
// contacts.php -- HotCRP people listing/editing page
// HotCRP is Copyright (c) 2006-2008 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

require_once("Code/header.inc");
require_once("Code/contactlist.inc");
$Me = $_SESSION["Me"];
$rf = reviewForm();
$getaction = "";
if (isset($_REQUEST["get"]))
    $getaction = $_REQUEST["get"];
else if (isset($_REQUEST["getgo"]) && isset($_REQUEST["getaction"]))
    $getaction = $_REQUEST["getaction"];


// list type
$tOpt = array();
$tOpt["pc"] = "Program committee";
if ($Me->isPC)
    $tOpt["admin"] = "System administrators";
if ($Me->privChair || ($Me->isPC && $Conf->timePCViewAllReviews())) {
    $tOpt["re"] = "All reviewers";
    $tOpt["ext"] = "External reviewers";
    $tOpt["extsub"] = "External reviewers who completed a review";
}
if ($Me->isPC)
    $tOpt["req"] = "External reviewers you requested";
if ($Me->privChair || ($Me->isPC && $Conf->blindSubmission() == BLIND_NEVER))
    $tOpt["au"] = "Contact authors of submitted papers";
if ($Me->privChair
    || ($Me->isPC && $Conf->timeReviewerViewDecision()))
    $tOpt["auacc"] = "Contact authors of accepted papers";
if ($Me->privChair
    || ($Me->isPC && $Conf->blindSubmission() == BLIND_NEVER && $Conf->timeReviewerViewDecision()))
    $tOpt["aurej"] = "Contact authors of rejected papers";
if ($Me->privChair) {
    $tOpt["auuns"] = "Contact authors of non-submitted papers";
    $tOpt["all"] = "All users";
}
if (isset($_REQUEST["t"]) && !isset($tOpt[$_REQUEST["t"]])) {
    $Conf->errorMsg("You aren't allowed to list those accounts.");
    unset($_REQUEST["t"]);
}
if (!isset($_REQUEST["t"]))
    $_REQUEST["t"] = key($tOpt);


// paper selection and download actions
function paperselPredicate($papersel) {
    if (count($papersel) == 1)
	return "contactId=$papersel[0]";
    else
	return "contactId in (" . join(", ", $papersel) . ")";
}

if (isset($_REQUEST["pap"]) && is_string($_REQUEST["pap"]))
    $_REQUEST["pap"] = preg_split('/\s+/', $_REQUEST["pap"]);
if (isset($_REQUEST["pap"]) && is_array($_REQUEST["pap"])) {
    $papersel = array();
    foreach ($_REQUEST["pap"] as $p)
	if (($p = cvtint($p)) > 0)
	    $papersel[] = $p;
    if (count($papersel) == 0)
	unset($papersel);
}

if ($getaction == "nameemail" && isset($papersel)) {
    $result = $Conf->qe("select firstName, lastName, email from ContactInfo where " . paperselPredicate($papersel) . " order by lastName, firstName, email", "while selecting users");
    $text = "#name\temail\n";
    while ($row = edb_row($result))
	if ($row[0] && $row[1])
	    $text .= "$row[1], $row[0]\t$row[2]\n";
	else
	    $text .= "$row[1]$row[0]\t$row[2]\n";
    downloadText($text, $Opt['downloadPrefix'] . "accounts.txt", "accounts");
    exit;
}

if ($getaction == "address" && isset($papersel)) {
    $result = $Conf->qe("select firstName, lastName, email, voicePhoneNumber, faxPhoneNumber, ContactAddress.* from ContactInfo left join ContactAddress using (contactId) where " . paperselPredicate($papersel) . " order by lastName, firstName, email", "while selecting users");
    $text = "#name\temail\taddress line 1\taddress line 2\tcity\tstate/province/region\tzip/postal code\tcountry\tvoice phone\tfax\n";
    while ($row = edb_orow($result)) {
	if ($row->firstName && $row->lastName)
	    $text .= "$row->lastName, $row->firstName";
	else
	    $text .= "$row->lastName$row->firstName";
	foreach (array("email", "addressLine1", "addressLine2", "city",
		       "state", "zipCode", "country", "voicePhoneNumber",
		       "faxPhoneNumber") as $k)
	    $text .= "\t" . addcslashes($row->$k, "\\\r\n\t");
	$text .= "\n";
    }
    downloadText($text, $Opt['downloadPrefix'] . "addresses.txt", "addresses");
    exit;
}


// set scores to view
if (isset($_REQUEST["redisplay"])) {
    $_SESSION["foldpplaff"] = !defval($_REQUEST, "showaff", 0);
    $_SESSION["foldppltopics"] = !defval($_REQUEST, "showtop", 0);
    $_SESSION["pplscores"] = 0;
}
if (isset($_REQUEST["score"]) && is_array($_REQUEST["score"])) {
    $_SESSION["pplscores"] = 0;
    foreach ($_REQUEST["score"] as $s)
	$_SESSION["pplscores"] |= (1 << $s);
}
if (isset($_REQUEST["scoresort"])
    && ($_REQUEST["scoresort"] == "A" || $_REQUEST["scoresort"] == "V"
	|| $_REQUEST["scoresort"] == "D"))
    $_SESSION["pplscoresort"] = $_REQUEST["scoresort"];


$title = ($_REQUEST["t"] == "pc" ? "Program Committee" : "Users");
$Conf->header($title, "accounts", actionBar());


$pl = new ContactList(true);
$pl_text = $pl->text($_REQUEST["t"], $Me, "contacts$ConfSiteSuffix?t=" . $_REQUEST["t"]);


// form
echo "<div class='g'></div>\n";
if (count($tOpt) > 1) {
    echo "<table id='contactsform' class='tablinks1'>
<tr><td><div class='tlx'><div class='tld1'>";

    echo "<form method='get' action='contacts$ConfSiteSuffix' accept-charset='UTF-8'><div class='inform'>";
    if (isset($_REQUEST["sort"]))
	echo "<input type='hidden' name='sort' value=\"", htmlspecialchars($_REQUEST["sort"]), "\" />";
    echo tagg_select("t", $tOpt, $_REQUEST["t"], array("id" => "contactsform1_d")),
	" &nbsp;<input class='b' type='submit' value='Go' /></div></form>";

    echo "</div><div class='tld2'>";

    // Display options
    echo "<form method='get' action='contacts$ConfSiteSuffix' accept-charset='UTF-8'><div>\n";
    foreach (array("t", "sort") as $x)
	if (isset($_REQUEST[$x]))
	    echo "<input type='hidden' name='$x' value=\"", htmlspecialchars($_REQUEST[$x]), "\" />\n";

    echo "<table><tr><td><strong>Show:</strong> &nbsp;</td>
  <td class='pad'>";
    if ($pl->haveAffrow !== null) {
	echo "<input type='checkbox' name='showaff' value='1'";
	if ($pl->haveAffrow)
	    echo " checked='checked'";
	echo " onclick='fold(\"ppl\",!this.checked,2)' />&nbsp;Affiliations<br />\n";
    }
    if ($pl->haveTopics !== null) {
	echo "<input type='checkbox' name='showtop' value='1'";
	if ($pl->haveTopics)
	    echo " checked='checked'";
	echo " onclick='fold(\"ppl\",!this.checked,1)' />&nbsp;Topic interests<br />\n";
    }
    echo "</td>";
    if (isset($pl->scoreMax)) {
	echo "<td class='pad'>";
	$rf = reviewForm();
	$theScores = defval($_SESSION, "pplscores", 1);
	$revViewScore = $Me->viewReviewFieldsScore(null, true, $Conf);
	foreach ($rf->fieldOrder as $field)
	    if ($rf->authorView[$field] > $revViewScore
		&& isset($rf->options[$field])) {
		$i = array_search($field, $reviewScoreNames);
		echo "<input type='checkbox' name='score[]' value='$i' ";
		if ($theScores & (1 << $i))
		    echo "checked='checked' ";
		echo "/>&nbsp;" . htmlspecialchars($rf->shortName[$field]) . "<br />";
	    }
	echo "</td>";
    }
    echo "<td><input class='b' type='submit' name='redisplay' value='Redisplay' /></td></tr>\n";
    if (isset($pl->scoreMax)) {
	$ss = array();
	foreach (array("A", "V", "D") as $k) /* ghetto array_intersect_key */
	    if (isset($scoreSorts[$k]))
		$ss[$k] = $scoreSorts[$k];
	echo "<tr><td colspan='3'><div class='g'></div><b>Sort scores by:</b> &nbsp;",
	    tagg_select("scoresort", $ss, defval($_SESSION, "pplscoresort", "A")),
	    "</td></tr>";
    }
    echo "</table></div></form>";

    echo "</div></div></td></tr>\n";

    // Tab selectors
    echo "<tr><td class='tllx'><table><tr>
  <td><div class='tll1'><a onclick='return crpfocus(\"contactsform\", 1)' href=''>User selection</a></div></td>
  <td><div class='tll2'><a onclick='return crpfocus(\"contactsform\", 2)' href=''>Display options</a></div></td>
</tr></table></td></tr>
</table>\n\n";
}


if ($Me->privChair && $_REQUEST["t"] == "pc")
    $Conf->infoMsg("<p><a href='account$ConfSiteSuffix?new=1&amp;pc=1' class='button'>Add PC member</a></p><p>Select a PC member's name to edit their profile or remove them from the PC.</p>");
else if ($Me->privChair && $_REQUEST["t"] == "all")
    $Conf->infoMsg("<p><a href='account$ConfSiteSuffix?new=1' class='button'>Create account</a></p><p>Select an account name to edit that profile.  Select <img src='images/viewas.png' alt='[Act as]' /> to view the site as that user would see it.</p>");


if ($pl->anySelector) {
    echo "<form method='get' action='contacts$ConfSiteSuffix' accept-charset='UTF-8'><div>";
    foreach (array("t", "sort") as $x)
	if (isset($_REQUEST[$x]))
	    echo "<input type='hidden' name='$x' value=\"", htmlspecialchars($_REQUEST[$x]), "\" />\n";
}
echo $pl_text;
if ($pl->anySelector)
    echo "</div></form>";


$Conf->footer();
