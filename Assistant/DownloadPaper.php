<?php 
include('../Code/confHeader.inc');
$_SESSION["Me"] -> goIfInvalid("../index.php");
$_SESSION["Me"] -> goIfNotAssistant('../index.php');
$Conf -> connect();

//
// Don't send any output until late into the script..
//

//
// Check if they're allowed to get the paper..
//

$query = "SELECT paperId FROM Paper "
. " WHERE ( Paper.paperId='$_REQUEST[paperId]' )";

$result = $Conf->qe($query);

//
// Check if they're allowed to get the paper..
//

if ( $Conf -> downloadPaper($_REQUEST[paperId]) ) {
    //
    // Happy happy joy joy - do nothing
    //
    $Conf->log("Downloading $_REQUEST[paperId] for review", $_SESSION["Me"]);
    exit();
  } else {
    echo "<html>";
    $Conf->header("Error Retrieving Paper #$_REQUEST[paperId]");
    echo " <body> <p> There appears to be a problem ";
    echo "downloading the file "
      . $result->getMessage()
      . " </p> </body> </html>";

    $Conf->log("Error downloading $_REQUEST[paperId] for review"
	       . $result->getMessage(), $_SESSION["Me"]);
  }
?>
