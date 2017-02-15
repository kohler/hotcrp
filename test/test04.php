<?php
// test04.php -- HotCRP user database tests
// HotCRP is Copyright (c) 2006-2017 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

global $ConfSitePATH;
$ConfSitePATH = preg_replace(",/[^/]+/[^/]+$,", "", __FILE__);

global $Opt;
$Opt = array("contactdb_dsn" => "mysql://hotcrp_testdb:m5LuaN23j26g@localhost/hotcrp_testdb_cdb",
             "contactdb_passwordHmacKeyid" => "c1");
require_once("$ConfSitePATH/test/setup.php");

function user($email) {
    global $Conf;
    return $Conf->user_by_email($email);
}

function password($email, $iscdb = false) {
    global $Conf;
    $dblink = $iscdb ? Contact::contactdb() : $Conf->dblink;
    $result = Dbl::qe($dblink, "select password from ContactInfo where email=?", $email);
    $row = Dbl::fetch_first_row($result);
    return $row[0];
}

function save_password($email, $encoded_password, $iscdb = false) {
    global $Conf, $Now;
    $dblink = $iscdb ? Contact::contactdb() : $Conf->dblink;
    Dbl::qe($dblink, "update ContactInfo set password=?, passwordTime=? where email=?", $encoded_password, $Now, $email);
    ++$Now;
}

$user_chair = $Conf->user_by_email("chair@_.com");
$marina = "marina@poema.ru";

$Conf->set_opt("safePasswords", 0);
$Conf->set_opt("contactdb_safePasswords", 0);
user($marina)->change_password(null, "rosdevitch", 0);
xassert_eqq(password($marina), "");
xassert_eqq(password($marina, true), "rosdevitch");
xassert(user($marina)->check_password("rosdevitch"));
user($marina)->change_password(null, "rosdevitch", Contact::CHANGE_PASSWORD_NO_CDB);
xassert_eqq(password($marina), "rosdevitch");
xassert_eqq(password($marina, true), "rosdevitch");
xassert(user($marina)->check_password("rosdevitch"));

// different password in localdb => both passwords work
save_password($marina, "crapdevitch", false);
xassert(user($marina)->check_password("crapdevitch"));
xassert(user($marina)->check_password("rosdevitch"));

// change local password => only local password changes
user($marina)->change_password("crapdevitch", "assdevitch", 0);
xassert(user($marina)->check_password("assdevitch"));
xassert(user($marina)->check_password("rosdevitch"));
xassert(!user($marina)->check_password("crapdevitch"));

// change contactdb password => both passwords change
user($marina)->change_password("rosdevitch", "dungdevitch", 0);
xassert(user($marina)->check_password("dungdevitch"));
xassert(!user($marina)->check_password("assdevitch"));
xassert(!user($marina)->check_password("rosdevitch"));
xassert(!user($marina)->check_password("crapdevitch"));

// update contactdb password => old local password useless
save_password($marina, "isdevitch", true);
xassert_eqq(password($marina), "");
xassert(user($marina)->check_password("isdevitch"));
xassert(!user($marina)->check_password("dungdevitch"));

// update local password only
user($marina)->change_password(null, "ncurses", Contact::CHANGE_PASSWORD_NO_CDB);
xassert_eqq(password($marina), "ncurses");
xassert_eqq(password($marina, true), "isdevitch");
xassert(user($marina)->check_password("ncurses"));
xassert(user($marina)->check_password("isdevitch"));
xassert(user($marina)->check_password("ncurses"));

// null contactdb password => can log in locally
save_password($marina, null, true);
xassert(user($marina)->check_password("ncurses"));

// restore to "this is a cdb password"
user($marina)->change_password(null, "isdevitch", 0);
xassert_eqq(password($marina), "");
xassert_eqq(password($marina, true), "isdevitch");

// start upgrading passwords
if (function_exists("password_needs_rehash")) {
    $Conf->set_opt("safePasswords", 2);
    $Conf->set_opt("contactdb_safePasswords", 2);
    xassert(user($marina)->check_password("isdevitch"));
    xassert_eqq(substr(password($marina, true), 0, 2), " \$");
    xassert_eqq(password($marina), "");

    save_password($marina, ' $$2y$10$/URgqlFgQHpfE6mg4NzJhOZbg9Cc2cng58pA4cikzRD9F0qIuygnm', true);
    save_password($marina, '', false);
    xassert(user($marina)->check_password("isdevitch"));
    xassert_eqq(password($marina, true), ' $$2y$10$/URgqlFgQHpfE6mg4NzJhOZbg9Cc2cng58pA4cikzRD9F0qIuygnm');
}

