<?php
// test/setup.php -- HotCRP helper file to initialize tests
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

require_once(dirname(__DIR__) . "/src/siteloader.php");
define("HOTCRP_OPTIONS", SiteLoader::find("test/options.php"));
define("HOTCRP_TESTHARNESS", true);
ini_set("error_log", "");
ini_set("log_errors", "0");
ini_set("display_errors", "stderr");
ini_set("assert.exception", "1");

require_once(SiteLoader::find("src/init.php"));
initialize_conf();


// Record mail in MailChecker.
class MailChecker {
    /** @var int */
    static public $disabled = 0;
    /** @var bool */
    static public $print = false;
    /** @var list<MailPreparation> */
    static public $preps = [];
    /** @var array<string,list<array{string,string}>> */
    static public $messagedb = [];

    /** @param MailPreparation $prep */
    static function send_hook($fh, $prep) {
        if (self::$disabled === 0) {
            $prep->landmark = "";
            foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $trace) {
                if (isset($trace["file"]) && preg_match('/\/test\d/', $trace["file"])) {
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
                       . "To: " . join(", ", $prep->to) . "\n"
                       . "Subject: " . str_replace("\r", "", $prep->subject) . "\n"
                       . ($prep->landmark ? "X-Landmark: $prep->landmark\n" : "") . "\n"
                       . $prep->body);
            }
        }
        return false;
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
            xassert(isset(self::$messagedb[$name]));
            xassert_eqq(count(self::$messagedb[$name]), count(self::$preps));
            $mdb = self::$messagedb[$name];
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

    /** @param list<string> $mdb */
    static function check_match($mdb) {
        $haves = [];
        foreach (self::$preps as $prep) {
            $haves[] = "To: " . join(", ", $prep->to) . "\n"
                . "Subject: " . str_replace("\r", "", $prep->subject)
                . "\n\n" . $prep->body;
        }
        sort($haves);
        $wants = [];
        foreach ($mdb as $m) {
            if (is_string($m)) {
                $wants[] = $m;
            } else {
                $wants[] = preg_replace('/^X-Landmark:.*?\n/m', "", $m[0]) . $m[1];
            }
        }
        sort($wants);
        foreach ($wants as $want) {
            ++Xassert::$n;
            list($match, $index, $badline) = self::find_best_mail_match($want, $haves);
            if ($match) {
                ++Xassert::$nsuccess;
            } else if ($index !== false) {
                $have = $haves[$index];
                error_log(assert_location() . ": Mail mismatch: " . var_export($want, true) . " !== " . var_export($have, true));
                $havel = explode("\n", $have);
                $wantl = explode("\n", $want);
                fwrite(STDERR, "... line {$badline} differs near {$havel[$badline-1]}\n... expected {$wantl[$badline-1]}\n");
            } else {
                error_log(assert_location() . ": Mail not found: " . var_export($want, true));
            }
            if ($index !== false) {
                array_splice($haves, $index, 1);
            }
        }
        foreach ($haves as $have) {
            ++Xassert::$n;
            error_log(assert_location() . ": Unexpected mail: " . var_export($have, true));
        }
        self::$preps = [];
    }

    static function clear() {
        self::$preps = [];
    }

    /** @param string $text */
    static function add_messagedb($text) {
        preg_match_all('/^\*\*\*\*\*\*\*\*(.*)\n([\s\S]*?\n)(?=^\*\*\*\*\*\*\*\*|\z)/m', $text, $ms, PREG_SET_ORDER);
        foreach ($ms as $m) {
            $m[1] = trim($m[1]);
            $nlpos = strpos($m[2], "\n\n");
            $nlpos = $nlpos === false ? strlen($m[2]) : $nlpos + 2;
            $header = substr($m[2], 0, $nlpos);
            $body = substr($m[2], $nlpos);
            if ($m[1] === ""
                && preg_match('/\nX-Landmark:\s*(\S+)/', $header, $mx)) {
                $m[1] = $mx[1];
            }
            if ($m[1] !== "") {
                if (!isset(self::$messagedb[$m[1]])) {
                    self::$messagedb[$m[1]] = [];
                }
                if (trim($body) !== "") {
                    $body = preg_replace('/^\\\\\\*/m', "*", $body);
                    self::$messagedb[$m[1]][] = [$header, $body];
                }
            }
        }
    }
}

