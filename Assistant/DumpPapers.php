<?php 
include('../Code/confHeader.inc');
$_SESSION["Me"] -> goIfInvalid("../index.php");
$_SESSION["Me"] -> goIfNotAssistant('../index.php');
$Conf -> connect();
?>

<html>

<?php  $Conf->header("Dump Papers To Directory") ?>

<body>


<?php 
$Conf->infoMsg("This dumps the papers to a specified directory "
	       . " on the WEB SERVER (not your browser machine) " );

if ( IsSet($_REQUEST[dumpToDirectory]) ) {
  if ( ! is_dir($_REQUEST[directory]) ) {
    mkdir($_REQUEST[directory], 0777);
    $Conf->infoMsg("Trying to create directory " . $_REQUEST[directory]);
  }
  if ( ! is_dir($_REQUEST[directory]) ) {
    $Conf->errorMsg("Failed to find or create directory " . $_REQUEST[directory]);
  } else {
    //
    // Get to work...
    //
    $q="SELECT * from PaperStorage ORDER BY paperId";
    $r=$Conf->qe($q);
    if (DB::isError($r)) {
      $Conf->errorMsg("Life sucks ". $r->getMessage() );
    } else {
      while ($row=$r->fetchRow(DB_FETCHMODE_ASSOC)) {
	$paperId=$row['paperId'];
	if (! IsSet($paperId) ) {
	  $Conf->errorMsg("Bogus paper, invalid paper id");
	} else {
	  $mimetype=$row['mimetype'];
	  $content=base64_decode(stripslashes($row['paper']));
	  $length=strlen($content);
	  $ext= $Conf -> getFileExtension($mimetype);
	  $prefix = $_REQUEST[directory] . "/" . $Conf->paperPrefix;
	  $name= $prefix . "-$paperId" . $ext;
	
	  $fd = fopen($name, "w");
	  if ( !$fd ) {
	    $Conf->errorMsg("Unable to open $name for writing");
	  } else {
	    $ret = fwrite($fd, $content);
	    if ($ret < 0) {
	      $Conf->errorMsg("Unable to write data for $name");
	    } else {
	      $Conf->infoMsg("Wrote paper $name");
	    }
	    fclose($fd);
	  }
	  flush();
	}
      }
    }
  }
}

?>

<FORM METHOD=POST ACTION=<?php echo $_SERVER[PHP_SELF]?>>
<table>
<tr> <th> Directory path? </th>
<td>
<INPUT TYPE=text size=80 name=directory>
<td>
</tr>
<tr>
<td colspan=2>
<INPUT TYPE=submit name=dumpToDirectory value="Dump those files..">
</td>
</tr>
</table>
</FORM>

</body>
<?php  $Conf->footer() ?>
</html>

