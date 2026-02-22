<?php
// test/setup.php -- HotCRP helper file to initialize tests
// Copyright (c) 2006-2026 Eddie Kohler; see LICENSE.

require_once(dirname(__DIR__) . "/src/siteloader.php");
define("HOTCRP_OPTIONS", SiteLoader::find("test/options.php"));
define("HOTCRP_TESTHARNESS", true);
ini_set("error_log", "");
ini_set("log_errors", "0");
ini_set("display_errors", "stderr");
ini_set("assert.exception", "1");
error_reporting(E_ALL);

require_once(SiteLoader::find("src/init.php"));
initialize_conf();


// Record mail in MailChecker.
class MailCheckerExpectation {
    /** @var string */
    public $header;
    /** @var string */
    public $body;
    /** @var string */
    public $landmark;
}

class MailChecker {
    /** @var int */
    static public $disabled = 0;
    /** @var bool */
    static public $print = false;
    /** @var list<MailPreparation> */
    static public $preps = [];
    /** @var array<string,list<MailCheckerExpectation>> */
    static public $messagedb = [];

    /** @param MailPreparation $prep */
    static function send_hook($prep) {
        if (self::$disabled !== 0) {
            return;
        }
        $prep->landmark = "";
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $trace) {
            if (isset($trace["file"])
                && preg_match('/\/(?:test\d|t_)/', $trace["file"])) {
                if (str_starts_with($trace["file"], SiteLoader::$root)) {
                    $trace["file"] = substr($trace["file"], strlen(SiteLoader::$root) + 1);
                }
                $prep->landmark = $trace["file"] . ":" . $trace["line"];
                break;
            }
        }
        self::$preps[] = $prep;
        if (self::$print) {
            fwrite(STDOUT, "********\n"
                   . "To: " . join(", ", $prep->recipient_texts()) . "\n"
                   . "Subject: " . str_replace("\r", "", $prep->subject) . "\n"
                   . ($prep->landmark ? "X-Landmark: {$prep->landmark}\n" : "") . "\n"
                   . $prep->body);
        }
    }

    static function check0() {
        self::check_match([]);
    }

    /** @param string $want
     * @param list<string> $haves */
    static function find_best_mail_match($want, $haves) {
        $len0 = strlen($want);
        $best = [false, false, false];
        $best_nbad = 1000000;
        foreach ($haves as $i => $have) {
            $len1 = strlen($have);
            $pos0 = $pos1 = $line = $nbad = 0;
            $badline = null;
            while ($pos0 !== $len0 || $pos1 !== $len1) {
                ++$line;
                $epos0 = strpos($want, "\n", $pos0);
                $epos0 = $epos0 !== false ? $epos0 + 1 : $len0;
                $epos1 = strpos($have, "\n", $pos1);
                $epos1 = $epos1 !== false ? $epos1 + 1 : $len1;
                $line0 = substr($want, $pos0, $epos0 - $pos0);
                $line1 = substr($have, $pos1, $epos1 - $pos1);
                if (strpos($line0, "{{}}") !== false
                    ? !preg_match('{\A' . str_replace('\\{\\{\\}\\}', ".*", preg_quote($line0)) . '\z}', $line1)
                    : $line0 !== $line1) {
                    $badline = $badline ?? $line;
                    ++$nbad;
                }
                $pos0 = $epos0;
                $pos1 = $epos1;
            }
            if ($nbad === 0) {
                return [true, $i, false];
            } else if ($nbad < $best_nbad && $nbad < 12) {
                $best = [false, $i, $badline];
            }
        }
        return $best;
    }

    /** @param ?string $name */
    static function check_db($name = null) {
        if ($name) {
            $mdb = self::$messagedb[$name] ?? [];
            xassert_eqq(count(self::$preps), count($mdb));
        } else {
            xassert(!empty(self::$preps));
            $last_landmark = null;
            $mdb = [];
            foreach (self::$preps as $prep) {
                xassert($prep->landmark);
                $landmark = $prep->landmark;
                for ($delta = 0; $delta < 10 && !isset(self::$messagedb[$landmark]); ++$delta) {
                    $colon = strpos($prep->landmark, ":");
                    $landmark = substr($prep->landmark, 0, $colon + 1)
                        . (intval(substr($prep->landmark, $colon + 1), 10)
                           + ($delta & 1 ? ($delta + 1) / 2 : -$delta / 2 - 1));
                }
                if (isset(self::$messagedb[$landmark])) {
                    if ($landmark !== $last_landmark) {
                        $mdb = array_merge($mdb, self::$messagedb[$landmark]);
                        $last_landmark = $landmark;
                    }
                } else {
                    trigger_error("Found no database messages near {$prep->landmark}\n", E_USER_WARNING);
                }
            }
        }
        self::check_match($mdb);
    }

    /** @param list<string|MailCheckerExpectation> $mdb */
    static function check_match($mdb) {
        $haves = [];
        foreach (self::$preps as $prep) {
            $haves[] = "To: " . join(", ", $prep->recipient_texts()) . "\n"
                . "Subject: " . str_replace("\r", "", $prep->subject)
                . "\n\n" . $prep->body;
        }
        sort($haves);

        foreach ($mdb as $want) {
            $wtext = is_string($want)
                ? $want
                : preg_replace('/^X-Landmark:.*?\n/m', "", $want->header) . $want->body;
            list($match, $index, $badline) = self::find_best_mail_match($wtext, $haves);
            if ($match) {
                Xassert::succeed();
            } else if ($index !== false) {
                $have = $haves[$index];
                $havel = explode("\n", $have);
                $wantl = explode("\n", $wtext);
                $color = Xassert::$test_runner && Xassert::$test_runner->color;
                Xassert::fail_with(
                    "Mail mismatch at line {$badline}\n",
                    "  expected {$wantl[$badline-1]}\n",
                    is_object($want) && isset($want->landmark)
                    ? "        at {$want->landmark}\n" : "",
                    "       got {$havel[$badline-1]}\n",
                    $color ? "\x1b[90m" : "",
                    "  expected ",
                    str_replace("\n", "\n           ", rtrim($have)),
                    "\n       got ",
                    str_replace("\n", "\n           ", rtrim($wtext)),
                    $color ? "\x1b[m\n" : "\n"
                );
            } else {
                Xassert::fail_with("mail not found `{$wtext}`");
            }
            if ($index !== false) {
                array_splice($haves, $index, 1);
            }
        }

        Xassert::push_failure_group();
        foreach ($haves as $have) {
            Xassert::fail_with("unexpected mail: " . $have);
        }
        Xassert::pop_failure_group();
        self::$preps = [];
    }

    static function clear() {
        self::$preps = [];
    }

    /** @param string $text
     * @param ?string $file */
    static function add_messagedb($text, $file = null) {
        $l = explode("\n", $text);
        $n = count($l);
        if ($n > 0 && $l[$n - 1] === "") {
            --$n;
        }
        for ($i = 0; $i !== $n; ) {
            if (!str_starts_with($l[$i], "********")) {
                ++$i;
                continue;
            }

            $mid = trim(substr($l[$i], 8));
            $bodypos = null;
            $j = $i + 1;
            while ($j !== $n && !str_starts_with($l[$j], "********")) {
                if ($bodypos === null) {
                    if ($mid === ""
                        && str_starts_with($l[$j], "X-Landmark:")) {
                        $mid = trim(substr($l[$j], 11));
                    } else if ($l[$j] === "") {
                        $bodypos = $j + 1;
                    }
                }
                ++$j;
            }
            $bodypos = $bodypos ?? $j;

            $body = preg_replace('/^\\\\\\*/m', "*", join("\n", array_slice($l, $bodypos, $j - $bodypos)) . "\n");
            if ($mid !== "" && trim($body) !== "") {
                $mex = new MailCheckerExpectation;
                $mex->header = join("\n", array_slice($l, $i + 1, $bodypos - $i - 1)) . "\n";
                $mex->body = preg_replace('/^\\\\\\*/m', "*", $body);
                $lineno = $i + 1;
                $mex->landmark = $file ? "{$file}:{$lineno}" : "line {$lineno}";
                self::$messagedb[$mid][] = $mex;
            }

            $i = $j;
        }
    }
}

