<?php 
include('../Code/header.inc');
?>

<html>

<?php  $Conf->header("Activities for Authors") ?>
<body>

<?php 
$AuthorPrefix="";
include("../Tasks-Author.inc");
?>

<?php $Conf->footer() ?>

