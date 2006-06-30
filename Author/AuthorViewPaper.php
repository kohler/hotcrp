<?php 
include('../Code/confHeader.inc');
$_SESSION["Me"] -> goIfInvalid("../index.php");
$_SESSION["Me"] -> goIfNotAuthor("../index.php");
$Conf -> connect();
?>

<html>

<?php  $Conf->header("The Authors View of Paper #$_REQUEST[paperId]") ?>

<body>
<?php 
if (!IsSet($_REQUEST[paperId]) || $_REQUEST[paperId] == 0) {
  $Conf->errorMsg("No paper was selected for finalization?" );
} 
else if ( ! $_SESSION["Me"] -> amPaperAuthor($_REQUEST[paperId], $Conf) ) {
  $Conf -> errorMsg("Only the submitting paper author can view the "
		    . "paper information.");
  exit;
} else {
  $query = "SELECT Paper.title, Paper.abstract, Paper.authorInformation, "
    . " PaperStorage.mimetype, Paper.withdrawn, Paper.collaborators "
    . " FROM Paper,PaperStorage WHERE "
    . " (Paper.contactId='" . $_SESSION["Me"]->contactId . "' "
    . " AND Paper.paperId=$_REQUEST[paperId] "
    . " AND PaperStorage.paperId=$_REQUEST[paperId]"
    . " )";

  $result = $Conf->qe($query);
  if ( DB::isError($result) ) {
    $Conf->errorMsg("That's odd - paper #$_REQUEST[paperId] isn't suitable for finalizing. "
		    . $result->getMessage());
  } else {
    $row = $result->fetchRow();
    $i = 0;
    $title = $Conf->safeHtml($row[$i++]);
    $abstract = $Conf->safeHtml($row[$i++]);
    $authorInfo = $Conf->safeHtml($row[$i++]);
    $mimetype = $row[$i++];
    $withdrawn = $row[$i++];
    $collaborators = $row[$i++];

    if ($withdrawn) {
      $Conf->infoMsg("This paper has been WITHDRAWN");
    }

    print "<center>";
    $Conf->textButton("Click here to go to the paper list",
		      "../index.php");
    print "</center>";
   }
   $Conf->paperTable();
}
$Conf->footer()
?>
</body>
</html>
