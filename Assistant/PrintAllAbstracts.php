<?php 
include('../Code/confHeader.inc');
$_SESSION["Me"] -> goIfInvalid("../index.php");
$_SESSION["Me"] -> goIfNotAssistant('../index.php');
$Conf -> connect();

function olink($key,$string)
{
  return "<a href=\"$_SERVER[PHP_SELF]?orderBy=$key\"> $string </a>";
}

?>

<html>
<style type=text/css>
p.page {page-break-after: always}
</style>


<?php  $Conf->header("List All Submitted Papers") ?>

<body>
<?php 

$finalizedStr = "";
if ( $_REQUEST[onlyFinalized] ) {
  $finalizedStr =  " AND Paper.acknowledged!=0 ";
}

$ORDER="ORDER BY Paper.paperId";
if (IsSet($_REQUEST[orderBy])) {
  $ORDER = "ORDER BY " . $_REQUEST[orderBy];
}
?>
<h2> List of submitted papers and authors </h2>
<p>
This page shows you all the papers & abstracts that have been entered into the database.
Under Microsoft Internet Explorer, you should be able to "Print" or "Print Preview" and it
will print a single abstract per page (overly long abstracts may print on two pages).
I'm not certain if this works under Netscape or other browsers.
</p>

<P CLASS=page> You should see a page break following this when printing. </p>

<FORM method="POST" action="<?php echo $_SERVER[PHP_SELF] ?>">
<INPUT type=checkbox name=onlyFinalized value=1
   <?php  if ($_REQUEST[onlyFinalized]) {echo "checked";}?> > Only show finalized </br>
<input type="submit" value="Update View" name="submit">
</FORM>
</p>


<?php 
  $query="SELECT Paper.paperId, Paper.title, "
  . " Paper.acknowledged, Paper.withdrawn, "
  . " Paper.authorInformation, Paper.abstract, Paper.contactId, "
  . " ContactInfo.firstName, ContactInfo.lastName, "
  . " ContactInfo.email, ContactInfo.affiliation, "
  . " LENGTH(PaperStorage.paper), PaperStorage.mimetype "
  . " FROM Paper, ContactInfo "
  . " LEFT JOIN PaperStorage ON (PaperStorage.paperId=Paper.paperId)"
  . " WHERE ContactInfo.contactId = Paper.ContactId "
  . $finalizedStr
  . $ORDER;

$result=$Conf->q($query);

  if (DB::isError($result) ) {
    $Conf->errorMsg("Error in retrieving paper list " . $result->getMessage());
  } else {
   while ($row = $result->fetchRow(DB_FETCHMODE_ASSOC) ) {
     $paperId=$row['paperId'];
     $title=$row['title'];
     $affiliation=nl2br($row['authorInformation']);
     $contactInfo = $row['firstName'] . " " . $row['lastName']
     . " ( " . $row['email'] . " ) ";
     $abstract=$row['abstract'];
     $mimetype=$row['mimetype'];
     $length=$row['LENGTH(PaperStorage.paper)'];
  ?>


<div align=center>
<h2> Paper # <?php  echo $paperId ?> </h2>
</div>

<table align=center width="100%" border=1 bgcolor="<?php echo $Conf->bgOne?>" >
<tr> <h3> <b> Paper # <?php  echo $paperId ?>
(Paper is ~<?php echo $length?> bytes, <?php  echo $mimetype ?> format) </b>
<h3> <tr>
   <tr> <td> Title: </td> <td> <?php  echo $title ?> </td> </tr>
   <tr> <td> Contact: </td> <td> <?php  echo $contactInfo ?> </td> </tr>
   <tr> <td> Author Information: </td>
   <td>  <?php  echo $affiliation ?> </td> </tr>
<tr> <td> Abstract: </td> <td> <?php  echo $abstract ?> </td> </tr>
</table>

<table align=center width="75%" border=0 >
<tr> <th> Indicated Topics </th> </tr>
<tr> <td>
<?php 
   $query="SELECT topicName from TopicArea, PaperTopic "
   . "WHERE PaperTopic.paperId=$paperId "
   . "AND PaperTopic.topicId=TopicArea.topicId ";
    $result2 = $Conf->qe($query);
    if ( ! DB::isError($result) ) {
      print "<ul>";
      while ($top=$result2->fetchRow()) {
	print "<li>" . $top[0] . "</li>\n";
      }
      print "</ul>";
    }
?>
</td> </tr>
</table>

<p CLASS=page> End of <?php  echo $paperId ?> </p>
  <?php 
}
}
?>

</body>
<?php  $Conf->footer() ?>
</html>

