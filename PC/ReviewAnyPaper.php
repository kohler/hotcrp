<?php 
include('../Code/confHeader.inc');
$_SESSION[Me] -> goIfInvalid($Conf->paperSite);
$_SESSION[Me] -> goIfNotPC($Conf->paperSite);
$Conf -> goIfInvalidActivity("PCSubmitReviewDeadline",
			     $Conf->paperSite);
$Conf -> goIfInvalidActivity("PCReviewAnyPaper",
			     $Conf->paperSite);

$Conf -> connect();
?>

<html>

<?php  $Conf->header("Review Any Paper") ?>

<body>


<?php 
$Conf->infoMsg("This page shows you all the papers that have been entered "
	       . " and lets you review any for which you do not have a conflict. "
	       . " Simply click on the paper title to review it ");

$allConflicts = $Conf->allMyConflicts($_SESSION[Me]->contactId);

//
// Make an array of all the valid paper indicies.
//
  $result=$Conf->qe("SELECT Paper.paperId, Paper.title, "
		    . " Paper.acknowledged, Paper.withdrawn, "
		    . " Paper.authorInformation, Paper.contactId, "
		    . " PaperStorage.mimetype "
		    . " FROM Paper "
		    . " LEFT JOIN PaperStorage ON (PaperStorage.paperId=Paper.paperId) "
		    . " WHERE Paper.acknowledged=1 "
		    . " ORDER BY Paper.paperId");

  if (DB::isError($result)) {
    $Conf->errorMsg("Error in retrieving paper list " . $result->getMessage());
  } else {
    $num = $result->numRows();
    ?>

      <p> There are <?php  echo $num ?> papers.<br>  </p>

      <table border="1" width="100%" cellpadding=0 cellspacing=0>
	 <thead>
	 <tr>
	 <th colspan=1 width=10% valign="center" align="center">Paper # </th>
	 <th colspan=3 width=90% valign="center" align="center"> Title </th>
	 </tr>
	 <?php 
	 $i = 0;
    while ($row = $result->fetchRow(DB_FETCHMODE_ASSOC)) {
      $paperId = $row['paperId'];
      if ( ! $allConflicts[$paperId] ) {
  ?>
    <tr>
    <td width=10% align="center">  <?php  echo $paperId ?> </td>
    <td width=90%>

    <?php
       $Conf->linkWithPaperId( $row['title'],
			       "PCSubmitReview.php",
			       $paperId);
    ?>

    </td>
    </tr>
       <?php 
       }
    }
    print "</table>";
  }
?>

</body>
<?php  $Conf->footer() ?>
</html>




