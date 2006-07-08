<?php 
//
// This needs to be included in each file - it defines the session
// variables used throughout the application.
//
// You should not need to modify any information in this file since
// we automatically determine the configuration paths.
//

$posixinfo = posix_uname();
$hostname = $posixinfo['nodename'];

define("ROLE_EXISTS", 0);
define("ROLE_PC", 3);
define("ROLE_ASSISTANT", 4);
define("ROLE_CHAIR", 5);
define("ROLE_REVIEWER", 100);	// does not appear in Roles table

define("REVIEW_PRIMARY", 1);
define("REVIEW_SECONDARY", 2);
define("REVIEW_REQUESTED", 3);

$OK = 1;

//
// Disable caching
//
header("Cache-Control: no-cache, must-revalidate");  // HTTP/1.1
header("Pragma: no-cache");                          
// session_cache_limiter('nocache');

//
// automatically determine path to conference site
//
$here = substr(__FILE__,0,strrpos(__FILE__,'/'));
if (file_exists($here . "/Code/confHeader.inc")) {
    $ConfSitePATH=$here;
} else if (file_exists($here . "/../Code/confHeader.inc")) {
    $ConfSitePATH=substr($here,0,strrpos($here,'/'));
} else {
    //  print "<p> CRP is mis-configured? </p>\n";
    $ConfSitePATH="/var/www/html";
}

// decide base URL
$here = substr($_SERVER["PHP_SELF"], 0, strrpos($_SERVER["PHP_SELF"], '/'));
$last = substr($here, strrpos($here, '/') + 1);
if ($last == "All" || $last == "Assistant" || $last == "Author"
    || $last == "Code" || $last == "Chair" || $last == "Download"
    || $last == "PC" || $last == "Reviewer") {
    $ConfSiteBase = "../";
} else {
    $ConfSiteBase = "./";
}

//
// Bring in code
//
require_once("$ConfSitePATH/Code/Helpers.inc");
require_once("$ConfSitePATH/Code/ClassConference.inc");
require_once("$ConfSitePATH/Code/ClassContact.inc");
require_once("$ConfSitePATH/Code/ClassPaper.inc");
require_once("$ConfSitePATH/Code/ClassReview.inc");
//
//
// If you have the PEAR::DB library installed globally, you
// should just require_once() the DB interface you need.
// Otherwise, leave these in...
//
ini_set("include_path", ini_get("include_path") . ":$ConfSitePATH/Code/DB-1.7.6");
require_once("$ConfSitePATH/Code/DB-1.7.6/DB.php");
//require_once("$ConfSitePATH/Code/PEAR-DB/common.inc");
//require_once("$ConfSitePATH/Code/PEAR-DB/DB.inc");
//require_once("$ConfSitePATH/Code/PEAR-DB/mysql.inc");
//require_once("$ConfSitePATH/Code/PEAR-DB/pgsql.inc");
//
//
include("$ConfSitePATH/Code/confConfig.inc");
//

ini_alter('session.gc_maxlifetime', '10800');


session_name($Conf->sessionName);
session_start();
//
// Do we really want Conf to be a session variable?
// I don't think so since this means that changes don't
// take affect until the session terminates
//
//session_register('Conf');

// we are disabling register_globals in php.ini, so we don't need to
// register the following session variables anymore

//session_register('Me');
//session_register('Paper');
//session_register('DB');

//
// Used in PC/PCAllANonReviewsForPaper
//
session_register('SeeReviewerInfo');
session_register('SeeUnfinishedReviews');
session_register('SeeAuthorInfo');

//
// For warnings/popups
//
//session_register('ToldYouAboutNetscape47');
//session_register('AskedYouToUpdateContactInfo');
//
// Grading, Chair/GradeAllPapers
//
//session_register('ShowChairGrades');
//session_register('ShowGrades');
//session_register('ShowOutcome');
//
// Should we show PC papers?
//
//session_register('ShowPCPapers');

if (!isset($_SESSION['Me']))
    $_SESSION['Me'] = new Contact;
$_SESSION['Me']->validated = 0;

if (!IsSet($_SESSION['WhichTaskView']))
    $_SESSION['WhichTaskView'] = 'All';

if (!IsSet($_SESSION['SeeReviewerInfo']))
    $_SESSION['SeeReviewerInfo'] = 0;
if (!IsSet($_SESSION['SeeUnfinishedReviews']))
    $_SESSION['SeeUnfinishedReviews'] = 0;
if (!IsSet($_SESSION['SeeAuthorInfo']))
    $_SESSION['SeeAuthorInfo'] = 0;
if (!IsSet($_SESSION['ToldYouAboutNetscape47']))
    $_SESSION['ToldYouAboutNetscape47'] = 0;
if (!IsSet($_SESSION['AskedYouToUpdateContactInfo']))
    $_SESSION['AskedYouToUpdateContactInfo'] = 0;

if (!IsSet($_SESSION['ShowChairGrades']))
    $_SESSION['ShowChairGrades'] = 0;

?>