<?php
// header.inc -- HotCRP central helper file; includes other helpers
// HotCRP is Copyright (c) 2006-2013 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

define("HOTCRP_VERSION", "2.61");

// All review types must be 1 digit
define("REVIEW_PRIMARY", 4);
define("REVIEW_SECONDARY", 3);
define("REVIEW_PC", 2);
define("REVIEW_EXTERNAL", 1);
global $reviewTypeName;
$reviewTypeName = array("None", "External", "PC", "Secondary", "Primary");
// see also review_type_icon, script:selassign

define("CONFLICT_NONE", 0);
define("CONFLICT_PCMARK", 1);
define("CONFLICT_AUTHORMARK", 2);
define("CONFLICT_MAXAUTHORMARK", 7);
define("CONFLICT_CHAIRMARK", 8);
define("CONFLICT_AUTHOR", 9);
define("CONFLICT_CONTACTAUTHOR", 10);

define("TAG_MAXLEN", 40);

// User explicitly set notification preference (only in PaperWatch.watch)
define("WATCHSHIFT_EXPLICIT", 0);
// Notify if author, reviewer, commenter
define("WATCHSHIFT_NORMAL", 1);
// Always notify (only in ContactInfo.defaultWatch, generally admin only)
define("WATCHSHIFT_ALL", 2);

define("WATCHTYPE_COMMENT", (1 << 0));
define("WATCH_COMMENTSET", WATCHTYPE_COMMENT << WATCHSHIFT_EXPLICIT);
define("WATCH_COMMENT", WATCHTYPE_COMMENT << WATCHSHIFT_NORMAL);
define("WATCH_ALLCOMMENTS", WATCHTYPE_COMMENT << WATCHSHIFT_ALL);

define("WATCHTYPE_REVIEW", (1 << 0)); // same as WATCHTYPE_COMMENT

define("WATCHTYPE_FINAL_SUBMIT", (1 << 3));

define("REV_RATINGS_PC", 0);
define("REV_RATINGS_PC_EXTERNAL", 1);
define("REV_RATINGS_NONE", 2);

define("AU_SEEREV_NO", 0);
define("AU_SEEREV_YES", 1);
define("AU_SEEREV_ALWAYS", 2);

define("SEEDEC_NCREV", 3);
define("SEEDEC_ALL", 2);
define("SEEDEC_REV", 1);
define("SEEDEC_ADMIN", 0);

define("BLIND_UNTILREVIEW", 3);
define("BLIND_ALWAYS", 2);	/* ordering is important for rev/cmt */
define("BLIND_OPTIONAL", 1);
define("BLIND_NEVER", 0);

define("DTYPE_SUBMISSION", 0);
define("DTYPE_FINAL", -1);
define("DTYPE_COMMENT", -2);

define("MIMETYPEID_TXT", 1);
define("MIMETYPEID_PDF", 2);

define("OPTIONTYPE_CHECKBOX", 0);
define("OPTIONTYPE_SELECTOR", 1); /* see also script.js:doopttype */
define("OPTIONTYPE_NUMERIC", 2);
define("OPTIONTYPE_TEXT", 3);
define("OPTIONTYPE_PDF", 4);	/* order matters */
define("OPTIONTYPE_SLIDES", 5);
define("OPTIONTYPE_VIDEO", 6);
define("OPTIONTYPE_FINALPDF", 100);
define("OPTIONTYPE_FINALSLIDES", 101);
define("OPTIONTYPE_FINALVIDEO", 102);

define("VIEWSCORE_FALSE", -3);
define("VIEWSCORE_ADMINONLY", -2);
define("VIEWSCORE_REVIEWERONLY", -1);
define("VIEWSCORE_PC", 0);
define("VIEWSCORE_AUTHOR", 1);

define("COMMENTTYPE_DRAFT", 1);
define("COMMENTTYPE_BLIND", 2);
define("COMMENTTYPE_RESPONSE", 4);
define("COMMENTTYPE_ADMINONLY", 0x00000);
define("COMMENTTYPE_PCONLY", 0x10000);
define("COMMENTTYPE_REVIEWER", 0x20000);
define("COMMENTTYPE_AUTHOR", 0x30000);
define("COMMENTTYPE_VISIBILITY", 0xFFF0000);

define("TAG_REGEX", '~?~?[a-zA-Z!@*_:.][-a-zA-Z0-9!@*_:.\/]*');
define("TAG_REGEX_OPTVALUE", '~?~?[a-zA-Z!@*_:.][-a-zA-Z0-9!@*_:.\/]*([#=](-\d)?\d*)?');

define("CAPTYPE_RESETPASSWORD", 1);
define("CAPTYPE_CHANGEEMAIL", 2);

global $rf;
$rf = null;
global $ReviewFormCache;
$ReviewFormCache = null;

