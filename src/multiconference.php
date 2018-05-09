<?php
// multiconference.php -- HotCRP multiconference installations
// Copyright (c) 2006-2018 Eddie Kohler; see LICENSE.

class Multiconference {
    static private $original_opt = null;

    static function init() {
        global $Opt, $argv;
        assert(self::$original_opt === null);
        self::$original_opt = $Opt;

        $confid = get($Opt, "confid");
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
            $base = Navigation::site_absolute(true);
            if (($multis = get($Opt, "multiconferenceAnalyzer"))) {
                foreach (is_array($multis) ? $multis : array($multis) as $multi) {
                    list($match, $replace) = explode(" ", $multi);
                    if (preg_match("`\\A$match`", $base, $m)) {
                        $confid = $replace;
                        for ($i = 1; $i < count($m); ++$i)
                            $confid = str_replace("\$$i", $m[$i], $confid);
                        break;
                    }
                }
            } else if (preg_match(',/([^/]+)/\z,', $base, $m))
                $confid = $m[1];
        }

        if (!$confid)
            $confid = "__nonexistent__";
        else if (!preg_match(',\A[-a-zA-Z0-9_][-a-zA-Z0-9_.]*\z,', $confid))
            $confid = "__invalid__";

        self::assign_confid($Opt, $confid);
    }

    static function assign_confid(&$opt, $confid) {
        foreach (array("dbName", "dbUser", "dbPassword", "dsn") as $k)
            if (isset($opt[$k]) && is_string($opt[$k]))
                $opt[$k] = preg_replace(',\*|\$\{conf(?:id|name)\}|\$conf(?:id|name)\b,', $confid, $opt[$k]);
        if (!get($opt, "dbName") && !get($opt, "dsn"))
            $opt["dbName"] = $confid;
        $opt["confid"] = $confid;
    }

    static function load_confid($confid) {
        global $Opt;
        $save_opt = $Opt;
        $Opt = self::$original_opt;
        self::assign_confid($Opt, $confid);
        if (get($Opt, "include"))
            read_included_options($Opt["include"]);
        $newconf = get($Opt, "missing") ? null : new Conf($Opt, true);
        $Opt = $save_opt;
        return $newconf;
    }

    static function fail_message($errors) {
        global $Conf, $Me, $Opt;

        if (is_string($errors))
            $errors = array($errors);
        if (get($Opt, "maintenance"))
            $errors = array("The site is down for maintenance. " . (is_string($Opt["maintenance"]) ? $Opt["maintenance"] : "Please check back later."));

        if (PHP_SAPI == "cli") {
            fwrite(STDERR, join("\n", $errors) . "\n");
            exit(1);
        } else if (get($_GET, "ajax")) {
            $ctype = get($_GET, "text") ? "text/plain" : "application/json";
            header("Content-Type: $ctype; charset=utf-8");
            if (get($Opt, "maintenance"))
                echo "{\"error\":\"maintenance\"}\n";
            else
                echo "{\"error\":\"unconfigured installation\"}\n";
        } else {
            if (!$Conf)
                $Conf = Conf::$g = new Conf($Opt, false);
            $Me = null;
            header("HTTP/1.1 404 Not Found");
            $Conf->header("HotCRP Error", "", ["action_bar" => false]);
            foreach ($errors as $i => &$e)
                $e = ($i ? "<div class=\"hint\">" : "<p>") . htmlspecialchars($e) . ($i ? "</div>" : "</p>");
            echo join("", $errors);
            $Conf->footer();
        }
        exit;
    }

    static function fail_bad_options() {
        global $Opt;
        $errors = array();
        if (get($Opt, "multiconference") && $Opt["confid"] === "__nonexistent__")
            $errors[] = "You haven’t specified a conference and this is a multiconference installation.";
        else if (get($Opt, "multiconference"))
            $errors[] = "The “" . $Opt["confid"] . "” conference does not exist. Check your URL to make sure you spelled it correctly.";
        else if (!get($Opt, "loaded"))
            $errors[] = "HotCRP has been installed, but not yet configured. You must run `lib/createdb.sh` to create a database for your conference. See `README.md` for further guidance.";
        else
            $errors[] = "HotCRP was unable to load. A system administrator must fix this problem.";
        if (!get($Opt, "loaded") && defined("HOTCRP_OPTIONS"))
            $errors[] = "Error: Unable to load options file `" . HOTCRP_OPTIONS . "`";
        else if (!get($Opt, "loaded"))
            $errors[] = "Error: Unable to load options file";
        if (get($Opt, "missing"))
            $errors[] = "Error: Unable to load options from " . commajoin($Opt["missing"]);
        self::fail_message($errors);
    }

    static function fail_bad_database() {
        global $Conf, $Opt;
        $errors = array();
        if (get($Opt, "multiconference") && $Opt["confid"] === "__nonexistent__")
            $errors[] = "You haven’t specified a conference and this is a multiconference installation.";
        else if (get($Opt, "multiconference"))
            $errors[] = "The “" . $Opt["confid"] . "” conference does not exist. Check your URL to make sure you spelled it correctly.";
        else {
            $errors[] = "HotCRP was unable to load. A system administrator must fix this problem.";
            $errors[] = "Error: Unable to connect to database " . Dbl::sanitize_dsn($Conf->dsn);
            if (defined("HOTCRP_TESTHARNESS"))
                $errors[] = "You may need to run `lib/createdb.sh -c test/options.php` to create the database.";
        }
        self::fail_message($errors);
    }
}
