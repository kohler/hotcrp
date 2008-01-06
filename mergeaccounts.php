<?php 
// mergeaccounts.php -- HotCRP account merging page
// HotCRP is Copyright (c) 2006-2007 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

include('Code/header.inc');
$Me = $_SESSION["Me"];
$Me->goIfInvalid();
$MergeError = "";

function crpmergeone($table, $field, $oldid, $newid) {
    global $Conf, $MergeError;
    if (!$Conf->q("update $table set $field=$newid where $field=$oldid"))
	$MergeError .= $Conf->dbErrorText(true, "", 0);
}

function crpmergeonex($table, $field, $oldid, $newid) {
    global $Conf, $MergeError;
    if (!$Conf->q("update $table set $field=$newid where $field=$oldid")
	&& !$Conf->q("delete from $table where $field=$oldid"))
	$MergeError .= $Conf->dbErrorText(true, "", 0);
}

if (isset($_REQUEST["merge"])) {
    if (!$_REQUEST["email"])
	$MergeError = "Enter an email address to merge.";
    else if (!$_REQUEST["password"])
	$MergeError = "Enter the password of the account to merge.";
    else {
	$MiniMe = new Contact();
	$MiniMe->lookupByEmail($_REQUEST["email"], $Conf);

	if (!$MiniMe->valid())
	    $MergeError = "No account for " . htmlspecialchars($_REQUEST["email"]) . " exists.  Did you enter the correct email address?";
	else if ($MiniMe->password != $_REQUEST["password"])
	    $MergeError = "That password is incorrect.";
	else if ($MiniMe->contactId == $Me->contactId) {
	    $Conf->confirmMsg("Accounts successfully merged.");
	    $Me->go("index$ConfSiteSuffix");
	} else {
	    // Do they prefer the account they named?
	    if (defval($_REQUEST, 'prefer')) {
		$mm = $Me;
		$_REQUEST["Me"] = $Me = $MiniMe;
		$MiniMe = $mm;
	    }
	    
	    require_once("Code/mailtemplate.inc");
	    Mailer::send("@mergeaccount", null, $MiniMe, $Me, array("headers" => "Cc: " . contactEmailTo($Me)));
	    
	    // Now, scan through all the tables that possibly
	    // specify a contactID and change it from their 2nd
	    // contactID to their first contactId
	    $oldid = $MiniMe->contactId;
	    $newid = $Me->contactId;
	    
	    $while = "while merging conflicts";
	    $Conf->q("lock tables Paper write, ContactInfo write, PaperConflict write, PCMember write, ChairAssistant write, Chair write, ActionLog write, TopicInterest write, PaperComment write, PaperReview write, PaperReviewArchive write, PaperReviewPreference write, PaperReviewRefused write", $while);
	    
	    crpmergeone("Paper", "leadContactId", $oldid, $newid);
	    crpmergeone("Paper", "shepherdContactId", $oldid, $newid);

	    // paper authorship
	    $result = $Conf->qe("select paperId, authorInformation from Paper where authorInformation like '%	" . sqlq_for_like($MiniMe->email) . "	%'", $while);
	    $qs = array();
	    while (($row = edb_row($result))) {
		$row[1] = str_ireplace("\t" . $MiniMe->email . "\t", "\t" . $Me->email . "\t", $row[1]);
		$qs[] = "update Paper set authorInformation='" . sqlq($row[1]) . "' where paperId=$row[0]";
	    }
	    foreach ($qs as $q)
		$Conf->qe($q, $while);
	    
	    // ensure uniqueness in PaperConflict
	    $result = $Conf->qe("select paperId, conflictType from PaperConflict where contactId=$oldid", $while);
	    $values = "";
	    while (($row = edb_row($result)))
		$values .= ", ($row[0], $newid, $row[1])";
	    if ($values)
		$Conf->qe("insert into PaperConflict (paperId, contactId, conflictType) values " . substr($values, 2) . " on duplicate key update conflictType=greatest(conflictType, values(conflictType))", $while);
	    $Conf->qe("delete from PaperConflict where contactId=$oldid", $while);
	    
	    crpmergeonex("PCMember", "contactId", $oldid, $newid);
	    crpmergeonex("ChairAssistant", "contactId", $oldid, $newid);
	    crpmergeonex("Chair", "contactId", $oldid, $newid);
	    if ($Conf->setting("allowPaperOption") >= 6) {
		if (($MiniMe->roles | $Me->roles) != $Me->roles) {
		    $Me->roles |= $MiniMe->roles;
		    $Conf->qe("update ContactInfo set roles=$Me->roles where contactId=$Me->contactId", $while);
		}
	    }
	    
	    crpmergeone("ActionLog", "contactId", $oldid, $newid);
	    crpmergeone("TopicInterest", "contactId", $oldid, $newid);
	    crpmergeone("PaperComment", "contactId", $oldid, $newid);
	    crpmergeone("PaperReview", "contactId", $oldid, $newid);
	    crpmergeone("PaperReview", "requestedBy", $oldid, $newid);
	    crpmergeone("PaperReviewArchive", "contactId", $oldid, $newid);
	    crpmergeone("PaperReviewArchive", "requestedBy", $oldid, $newid);
	    crpmergeone("PaperReviewPreference", "contactId", $oldid, $newid);
	    crpmergeone("PaperReviewRefused", "contactId", $oldid, $newid);
	    crpmergeone("PaperReviewRefused", "requestedBy", $oldid, $newid);

	    // XXX ensure uniqueness in PaperReview
	    
	    // Remove the old contact record
	    if ($MergeError == "") {
		if (!$Conf->q("delete from ContactInfo where contactId=$oldid"))
		    $MergeError .= $Conf->dbErrorText($result, "", 0);
	    }

	    $Conf->qe("unlock tables", $while);

	    // Update PC settings if we need to
	    if ($MiniMe->isPC) {
		$t = time();
		$Conf->qe("insert into Settings (name, value) values ('pc', $t) on duplicate key update value=$t");
	    }

	    if ($MergeError == "") {
		$Conf->confirmMsg("Account " . htmlspecialchars($MiniMe->email) . " successfully merged.");
		$Conf->log("Merged account $MiniMe->email", $Me);
		$Me->go("index$ConfSiteSuffix");
	    } else {
		$Conf->log("Merged account $MiniMe->email with errors", $Me);
		$MergeError .= $Conf->dbErrorText(null);
	    }
	}
    }
}

