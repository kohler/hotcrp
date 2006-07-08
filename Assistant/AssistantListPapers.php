<?php 
include('../Code/confHeader.inc');
$_SESSION["Me"] -> goIfInvalid("../index.php");
$_SESSION["Me"] -> goIfNotAssistant('../index.php');
$Conf -> connect();

function olink($key,$string)
{
  return "<a href=\"" . $_SERVER['PHP_SELF'] . "?orderBy=$key\"> $string </a>";
}

?>

<html>

<?php  $Conf->header("List All Submitted Papers") ?>

<body>
<?php 
if (!IsSet($_REQUEST["ORDER"])) {
  $_REQUEST["ORDER"]="ORDER BY Paper.paperId";
}
if (IsSet($_REQUEST["orderBy"])) {
  $_REQUEST["ORDER"] = "ORDER BY " . $_REQUEST["orderBy"];
}
?>
<h2> List of submitted papers and authors </h2>
<p>
This page shows you all the papers that have been entered into the database.
<br>
If you click on the paper title, a window will pop up with the paper
abstract and a link to download the paper.
<br>
If you click on the contact author email address, you can send them email.
<br>
If you click on the table headers, the table will be sorted using
that specified criteria.
</p>

<?php 

// jl: where is updateFinalized/markFinalized/allPapers defined? is
// this code used?

if ($_SESSION["Me"]->isChair &&
    IsSet($updateFinalized) && IsSet($markFinalized) && IsSet($allPapers)) {
  for ($i = 0; $i < sizeof($allPapers); $i++) {
    $p = $allPapers[$i];
    $mark=0;
    if ( IsSet($markFinalized[$p] ) ) {
      $mark = 1;
    } else {
      $mark = 0;
    }
    $query="UPDATE Paper SET acknowledged=$mark WHERE paperId=$p";
    $Conf->qe($query);
  }
}

$finalizedStr = "";
if ( $_REQUEST[onlyFinalized] ) {
  $finalizedStr =  " AND Paper.acknowledged!=0 ";
}

$withdrawnStr = "";
if ( $_REQUEST[onlyWithdrawn] ) {
  $withdrawnStr =  " AND Paper.withdrawn!=0 ";
}


  $query="SELECT Paper.paperId, Paper.title, "
  . " Paper.acknowledged, Paper.withdrawn, "
  . " Paper.authorInformation, Paper.contactId, "
  . " ContactInfo.firstName, ContactInfo.lastName, "
  . " ContactInfo.email, ContactInfo.affiliation, Paper.collaborators, "
  . " LENGTH(PaperStorage.paper) as size, PaperStorage.mimetype "
  . " FROM Paper, ContactInfo, PaperStorage "
  . " WHERE ContactInfo.contactId = Paper.contactId and PaperStorage.paperStorageId = Paper.paperStorageId "
  . $finalizedStr . $withdrawnStr
  . $_REQUEST["ORDER"];

$result=$Conf->q($query);

  if (DB::isError($result)) {
    $Conf->errorMsg("Error in retrieving paper list "
		    . $result -> getMessage() . " query was: " . $query);
  } else {

    $numpapers = $result->numRows($result);
?>

Found <?php  echo $numpapers ?> papers.

<FORM method="POST" action="<?php echo $_SERVER[PHP_SELF] ?>">

<INPUT type=checkbox name=SeeAuthorInfo value=1
   <?php  if ($_REQUEST["SeeAuthorInfo"]) {echo "checked";}?> > See author info </br>

<INPUT type=checkbox name=onlyWithdrawn value=1
   <?php  if ($_REQUEST["onlyWithdrawn"]) {echo "checked";}?> > Only show withdrawn </br>

<INPUT type=checkbox name=onlyFinalized value=1
   <?php  if ($_REQUEST["onlyFinalized"]) {echo "checked";}?> > Only show finalized </br>

<input type=hidden name="ORDER" value="<?php echo $_REQUEST[ORDER] ?>">

<input type="submit" value="Update View" name="submit">

</p>

<table border="1" width="100%" cellpadding=0 cellspacing=0>
<thead>
<tr>
<th width= 5%> <?php  echo olink("Paper.paperId", "Paper #") ?></th>
<th>
   <?php  echo olink("Paper.title", "Title") ?>  <br>
   <?php  echo olink("size", "(size)") ?> 
</th>
   <?php  if ($_REQUEST["SeeAuthorInfo"]) { ?>
<th width=30%>
<?php  echo olink("ContactInfo.lastName", "Author") ?>
<br>
<?php  echo olink("ContactInfo.email", "(email)") ?>
</th>
<th width=30%>
<?php  echo olink("ContactInfo.affiliation", "Affiliation") ?>
</th>
			    <?php }?>
</tr>

<?php 
   $i = 0;
 while ($row = $result->fetchRow(DB_FETCHMODE_ASSOC) ){
     
     $paperId=$row['paperId'];
     $title=$row['title'];
     $author=nl2br($row['affiliation']);
     $authorInfo = " "
     . $row['firstName'] . " " . $row['lastName']
     . "<br> ( <a href=\"mailto:"
     . $row['email']
     . "\""
     . " TITLE=\"Concerning paper#$paperId\" "
     . ">"
     . $row['email'] . "</a> ) ";

     $collaborators=nl2br($row['collaborators']);

     $finalized = "";

     if ( $row['acknowledged'] > 0) {
       $finalized = "\nFINALIZED";
     }

     if ($row['mimetype'] == NULL) {
       $withdrawn = "\nWITHDRAWN ";
     } else {
	$withdrawn = "\n(" . $row['size']
	   . " bytes) "
	   . $row['mimetype'] . " ";
     }

  ?>
  <tr>
  <td ROWSPAN=2> <?php  echo $paperId; ?></td>
				   <td>
     <?php 
     if ($_SESSION["Me"]->isChair) {
       $link="../PC/PCAllAnonReviewsForPaper.php";
     } else {
       $link="AssistantViewSinglePaper.php";
     }
    ?>

  <?php  
  $Conf->linkWithPaperId($title . $withdrawn . $finalized, $link, $paperId);

  ?>
  </td>
  <?php  if ($_REQUEST["SeeAuthorInfo"]) { ?>
  <td> <?php  echo $authorInfo?> </td>
  <td> <?php  echo $author ?> </td>
     <?php  } ?>
  </tr>
  </tr>
  <TR><TD COLSPAN=3>
  <?php 
  $Conf->paperTable( $_REQUEST["SeeAuthorInfo"], false, $paperId );
  echo '</TD></TR>';
}
}
?>
     </table>
</FORM>
</body>
<?php  $Conf->footer() ?>
</html>

