<?php
// test/setup.php -- HotCRP helper file to initialize tests
// Copyright (c) 2006-2018 Eddie Kohler; see LICENSE.

global $ConfSitePATH;
$ConfSitePATH = preg_replace(",/[^/]+/[^/]+$,", "", __FILE__);
define("HOTCRP_OPTIONS", "$ConfSitePATH/test/options.php");
define("HOTCRP_TESTHARNESS", true);
require_once("$ConfSitePATH/src/init.php");
$Conf->set_opt("disablePrintEmail", true);
$Conf->set_opt("postfixEOL", "\n");

function die_hard($message) {
    fwrite(STDERR, $message);
    exit(1);
}

// Initialize from an empty database.
if (!$Conf->dblink->multi_query(file_get_contents("$ConfSitePATH/src/schema.sql")))
    die_hard("* Can't reinitialize database.\n" . $Conf->dblink->error . "\n");
do {
    if (($result = $Conf->dblink->store_result()))
        $result->free();
    else if ($Conf->dblink->errno)
        break;
} while ($Conf->dblink->more_results() && $Conf->dblink->next_result());
if ($Conf->dblink->errno)
    die_hard("* Error initializing database.\n" . $Conf->dblink->error . "\n");

// No setup phase.
$Conf->qe_raw("delete from Settings where name='setupPhase'");
$Conf->qe_raw("insert into Settings set name='options', value=1, data='[{\"id\":1,\"name\":\"Calories\",\"abbr\":\"calories\",\"type\":\"numeric\",\"position\":1,\"display\":\"default\"}]'");
$Conf->load_settings();
// Contactdb.
if (($cdb = $Conf->contactdb())) {
    if (!$cdb->multi_query(file_get_contents("$ConfSitePATH/test/cdb-schema.sql")))
        die_hard("* Can't reinitialize contact database.\n" . $cdb->error);
    while ($cdb->more_results())
        Dbl::free($cdb->next_result());
    $cdb->query("insert into Conferences set dbname='" . $cdb->real_escape_string($Conf->dbname) . "'");
}