MailChecker::add_messagedb(file_get_contents(SiteLoader::find("test/emails.txt")));
Conf::$main->add_hook((object) [
    "event" => "send_mail",
    "function" => "MailChecker::send_hook",
    "priority" => 1000
]);


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


class Xassert {
    /** @var int */
    static public $n = 0;
    /** @var int */
    static public $nsuccess = 0;
    /** @var int */
    static public $nerror = 0;
    /** @var int */
    static public $disabled = 0;
    /** @var array<int,string> */
    static public $emap = [
        E_ERROR => "PHP Fatal Error",
        E_WARNING => "PHP Warning",
        E_NOTICE => "PHP Notice",
        E_USER_ERROR => "PHP Error",
        E_USER_WARNING => "PHP Warning",
        E_USER_NOTICE => "PHP Notice"
    ];
}

/** @param int $errno
 * @param string $emsg
 * @param string $file
 * @param int $line */
function xassert_error_handler($errno, $emsg, $file, $line) {
    if ((error_reporting() || $errno != E_NOTICE) && Xassert::$disabled <= 0) {
        if (($e = Xassert::$emap[$errno] ?? null)) {
            $emsg = "$e:  $emsg";
        } else {
            $emsg = "PHP Message $errno:  $emsg";
        }
        fwrite(STDERR, "$emsg in $file on line $line\n");
        ++Xassert::$nerror;
    }
}

set_error_handler("xassert_error_handler");

function assert_location() {
    return caller_landmark('/^(?:x?assert|MailChecker::check)/');
}

/** @param mixed $x
 * @param string $description
 * @return bool */
function xassert($x, $description = "") {
    ++Xassert::$n;
    if ($x) {
        ++Xassert::$nsuccess;
    } else {
        error_log(assert_location() . ": " . ($description ? : "Assertion failed"));
    }
    return !!$x;
}

/** @return void */
function xassert_exit() {
    $ok = Xassert::$nsuccess
        && Xassert::$nsuccess == Xassert::$n
        && !Xassert::$nerror;
    echo ($ok ? "* " : "! "), plural(Xassert::$nsuccess, "test"), " succeeded out of ", Xassert::$n, " tried.\n";
    if (Xassert::$nerror > Xassert::$n - Xassert::$nsuccess) {
        $nerror = Xassert::$nerror - (Xassert::$n - Xassert::$nsuccess);
        echo "! ", plural($nerror, "other error"), ".\n";
    }
    exit($ok ? 0 : 1);
}

/** @return bool */
function xassert_eqq($a, $b) {
    ++Xassert::$n;
    $ok = $a === $b;
    if ($ok) {
        ++Xassert::$nsuccess;
    } else {
        error_log(assert_location() . ": Expected " . var_export($a, true) . " === " . var_export($b, true));
    }
    return $ok;
}

/** @return bool */
function xassert_neqq($a, $b) {
    ++Xassert::$n;
    $ok = $a !== $b;
    if ($ok) {
        ++Xassert::$nsuccess;
    } else {
        error_log(assert_location() . ": Expected " . var_export($a, true) . " !== " . var_export($b, true));
    }
    return $ok;
}

/** @param null|int|float|string $a
 * @param list<null|int|float|string> $b
 * @return bool */
function xassert_in_eqq($a, $b) {
    ++Xassert::$n;
    $ok = false;
    foreach ($b as $bx) {
        $ok = $ok || $a === $bx;
    }
    if ($ok) {
        ++Xassert::$nsuccess;
    } else {
        error_log(assert_location() . ": Expected " . var_export($a, true) . " \\in " . var_export($b, true));
    }
    return $ok;
}

