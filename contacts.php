<?php
// contacts.php -- HotCRP people listing/editing page
// HotCRP is Copyright (c) 2006-2011 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

require_once("Code/header.inc");
require_once("Code/contactlist.inc");
$rf = reviewForm();
$getaction = "";
if (isset($_REQUEST["get"]))
    $getaction = $_REQUEST["get"];
else if (isset($_REQUEST["getgo"]) && isset($_REQUEST["getaction"]))
    $getaction = $_REQUEST["getaction"];


// list type
$tOpt = array();
$tOpt["pc"] = "Program committee";
if ($Me->isPC && count($pctags = pcTags())) {
    foreach ($pctags as $t)
	$tOpt["pc:$t"] = "PC members tagged &ldquo;$t&rdquo;";
}
if ($Me->isPC)
    $tOpt["admin"] = "System administrators";
if ($Me->privChair || ($Me->isPC && $Conf->timePCViewAllReviews())) {
    $tOpt["re"] = "All reviewers";
    $tOpt["ext"] = "External reviewers";
    $tOpt["extsub"] = "External reviewers who completed a review";
}
if ($Me->isPC)
    $tOpt["req"] = "External reviewers you requested";
if ($Me->privChair || ($Me->isPC && $Conf->subBlindNever()))
    $tOpt["au"] = "Contact authors of submitted papers";
if ($Me->privChair
    || ($Me->isPC && $Conf->timeReviewerViewDecision()))
    $tOpt["auacc"] = "Contact authors of accepted papers";
if ($Me->privChair
    || ($Me->isPC && $Conf->subBlindNever() && $Conf->timeReviewerViewDecision()))
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
    if (count($papersel) == 0)
	return "contactId=-1";
    else if (count($papersel) == 1)
	return "contactId=$papersel[0]";
    else
	return "contactId in (" . join(", ", $papersel) . ")";
}

if (isset($_REQUEST["pap"]) && is_string($_REQUEST["pap"]))
    $_REQUEST["pap"] = preg_split('/\s+/', $_REQUEST["pap"]);
if (isset($_REQUEST["pap"]) && is_array($_REQUEST["pap"])) {
    $allowed_papers = array();
    $pl = new ContactList($Me, true);
    // Ensure that we only select contacts we're allowed to see.
    if (($rows = $pl->rows($_REQUEST["t"]))) {
	foreach ($rows as $row)
	    $allowed_papers[$row->paperId] = true;
    }
    $papersel = array();
    foreach ($_REQUEST["pap"] as $p)
	if (($p = cvtint($p)) > 0 && isset($allowed_papers[$p]))
	    $papersel[] = $p;
    if (count($papersel) == 0)
	unset($papersel);
}

if ($getaction == "nameemail" && isset($papersel) && $Me->isPC) {
    $result = $Conf->qe("select firstName, lastName, email from ContactInfo where " . paperselPredicate($papersel) . " order by lastName, firstName, email", "while selecting users");
    $people = array();
    while ($row = edb_row($result))
	$people[] = array($row[0] && $row[1] ? "$row[1], $row[0]" : "$row[1]$row[0]", $row[2]);
    downloadCSV($people, array("name", "email"), "accounts", "accounts");
    exit;
}

if ($getaction == "nameaffemail" && isset($papersel) && $Me->isPC) {
    $result = $Conf->qe("select firstName, lastName, email, affiliation from ContactInfo where " . paperselPredicate($papersel) . " order by lastName, firstName, email", "while selecting users");
    $people = array();
    while ($row = edb_row($result))
	$people[] = array($row[0] && $row[1] ? "$row[1], $row[0]" : "$row[1]$row[0]", $row[3], $row[2]);
    downloadCSV($people, array("name", "affiliation", "email"), "accounts", "accounts");
    exit;
}

