<?php
// init.php -- HotCRP initialization (test or site)
// Copyright (c) 2006-2018 Eddie Kohler; see LICENSE.

define("HOTCRP_VERSION", "2.102");

// All review types must be 1 digit
define("REVIEW_META", 5);
define("REVIEW_PRIMARY", 4);
define("REVIEW_SECONDARY", 3);
define("REVIEW_PC", 2);
define("REVIEW_EXTERNAL", 1);

define("CONFLICT_NONE", 0);
define("CONFLICT_PCMARK", 1); /* unused */
define("CONFLICT_AUTHORMARK", 2);
define("CONFLICT_MAXAUTHORMARK", 7);
define("CONFLICT_CHAIRMARK", 8);
define("CONFLICT_AUTHOR", 9);
define("CONFLICT_CONTACTAUTHOR", 10);

define("REV_RATINGS_PC", 0);
define("REV_RATINGS_PC_EXTERNAL", 1);
define("REV_RATINGS_NONE", 2);

define("DTYPE_SUBMISSION", 0);
define("DTYPE_FINAL", -1);
define("DTYPE_COMMENT", -2);

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
define("COMMENTTYPE_BYAUTHOR", 8);
define("COMMENTTYPE_BYSHEPHERD", 16);
define("COMMENTTYPE_ADMINONLY", 0x00000);
define("COMMENTTYPE_PCONLY", 0x10000);
define("COMMENTTYPE_REVIEWER", 0x20000);
define("COMMENTTYPE_AUTHOR", 0x30000);
define("COMMENTTYPE_VISIBILITY", 0xFFF0000);

define("TAG_REGEX_NOTWIDDLE", '[a-zA-Z@*_:.][-a-zA-Z0-9!@*_:.\/]*');
define("TAG_REGEX", '~?~?' . TAG_REGEX_NOTWIDDLE);
define("TAG_MAXLEN", 80);
define("TAG_INDEXBOUND", 2147483646);

define("CAPTYPE_RESETPASSWORD", 1);
define("CAPTYPE_CHANGEEMAIL", 2);

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
        "AbbreviationClass" => "lib/abbreviationmatcher.php",
        "AssignmentCountSet" => "src/assignmentset.php",
        "AutoassignerCosts" => "src/autoassigner.php",
        "BanalSettings" => "src/settings/s_subform.php",
        "CapabilityManager" => "src/capability.php",
        "ContactCountMatcher" => "src/papersearch.php",
        "CsvGenerator" => "lib/csv.php",
        "CsvParser" => "lib/csv.php",
        "FormatChecker" => "src/formatspec.php",
        "JsonSerializable" => "lib/json.php",
        "LoginHelper" => "lib/login.php",
        "MailPreparation" => "lib/mailer.php",
        "MimeText" => "lib/mailer.php",
        "NameInfo" => "lib/text.php",
        "NumericOrderPaperColumn" => "src/papercolumn.php",
        "Option_SearchTerm" => "src/search/st_option.php",
        "PaperInfoSet" => "src/paperinfo.php",
        "PaperOptionList" => "src/paperoption.php",
        "Review_Assigner" => "src/assignmentset.php",
        "ReviewField" => "src/review.php",
        "ReviewFieldInfo" => "src/review.php",
        "ReviewForm" => "src/review.php",
        "ReviewSearchMatcher" => "src/search/st_review.php",
        "ReviewValues" => "src/review.php",
        "SearchSplitter" => "src/papersearch.php",
        "SearchTerm" => "src/papersearch.php",
        "TagAnno" => "lib/tagger.php",
        "TagInfo" => "lib/tagger.php",
        "TagMap" => "lib/tagger.php",
        "TextPaperOption" => "src/paperoption.php",
        "XlsxGenerator" => "lib/xlsx.php"
    ];

    static $suffix_map = [
        "_api.php" => ["api_", "api"],
        "_assignmentparser.php" => ["a_", "assigners"],
        "_helptopic.php" => ["h_", "help"],
        "_listaction.php" => ["la_", "listactions"],
        "_papercolumn.php" => ["pc_", "papercolumns"],
        "_papercolumnfactory.php" => ["pc_", "papercolumns"],
        "_searchterm.php" => ["st_", "search"],
        "_settingrenderer.php" => ["s_", "settings"],
        "_settingparser.php" => ["s_", "settings"],
        "_userinfo.php" => ["u_", "userinfo"]
    ];

    static function read_main_options() {
        global $ConfSitePATH, $Opt;
        if (defined("HOTCRP_OPTIONS"))
            $files = [HOTCRP_OPTIONS];
        else
            $files = ["$ConfSitePATH/conf/options.php", "$ConfSitePATH/conf/options.inc", "$ConfSitePATH/Code/options.inc"];
        foreach ($files as $f)
            if ((@include $f) !== false) {
                $Opt["loaded"][] = $f;
                break;
            }
    }
}

