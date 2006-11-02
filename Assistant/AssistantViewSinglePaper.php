<?php 
require_once('../Code/header.inc');
$Conf->connect();
$Me = $_SESSION["Me"];
$Me->goIfInvalid();
$Me->goIfNotAssistant('../index.php');
?>

<html>

<?php  $Conf->header("The Chairs View of Paper #" . $_REQUEST['paperId']) ?>

<body>
<?php 
if (!IsSet($_REQUEST['paperId']) || $_REQUEST['paperId'] == 0) {
  $Conf->errorMsg("No paper was selected for viewing?" );
} else {
  $query = "SELECT Paper.title, Paper.abstract, Paper.authorInformation, "
    . " Paper.timeWithdrawn, "
    . " Paper.size, Paper.mimetype, "
    . " ContactInfo.firstName, ContactInfo.lastName, "
    . " ContactInfo.email, ContactInfo.affiliation "
    . " FROM Paper join ContactInfo using (contactId) "
    . " WHERE Paper.paperId=" . $_REQUEST['paperId'] . " AND ContactInfo.contactId=Paper.ContactId"
    ;
  $result = $Conf->qe($query);
  if ( DB::isError($result) ) {
    $Conf->errorMsg("That's odd - paper #" . $_REQUEST['paperId'] . " isn't suitable for finalizing. "
		    . $result -> getMessage());
  } else {
    $row = $result->fetchRow(DB_FETCHMODE_ASSOC);
    $title = $Conf->safeHtml($row['title']);
    $abstract = $Conf->safeHtml($row['abstract']);
    $authorInfo = $Conf->safeHtml($row['authorInformation']);
    $contactInfo = $row['firstName'] . " " . $row['lastName']
      . " ( " . $row['email'] . " ) ";
    $length=$row['size'];
    $mimetype = $Conf->safeHtml($row['mimetype']);

    print "<table align=center border=0 cellpadding=10>\n";
    print "<tr>";
    if ( $_SESSION['Me'] -> isChair ) {
      print "<td>";
      $Conf->linkWithPaperId("Modify Paper",
			     "../paper.php",
			     $_REQUEST['paperId']);
      print "</td>";
    }

    print "<td>";
    $Conf->textButton("Return To List of Papers",
		      "../Assistant/AssistantListPapers.php");
    print "</td>";

    print "<td>";
    $Conf->toggleButtonUpdate('SeeAuthorInfo');
    $Conf->toggleButtonWithPaperId('SeeAuthorInfo',
				   "Hide Author Information",
				   "See Author Information",
				   $_REQUEST['paperId']);
    print "</td>";

    print "<td>";
    $Conf->linkWithPaperId("See Reviews",
			   "../review.php",
			   $_REQUEST['paperId']);
    print "</td>";

    print "</tr>";
    print "</table>";

    if ($row['timeWithdrawn']) {
      $Conf->infoMsg("This paper has been WITHDRAWN");
    }

    $Conf->toggleButtonUpdate("SeeAuthorInfo");
    $Conf->paperTable( $_REQUEST['SeeAuthorInfo'] );
   }
}
?>

<?php $Conf->footer() ?>