global $reviewScoreNames;
$reviewScoreNames = array("overAllMerit", "technicalMerit", "novelty",
			  "grammar", "reviewerQualification", "potential",
			  "fixability", "interestToCommunity", "longevity",
			  "likelyPresentation", "suitableForShort");

global $OK;
$OK = 1;
global $Now;
$Now = time();

global $allowedSessionVars;
$allowedSessionVars = array("foldassigna", "foldpaperp", "foldpaperb",
			    "foldpapert", "foldpscollab", "foldhomeactivity",
			    "pfdisplay", "pldisplay", "ppldisplay");

global $authorConflictTypes;
$authorConflictTypes = array(0 => "No conflict", 3 => "Advisor/student",
			     2 => "Recent collaborator", 4 => "Institutional",
			     5 => "Personal", 6 => "Other");


// set up conference path variables
function set_path_variables() {
    global $ConfSitePATH, $ConfSiteBase, $ConfSiteSuffix;

    // Determine path to conference site on file system
    $here = substr(__FILE__, 0, strrpos(__FILE__, '/'));
    if (file_exists($here . "/Code/header.inc"))
	$ConfSitePATH = $here;
    else if (file_exists($here . "/../Code/header.inc"))
	$ConfSitePATH = substr($here, 0, strrpos($here, '/'));
    else
	$ConfSitePATH = "/var/www/html";

    // Determine relative path to conference home in URLs
    if (isset($_SERVER["PATH_INFO"]))
	$ConfSiteBase = str_repeat("../", substr_count($_SERVER["PATH_INFO"], "/"));
    else {
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
    }

    // Determine whether to include the .php suffix
    $ConfSiteSuffix = ".php";
    if (function_exists("apache_get_modules")
        && array_search("mod_rewrite", apache_get_modules()) !== false)
        $ConfSiteSuffix = "";
}

set_path_variables();


// Check for obsolete pages.
// These are pages that we've removed from the source. But some user might
// have an old version of the page lying around their directory. Don't run
// that code; redirect to index.
require_once("$ConfSitePATH/lib/base.php");
require_once("$ConfSitePATH/lib/redirect.php");
if (array_search(request_script_base(),
                 array("login", "logout", "contactauthors")) !== false)
    go();


// Bring in code
require_once("$ConfSitePATH/src/helpers.php");
require_once("$ConfSitePATH/src/conference.php");
require_once("$ConfSitePATH/src/contact.php");

function __autoload($class_name) {
    global $ConfSitePATH;
    if ($class_name == "ReviewForm")
	require_once("$ConfSitePATH/src/review.php");
    else if ($class_name == "PaperSearch")
        require_once("$ConfSitePATH/src/papersearch.php");
    else if ($class_name == "PaperActions")
        require_once("$ConfSitePATH/src/paperactions.php");
    else if ($class_name == "Text")
        require_once("$ConfSitePATH/lib/text.php");
    else if ($class_name == "Tagger")
        require_once("$ConfSitePATH/lib/tagger.php");
    else if ($class_name == "Mimetype")
        require_once("$ConfSitePATH/lib/mimetype.php");
    else if ($class_name == "DocumentHelper" || $class_name == "ZipDocument")
        require_once("$ConfSitePATH/lib/documenthelper.php");
    else if ($class_name == "HotCRPDocument")
        require_once("$ConfSitePATH/src/hotcrpdocument.php");
    else if ($class_name == "Mailer")
        require_once("$ConfSitePATH/src/mailer.php");
    else if ($class_name == "UnicodeHelper")
        require_once("$ConfSitePATH/lib/unicodehelper.php");
    else if ($class_name == "Qobject")
        require_once("$ConfSitePATH/lib/qobject.php");
    else if ($class_name == "PaperList")
        require_once("$ConfSitePATH/src/paperlist.php");
    else if ($class_name == "Column")
        require_once("$ConfSitePATH/lib/column.php");
    else if ($class_name == "PaperColumn")
        require_once("$ConfSitePATH/src/papercolumn.php");
    else if ($class_name == "PaperOption")
        require_once("$ConfSitePATH/src/paperoption.php");
    else if ($class_name == "PaperRank")
        require_once("$ConfSitePATH/src/rank.php");
    else if ($class_name == "CsvParser" || $class_name == "CsvGenerator")
        require_once("$ConfSitePATH/lib/csv.php");
    else if ($class_name == "XlsxGenerator")
        require_once("$ConfSitePATH/lib/xlsx.php");
    else if ($class_name == "LoginHelper")
        require_once("$ConfSitePATH/lib/login.php");
    else if ($class_name == "CleanHTML")
        require_once("$ConfSitePATH/lib/cleanhtml.php");
    else if ($class_name == "CheckFormat")
        require_once("$ConfSitePATH/lib/checkformat.php");
    else if ($class_name == "Countries")
        require_once("$ConfSitePATH/lib/countries.php");
    else if ($class_name == "Message")
        require_once("$ConfSitePATH/lib/message.php");
    else if ($class_name == "Formula")
        require_once("$ConfSitePATH/src/formula.php");
    else if ($class_name == "Ht")
        require_once("$ConfSitePATH/lib/ht.php");
}

