<?php 
include('../Code/confHeader.inc');
$Conf -> connect();

if ( IsSet($loginEmail) ) {
  $_SESSION["Me"] -> lookupByEmail($loginEmail, $Conf);
  if ( IsSet($password)
       && $Conf -> validTimeFor('reviewerViewDecision', 0)
       && $_SESSION["Me"] -> valid()
       && $_SESSION["Me"] -> password == $password) {
    //
    // Let them fall through -- they'll hit other security
    // checks in just a second, but they've been
    // logged in
    //
  } else {
    $_SESSION["Me"] -> invalidate();
    header("Location: ../index.php");
  }
}

$_SESSION["Me"] -> goIfInvalid("../index.php");
if (! $_SESSION["Me"]->isChair ) {
  $Conf -> goIfInvalidActivity("reviewerViewDecision", "../index.php");
}

?>

<?php $Conf->header("See the outcomes for papers you reviewed") ?>



<h3> EDUCATING REVIEWERS</h3>
<p> Reviewing papers is a 
difficult process, and it is sometimes difficult 
for reviewers to determine if their review was 
appropriate or to understand why a paper was rejected 
or accepted. Some authors of papers you have reviewed may
allow you to see to see either the author response 
or the other reviews. The chocie to do this is up to the
author.
This information is only available to reviewers of your paper, 
and unless the author or reviewers have revealed any information, 
the information is anonymous.
</p>

<p> Click the appropriate titles to see paper outcomes and any information provided by the author.</p>

<?php 
 //
 // Now, look for reviews directed to this person
 //

$result=$Conf->qe("SELECT Paper.paperId, Paper.title, Paper.withdrawn FROM Paper, PaperReview "
		  . "WHERE PaperReview.reviewer='".$_SESSION["Me"]->contactId."' "
		  . " AND Paper.paperId=PaperReview.paperId "
		  . " AND PaperReview.finalized=1 "
		  . "ORDER BY Paper.paperId");

if (!DB::isError($result)) {
  $i = 0;
  print "<table align=center width=75% border=1>\n";
  print "<tr> <th align=center> Paper # </th> <th align=cetner > Title </th> </tr>\n";
  while ($row=$result->fetchRow(DB_FETCHMODE_ASSOC)) {
    if ( !$row['withdrawn']) {
      $paperId=$row['paperId'];
      $title=$row['title'];
      print "<tr> ";
      print "<td align=right> $paperId </td> ";
      print "<td> <a href=\"SeeDecision.php?paperId=$paperId\" target=_blank> $title </a> </td>";
      print "</tr>";
    }
  }
  print "</table>\n";
} else {
  $Conf->errorMsg("Error in sql " . $result->getMessage());
}
?>

<?php $Conf->footer() ?>