// Record mail in MailChecker.
class MailChecker {
    static public $print = false;
    static public $preps = [];
    static public $messagedb = [];
    static function send_hook($fh, $prep) {
        global $ConfSitePATH;
        $prep->landmark = "";
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $trace) {
            if (isset($trace["file"]) && preg_match(',/test\d,', $trace["file"])) {
                if (str_starts_with($trace["file"], $ConfSitePATH))
                    $trace["file"] = substr($trace["file"], strlen($ConfSitePATH) + 1);
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
        return false;
    }
    static function check0() {
        xassert_eqq(count(self::$preps), 0);
        self::$preps = [];
    }
    static function check1($recipient, $template, $row = null, $rest = []) {
        global $Conf;
        xassert_eqq(count(self::$preps), 1);
        if (count(self::$preps) === 1) {
            $mailer = new HotCRPMailer($Conf, $recipient, $row, $rest);
            $prep = $mailer->make_preparation($template, $rest);
            xassert_eqq(self::$preps[0]->subject, $prep->subject);
            xassert_eqq(self::$preps[0]->body, $prep->body);
            xassert_eq(self::$preps[0]->to, $prep->to);
        }
        self::$preps = [];
    }
    static function check_db($name = null) {
        if ($name) {
            xassert(isset(self::$messagedb[$name]));
            xassert_eqq(count(self::$preps), count(self::$messagedb[$name]));
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
        $haves = [];
        foreach (self::$preps as $prep)
            $haves[] = "To: " . join(", ", $prep->to) . "\n"
                . "Subject: " . str_replace("\r", "", $prep->subject)
                . "\n\n" . $prep->body;
        sort($haves);
        $wants = [];
        foreach ($mdb as $m)
            $wants[] = preg_replace('/^X-Landmark:.*?\n/m', "", $m[0]) . $m[1];
        sort($wants);
        foreach ($wants as $i => $want) {
            ++Xassert::$n;
            $have = isset($haves[$i]) ? $haves[$i] : "";
            if ($have === $want
                || preg_match("=\\A" . str_replace('\\{\\{\\}\\}', ".*", preg_quote($want)) . "\\z=", $have)) {
                ++Xassert::$nsuccess;
            } else {
                fwrite(STDERR, "Mail assertion failure: " . var_export($have, true) . " !== " . var_export($want, true) . "\n");
                $havel = explode("\n", $have);
                foreach (explode("\n", $want) as $j => $wantl) {
                    if (!isset($havel[$j])
                        || ($havel[$j] !== $wantl
                            && !preg_match("=\\A" . str_replace('\\{\\{\\}\\}', ".*", preg_quote($wantl, "#\"")) . "\\z=", $havel[$j]))) {
                        fwrite(STDERR, "... line " . ($j + 1) . " differs near " . $havel[$j] . "\n"
                               . "... expected " . $wantl . "\n");
                        break;
                    }
                }
                trigger_error("Assertion failed at " . assert_location() . "\n", E_USER_WARNING);
            }
        }
        self::$preps = [];
    }
    static function clear() {
        self::$preps = [];
    }
    static function add_messagedb($text) {
        preg_match_all('/^\*\*\*\*\*\*\*\*(.*)\n([\s\S]*?\n\n)([\s\S]*?)(?=^\*\*\*\*\*\*\*\*|\z)/m', $text, $ms, PREG_SET_ORDER);
        foreach ($ms as $m) {
            $m[1] = trim($m[1]);
            if ($m[1] === ""
                && preg_match('/\nX-Landmark:\s*(\S+)/', $m[2], $mx))
                $m[1] = $mx[1];
            if ($m[1] !== "")
                self::$messagedb[$m[1]][] = [$m[2], $m[3]];
        }
    }
}
MailChecker::add_messagedb(file_get_contents("$ConfSitePATH/test/emails.txt"));
$Conf->add_hook((object) ["event" => "send_mail", "callback" => "MailChecker::send_hook", "priority" => 1000]);

// Create initial administrator user.
$Admin = Contact::create($Conf, null, ["email" => "chair@_.com", "name" => "Jane Chair"]);
$Admin->save_roles(Contact::ROLE_ADMIN | Contact::ROLE_CHAIR | Contact::ROLE_PC, $Admin);

// Load data.
$json = json_decode(file_get_contents("$ConfSitePATH/test/db.json"));
if (!$json)
    die_hard("* test/testdb.json error: " . json_last_error_msg() . "\n");
$us = new UserStatus($Conf->site_contact());
foreach ($json->contacts as $c) {
    $user = $us->save($c);
    if ($user)
        MailChecker::check_db("create-{$c->email}");
    else
        die_hard("* failed to create user $c->email\n");
}
foreach ($json->papers as $p) {
    $ps = new PaperStatus($Conf);
    if (!$ps->save_paper_json($p))
        die_hard("* failed to create paper $p->title:\n" . htmlspecialchars_decode(join("\n", $ps->messages())) . "\n");
}

function setup_assignments($assignments, Contact $user) {
    if (is_array($assignments))
        $assignments = join("\n", $assignments);
    $assignset = new AssignmentSet($user, true);
    $assignset->parse($assignments, null, null);
    if (!$assignset->execute())
        die_hard("* failed to run assignments:\n" . join("\n", $assignset->errors_text(true)) . "\n");
}
setup_assignments($json->assignments_1, $Admin);

class Xassert {
    static public $n = 0;
    static public $nsuccess = 0;
    static public $nerror = 0;
    static public $emap = array(E_ERROR => "PHP Fatal Error",
                                E_WARNING => "PHP Warning",
                                E_NOTICE => "PHP Notice",
                                E_USER_ERROR => "PHP Error",
                                E_USER_WARNING => "PHP Warning",
                                E_USER_NOTICE => "PHP Notice");
    static public $disabled = 0;
}

function xassert_error_handler($errno, $emsg, $file, $line) {
    if ((error_reporting() || $errno != E_NOTICE) && Xassert::$disabled <= 0) {
        if (get(Xassert::$emap, $errno))
            $emsg = Xassert::$emap[$errno] . ":  $emsg";
        else
            $emsg = "PHP Message $errno:  $emsg";
        fwrite(STDERR, "$emsg in $file on line $line\n");
        ++Xassert::$nerror;
    }
}

set_error_handler("xassert_error_handler");

function assert_location() {
    return caller_landmark(",^x?assert,");
}

function xassert($x, $description = "") {
    ++Xassert::$n;
    if (!$x)
        trigger_error("Assertion" . ($description ? " " . $description : "") . " failed at " . assert_location() . "\n", E_USER_WARNING);
    else
        ++Xassert::$nsuccess;
}

function xassert_exit() {
    $ok = Xassert::$nsuccess && Xassert::$nsuccess == Xassert::$n
        && !Xassert::$nerror;
    echo ($ok ? "* " : "! "), plural(Xassert::$nsuccess, "test"), " succeeded out of ", Xassert::$n, " tried.\n";
    if (Xassert::$nerror
        && ($nerror = Xassert::$nerror - (Xassert::$n - Xassert::$nsuccess)))
        echo "! ", plural($nerror, "other error"), ".\n";
    exit($ok ? 0 : 1);
}

function xassert_eqq($a, $b) {
    ++Xassert::$n;
    if ($a === $b)
        ++Xassert::$nsuccess;
    else
        trigger_error("Assertion " . var_export($a, true) . " === " . var_export($b, true)
                      . " failed at " . assert_location() . "\n", E_USER_WARNING);
}

function xassert_neqq($a, $b) {
    ++Xassert::$n;
    if ($a !== $b)
        ++Xassert::$nsuccess;
    else
        trigger_error("Assertion " . var_export($a, true) . " !== " . var_export($b, true)
                      . " failed at " . assert_location() . "\n", E_USER_WARNING);
}

function xassert_eq($a, $b) {
    ++Xassert::$n;
    if ($a == $b)
        ++Xassert::$nsuccess;
    else
        trigger_error("Assertion " . var_export($a, true) . " == " . var_export($b, true)
                      . " failed at " . assert_location() . "\n", E_USER_WARNING);
}

function xassert_neq($a, $b) {
    ++Xassert::$n;
    if ($a != $b)
        ++Xassert::$nsuccess;
    else
        trigger_error("Assertion " . var_export($a, true) . " != " . var_export($b, true)
                      . " failed at " . assert_location() . "\n", E_USER_WARNING);
}

function xassert_array_eqq($a, $b) {
    ++Xassert::$n;
    $problem = "";
    if ($a === null && $b === null)
        /* ok */;
    else if (is_array($a) && is_array($b)) {
        if (count($a) !== count($b))
            $problem = "size " . count($a) . " !== " . count($b);
        else {
            $ka = array_keys($a);
            $va = array_values($a);
            $kb = array_keys($b);
            $vb = array_values($b);
            for ($i = 0; $i < count($ka) && !$problem; ++$i)
                if ($ka[$i] !== $kb[$i])
                    $problem = "key position $i differs, {$ka[$i]} !== {$kb[$i]}";
                else if ($va[$i] !== $vb[$i])
                    $problem = "value {$ka[$i]} differs, " . var_export($va[$i], true) . " !== " . var_export($vb[$i], true);
        }
    } else
        $problem = "different types";
    if ($problem === "")
        ++Xassert::$nsuccess;
    else
        trigger_error("Array assertion failed, $problem at " . assert_location() . "\n", E_USER_WARNING);
}

function xassert_match($a, $b) {
    ++Xassert::$n;
    if (is_string($a) && preg_match($b, $a))
        ++Xassert::$nsuccess;
    else
        trigger_error("Assertion " . var_export($a, true) . " ~= " . $b
                      . " failed at " . assert_location() . "\n", E_USER_WARNING);
}

function search_json($user, $text, $cols = "id") {
    $pl = new PaperList(new PaperSearch($user, $text));
    return $pl->text_json($cols);
}

function search_text_col($user, $text, $col = "id") {
    $pl = new PaperList(new PaperSearch($user, $text));
    $x = array();
    foreach ($pl->text_json($col) as $pid => $p)
        $x[] = $pid . " " . $p->$col . "\n";
    return join("", $x);
}

function assert_search_papers($user, $text, $result) {
    if (is_array($result))
        $result = join(" ", $result);
    $result = preg_replace_callback('/(\d+)-(\d+)/', function ($m) {
        return join(" ", range(+$m[1], +$m[2]));
    }, $result);
    xassert_eqq(join(" ", array_keys(search_json($user, $text))), $result);
}

function assert_query($q, $b) {
    $result = Dbl::qe_raw($q);
    xassert_eqq(join("\n", edb_first_columns($result)), $b);
}

function tag_normalize_compare($a, $b) {
    $a_twiddle = strpos($a, "~");
    $b_twiddle = strpos($b, "~");
    $ax = ($a_twiddle > 0 ? substr($a, $a_twiddle + 1) : $a);
    $bx = ($b_twiddle > 0 ? substr($b, $b_twiddle + 1) : $b);
    if (($cmp = strcasecmp($ax, $bx)) == 0) {
        if (($a_twiddle > 0) != ($b_twiddle > 0))
            $cmp = ($a_twiddle > 0 ? 1 : -1);
        else
            $cmp = strcasecmp($a, $b);
    }
    return $cmp;
}

function paper_tag_normalize($prow) {
    $t = array();
    $pcm = $prow->conf->pc_members();
    foreach (explode(" ", $prow->all_tags_text()) as $tag) {
        if (($twiddle = strpos($tag, "~")) > 0
            && ($c = get($pcm, substr($tag, 0, $twiddle)))) {
            $at = strpos($c->email, "@");
            $tag = ($at ? substr($c->email, 0, $at) : $c->email) . substr($tag, $twiddle);
        }
        if (strlen($tag) > 2 && substr($tag, strlen($tag) - 2) == "#0")
            $tag = substr($tag, 0, strlen($tag) - 2);
        if ($tag)
            $t[] = $tag;
    }
    usort($t, "tag_normalize_compare");
    return $t;
}

function xassert_assign($who, $what, $override = false) {
    $assignset = new AssignmentSet($who, $override);
    $assignset->parse($what);
    $xassert_success = $assignset->execute();
    xassert($xassert_success);
    if (!$xassert_success) {
        foreach ($assignset->errors_text() as $line)
            fwrite(STDERR, "  $line\n");
    }
}

function xassert_assign_fail($who, $what, $override = false) {
    $assignset = new AssignmentSet($who, $override);
    $assignset->parse($what);
    xassert(!$assignset->execute());
}

function call_api($fn, $user, $qreq, $prow) {
    if (!($qreq instanceof Qrequest))
        $qreq = new Qrequest("POST", $qreq);
    $uf = $user->conf->api($fn);
    xassert($uf);
    Conf::xt_resolve_require($uf);
    JsonResultException::$capturing = true;
    $result = null;
    try {
        $result = (object) call_user_func($uf->callback, $user, $qreq, $prow, $uf);
    } catch (JsonResultException $jre) {
        $result = (object) $jre->result;
    }
    JsonResultException::$capturing = false;
    return $result;
}

function fetch_paper($pid, $contact = null) {
    global $Conf;
    return $Conf->paperRow($pid, $contact);
}

function fetch_review(PaperInfo $prow, $contact) {
    return $prow->fresh_review_of_user($contact);
}

function save_review($paper, $contact, $revreq) {
    global $Conf;
    $pid = is_object($paper) ? $paper->paperId : $paper;
    $prow = fetch_paper($pid, $contact);
    $rf = $Conf->review_form();
    $tf = new ReviewValues($rf);
    $tf->parse_web(new Qrequest("POST", $revreq), false);
    $tf->check_and_save($contact, $prow, fetch_review($prow, $contact));
    return fetch_review($prow, $contact);
}

function user($email) {
    global $Conf;
    return $Conf->user_by_email($email);
}

MailChecker::clear();
echo "* Tests initialized.\n";