MailChecker::add_messagedb(file_get_contents(SiteLoader::find("test/emails.txt")), "test/emails.txt");


class ProfileTimer {
    /** @var array<string,float> */
    public $times = [];
    /** @var float */
    public $last_time;

    function __construct() {
        $this->last_time = microtime(true);
    }

    /** @param string $name */
    function mark($name) {
        assert(!isset($this->times[$name]));
        $t = microtime(true);
        $this->times[$name] = $t - $this->last_time;
        $this->last_time = $t;
    }
}

#[Attribute]
class SkipLandmark {
}

#[Attribute]
class RequireCdb {
    public $required;
    function __construct($required = true) {
        $this->required = $required;
    }
}

#[Attribute]
class RequireDb {
    /** @var bool|'fresh' */
    public $required;
    function __construct($required = true) {
        $this->required = $required;
    }
}

class Xassert {
    /** @var int */
    static public $n = 0;
    /** @var int */
    static public $nsuccess = 0;
    /** @var int */
    static public $nerror = 0;
    /** @var int */
    static public $disabled = 0;
    /** @var ?list<int> */
    static private $group_stack = [];
    /** @var ?string */
    static public $context = null;
    /** @var bool */
    static public $stop = false;
    /** @var bool */
    static public $retry = false;
    /** @var ?TestRunner */
    static public $test_runner;
    /** @var array<int,string> */
    static public $emap = [
        E_ERROR => "PHP Fatal Error",
        E_WARNING => "PHP Warning",
        E_NOTICE => "PHP Notice",
        E_USER_ERROR => "PHP Error",
        E_USER_WARNING => "PHP Warning",
        E_USER_NOTICE => "PHP Notice"
    ];

    static function succeed() {
        ++self::$n;
        ++self::$nsuccess;
    }

    static function fail() {
        ++self::$n;
        if (self::$stop) {
            throw new ErrorException("error at assertion #" . self::$n);
        }
    }

    static function push_failure_group() {
        self::$group_stack[] = self::$n;
    }

    static function pop_failure_group() {
        assert(!empty(self::$group_stack));
        $n = array_pop(self::$group_stack);
        if (self::$n > $n) {
            self::$n = $n + 1;
        } else {
            ++self::$n;
            ++self::$nsuccess;
        }
    }

    static function will_print() {
        if (self::$test_runner) {
            self::$test_runner->will_print();
        }
    }

    static function print_landmark() {
        list($location, $rest) = self::landmark(true);
        $x = $location . $rest;
        if ($x !== "") {
            self::will_print();
            if (!str_ends_with($x, "\n")) {
                $x .= "\n";
            }
            fwrite(STDERR, $x);
        }
    }

    /** @param list<string> $sl */
    static private function fail_message($sl) {
        if (self::$retry) {
            return;
        }
        if (self::$test_runner) {
            self::$test_runner->will_fail();
        }
        if (($sl[0] ?? null) === "!") {
            $x = join("", array_slice($sl, 1));
        } else {
            list($location, $rest) = self::landmark(true);
            $x = $location . join("", $sl) . $rest;
        }
        if ($x !== "" && !str_ends_with($x, "\n")) {
            $x .= "\n";
        }
        fwrite(STDERR, $x);
    }

    /** @param string ...$sl */
    static function fail_with(...$sl) {
        self::fail_message($sl);
        self::fail();
    }

    /** @param string ...$sl */
    static function error_with(...$sl) {
        self::fail_message($sl);
        ++self::$nerror;
    }

    /** @param string $xprefix
     * @param string $eprefix
     * @param mixed $expected
     * @param string $aprefix
     * @param mixed $actual
     * @return string */
    static function match_failure_message($xprefix, $eprefix, $expected, $aprefix, $actual) {
        $estr = xassert_var_export($expected);
        $astr = xassert_var_export($actual);
        if (strlen($estr) < 20 && strlen($astr) < 20) {
            return "{$xprefix}{$eprefix}{$estr}{$aprefix}{$astr}\n";
        } else {
            $xprefix = rtrim($xprefix);
            if (str_starts_with($aprefix, ",")) {
                $aprefix = substr($aprefix, 1);
            }
            $aprefix = ltrim($aprefix);
            $eprefix = str_pad($eprefix, max(strlen($eprefix), strlen($aprefix)), " ", STR_PAD_LEFT);
            $aprefix = str_pad($aprefix, strlen($eprefix), " ", STR_PAD_LEFT);
            return "{$xprefix}\n  {$eprefix}{$estr}\n  {$aprefix}{$astr}\n";
        }
    }

    /** @param string $eprefix
     * @param mixed $expected
     * @param string $aprefix
     * @param mixed $actual
     * @param string $rest
     * @return void */
    static function fail_match($eprefix, $expected, $aprefix, $actual, $rest = "") {
        list($location, $xrest) = self::landmark(true);
        self::fail_with("!", self::match_failure_message($location, $eprefix, $expected, $aprefix, $actual), $xrest, $rest);
    }

    /** @param bool $want_color
     * @return array{string,string} */
    static function landmark($want_color = false) {
        $colorize = $want_color && self::$test_runner && self::$test_runner->color;
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
        $first = "";
        $rest = "";
        for ($pos = 2; isset($trace[$pos]); ++$pos) {
            $tr = $trace[$pos];
            $fname = (isset($tr["class"]) ? $tr["class"] . "::" : "") . $tr["function"];
            if (preg_match('/\A[Xx]?assert|\AMailChecker::check|::x?assert/s', $fname)) {
                continue;
            }
            if (PHP_MAJOR_VERSION >= 8 && !str_contains($fname, "{closure")) {
                if (isset($tr["class"])) {
                    $refl = new ReflectionMethod($tr["class"], $tr["function"]);
                } else {
                    $refl = new ReflectionFunction($tr["function"]);
                }
                if ($refl->getAttributes("SkipLandmark")) {
                    continue;
                }
            }
            $loc = $fname;
            if (($file = $trace[$pos - 1]["file"] ?? null) !== null) {
                if (str_starts_with($file, SiteLoader::$root)) {
                    $file = substr($file, strlen(SiteLoader::$root) + 1);
                }
                $loc = $file . ":" . $trace[$pos - 1]["line"] . ":" . $fname;
            } else {
                $loc = $fname;
            }
            if ($first === "") {
                if (self::$context !== null) {
                    $loc .= " <" . self::$context . ">";
                }
                if ($colorize) {
                    $first = "\x1b[1m{$loc}:\x1b[m ";
                } else if ($want_color) {
                    $first = "{$loc}: ";
                } else {
                    $first = $loc;
                }
            } else if ($colorize) {
                $rest .= "  \x1b[90;1m{$loc}:\x1b[22m called from here\x1b[m\n";
            } else {
                $rest .= "  {$loc}: called from here\n";
            }
            if (preg_match('/::test[_A-Z]/', $fname)) {
                return [$first, $rest];
            }
        }
        return [$first, ""];
    }

