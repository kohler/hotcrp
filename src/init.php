<?php
// init.php -- HotCRP initialization (test or site)
// HotCRP is Copyright (c) 2006-2017 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

define("HOTCRP_VERSION", "2.100");

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
define("WATCHSHIFT_ISSET", 0);
// Notify if author, reviewer, commenter
define("WATCHSHIFT_ON", 1);
// Always notify (only in ContactInfo.defaultWatch, generally admin only)
define("WATCHSHIFT_ALLON", 2);

define("WATCHTYPE_COMMENT", (1 << 0));
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

define("TAG_REGEX_NOTWIDDLE", '[a-zA-Z@*_:.][-a-zA-Z0-9!@*_:.\/]*');
define("TAG_REGEX", '~?~?' . TAG_REGEX_NOTWIDDLE);
define("TAG_MAXLEN", 40);
define("TAG_INDEXBOUND", 2147483646);

define("CAPTYPE_RESETPASSWORD", 1);
define("CAPTYPE_CHANGEEMAIL", 2);

define("ALWAYS_OVERRIDE", 9999);

global $Now, $ConfSitePATH;
$Now = time();
$ConfSitePATH = null;


// set $ConfSitePATH (path to conference site)
function set_path_variables() {
    global $ConfSitePATH;
    if (!isset($ConfSitePATH)) {
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
        "AutoassignerCosts" => "src/autoassigner.php",
        "BanalSettings" => "src/settings/s_subform.php",
        "CapabilityManager" => "src/capability.php",
        "ColumnErrors" => "lib/column.php",
        "CsvGenerator" => "lib/csv.php",
        "CsvParser" => "lib/csv.php",
        "FormatChecker" => "src/formatspec.php",
        "Formula_PaperColumn" => "src/papercolumn.php",
        "JsonSerializable" => "lib/json.php",
        "LoginHelper" => "lib/login.php",
        "MailPreparation" => "lib/mailer.php",
        "MimeText" => "lib/mailer.php",
        "NameInfo" => "lib/text.php",
        "NumericOrderPaperColumn" => "src/papercolumn.php",
        "PaperInfoSet" => "src/paperinfo.php",
        "PaperOptionList" => "src/paperoption.php",
        "ReviewAssigner" => "src/assigners.php",
        "ReviewField" => "src/review.php",
        "ReviewForm" => "src/review.php",
        "ReviewSearchMatcher" => "src/papersearch.php",
        "TagInfo" => "lib/tagger.php",
        "TagMap" => "lib/tagger.php",
        "TextPaperOption" => "src/paperoption.php",
        "XlsxGenerator" => "lib/xlsx.php",
        "ZipDocument" => "lib/filer.php"
    ];
    const API_POST = 0;
    const API_GET = 1;
    const API_PAPER = 2;
    const API_GET_PAPER = 3 /* == API_GET | API_PAPER */;
}

