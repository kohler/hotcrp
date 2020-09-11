<?php
// multiconference.php -- HotCRP multiconference installations
// Copyright (c) 2006-2020 Eddie Kohler; see LICENSE.

class Multiconference {
    static private $original_opt;
    static private $cache;

    static function init() {
        global $Opt, $argv;
        assert(self::$original_opt === null);
        self::$original_opt = $Opt;

        $confid = $Opt["confid"] ?? null;
        if (!$confid && PHP_SAPI == "cli") {
            for ($i = 1; $i != count($argv); ++$i) {
                if ($argv[$i] === "-n" || $argv[$i] === "--name") {
                    if (isset($argv[$i + 1]))
                        $confid = $argv[$i + 1];
                    break;
                } else if (substr($argv[$i], 0, 2) === "-n") {
                    $confid = substr($argv[$i], 2);
                    break;
                } else if (substr($argv[$i], 0, 7) === "--name=") {
                    $confid = substr($argv[$i], 7);
                    break;
                } else if ($argv[$i] === "--") {
                    break;
                }
            }
        } else if (!$confid) {
            $base = Navigation::base_absolute(true);
            if (($multis = $Opt["multiconferenceAnalyzer"] ?? null)) {
                foreach (is_array($multis) ? $multis : [$multis] as $multi) {
                    list($match, $replace) = explode(" ", $multi);
                    if (preg_match("`\\A$match`", $base, $m)) {
                        $confid = $replace;
                        for ($i = 1; $i < count($m); ++$i) {
                            $confid = str_replace("\$$i", $m[$i], $confid);
                        }
                        break;
                    }
                }
            } else if (preg_match(',/([^/]+)/\z,', $base, $m)) {
                $confid = $m[1];
            }
        }

        if (!$confid) {
            $confid = "__nonexistent__";
        } else if (!preg_match('/\A[-a-zA-Z0-9_][-a-zA-Z0-9_.]*\z/', $confid)) {
            $Opt["__original_confid"] = $confid;
            $confid = "__invalid__";
        }

        self::assign_confid($Opt, $confid);
    }

    static function assign_confid(&$opt, $confid) {
        foreach (["dbName", "dbUser", "dbPassword", "dsn"] as $k) {
            if (isset($opt[$k]) && is_string($opt[$k]))
                $opt[$k] = preg_replace('/\*|\$\{conf(?:id|name)\}|\$conf(?:id|name)\b/', $confid, $opt[$k]);
        }
        if (!($opt["dbName"] ?? null) && !($opt["dsn"] ?? null)) {
            $opt["dbName"] = $confid;
        }
        $opt["confid"] = $confid;
    }

    static function get_confid($confid) {
        if (self::$cache === null) {
            self::$cache = [];
            if (Conf::$main && ($xconfid = Conf::$main->opt("confid"))) {
                self::$cache[$xconfid] = Conf::$main;
            }
        }
        $conf = self::$cache[$confid] ?? null;
        if ($conf === null && ($conf = self::load_confid($confid))) {
            self::$cache[$confid] = $conf;
        }
        return $conf;
    }

    static function load_confid($confid) {
        global $Opt;
        $save_opt = $Opt;
        $Opt = self::$original_opt;
        self::assign_confid($Opt, $confid);
        if ($Opt["include"] ?? null) {
            read_included_options($Opt["include"]);
        }
        $newconf = ($Opt["missing"] ?? null) ? null : new Conf($Opt, true);
        $Opt = $save_opt;
        return $newconf;
    }