// Set up conference options
global $Opt;
$Opt = array();
if ((@include "$ConfSitePATH/Code/options.inc") == false) {
    if (isset($_REQUEST["ajax"]) && $_REQUEST["ajax"]) {
        if (isset($_REQUEST["jsontext"]) && $_REQUEST["jsontext"])
            header("Content-Type: text/plain");
        else
            header("Content-Type: application/json");
        echo "{\"error\":\"unconfigured installation\"}\n";
    } else {
        echo "<html><head><title>HotCRP error</title><head><body><h1>Unconfigured HotCRP installation</h1>";
        echo "<p>HotCRP has been installed, but you haven’t yet configured a conference. You must run <code>Code/createdb.sh</code> to create a database for your conference. See <code>README.md</code> for further guidance.</p></body></html>\n";
    }
    exit;
}

// Multi-conference support
function setMulticonference() {
    global $ConfSiteBase, $ConfMulticonf, $Opt;

    $url = explode("/", $_SERVER["PHP_SELF"]);
    $npop = strlen($ConfSiteBase) / 3;
    if ($url[count($url) - 1] == "")
	$npop++;
    if ($npop + 2 > count($url))
	return;
    $ConfMulticonf = $url[count($url) - $npop - 2];

    $nchanged = 0;
    foreach (array("dbName", "dbUser", "dbPassword", "dsn") as $k)
	if (isset($Opt[$k])) {
	    $Opt[$k] = str_replace("*", $ConfMulticonf, $Opt[$k]);
	    ++$nchanged;
	}
    if ($nchanged == 0)
	$Opt["dbName"] = $ConfMulticonf;

    foreach (array("sessionName", "downloadPrefix", "conferenceSite",
		   "paperSite") as $k)
	if (isset($Opt[$k]))
	    $Opt[$k] = str_replace("*", $ConfMulticonf, $Opt[$k]);

    if (!isset($Opt["downloadPrefix"]))
	$Opt["downloadPrefix"] = $ConfMulticonf . "-";
}

if (isset($Opt["multiconference"]) && $Opt["multiconference"])
    setMulticonference();

if (isset($Opt["dbName"])) {
    if (!isset($Opt["sessionName"]))
	$Opt["sessionName"] = $Opt["dbName"];
    if (!isset($Opt["downloadPrefix"]))
	$Opt["downloadPrefix"] = $Opt["dbName"] . "-";
    if (!isset($Opt["dbUser"]))
	$Opt["dbUser"] = $Opt["dbName"];
    if (!isset($Opt["dbPassword"]))
	$Opt["dbPassword"] = $Opt["dbName"];
    if (!isset($Opt["dsn"]))
	$Opt["dsn"] = "mysql://" . urlencode($Opt["dbUser"]) . ":" . urlencode($Opt["dbPassword"]) . "@" . urlencode(defval($Opt, "dbHost", "localhost")) . "/" . urlencode($Opt["dbName"]);
}

function setPaperSite() {
    global $Opt;
    $https = isset($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] != "off";
    $Opt["paperSite"] = ($https ? "https://" : "http://") . urlencode($_SERVER["HTTP_HOST"]);
    if (isset($_SERVER["SERVER_PORT"]) && $_SERVER["SERVER_PORT"] != ($https ? 443 : 80))
	$Opt["paperSite"] .= ":" . $_SERVER["SERVER_PORT"];
    $uri = $_SERVER["REQUEST_URI"];
    if (isset($_SERVER["QUERY_STRING"]) && ($len = strlen($_SERVER["QUERY_STRING"])))
	$uri = substr($uri, 0, -$len);
    if (isset($_SERVER["PATH_INFO"]) && ($len = strlen($_SERVER["PATH_INFO"])))
	$uri = substr($uri, 0, -$len);
    $Opt["paperSite"] .= substr($uri, 0, strrpos($uri, "/"));
}

if (!isset($Opt["paperSite"]) || $Opt["paperSite"] == "")
     setPaperSite();

// Create the conference
global $Conf;
$Conf = new Conference();

// Set server timezone
if (function_exists("date_default_timezone_set")) {
    if (isset($Opt["timezone"]))
        date_default_timezone_set($Opt["timezone"]);
    else if (!ini_get("date.timezone") && !getenv("TZ"))
        date_default_timezone_set("America/New_York");
}

// Set locale to C (so that, e.g., strtolower() on UTF-8 data doesn't explode)
setlocale(LC_COLLATE, "C");
setlocale(LC_CTYPE, "C");

