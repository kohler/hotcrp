<?php 
include('Code/header.inc');
$Me = $_SESSION["Me"];
$Me->goIfInvalid();
$MergeError = "";

function crpmergeone($table, $field, $oldid, $newid) {
    global $Conf;
    $result = $Conf->q("update $table set $field=$newid where $field=$oldid");
    if (MDB2::isError($result))
	$MergeError .= $Conf->dbErrorText($result, "", 0);
}

function crpmergeonex($table, $field, $oldid, $newid) {
    global $Conf;
    $result = $Conf->q("update $table set $field=$newid where $field=$oldid");
    if (MDB2::isError($result)) {
	$result = $Conf->q("delete from $table where $field=$oldid");
	if (MDB2::isError($result))
	    $MergeError .= $Conf->dbErrorText($result, "", 0);
    }
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
	    $Me->go("index.php");
	} else {
	    // Do they prefer the account they named?
	    if (defval($_REQUEST['prefer'])) {
		$mm = $Me;
		$_REQUEST["Me"] = $Me = $MiniMe;
		$MiniMe = $mm;
	    }
	    
	    $message = "\
Your account at the " . $Conf->shortName . "submissions site has been\n\
merged with the account of " . $Me->fullnameAndEmail() . ".\n\
If you suspect something fishy, contact the site administrator at\n\
" . $Conf->contactEmail . ".\n";
	    if ($Conf->allowEmailTo($MiniMe->email))
		mail($MiniMe->email, "[$Conf->shortName] Account Information",
		     $message, "From: $Conf->emailFrom");
	    
	    // Now, scan through all the tables that possibly
	    // specify a contactID and change it from their 2nd
	    // contactID to their first contactId
	    $oldid = $MiniMe->contactId;
	    $newid = $Me->contactId;
	    
	    crpmergeone("Paper", "contactId", $oldid, $newid);
	    crpmergeone("PaperConflict", "contactId", $oldid, $newid);
	    crpmergeonex("PCMember", "contactId", $oldid, $newid);
	    crpmergeonex("ChairAssistant", "contactId", $oldid, $newid);
	    crpmergeonex("Chair", "contactId", $oldid, $newid);
	    crpmergeone("TopicInterest", "contactId", $oldid, $newid);
	    crpmergeone("PaperReview", "contactId", $oldid, $newid);
	    crpmergeone("PaperReview", "requestedBy", $oldid, $newid);
	    crpmergeone("PaperReviewArchive", "contactId", $oldid, $newid);
	    crpmergeone("PaperReviewArchive", "requestedBy", $oldid, $newid);
	    crpmergeone("PaperReviewRefused", "contactId", $oldid, $newid);
	    crpmergeone("PaperReviewRefused", "requestedBy", $oldid, $newid);

	    // XXX ensure uniqueness in PaperConflict, PaperReview

	    // Update PC settings if we need to
	    if ($MiniMe->isPC) {
		$t = time();
		$Conf->qe("insert into Settings (name, value) values ('pc', $t) on duplicate key update value=$t");
	    }
	    
	    // Remove the old contact record
	    if ($MergeError == "") {
		$result = $Conf->q("delete from ContactInfo where contactId=$oldid");
		if (MDB2::isError($result))
		    $MergeError .= $Conf->dbErrorText($result, "", 0);
	    }

	    if ($MergeError == "") {
		$Conf->confirmMsg("Account " . htmlspecialchars($MiniMe->email) . " successfully merged.");
		$Conf->log("Merged account $oldid into " . $Me->contactId, $Me);
		$Me->go("index.php");
	    } else {
		$Conf->log("Merged account $oldid into " . $Me->contactId . " with errors", $Me);
		$MergeError .= $Conf->dbErrorText(null);
	    }
	}
    }
}

$Conf->header("Merge Account Information");
?>

<?php
if ($MergeError)
    $Conf->errorMsg($MergeError);
else
    $Conf->infoMsg(
"You may have multiple accounts registered with the "
.  $Conf->shortName . " conference, usually because "
. "multiple people asked you to review a paper using "
. "different email addresses. "
. "This may make it "
. "more difficult to keep track of your different papers. "
. "If you have been informed of multiple accounts, "
. "enter the email address and the password "
. "of the secondary account. This will merge all the information from "
. "that account into this one. "
);
?>

<form class='mergeAccounts' method='post' action='mergeaccounts.php'>
<table class='form'>

<tr>
  <td class='caption'>Email:</td>
  <td class='entry'><input type='text' name='email' size='50'
    <?php if (isset($_REQUEST["email"])) echo "value=\"", htmlspecialchars($_REQUEST["email"]), "\" "; ?>
  /></td>
</tr>

<tr>
  <td class='caption'>Password:</td>
  <td class='entry'><input type='password' name='password' size='50' /></td>
</tr>

<tr>
  <td class='caption'></td>
  <td class='entry'><input type='radio' name='prefer' value='0' checked='checked' />&nbsp;Keep my current account (<?php echo htmlspecialchars($Me->email) ?>)<br />
    <input type='radio' name='prefer' value='1' />&nbsp;Keep the account named above, delete my current account</td>
</tr>

<tr><td></td><td><input class='button_default' type='submit' value='Merge Account' name='merge' /></td></tr>
</table>
</form>

</div>
<?php $Conf->footer() ?>
</body>
</html>

