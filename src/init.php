<?php
// init.php -- HotCRP initialization (test or site)
// HotCRP is Copyright (c) 2006-2014 Eddie Kohler and Regents of the UC
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

global $ReviewFormCache;
$ReviewFormCache = null;
global $CurrentList;
$CurrentList = 0;

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


// set $ConfSitePATH (path to conference site), $ConfSiteBase, and $ConfSiteSuffix
function set_path_variables() {
    global $ConfSitePATH, $ConfSiteBase, $ConfSiteSuffix;

    if (!@$ConfSitePATH) {
        $ConfSitePATH = substr(__FILE__, 0, strrpos(__FILE__, "/"));
        while ($ConfSitePATH !== "" && !file_exists("$ConfSitePATH/src/init.php"))
            $ConfSitePATH = substr($ConfSitePATH, 0, strrpos($ConfSitePATH, "/"));
        if ($ConfSitePATH === "")
            $ConfSitePATH = "/var/www/html";
    }

    if (@$ConfSiteBase === null) {
        if (@$_SERVER["PATH_INFO"])
            $ConfSiteBase = str_repeat("../", substr_count($_SERVER["PATH_INFO"], "/"));
        else
            $ConfSiteBase = "";
    }

    if (@$ConfSiteSuffix === null) {
        $ConfSiteSuffix = ".php";
        if (function_exists("apache_get_modules")
            && array_search("mod_rewrite", apache_get_modules()) !== false)
            $ConfSiteSuffix = "";
    }
}
set_path_variables();


// Load code
require_once("$ConfSitePATH/lib/base.php");
require_once("$ConfSitePATH/lib/redirect.php");
require_once("$ConfSitePATH/src/helpers.php");
require_once("$ConfSitePATH/src/conference.php");
require_once("$ConfSitePATH/src/contact.php");

function __autoload($class_name) {
    global $ConfSitePATH;
    if ($class_name == "ReviewForm")
	require_once("$ConfSitePATH/src/review.php");
    else if ($class_name == "PaperInfo")
        require_once("$ConfSitePATH/src/paperinfo.php");
    else if ($class_name == "PaperSearch")
        require_once("$ConfSitePATH/src/papersearch.php");
    else if ($class_name == "PaperActions")
        require_once("$ConfSitePATH/src/paperactions.php");
    else if ($class_name == "PaperStatus")
        require_once("$ConfSitePATH/src/paperstatus.php");
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
    else if ($class_name == "Conflict")
        require_once("$ConfSitePATH/src/conflict.php");
    else if ($class_name == "MeetingTracker")
        require_once("$ConfSitePATH/src/meetingtracker.php");
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
    else if ($class_name == "S3Document")
        require_once("$ConfSitePATH/lib/s3document.php");
    else if ($class_name == "AssignmentSet")
        require_once("$ConfSitePATH/src/assigners.php");
    else if ($class_name == "CommentSave")
        require_once("$ConfSitePATH/src/commentsave.php");
    else if ($class_name == "Ht")
        require_once("$ConfSitePATH/lib/ht.php");
}


// Set up conference options
function read_included_options($files) {
    global $Opt, $ConfMulticonf, $ConfSitePATH;
    if (is_string($files))
        $files = array($files);
    $confname = @$ConfMulticonf ? $ConfMulticonf : @$Opt["dbName"];
    $cwd = null;
    foreach ($files as $f) {
        $f = preg_replace(',\$\{confname\}|\$confname\b,', $confname, $f);
        if (preg_match(',[\[\]\*\?],', $f)) {
            if ($cwd === null) {
                $cwd = getcwd();
                if (!chdir($ConfSitePATH)) {
                    $Opt["missing"][] = $f;
                    break;
                }
            }
            $flist = glob($f, GLOB_BRACE);
        } else
            $flist = array($f);
        foreach ($flist as $f) {
            $f = ($f[0] == "/" ? $f : "$ConfSitePATH/$f");
            if (!@include $f)
                $Opt["missing"][] = $f;
        }
    }
}

global $Opt;
if (!@$Opt)
    $Opt = array();
if (!@$Opt["loaded"]) {
    if ((@include "$ConfSitePATH/conf/options.php") !== false // see also `cacheable.php`
        || (@include "$ConfSitePATH/conf/options.inc") !== false
        || (@include "$ConfSitePATH/Code/options.inc") !== false)
        $Opt["loaded"] = true;
    if (@$Opt["multiconference"])
        require_once("$ConfSitePATH/src/multiconference.php");
    if (@$Opt["include"])
        read_included_options($Opt["include"]);
}


// Set timezone
if (function_exists("date_default_timezone_set")) {
    if (isset($Opt["timezone"]))
        date_default_timezone_set($Opt["timezone"]);
    else if (!ini_get("date.timezone") && !getenv("TZ"))
        date_default_timezone_set("America/New_York");
}

// Set locale to C (so that, e.g., strtolower() on UTF-8 data doesn't explode)
setlocale(LC_COLLATE, "C");
setlocale(LC_CTYPE, "C");

// Allow lots of memory
ini_set("memory_limit", defval($Opt, "memoryLimit", "128M"));
