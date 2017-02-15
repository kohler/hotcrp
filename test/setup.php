<?php
// test/setup.php -- HotCRP helper file to initialize tests
// HotCRP is Copyright (c) 2006-2017 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

global $ConfSitePATH;
$ConfSitePATH = preg_replace(",/[^/]+/[^/]+$,", "", __FILE__);
define("HOTCRP_OPTIONS", "$ConfSitePATH/test/options.php");
define("HOTCRP_TESTHARNESS", true);
require_once("$ConfSitePATH/src/init.php");
$Conf->set_opt("disablePrintEmail", true);

function die_hard($message) {
    fwrite(STDERR, $message);
    exit(1);
}

// Initialize from an empty database.
if (!$Conf->dblink->multi_query(file_get_contents("$ConfSitePATH/src/schema.sql")))
    die_hard("* Can't reinitialize database.\n" . $Conf->dblink->error);
while ($Conf->dblink->more_results())
    Dbl::free($Conf->dblink->next_result());
// No setup phase.
$Conf->qe_raw("delete from Settings where name='setupPhase'");
$Conf->qe_raw("insert into Settings set name='options', value=1, data='{\"1\":{\"id\":1,\"name\":\"Calories\",\"abbr\":\"calories\",\"type\":\"numeric\",\"position\":1,\"display\":\"default\"}}'");
$Conf->load_settings();
// Contactdb.
if (($cdb = Contact::contactdb())) {
    if (!$cdb->multi_query(file_get_contents("$ConfSitePATH/test/cdb-schema.sql")))
        die_hard("* Can't reinitialize contact database.\n" . $cdb->error);
    while ($cdb->more_results())
        Dbl::free($cdb->next_result());
}

// Create initial administrator user.
$Admin = Contact::create($Conf, ["email" => "chair@_.com", "name" => "Jane Chair",
                                 "password" => "testchair"]);
$Admin->save_roles(Contact::ROLE_ADMIN | Contact::ROLE_CHAIR | Contact::ROLE_PC, $Admin);

// Load data.
$json = json_decode(file_get_contents("$ConfSitePATH/test/db.json"));
if (!$json)
    die_hard("* test/testdb.json error: " . json_last_error_msg() . "\n");
foreach ($json->contacts as $c) {
    $us = new UserStatus;
    if (!$us->save($c))
        die_hard("* failed to create user $c->email\n");
}
foreach ($json->papers as $p) {
    $ps = new PaperStatus($Conf);
    if (!$ps->save_paper_json($p))
        die_hard("* failed to create paper $p->title:\n" . htmlspecialchars_decode(join("\n", $ps->messages())) . "\n");
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
    $pcm = pcMembers();
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

function xassert_assign($who, $override, $what) {
    $assignset = new AssignmentSet($who, $override);
    $assignset->parse($what);
    xassert($assignset->execute());
}

function xassert_assign_fail($who, $override, $what) {
    $assignset = new AssignmentSet($who, $override);
    $assignset->parse($what);
    xassert(!$assignset->execute());
}

function call_api($fn, $user, $qreq, $prow) {
    if (!($qreq instanceof Qrequest))
        $qreq = new Qrequest("POST", $qreq);
    $uf = $user->conf->api($fn);
    xassert($uf);
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

function fetch_paper($pid, $contact) {
    global $Conf;
    return $Conf->paperRow($pid, $contact);
}

function fetch_review($pid, $contact) {
    global $Conf;
    $pid = is_object($pid) ? $pid->paperId : $pid;
    $cid = is_object($contact) ? $contact->contactId : $contact;
    return $Conf->reviewRow(["paperId" => $pid, "contactId" => $cid]);
}

function save_review($pid, $contact, $revreq) {
    global $Conf;
    $pid = is_object($pid) ? $pid->paperId : $pid;
    $rf = $Conf->review_form();
    $rf->save_review($revreq, fetch_review($pid, $contact), fetch_paper($pid, $contact), $contact);
    return fetch_review($pid, $contact);
}

echo "* Tests initialized.\n";