function __autoload($class_name) {
    global $ConfSitePATH;
    $f = null;
    if (isset(SiteLoader::$map[$class_name]))
        $f = SiteLoader::$map[$class_name];
    if (!$f)
        $f = strtolower($class_name) . ".php";
    foreach (expand_includes($f, ["autoload" => true]) as $fx)
        require_once($fx);
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
function expand_includes($files, $expansions = array()) {
    global $Opt, $ConfSitePATH;
    if (!is_array($files))
        $files = array($files);
    $confname = get($Opt, "confid") ? : get($Opt, "dbName");
    $expansions["confid"] = $expansions["confname"] = $confname;
    $expansions["siteclass"] = get($Opt, "siteclass");

    if (isset($expansions["autoload"]) && strpos($files[0], "/") === false)
        $includepath = [$ConfSitePATH . "/src/", $ConfSitePATH . "/lib/"];
    else
        $includepath = [$ConfSitePATH . "/"];
    if (isset($Opt["includepath"]) && is_array($Opt["includepath"])) {
        foreach ($Opt["includepath"] as $i)
            if ($i)
                $includepath[] = str_ends_with($i, "/") ? $i : $i . "/";
    }

    $results = array();
    foreach ($files as $f) {
        if (strpos((string) $f, '$') !== false) {
            foreach ($expansions as $k => $v)
                if ($v !== false && $v !== null)
                    $f = preg_replace(',\$\{' . $k . '\}|\$' . $k . '\b,', $v, $f);
                else if (preg_match(',\$\{' . $k . '\}|\$' . $k . '\b,', $f)) {
                    $f = "";
                    break;
                }
        }
        if ((string) $f === "")
            continue;
        $matches = [];
        $ignore_not_found = $globby = false;
        if (str_starts_with($f, "?")) {
            $ignore_not_found = true;
            $f = substr($f, 1);
        }
        if (preg_match(',[\[\]\*\?\{\}],', $f))
            $ignore_not_found = $globby = true;
        foreach ($f[0] === "/" ? array("") : $includepath as $idir) {
            $e = $idir . $f;
            if ($globby)
                $matches = glob($f, GLOB_BRACE);
            else if (is_readable($e))
                $matches = [$e];
            if (!empty($matches))
                break;
        }
        $results = array_merge($results, $matches);
        if (empty($matches) && !$ignore_not_found)
            $results[] = $f[0] === "/" ? $f : $includepath[0] . $f;
    }
    return $results;
}

function read_included_options(&$files) {
    global $Opt;
    if (is_string($files))
        $files = [$files];
    for ($i = 0; $i != count($files); ++$i) {
        foreach (expand_includes($files[$i]) as $f)
            if (!@include $f)
                $Opt["missing"][] = $f;
    }
}

function expand_json_includes_callback($includelist, $callback, $extra_arg = null, $no_validate = false) {
    $includes = [];
    foreach (is_array($includelist) ? $includelist : [$includelist] as $k => $str) {
        $expandable = null;
        if (is_string($str)) {
            if (str_starts_with($str, "@"))
                $expandable = substr($str, 1);
            else if (!preg_match('/\A[\s\[\{]/', $str))
                $expandable = $str;
        }
        if ($expandable) {
            foreach (expand_includes($expandable) as $f)
                if (($x = file_get_contents($f)))
                    $includes[] = [$x, $f];
        } else
            $includes[] = [$str, "entry $k"];
    }
    foreach ($includes as $xentry) {
        list($entry, $landmark) = $xentry;
        if (is_string($entry)) {
            if (($x = json_decode($entry)) !== false)
                $entry = $x;
            else {
                if (json_last_error()) {
                    Json::decode($entry);
                    error_log("$landmark: Invalid JSON. " . Json::last_error_msg());
                }
                continue;
            }
        }
        if (is_object($entry) && !$no_validate
            && !isset($entry->id) && !isset($entry->factory) && !isset($entry->factory_class) && !isset($entry->callback))
            $entry = get_object_vars($entry);
        foreach (is_array($entry) ? $entry : [$entry] as $key => $obj) {
            $arg = $extra_arg === null ? $key : $extra_arg;
            if ((!is_object($obj) && !$no_validate)
                || !call_user_func($callback, $obj, $arg))
                error_log("$landmark: Invalid expansion " . json_encode($obj) . ".");
        }
    }
}

global $Opt;
if (!$Opt)
    $Opt = array();
if (!get($Opt, "loaded")) {
    if (defined("HOTCRP_OPTIONS")) {
        if ((@include HOTCRP_OPTIONS) !== false)
            $Opt["loaded"] = true;
    } else if ((@include "$ConfSitePATH/conf/options.php") !== false
               || (@include "$ConfSitePATH/conf/options.inc") !== false
               || (@include "$ConfSitePATH/Code/options.inc") !== false)
        $Opt["loaded"] = true;
    if (get($Opt, "multiconference"))
        Multiconference::init();
    if (get($Opt, "include"))
        read_included_options($Opt["include"]);
}
if (!get($Opt, "loaded") || get($Opt, "missing"))
    Multiconference::fail_bad_options();
if (get($Opt, "dbLogQueries"))
    Dbl::log_queries($Opt["dbLogQueries"]);


// Allow lots of memory
if (!get($Opt, "memoryLimit") && ini_get_bytes("memory_limit") < (128 << 20))
    $Opt["memoryLimit"] = "128M";
if (get($Opt, "memoryLimit"))
    ini_set("memory_limit", $Opt["memoryLimit"]);


// Create the conference
global $Conf;
if (!$Conf)
    $Conf = Conf::$g = new Conf($Opt, true);
if (!$Conf->dblink)
    Multiconference::fail_bad_database();
