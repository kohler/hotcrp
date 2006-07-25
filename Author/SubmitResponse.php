<?php 
include('../Code/confHeader.inc');
$_SESSION["Me"] -> goIfInvalid("../index.php");
$_SESSION["Me"] -> goIfNotAuthor("../index.php");
$Conf -> goIfInvalidActivity("authorRespondToReviews", "../index.php");
$Conf -> connect();

$word_limit = 800;
?>

<html>

<?php  $Conf->header("Submit Response to paper reviews for #$_REQUEST[paperId]") ?>

<body>

<p>
You can submit responses to your paper review
<?php  echo $Conf->printableTimeRange('authorRespondToReviews') ?>. <br>
You can continue modifying or  updating the stored response until then.
You will receive email messages each time a new review is finalized, but you
should periodically submit your response as you revise the response to
insure you do not miss the response deadline.  </p>


<?php 

$Conf->infoMsg(
   '<P ALIGN=CENTER>INSTRUCTIONS</P><P> ' .
   'The "author response" should be addressed to the program ' .
   'committee. The program committee will use this information in ' .
   'conjunction with the reviews to reach a final determination concerning ' .
   'the paper. You should keep your response brief and to the point, since ' .
   'each program committee member is responsible for many papers. ' .
   '</P><P> ' .
   'The authors response is a mechanism to address reviewers concerns, to ' .
   'address reviewer confusion, or to address incorrect reviews.  It is ' .
   'not a mechanism to augment the content or form of the paper, or to ' .
   're-argue the paper --- the conference deadline has passed and the ' .
   'program committee must evaluate the papers on the merits of what is ' .
   'included in the paper, not on additional information submitted at this ' .
   'time.</P> '
	       );
$Conf->infoMsg(
   'The response is limited to only ' . $word_limit . ' words.  This limit is enforced by ' .
   'the conference review software.  Your response will not be accepted ' .
   'until it is within the ' . $word_limit . ' limit word.  You can press on "Submit Your ' .
   'Response" to see how many words you have in your response.  YOUR ' .
   'RESPONSE WILL ONLY BE SAVED IF IT IS WITHIN THE ' . $word_limit . ' WORD LIMIT. '
	       );


if ( ! $_SESSION["Me"] -> amPaperAuthor($_REQUEST[paperId], $Conf) ) {
  $Conf->errorMsg("You aren't supposed to be able to respond for paper #$_REQUEST[paperId]. "
		  . "If you think this is in error, contact the program chair. ");
  exit;
}

//
// Make certain that the author has submitted all their reviews
// prior to viewing their own reviews

if( 0 ){
$missingReviews = $Conf->listMissingReviews($_SESSION["Me"]->contactId);

if ($missingReviews) {
  $Conf->errorMsg("Before you can submit a response for your paper, "
		  . " you must finish reviewing the papers you were "
		  . " asked to review, or you must tell the program "
		  . " committee member that you can not finish your "
		  . " reviews. ");
  exit();
}
}


//
// Process any submissions
//

if (IsSet($_REQUEST['submit'])) {
//  $str = $_REQUEST['authorsResponse'];
//  $_REQUEST['authorsResponse'] =
//    preg_replace('/^(\s*(\S+\s+){' . $word_limit . '})(\S)/', "\$1 LIMIT_REACHED \$3", $str);
//  if( $str != $_REQUEST['authorsResponse'] ){
//    $Conf->errorMsg("Response not  updated -- limit of $word_limit words exceeded.");
  $num_words = preg_match_all( '/\S+/', $_REQUEST['authorsResponse'], $junk );
  if( $num_words > $word_limit ){
    $Conf->errorMsg("Response not  updated -- limit of $word_limit words exceeded by " . ($num_words-$word_limit) . " words.");
  } else {
    $Conf->infoMsg("Updating the response ($num_words of $word_limit words used)");
    $set = "authorsResponse='" . addslashes($_REQUEST[authorsResponse]) . "'";
    $query="UPDATE Paper SET $set WHERE paperId='$_REQUEST[paperId]' and contactId='" . $_SESSION["Me"]->contactId . "'";
    $result = $Conf->qe($query);

    if ( !DB::isError($result) ) {
      $Conf->confirmMsg("Successfully updated response");
    } else {
      $Conf->errorMsg("Error in updating response: " . $result->getMessage());
      $Conf->log("Error in updating response for  $_REQUEST[paperId]: " . $result->getMessage(), $_SESSION["Me"]);
    }
  }
} else {

  //
  // Check to see if they've already submitted a review for this paper,
  // and if so, provide them the values from their prior review
  //
  $query = "SELECT authorsResponse FROM Paper WHERE paperId='$_REQUEST[paperId]'";

  $result=$Conf->qe($query);
  if (!DB::isError($result)) {
    if ( $row=$result->fetchRow() ) {
      $_REQUEST[authorsResponse] = stripslashes($row[0]);
    }
  } else {
    $Conf->errorMsg("Error in query: " . $result->getMessage());
  }
}
?>

<form METHOD="POST" ACTION="<?php echo $_SERVER[PHP_SELF] ?>">
<?php echo $Conf->mkHiddenVar('paperId', $_REQUEST[paperId])?>
<table border=1 align=center bgcolor=<?php echo $Conf->bgOne?>>
<tr> <td align=center>
<INPUT TYPE=submit NAME=submit VALUE="Submit your response to the reviews">
</td> </tr>
<tr> <th> Response </th> </tr>
<tr> <td>
<textarea NAME=authorsResponse rows=30 cols=70><?php echo $_REQUEST[authorsResponse]?></textarea>
</td> </tr>
<tr><td align=center>
<INPUT TYPE=submit NAME=submit VALUE="Submit your response to the reviews"><BR>
</td></tr>
</table>
</form>
</body>

<?php $Conf->footer() ?>
