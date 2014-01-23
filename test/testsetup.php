<?php
// testsetup.php -- HotCRP helper file to initialize tests
// HotCRP is Copyright (c) 2006-2014 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

global $ConfSitePATH;
$ConfSitePATH = preg_replace(",/[^/]+/[^/]+$,", "", __FILE__);

global $Opt;
$Opt = array();
if ((@include "$ConfSitePATH/test/testoptions.php") === false)
    die("* Can't load test/testoptions.php.\n");
$Opt["loaded"] = true;

require_once("$ConfSitePATH/src/init.php");
$Opt["dsn"] = Conference::make_dsn($Opt);
$Conf = new Conference($Opt["dsn"]);
if (!$Conf->dblink)
    die("* Can't load database " . $Opt["dsn"] . " specified by test/testoptions.php.\n"
        . "* Run `lib/createdb.sh -c test/testoptions.php` to create the database.\n");

// Initialize from an empty database.
if (!$Conf->dblink->multi_query(file_get_contents("$ConfSitePATH/src/schema.sql")))
    die("* Can't reinitialize database.\n" . $Conf->dblink->error);
while ($Conf->dblink->more_results())
    $Conf->dblink->next_result();

// Create initial administrator user.
$Admin = Contact::find_by_email("chair@_.com", array("name" => "Jane Chair"));
$Admin->save_roles(Contact::ROLE_ADMIN | Contact::ROLE_CHAIR | Contact::ROLE_PC, $Admin);

// Load data.
$json = json_decode(file_get_contents("$ConfSitePATH/test/testdb.json"));
if (!$json)
    die("* test/testdb.json error: " . json_last_error_msg() . "\n");
foreach ($json->contacts as $c)
    if (!Contact::find_by_email($c->email, $c))
        die("* failed to create user $c->email\n");
foreach ($json->papers as $p) {
    $ps = new PaperStatus;
    if (!$ps->save($p))
        die("* failed to create paper $p->title:\n" . join("\n", $ps->error_messages()) . "\n");
}

echo "* Tests initialized.\n";
