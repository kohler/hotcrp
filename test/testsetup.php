<?php
// testsetup.php -- HotCRP helper file to initialize tests
// HotCRP is Copyright (c) 2006-2015 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

global $ConfSitePATH;
$ConfSitePATH = preg_replace(",/[^/]+/[^/]+$,", "", __FILE__);
define("HOTCRP_OPTIONS", "$ConfSitePATH/test/testoptions.php");
define("HOTCRP_TESTHARNESS", true);
require_once("$ConfSitePATH/src/init.php");

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
$json = json_decode(file_get_contents("$ConfSitePATH/test/testdb.json"));
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
        die("* failed to create paper $p->title:\n" . join("\n", $ps->error_messages()) . "\n");
}
$assignset = new AssignmentSet($Admin, true);
$assignset->parse($json->assignments_1, null, null);
$assignset->execute();

function assert_location() {
    return caller_landmark(",^assert,");
}

function assert_eqq($a, $b) {
    if ($a !== $b)
        trigger_error("Assertion " . var_export($a, true) . " === " . var_export($b, true)
                      . " failed at " . assert_location() . "\n", E_USER_WARNING);
}

function assert_neqq($a, $b) {
    if ($a === $b)
        trigger_error("Assertion " . var_export($a, true) . " !== " . var_export($b, true)
                      . " failed at " . assert_location() . "\n", E_USER_WARNING);
}

function search_json($user, $text, $cols = "id") {
    $pl = new PaperList(new PaperSearch($user, $text));
    return $pl->text_json("id");
}

function assert_search_papers($user, $text, $result) {
    if (is_array($result))
        $result = join(" ", $result);
    assert_eqq(join(" ", array_keys(search_json($user, $text))), $result);
}

echo "* Tests initialized.\n";
