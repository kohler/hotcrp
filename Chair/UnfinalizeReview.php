<?php 
include('../Code/confHeader.inc');
$_SESSION["Me"] -> goIfInvalid("../index.php");
$_SESSION["Me"] -> goIfNotChair('../index.php');
$Conf -> connect();
?>

<html>

<?php  $Conf->header("Unfinalize Or Finalize A Review") ?>

<body>
<?php 
if (IsSet($_REQUEST[paperId]) && IsSet($_REQUEST[doUnfinalize]) && IsSet($_REQUEST[unfinalizeId])) {
    $q = "UPDATE PaperReview SET finalized=0 "
      . " WHERE paperReviewId=$_REQUEST[unfinalizeId]";

    if ( $Conf->qe($q) ) {
      $Conf->infoMsg("Review should have been unfinalized..");
    } else {
      $Conf->infoMsg("Error unfinalizing review..");
    }
} else if (IsSet($_REQUEST[paperId]) && IsSet($_REQUEST[doFinalize]) && IsSet($_REQUEST[finalizeId])) {
    $q = "UPDATE PaperReview SET finalized=1 "
      . " WHERE paperReviewId=$_REQUEST[finalizeId]";

    if ( $Conf->qe($q) ) {
      $Conf->infoMsg("Review should have been finalized..");
    } else {
      $Conf->infoMsg("Error unfinalizing review..");
    }
} else if (IsSet($_REQUEST[paperId]) && IsSet($_REQUEST[doDelete]) && IsSet($_REQUEST[deleteId])) {
    $q = "DELETE FROM PaperReview  "
      . " WHERE paperReviewId=$_REQUEST[deleteId]";

    if ( $Conf->qe($q) ) {
      $Conf->infoMsg("Review should have been deleted..");
    } else {
      $Conf->infoMsg("Error deleting review..");
    }
}


if (!IsSet($_REQUEST[paperId])) {
  print "<h2> Unfinalize review for which paper ? </h2>";
  print "<p> First, select the paper </p>";
  print "<FORM METHOD=POST ACTION=\"$_SERVER[PHP_SELF]\">";

  print "<SELECT NAME=paperId>\n";

  $q = "SELECT paperId, title FROM Paper ORDER BY paperId";

  $result = $Conf->qe($q);
  if ( DB::isError($result) ) {
    $Conf->errorMsg("Bummer - error");
    exit();
  } else {
    while ($row=$result->fetchRow(DB_FETCHMODE_ASSOC)) {
      $id=$row['paperId'];
      $title=$row['title'];
      print "<OPTION VALUE=$id> #$id - $title </OPTION>\n";
    }
  }
  print "</SELECT>";
  print "<INPUT TYPE=SUBMIT VALUE=\"Select paper\">\n";
  print "</FORM>";

} else if (IsSet($_REQUEST[paperId]) && !IsSet($reviewId)) {

  $q = "SELECT title FROM Paper WHERE paperId=$_REQUEST[paperId]"; 
  $result=$Conf->qe($q);
  if (!DB::isError($result) && $result->numRows() == 1) {
    $row = $result->fetchRow(DB_FETCHMODE_ASSOC);
    $title = $row['title'];
  } else {
    $Conf->errorMsg("Something wrong happened");
    exit();
  }
  print "<h2> You've selected paper #$_REQUEST[paperId] - $title </h2>\n";
  print "<p> Now, select the review (by reviewer) you want to finalize or unfinalize </p>";
  
  $q = "SELECT firstName, lastName, email, paperReviewId "
    . " FROM PaperReview, ContactInfo "
    . " WHERE ContactInfo.contactId=PaperReview.reviewer "
    . " AND PaperReview.paperId=$_REQUEST[paperId] "
    . " AND PaperReview.finalized=0 ";

  $result = $Conf->qe($q);

  if (DB::isError($result)) {
    $Conf->errorMsg("Bummer, error");
    exit();
  } 

  $Conf->textButton("Click here to select another paper", $_SERVER[PHP_SELF]);

  print "<FORM METHOD=POST ACTION=\"$_SERVER[PHP_SELF]\">";
  print "<INPUT TYPE=HIDDEN NAME=paperId VALUE=$_REQUEST[paperId]>\n";

  print "<FIELDSET> <LEGEND> Finalize A Review </LEGEND>\n";
  print "<INPUT TYPE=SUBMIT NAME=doFinalize VALUE=\"Select reviewer and finalize\">\n";
  print "<br>";
  print "<SELECT NAME=finalizeId>\n";
  print "<OPTION VALUE=-1 SELECT> Remember to select a reviewer! </OPTION>\n";

  while($row=$result->fetchRow(DB_FETCHMODE_ASSOC)) {
    $name = $row['firstName'] . " " . $row['lastName']
      . " (" . $row['email'] . ")";
    $rev=$row['paperReviewId'];
    print "<OPTION VALUE=$rev> $name  </OPTION>\n";
  }
  print "</SELECT>";
  print "</FIELDSET>\n";
  
  print "<br> <br>\n";

  $q = "SELECT firstName, lastName, email, paperReviewId "
    . " FROM PaperReview, ContactInfo "
    . " WHERE ContactInfo.contactId=PaperReview.reviewer "
    . " AND PaperReview.paperId=$_REQUEST[paperId] "
    . " AND PaperReview.finalized=1 ";

  $result = $Conf->qe($q);

  if (DB::isError($result)) {
    $Conf->errorMsg("Bummer, error");
    exit();
  }
  print "<FIELDSET> <LEGEND> Unfinalize A Review </LEGEND>\n";
  print "<INPUT TYPE=SUBMIT NAME=doUnfinalize VALUE=\"Select reviewer and unfinalize\">\n";
  print "<br>";
  print "<SELECT NAME=unfinalizeId>\n";
  print "<OPTION VALUE=-1 SELECT> Remember to select a reviewer! </OPTION>\n";

  while($row=$result->fetchRow(DB_FETCHMODE_ASSOC)) {
    $name = $row['firstName'] . " " . $row['lastName']
      . " (" . $row['email'] . ")";
    $rev=$row['paperReviewId'];
    print "<OPTION VALUE=$rev> $name  </OPTION>\n";
  }
  print "</SELECT>";
  print "</FIELDSET>\n";

  print "<br> <br>\n";

  $q = "SELECT firstName, lastName, email, paperReviewId "
    . " FROM PaperReview, ContactInfo "
    . " WHERE ContactInfo.contactId=PaperReview.reviewer "
    . " AND PaperReview.paperId=$_REQUEST[paperId] ";

  $result = $Conf->qe($q);

  if (DB::isError($result)) {
    $Conf->errorMsg("Bummer, error");
    exit();
  }

  print "<FIELDSET> <LEGEND> Delete A Review </LEGEND>\n";
  print "<INPUT TYPE=SUBMIT NAME=doDelete VALUE=\"Select reviewer and delete that review\">\n";
  print "<br>";
  print "<SELECT NAME=deleteId>\n";
  print "<OPTION VALUE=-1 SELECT> Remember to select a reviewer! </OPTION>\n";

  while($row=$result->fetchRow(DB_FETCHMODE_ASSOC)) {
    $name = $row['firstName'] . " " . $row['lastName']
      . " (" . $row['email'] . ")";
    $rev=$row['paperReviewId'];
    print "<OPTION VALUE=$rev> $name  </OPTION>\n";
  }
  print "</SELECT>";
  print "</FIELDSET>\n";

  print "</FORM>";


} else {
  $Conf->erroMsg("I don't know what you're trying to do");
}
?>

</body>
<?php  $Conf->footer() ?>
</html>
