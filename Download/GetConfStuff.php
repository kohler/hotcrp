<?php 
require_once('../Code/header.inc');
$Me = $_SESSION["Me"];
$Me->goIfInvalid();
if (!$Conf->timePCViewGrades())
    $Conf->go("../");

function person_ok( $who )
{
  if( $who == "Chair" ){
    return $_SESSION['Me']->isChair;
  } else if( $who == "PC" ){
    return $_SESSION['Me']->isPC;
  } else if( $who == "Author" ){
    return $_SESSION['Me']->isAuthor;
  } else if( $who == "Assistant" ){
    return $_SESSION['Me']->isAssistant;
  }

  return 0;
}

$PATH_INFO = "/" . $_REQUEST['file'];

$matched = preg_match ('/\/((PC|Chair|Author|Assistant)\/([^.\/]*\.(zip|gz|tar\.gz)))$/', $PATH_INFO, $match);

$who = $match[2];
$ext = $match[3];
$base = $match[4];

$file = $match[1];
$path = "../../downloadables/$file";

if ( !$matched || !person_ok($who) || !($fp=fopen($path,"r")) || !($stat=fstat($fp)) ) {
  print "<html>";
  print "<body>";
  $Conf->errorMsg("You are not authorized to download " . safeHtml($file) );
  print "</body>";
  print "</html>";
  exit();
}

$Conf->log("Downloading $file", $_SESSION['Me']);

//$mimetypes = array (
//  "zip" =>

header( "Content-Description: PHP3 Generated Data" );
header( "Content-disposition: inline; filename=$base" );
header( "Content-Type: application/octet-stream" );
header( "Content-length: " . $stat[7] ); 

// If PHP >= 4.3.0, could use file_get_contents which uses mmap

while( strlen($str = fread($fp, 4096)) > 0 ){
  print $str;
}
?>
