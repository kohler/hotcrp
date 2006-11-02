<?php 
require_once('../Code/confHeader.inc');
$Conf->connect();
$Me = $_SESSION["Me"];
$Me->goIfInvalid();
$Me->goIfNotChair('../index.php');
?>

<html>

<?php  $Conf->header("Prepare a backup of the $Conf->ShortName Database") ?>

<body>

<?php 
if ( IsSet($_REQUEST[submitted]) ) {
    $time=mktime();
    $dumpname = $Opt['dbDumpDir'] . "/" . $Opt['dbName'] . "-$time";
    $cmd = "/usr/bin/mysqldump -u " . $Opt['dbUser'] . " -p"
	. $Opt['dbPassword'] . " -h " . $Opt['dbHost'] . " "
	. $Opt['dbName'] . " > $dumpname";

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

<?php $Conf->footer() ?>