    /** @param int $ntries
     * @param callable $f */
    static function retry($ntries, $f) {
        $r = self::$retry;
        self::$retry = true;
        while ($ntries > 1) {
            --$ntries;
            $n = self::$n;
            $nsuccess = self::$nsuccess;
            $f();
            if (self::$nsuccess === $nsuccess + self::$n - $n) {
                self::$retry = $r;
                return;
            }
            self::$n = $n;
            self::$nsuccess = $nsuccess;
        }
        self::$retry = $r;
        $f();
    }
}

/** @param int $errno
 * @param string $emsg
 * @param string $file
 * @param int $line */
function xassert_error_handler($errno, $emsg, $file, $line) {
    if ((error_reporting() & $errno) === 0
        || Xassert::$disabled > 0) {
        return;
    }
    if (($e = Xassert::$emap[$errno] ?? null)) {
        $emsg = "{$e}:  {$emsg}";
    } else {
        $emsg = "PHP Message {$errno}:  {$emsg}";
    }
    Xassert::error_with("!", "{$emsg} in {$file} on line {$line}\n");
}

set_error_handler("xassert_error_handler");

/** @param mixed $x
 * @return string */
function xassert_var_export($x) {
    if (is_scalar($x)) {
        return json_encode($x);
    } else if (is_object($x)) {
        $cn = get_class($x);
        $ch = spl_object_id($x);
        $xp = "[{$cn}#{$ch}]";
        if (($s = json_encode($x))) {
            $s = strlen($s) > 120 ? substr($s, 0, 120) . "...}" : $s;
            $xp .= $s;
        }
        return $xp;
    } else if (($s = json_encode($x))) {
        return strlen($s) > 121 ? substr($s, 0, 120) . "..." : $s;
    } else {
        return "[" . gettype($s) . "]";
    }
}

/** @param mixed $x
 * @param string $description
 * @return bool */
function xassert($x, $description = "") {
    if ($x) {
        Xassert::succeed();
    } else {
        Xassert::fail_with($description ? : "assertion failed");
    }
    return !!$x;
}

/** @param string $rest
 * @return bool */
function xassert_eqq($actual, $expected, $rest = "") {
    $ok = $actual === $expected;
    if (!$ok && is_float($expected) && is_nan($expected) && is_float($actual) && is_nan($actual)) {
        $ok = true;
    }
    if ($ok) {
        Xassert::succeed();
    } else {
        Xassert::fail_match("expected === ", $expected, ", got ", $actual, $rest);
    }
    return $ok;
}

/** @return bool */
function xassert_neqq($actual, $nonexpected) {
    $ok = $actual !== $nonexpected;
    if ($ok && is_float($nonexpected) && is_nan($nonexpected) && is_float($actual) && is_nan($actual)) {
        $ok = false;
    }
    if ($ok) {
        Xassert::succeed();
    } else {
        Xassert::fail_with("expected !== " . xassert_var_export($actual));
    }
    return $ok;
}

/** @param null|int|float|string $member
 * @param list<null|int|float|string> $list
 * @return bool */
function xassert_in_eqq($member, $list) {
    $ok = false;
    $nan = is_float($member) && is_nan($member);
    foreach ($list as $bx) {
        if ($member === $bx || ($nan && is_float($bx) && is_nan($bx))) {
            $ok = true;
            break;
        }
    }
    if ($ok) {
        Xassert::succeed();
    } else {
        Xassert::fail_match("expected ∈ ", $list, ", got ", $member);
    }
    return $ok;
}

/** @param null|int|float|string $member
 * @param list<null|int|float|string> $list
 * @return bool */
function xassert_not_in_eqq($member, $list) {
    $ok = true;
    $nan = is_float($member) && is_nan($member);
    foreach ($list as $bx) {
        if ($member === $bx || ($nan && is_float($bx) && is_nan($bx))) {
            $ok = false;
            break;
        }
    }
    if ($ok) {
        Xassert::succeed();
    } else {
        Xassert::fail_match("expected ∉ ", $list, ", got ", $member);
    }
    return $ok;
}

/** @param null|int|float|string $actual
 * @param null|int|float|string $expected
 * @return bool */
function xassert_eq($actual, $expected) {
    $ok = $actual == $expected;
    if ($ok) {
        Xassert::succeed();
    } else {
        Xassert::fail_match("expected == ", $expected, ", got ", $actual);
    }
    return $ok;
}

/** @param null|int|float|string $actual
 * @param null|int|float|string $nonexpected
 * @return bool */
function xassert_neq($actual, $nonexpected) {
    $ok = $actual != $nonexpected;
    if ($ok) {
        Xassert::succeed();
    } else {
        Xassert::fail_with("expected != " . var_export($actual, true));
    }
    return $ok;
}

/** @param null|int|float|string $actual
 * @param null|int|float|string $expected_bound
 * @return bool */
function xassert_lt($actual, $expected_bound) {
    $ok = $actual < $expected_bound;
    if ($ok) {
        Xassert::succeed();
    } else {
        Xassert::fail_match("expected < ", $expected_bound, ", got ", $actual);
    }
    return $ok;
}

/** @param null|int|float|string $actual
 * @param null|int|float|string $expected_bound
 * @return bool */
function xassert_le($actual, $expected_bound) {
    $ok = $actual <= $expected_bound;
    if ($ok) {
        Xassert::succeed();
    } else {
        Xassert::fail_match("expected <= ", $expected_bound, ", got ", $actual);
    }
    return $ok;
}

/** @param null|int|float|string $actual
 * @param null|int|float|string $expected_bound
 * @return bool */
function xassert_ge($actual, $expected_bound) {
    $ok = $actual >= $expected_bound;
    if ($ok) {
        Xassert::succeed();
    } else {
        Xassert::fail_match("expected >= ", $expected_bound, ", got ", $actual);
    }
    return $ok;
}

/** @param null|int|float|string $actual
 * @param null|int|float|string $expected_bound
 * @return bool */
function xassert_gt($actual, $expected_bound) {
    $ok = $actual > $expected_bound;
    if ($ok) {
        Xassert::succeed();
    } else {
        Xassert::fail_match("expected > ", $expected_bound, ", got ", $actual);
    }
    return $ok;
}

/** @param string $haystack
 * @param string $needle
 * @return bool */
function xassert_str_starts_with($haystack, $needle) {
    $ok = str_starts_with($haystack, $needle);
    if ($ok) {
        Xassert::succeed();
    } else {
        Xassert::fail_with("expected `{$haystack}` to start with `{$needle}`");
    }
    return $ok;
}

/** @param string $haystack
 * @param string $needle
 * @return bool */
function xassert_str_contains($haystack, $needle) {
    $ok = strpos($haystack, $needle) !== false;
    if ($ok) {
        Xassert::succeed();
    } else {
        Xassert::fail_with("expected `{$haystack}` to contain `{$needle}`");
    }
    return $ok;
}

/** @param string $haystack
 * @param string $needle
 * @return bool */
function xassert_not_str_contains($haystack, $needle) {
    $ok = strpos($haystack, $needle) === false;
    if ($ok) {
        Xassert::succeed();
    } else {
        Xassert::fail_with("expected `{$haystack}` to not contain `{$needle}`");
    }
    return $ok;
}

/** @param ?list<mixed> $actual
 * @param ?list<mixed> $expected
 * @param bool $sort
 * @return bool */
