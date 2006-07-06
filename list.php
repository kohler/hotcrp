<?php 
require_once('../Code/confHeader.inc');
require_once('../Code/ClassPaperList.inc');
$Conf->connect();
$_SESSION["Me"]->goIfInvalid("../");
$Me = $_SESSION["Me"];

$Conf->header("List Papers");

if (isset($_REQUEST["list"]))
    $list = $_REQUEST['list'];
else
    $list = 'All';

$pl = new PaperList(1, $_REQUEST['sort'], "ListPapers.php");
echo $pl->text($list, $Me);

$Conf->footer() ?>
