<?php
// header.inc -- HotCRP central helper file; includes other helpers
// HotCRP is Copyright (c) 2006-2009 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

// All review types must be 1 digit
define("REVIEW_PRIMARY", 4);
define("REVIEW_SECONDARY", 3);
define("REVIEW_PC", 2);
define("REVIEW_EXTERNAL", 1);
$reviewTypeName = array("None", "External", "PC", "Secondary", "Primary");

define("CONFLICT_NONE", 0);
define("CONFLICT_PCMARK", 1);
define("CONFLICT_AUTHORMARK", 2);
define("CONFLICT_MAXAUTHORMARK", 7);
define("CONFLICT_CHAIRMARK", 8);
define("CONFLICT_AUTHOR", 9);
define("CONFLICT_CONTACTAUTHOR", 10);

define("TAG_MAXLEN", 40);

define("WATCH_COMMENTSET", 1);
define("WATCH_COMMENT", 2);

define("REV_RATINGS_PC", 0);
define("REV_RATINGS_PC_EXTERNAL", 1);
define("REV_RATINGS_NONE", 2);

define("AU_SEEREV_NO", 0);
define("AU_SEEREV_YES", 1);
define("AU_SEEREV_ALWAYS", 2);

define("SEEDEC_ALL", 2);
define("SEEDEC_REV", 1);
define("SEEDEC_ADMIN", 0);

define("BLIND_ALWAYS", 2);	/* ordering is important */
define("BLIND_OPTIONAL", 1);
define("BLIND_NEVER", 0);

$ReviewFormCache = null;
$reviewScoreNames = array("overAllMerit", "technicalMerit", "novelty",
			  "grammar", "reviewerQualification", "potential",
			  "fixability", "interestToCommunity", "longevity",
			  "likelyPresentation", "suitableForShort");

$allowedSessionVars = array("foldassigna", "foldpapera", "foldpaperp",
			    "foldpaperb", "foldpapert", "pfdisplay",
			    "pldisplay", "foldpscollab");

$authorConflictTypes = array(0 => "No conflict", 3 => "Advisor/student",
			     2 => "Recent collaborator", 4 => "Institutional",
			     5 => "Personal", 6 => "Other");

$OK = 1;

$Msg["unknown"] = "Unknown error.";


// set up conference path variables
function setPathVariables() {
    global $ConfSitePATH, $ConfSiteBase, $ConfSiteSuffix;

    // Determine path to conference site on file system
    $here = substr(__FILE__, 0, strrpos(__FILE__, '/'));
    if (file_exists($here . "/Code/header.inc"))
	$ConfSitePATH = $here;
    else if (file_exists($here . "/../Code/header.inc"))
	$ConfSitePATH = substr($here, 0, strrpos($here,'/'));
    else
	$ConfSitePATH = "/var/www/html";

    // Determine relative path to conference home in URLs
    $toplev = array("Code" => 1, "doc" => 1, "doc.php" => 1);
    $url = $_SERVER["PHP_SELF"];
    $ndotdot = 0;
    $ConfSiteBase = "";
    while (($rpos = strrpos($url, "/")) !== false) {
	$last = substr($url, $rpos + 1);
	if (isset($toplev[$last])) {
	    $ConfSiteBase = str_repeat("../", $ndotdot);
	    break;
	}
	$ndotdot++;
	$url = substr($url, 0, $rpos);
    }

    // Determine whether to include the .php suffix
    $ConfSiteSuffix = ".php";
    if (function_exists("apache_get_modules")) {
	if (array_search("mod_rewrite", apache_get_modules()) !== false)
	    $ConfSiteSuffix = "";
    }
}

setPathVariables();


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

// Multi-conference support
function setMulticonference() {
    global $ConfSiteBase, $Opt;

    $url = explode("/", $_SERVER["PHP_SELF"]);
    $npop = strlen($ConfSiteBase) / 3;
    if ($url[count($url) - 1] == "")
	$npop++;
    if ($npop + 2 > count($url))
	return;
    $confabbr = $url[count($url) - $npop - 2];

    $nchanged = 0;
    foreach (array("dbName", "dbUser", "dbPassword", "dsn") as $k)
	if (isset($Opt[$k])) {
	    $Opt[$k] = str_replace("*", $confabbr, $Opt[$k]);
	    ++$nchanged;
	}
    if ($nchanged == 0)
	$Opt["dbName"] = $confabbr;

    foreach (array("sessionName", "downloadPrefix", "conferenceSite",
		   "paperSite") as $k)
	if (isset($Opt[$k]))
	    $Opt[$k] = str_replace("*", $confabbr, $Opt[$k]);

    if (!isset($Opt["downloadPrefix"]))
	$Opt["downloadPrefix"] = $confabbr . "-";
}

if (isset($Opt["multiconference"]) && $Opt["multiconference"])
    setMulticonference();

if (!isset($Opt['sessionName']))
    $Opt['sessionName'] = $Opt['dbName'];
if (!isset($Opt["downloadPrefix"]))
    $Opt["downloadPrefix"] = $Opt["dbName"] . "-";
if (!isset($Opt['dbUser']))
    $Opt['dbUser'] = $Opt['dbName'];
if (!isset($Opt['dbPassword']))
    $Opt['dbPassword'] = $Opt['dbName'];
if (!isset($Opt['dsn']))
    $Opt['dsn'] = "mysql://" . $Opt['dbUser'] . ":" . $Opt['dbPassword'] . "@localhost/" . $Opt['dbName'];

// Create the conference
$Conf = new Conference();

// Now load review code (which requires that $Conf exist)
function __autoload($class_name) {
    global $ConfSitePATH;
    if ($class_name == "ReviewForm")
	require_once("$ConfSitePATH/Code/review.inc");
}

// Set server timezone
if (isset($Opt["timezone"]) && function_exists("date_default_timezone_set"))
    date_default_timezone_set($Opt["timezone"]);

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

// Check and fix Zlib output compression.
global $zlib_output_compression;
$zlib_output_compression = false;
if (function_exists("zlib_get_coding_type"))
    $zlib_output_compression = zlib_get_coding_type();
if ($zlib_output_compression) {
    header("Content-Encoding: $zlib_output_compression");
    header("Vary: Accept-Encoding", false);
}

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