function xassert_array_eqq($actual, $expected, $sort = false) {
    $problem = "";
    if (is_array($actual) && is_array($expected)) {
        if (count($actual) !== count($expected)
            && !$sort) {
            $problem = "expected size " . count($expected) . ", got " . count($actual);
        } else if (!array_is_list($actual) || !array_is_list($expected)) {
            $problem = "associative arrays";
        } else {
            if ($sort) {
                sort($actual);
                sort($expected);
            }
            for ($i = 0; $i < count($actual) && $i < count($expected); ++$i) {
                if ($actual[$i] !== $expected[$i]) {
                    $problem = rtrim(Xassert::match_failure_message("value {$i} differs: ", "expected === ", $expected[$i], ", got ", $actual[$i]));
                    break;
                }
            }
            if (!$problem && count($actual) !== count($expected)) {
                $problem = "expected size " . count($expected) . ", got " . count($actual);
            }
        }
    } else if ($actual !== null || $expected !== null) {
        $problem = "different types";
    }
    if ($problem === "") {
        Xassert::succeed();
    } else {
        $ml = ["Array assertion failed, {$problem}\n"];
        if ($sort) {
            $aj = json_encode(array_slice($actual, 0, 10));
            if (count($actual) > 10) {
                $aj .= "...";
            }
            $bj = json_encode(array_slice($expected, 0, 10));
            if (count($expected) > 10) {
                $bj .= "...";
            }
            $ml[] = "  expected {$bj}, got {$aj}\n";
        }
        Xassert::fail_with(...$ml);
    }
    return $problem === "";
}

/** @return bool */
function xassert_match($a, $b) {
    $ok = is_string($a) && preg_match($b, $a);
    if ($ok) {
        Xassert::succeed();
    } else {
        Xassert::fail_match("expected ", var_export($a, true), " ~= ", $b);
    }
    return $ok;
}

/** @param list<int> $actual
 * @param list<int|string>|string|int $expected
 * @return bool */
function xassert_int_list_eqq($actual, $expected) {
    $astr = join(" ", $actual);
    $estr = is_array($expected) ? join(" ", $expected) : (string) $expected;
    $estr = preg_replace_callback('/(\d+)-(\d+)/', function ($m) {
        return join(" ", range(+$m[1], +$m[2]));
    }, $estr);
    if ($astr === "") {
        $astr = "<empty>";
    }
    if ($estr === "") {
        $estr = "<empty>";
    }
    $ok = $astr === $estr;
    if ($ok) {
        Xassert::succeed();
    } else {
        Xassert::fail_match("expected ", $estr, ", got ", $astr);
    }
    return $ok;
}


/** @param Contact $user
 * @param string|array $query
 * @param string $cols
 * @param bool $allow_warnings
 * @return array<int,array> */
function search_json($user, $query, $cols = "id", $allow_warnings = false) {
    $pl = new PaperList("empty", new PaperSearch($user, $query));
    $pl->parse_view($cols, PaperList::VIEWORIGIN_MAX);
    if ($pl->has_problem() && !$allow_warnings) {
        Xassert::will_print();
        list($first, $rest) = Xassert::landmark(true);
        fwrite(STDERR, "{$first}Search reports warnings: " . $pl->full_feedback_text() . $rest);
    }
    return $pl->text_json();
}

/** @param Contact $user
 * @param string|array $query
 * @param string $col
 * @return string */
function search_text_col($user, $query, $col = "id") {
    $pl = new PaperList("empty", new PaperSearch($user, $query));
    $pl->parse_view($col, PaperList::VIEWORIGIN_MAX);
    $tj = $pl->text_json();
    $colx = ($pl->vcolumns())[0]->name;
    $x = [];
    foreach ($tj as $pid => $p) {
        $x[] = $pid . " " . $p[$colx] . "\n";
    }
    return join("", $x);
}

/** @param Contact $user
 * @param string|array $query
 * @param list<int|string>|string|int $expected
 * @return bool */
function xassert_search($user, $query, $expected) {
    return xassert_int_list_eqq(array_keys(search_json($user, $query)), $expected);
}

/** @param Contact $user
 * @param string|array $query
 * @param list<int|string>|string|int $expected
 * @return bool */
function xassert_search_all($user, $query, $expected) {
    $q = is_string($query) ? ["q" => $query] : $query;
    $q["t"] = $q["t"] ?? "all";
    return xassert_int_list_eqq(array_keys(search_json($user, $q)), $expected);
}

/** @param Contact $user
 * @param string|array $query
 * @param list<int|string>|string|int $expected
 * @return bool */
function xassert_search_ignore_warnings($user, $query, $expected) {
    return xassert_int_list_eqq(array_keys(search_json($user, $query, "id", true)), $expected);
}

/** @return bool */
function assert_query($q, $b) {
    return xassert_eqq(join("\n", Dbl::fetch_first_columns($q)), $b);
}

/** @return int */
function tag_normalize_compare($a, $b) {
    $a_twiddle = strpos($a, "~");
    $b_twiddle = strpos($b, "~");
    $ax = ($a_twiddle > 0 ? substr($a, $a_twiddle + 1) : $a);
    $bx = ($b_twiddle > 0 ? substr($b, $b_twiddle + 1) : $b);
    if (($cmp = strcasecmp($ax, $bx)) === 0) {
        if (($a_twiddle > 0) !== ($b_twiddle > 0)) {
            $cmp = ($a_twiddle > 0 ? 1 : -1);
        } else {
            $cmp = strcasecmp($a, $b);
        }
    }
    return $cmp;
}

/** @param PaperInfo $prow
 * @return string */
function paper_tag_normalize($prow) {
    $t = [];
    $pcm = $prow->conf->pc_members();
    foreach (explode(" ", $prow->all_tags_text()) as $tag) {
        if (($twiddle = strpos($tag, "~")) > 0
            && ($c = $pcm[(int) substr($tag, 0, $twiddle)] ?? null)) {
            $at = strpos($c->email, "@");
            $tag = ($at ? substr($c->email, 0, $at) : $c->email) . substr($tag, $twiddle);
        }
        if (str_ends_with($tag, "#0")) {
            $tag = substr($tag, 0, strlen($tag) - 2);
        }
        if ($tag) {
            $t[] = $tag;
        }
    }
    usort($t, "tag_normalize_compare");
    return join(" ", $t);
}

/** @param Contact $who
 * @return bool */
function xassert_assign($who, $what, $override = false) {
    $assignset = new AssignmentSet($who);
    $assignset->set_override_conflicts($override);
    $assignset->parse($what);
    $ok = $assignset->execute();
    xassert($ok);
    if (!$ok) {
        Xassert::will_print();
        fwrite(STDERR, preg_replace('/^/m', "  ", $assignset->full_feedback_text()));
    }
    return $ok;
}

/** @param Contact $who
 * @return bool */
function xassert_assign_fail($who, $what, $override = false) {
    $assignset = new AssignmentSet($who);
    $assignset->set_override_conflicts($override);
    $assignset->parse($what);
    return xassert(!$assignset->execute());
}

/** @param ?int $maxstatus */
function xassert_paper_status(PaperStatus $ps, $maxstatus = null) {
    $xmaxstatus = $maxstatus ?? MessageSet::PLAIN;
    $ok = true;
    foreach ($ps->message_list() as $mx) {
        if ($mx->status > $maxstatus
            && ($maxstatus !== null
                || $mx->status !== MessageSet::WARNING
                || $mx->field !== "submission"
                || $mx->message !== "<0>Entry required to complete submission")) {
            $ok = false;
            break;
        }
    }
    if (!$ok) {
        xassert($ps->problem_status() <= $xmaxstatus);
        Xassert::will_print();
        foreach ($ps->message_list() as $mx) {
            if ($mx->status === MessageSet::INFORM && $mx->message) {
                fwrite(STDERR, "!     {$mx->message}\n");
            } else {
                fwrite(STDERR, "! {$mx->field}" . ($mx->message ? ": {$mx->message}\n" : "\n"));
            }
        }
    }
}