/** @param null|int|float|string $a
 * @param null|int|float|string $b
 * @return bool */
function xassert_eq($a, $b) {
    ++Xassert::$n;
    $ok = $a == $b;
    if ($ok) {
        ++Xassert::$nsuccess;
    } else {
        error_log(assert_location() . ": Expected " . var_export($a, true) . " == " . var_export($b, true));
    }
    return $ok;
}

/** @param null|int|float|string $a
 * @param null|int|float|string $b
 * @return bool */
function xassert_neq($a, $b) {
    ++Xassert::$n;
    $ok = $a != $b;
    if ($ok) {
        ++Xassert::$nsuccess;
    } else {
        error_log(assert_location() . ": Expected " . var_export($a, true) . " != " . var_export($b, true));
    }
    return $ok;
}

/** @param ?list<mixed> $a
 * @param ?list<mixed> $b
 * @param bool $sort
 * @return bool */
function xassert_array_eqq($a, $b, $sort = false) {
    ++Xassert::$n;
    $problem = "";
    if ($a === null && $b === null) {
        // ok
    } else if (is_array($a) && is_array($b)) {
        if (count($a) !== count($b) && !$sort) {
            $problem = "size " . count($a) . " !== " . count($b);
        } else if (is_associative_array($a) || is_associative_array($b)) {
            $problem = "associative arrays";
        } else {
            if ($sort) {
                sort($a);
                sort($b);
            }
            for ($i = 0; $i < count($a) && $i < count($b) && !$problem; ++$i) {
                if ($a[$i] !== $b[$i]) {
                    $problem = "value {$i} differs, " . var_export($a[$i], true) . " !== " . var_export($b[$i], true);
                }
            }
            if (!$problem && count($a) !== count($b)) {
                $problem = "size " . count($a) . " !== " . count($b);
            }
        }
    } else {
        $problem = "different types";
    }
    if ($problem === "") {
        ++Xassert::$nsuccess;
    } else {
        error_log(assert_location() . ": Array assertion failed, {$problem}");
        if ($sort) {
            $aj = json_encode(array_slice($a, 0, 10));
            if (count($a) > 10) {
                $aj .= "...";
            }
            $bj = json_encode(array_slice($b, 0, 10));
            if (count($b) > 10) {
                $bj .= "...";
            }
            error_log("  " . $aj . " !== " . $bj);
        }
    }
    return $problem === "";
}

/** @return bool */
function xassert_match($a, $b) {
    ++Xassert::$n;
    $ok = is_string($a) && preg_match($b, $a);
    if ($ok) {
        ++Xassert::$nsuccess;
    } else {
        error_log(assert_location() . ": Expected " . var_export($a, true) . " ~= {$b}");
    }
    return $ok;
}

/** @return bool */
function xassert_int_list_eqq($a, $b) {
    ++Xassert::$n;
    $x = [];
    foreach ([$a, $b] as $ids) {
        $s = is_array($ids) ? join(" ", $ids) : $ids;
        $x[] = preg_replace_callback('/(\d+)-(\d+)/', function ($m) {
            return join(" ", range(+$m[1], +$m[2]));
        }, $s);
    }
    $ok = $x[0] === $x[1];
    if ($ok) {
        ++Xassert::$nsuccess;
    } else {
        error_log(assert_location() . ": Expected {$x[0]} === {$x[1]}");
    }
    return $ok;
}


/** @param Contact $user
 * @param string|array $query
 * @param string $cols
 * @return array<int,array> */
function search_json($user, $query, $cols = "id") {
    $pl = new PaperList("empty", new PaperSearch($user, $query));
    $pl->parse_view($cols);
    return $pl->text_json();
}

/** @param Contact $user
 * @param string|array $query
 * @param string $col
 * @return string */