spl_autoload_register(function ($class_name) {
    global $ConfSitePATH;
    $f = null;
    if (isset(SiteLoader::$map[$class_name]))
        $f = SiteLoader::$map[$class_name];
    if (!$f)
        $f = strtolower($class_name) . ".php";
    foreach (expand_includes($f, ["autoload" => true]) as $fx)
        require_once($fx);
});

require_once("$ConfSitePATH/lib/base.php");
require_once("$ConfSitePATH/lib/redirect.php");
require_once("$ConfSitePATH/lib/dbl.php");
require_once("$ConfSitePATH/src/helpers.php");
require_once("$ConfSitePATH/src/conference.php");
require_once("$ConfSitePATH/src/contact.php");


// Set locale to C (so that, e.g., strtolower() on UTF-8 data doesn't explode)
setlocale(LC_COLLATE, "C");
setlocale(LC_CTYPE, "C");

// Don't want external entities parsed by default
if (function_exists("libxml_disable_entity_loader"))
    libxml_disable_entity_loader(true);


// Set up conference options (also used in mailer.php)
function expand_includes_once($file, $includepath, $globby) {
    foreach ($file[0] === "/" ? [""] : $includepath as $idir) {
        $try = $idir . $file;
        if (!$globby && is_readable($try))
            return [$try];
        else if ($globby && ($m = glob($try, GLOB_BRACE)))
            return $m;
    }
    return [];
}

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
        $matches = expand_includes_once($f, $includepath, $globby);
        if (empty($matches)
            && isset($expansions["autoload"])
            && ($underscore = strpos($f, "_"))
            && ($f2 = get(SiteLoader::$suffix_map, substr($f, $underscore)))) {
            $xincludepath = array_merge($f2[1] ? ["{$ConfSitePATH}/src/{$f2[1]}/"] : [], $includepath);
            $matches = expand_includes_once($f2[0] . substr($f, 0, $underscore) . ".php", $xincludepath, $globby);
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
    for ($i = 0; $i != count($files); ++$i)
        foreach (expand_includes($files[$i]) as $f) {
            $key = "missing";
            if ((@include $f) !== false)
                $key = "loaded";
            $Opt[$key][] = $f;
        }
}

function expand_json_includes_callback($includelist, $callback) {
    $includes = [];
    foreach (is_array($includelist) ? $includelist : [$includelist] as $k => $str) {
        $expandable = null;
        if (is_string($str)) {
            if (str_starts_with($str, "@"))
                $expandable = substr($str, 1);
            else if (!preg_match('/\A[\s\[\{]/', $str)
                     || ($str[0] === "[" && !preg_match('/\]\s*\z/', $str)))
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
            $x = json_decode($entry);
            if ($x === null && json_last_error()) {
                $x = Json::decode($entry);
                if ($x === null)
                    error_log("$landmark: Invalid JSON: " . Json::last_error_msg());
            }
            if ($x === null)
                continue;
            $entry = $x;
        }
        foreach (is_array($entry) ? $entry : [$entry] as $k => $v) {
            if (is_object($v))
                $v->__subposition = ++Conf::$next_xt_subposition;
            if (!call_user_func($callback, $v, $k, $landmark))
                error_log("$landmark: Invalid expansion " . json_encode($v) . ".");
        }
    }
}

global $Opt;
if (!$Opt)
    $Opt = array();
if (!get($Opt, "loaded")) {
    SiteLoader::read_main_options();
    if (get($Opt, "multiconference"))
        Multiconference::init();
    if (get($Opt, "include"))
        read_included_options($Opt["include"]);
}
if (!get($Opt, "loaded") || get($Opt, "missing"))
    Multiconference::fail_bad_options();
if (get($Opt, "dbLogQueries"))
    Dbl::log_queries($Opt["dbLogQueries"], get($Opt, "dbLogQueryFile"));


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
