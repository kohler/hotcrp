<?php 
include('../Code/confHeader.inc');
$_SESSION[Me] -> goIfInvalid("../index.php");
$_SESSION[Me] -> goIfNotChair('../index.php');
$Conf -> connect();
?>

<html>

<?php  $Conf->header("Prepare a backup of the $Conf->ShortName Database") ?>

<body>

<?php 
if ( IsSet($_REQUEST[submitted]) ) {
    $time=mktime();
    $dumpname = "$Conf->dbDumpDir/$Conf->dbName-$time";
    $cmd = "/usr/bin/mysqldump -u $Conf->dbUser -p$Conf->dbPassword "
      . " -h $Conf->dbHost $Conf->dbName > $dumpname";

    $Conf->infoMsg("Dump command is $cmd");

    $rval = 0;
    $ret = system($cmd,$rval);

    if ( !$rval ) {
      $Conf->infoMsg("The database was dumped to $dumpname. It is "
		     . filesize($dumpname) . " bytes large. You should "
		     . " copy it to another location ");
    } else {
      $Conf->errorMsg("There was an error dumping the database. "
		      . "Try doing it by hand using the mysqldump command ");
    }
} else {
?>

<form METHOD=POST ACTION="<?php  echo $_SERVER[PHP_SELF] ?>" >
<INPUT type=SUBMIT name='submitted' value='Press this button to backup the database'>
</form>

<p> If you do not want to backup the database, return to the task index. </p>

<?php }?>

<?php  $Conf->footer() ?>
</body>
</html>