/** @param int $maxstatus */
function xassert_paper_status_saved_nonrequired(PaperStatus $ps, $maxstatus = MessageSet::PLAIN) {
    xassert($ps->save_status_prepared());
    if ($ps->problem_status() > $maxstatus) {
        $asserted = false;
        foreach ($ps->problem_list() as $mx) {
            if ($mx->message !== "<0>Entry required"
                && $mx->message !== "<0>Entry required to complete submission") {
                Xassert::will_print();
                if (!$asserted) {
                    xassert($ps->problem_status() <= $maxstatus);
                    $asserted = true;
                }
                fwrite(STDERR, "! {$mx->field}" . ($mx->message ? ": {$mx->message}\n" : "\n"));
            }
        }
    }
}


/** @param Contact|TokenInfo $user
 * @param ?PaperInfo $prow
 * @return Downloader|JsonResult */
function call_api_result($fn, $user, $qreq, $prow = null) {
    if (($is_post = str_starts_with($fn, "="))) {
        $fn = substr($fn, 1);
    }
    if (!($qreq instanceof Qrequest)) {
        if ($is_post) {
            $qreq = new Qrequest("POST", $qreq);
            $qreq->approve_token();
        } else {
            $qreq = new Qrequest("GET", $qreq);
        }
    }
    if ($user instanceof TokenInfo) {
        $token = $user;
        if ($token->capabilityType !== TokenInfo::BEARER
            || !$token->is_active()) {
            return JsonResult::make_error(401, "<0>Unauthorized");
        }
        $qreq->set_header("Authorization", "Bearer {$token->salt}");
        $qreq->approve_token();
        $user = clone $token->local_user();
        $user->set_bearer_authorized();
        $user->set_scope($token->data("scope"));
    } else {
        assert(!$user->is_bearer_authorized());
    }
    $qreq->set_navigation(Navigation::get());
    $qreq->set_user($user);
    if ($prow) {
        $qreq->set_paper($prow);
    } else if ($qreq->p && ctype_digit((string) $qreq->p)) {
        $user->conf->set_paper_request($qreq, $user);
    }
    Qrequest::set_main_request($qreq);
    $uf = $user->conf->api($fn, $user, $qreq->method());
    return $user->conf->call_api_on($uf, $fn, $user, $qreq);
}

/** @param Contact|TokenInfo $user
 * @param ?PaperInfo $prow
 * @return object */
function call_api($fn, $user, $qreq, $prow = null) {
    $jr = call_api_result($fn, $user, $qreq, $prow);
    if (!($jr instanceof JsonResult)) {
        $jr = JsonResult::make_error(500, "<0>Not a JSON");
    }
    if ($jr->minimal) {
        if (is_array($jr->content) && !is_list($jr->content)) {
            return (object) $jr->content;
        }
        /** @phan-suppress-next-line PhanTypeMismatchReturn */
        return $jr->content;
    }
    if (!isset($jr->content["status_code"]) && $jr->status > 299) {
        $jr->content["status_code"] = $jr->status;
    }
    return (object) $jr->content;
}

/** @param Conf $conf
 * @param string $id
 * @return Score_ReviewField */
function review_score($conf, $id) {
    $rf = $conf->checked_review_field($id);
    assert($rf instanceof Score_ReviewField);
    return $rf;
}

/** @param int|PaperInfo $prow
 * @param Contact $user
 * @return ?ReviewInfo */
function fresh_review($prow, $user) {
    if (is_int($prow)) {
        $prow = $user->conf->checked_paper_by_id($prow, $user);
    }
    return $prow->fresh_review_by_user($user);
}

/** @param int|PaperInfo $prow
 * @param Contact $user
 * @return ReviewInfo */
function checked_fresh_review($prow, $user) {
    if (is_int($prow)) {
        $prow = $user->conf->checked_paper_by_id($prow, $user);
    }
    if (($rrow = $prow->fresh_review_by_user($user))) {
        return $rrow;
    } else {
        throw new Exception("checked_fresh_review failed");
    }
}

/** @param int|PaperInfo $prow
 * @param Contact $user
 * @param ?ReviewInfo $rrow
 * @return ?ReviewInfo */
function save_review($prow, $user, $revreq, $rrow = null, $args = []) {
    if (is_int($prow)) {
        $prow = $user->conf->checked_paper_by_id($prow, $user);
    }
    $rf = Conf::$main->review_form();
    $tf = new ReviewValues($rf);
    $tf->parse_qreq(new Qrequest("POST", $revreq));
    $rrowx = $rrow ?? $prow->fresh_review_by_user($user);
    $tf->check_and_save($user, $prow, $rrowx);
    if (!($args["quiet"] ?? false)) {
        foreach ($tf->problem_list() as $mx) {
            Xassert::will_print();
            if ($mx->field) {
                fwrite(STDERR, "! {$mx->field}" . ($mx->message ? ": {$mx->message}\n" : "\n"));
            } else if ($mx->message) {
                fwrite(STDERR, "! {$mx->message}\n");
            }
        }
    }
    if ($rrowx) {
        return $prow->fresh_review_by_id($rrowx->reviewId);
    } else {
        return $prow->fresh_review_by_user($user);
    }
}

/** @return Contact */
function user($email) {
    return Conf::$main->checked_user_by_email($email);
}

/** @return ?Contact */
function maybe_user($email) {
    return Conf::$main->fresh_user_by_email($email);
}

/** @param string $email
 * @param bool $iscdb
 * @return ?string */
function password($email, $iscdb = false) {
    $dblink = $iscdb ? Conf::$main->contactdb() : Conf::$main->dblink;
    $result = Dbl::qe($dblink, "select password from ContactInfo where email=?", $email);
    $row = Dbl::fetch_first_row($result);
    return $row[0] ?? null;
}

/** @param string $email
 * @param ?string $encoded_password
 * @param bool $iscdb */
function save_password($email, $encoded_password, $iscdb = false) {
    $dblink = $iscdb ? Conf::$main->contactdb() : Conf::$main->dblink;
    Dbl::qe($dblink, "update ContactInfo set password=?, passwordTime=?, passwordUseTime=? where email=?", $encoded_password, Conf::$now + 1, Conf::$now + 1, $email);
    Conf::advance_current_time(Conf::$now + 2);
    if ($iscdb) {
        Conf::$main->invalidate_user(Contact::make_cdb_email(Conf::$main, $email));
    }
}

const TESTSC_ALL = 7;
const TESTSC_CONTACTS = 1;
const TESTSC_CONFLICTS = 2;
const TESTSC_ENABLED = 3;
const TESTSC_DISABLED = 4;

/** @param int $flags
 * @return string */
function sorted_conflicts(PaperInfo $prow, $flags) {
    $c = [];
    foreach ($prow->conflict_list() as $cu) {
        if (($cu->conflictType >= CONFLICT_AUTHOR
             ? ($flags & TESTSC_CONTACTS) !== 0
             : ($flags & TESTSC_CONFLICTS) !== 0)
            && (!$cu->user->is_dormant() || ($flags & TESTSC_DISABLED) !== 0))
            $c[] = $cu->user->email;
    }
    sort($c);
    return join(" ", $c);
}


class TestRunner {
    static public $original_opt;

