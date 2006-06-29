<?php 
//
// GetPaper -- this is a PHP script where execution is specified in a .htaccess
// file. This is done so the paper that is being requested is specified as a
// suffix to the GetPaper request. This is necessary to have automatic file naming
// work for specific browsers (I think Mozilla/netscape).
//
include('../Code/confHeader.inc');
//$_SESSION[Me] -> goIfInvalid("../index.php");
$Conf -> connect();

//
// Determine the intended paper -- this code from artur
//

// $PATH_INFO is the rest of the URI (w/o ?... queries)
// strip leading slash
if ( ! IsSet($_REQUEST[paperId]) ) {
  $paper = preg_replace ("/^\//", "", $PATH_INFO);
  $found = preg_match ("/.*paper-(\d+).*$/", $paper, $match);
  if (!$found) {
    echo "<p>Invalid paper name $paper</p>\n";
    exit;
  } else {
    $_REQUEST[paperId] = $match[1];
  }
} else {
  //
  // Should have a valid paperId?
  //
}
//
// Security checks - people who can download all paperss
// are assistants, chairs & PC members. Otherwise, you need
// to be the contact person for that paper.
//
//
if ( $_SESSION[Me] -> isChair || $_SESSION[Me] -> isPC || $_SESSION[Me] -> isAssistant) {
  $valid = 1;
} else if ($_SESSION[Me] -> amPaperAuthor($_REQUEST[paperId], $Conf) ) {
  $valid = 1;
} else if ( $_SESSION[Me] -> iCanReview($_REQUEST[paperId], $Conf) ) {
  $valid = 1;
} else {
  $valid = 0;
}

if ( !$valid ) {
  print "<html>";
  print "<body>";
  $Conf->errorMsg("You are not authorized to download paper #$_REQUEST[paperId]");
  print "</body>";
  print "</html>";
  exit();
}


if ( $Conf -> downloadPaper($_REQUEST[paperId]) ) {
  //
  // Happy happy joy joy - do nothing
  //
  $Conf->log("Downloading $_REQUEST[paperId] for review", $_SESSION[Me]);
  exit();
} else {
  echo "<html>";
  $Conf->header("Error Retrieving Paper #$_REQUEST[paperId]");
  echo " <body> <p> There appears to be a problem ";
  echo "downloading the file  </p> </body> </html>";
}
?>
