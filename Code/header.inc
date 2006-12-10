<?php 
//
// This needs to be included in each file - it defines the session
// variables used throughout the application.
//
// You should not need to modify any information in this file since
// we automatically determine the configuration paths.
//

define("ROLE_EXISTS", 0);
define("ROLE_PC", 3);
define("ROLE_ASSISTANT", 4);
define("ROLE_CHAIR", 5);
define("ROLE_REVIEWER", 100);	// does not appear in Roles table

define("REVIEW_PRIMARY", 4);
define("REVIEW_SECONDARY", 3);
define("REVIEW_PC", 2);
define("REVIEW_REQUESTED", 1);
$reviewTypeName = array("None", "Requested", "PC", "Secondary", "Primary");

define("OUTCOME_ACCEPTED", 1);
define("OUTCOME_ACCEPTED_SHORT", 2);
define("OUTCOME_REJECTED", -1);

define("CONFLICT_NONE", 0);
define("CONFLICT_PCMARK", 1);
define("CONFLICT_CHAIRMARK", 2);
define("CONFLICT_AUTHOR", 3);

define("TAG_MAXLEN", 40);

$OK = 1;

$Msg["unknown"] = "Unknown error.";

// Disable caching
header("Cache-Control: no-cache, must-revalidate");  // HTTP/1.1
header("Expires: Mon, 26 Jul 1997 05:00:00 GMT");

// Automatically determine path to conference site
$here = substr(__FILE__,0,strrpos(__FILE__,'/'));
if (file_exists($here . "/Code/header.inc"))
    $ConfSitePATH = $here;
else if (file_exists($here . "/../Code/header.inc"))
    $ConfSitePATH = substr($here, 0, strrpos($here,'/'));
else
    $ConfSitePATH = "/var/www/html";

// special handling for Download URLs
{
    $toplev = array('All' => 1, 'Assistant' => 1, 'Author' => 1,
		    'Chair' => 1, 'Code' => 1, 'Download' => 1,
		    'PC' => 1, 'Reviewer' => 1);
    $url = $_SERVER['PHP_SELF'];
    $ndotdot = 0;
    while (($rpos = strrpos($url, "/")) !== false) {
	$last = substr($url, $rpos + 1);
	if (isset($toplev[$last])) {
	    $ConfSiteBase = str_repeat("../", $ndotdot);
	    break;
	}
	$ndotdot++;
	$url = substr($url, 0, $rpos);
    }
    if (!isset($ConfSiteBase))
	$ConfSiteBase = "";
}


// Bring in code
require_once("$ConfSitePATH/Code/helpers.inc");
require_once("$ConfSitePATH/Code/conference.inc");
require_once("$ConfSitePATH/Code/contact.inc");
require_once("$ConfSitePATH/Code/review.inc");

// If you have the PEAR::Mail libraries installed globally,
// there's no need to change the include path.
ini_set("include_path", ini_get("include_path") . ":$ConfSitePATH/Code/Mail-1.1.14");
require_once("PEAR.php");

// Set up conference options
include("$ConfSitePATH/Code/options.inc");

if (!isset($Opt['sessionName']))
    $Opt['sessionName'] = $Opt['dbName'];
if (!isset($Opt['dbPassword']))
    $Opt['dbPassword'] = $Opt['dbName'];
if (!isset($Opt['dsn']))
    $Opt['dsn'] = "mysql://" . $Opt['dbName'] . ":" . $Opt['dbPassword'] . "@127.0.0.1/" . $Opt['dbName'];


// Create the conference
$Conf = new Conference();


// How long before a session is automatically logged out?
//
// Note that on some Debian installations, a cron job garbage-collects old
// sessions.  That cron job ignores local 'session.gc_maxlifetime' settings,
// so you'll also need to change the system-wide setting in 'php.ini'.
ini_set('session.gc_maxlifetime', defval($Opt["sessionLifetime"], 86400));

// Allow lots of memory.
ini_set('memory_limit', defval($Opt["memoryLimit"], "128M"));


session_name($Opt['sessionName']);
session_start();

if (!isset($_SESSION['Me']))
    $_SESSION['Me'] = new Contact;
$_SESSION['Me']->validated = 0;

if (!isset($_SESSION['SeeReviewerInfo']))
    $_SESSION['SeeReviewerInfo'] = 0;
if (!isset($_SESSION['SeeUnfinishedReviews']))
    $_SESSION['SeeUnfinishedReviews'] = 0;
if (!isset($_SESSION['SeeAuthorInfo']))
    $_SESSION['SeeAuthorInfo'] = 0;
if (!isset($_SESSION['ToldYouAboutNetscape47']))
    $_SESSION['ToldYouAboutNetscape47'] = 0;
if (!isset($_SESSION['AskedYouToUpdateContactInfo']))
    $_SESSION['AskedYouToUpdateContactInfo'] = 0;

if (!isset($_SESSION['ShowChairGrades']))
    $_SESSION['ShowChairGrades'] = 0;

if (isset($_REQUEST['whichList']))
    $_SESSION['whichList'] = $_REQUEST['whichList'];
