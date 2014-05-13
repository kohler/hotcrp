<?php
// init.php -- HotCRP initialization (test or site)
// HotCRP is Copyright (c) 2006-2014 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

define("HOTCRP_VERSION", "2.92");

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

    require_once("$ConfSitePATH/lib/navigation.php");
    Navigation::analyze();
    if (@$ConfSiteBase === null)
        $ConfSiteBase = Navigation::site_relative();
    if (@$ConfSiteSuffix === null)
        $ConfSiteSuffix = Navigation::php_suffix();
}
set_path_variables();


// Load code
require_once("$ConfSitePATH/lib/base.php");
require_once("$ConfSitePATH/lib/redirect.php");
require_once("$ConfSitePATH/src/helpers.php");
require_once("$ConfSitePATH/src/conference.php");
require_once("$ConfSitePATH/src/contact.php");

function __autoload($class_name) {
    global $ConfSitePATH, $ConfAutoloads;
    if (!@$ConfAutoloads)
        $ConfAutoloads = array("AssignmentSet" => "src/assigners.php",
                               "CheckFormat" => "src/checkformat.php",
                               "CleanHTML" => "lib/cleanhtml.php",
                               "Column" => "lib/column.php",
                               "CommentSave" => "src/commentsave.php",
                               "Conflict" => "src/conflict.php",
                               "Countries" => "lib/countries.php",
                               "CsvGenerator" => "lib/csv.php",
                               "CsvParser" => "lib/csv.php",
                               "DocumentHelper" => "lib/documenthelper.php",
                               "Formula" => "src/formula.php",
                               "HotCRPDocument" => "src/hotcrpdocument.php",
                               "Ht" => "lib/ht.php",
                               "LoginHelper" => "lib/login.php",
                               "Mailer" => "src/mailer.php",
                               "MeetingTracker" => "src/meetingtracker.php",
                               "Message" => "lib/message.php",
                               "Mimetype" => "lib/mimetype.php",
                               "PaperActions" => "src/paperactions.php",
                               "PaperColumn" => "src/papercolumn.php",
                               "PaperInfo" => "src/paperinfo.php",
                               "PaperList" => "src/paperlist.php",
                               "PaperOption" => "src/paperoption.php",
                               "PaperRank" => "src/rank.php",
                               "PaperSearch" => "src/papersearch.php",
                               "PaperStatus" => "src/paperstatus.php",
                               "Qobject" => "lib/qobject.php",
                               "ReviewForm" => "src/review.php",
                               "S3Document" => "lib/s3document.php",
                               "Tagger" => "lib/tagger.php",
                               "Text" => "lib/text.php",
                               "UnicodeHelper" => "lib/unicodehelper.php",
                               "UserActions" => "src/useractions.php",
                               "XlsxGenerator" => "lib/xlsx.php",
                               "ZipDocument" => "lib/documenthelper.php");
    if (($f = @$ConfAutoloads[$class_name]))
        require_once("$ConfSitePATH/$f");
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
    // see also `cacheable.php`
    if ((@include "$ConfSitePATH/conf/options.php") !== false
        || (@include "$ConfSitePATH/conf/options.inc") !== false
        || (@include "$ConfSitePATH/Code/options.inc") !== false)
        $Opt["loaded"] = true;
    if (@$Opt["multiconference"])
        require_once("$ConfSitePATH/src/multiconference.php");
    if (@$Opt["include"])
        read_included_options($Opt["include"]);
}


// Set locale to C (so that, e.g., strtolower() on UTF-8 data doesn't explode)
setlocale(LC_COLLATE, "C");
setlocale(LC_CTYPE, "C");

// Allow lots of memory
ini_set("memory_limit", defval($Opt, "memoryLimit", "128M"));
