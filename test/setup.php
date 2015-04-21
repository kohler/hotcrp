<?php
// test/setup.php -- HotCRP helper file to initialize tests
// HotCRP is Copyright (c) 2006-2015 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

global $ConfSitePATH;
$ConfSitePATH = preg_replace(",/[^/]+/[^/]+$,", "", __FILE__);
define("HOTCRP_OPTIONS", "$ConfSitePATH/test/options.php");
define("HOTCRP_TESTHARNESS", true);
require_once("$ConfSitePATH/src/init.php");
$Opt["disablePrintEmail"] = true;

// Initialize from an empty database.
if (!$Conf->dblink->multi_query(file_get_contents("$ConfSitePATH/src/schema.sql")))
    die("* Can't reinitialize database.\n" . $Conf->dblink->error);
while ($Conf->dblink->more_results())
    $Conf->dblink->next_result();
// No setup phase.
$Conf->qe("delete from Settings where name='setupPhase'");
$Conf->load_settings();

// Create initial administrator user.
$Admin = Contact::find_by_email("chair@_.com", array("name" => "Jane Chair",
                                                     "password" => "testchair"));
$Admin->save_roles(Contact::ROLE_ADMIN | Contact::ROLE_CHAIR | Contact::ROLE_PC, $Admin);

// Load data.
$json = json_decode(file_get_contents("$ConfSitePATH/test/db.json"));
if (!$json)
    die("* test/testdb.json error: " . json_last_error_msg() . "\n");
foreach ($json->contacts as $c) {
    $us = new UserStatus;
    if (!$us->save($c))
        die("* failed to create user $c->email\n");
}
foreach ($json->papers as $p) {
    $ps = new PaperStatus;
    if (!$ps->save($p))
        die("* failed to create paper $p->title:\n" . htmlspecialchars_decode(join("\n", $ps->error_html())) . "\n");
}
$assignset = new AssignmentSet($Admin, true);
$assignset->parse($json->assignments_1, null, null);
$assignset->execute();

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
}

function xassert_error_handler($errno, $emsg, $file, $line) {
    if (error_reporting() || $errno != E_NOTICE) {
        if (@Xassert::$emap[$errno])
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

function assert_eqq($a, $b) {
    ++Xassert::$n;
    if ($a !== $b)
        trigger_error("Assertion " . var_export($a, true) . " === " . var_export($b, true)
                      . " failed at " . assert_location() . "\n", E_USER_WARNING);
    else
        ++Xassert::$nsuccess;
}

function assert_neqq($a, $b) {
    ++Xassert::$n;
    if ($a === $b)
        trigger_error("Assertion " . var_export($a, true) . " !== " . var_export($b, true)
                      . " failed at " . assert_location() . "\n", E_USER_WARNING);
    else
        ++Xassert::$nsuccess;
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
    assert_eqq(join(" ", array_keys(search_json($user, $text))), $result);
}

function assert_query($q, $b) {
    $result = Dbl::qe_raw($q);
    assert_eqq(join("\n", edb_first_columns($result)), $b);
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
    $pcm = pcMembers();
    foreach (explode(" ", $prow->all_tags_text()) as $tag) {
        if (($twiddle = strpos($tag, "~")) > 0
            && ($c = @$pcm[substr($tag, 0, $twiddle)])) {
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

echo "* Tests initialized.\n";
