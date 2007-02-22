<?php 
require_once('../Code/header.inc');
$Me = $_SESSION["Me"];
$Me->goIfInvalid();
$Me->goIfNotAssistant('../index.php');

function olink($key,$string)
{
  return "<a href=\"$_SERVER[PHP_SELF]?orderBy=$key\"> $string </a>";
}


$Conf->header_head("List All Submitted Papers");


echo "<style type='text/css'>
p.page {page-break-after: always}
</style>\n";


$Conf->header("List All Submitted Papers");


$finalizedStr = "";
if (defval($_REQUEST["onlyFinalized"])) {
  $finalizedStr =  " where Paper.timeSubmitted>0 ";
}

$ORDER=" order by Paper.paperId";
if (isset($_REQUEST["orderBy"])) {
  $ORDER = " order by " . $_REQUEST["orderBy"];
}
?>
<h2> List of submitted papers and authors </h2>
<p>
This page shows you all the papers &amp; abstracts that have been entered into the database.
Under Microsoft Internet Explorer, you should be able to "Print" or "Print Preview" and it
will print a single abstract per page (overly long abstracts may print on two pages).
I'm not certain if this works under Netscape or other browsers.
</p>

<p class="page"> You should see a page break following this when printing. </p>

<form method="post" action="<?php echo $_SERVER["PHP_SELF"] ?>">
<input type='checkbox' name='onlyFinalized' value='1'
   <?php  if (defval($_REQUEST["onlyFinalized"])) {echo "checked='checked' ";}?> /> Only show finalized <br/>
<input type="submit" value="Update View" name="submit">
</form>
</p>


<?php 
  $query="SELECT Paper.paperId, Paper.title, "
  . " Paper.timeSubmitted, Paper.timeWithdrawn, "
  . " Paper.authorInformation, Paper.abstract, "
  . " group_concat(firstName, ' ', lastName, ' (', email, ')' separator ', ') as contactInfo,"
  . " Paper.size, Paper.mimetype "
  . " from Paper left join PaperConflict on (PaperConflict.paperId=Paper.paperId and PaperConflict.conflictType=" . CONFLICT_AUTHOR . ") left join ContactInfo on (PaperConflict.contactId=ContactInfo.contactId)"
  . $finalizedStr
  . " group by Paper.paperId"
  . $ORDER;

$result=$Conf->qe($query);

   while ($row = edb_arow($result) ) {
     $paperId=$row['paperId'];
     $title=$row['title'];
     $affiliation=nl2br($row['authorInformation']);
     $contactInfo = $row['contactInfo'];
     $abstract=$row['abstract'];
     $mimetype=$row['mimetype'];
     $length=$row['size'];
  ?>


<div align=center>
<h2> Paper # <?php  echo $paperId ?> </h2>
</div>

<table align=center width="100%" border="1">
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
    if (edb_nrows($result2)) {
      print "<ul>";
      while ($top=edb_row($result2)) {
	print "<li>" . $top[0] . "</li>\n";
      }
      print "</ul>";
    }
?>
</td> </tr>
</table>

<p class='page'> End of <?php  echo $paperId ?> </p>
  <?php 
}

$Conf->footer();
