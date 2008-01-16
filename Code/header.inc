<?php 
// header.inc -- HotCRP central helper file; includes other helpers
// HotCRP is Copyright (c) 2006-2007 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

// All review types must be 1 digit
define("REVIEW_PRIMARY", 4);
define("REVIEW_SECONDARY", 3);
define("REVIEW_PC", 2);
define("REVIEW_EXTERNAL", 1);
$reviewTypeName = array("None", "External", "PC", "Secondary", "Primary");

define("OUTCOME_ACCEPTED", 1);
define("OUTCOME_ACCEPTED_SHORT", 2);
define("OUTCOME_REJECTED", -1);

define("CONFLICT_NONE", 0);
define("CONFLICT_PCMARK", 1);
define("CONFLICT_AUTHORMARK", 2);
define("CONFLICT_CHAIRMARK", 3);
define("CONFLICT_AUTHOR", 9);
define("CONFLICT_CONTACTAUTHOR", 10);

define("TAG_MAXLEN", 40);

define("WATCH_COMMENTSET", 1);
define("WATCH_COMMENT", 2);

$OK = 1;

$Msg["unknown"] = "Unknown error.";

// Determine path to conference site on file system
$here = substr(__FILE__, 0, strrpos(__FILE__,'/'));
if (file_exists($here . "/Code/header.inc"))
    $ConfSitePATH = $here;
else if (file_exists($here . "/../Code/header.inc"))
    $ConfSitePATH = substr($here, 0, strrpos($here,'/'));
else
    $ConfSitePATH = "/var/www/html";

// Determine relative path to conference home in URLs
{
    $toplev = array('Chair' => 1, 'Code' => 1, 'Download' => 1, 'doc' => 1,
		    'doc.php' => 1, 'PC' => 1, 'Reviewer' => 1);
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

// Determine whether to include the .php suffix
$ConfSiteSuffix = ".php";
if (function_exists("apache_get_modules")) {
    if (array_search("mod_rewrite", apache_get_modules()) !== false)
	$ConfSiteSuffix = "";
}


// Bring in code
require_once("$ConfSitePATH/Code/helpers.inc");
require_once("$ConfSitePATH/Code/conference.inc");
require_once("$ConfSitePATH/Code/contact.inc");

// If you have the PEAR::Mail libraries installed globally,
// there's no need to change the include path.
ini_set("include_path", ini_get("include_path") . ":$ConfSitePATH/Code/Mail-1.1.14");
require_once("PEAR.php");

// Set up conference options
include("$ConfSitePATH/Code/options.inc");

if (!isset($Opt['sessionName']))
    $Opt['sessionName'] = $Opt['dbName'];
if (!isset($Opt['dbUser']))
    $Opt['dbUser'] = $Opt['dbName'];
if (!isset($Opt['dbPassword']))
    $Opt['dbPassword'] = $Opt['dbName'];
if (!isset($Opt['dsn']))
    $Opt['dsn'] = "mysql://" . $Opt['dbUser'] . ":" . $Opt['dbPassword'] . "@127.0.0.1/" . $Opt['dbName'];

// Create the conference
$Conf = new Conference();

// Now load review code (which requires that $Conf exist)
require_once("$ConfSitePATH/Code/review.inc");

// How long before a session is automatically logged out?
//
// Note that on many installations, a cron job garbage-collects old
// sessions.  That cron job ignores local 'session.gc_maxlifetime' settings,
// so you'll also need to change the system-wide setting in 'php.ini'.
$Opt["globalSessionLifetime"] = ini_get('session.gc_maxlifetime');
if (!isset($Opt["sessionLifetime"]))
    $Opt["sessionLifetime"] = 86400;
ini_set('session.gc_maxlifetime', defval($Opt, "sessionLifetime", 86400));

// Allow lots of memory.
ini_set('memory_limit', defval($Opt, "memoryLimit", "128M"));


session_name($Opt['sessionName']);
session_start();

if (!isset($_SESSION['Me']))
    $_SESSION['Me'] = new Contact();
$_SESSION['Me']->validated = 0;

if (!isset($_SESSION['AskedYouToUpdateContactInfo']))
    $_SESSION['AskedYouToUpdateContactInfo'] = 0;

// Mark as already expired to discourage caching, but allow the browser
// to cache for history buttons
header("Expires: Mon, 26 Jul 1997 05:00:00 GMT");
header("Cache-Control: private");
header("Pragma: ");