// insert someone into the contactdb
$result = Dbl::qe(Contact::contactdb(), "insert into ContactInfo set firstName='Te', lastName='Thamrongrattanarit', email='te@_.com', affiliation='Brandeis University', collaborators='Computational Linguistics Magazine', password=' $$2y$10$/URgqlFgQHpfE6mg4NzJhOZbg9Cc2cng58pA4cikzRD9F0qIuygnm'");
assert(!!$result);
Dbl::free($result);
xassert(!user("te@_.com"));
$u = Contact::contactdb_find_by_email("te@_.com");
xassert(!!$u);
xassert_eqq($u->firstName, "Te");

// inserting them should succeed and borrow their data
$us = new UserStatus(array("send_email" => false));
$acct = $us->save((object) array("email" => "te@_.com"));
xassert(!!$acct);
$te = user("te@_.com");
xassert(!!$te);
xassert_eqq($te->firstName, "Te");
xassert_eqq($te->lastName, "Thamrongrattanarit");
xassert_eqq($te->affiliation, "Brandeis University");
if (function_exists("password_needs_rehash"))
    xassert($te->check_password("isdevitch"));
xassert_eqq($te->collaborators, "Computational Linguistics Magazine");

// changing email should work too, but not change cdb except for defaults
$result = Dbl::qe(Contact::contactdb(), "insert into ContactInfo set firstName='', lastName='Thamrongrattanarit 2', email='te2@_.com', affiliation='Brandeis University or something', collaborators='Newsweek Magazine', password=' $$2y$10$/URgqlFgQHpfE6mg4NzJhOZbg9Cc2cng58pA4cikzRD9F0qIuygnm'");
xassert(!!$result);
Dbl::free($result);
$acct = $us->save((object) ["email" => "te2@_.com", "lastName" => "Thamrongrattanarit 1", "firstName" => "Te 1"], $te);
xassert(!!$acct);
$te = user("te@_.com");
$te2 = user("te2@_.com");
xassert(!$te);
xassert(!!$te2);
xassert_eqq($te2->lastName, "Thamrongrattanarit 1");
xassert_eqq($te2->affiliation, "Brandeis University");
$te2_cdb = $te2->contactdb_user();
xassert(!!$te2_cdb);
xassert_eqq($te2_cdb->email, "te2@_.com");
xassert_eqq($te2_cdb->affiliation, "Brandeis University or something");
// if changing email, keep old value in cdb
xassert_eqq($te2_cdb->firstName, "Te 1");
xassert_eqq($te2_cdb->lastName, "Thamrongrattanarit 1");

// changes by the chair don't affect the cdb
$Me = user($marina);
$te2_cdb = $te2->contactdb_user();
Dbl::qe(Contact::contactdb(), "update ContactInfo set affiliation='' where email='te2@_.com'");
$acct = $us->save((object) ["firstName" => "Wacky", "affiliation" => "String"], $te2);
xassert(!!$acct);
$te2 = user("te2@_.com");
xassert(!!$te2);
xassert_eqq($te2->firstName, "Wacky");
xassert_eqq($te2->lastName, "Thamrongrattanarit 1");
xassert_eqq($te2->affiliation, "String");
$te2_cdb = $te2->contactdb_user();
xassert(!!$te2_cdb);
xassert_eqq($te2_cdb->firstName, "Te 1");
xassert_eqq($te2_cdb->lastName, "Thamrongrattanarit 1");
xassert_eqq($te2_cdb->affiliation, "String");

// borrow from cdb
$acct = $us->save((object) ["email" => "te@_.com"]);
xassert(!!$acct);
$te = user("te@_.com");
xassert_eqq($te->email, "te@_.com");
xassert_eqq($te->firstName, "Te");
xassert_eqq($te->lastName, "Thamrongrattanarit");
xassert_eqq($te->affiliation, "Brandeis University");
xassert_eqq($te->collaborators, "Computational Linguistics Magazine");

// create a user in cdb: create, then delete from local db
$anna = "akhmatova@poema.ru";
xassert(!user($anna));
$acct = $us->save((object) ["email" => $anna, "first" => "Anna", "last" => "Akhmatova"]);
xassert(!!$acct);
Dbl::qe("delete from ContactInfo where email=?", $anna);
save_password($anna, "aquablouse", true);
xassert(!user($anna));

$user_estrin = user("estrin@usc.edu");
$user_floyd = user("floyd@EE.lbl.gov");
$user_van = user("van@ee.lbl.gov");

$ps = new PaperStatus($Conf);
$ps->save_paper_json((object) [
    "id" => 1,
    "authors" => ["puneet@catarina.usc.edu", $user_estrin->email,
                  $user_floyd->email, $user_van->email, $anna]
]);

$paper1 = $Conf->paperRow(1, $user_chair);
$user_anna = user($anna);
xassert(!!$user_anna);
xassert($user_anna->act_author_view($paper1));
xassert($user_estrin->act_author_view($paper1));
xassert($user_floyd->act_author_view($paper1));
xassert($user_van->act_author_view($paper1));

xassert_exit();