    static function fail_message($errors) {
        global $Opt;
        $maintenance = $Opt["maintenance"] ?? null;

        if ($maintenance) {
            $errors = ["The site is down for maintenance. " . (is_string($maintenance) ? $maintenance : "Please check back later.")];
        } else {
            $errors = is_string($errors) ? [$errors] : $errors;
        }

        if (PHP_SAPI === "cli") {
            fwrite(STDERR, join("\n", $errors) . "\n");
            exit(1);
        } else if (Navigation::page() === "api" || ($_GET["ajax"] ?? null)) {
            $ctype = ($_GET["text"] ?? null) ? "text/plain" : "application/json";
            header("HTTP/1.1 404 Not Found");
            header("Content-Type: $ctype; charset=utf-8");
            if ($maintenance) {
                echo "{\"error\":\"maintenance\"}\n";
            } else {
                echo "{\"error\":\"unconfigured installation\"}\n";
            }
        } else {
            if (!Conf::$main) {
                Conf::set_main_instance(new Conf($Opt, false));
            }
            Contact::set_guser(null);
            header("HTTP/1.1 404 Not Found");
            Conf::$main->header("HotCRP Error", "", ["action_bar" => false]);
            foreach ($errors as $i => &$e) {
                $e = ($i ? "<div class=\"hint\">" : "<p>") . htmlspecialchars($e) . ($i ? "</div>" : "</p>");
            }
            echo join("", $errors);
            Conf::$main->footer();
        }
        exit;
    }

    static private function nonexistence_error() {
        if (PHP_SAPI === "cli") {
            return "This is a multiconference installation. Use `-n CONFID` to specify a conference.";
        } else {
            return "Conference not specified.";
        }
    }

    static function fail_bad_options() {
        global $Opt;
        if (isset($Opt["multiconferenceFailureCallback"])) {
            call_user_func($Opt["multiconferenceFailureCallback"], "options");
        }
        $errors = [];
        $confid = $Opt["confid"] ?? null;
        $multiconference = $Opt["multiconference"] ?? null;
        if ($multiconference && $confid === "__nonexistent__") {
            $errors[] = self::nonexistence_error();
        } else if ($multiconference) {
            $errors[] = "The “{$confid}” conference does not exist. Check your URL to make sure you spelled it correctly.";
        } else if (!($Opt["loaded"] ?? false)) {
            $errors[] = "HotCRP has been installed, but not yet configured. You must run `lib/createdb.sh` to create a database for your conference. See `README.md` for further guidance.";
        } else {
            $errors[] = "HotCRP was unable to load. A system administrator must fix this problem.";
        }
        if (!($Opt["loaded"] ?? false) && defined("HOTCRP_OPTIONS")) {
            $errors[] = "Error: Unable to load options file `" . HOTCRP_OPTIONS . "`";
        } else if (!($Opt["loaded"] ?? false)) {
            $errors[] = "Error: Unable to load options file";
        }
        if (isset($Opt["missing"]) && $Opt["missing"]) {
            $missing = array_filter($Opt["missing"], function ($x) {
                return strpos($x, "__nonexistent__") === false;
            });
            if (!empty($missing)) {
                $errors[] = "Error: Unable to load options from " . commajoin($missing);
            }
        }
        self::fail_message($errors);
    }

    static function fail_bad_database() {
        global $Opt;
        if (isset($Opt["multiconferenceFailureCallback"])) {
            call_user_func($Opt["multiconferenceFailureCallback"], "database");
        }
        $errors = [];
        $confid = $Opt["confid"] ?? null;
        $multiconference = $Opt["multiconference"] ?? null;
        if ($multiconference && $confid === "__nonexistent__") {
            $errors[] = self::nonexistence_error();
        } else if ($multiconference) {
            $errors[] = "The “{$confid}” conference does not exist. Check your URL to make sure you spelled it correctly.";
        } else {
            $errors[] = "HotCRP was unable to load. A system administrator must fix this problem.";
            $errors[] = "Error: Unable to connect to database " . Dbl::sanitize_dsn(Conf::$main->dsn);
            if (defined("HOTCRP_TESTHARNESS")) {
                $errors[] = "You may need to run `lib/createdb.sh -c test/options.php` to create the database.";
            }
        }
        self::fail_message($errors);
    }
}