    /** @var array<string,list<string>> */
    static public $collections = [
        "test01" => [
            "fresh_db", "Permission_Tester", "Tags_Tester"
        ],
        "test02" => [
            "Unit_Tester", "XtCheck_Tester", "Navigation_Tester",
            "AuthorMatch_Tester", "Ht_Tester", "Fmt_Tester",
            "Getopt_Tester", "CleanHTML_Tester", "Abbreviation_Tester",
            "DocumentBasics_Tester", "FixCollaborators_Tester",
            "Mention_Tester", "Search_Tester", "Settings_Tester",
            "UpdateSchema_Tester", "Batch_Tester", "Mimetype_Tester"
        ],
        "test03" => [
            "MinCostMaxFlow_Tester"
        ],
        "test04" => [
            "Cdb_Tester"
        ],
        "test05" => [
            "fresh_db", "PaperStatus_Tester", "AuthorCertification_Tester",
            "Login_Tester", "UserStatus_Tester",
            "(", "no_cdb", "Login_Tester", "UserStatus_Tester", ")"
        ],
        "test06" => [
            "fresh_db", "Reviews_Tester", "Comments_Tester", "UserAPI_Tester",
            "UploadAPI_Tester", "Mailer_Tester", "Events_Tester",
            "Autoassign_Tester"
        ],
        "test07" => [
            "DiffMatchPatch_Tester"
        ],
        "test08" => [
            "(", "no_cdb", "fresh_db", "Permission_Tester", "Unit_Tester",
            "XtCheck_Tester", "Abbreviation_Tester",
            "DocumentBasics_Tester", "Mention_Tester",
            "Invariants_Tester", "Search_Tester", "Settings_Tester",
            "Invariants_Tester", "UpdateSchema_Tester",
            "Invariants_Tester", "Batch_Tester",
            "PaperStatus_Tester", "Login_Tester",
            "fresh_db", "Reviews_Tester", "Comments_Tester",
            "UserAPI_Tester", ")"
        ],
        "test09" => [
            "fresh_db", "PaperAPI_Tester", "ReviewAPI_Tester",
            "Scope_Tester"
        ],
        "default" => [
            "test01", "test02", "test03", "test04", "test05", "test06",
            "test07", "(", "if_all", "test08", ")", "test09"
        ],
        "all" => [
            "test01", "test02", "test03", "test04", "test05", "test06",
            "test07", "test08", "test09"
        ]
    ];

    /** @var bool */
    private $verbose;
    /** @var bool */
    private $all;
    /** @var bool */
    private $skipping = false;
    /** @var ?bool */
    private $reset;
    /** @var bool */
    private $need_fresh = true;
    /** @var bool
     * @readonly */
    public $color;
    /** @var int */
    private $width = 64;
    /** @var ?string */
    private $last_classname;
    /** @var ?object */
    private $tester;
    /** @var bool */
    private $need_newline = false;
    /** @var ?string */
    private $verbose_test;
    /** @var ?object */
    private $save_stack;
    /** @var int */
    private $test_index = 0;
    /** @var int */
    private $test_count = 1;
    /** @var int */
    private $test_digits = 1;


    function __construct($arg) {
        $this->verbose = isset($arg["verbose"]);
        $this->all = isset($arg["all"]);
        if (isset($arg["stop"])) {
            Xassert::$stop = true;
        }
        if (isset($arg["no-cdb"])) {
            Conf::$main->set_opt("contactdbDsn", null);
            self::$original_opt["contactdbDsn"] = null;
        }
        if (isset($arg["reset"])) {
            $this->reset = true;
        } else if (isset($arg["no-reset"])) {
            $this->reset = false;
        } else {
            $this->reset = null;
        }
        if (isset($arg["color"])) {
            $this->color = true;
        } else if (isset($arg["no-color"])) {
            $this->color = false;
        } else {
            $this->color = posix_isatty(STDERR);
        }
    }

    static private function setup_assignments($assignments, Contact $user) {
        if (is_array($assignments)) {
            $assignments = join("\n", $assignments);
        }
        $assignset = (new AssignmentSet($user))->set_override_conflicts(true);
        $assignset->parse($assignments);
        if (!$assignset->execute()) {
            error_log("* Failed to run assignments:\n" . $assignset->full_feedback_text());
            exit(1);
        }
    }

    /** @param \mysqli $dblink
     * @param string $filename
     * @param bool $rebuild */
    static private function reset_schema($dblink, $filename, $rebuild = false) {
        $s0 = file_get_contents($filename);
        assert($s0 !== false);

        $s = preg_replace('/\s*(?:--|\#).*/m', "", $s0);
        $truncates = [];
        while (!$rebuild && preg_match('/\A\s*((?:DROP|CREATE)\C*?;)$/mi', $s, $m)) {
            $stmt = $m[1];
            $s = substr($s, strlen($m[0]));
            if (preg_match('/\ACREATE\s*TABLE\s*\`(.*?)\`/i', $stmt, $m)) {
                $truncates[] = "TRUNCATE TABLE `{$m[1]}`;\n";
                if (stripos($stmt, "auto_increment") !== false) {
                    $truncates[] = "ALTER TABLE `{$m[1]}` AUTO_INCREMENT=0;\n";
                }
            } else if (!preg_match('/\ADROP\s*TABLE\s*(?:IF\s*EXISTS\s*|)\`.*?\`;\z/', $stmt)) {
                $rebuild = true;
                break;
            }
        }

        if ($rebuild
            || !preg_match('/\A\s*insert into Settings[^;]*\(\'(allowPaperOption|sversion)\',\s*(\d+)[,\)]/mi', $s, $m)
            || Dbl::fetch_ivalue($dblink, "select value from Settings where name=?", $m[1]) !== intval($m[2])) {
            $rebuild = true;
        }

        if ($rebuild) {
            $query = $s0;
        } else {
            $query = join("", $truncates) . $s;
        }

        $mresult = Dbl::multi_q_raw($dblink, $query);
        $mresult->free_all();
        if ($dblink->errno) {
            error_log("* Error initializing database.\n{$dblink->error}");
            exit(1);
        }
        Conf::set_current_time();
    }

    /** @param bool $first */
    static function reset_options($first = false) {
        Conf::$main->qe("insert into Settings set name='options', value=1, data='[{\"id\":1,\"name\":\"Calories\",\"abbr\":\"calories\",\"type\":\"numeric\",\"order\":1,\"display\":\"default\"}]' ?U on duplicate key update data=?U(data)");
        Conf::$main->qe("alter table PaperOption auto_increment=2");
        Conf::$main->qe("delete from PaperOption where optionId!=1");
        Conf::$main->load_settings();
    }

    /** @param bool $rebuild */
    static function reset_db($rebuild = false) {
        $conf = Conf::$main;
        $conf->call_shutdown_functions();
        $timer = new ProfileTimer;
        MailChecker::clear();

        // Initialize from an empty database
        self::reset_schema($conf->dblink, SiteLoader::find("src/schema.sql"), $rebuild);
        $timer->mark("schema");

        // No setup phase; initial review rounds
        $conf->qe_raw("delete from Settings where name='setupPhase'");
        $conf->qe("insert into Settings (name, value, data) values (?, ?, ?) ?U on duplicate key update data=?U(data)", "tag_rounds", 1, "R1 R2 R3");
        self::reset_options(true);
        $timer->mark("settings");

        // Contactdb.
        if (($cdb = $conf->contactdb())) {
            self::reset_schema($cdb, SiteLoader::find("test/cdb-schema.sql"), $rebuild);
            Dbl::qe($cdb, "insert into Conferences set confuid=?", $conf->dbname);
            Contact::$props["demoBirthday"] = Contact::PROP_CDB | Contact::PROP_NULL | Contact::PROP_INT | Contact::PROP_IMPORT;
        }
        $timer->mark("contactdb");

        // Create initial administrator user.
        $user_chair = Contact::make_keyed($conf, ["email" => "chair@_.com", "name" => "Jane Chair"]);
        assert($user_chair->firstName === "Jane");
        $user_chair = $user_chair->store();
        assert($user_chair->firstName === "Jane");
        $x = $conf->fresh_user_by_email("chair@_.com");
        assert($x->firstName === "Jane");
        $user_chair->save_roles(Contact::ROLE_ADMIN | Contact::ROLE_CHAIR | Contact::ROLE_PC, $user_chair);

        // Load data.
        $json = json_decode(file_get_contents(SiteLoader::find("test/db.json")));
        if (!$json) {
            error_log("* test/testdb.json error: " . json_last_error_msg());
            exit(1);
        }
        $us = new UserStatus($conf->root_user());
        $ok = true;
        foreach ($json->contacts as $c) {
            $us->set_notify(in_array("pc", $c->roles ?? [], true));
            $user = $us->save_user($c);
            if ($user) {
                MailChecker::check_db("create-{$c->email}");
            } else {
                fwrite(STDERR, "* failed to create user {$c->email}\n" . debug_string_backtrace());
                $ok = false;
            }
        }
        $timer->mark("users");
        foreach ($json->papers as $p) {
            $ps = new PaperStatus($conf->root_user());
            if (!$ps->save_paper_json($p)) {
                $t = join("", array_map(function ($mx) {
                    return "    {$mx->field}: {$mx->message}\n";
                }, $ps->message_list()));
                $id = isset($p->_id_) ? "#{$p->_id_} " : "";
                fwrite(STDERR, "* failed to create paper {$id}{$p->title}:\n" . htmlspecialchars_decode($t) . "\n"  . debug_string_backtrace());
                $ok = false;
            }
        }
        if (!$ok) {
            exit(1);
        }
        $timer->mark("papers");

        self::setup_assignments($json->assignments_1, $user_chair);
        $conf->call_shutdown_function("CdbUserUpdate");
        $timer->mark("assignment");
        MailChecker::clear();
    }

