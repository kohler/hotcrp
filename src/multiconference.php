<?php
// multiconference.php -- HotCRP multiconference installations
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class Multiconference {
    /** @var array<string,?Conf> */
    static private $conf_cache;

    /** @param ?string $confid */
    static function init($confid = null) {
        global $Opt;

        $confid = $confid ?? $Opt["confid"] ?? null;
        if ($confid === null && PHP_SAPI !== "cli") {
            $base = Navigation::base_absolute(true);
            if (($multis = $Opt["multiconferenceAnalyzer"] ?? null)) {
                foreach (is_array($multis) ? $multis : [$multis] as $multi) {
                    list($match, $replace) = explode(" ", $multi);
                    if (preg_match("`\\A{$match}`", $base, $m)) {
                        $confid = $replace;
                        for ($i = 1; $i < count($m); ++$i) {
                            $confid = str_replace("\${$i}", $m[$i], $confid);
                        }
                        break;
                    }
                }
            } else if (preg_match('/\/([^\/]+)\/\z/', $base, $m)) {
                $confid = $m[1];
            }
        }

        if (!$confid) {
            $Opt["confid"] = "__nonexistent__";
        } else if (preg_match('/\A[-a-zA-Z0-9_][-a-zA-Z0-9_.]*\z/', $confid)) {
            $Opt["confid"] = $confid;
        } else {
            $Opt["__original_confid"] = $confid;
            $Opt["confid"] = "__invalid__";
        }
    }

    /** @param ?string $root
     * @param string $confid
     * @return ?Conf */
    static function get_conf($root, $confid) {
        if (self::$conf_cache === null) {
            self::$conf_cache = [];
            if (Conf::$main && ($xconfid = Conf::$main->opt("confid"))) {
                self::$conf_cache[SiteLoader::$root . "{}{$xconfid}"] = Conf::$main;
            }
        }
        $root = $root ?? SiteLoader::$root;
        $key = "{$root}{}{$confid}";
        if (!array_key_exists($key, self::$conf_cache)) {
            self::$conf_cache[$key] = self::load_conf($root, $confid);
        }
        return self::$conf_cache[$key];
    }

    /** @param ?string $root
     * @param string $confid
     * @return ?Conf */
    static function load_conf($root, $confid) {
        global $Opt;
        $save_opt = $Opt;
        '@phan-var array<string,mixed> $save_opt';
        $root = $root ?? SiteLoader::$root;
        $Opt = [];
        SiteLoader::read_options_file("{$root}/conf/options.php");
        $Opt["confid"] = $confid;
        if ($Opt["include"] ?? null) {
            SiteLoader::read_included_options();
        }
        $newconf = ($Opt["missing"] ?? null) ? null : new Conf($Opt, true);
        $Opt = $save_opt;
        return $newconf;
    }

    static function fail(...$arg) {
        global $Opt;

        $maintenance = $Opt["maintenance"] ?? null;
        if ($maintenance) {
            $status = 503;
            $title = "Maintenance";
            $errors = ["The site is down for maintenance. " . (is_string($maintenance) ? $maintenance : "Please check back later.")];
            $nolink = true;
        } else {
            $errors = [];
            $title = "Error";
            $status = 404;
            $nolink = false;
            foreach ($arg as $x) {
                if (is_int($x)) {
                    $status = $x;
                } else if (is_string($x)) {
                    $errors[] = $x;
                } else {
                    assert(is_array($x));
                    $title = $x["title"] ?? $title;
                    $nolink = $x["nolink"] ?? $nolink;
                }
            }
        }

        if (PHP_SAPI === "cli") {
            fwrite(STDERR, join("\n", $errors) . "\n");
            exit(1);
        } else if (Navigation::page() === "api" || ($_GET["ajax"] ?? null)) {
            http_response_code($status);
            header("Content-Type: application/json; charset=utf-8");
            if ($maintenance) {
                echo "{\"ok\":false,\"error\":\"maintenance\"}\n";
            } else {
                echo "{\"ok\":false,\"error\":\"unconfigured installation\"}\n";
            }
        } else {
            if (!Conf::$main) {
                Conf::set_main_instance(new Conf($Opt, false));
                $nolink = true;
            }
            if ($nolink) {
                Contact::set_main_user(null);
            }
            http_response_code($status);
            Conf::$main->header($title, "", ["action_bar" => false]);
            if (empty($errors)) {
                $errors[] = "Internal error.";
            }
            echo '<div class="msg msg-error">';
            foreach ($errors as $i => $e) {
                echo '<p>', htmlspecialchars($e);
                if (!$nolink && $i === 0) {
                    echo ' ', Ht::link("Return to site", Conf::$main->hoturl("index"));
                }
                echo '</p>';
            }
            echo '</div>';
            Conf::$main->footer();
        }
        exit;
    }

    /** @return string */
    static private function nonexistence_error() {
        if (PHP_SAPI === "cli") {
            return "Conference not specified. Use `-n CONFID` to specify a conference.";
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
        $missing = array_values(array_filter($Opt["missing"] ?? [], function ($x) {
            return strpos($x, "__nonexistent__") === false;
        }));

        if (PHP_SAPI === "cli") {
            if ($missing) {
                array_push($errors, ...array_map(function ($s) {
                    if (!file_exists($s)) {
                        return "{$s}: Configuration file not found";
                    } else {
                        return "{$s}: Unable to load configuration file";
                    }
                }, $missing));
            } else if ($multiconference && $confid === "__nonexistent__") {
                $errors[] = self::nonexistence_error();
            } else {
                $errors[] = "Unable to load HotCRP";
            }
        } else {
            if (!($Opt["loaded"] ?? null)) {
                $main_options = defined("HOTCRP_OPTIONS") ? HOTCRP_OPTIONS : SiteLoader::$root . "/conf/options.php";
                if (!file_exists($main_options)) {
                    $errors[] = "HotCRP has been installed, but not yet configured. You must run `lib/createdb.sh` to create a database for your conference. See `README.md` for further guidance.";
                } else {
                    $errors[] = "HotCRP was unable to load. A system administrator must fix this problem.";
                }
            } else if ($multiconference && $confid === "__nonexistent__") {
                $errors[] = self::nonexistence_error();
            } else {
                if ($multiconference) {
                    $errors[] = "The “{$confid}” conference does not exist. Check your URL to make sure you spelled it correctly.";
                }
                if (!empty($missing)) {
                    $errors[] = "Unable to load " . pluralx(count($missing), "configuration file") . " " . commajoin($missing) . ".";
                }
            }
        }

        self::fail(["nolink" => true], ...$errors);
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
            $errors[] = "HotCRP was unable to connect to its database. A system administrator must fix this problem.";
            if (defined("HOTCRP_TESTHARNESS")) {
                $errors[] = "You may need to run `lib/createdb.sh -c test/options.php` to create the database.";
            }
            if (($cp = Dbl::parse_connection_params($Opt))) {
                error_log("Unable to connect to database " . $cp->sanitized_dsn());
            } else {
                error_log("Unable to connect to database");
            }
        }
        self::fail(["nolink" => true], ...$errors);
    }

    /** @param Throwable $ex
     * @suppress PhanUndeclaredProperty */
    static function batch_exception_handler($ex) {
        global $argv;
        $s = $ex->getMessage();
        if (defined("HOTCRP_TESTHARNESS")) {
            $s = $ex->getFile() . ":" . $ex->getLine() . ": " . $s;
        }
        if (strpos($s, ":") === false) {
            $script = $argv[0] ?? "";
            if (($slash = strrpos($script, "/")) !== false) {
                if (($slash === 5 && str_starts_with($script, "batch"))
                    || ($slash > 5 && substr_compare($script, "/batch", $slash - 6, 6) === 0)) {
                    $slash -= 6;
                }
                $script = substr($script, $slash + 1);
            }
            if ($script !== "") {
                $s = "{$script}: {$s}";
            }
        }
        if (substr($s, -1) !== "\n") {
            $s = "{$s}\n";
        }
        if (property_exists($ex, "getopt")
            && $ex->getopt instanceof Getopt) {
            $s .= $ex->getopt->short_usage();
        }
        if (defined("HOTCRP_TESTHARNESS")) {
            $s .= debug_string_backtrace($ex) . "\n";
        }
        fwrite(STDERR, $s);
        exit(1);
    }
}
