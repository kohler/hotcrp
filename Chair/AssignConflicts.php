<?php 
include('../Code/confHeader.inc');
$_SESSION[Me] -> goIfInvalid("../index.php");
$_SESSION[Me] -> goIfNotChair('../index.php');
$Conf -> connect();
include('Code.inc');
?>

<html>

<?php $Conf->header("Indicate Paper Conflicts With PC Members ") ?>

<body>
<?php 
//
// Process actions from this form..
//
if (IsSet($_REQUEST[assignConflicts])) {
  if (!IsSet($_REQUEST[reviewer])) {
    $Conf->errorMsg("You need to select a reviewer.");
  } else {
    if (IsSet($_REQUEST[Conflict])) {
      for($i=0; $i < sizeof($_REQUEST[Conflict]); $i++) {
	$paperId=$_REQUEST[Conflict][$i];
	//
	// Delete any existing..
	//
	$query="DELETE FROM PaperConflict WHERE paperId='$paperId' "
	  . " AND authorId='$_REQUEST[reviewer]'";
	$Conf->qe($query);

	$query="INSERT INTO PaperConflict SET paperId='$paperId', "
	  . " authorId='$_REQUEST[reviewer]'";
	$Conf -> qe($query);
	$Conf->log("Added reviewer conflict for $_REQUEST[reviewer] for paper $paper", $_SESSION[Me]);
      }
    }
  }
}

if (IsSet($_REQUEST[removeConflict])) {
  removePCConflictPapers($_REQUEST[ExistingConflicts]);
}

$Conf->infoMsg("This is the existing list of conflicts. "
	       . " If you want to remove a conflict, check the box "
	       . " and click the 'remove conflicts' button");

showPCConflictPapers();

print "<br> <br>\n";

print "<center>";
$Conf->textButtonPopup("Click here to search for conflicts based on author names",
		       "FindPCConflicts.php");
print "<br>\n";

$Conf->toggleButtonUpdate('OnlySeeConflicts');
print $Conf->toggleButton('OnlySeeConflicts',
			  "Click to see paper list (for adding conflicts)",
			  "Click to only see conflicts (for printing)");

print "</center>";

print "<br> <br>\n";


if ( ! $_REQUEST[OnlySeeConflicts] ) {
$Conf->infoMsg("You can use this interface to indicate further conflicts");

?>

<FORM METHOD="POST" ACTION="<?php echo $_SERVER[PHP_SELF] ?>">

<?php  $Conf->makePCSelector('reviewer', $_REQUEST[reviewer]); ?>  

<br>
     <INPUT TYPE="SUBMIT" name="assignConflicts" value="Indicate conflict for the selected papers">

     <table border="1" width="100%" cellpadding=0 cellspacing=0>

     <thead>
     <tr>
     <th colspan=1 width=15% valign="center" align="center"> Paper </th>
     <th colspan=3 width=80% valign="center" align="center"> Title & Conflicts </th>
     </tr>
<?php 

$result=$Conf->qe("SELECT Paper.paperId, Paper.title "
		  . " FROM Paper ORDER BY paperId");
if (DB::isError($result)) {
  $Conf->errorMsg("Error in sql " . $result->getMessage());
  exit;
}
 while ($paperRow=$result->fetchRow(DB_FETCHMODE_ASSOC)) {
   $paperId=$paperRow['paperId'];
   //
   // All other fields indexed by papers
   //
   $title=$paperRow['title'];
?>
       <tr>
       <td>
       <b> <?php  echo $paperId ?> </b>
       <INPUT TYPE=checkbox NAME=Conflict[] VALUE='<?php echo $paperId?>'>
       </td> 

       <td> <?php 

       $Conf->linkWithPaperId($title,
			      "../Assistant/AssistantViewSinglePaper.php",
			      $_REQUEST[paperId]);
			      
       $query2="SELECT ContactInfo.email, PaperConflict.paperConflictId "
       . " FROM ContactInfo, PaperConflict "
       . " WHERE PaperConflict.paperId=$paperId AND PaperConflict.authorId=ContactInfo.contactId "
       . " ORDER BY ContactInfo.email";
       $result2=$Conf -> q($query2);
       if ( $result2 ) {
	 print "<br>";
	 while ($row2=$result2->fetchRow()) {
	   print $row2[0];
	   print "<br>";
	 }
       }
       ?>  </td> <tr>
     <?php 
     }
?>
     </table>
<INPUT TYPE="SUBMIT" name="assignConflicts" value="Indicate conflict for the selected papers">
</FORM>
	 <?php  } ?> 
</body>
<?php  $Conf->footer() ?>
</html>

