<?php 
include('../Code/confHeader.inc');
$_SESSION["Me"] -> goIfInvalid($Conf->paperSite);
$_SESSION["Me"] -> goIfNotPC($Conf->paperSite);
$Conf -> connect();

include('../Code/confConfigReview.inc');

if (IsSet($_REQUEST[storeComment]) && IsSet($_REQUEST[paperId]) && IsSet($_REQUEST[theComment])) {
  if (IsSet($_REQUEST[forReviewer])) {
    $_REQUEST[forReviewer]=1;
  } else {
    $_REQUEST[forReviewer]=0;
  }

  if (IsSet($_REQUEST[forAuthor])) {
    $_REQUEST[forAuthor]=1;
  } else {
    $_REQUEST[forAuthor]=0;
  }

  $query="INSERT INTO PaperComments "
    . " SET paperId=$_REQUEST[paperId], contactId=" . $_SESSION["Me"]->contactId. ", "
    . " forAuthor=$_REQUEST[forAuthor], forReviewers=$_REQUEST[forReviewer], "
    . " comment='" . addslashes($_REQUEST[theComment]) . "'";

  $Conf->qe($query);
}

if (IsSet($_REQUEST[killCommentId])) {
  $query="DELETE FROM PaperComments WHERE commentId=$_REQUEST[killCommentId]";
  $Conf->qe($query);
}

?>

<html>

<?php  $Conf->header("PC Comments for Paper #$_REQUEST[paperId]") ?>

<body>
<?php 
if ( $_SESSION["Me"]->checkConflict($_REQUEST[paperId], $Conf)) {
  
  $Conf -> errorMsg("The program chairs have registered a conflict "
		    . " of interest for you to read this paper."
		    . " If you think this is incorrect, contact the "
		    . " program chair " );
  exit();
}

//
// Check if they're a primary reviewer (they need to review it), but
// they haven't yet finalized their reviews.
//

$query="select paperId from ReviewRequest where "
. " contactId=" . $_SESSION["Me"]->contactId . " and paperId=" . $_REQUEST["paperId"] . " and reviewType=" . REVIEW_PRIMARY;
;

$result = $Conf->q($query);

if ( $result ) {
  //
  // Ok, I'm a primary reviewer for it
  //
  $row = $result->fetchRow(DB_FETCHMODE_ASSOC);
  if ($row && $row['paperId'] == $_REQUEST[paperId]) {
    //
    // OK, check if they've done the review
    //
    $query="SELECT reviewSubmitted FROM PaperReview WHERE "
      . " PaperReview.contactId=" . $_SESSION["Me"]->contactId. " "
      . " AND PaperReview.paperId=" . $_REQUEST["paperId"];
    ;
    $result = $Conf->q($query);
    $finalized = 0;

    if ( $result ) {
	while ($row = $result->fetchRow()) {
	    $finalized=$row[0];
	}
    }

    if ( ! $finalized ) {
      $Conf->errorMsg("You can not view all the reviews for this paper "
		      . "since you have not yet finalized your own review. ");
      print "<center>";
      print "<a href=\"CheckAssignedPapers.php\"> Click here to continue to check reviews </a>";
      print "</center>";
      exit();
    } else {
#      $Conf->infoMsg("You're cool");
    }
  }
}



$result = $Conf -> qe("SELECT *, UNIX_TIMESTAMP(time) as unixtime "
		      . " FROM PaperComments "
		      . " WHERE paperId=$_REQUEST[paperId] "
		      . " ORDER BY time ");
if (! $result ) {
  $Conf->errorMsg("Error in SQL " . $result->getMessage()/*($result)*/);
}

if ($result->numRows() > 0 ) {
  $Conf->infoMsg("There are no comments");
}

while ($row=$result->fetchRow(DB_FETCHMODE_ASSOC)) {
  print "<table width=75% align=center>\n";

  $when = date ("l dS of F Y h:i:s A",
		$row['unixtime']);

  print "<tr bgcolor=$Conf->infoColor>";
  print "<th align=left> $when </th>";
  print "<th align=right> For PC";
  if ($row['forReviewers']) {
    print ", Reviewers";
  }
  if ($row['forAuthor']) {
    print " and Author.";
  }
  print ". </th>";
  if ( $row['contactId'] == $_SESSION["Me"]->contactId ) {
    print "<th>";
    $id=$row['commentId'];
    $Conf->textButton("Delete?",
		      "$_SERVER[PHP_SELF]?paperId=$_REQUEST[paperId]",
		      "<input type=hidden NAME=killCommentId value=$id>");
    print "</th>";
  }
  print "</tr>";
  print "<tr bgcolor=$Conf->contrastColorOne>\n";
  print "<td colspan=3>";
  print nl2br($row['comment']);
  print "</td>";
  print "</tr>";
  print "</table>";
  print "<br> <br>";
}
?>

<br>
<br>
<?php 
   $Conf->infoMsg("You can enter new comments below. Although your "
		  . " identity is stored, it is not displayed unless "
		  . " you choose to identify yourself. ");
?>
<FORM METHOD=POST ACTION=<?php echo $_SERVER[PHP_SELF]?>>
<INPUT TYPE=hidden name=paperId value="<?php  echo $_REQUEST[paperId]?>">
<INPUT TYPE=submit name=storeComment value="Store Comment">
<table width=80% align=center bgcolor=<?php echo $Conf->contrastColorTwo?>>
<tr> <th colspan=2 bgcolor=<?php echo $Conf->infoColor?>> Add a new comment </th> </tr>
<tr> <th colspan=2> Who should See It (in addition to PC)? </th> </tr>
<tr> <th> The Reviewers? </th> <td> <INPUT TYPE=CHECKBOX NAME=forReviewer> </td> </tr>
<tr> <th> The Authors? </th> <td> <INPUT TYPE=CHECKBOX NAME=forAuthor> </td> </tr>

<tr> <th> Your Comment.<br> HTML OK. </th>
<td><TEXTAREA NAME=theComment rows=10 cols=50 wrap=virtual></TEXTAREA> </td>
</tr>
</table>
<INPUT TYPE=submit name=storeComment value="Store Comment">
</FORM>


<?php  $Conf->footer() ?>
</body>
</html>