if ($getaction == "address" && isset($papersel) && $Me->isPC) {
    $result = $Conf->qe("select firstName, lastName, email, voicePhoneNumber, faxPhoneNumber, ContactAddress.* from ContactInfo left join ContactAddress using (contactId) where " . paperselPredicate($papersel) . " order by lastName, firstName, email", "while selecting users");
    $people = array();
    $phone = $fax = false;
    while ($row = edb_orow($result)) {
	$p = array(null, $row->email, $row->addressLine1, $row->addressLine2,
		   $row->city, $row->state, $row->zipCode, $row->country);
	if ($row->voicePhoneNumber || $row->faxPhoneNumber) {
	    $phone = true;
	    $p[] = $row->voicePhoneNumber;
	}
	if ($row->faxPhoneNumber) {
	    $fax = true;
	    $p[] = $row->faxPhoneNumber;
	}
	if ($row->firstName && $row->lastName)
	    $p[0] = "$row->lastName, $row->firstName";
	else
	    $p[0] = "$row->lastName$row->firstName";
	$people[] = $p;
    }
    $header = array("name", "email", "address line 1", "address line 2",
		    "city", "state/province/region", "zip/postal code", "country");
    if ($phone)
	$header[] = "voice phone";
    if ($fax)
	$header[] = "fax";
    downloadCSV($people, $header, "addresses", "addresses");
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


if ($_REQUEST["t"] == "pc")
    $title = "Program Committee";
else if (substr($_REQUEST["t"], 0, 3) == "pc:")
    $title = "&ldquo;" . substr($_REQUEST["t"], 3) . "&rdquo; Program Committee";
else
    $title = "Users";
$Conf->header($title, "accounts", actionBar());


$pl = new ContactList($Me, true);
$pl_text = $pl->text($_REQUEST["t"], hoturl("contacts", "t=" . $_REQUEST["t"]), $tOpt[$_REQUEST["t"]]);


// form
echo "<div class='g'></div>\n";
if (count($tOpt) > 1) {
    echo "<table id='contactsform' class='tablinks1'>
<tr><td><div class='tlx'><div class='tld1'>";

    echo "<form method='get' action='", hoturl("contacts"), "' accept-charset='UTF-8'><div class='inform'>";
    if (isset($_REQUEST["sort"]))
	echo "<input type='hidden' name='sort' value=\"", htmlspecialchars($_REQUEST["sort"]), "\" />";
    echo tagg_select("t", $tOpt, $_REQUEST["t"], array("id" => "contactsform1_d")),
	" &nbsp;<input class='b' type='submit' value='Go' /></div></form>";

    echo "</div><div class='tld2'>";

    // Display options
    echo "<form method='get' action='", hoturl("contacts"), "' accept-charset='UTF-8'><div>\n";
    foreach (array("t", "sort") as $x)
	if (isset($_REQUEST[$x]))
	    echo "<input type='hidden' name='$x' value=\"", htmlspecialchars($_REQUEST[$x]), "\" />\n";

    echo "<table><tr><td><strong>Show:</strong> &nbsp;</td>
  <td class='pad'>";
    if ($pl->haveAffrow !== null) {
	echo tagg_checkbox("showaff", 1, $pl->haveAffrow,
			   array("onchange" => "fold('ppl',!this.checked,2)")),
	    "&nbsp;", tagg_label("Affiliations"), "<br />\n";
    }
    if ($pl->haveTopics !== null) {
	echo tagg_checkbox("showtop", 1, $pl->haveTopics,
			   array("onchange" => "fold('ppl',!this.checked,1)")),
	    "&nbsp;", tagg_label("Topic interests"), "<br />\n";
    }
    echo "</td>";
    if (isset($pl->scoreMax)) {
	echo "<td class='pad'>";
	$rf = reviewForm();
	$theScores = defval($_SESSION, "pplscores", 1);
	$revViewScore = $Me->viewReviewFieldsScore(null, true);
	foreach ($rf->fieldOrder as $field)
	    if ($rf->authorView[$field] > $revViewScore
		&& isset($rf->options[$field])) {
		$i = array_search($field, $reviewScoreNames);
		echo tagg_checkbox("score[]", $i, $theScores & (1 << $i)),
		    "&nbsp;", tagg_label(htmlspecialchars($rf->shortName[$field])), "<br />";
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
  <td><div class='tll1'><a class='tla' onclick='return crpfocus(\"contactsform\", 1)' href=''>User selection</a></div></td>
  <td><div class='tll2'><a class='tla' onclick='return crpfocus(\"contactsform\", 2)' href=''>Display options</a></div></td>
</tr></table></td></tr>
</table>\n\n";
}


if ($Me->privChair && $_REQUEST["t"] == "pc")
    $Conf->infoMsg("<p><a href='" . hoturl("account", "new=1&amp;pc=1") . "' class='button'>Add PC member</a></p><p>Select a PC member's name to edit their profile or remove them from the PC.</p>");
else if ($Me->privChair && $_REQUEST["t"] == "all")
    $Conf->infoMsg("<p><a href='" . hoturl("account", "new=1") . "' class='button'>Create account</a></p><p>Select an account name to edit that profile.  Select <img src='images/viewas.png' alt='[Act as]' /> to view the site as that user would see it.</p>");


if ($pl->anySelector) {
    echo "<form method='get' action='", hoturl("contacts"), "' accept-charset='UTF-8'><div>";
    foreach (array("t", "sort") as $x)
	if (isset($_REQUEST[$x]))
	    echo "<input type='hidden' name='$x' value=\"", htmlspecialchars($_REQUEST[$x]), "\" />\n";
}
echo $pl_text;
if ($pl->anySelector)
    echo "</div></form>";


$Conf->footer();