    /** @param string $url */
    static function set_navigation_base($url) {
        $nav = Navigation::get();
        $urlp = parse_url($url);
        $nav->protocol = ($urlp["scheme"] ?? "http") . "://";
        $nav->host = $urlp["host"] ?? "example.com";
        $nav->server = $nav->protocol . $nav->host;
        if (($s = $urlp["pass"] ?? null)) {
            $nav->server .= ":{$s}";
        }
        if (($s = $urlp["user"] ?? null)) {
            $nav->server .= "@{$s}";
        }
        if (($s = $urlp["port"] ?? null)) {
            $nav->server .= ":{$s}";
        }
        $nav->base_path = $nav->base_path_relative = $nav->site_path = $nav->site_path_relative =
            $urlp["path"] ?? "/";
    }


    function will_print() {
        if (!$this->need_newline) {
            return;
        }
        if (!$this->color) {
            fwrite(STDERR, "\n");
        } else if (($this->verbose_test ?? "") !== "") {
            fwrite(STDERR, "\r{$this->verbose_test}\x1b[K\n");
        } else {
            fwrite(STDERR, "\n");
        }
        $this->need_newline = false;
    }

    function will_fail() {
        if ($this->verbose_test === null) {
            $this->will_print();
            return;
        }
        if ($this->color) {
            fwrite(STDERR, "\r{$this->verbose_test} \x1b[01;31mFAIL\x1b[m\n");
        } else {
            fwrite(STDERR, " FAIL\n");
        }
        $this->need_newline = false;
        $this->verbose_test = null;
    }

    function set_verbose_test($ro, $m) {
        if (!$this->verbose) {
            return;
        }
        $mpfx = str_pad("{$ro->getName()}::{$m->name} ", $this->width, ".");
        if (strlen($mpfx) > $this->width) {
            $mpfx = rtrim($mpfx);
        }
        $this->verbose_test = $mpfx;
    }

    /** @param object $testo
     * @param string $methodmatch */
    private function run_object_tests($testo, $methodmatch) {
        $ro = new ReflectionObject($testo);
        foreach ($ro->getMethods() as $m) {
            if (!str_starts_with($m->name, "test")
                || strlen($m->name) <= 4
                || ($m->name[4] !== "_" && !ctype_upper($m->name[4]))
                || ($methodmatch !== "" && !fnmatch($methodmatch, $m->name))
                || !$m->isPublic()) {
                continue;
            }
            $this->set_verbose_test($ro, $m);
            if (!$this->check_test_attributes($m)) {
                if ($this->verbose) {
                    if ($this->color) {
                        fwrite(STDERR, "\x1b[90m{$this->verbose_test} \x1b[1;90mSKIP\x1b[m\n");
                    } else {
                        fwrite(STDERR, "{$this->verbose_test} SKIP\n");
                    }
                }
                continue;
            }
            if (!$this->verbose) {
                $m->invoke($testo);
                continue;
            }
            if ($this->color) {
                fwrite(STDERR, "{$this->verbose_test} \x1b[1;36mRUN\x1b[m");
            } else {
                fwrite(STDERR, $this->verbose_test);
            }
            $this->need_newline = true;
            $before_nfail = Xassert::$n - Xassert::$nsuccess;
            $before_nerror = Xassert::$nerror;
            $m->invoke($testo);
            $fail = Xassert::$n - Xassert::$nsuccess > $before_nfail;
            $ok = !$fail && Xassert::$nerror === $before_nerror;
            if ($this->verbose_test !== null) {
                if ($this->color) {
                    $pfx = ($this->need_newline ? "\r" : "") . $this->verbose_test;
                    if ($ok) {
                        $sfx = " \x1b[1;32m OK\x1b[m\x1b[K";
                    } else if (!$fail) {
                        $sfx = " \x1b[1;36mERROR\x1b[m\x1b[K";
                    } else {
                        $sfx = " \x1b[1;31mFAIL\x1b[m";
                    }
                } else {
                    $pfx = $this->need_newline ? "" : $this->verbose_test;
                    if ($ok) {
                        $sfx = "  OK";
                    } else if (!$fail) {
                        $sfx = " ERROR";
                    } else {
                        $sfx = " FAIL";
                    }
                }
                fwrite(STDERR, "{$pfx}{$sfx}\n");
            }
            $this->verbose_test = null;
            $this->need_newline = false;
        }
    }

    private function check_test_attributes($class) {
        if (PHP_MAJOR_VERSION < 8) {
            return true;
        }
        $require_db = $require_cdb = null;
        foreach ($class->getAttributes("RequireDb") ?? [] as $attr) {
            $x = $attr->newInstance();
            $require_db = $x->required;
        }
        foreach ($class->getAttributes("RequireCdb") ?? [] as $attr) {
            $x = $attr->newInstance();
            $require_cdb = $x->required;
        }
        if ($require_cdb !== null
            && $require_cdb !== !!Conf::$main->opt("contactdbDsn")) {
            return false;
        }
        if ($require_db === false) {
            $this->need_fresh = false;
        } else if ($require_db === "fresh") {
            $this->need_fresh = true;
        }
        return true;
    }

    /** @param string $test */
    private function set_test_class($test) {
        if ($this->tester) {
            $ro = new ReflectionObject($this->tester);
            if ($ro->hasMethod("finalize")
                && ($m = $ro->getMethod("finalize"))->isPublic()) {
                $m->invoke($this->tester);
            }
            $this->tester = null;
        }

        $need_fresh = $this->need_fresh;
        $class = new ReflectionClass($test);
        if (!$this->check_test_attributes($class)) {
            if (!$this->save_stack) {
                Xassert::fail_with("!", "Cannot run `{$test}` due to requirement failure");
            }
            $this->need_fresh = $need_fresh;
            return;
        }

        // prepare database
        if ($this->reset ?? $this->need_fresh) {
            self::reset_db($this->reset ?? false);
            $this->need_fresh = false;
            $this->reset = null;
        } else {
            $this->need_fresh = $need_fresh;
        }

        // construct tester
        $this->last_classname = $test;
        $this->tester = null;
        $ctor = $class->getConstructor();
        if ($ctor && $ctor->getNumberOfParameters() === 1) {
            $this->tester = $class->newInstance(Conf::$main);
        } else {
            assert(!$ctor || $ctor->getNumberOfParameters() === 0);
            $this->tester = $class->newInstance();
        }
    }