$Conf->header("Merge Accounts");


if ($MergeError)
    $Conf->errorMsg($MergeError);
else
    $Conf->infoMsg(
"You may have multiple accounts registered with the "
.  $Conf->shortName . " conference, usually because "
. "multiple people asked you to review a paper using "
. "different email addresses. "
. "This can make it "
. "harder to keep track of your papers. "
. "If you have been informed of multiple accounts, "
. "enter the email address and the password "
. "of the secondary account. This will merge all the information from "
. "that account into this one. "
);

echo "<form method='post' action=\"mergeaccounts$ConfSiteSuffix\">\n";
?>

<table class='form'>

<tr>
  <td class='caption'>Email:</td>
  <td class='entry'><input type='text' class='textlite' name='email' size='50'
    <?php if (isset($_REQUEST["email"])) echo "value=\"", htmlspecialchars($_REQUEST["email"]), "\" "; ?>
  /></td>
</tr>

<tr>
  <td class='caption'>Password:</td>
  <td class='entry'><input type='password' class='textlite' name='password' size='50' /></td>
</tr>

<tr>
  <td class='caption'></td>
  <td class='entry'><input type='radio' name='prefer' value='0' checked='checked' />&nbsp;Keep my current account (<?php echo htmlspecialchars($Me->email) ?>)<br />
    <input type='radio' name='prefer' value='1' />&nbsp;Keep the account named above and delete my current account</td>
</tr>

<tr><td class='caption'></td><td class='entry'><input class='button' type='submit' value='Merge Account' name='merge' /></td></tr>
<tr class='last'><td class='caption'></td></tr>
</table>
</form>


<?php $Conf->footer();
