<?php
// init.php -- HotCRP initialization (test or site)
// HotCRP is Copyright (c) 2006-2015 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

define("HOTCRP_VERSION", "2.94");

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

define("OPTIONTYPE_CHECKBOX", 0);
define("OPTIONTYPE_SELECTOR", 1); /* see also script.js:doopttype */
define("OPTIONTYPE_NUMERIC", 2);
define("OPTIONTYPE_TEXT", 3);
define("OPTIONTYPE_PDF", 4);    /* order matters */
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
define("TAG_MAXLEN", 40);

define("CAPTYPE_RESETPASSWORD", 1);
define("CAPTYPE_CHANGEEMAIL", 2);

define("ALWAYS_OVERRIDE", 9999);

global $reviewScoreNames;
$reviewScoreNames = array("overAllMerit", "technicalMerit", "novelty",
                          "grammar", "reviewerQualification", "potential",
                          "fixability", "interestToCommunity", "longevity",
                          "likelyPresentation", "suitableForShort");

global $OK, $Now, $CurrentList, $CurrentProw;
$OK = 1;
$Now = time();
$CurrentList = 0;
$CurrentProw = null;

global $allowedSessionVars;
$allowedSessionVars = array("foldpapera", "foldpaperp", "foldpaperb",
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
    require_once("$ConfSitePATH/lib/navigation.php");
    Navigation::analyze();
    if (@$ConfSiteBase === null)
        $ConfSiteBase = Navigation::siteurl();
    if (@$ConfSiteSuffix === null)
        $ConfSiteSuffix = Navigation::php_suffix();
}
set_path_variables();


// Load code
function __autoload($class_name) {
    global $ConfSitePATH, $ConfAutoloads;
    if (!@$ConfAutoloads)
        $ConfAutoloads = array("AssignmentSet" => "src/assigners.php",
                               "CapabilityManager" => "src/capability.php",
                               "CheckFormat" => "src/checkformat.php",
                               "CleanHTML" => "lib/cleanhtml.php",
                               "Column" => "lib/column.php",
                               "CommentInfo" => "src/commentinfo.php",
                               "CommentViewState" => "src/commentinfo.php",
                               "Conflict" => "src/conflict.php",
                               "ContactSearch" => "src/papersearch.php",
                               "Countries" => "lib/countries.php",
                               "CsvGenerator" => "lib/csv.php",
                               "CsvParser" => "lib/csv.php",
                               "DocumentHelper" => "lib/documenthelper.php",
                               "Formula" => "src/formula.php",
                               "FormulaPaperColumn" => "src/papercolumn.php",
                               "HotCRPDocument" => "src/hotcrpdocument.php",
                               "HotCRPMailer" => "src/hotcrpmailer.php",
                               "Ht" => "lib/ht.php",
                               "LoginHelper" => "lib/login.php",
                               "Mailer" => "lib/mailer.php",
                               "MeetingTracker" => "src/meetingtracker.php",
                               "Message" => "lib/message.php",
                               "MimeText" => "lib/mailer.php",
                               "Mimetype" => "lib/mimetype.php",
                               "Multiconference" => "src/multiconference.php",
                               "PaperActions" => "src/paperactions.php",
                               "PaperColumn" => "src/papercolumn.php",
                               "PaperColumnErrors" => "src/papercolumn.php",
                               "PaperInfo" => "src/paperinfo.php",
                               "PaperList" => "src/paperlist.php",
                               "PaperOption" => "src/paperoption.php",
                               "PaperRank" => "src/rank.php",
                               "PaperSearch" => "src/papersearch.php",
                               "PaperStatus" => "src/paperstatus.php",
                               "PaperTable" => "src/papertable.php",
                               "Qobject" => "lib/qobject.php",
                               "ReviewForm" => "src/review.php",
                               "ReviewTimes" => "src/reviewtimes.php",
                               "S3Document" => "lib/s3document.php",
                               "ScoreInfo" => "lib/scoreinfo.php",
                               "SearchActions" => "src/searchactions.php",
                               "TagInfo" => "lib/tagger.php",
                               "Tagger" => "lib/tagger.php",
                               "Text" => "lib/text.php",
                               "UnicodeHelper" => "lib/unicodehelper.php",
                               "UserActions" => "src/useractions.php",
                               "UserStatus" => "src/userstatus.php",
                               "XlsxGenerator" => "lib/xlsx.php",
                               "ZipDocument" => "lib/documenthelper.php");
    if (($f = @$ConfAutoloads[$class_name]))
        require_once("$ConfSitePATH/$f");
}