// How long before a session is automatically logged out?
//
// Note that on many installations, a cron job garbage-collects old
// sessions.  That cron job ignores local 'session.gc_maxlifetime' settings,
// so you'll also need to change the system-wide setting in 'php.ini'.
$Opt["globalSessionLifetime"] = ini_get('session.gc_maxlifetime');
if (!isset($Opt["sessionLifetime"]))
    $Opt["sessionLifetime"] = 86400;
ini_set('session.gc_maxlifetime', defval($Opt, "sessionLifetime", 86400));

// Allow lots of memory
ini_set('memory_limit', defval($Opt, "memoryLimit", "128M"));

// Check and fix Zlib output compression
global $zlib_output_compression;
$zlib_output_compression = false;
if (function_exists("zlib_get_coding_type"))
    $zlib_output_compression = zlib_get_coding_type();
if ($zlib_output_compression) {
    header("Content-Encoding: $zlib_output_compression");
    header("Vary: Accept-Encoding", false);
}

ensure_session();

global $Me;
if (!isset($_SESSION["Me"])) {
    $_SESSION["Me"] = new Contact();
    $_SESSION["Me"]->fresh = true;
    $_SESSION["Me"]->dsn = $Opt["dsn"];
}
$Me = $_SESSION["Me"];
$Me->validated = false;

// Handle capabilities
function hotcrp_random_bytes($length = 16, $secure_only = false) {
    $key = false;
    if (function_exists("openssl_random_pseudo_bytes")) {
	$key = openssl_random_pseudo_bytes($length, $strong);
	$key = ($strong ? $key : false);
    }
    if (!$key)
	$key = @file_get_contents("/dev/urandom", false, null, 0, $length);
    if (!$key && !$secure_only) {
	$key = "";
	while (strlen($key) < $length)
	    $key .= pack("V", mt_rand());
	$key = substr($key, 0, $length);
    }
    return $key;
}

function setCapabilities() {
    global $Conf, $Opt, $Me, $CapabilitiesOK;
    if (isset($Opt["disableCapabilities"]) && !$Opt["disableCapabilities"])
	unset($Opt["disableCapabilities"]);
    if (!isset($Opt["disableCapabilities"]) && !$Conf->setting("cap_key")) {
	$key = hotcrp_random_bytes(16);
	if ($key && ($key = base64_encode($key))
	    && $Conf->qx("insert into Settings (name, value, data) values ('cap_key', 1, '" . sqlq($key) . "')"))
	    $Conf->updateSettings();
	else
	    $Opt["disableCapabilities"] = true;
    }
    if (!isset($Opt["disableCapabilities"]) && isset($_REQUEST["cap"])
	&& $_REQUEST["cap"][0] == "0") {
	if (preg_match('/\A0([1-9]\d*)a\S+\z/', $_REQUEST["cap"], $m)
	    && ($result = $Conf->qx("select paperId, capVersion from Paper where paperId=$m[1]"))
	    && ($row = edb_orow($result))) {
	    $rowcap = $Conf->capabilityText($row, "a");
	    if ($_REQUEST["cap"] == $rowcap || str_replace("/", "_", $_REQUEST["cap"]) == $rowcap)
		$Me->changeCapability($m[1], Contact::CAP_AUTHORVIEW, true);
	}
    }
    if (isset($Opt["testCapabilities"]) && $Opt["testCapabilities"]
	&& isset($_REQUEST["testcap"])) {
	if (preg_match_all('/([-+]?)([1-9]\d*)([A-Za-z]+)/',
			   $_REQUEST["testcap"], $m, PREG_SET_ORDER))
	    foreach ($m as $mm) {
		$c = ($mm[3] == "a" ? Contact::CAP_AUTHORVIEW : 0);
		$Me->changeCapability($mm[2], $c, $mm[1] != "-");
	    }
    }
}

setCapabilities();


// Set conference key
function setConferenceKey() {
    global $Conf;
    if (($key = hotcrp_random_bytes(32))
	&& $Conf->qx("insert into Settings (name, value, data) values ('conf_key', 1, '" . sqlq($key) . "')"))
	$Conf->updateSettings();
}

if (!isset($Opt["conferenceKey"])) {
    if (!isset($Conf->settingTexts["conf_key"]))
	setConferenceKey();
    $Opt["conferenceKey"] = defval($Conf->settingTexts, "conf_key", "");
}


// Extract an error that we redirected through
if (isset($_SESSION["redirect_error"])) {
    global $Error;
    $Error = $_SESSION["redirect_error"];
    unset($_SESSION["redirect_error"]);
}

// Mark as already expired to discourage caching, but allow the browser
// to cache for history buttons
session_cache_limiter("");
header("Expires: Mon, 26 Jul 1997 05:00:00 GMT");
header("Cache-Control: private");