function search_text_col($user, $query, $col = "id") {
    $pl = new PaperList("empty", new PaperSearch($user, $query));
    $pl->parse_view($col);
    $x = [];
    foreach ($pl->text_json() as $pid => $p) {
        $x[] = $pid . " " . $p[$col] . "\n";
    }
    return join("", $x);
}

/** @param Contact $user
 * @return bool */
function assert_search_papers($user, $query, $result) {
    return xassert_int_list_eqq(array_keys(search_json($user, $query)), $result);
}

/** @param Contact $user
 * @return bool */
function assert_search_ids($user, $query, $result) {
    return xassert_int_list_eqq((new PaperSearch($user, $query))->paper_ids(), $result);
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
    if (($cmp = strcasecmp($ax, $bx)) == 0) {
        if (($a_twiddle > 0) != ($b_twiddle > 0)) {
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
        if (strlen($tag) > 2 && substr($tag, strlen($tag) - 2) == "#0") {
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
    $assignset = new AssignmentSet($who, $override);
    $assignset->parse($what);
    $ok = $assignset->execute();
    xassert($ok);
    if (!$ok) {
        fwrite(STDERR, preg_replace('/^/m', "  ", $assignset->full_feedback_text()));
    }
    return $ok;
}

/** @param Contact $who
 * @return bool */
function xassert_assign_fail($who, $what, $override = false) {
    $assignset = new AssignmentSet($who, $override);
    $assignset->parse($what);
    return xassert(!$assignset->execute());
}

/** @param int $maxstatus */
function xassert_paper_status(PaperStatus $ps, $maxstatus = MessageSet::PLAIN) {
    if (!xassert($ps->problem_status() <= $maxstatus)) {
        foreach ($ps->message_list() as $mx) {
            if ($mx->status === MessageSet::INFORM && $mx->message) {
                error_log("!     {$mx->message}");
            } else {
                error_log("! {$mx->field}" . ($mx->message ? ": {$mx->message}" : ""));
            }
        }
    }
}

/** @param int $maxstatus */
function xassert_paper_status_saved_nonrequired(PaperStatus $ps, $maxstatus = MessageSet::PLAIN) {
    xassert($ps->save_status() !== 0);
    if ($ps->problem_status() > $maxstatus) {
        $asserted = false;
        foreach ($ps->problem_list() as $mx) {
            if ($mx->message !== "<0>Entry required"
                && $mx->message !== "<0>Entry required to complete submission") {
                if (!$asserted) {
                    xassert($ps->problem_status() <= $maxstatus);
                    $asserted = true;
                }
                error_log("! {$mx->field}" . ($mx->message ? ": {$mx->message}" : ""));
            }
        }
    }
}


/** @param Contact $user
 * @param ?PaperInfo $prow
 * @return object */
function call_api($fn, $user, $qreq, $prow) {
    if (!($qreq instanceof Qrequest)) {
        $qreq = new Qrequest("POST", $qreq);
        $qreq->approve_token();
    }
    $uf = $user->conf->api($fn, $user, $qreq->method());
    $jr = $user->conf->call_api_on($uf, $fn, $user, $qreq, $prow);
    return (object) $jr->content;
}

/** @param int|PaperInfo $prow
 * @param Contact $user
 * @return ?ReviewInfo */
function fetch_review($prow, $user) {
    if (is_int($prow)) {
        $prow = $user->conf->checked_paper_by_id($prow, $user);
    }
    return $prow->fresh_review_by_user($user);
}

/** @param Contact $user
 * @return ?ReviewInfo */
function save_review($paper, $user, $revreq, $rrow = null) {
    $pid = is_object($paper) ? $paper->paperId : $paper;
    $prow = $user->conf->checked_paper_by_id($pid, $user);
    $rf = Conf::$main->review_form();
    $tf = new ReviewValues($rf);
    $tf->parse_qreq(new Qrequest("POST", $revreq), false);
    $tf->check_and_save($user, $prow, $rrow ?? fetch_review($prow, $user));
    foreach ($tf->problem_list() as $mx) {
        error_log("! {$mx->field}" . ($mx->message ? ": {$mx->message}" : ""));
    }
    return fetch_review($prow, $user);
}

/** @return Contact */
function user($email) {
    return Conf::$main->checked_user_by_email($email);
}

/** @return ?Contact */
function maybe_user($email) {
    return Conf::$main->user_by_email($email);
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
        Conf::$main->invalidate_cdb_user_by_email($email);
    }
}


class TestRunner {
    static public $original_opt;

    static private function setup_assignments($assignments, Contact $user) {
        if (is_array($assignments)) {
            $assignments = join("\n", $assignments);
        }
        $assignset = new AssignmentSet($user, true);
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

        $s = preg_replace('/\s*(?:--|#).*/m', "", $s0);
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
            || !preg_match('/\A\s*insert into Settings[^;]*\(\'(allowPaperOption|sversion)\',\s*(\d+)\);/mi', $s, $m)
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
    }

    static function reset_options($first = false) {
        Conf::$main->qe("insert into Settings set name='options', value=1, data='[{\"id\":1,\"name\":\"Calories\",\"abbr\":\"calories\",\"type\":\"numeric\",\"order\":1,\"display\":\"default\"}]' ?U on duplicate key update data=?U(data)");
        Conf::$main->load_settings();
    }

    static function reset_db($rebuild = false) {
        $conf = Conf::$main;
        $timer = new ProfileTimer;
        MailChecker::clear();

        // Initialize from an empty database.
        self::reset_schema($conf->dblink, SiteLoader::find("src/schema.sql"), $rebuild);
        $timer->mark("schema");

        // No setup phase.
        $conf->qe_raw("delete from Settings where name='setupPhase'");
        self::reset_options(true);
        $timer->mark("settings");

        // Contactdb.
        if (($cdb = $conf->contactdb())) {
            self::reset_schema($cdb, SiteLoader::find("test/cdb-schema.sql"), $rebuild);
            $cdb->query("insert into Conferences set dbname='" . $cdb->real_escape_string($conf->dbname) . "'");
            Contact::$props["demoBirthday"] = Contact::PROP_CDB | Contact::PROP_NULL | Contact::PROP_INT | Contact::PROP_IMPORT;
        }
        $timer->mark("contactdb");

        // Create initial administrator user.
        $user_chair = Contact::make_keyed($conf, ["email" => "chair@_.com", "name" => "Jane Chair"])->store();
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
            $us->notify = in_array("pc", $c->roles ?? []);
            $user = $us->save_user($c);
            if ($user) {
                MailChecker::check_db("create-{$c->email}");
            } else {
                fwrite(STDERR, "* failed to create user $c->email\n");
                $ok = false;
            }
        }
        $timer->mark("users");
        foreach ($json->papers as $p) {
            $ps = new PaperStatus($conf);
            if (!$ps->save_paper_json($p)) {
                $t = join("", array_map(function ($mx) {
                    return "    {$mx->field}: {$mx->message}\n";
                }, $ps->message_list()));
                $id = isset($p->_id_) ? "#{$p->_id_} " : "";
                fwrite(STDERR, "* failed to create paper {$id}{$p->title}:\n" . htmlspecialchars_decode($t) . "\n");
                $ok = false;
            }
        }
        if (!$ok) {
            exit(1);
        }
        $timer->mark("papers");

        self::setup_assignments($json->assignments_1, $user_chair);
        $timer->mark("assignment");
        MailChecker::clear();
    }

    /** @param object $testo */
    static function go($testo) {
        $ro = new ReflectionObject($testo);
        foreach ($ro->getMethods() as $m) {
            if (str_starts_with($m->name, "test")
                && strlen($m->name) > 4
                && ($m->name[4] === "_" || ctype_upper($m->name[4])))
                $testo->{$m->name}();
        }
    }

    /** @param string $url */
    static function set_navigation_base($url) {
        Navigation::analyze();
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
}

TestRunner::$original_opt = $Opt;
TestRunner::set_navigation_base("/");