require_once("$ConfSitePATH/lib/base.php");
require_once("$ConfSitePATH/lib/redirect.php");
require_once("$ConfSitePATH/lib/dbl.php");
require_once("$ConfSitePATH/src/helpers.php");
require_once("$ConfSitePATH/src/conference.php");
require_once("$ConfSitePATH/src/contact.php");


// Set locale to C (so that, e.g., strtolower() on UTF-8 data doesn't explode)
setlocale(LC_COLLATE, "C");
setlocale(LC_CTYPE, "C");


// Set up conference options (also used in mailer.php)
function expand_includes($sitedir, $files, $expansions = array()) {
    global $Opt;
    if (is_string($files))
        $files = array($files);
    $confname = @$Opt["confid"] ? : @$Opt["dbName"];
    $results = array();
    $cwd = null;
    foreach ($files as $f) {
        if (strpos($f, '$') !== false) {
            $f = preg_replace(',\$\{conf(?:id|name)\}|\$conf(?:id|name)\b,', $confname, $f);
            foreach ($expansions as $k => $v)
                if ($v !== false && $v !== null)
                    $f = preg_replace(',\$\{' . $k . '\}|\$' . $k . '\b,', $v, $f);
                else if (preg_match(',\$\{' . $k . '\}|\$' . $k . '\b,', $f)) {
                    $f = false;
                    break;
                }
        }
        if ($f === false)
            /* skip */;
        else if (preg_match(',[\[\]\*\?],', $f)) {
            if ($cwd === null) {
                $cwd = getcwd();
                chdir($sitedir);
            }
            foreach (glob($f, GLOB_BRACE) as $x)
                $results[] = $x;
        } else
            $results[] = $f;
    }
    foreach ($results as &$f)
        $f = ($f[0] == "/" ? $f : "$sitedir/$f");
    if ($cwd)
        chdir($cwd);
    return $results;
}

function read_included_options($sitedir, $files) {
    global $Opt;
    foreach (expand_includes($sitedir, $files) as $f)
        if (!@include $f)
            $Opt["missing"][] = $f;
}

global $Opt, $OptOverride;
if (!@$Opt)
    $Opt = array();
if (!@$OptOverride)
    $OptOverride = array();
if (!@$Opt["loaded"]) {
    if (defined("HOTCRP_OPTIONS")) {
        if ((@include HOTCRP_OPTIONS) !== false)
            $Opt["loaded"] = true;
    } else if ((@include "$ConfSitePATH/conf/options.php") !== false
               || (@include "$ConfSitePATH/conf/options.inc") !== false
               || (@include "$ConfSitePATH/Code/options.inc") !== false)
        $Opt["loaded"] = true;
    if (@$Opt["multiconference"])
        Multiconference::init();
    if (@$Opt["include"])
        read_included_options($ConfSitePATH, $Opt["include"]);
}
if (!@$Opt["loaded"] || @$Opt["missing"])
    Multiconference::fail_bad_options();


// Allow lots of memory
function set_memory_limit() {
    global $Opt;
    if (!@$Opt["memoryLimit"]) {
        $suf = array("" => 1, "k" => 1<<10, "m" => 1<<20, "g" => 1<<30);
        if (preg_match(',\A(\d+)\s*([kmg]?)\z,', strtolower(ini_get("memory_limit")), $m)
            && $m[1] * $suf[$m[2]] < (128<<20))
            $Opt["memoryLimit"] = "128M";
    }
    if (@$Opt["memoryLimit"])
        ini_set("memory_limit", $Opt["memoryLimit"]);
}
set_memory_limit();


// Create the conference
global $Conf;
if (!@$Conf)
    $Conf = new Conference(Dbl::make_dsn($Opt));
if (!$Conf->dblink)
    Multiconference::fail_bad_database();
