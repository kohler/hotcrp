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

echo PaperList::text($list, $Me);

$Conf->footer() ?>
