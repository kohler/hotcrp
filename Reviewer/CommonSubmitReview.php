<?php 
include('../Code/confConfigReview.inc');
include('../Code/confJavaScript.inc');
?>

<html>

<?php  $Conf->header("Submit or Update A Review For Paper #$_REQUEST[paperId]") ?>

<body>
<?php 

if (stristr($HTTP_USER_AGENT, "Mozilla/4.7")
    && ! $_SESSION["ToldYouAboutNetscape47"] ) {
  $Conf->popupWarning(
		   "You appear to be using netscape 4.7."
		   . "With this browser, resizing the window discards "
		   . "existing form contents. Make certain you click on "
		   . "the \"save review\" button before resizing.");

  $_SESSION["ToldYouAboutNetscape47"] = 1;
}


if (!$_SESSION["Me"]->canReview($_REQUEST["paperId"], null, $Conf) ) {
  $Conf->errorMsg("You aren't supposed to be able to review paper #$_REQUEST[paperId]. "
		  . "If you think this is in error, contact the program chair. ");
  exit();
} 


$Review = ReviewFactory($Conf, $_SESSION["Me"]->contactId, $_REQUEST[paperId]);

if (IsSet($_REQUEST[submit])) {
  //
  // Check form & modify finalized..
  //
  if ($Review->finalized()) {
    $Conf->errorMsg("This review is already finalized, you can't submit it again");
  } else {
    $Review-> checkForm($Conf);
    $Review->load($_POST);
    $Review->saveReview($Conf, $_SESSION["Me"]->contactId);

    $Conf->log("Save review for $_REQUEST[paperId]", $_SESSION["Me"]);

    //
    // Read the review again, just to be certain
    // they see the proper values
    //

    $Review = ReviewFactory($Conf, $_SESSION["Me"]->contactId, $_REQUEST[paperId]);

    //
    // Check if this review has been finalized during
    // the author rebuttal period. If so, send mail
    // to the author
    //
    if ($Review->finalized() ) {
      $Conf->infoMsg("Your review has been finalized!");
      $Conf->log("Review for paper #$_REQUEST[paperId] finalized", $_SESSION["Me"]);

      if ( $Conf->timeEmailChairAboutReview() ) {
	$email = $Conf->contactEmail;
	$title = $Review->paperFields['title'];

	$message = "This is just to let you know that another review \n"
	  . "for paper #$_REQUEST[paperId] - $title, \n"
	  . "has been finalized.";

	if ($Conf->allowEmailTo($email))
	    mail($email,
		 "[$Conf->shortName] Paper #" . $_REQUEST['paperId'] . ": Additional review",
		 $message,
		 "From: $Conf->emailFrom"
		 );
      }

      if ( $Conf->validTimeFor("authorViewReviews",0) ) {
	$email = $Review->authorFields['email'];
	$title = $Review->paperFields['title'];

	$message = "This is just to let you know that another review \n"
	  . "for your paper #$_REQUEST[paperId] - $title, \n"
	  . "has been finalized. You may wish to augment or amend \n"
	  . "your response.";

	if ($Conf->allowEmailTo($email))
	    mail($email,
		 "[$Conf->shortName] Paper #" . $_REQUEST['paperId'] . ": Additional review",
		 $message,
		 "From: $Conf->emailFrom"
		 );
      } 
    } else {
      $Conf->infoMsg("Your review has been saved!");
    }
  }
}

if (IsSet($_REQUEST[emailReview])) {
  //
  // Empty
  //
    if ($Conf->allowEmailTo($_SESSION["Me"]->email))
	mail($_SESSION["Me"]->email,
	     "[$Conf->shortName] Paper #" . $_REQUEST['paperId'] . ": Your review",
	     $Review -> getAnonReviewHeaderASCII($Conf)
	     . $Review -> getReviewASCII(),
	     "From: $Conf->emailFrom"
	     );
    $Conf->infoMsg("Sent email with review");
    exit();
}


if ( !IsSet($_REQUEST[printableView]) ) {
  $Review -> printAnonReviewHeader($Conf);
}

?>

<hr>
<div align="center">
<center>
<?php 
   if ($Review->finalized()) {
     ?>
     <h2> <p> You have already finalized your review for this paper.<br>
     You can no longer modify it, but you may view it. 
     </p> 

     <p> If you made a mistake in your review and you want it
     "unfinalized", <br> you may 
     <a href="mailto:<?php echo $Conf->contactEmail?>">
     send mail to the program chair asking them to unfinalize it </a> </p>
     </h2>
     <table align=center>
     <tr> <td>
     <FORM METHOD="POST" ACTION="<?php echo $_SERVER[PHP_SELF] ?>" TARGET=_blank>
     <INPUT TYPE=submit NAME=emailReview VALUE="Send yourself this review by email">
     <INPUT TYPE=hidden NAME=paperId value=<?php echo $_REQUEST[paperId]?>>
     </FORM>
     </td> </tr>
     </table>

     <table>

     <?php  $Review -> printViewable(); ?>

     <?php 
   } else if (IsSet($_REQUEST[printableView])) {

     print "<h2> Here is a printable version of the review current stored in the database. </h2>";
     $Review -> printViewable();

   } else {
     $Conf->infoMsg("Enter your review of this paper below. "
		    . "Note that you will be automatically logged "
		    . " out if you are idle for more than "
		    . round(ini_get("session.gc_maxlifetime")/3600) 
		    . " hours, so you may want to save your review "
		    . "periodically (by marking \"don't finalize\" "
		    . " and hitting \"Submit your paper review\") "
		    . " and then return to edit them later. "
		    );
     $Conf->infoMsg( '<P>Please see <a href="../Reviewer/ratings.html" ' .
                     'target="_blank"> rating description information</a> ' .
                     'to decide your review scores.</P><P>' .
                     'The tone of your review is important. When you write ' .
		     'an anonymous review, you are acting as a ' .
		     'representative of our field. It is <em> always </em> ' .
		     'possible to be constructive.</P>'
		    );
     ?>
</center>
</div>

<table>
<tr>

<td>
<FORM METHOD="POST" ACTION="<?php echo $_SERVER[PHP_SELF] ?>" TARGET=_blank>
<INPUT TYPE=submit NAME=printableView VALUE="See a printable version of your review">
<INPUT TYPE=hidden NAME=paperId value=<?php echo $_REQUEST[paperId]?>>
</FORM>
</td>

<td>
<FORM METHOD="POST" ACTION="<?php echo $_SERVER[PHP_SELF] ?>" TARGET=_blank>
<INPUT TYPE=submit NAME=emailReview VALUE="Send yourself this review by email">
<INPUT TYPE=hidden NAME=paperId value=<?php echo $_REQUEST[paperId]?>>
</FORM>
</td>

</tr> </table>


<FORM METHOD="POST" ACTION="<?php echo $_SERVER[PHP_SELF] ?>">
<INPUT TYPE=submit NAME=submit VALUE="Submit your paper review">
<INPUT TYPE=hidden NAME=paperId value=<?php echo $_REQUEST[paperId]?>>
<?php  $Review -> printEditable() ?>
<INPUT TYPE=submit NAME=submit VALUE="Submit your paper review">
</FORM>

<FORM METHOD="POST" ACTION="<?php echo $_SERVER[PHP_SELF] ?>" TARGET=_blank>
<INPUT TYPE=submit NAME=printableView VALUE="See a printable version of your review">
<INPUT TYPE=hidden NAME=paperId value=<?php echo $_REQUEST[paperId]?>>
</FORM>

<?php 
}
?>

<?php $Conf->footer() ?>
