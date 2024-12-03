<?php
// multiconference.php -- HotCRP multiconference installations
// Copyright (c) 2006-2024 Eddie Kohler; see LICENSE.

class Multiconference {
    /** @var array<string,?Conf> */
    static private $conf_cache;

    /** @param ?string $confid */
    static function init($confid = null) {
        global $Opt;

        $confid = $confid ?? $Opt["confid"] ?? null;
        if ($confid === null && PHP_SAPI !== "cli") {
            $nav = Navigation::get();
            if (($max = $Opt["multiconferenceAnalyzer"] ?? null)) {
                if (is_string($max)) {
                    $confid = self::test_multiconference_analyzer($max, $nav);
                } else {
                    foreach ($max as $ma) {
                        if (($confid = self::test_multiconference_analyzer($ma, $nav))) {
                            break;
                        }
                    }
                }
            } else if ($nav->base_path !== "/") {
                $slash = strrpos($nav->base_path, "/", -2);
                $confid = substr($nav->base_path, $slash + 1, -1);
            }
        }

        if (!$confid) {
            $Opt["confid"] = "__nonexistent__";
        } else if (preg_match('/\A[a-zA-Z0-9_][-a-zA-Z0-9_.]*\z/', $confid)) {
            $Opt["confid"] = $confid;
        } else {
            $Opt["__original_confid"] = $confid;
            $Opt["confid"] = "__invalid__";
        }
    }

    /** @param string $ma
     * @param NavigationState $nav
     * @return ?string */
    static private function test_multiconference_analyzer($ma, $nav) {
        $sp = strpos($ma, " ");
        $p = 0;
        if ($sp === 1) {
            $t = $ma[0];
            $p = 2;
            $sp = strpos($ma, " ", 2);
        } else {
            $t = "b";
        }
        if ($sp === false) {
            return null;
        }
        if ($t === "b") {
            $subject = $nav->base_absolute(true);
        } else if ($t === "h") {
            $subject = strtolower($nav->host);
        } else if ($t === "p") {
            $subject = $nav->base_path;
        } else {
            return null;
        }
        if (!preg_match("\1\\A" . substr($ma, $p, $sp - $p) . "\1", $subject, $m)) {
            return null;
        }
        $confid = substr($ma, $sp + 1);
        for ($i = 1; isset($m[$i]); ++$i) {
            $confid = str_replace("\${$i}", $m[$i], $confid);
        }
        return $confid;
    }


