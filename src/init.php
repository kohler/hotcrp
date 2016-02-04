<?php
// init.php -- HotCRP initialization (test or site)
// HotCRP is Copyright (c) 2006-2016 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

define("HOTCRP_VERSION", "2.99");

// All review types must be 1 digit
define("REVIEW_PRIMARY", 4);
define("REVIEW_SECONDARY", 3);
define("REVIEW_PC", 2);
define("REVIEW_EXTERNAL", 1);

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
define("VIEWSCORE_AUTHORDEC", 1);
define("VIEWSCORE_AUTHOR", 2);
define("VIEWSCORE_MAX", 3);

define("COMMENTTYPE_DRAFT", 1);
define("COMMENTTYPE_BLIND", 2);
define("COMMENTTYPE_RESPONSE", 4);
define("COMMENTTYPE_ADMINONLY", 0x00000);
define("COMMENTTYPE_PCONLY", 0x10000);
define("COMMENTTYPE_REVIEWER", 0x20000);
define("COMMENTTYPE_AUTHOR", 0x30000);
define("COMMENTTYPE_VISIBILITY", 0xFFF0000);

define("TAG_REGEX_NOTWIDDLE", '[a-zA-Z!@*_:.][-a-zA-Z0-9!@*_:.\/]*');
define("TAG_REGEX", '~?~?' . TAG_REGEX_NOTWIDDLE);
define("TAG_MAXLEN", 40);

define("CAPTYPE_RESETPASSWORD", 1);
define("CAPTYPE_CHANGEEMAIL", 2);

define("ALWAYS_OVERRIDE", 9999);

global $OK, $Now, $ConfSitePATH;
$OK = 1;
$Now = time();
$ConfSitePATH = null;


// set $ConfSitePATH (path to conference site)
function set_path_variables() {
    global $ConfSitePATH;
    if (!@$ConfSitePATH) {
        $ConfSitePATH = substr(__FILE__, 0, strrpos(__FILE__, "/"));
        while ($ConfSitePATH !== "" && !file_exists("$ConfSitePATH/src/init.php"))
            $ConfSitePATH = substr($ConfSitePATH, 0, strrpos($ConfSitePATH, "/"));
        if ($ConfSitePATH === "")
            $ConfSitePATH = "/var/www/html";
    }
    require_once("$ConfSitePATH/lib/navigation.php");
}
set_path_variables();


// Load code
class SiteLoader {
    static $map = [
        "AssignmentSet" => "src/assigners.php",
        "CapabilityManager" => "src/capability.php",
        "ColumnErrors" => "lib/column.php",
        "ContactSearch" => "src/papersearch.php",
        "CsvGenerator" => "lib/csv.php",
        "CsvParser" => "lib/csv.php",
        "FormulaPaperColumn" => "src/papercolumn.php",
        "JsonSerializable" => "lib/json.php",
        "LoginHelper" => "lib/login.php",
        "MimeText" => "lib/mailer.php",
        "NumericOrderPaperColumn" => "src/papercolumn.php",
        "ReviewAssigner" => "src/assigners.php",
        "ReviewField" => "src/review.php",
        "ReviewForm" => "src/review.php",
        "ReviewSearchMatcher" => "src/papersearch.php",
        "TagInfo" => "lib/tagger.php",
        "TextPaperOption" => "src/paperoption.php",
        "XlsxGenerator" => "lib/xlsx.php",
        "ZipDocument" => "lib/filer.php"
    ];
    const API_POST = 0;
    const API_GET = 1;
    const API_PAPER = 2;
    static $api_map = [
        "alltags" => ["PaperApi::alltags_api", self::API_GET],
        "setdecision" => ["PaperApi::setdecision_api", self::API_PAPER],
        "setlead" => ["PaperApi::setlead_api", self::API_PAPER],
        "setmanager" => ["PaperApi::setmanager_api", self::API_PAPER],
        "setpref" => ["PaperApi::setpref_api", self::API_PAPER],
        "setshepherd" => ["PaperApi::setshepherd_api", self::API_PAPER],
        "settags" => ["PaperApi::settags_api", self::API_PAPER],
        "tagreport" => ["PaperApi::tagreport_api", self::API_GET],
        "trackerstatus" => ["MeetingTracker::trackerstatus_api", self::API_GET] // hotcrp-comet entrypoint
    ];
}

function __autoload($class_name) {
    global $ConfSitePATH;
    $f = null;
    if (isset(SiteLoader::$map[$class_name]))
        $f = SiteLoader::$map[$class_name];
    if (!$f) {
        $l = strtolower($class_name);
        if (file_exists("$ConfSitePATH/src/$l.php"))
            $f = "src/$l.php";
        else if (file_exists("$ConfSitePATH/lib/$l.php"))
            $f = "lib/$l.php";
    }
    if ($f)
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
if (@$Opt["dbLogQueries"])
    Dbl::log_queries(@$Opt["dbLogQueries"]);


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
if (!$Conf)
    $Conf = Conf::$g = new Conf(Dbl::make_dsn($Opt));
if (!$Conf->dblink)
    Multiconference::fail_bad_database();