    private function push_test_state() {
        $this->save_stack = (object) [
            "contactdbDsn" => Conf::$main->opt("contactdbDsn"),
            "skipping" => $this->skipping,
            "next" => $this->save_stack
        ];
    }

    private function pop_test_state() {
        if (!$this->save_stack) {
            return;
        }
        $cdb_dsn = $this->save_stack->contactdbDsn;
        if (Conf::$main->opt("contactdbDsn") !== $cdb_dsn) {
            Conf::$main->set_opt("contactdbDsn", $cdb_dsn);
            Conf::$main->invalidate_caches("cdb");
        }
        $this->skipping = $this->save_stack->skipping;
        $this->save_stack = $this->save_stack->next;
    }

    /** @param string $test */
    private function run_test($test) {
        if ($test === "no_argv") {
            return;
        } else if ($test === "(") {
            $this->push_test_state();
            return;
        } else if ($test === ")") {
            $this->pop_test_state();
            return;
        } else if ($this->skipping) {
            return;
        } else if ($test === "if_all") {
            if (!$this->all) {
                $this->skipping = true;
            }
            return;
        } else if ($test === "no_cdb") {
            Conf::$main->set_opt("contactdbDsn", null);
            Conf::$main->invalidate_caches("cdb");
            $this->last_classname = null;
            return;
        } else if ($test === "reset_db") {
            if (!$this->reset) {
                $this->need_fresh = $this->reset = true;
            }
            $this->last_classname = null;
            return;
        } else if ($test === "fresh_db") {
            $this->need_fresh = true;
            $this->last_classname = null;
            return;
        }

        if ($this->color && !$this->verbose) {
            fwrite(STDERR, sprintf("\r\x1b[38;2;212;23;67m[%{$this->test_digits}d/%d] \x1b[38;2;70;100;150m%s...\x1b[m \x1b[K",
                                   $this->test_index, $this->test_count, $test));
            $this->need_newline = true;
        }

        $methodmatch = "";
        if (($pos = strpos($test, "::")) !== false) {
            $methodmatch = substr($test, $pos + 2);
            $testclass = substr($test, 0, $pos);
        } else {
            $testclass = $test;
        }
        if (strpos($testclass, "_") === false && ctype_alpha($testclass[0])) {
            $testclass .= "_Tester";
        }

        if ($testclass !== $this->last_classname || $methodmatch === "") {
            $this->set_test_class($testclass);
        }
        if ($this->tester) {
            $this->run_object_tests($this->tester, $methodmatch);
        }
    }

    private function run_test_list($tests) {
        $this->test_index = 0;
        $this->test_count = count($tests);
        $this->test_digits = (int) floor(log10(max($this->test_count, 1))) + 1;
        foreach ($tests as $test) {
            ++$this->test_index;
            $this->run_test($test);
        }
        if ($this->color && !$this->verbose) {
            fwrite(STDERR, "\r\x1b[K");
        }
    }

    private function expand($tests) {
        $new_tests = [];
        foreach ($tests as $test) {
            if (isset(self::$collections[$test])) {
                array_push($new_tests, ...$this->expand(self::$collections[$test]));
            } else {
                $new_tests[] = $test;
            }
        }
        return $new_tests;
    }

    private function complete() {
        $ok = Xassert::$nsuccess > 0
            && Xassert::$nsuccess === Xassert::$n
            && Xassert::$nerror === 0;
        $msg = ($ok ? "* " : "! ")
            . plural(Xassert::$nsuccess, "test")
            . " succeeded out of " . Xassert::$n . " tried.";
        if ($this->color) {
            $k = $ok ? "1;38;2;40;160;80" : "1;31";
            $msg = "\x1b[{$k}m{$msg}\x1b[m";
        }
        echo $msg, "\n";
        if (Xassert::$nerror !== 0) {
            $k0 = $this->color ? "\x1b[31m" : "";
            $k1 = $this->color ? "\x1b[m" : "";
            echo $k0, "! ", plural(Xassert::$nerror, "other error"), ".", $k1, "\n";
        }
        return $ok;
    }

    /** @param 'no_cdb'|'reset_db'|'fresh_db'|class-string ...$tests */
    static function run(...$tests) {
        if (($tests[0] ?? "") === "no_argv") {
            $arg = [];
        } else {
            global $argv;
            $arg = (new Getopt)->long(
                "all,a run all test collections",
                "verbose,V be verbose",
                "help,h !",
                "reset,reset reset test database",
                "no-reset,no-reset-db,R do not reset test database",
                "no-cdb no contact database",
                "stop,s stop on first error",
                "color !",
                "no-color !"
            )->description("Usage: php test/" . basename($_SERVER["PHP_SELF"]) . " [-V] [CLASSNAME...]")
             ->helpopt("help")
             ->interleave(true)
             ->parse($argv);
        }

        $tr = new TestRunner($arg);
        Xassert::$test_runner = $tr;
        $test_list = $arg["_"];
        if (empty($test_list)) {
            $test_list = empty($tests) ? ["default"] : $tests;
        }
        $test_list = $tr->expand($test_list);
        $tr->run_test_list($test_list);
        exit($tr->complete() ? 0 : 1);
    }
}

TestRunner::$original_opt = $Opt;
TestRunner::set_navigation_base("/");


class TestQreq {
    /** @param array<string,mixed> $args
     * @return Qrequest */
    static function get($args = []) {
        return (new Qrequest("GET", $args))
            ->set_navigation(Navigation::get());
    }

    /** @param string $page
     * @param array<string,mixed> $args
     * @return Qrequest */
    static function get_page($page, $args = []) {
        $slash = strlpos($page, "/");
        return self::get($args)
            ->set_page(substr($page, 0, $slash))
            ->set_path((string) substr($page, $slash));
    }

    /** @param array<string,mixed> $args
     * @return Qrequest */
    static function post($args = []) {
        return (new Qrequest("POST", $args))
            ->set_navigation(Navigation::get())
            ->approve_token()
            ->set_body(null, "application/x-www-form-urlencoded");
    }

    /** @param string $page
     * @param array<string,mixed> $args
     * @return Qrequest */
    static function post_page($page, $args = []) {
        $slash = strlpos($page, "/");
        return self::post($args)
            ->set_page(substr($page, 0, $slash))
            ->set_path((string) substr($page, $slash));
    }

    /** @param mixed $json
     * @param array<string,mixed> $args
     * @return Qrequest */
    static function post_json($json, $args = []) {
        return self::post($args)
            ->set_body(is_string($json) ? $json : json_encode_db($json),
                       "application/json");
    }

    /** @param array<string,mixed> $contents
     * @param array<string,mixed> $args
     * @return Qrequest */
    static function post_zip($contents, $args = []) {
        if (($fn = tempnam("/tmp", "hctz")) === false) {
            throw new ErrorException("Failed to create temporary file");
        }
        unlink($fn);
        $zip = new ZipArchive;
        $zip->open($fn, ZipArchive::CREATE);
        foreach ($contents as $name => $value) {
            $zip->addFromString($name, is_string($value) ? $value : json_encode_db($value));
        }
        $zip->close();
        $qreq = (new Qrequest("POST", $args))
            ->approve_token()
            ->set_body(file_get_contents($fn), "application/zip");
        unlink($fn);
        return $qreq;
    }

    /** @param array<string,mixed> $args
     * @return Qrequest */
    static function delete($args = []) {
        return (new Qrequest("DELETE", $args))
            ->approve_token()
            ->set_body(null, "application/x-www-form-urlencoded");
    }
}
