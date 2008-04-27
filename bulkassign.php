<?php
// bulkassign.php -- HotCRP bulk paper assignment page
// HotCRP is Copyright (c) 2006-2008 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

require_once('Code/header.inc');
require_once('Code/paperlist.inc');
require_once('Code/search.inc');
require_once("Code/mailtemplate.inc");
$Me = $_SESSION["Me"];
$Me->goIfInvalid();
$Me->goIfNotPrivChair("index$ConfSiteSuffix");
$nullMailer = new Mailer(null, null, $Me);
$nullMailer->width = 10000000;
$Error = array();


// parse my ass
function tfError(&$tf, $lineno, $text) {
    if ($tf['filename'])
	$e = htmlspecialchars($tf['filename']) . ":" . $lineno;
    else
	$e = "line " . $lineno;
    $tf['err'][$lineno] = "<span class='lineno'>" . $e . ":</span> " . $text;
}

function parseBulkFile($text, $filename, $type) {
    global $Conf, $Me, $nullMailer, $Error;
    $text = cleannl($text);
    $lineno = 0;
    $tf = array('err' => array(), 'filename' => $filename);
    $pcm = pcMembers();
    $ass = array();
    $lnameemail = array();
    $doemail = defval($_REQUEST, "email");
    $mailtemplate = $nullMailer->expandTemplate("requestreview", true, false);
    if (isset($_REQUEST["email_requestreview"]))
	$mailtemplate[1] = $_REQUEST["email_requestreview"];

    // check review round
    if (($rev_roundtag = defval($_REQUEST, "rev_roundtag")) == "(None)")
	$rev_roundtag = "";
    if ($rev_roundtag && !preg_match('/^[a-zA-Z0-9]+$/', $rev_roundtag)) {
	$Error["rev_roundtag"] = true;
	return $Conf->errorMsg("The review round must contain only letters and numbers.");
    }
    
    // XXX lock tables
    
    while ($text != "") {
	$pos = strpos($text, "\n");
	$line = ($pos === FALSE ? $text : substr($text, 0, $pos + 1));
	$lineno++;
	$text = substr($text, strlen($line));

	// skip blank lines
	if (trim($line) == "" || $line[0] == "#" || $line[0] == "!")
	    continue;

	// parse a bunch of formats
	if (preg_match('/^(\d+)\s+([^\t]+)\t([^\t]+)/', $line, $m)) {
	    $paperId = $m[1];
	    $email = trim($m[2]);
	    $name = trim($m[3]);
	} else if (preg_match('/^(\d+)\s+([^\t]*?)\s*<(\S+)>\s*/', $line, $m)) {
	    $paperId = $m[1];
	    $email = $m[3];
	    $name = $m[2];
	} else if (preg_match('/^(\d+)\s+([^\t]+)$/', $line, $m)) {
	    $paperId = $m[1];
	    $email = trim($m[2]);
	    $name = "";
	} else {
	    tfError($tf, $lineno, "bad format");
	    continue;
	}

	// check emails
	if ($name && $email
	    && strpos($name, "@") !== false && strpos($email, "@") === false) {
	    $tmp = $name;
	    $name = $email;
	    $email = $tmp;
	}

	$nameemail = ($name && $email ? "$name <$email>" : "$name$email");
	
	// PC members
	if ($type != REVIEW_EXTERNAL) {
	    list($firstName, $lastName) = splitName(simplifyWhitespace($name));
	    if (!$lastName)
		$lastName = $email;
	    if (!$firstName)
		$firstName = $lastName;
	    $cid = -2;
	    $matchprio = 1000;
	    foreach ($pcm as $pcid => $pc) {
		$x = array($pc->email, $email,
			   $pc->lastName, $lastName,
			   $pc->firstName, $firstName);
		for ($i = 0; $i < count($x) && $i <= $matchprio; $i += 2)
		    if ($x[$i+1]
			&& (strncasecmp($x[$i], $x[$i+1], strlen($x[$i+1])) == 0
			    || ($i >= 2 && strlen($x[$i+1]) < strlen($x[$i])
				&& stripos($x[$i], $x[$i+1]) !== false))) {
			$prio = (strcasecmp($x[$i], $x[$i+1]) == 0 ? $i : $i+1);
			if ($matchprio >= $prio) {
			    $cid = ($matchprio > $prio ? $pcid : -1);
			    $matchprio = $prio;
			}
		    }
	    }
	    // assign
	    if ($cid <= 0) {
		if ($cid == -2)
		    tfError($tf, $lineno, htmlspecialchars($nameemail) . " matches no PC member");
		else
		    tfError($tf, $lineno, htmlspecialchars($nameemail) . " matches more than one PC member; give a full email address to disambiguate");
		continue;
	    }
	} else {
	    // external reviewers
	    $_REQUEST["name"] = $name;
	    if (($cid = $Conf->getContactId($email, true, false)) <= 0) {
		tfError($tf, $lineno, htmlspecialchars($email) . " not a valid email address");
		continue;
	    }
	}

	// mark assignment
	if (!isset($ass[$paperId]))
	    $ass[$paperId] = array();
	$ass[$paperId][$cid] = $lineno;
	$lnameemail[$lineno] = $nameemail;
    }

    
    // examine assignments for duplicates and bugs
    if (count($ass) > 0) {
	$paperIds = join(", ", array_keys($ass));
	$validPaperIds = array();
	$unsubmittedPaperIds = array();
	$result = $Conf->qe("select paperId, timeSubmitted, timeWithdrawn from Paper where paperId in ($paperIds)", "while doing bulk assignments");
	while (($row = edb_row($result)))
	    if ($row[1] > 0 && $row[2] <= 0)
		$validPaperIds[$row[0]] = true;
	    else
		$unsubmittedPaperIds[$row[0]] = true;

	$invalidPaperIds = array();
	foreach ($ass as $paperId => $apaper)
	    if (!isset($validPaperIds[$paperId]))
		$invalidPaperIds[] = $paperId;
	foreach ($invalidPaperIds as $paperId) {
	    $error = (isset($unsubmittedPaperIds[$paperId]) ? "paper #$paperId has been withdrawn, or was never submitted" : "no such paper #$paperId");
	    foreach ($ass[$paperId] as $cid => $lineno)
		tfError($tf, $lineno, $error);
	    unset($ass[$paperId]);
	}

	$result = $Conf->qe("select paperId, contactId from PaperReview where paperId in ($paperIds)", "while doing bulk assignments");
	while (($row = edb_row($result)))
	    if (isset($ass[$row[0]][$row[1]])) {
		$lineno = $ass[$row[0]][$row[1]];
		tfError($tf, $lineno, htmlspecialchars($lnameemail[$lineno]) . " already assigned to paper #$row[0]");
		unset($ass[$row[0]][$row[1]]);
	    }

	$result = $Conf->qe("select paperId, contactId from PaperConflict where paperId in ($paperIds)", "while doing bulk assignments");
	while (($row = edb_row($result)))
	    if (isset($ass[$row[0]][$row[1]])) {
		$lineno = $ass[$row[0]][$row[1]];
		tfError($tf, $lineno, htmlspecialchars($lnameemail[$lineno]) . " has a conflict with paper #$row[0]");
		unset($ass[$row[0]][$row[1]]);
	    }
    }


    // set review round
    if ($rev_roundtag) {
	$Conf->settings["rev_roundtag"] = 1;
	$Conf->settingTexts["rev_roundtag"] = $rev_roundtag;
    } else
	unset($Conf->settings["rev_roundtag"]);

    // perform assignment
    $nass = 0;
    foreach ($ass as $paperId => $apaper) {
	$prow = null;
	foreach ($apaper as $cid => $lineno) {
	    $t = $type;
	    if ($type == REVIEW_EXTERNAL && isset($pcm[$cid]))
		$t = REVIEW_PC;
	    $Me->assignPaper($paperId, null, $cid, $t, $Conf);
	    if ($type == REVIEW_EXTERNAL && $doemail) {
		$Them = new Contact();
		$Them->lookupById($cid, $Conf);
		if (!$prow)
		    $prow = $Conf->paperRow($paperId);
		Mailer::send($mailtemplate, $prow, $Them, $Me);
	    }
	    $nass++;
	}
    }


    // possible complaints
    if (count($tf["err"]) > 0) {
	ksort($tf["err"]);
	$errorMsg = "were errors while parsing the uploaded assignment file. <div class='parseerr'><p>" . join("</p>\n<p>", $tf["err"]) . "</p></div>";
    }
    if ($nass > 0 && count($tf["err"]) > 0)
	$Conf->confirmMsg("Made " . plural($nass, "assignment") . ".<br />However, there $errorMsg");
    else if ($nass > 0)
	$Conf->confirmMsg("Made " . plural($nass, "assignment") . ".");
    else if (count($tf["err"]) > 0)
	$Conf->errorMsg("There $errorMsg");
    else
	$Conf->warnMsg("Nothing to do.");
}


