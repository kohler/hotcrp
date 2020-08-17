<?php
// init.php -- HotCRP initialization (test or site)
// Copyright (c) 2006-2020 Eddie Kohler; see LICENSE.

declare(strict_types=1);
define("HOTCRP_VERSION", "2.102");

// All positive review types must be 1 digit
define("REVIEW_META", 5);
define("REVIEW_PRIMARY", 4);
define("REVIEW_SECONDARY", 3);
define("REVIEW_PC", 2);
define("REVIEW_EXTERNAL", 1);
define("REVIEW_REQUEST", -1);
define("REVIEW_REFUSAL", -2);

define("CONFLICT_MAXUNCONFLICTED", 1);
define("CONFLICT_PCMASK", 31);
define("CONFLICT_AUTHOR", 32);
define("CONFLICT_CONTACTAUTHOR", 64);

define("REV_RATINGS_PC", 0);
define("REV_RATINGS_PC_EXTERNAL", 1);
define("REV_RATINGS_NONE", 2);

define("DTYPE_SUBMISSION", 0);
define("DTYPE_FINAL", -1);
define("DTYPE_COMMENT", -2);
define("DTYPE_EXPORT", -3);

define("VIEWSCORE_EMPTY", -3);         // score no one can see; see also reviewViewScore
define("VIEWSCORE_ADMINONLY", -2);
define("VIEWSCORE_REVIEWERONLY", -1);
define("VIEWSCORE_PC", 0);
define("VIEWSCORE_AUTHORDEC", 1);
define("VIEWSCORE_AUTHOR", 2);
define("VIEWSCORE_EMPTYBOUND", 3);     // bound that can see nothing

define("NAME_E", 1);   // include email
define("NAME_B", 2);   // always put email in angle brackets
define("NAME_EB", 3);  // NAME_E + NAME_B
define("NAME_P", 4);   // return email or "[No name]" instead of empty string
define("NAME_L", 8);   // "last, first"
define("NAME_I", 16);  // first initials instead of first name
define("NAME_S", 32);  // "last, first" according to conference preference
define("NAME_U", 64);  // unaccented
define("NAME_MAILQUOTE", 128); // quote name by RFC822
define("NAME_A", 256); // affiliation
define("NAME_PARSABLE", 512); // `last, first` if `first last` would be ambiguous

define("COMMENTTYPE_DRAFT", 1);
define("COMMENTTYPE_BLIND", 2);
define("COMMENTTYPE_RESPONSE", 4);
define("COMMENTTYPE_BYAUTHOR", 8);
define("COMMENTTYPE_BYSHEPHERD", 16);
define("COMMENTTYPE_HASDOC", 32);
define("COMMENTTYPE_ADMINONLY", 0x00000);
define("COMMENTTYPE_PCONLY", 0x10000);
define("COMMENTTYPE_REVIEWER", 0x20000);
define("COMMENTTYPE_AUTHOR", 0x30000);
define("COMMENTTYPE_VISIBILITY", 0xFFF0000);

define("TAG_REGEX_NOTWIDDLE", '[a-zA-Z@*_:.][-+a-zA-Z0-9!@*_:.\/]*');
define("TAG_REGEX", '~?~?' . TAG_REGEX_NOTWIDDLE);
define("TAG_MAXLEN", 80);
define("TAG_INDEXBOUND", 2147483646);

global $Conf, $Now, $ConfSitePATH;

require_once("siteloader.php");
require_once(SiteLoader::find("lib/navigation.php"));
require_once(SiteLoader::find("lib/polyfills.php"));
require_once(SiteLoader::find("lib/base.php"));
require_once(SiteLoader::find("lib/redirect.php"));
require_once(SiteLoader::find("lib/dbl.php"));
require_once(SiteLoader::find("src/helpers.php"));
require_once(SiteLoader::find("src/conference.php"));
require_once(SiteLoader::find("src/contact.php"));
Conf::set_current_time(time());


// Set locale to C (so that, e.g., strtolower() on UTF-8 data doesn't explode)
setlocale(LC_COLLATE, "C");
setlocale(LC_CTYPE, "C");

// Don't want external entities parsed by default
if (function_exists("libxml_disable_entity_loader")) {
    libxml_disable_entity_loader(true);
}


function read_included_options(&$files) {
    global $Opt;
    if (is_string($files)) {
        $files = [$files];
    }
    for ($i = 0; $i !== count($files); ++$i) {
        foreach (SiteLoader::expand_includes($files[$i]) as $f) {
            $key = "missing";
            if ((@include $f) !== false) {
                $key = "loaded";
            }
            $Opt[$key][] = $f;
        }
    }
}

function expand_json_includes_callback($includelist, $callback) {
    $includes = [];
    foreach (is_array($includelist) ? $includelist : [$includelist] as $k => $str) {
        $expandable = null;
        if (is_string($str)) {
            if (str_starts_with($str, "@")) {
                $expandable = substr($str, 1);
            } else if (!str_starts_with($str, "{")
                       && (!str_starts_with($str, "[") || !str_ends_with(rtrim($str), "]"))
                       && !ctype_space($str[0])) {
                $expandable = $str;
            }
        }
        if ($expandable) {
            foreach (SiteLoader::expand_includes($expandable) as $f) {
                if (($x = file_get_contents($f)))
                    $includes[] = [$x, $f];
            }
        } else {
            $includes[] = [$str, "entry $k"];
        }
    }
    foreach ($includes as $xentry) {
        list($entry, $landmark) = $xentry;
        if (is_string($entry)) {
            $x = json_decode($entry);
            if ($x === null && json_last_error()) {
                $x = Json::decode($entry);
                if ($x === null) {
                    error_log("$landmark: Invalid JSON: " . Json::last_error_msg());
                }
            }
            $entry = $x;
        }
        foreach (is_array($entry) ? $entry : [$entry] as $k => $v) {
            if ($v === null || $v === false) {
                continue;
            }
            if (is_object($v)) {
                $v->__subposition = ++Conf::$next_xt_subposition;
            }
            if (!call_user_func($callback, $v, $k, $landmark)) {
                error_log((Conf::$main ? Conf::$main->dbname . ": " : "") . "$landmark: Invalid expansion " . json_encode($v) . "\n" . debug_string_backtrace());
            }
        }
    }
}

global $Opt;
if (!$Opt) {
    $Opt = array();
}
if (!($Opt["loaded"] ?? null)) {
    SiteLoader::read_main_options();
    if ($Opt["multiconference"] ?? null) {
        Multiconference::init();
    }
    if (isset($Opt["include"]) && $Opt["include"]) {
        read_included_options($Opt["include"]);
    }
}
if (!($Opt["loaded"] ?? null) || ($Opt["missing"] ?? null)) {
    Multiconference::fail_bad_options();
}
if (isset($Opt["dbLogQueries"]) && $Opt["dbLogQueries"]) {
    Dbl::log_queries($Opt["dbLogQueries"], $Opt["dbLogQueryFile"] ?? null);
}


// Allow lots of memory
if (!($Opt["memoryLimit"] ?? null) && ini_get_bytes("memory_limit") < (128 << 20)) {
    $Opt["memoryLimit"] = "128M";
}
if (isset($Opt["memoryLimit"]) && $Opt["memoryLimit"]) {
    ini_set("memory_limit", $Opt["memoryLimit"]);
}


// Create the conference
if (!Conf::$main) {
    Conf::set_main_instance(new Conf($Opt, true));
}
if (!Conf::$main->dblink) {
    Multiconference::fail_bad_database();
}