    /** @param ?string $root
     * @param string $confid
     * @return ?Conf */
    static function get_conf($root, $confid) {
        if (self::$conf_cache === null) {
            self::$conf_cache = [];
            if (Conf::$main && ($xconfid = Conf::$main->opt("confid"))) {
                self::$conf_cache[SiteLoader::$root . "\0{$xconfid}"] = Conf::$main;
            }
        }
        $root = $root ?? SiteLoader::$root;
        $key = "{$root}\0{$confid}";
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
            SiteLoader::read_included_options($root);
        }
        $newconf = ($Opt["missing"] ?? null) ? null : new Conf($Opt, true);
        $Opt = $save_opt;
        return $newconf;
    }

    /** @param 403|404|array{title?:string,link?:bool}|Qrequest|MessageItem|FailureReason|string|null ...$arg
     * @return never */
    static function fail(...$arg) {
        global $Opt;

        $qreq = null;
        $mis1 = [];
        $title = "";
        $status = 404;
        $link = null;
        $fr = null;
        foreach ($arg as $x) {
            if ($x === null) {
                // skip
            } else if (is_int($x)) {
                $status = $x;
            } else if (is_string($x)) {
                $mis1[] = MessageItem::error($x);
            } else if ($x instanceof MessageItem) {
                $mis1[] = $x;
            } else if ($x instanceof Qrequest) {
                $qreq = $x;
            } else if ($x instanceof FailureReason) {
                assert(!$fr);
                $mis1[] = $fr = $x;
            } else {
                assert(is_array($x));
                $title = $x["title"] ?? $title;
                $link = $x["link"] ?? $link;
            }
        }

        $maintenance = $Opt["maintenance"] ?? null;
        if ($maintenance) {
            $status = 503;
            $title = "Maintenance";
            $mis = [Messageitem::error(Ftext::concat("<0>The site is down for maintenance. ", is_string($maintenance) ? $maintenance : "<0>Please check back later."))];
            $link = false;
            $fr = null;
        }

        $qreq = $qreq ?? Qrequest::$main_request ?? Qrequest::make_minimal();
        if (!$qreq->conf) {
            $qreq->set_conf(Conf::$main ?? new Conf($Opt, false));
        }
        if ($link === false) {
            $qreq->set_user(null);
        }

        // maybe expand FailureReason
        if ($fr) {
            if (PHP_SAPI !== "cli"
                && $qreq->page() !== "api"
                && !friendly_boolean($_GET["ajax"] ?? null)) {
                $fr->set("expand", true);
            }
            $mis = [];
            foreach ($mis1 as $x) {
                if ($x instanceof FailureReason) {
                    foreach ($x->message_list() as $mi) {
                        $mis[] = $mi;
                    }
                } else {
                    $mis[] = $x;
                }
            }
        } else {
            $mis = $mis1;
        }

        // print message
        if (PHP_SAPI === "cli") {
            fwrite(STDERR, MessageSet::feedback_text($mis));
            exit(1);
        }

        if ($qreq->page() === "api" || ($_GET["ajax"] ?? null)) {
            http_response_code($status);
            header("Content-Type: application/json; charset=utf-8");
            $j = ["ok" => false, "message_list" => $mis];
            if ($maintenance) {
                $j["maintenance"] = true;
            }
            echo json_encode_browser($j), "\n";
            exit(0);
        }

        http_response_code($status);
        $qreq->print_header($title, "", ["action_bar" => "", "body_class" => "body-error"]);
        $mis[0] = $mis[0] ?? MessageItem::error("<0>Internal error");
        if ($link && $mis[0]->status >= 2) {
            if (!is_string($link)) {
                $link = Conf::$main->hoturl_raw("index");
            }
            $mis[] = MessageItem::plain("<5><a href=\"" . htmlspecialchars($link) . "\">" . htmlspecialchars(Conf::$main->short_name) . " main site</a>");
        }
        echo '<div class="msg mx-auto msg-error">', MessageSet::feedback_html($mis), '</div>';
        $qreq->print_footer();
        exit(0);
    }

    /** @return Qrequest */
    static private function make_qrequest() {
        if (Qrequest::$main_request) {
            return Qrequest::$main_request;
        }
        $qreq = (new Qrequest("GET"))->set_navigation(Navigation::get());
        if (Contact::$main_user) {
            $qreq->set_user(Contact::$main_user);
        } else {
            global $Opt;
            $qreq->set_conf(Conf::$main ?? new Conf($Opt, false));
        }
        return $qreq;
    }

    /** @return string */
    static private function nonexistence_error() {
        if (PHP_SAPI === "cli") {
            return "Conference not specified. Use `-n CONFID` to specify a conference.";
        } else {
            return "Conference not specified.";
        }
    }

    /** @return never */
    static function fail_bad_options() {
        global $Opt;
        $qreq = null;
        if (isset($Opt["multiconferenceFailureCallback"])
            && PHP_SAPI !== "cli") {
            $qreq = Qrequest::make_minimal();
            call_user_func($Opt["multiconferenceFailureCallback"], "options", $qreq);
        }

        $errors = [];
        $confid = $Opt["confid"] ?? null;
        $multiconference = $Opt["multiconference"] ?? null;
        $missing = [];
        $invalid = false;
        foreach ($Opt["missing"] ?? [] as $x) {
            if (strpos($x, "__invalid__") !== false) {
                $invalid = true;
            } else if (strpos($x, "__nonexistent__") === false) {
                $missing[] = $x;
            }
        }

        if (PHP_SAPI === "cli") {
            if ($missing) {
                array_push($errors, ...array_map(function ($s) {
                    if (!file_exists($s)) {
                        return "{$s}: Configuration file not found";
                    } else {
                        return "{$s}: Unable to load configuration file";
                    }
                }, $missing));
            } else if ($invalid) {
                $errors[] = "Invalid conference specified with `-n`";
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
                } else if (!empty($missing)) {
                    $errors[] = "Unable to load configuration file";
                }
            }
        }

        Multiconference::fail($qreq, ["link" => false], ...$errors);
    }

    /** @return never */
    static function fail_bad_database() {
        global $Opt;
        $qreq = null;
        if (isset($Opt["multiconferenceFailureCallback"])
            && PHP_SAPI !== "cli") {
            $qreq = Qrequest::make_minimal();
            call_user_func($Opt["multiconferenceFailureCallback"], "database", $qreq);
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
        Multiconference::fail($qreq, ["link" => false], ...$errors);
    }

    /** @param Throwable $ex
     * @suppress PhanUndeclaredProperty
     * @deprecated */
    static function batch_exception_handler($ex) {
        return BatchProcess::exception_handler($ex);
    }
}
