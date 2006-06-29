<?php 
include('../Code/confHeader.inc');
$Conf -> connect();
$_SESSION[Me] -> goIfInvalid("../");
?>

<html>
<?php  $Conf->header("Merge Account Information From Two Accounts") ?>
<body>

<?php 

if (IsSet($_REQUEST[Merge])) {
  if ($_REQUEST[firstEmail] != $_REQUEST[secondEmail]) {
    $Conf->errorMsg("The first and second email addresses don't match");
  } else if ($_REQUEST[firstEmail] == "") {
    $Conf->errorMsg("You specified an empty email string");
  } else if ($_REQUEST[passwd] == "") {
    $Conf->errorMsg("You need to specify a password");
  } else {
    //
    // Look up that account
    //
    $MiniMe = new Contact();
    $MiniMe -> lookupByEmail($_REQUEST[firstEmail], $Conf);

    if ($MiniMe->contactId == $_SESSION[Me] -> contactId) {
      $Conf->errorMsg("You can't merge yourself with yourself");
    } else if (! $MiniMe -> valid() || $MiniMe -> password != $_REQUEST[passwd]) {
      $Conf-> errorMsg("Either the other acccount doesn't "
		       . " exist or you specified the wrong password");
    } else {
      $Conf->infoMsg("You match -- updating database!");
      
      $message = "Your account at the $Conf->shortName conference site "
	. " has been merged with the account of \n"
	. $_SESSION[Me]->fullname() . " ( " . $_SESSION[Me] -> email . " )\n";
      $message .= "If you suspect something fishy, contact the "
	. "conference contact (" . $Conf -> contactEmail . " )\n";
      
      mail($MiniMe->email,
	   "Account information for $conf->shortName",
	   $message,
	   "From: $conf->emailFrom");
      //
      // Now, scan through all the tables that possibly
      // specify a contactID and change it from their 2nd
      // contactID to their first contactId
      //
      $oldid = $MiniMe->contactId;
      $newid = $_SESSION[Me]->contactId;
      //
      // Paper
      //
      $Conf->qe("UPDATE Paper SET contactId=$newid  WHERE "
		. " contactId=$oldid");
      $Conf->qe("UPDATE PaperAuthor SET authorId=$newid  WHERE "
		. " authorId=$oldid");
      $Conf->qe("UPDATE PaperConflict SET authorId=$newid  WHERE "
		. " authorId=$oldid");
      $Conf->qe("UPDATE PCMember SET contactId=$newid  WHERE "
		. " contactId=$oldid");
      $Conf->qe("UPDATE Chair SET contactId=$newid  WHERE "
		. " contactId=$oldid");
      $Conf->qe("UPDATE TopicInterest SET contactId=$newid  WHERE "
		. " contactId=$oldid");
      $Conf->qe("UPDATE ReviewRequest SET asked=$newid  WHERE "
		. " asked=$oldid");
      $Conf->qe("UPDATE ReviewRequest SET requestedBy=$newid  WHERE "
		. " requestedBy=$oldid");
      $Conf->qe("UPDATE PrimaryReviewer SET reviewer=$newid  WHERE "
		. " reviewer=$oldid");
      $Conf->qe("UPDATE SecondaryReviewer SET reviewer=$newid  WHERE "
		. " reviewer=$oldid");
      $Conf->qe("UPDATE PaperReview SET reviewer=$newid  WHERE "
		. " reviewer=$oldid");
      
      //
      // Remove the contact record
      //
      $Conf->qe("DELETE From ContactInfo WHERE contactId=$oldid");

      $Conf->log("Merged account $oldid into " . $_SESSION[Me]->contactId, $_SESSION[Me]);

    }
  }
}

?>


<?php 
$Conf->infoMsg(
"You may have multiple accounts registered with the "
.  $Conf->shortName . " conference, usually because "
. "multiple people asked you to review a paper using "
. "different email addresses. "
. "This may make it "
. "more difficult to keep track of your different papers. "
. "If you have been informed of multiple accounts, you "
. "can enter the email address and the password "
. "of the secondary account here and press the \"MERGE\" "
. "button. This will then merge all the information from "
. "the account you specify into this account (papers, reviews, etc). "
. "<br>"
. "If you simply want to change your email address, you can update "
. "that in the \"update contact information\" page."
);
?>

<form method="POST" action="<?php  echo $_SERVER[PHP_SELF] ?>">
<div align="center">
<table border="1" width="75%" bgcolor="<?php echo $Conf->bgOne?>">
<tr>
<tr>
<td width="35%">Email To Merge</td>
<td width="65%"><input type="text" name="firstEmail" size="44"
value="<?php  echo $_REQUEST[firstEmail]?>" ></td>
</tr>
<tr>
<td width="35%">Email To Merge Again</td>
<td width="65%"><input type="text" name="secondEmail" size="44"
value="<?php  echo $_REQUEST[secondEmail]?>" ></td>
</tr>
<tr>
<td width="35%">Password of that account</td>
<td width="65%"><input type="password" name="passwd" size="44"
value="<?php echo $_REQUEST[password]?>" ></td>
</tr>
<td colspan=2 align=center>
<input type="submit" value="Merge" name="Merge">
</td>
</table>
</div>
</form>

<?php  $Conf->footer() ?>
</body>
</html>

