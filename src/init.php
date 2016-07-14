<?php
// init.php -- HotCRP initialization (test or site)
// HotCRP is Copyright (c) 2006-2016 Eddie Kohler and Regents of the UC
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
        "ContactSearch" => "src/papersearch.php",
        "CountMatcher" => "src/papersearch.php",
        "CsvGenerator" => "lib/csv.php",
        "CsvParser" => "lib/csv.php",
        "FormatChecker" => "src/formatspec.php",
        "FormulaPaperColumn" => "src/papercolumn.php",
        "JsonSerializable" => "lib/json.php",
        "LoginHelper" => "lib/login.php",
        "MailPreparation" => "lib/mailer.php",
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
    const API_GET_PAPER = 3 /* == API_GET | API_PAPER */;
    const API_REDIRECTABLE = 4;
    static $api_map = [
        "alltags" => ["PaperApi::alltags_api", self::API_GET],
        "checkformat" => ["PaperApi::checkformat_api", self::API_GET_PAPER],
        "setdecision" => ["PaperApi::setdecision_api", self::API_PAPER],
        "setlead" => ["PaperApi::setlead_api", self::API_PAPER],
        "setmanager" => ["PaperApi::setmanager_api", self::API_PAPER],
        "setpref" => ["PaperApi::setpref_api", self::API_PAPER],
        "setshepherd" => ["PaperApi::setshepherd_api", self::API_PAPER],
        "settaganno" => ["PaperApi::settaganno_api", self::API_POST],
        "settags" => ["PaperApi::settags_api", self::API_POST],
        "taganno" => ["PaperApi::taganno_api", self::API_GET],
        "tagreport" => ["PaperApi::tagreport_api", self::API_GET],
        "trackerstatus" => ["MeetingTracker::trackerstatus_api", self::API_GET], // hotcrp-comet entrypoint
        "votereport" => ["PaperApi::votereport_api", self::API_GET_PAPER],
        "whoami" => ["PaperApi::whoami_api", self::API_GET]
    ];
    static private $api_map_populated = false;
    static private function call_api($uf, $user, $qreq, $prow) {
        if ($uf) {
            if (!check_post($qreq)
                && !(is_array($uf) ? $uf[1] & self::API_GET : get($uf, "get")))
                json_exit(["ok" => false, "error" => "Missing credentials."]);
            if (!$prow
                && (is_array($uf) ? $uf[1] & self::API_PAPER : get($uf, "paper")))
                json_exit(["ok" => false, "error" => "No such paper."]);
            $fn = is_array($uf) ? $uf[0] : $uf->callback;
            call_user_func($fn, $user, $qreq, $prow, $uf);
        }
        json_exit(["ok" => false, "error" => "Internal error."]);
    }
    static public function _add_json($fj) {
        if (is_string($fj->fn) && !isset(self::$api_map[$fj->fn])
            && isset($fj->callback)) {
            self::$api_map[$fj->fn] = $fj;
            return true;
        } else
            return false;
    }
    static public function has_api($fn) {
        if (isset(self::$api_map[$fn]))
            return true;
        if (!self::$api_map_populated && ($olist = opt("apiFunctions"))) {
            expand_json_includes_callback($olist, "SiteLoader::_add_json");
            self::$api_map_populated = true;
            return isset(self::$api_map[$fn]);
        } else
            return false;
    }
    static public function call_api_exit($fn, $user, $qreq, $prow) {
        // XXX precondition: $user->can_view_paper($prow) || !$prow
        $uf = get(SiteLoader::$api_map, $fn);
        if (!$uf && self::has_api($fn))
            $uf = get(SiteLoader::$api_map, $fn);
        if (is_object($uf) && get($uf, "redirect") && $qreq->redirect
            && preg_match('@\A(?![a-z]+:|/).+@', $qreq->redirect)) {
            try {
                JsonResultException::$capturing = true;
                self::call_api($uf, $user, $qreq, $prow);
            } catch (JsonResultException $ex) {
                $j = $ex->result;
                if (!get($j, "ok") && !get($j, "error"))
                    Conf::msg_error("Internal error.");
                else if (($x = get($j, "error")))
                    Conf::msg_error(htmlspecialchars($x));
                else if (($x = get($j, "error_html")))
                    Conf::msg_error($x);
                Navigation::redirect_site($qreq->redirect);
            }
        } else
            self::call_api($uf, $user, $qreq, $prow);
    }
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
        $globby = preg_match(',[\[\]\*\?\{\}],', $f);
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
        if (empty($matches) && !$globby)
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

function expand_json_includes_callback($includelist, $callback, $extra_arg = null) {
    $includes = [];
    foreach (is_array($includelist) ? $includelist : [$includelist] as $k => $str)
        if (is_string($str) && str_starts_with($str, "@")) {
            foreach (expand_includes(substr($str, 1)) as $f)
                if (($x = file_get_contents($f)))
                    $includes[] = [$x, $f];
        } else
            $includes[] = [$str, "entry $k"];
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
        if (is_object($entry) && !isset($entry->id) && !isset($entry->factory)
            && !isset($entry->factory_class) && !isset($entry->callback))
            $entry = get_object_vars($entry);
        foreach (is_array($entry) ? $entry : [$entry] as $obj)
            if (!is_object($obj) || !call_user_func($callback, $obj, $extra_arg))
                error_log("$landmark: Invalid $callback " . json_encode($obj) . ".");
    }
}

global $Opt, $OptOverride;
if (!$Opt)
    $Opt = array();
if (!$OptOverride)
    $OptOverride = array();
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
function set_memory_limit() {
    global $Opt;
    if (!get($Opt, "memoryLimit")) {
        $suf = array("" => 1, "k" => 1<<10, "m" => 1<<20, "g" => 1<<30);
        if (preg_match(',\A(\d+)\s*([kmg]?)\z,', strtolower(ini_get("memory_limit")), $m)
            && $m[1] * $suf[$m[2]] < (128<<20))
            $Opt["memoryLimit"] = "128M";
    }
    if (get($Opt, "memoryLimit"))
        ini_set("memory_limit", $Opt["memoryLimit"]);
}
set_memory_limit();


// Create the conference
global $Conf;
if (!$Conf)
    $Conf = Conf::$g = new Conf(Dbl::make_dsn($Opt));
if (!$Conf->dblink)
    Multiconference::fail_bad_database();