// upload review form action
if (isset($_REQUEST['upload']) && fileUploaded($_FILES['uploadedFile'], $Conf)
    && isset($_REQUEST["t"]) && ($_REQUEST["t"] == REVIEW_PRIMARY
				 || $_REQUEST["t"] == REVIEW_SECONDARY
				 || $_REQUEST["t"] == REVIEW_EXTERNAL)) {
    if (($text = file_get_contents($_FILES['uploadedFile']['tmp_name'])) === false)
	$Conf->errorMsg("Internal error: cannot read file.");
    else
	parseBulkFile($text, $_FILES['uploadedFile']['name'], $_REQUEST['t']);
} else if (isset($_REQUEST['upload']))
    $Conf->errorMsg("Select an assignments file to upload.");


$abar = "<div class='vbar'><table class='vbar'><tr><td><table><tr>\n";
$abar .= actionTab("Automatic", "autoassign$ConfSiteSuffix", false);
$abar .= actionTab("Manual", "manualassign$ConfSiteSuffix", false);
$abar .= actionTab("Offline", "bulkassign$ConfSiteSuffix", true);
$abar .= "</tr></table></td>\n<td class='spanner'></td>\n<td class='gopaper nowrap'>" . goPaperForm() . "</td></tr></table></div>\n";


$Conf->header("Review Assignments", "bulkassign", $abar);


// Help list
echo "<div class='helpside'><div class='helpinside'>
Assignment methods:
<ul><li><a href='${ConfSiteBase}autoassign$ConfSiteSuffix'>Automatic</a></li>
 <li><a href='${ConfSiteBase}manualassign$ConfSiteSuffix'>Manual by PC member</a></li>
 <li><a href='${ConfSiteBase}assign$ConfSiteSuffix'>Manual by paper</a></li>
 <li><a href='${ConfSiteBase}bulkassign$ConfSiteSuffix' class='q'><strong>Offline (bulk upload)</strong></a></li>
</ul>
<hr />
Types of PC assignment:
<dl><dt><img src='${ConfSiteBase}images/ass", REVIEW_PRIMARY, ".png' alt='Primary' /> Primary</dt><dd>Expected to review the paper themselves</dd>
  <dt><img src='${ConfSiteBase}images/ass", REVIEW_SECONDARY, ".png' alt='Secondary' /> Secondary</dt><dd>May delegate to external reviewers</dd></dl>
</div></div>";


echo "<table>
  <tr class='id'><td class='caption'></td><td class='entry'></td></tr>
  <tr><td class='caption initial'>Upload</td><td class='entry'>
<form action='bulkassign$ConfSiteSuffix?upload=1' method='post' enctype='multipart/form-data' accept-charset='UTF-8'><div class=inform'>
Assign &nbsp;",
    tagg_select("t", array(REVIEW_PRIMARY => "primary",
			   REVIEW_SECONDARY => "secondary",
			   REVIEW_EXTERNAL => "external"), REVIEW_PRIMARY,
		array("id" => "tsel", "onchange" => "fold(\"email\",this.value!=" . REVIEW_EXTERNAL . ")")),
    "&nbsp; reviews from file:&nbsp;
<input type='file' name='uploadedFile' accept='text/plain' size='30' />

<hr class='g' />\n\n";

if (!isset($_REQUEST["rev_roundtag"]))
    $rev_roundtag = $Conf->settingText("rev_roundtag");
else if (($rev_roundtag = $_REQUEST["rev_roundtag"]) == "(None)")
    $rev_roundtag = "";
$t = $nullMailer->expandTemplate("requestreview", true, false);
echo "<div id='foldemail' class='foldo'><table class='extension'>
<tr><td><input type='checkbox' name='email' value='1' checked='checked' />&nbsp;</td>
<td>Send email to external reviewers:</td></tr>
<tr><td></td><td><textarea class='tt' name='email_requestreview' cols='80' rows='20'>", htmlspecialchars($t[1]), "</textarea></td></tr></table>
<div class='ellipsis";
if (isset($Error["rev_roundtag"]))
    echo " error";
echo "'>Review round: &nbsp;",
    "<input class='textlite' type='text' size='15' name='rev_roundtag' value=\"",
    htmlspecialchars($rev_roundtag ? $rev_roundtag : "(None)"),
    "\" onfocus=\"tempText(this, '(None)', 1)\" onblur=\"tempText(this, '(None)', 0)\" />",
    " &nbsp;<a class='hint' href='${ConfSiteBase}help$ConfSiteSuffix?t=revround'>What is this?</a></div></div>

<hr class='g' />

<input class='button' type='submit' value='Go' />

</div></form>

<hr class='g' />

<p>Use this page to upload many reviewer assignments at once.  Create a
tab-separated text file with one line per assignment.  The first column must
be a paper number, and the second and third columns should contain the
proposed reviewer's name and email address.  For example:</p>

<pre class='entryexample'>
1	Alice Man	man@alice.org
10	noname@anonymous.org
11	Manny Ramirez &lt;slugger@manny.com&gt;
</pre>

<p>Primary and secondary reviewers must be PC members, so for those reviewer
types you don't need a full name or email address, just some substring that
identifies the PC member uniquely.  For example:</p>

<pre class='entryexample'>
24	sylvia
1	Frank
100	feldmann
</pre>
</td></tr>
  <tr class='last'><td class='caption'></td><td class='entry'></td></tr>
</table>\n";


$Conf->footerStuff .= "<script type='text/javascript'>fold('email',e('tsel').value!=" . REVIEW_EXTERNAL . ");</script>";

$Conf->footer();
