<?php
// devel/oss-scanner/seed.php -- load a sample conference for the OSS Scanner image
// Copyright (c) 2006-2026 Eddie Kohler; see LICENSE.
//
// Usage: php devel/oss-scanner/seed.php [--minimal] CONFID fixture|small [PASSWORD]
//
// `fixture` rebuilds conference CONFID, and the shared contact database, from
// the test fixture test/db.json. `small` adds accounts to a conference whose
// database holds a fresh schema: a chair who is only an author in the fixture,
// and two PC members. Either way, submissions and reviewing open, and every
// account's password becomes PASSWORD (default `test1234`). Mail is discarded
// while seeding.
//
// Unless `--minimal` is given, the conference is then populated as if
// mid-review: submitted and draft reviews (with generated text), external
// reviews, comments of every visibility, author responses, unreleased
// decisions, tags (public, private, chair-only, hidden, votes, and a track),
// and review preferences. Generation is deterministic, so every build
// produces the same data.

require_once(dirname(__DIR__, 2) . "/test/setup.php");

$args = array_slice($argv, 1);
$minimal = ($args[0] ?? null) === "--minimal";
if ($minimal) {
    array_shift($args);
}
if (count($args) < 2 || !in_array($args[1], ["fixture", "small"], true)) {
    fwrite(STDERR, "Usage: php devel/oss-scanner/seed.php [--minimal] CONFID fixture|small [PASSWORD]\n");
    exit(2);
}
[$confid, $kind] = $args;
$password = $args[2] ?? "test1234";

global $Opt;
$Opt = [];
SiteLoader::read_main_options(null, $confid);
$Opt["sendEmail"] = false;
$Opt["hooks"]["send_mail"] = static function () { return false; };
// The fixture loader checks for test-suite mail; this configuration differs.
MailChecker::$messagedb = [];
$conf = initialize_conf();
Navigation::set(NavigationState::make_base($conf->opt("paperSite")));

if ($kind === "fixture") {
    Xassert::$test_runner = null;
    TestRunner::reset_db(true);
    // A second chair, conflicted with a paper that has its own administrator
    $chair2 = Contact::make_keyed($conf, ["email" => "chair2@z.edu", "name" => "Chris Chair"])->store();
    $chair2->save_roles(Contact::ROLE_ADMIN | Contact::ROLE_CHAIR | Contact::ROLE_PC, $conf->root_user());
    assign($conf->root_user(), "paper,action,email\n20,conflict,chair2@z.edu\n20,manager,marina@poema.ru\n");
} else {
    $conf->qe("delete from Settings where name='setupPhase'");
    if (($cdb = $conf->contactdb())) {
        Dbl::qe($cdb, "insert into Conferences set confuid=?", $conf->dbname);
    }
    $accounts = [
        ["vern@ee.lbl.gov", Contact::ROLE_ADMIN | Contact::ROLE_CHAIR | Contact::ROLE_PC],
        ["marina@poema.ru", Contact::ROLE_PC],
        ["anja@research.att.com", Contact::ROLE_PC]
    ];
    foreach ($accounts as $acct) {
        $u = Contact::make_keyed($conf, ["email" => $acct[0]])->store();
        $u->save_roles($acct[1], $conf->root_user());
    }
    $conf->call_shutdown_function("CdbUserUpdate");
}

// Open submissions and reviewing, with deadlines a year away so a long-lived
// image stays open.
$open = Conf::$now - 86400;
$close = Conf::$now + 365 * 86400;
foreach (["sub_open" => $open, "sub_reg" => $close, "sub_sub" => $close,
          "rev_open" => $open, "pcrev_soft" => $close, "pcrev_hard" => $close,
          "extrev_soft" => $close, "extrev_hard" => $close] as $name => $value) {
    $conf->save_setting($name, $value);
}
$conf->refresh_settings();

if (!$minimal) {
    mt_srand(crc32($confid));
    if ($kind === "fixture") {
        populate_fixture($conf);
    } else {
        populate_small($conf);
    }
}

$hash = " \$" . password_hash($password, PASSWORD_DEFAULT);
$dblinks = [$conf->dblink];
if (($cdb = $conf->contactdb())) {
    $dblinks[] = $cdb;
}
foreach ($dblinks as $dblink) {
    Dbl::qe($dblink, "update ContactInfo set password=?, passwordTime=?, passwordUseTime=?",
            $hash, Conf::$now, Conf::$now);
}

$conf->call_shutdown_functions();
fwrite(STDERR, "Seeded {$conf->confid}" . ($minimal ? " (minimal)" : "")
       . "; every account's password is {$password}\n");


/** @param list<string> $l
 * @return string */
function pick($l) {
    return $l[mt_rand(0, count($l) - 1)];
}

/** @param int $nsentences
 * @return string */
function prose($nsentences) {
    $subj = ["The paper", "This work", "The proposed design", "The evaluation",
             "The core idea", "The prototype", "The analysis", "The related-work section",
             "The main theorem", "The system"];
    $verb = ["convincingly shows", "attempts to show", "does not fully establish",
             "clearly demonstrates", "only partially supports", "overstates",
             "carefully measures", "argues"];
    $obj = ["that soft state scales to large networks",
            "a modest improvement over the baseline",
            "that the overhead is acceptable in practice",
            "the benefit of adaptive timers",
            "robustness under heavy packet loss",
            "that the approach generalizes beyond the testbed",
            "a tradeoff between latency and bandwidth",
            "the correctness of the recovery protocol"];
    $tail = ["", "", " The experiments are small.", " I would like to see a larger deployment.",
             " The writing is clear.", " Several figures are hard to read.",
             " A comparison with prior work is missing.", " The threat model is unclear."];
    $s = [];
    for ($i = 0; $i < $nsentences; ++$i) {
        $s[] = pick($subj) . " " . pick($verb) . " " . pick($obj) . "." . pick($tail);
    }
    return join(" ", $s);
}

/** @param Contact $user
 * @param string $csv */
function assign($user, $csv) {
    $aset = (new AssignmentSet($user))->set_override_conflicts(true);
    $aset->parse($csv);
    if (!$aset->execute()) {
        fwrite(STDERR, "seed: assignment failed:\n" . $aset->full_feedback_text());
    }
}

/** @param Contact $chair
 * @param array $j */
function settings($chair, $j) {
    $sv = (new SettingValues($chair))->set_use_req(true);
    $sv->add_json_string(json_encode($j));
    if (!$sv->execute()) {
        fwrite(STDERR, "seed: settings failed:\n" . $sv->full_feedback_text());
    }
}

/** @param Contact $user
 * @param int $pid
 * @param array $req */
function comment($user, $pid, $req, ?CommentInfo $crow = null) {
    $prow = $user->conf->checked_paper_by_id($pid, $user);
    $crow = $crow ?? CommentInfo::make_new_template($user, $prow);
    $cs = new CommentStatus($user);
    if (!$cs->prepare_save($crow, $req) || !$cs->execute_save()) {
        fwrite(STDERR, "seed: comment on #{$pid} by {$user->email} failed:\n" . $cs->full_feedback_text());
    }
}

/** @param Contact $user
 * @param int $pid
 * @param bool $ready */
function review($user, $pid, $ready) {
    $rrow = save_review($pid, $user, [
        "s01" => mt_rand(1, 5), "s02" => mt_rand(1, 4),
        "t01" => prose(2), "t02" => prose(mt_rand(3, 6)), "t03" => prose(1),
        "ready" => $ready
    ], null, ["quiet" => true]);
    if (!$rrow || ($ready && $rrow->reviewStatus < ReviewInfo::RS_COMPLETED)) {
        fwrite(STDERR, "seed: review of #{$pid} by {$user->email} failed\n");
    }
}

/** @param Conf $conf
 * @param int $pid
 * @return list<Contact> */
function non_pc_authors($conf, $pid) {
    $us = [];
    foreach ($conf->checked_paper_by_id($pid)->contact_list() as $u) {
        if (!$u->is_pc_member()) {
            $us[] = $u;
        }
    }
    return $us;
}

function populate_fixture(Conf $conf) {
    $chair = $conf->checked_user_by_email("chair@_.com");

    // Settings: a track visible only to PC members tagged `red`, allotment
    // voting, a hidden tag, and an open response round.
    settings($chair, [
        "track" => [["id" => "new", "tag" => "red-track", "perm" => ["view" => "+red"]]],
        "tag_vote_allotment" => "vote#10",
        "tag_hidden" => "secret",
        "response_active" => true,
        "response" => [["id" => 1,
                        "open" => "@" . (Conf::$now - 86400),
                        "done" => "@" . (Conf::$now + 365 * 86400)]]
    ]);

    // Tags
    assign($chair, "paper,action,tag\n"
           . "3,tag,red-track\n7,tag,red-track\n12,tag,red-track\n"
           . "2,tag,discuss\n5,tag,discuss\n9,tag,discuss\n16,tag,discuss\n"
           . "4,tag,secret\n11,tag,secret\n"
           . "5,tag,~~chairnote\n6,tag,~~chairnote\n");

    // Reviews: about two-thirds of assignments submitted, a sixth drafts
    $result = $conf->qe("select paperId, contactId from PaperReview order by paperId, reviewId");
    $rows = $result->fetch_all();
    Dbl::free($result);
    foreach ($rows as [$pid, $cid]) {
        $x = mt_rand(0, 5);
        if ($x < 5) {
            review($conf->user_by_id((int) $cid), (int) $pid, $x < 4);
        }
    }

    // External reviews, one requested by a PC member
    assign($chair, "paper,action,email,name,reviewtype\n"
           . "4,review,cathy.ext@example.org,Cathy External,external\n"
           . "8,review,dmitri.ext@example.org,Dmitri External,external\n");
    review($conf->checked_user_by_email("cathy.ext@example.org"), 4, true);
    // (With the fixture's `extrev_chairreq`, this may become a proposal
    // awaiting chair approval.)
    $estrin = $conf->checked_user_by_email("estrin@usc.edu");
    $jr = RequestReview_API::requestreview($estrin,
        new Qrequest("POST", ["email" => "erin.ext@example.org", "name" => "Erin External"]),
        $conf->checked_paper_by_id(18, $estrin));
    if (!$jr->content["ok"]) {
        fwrite(STDERR, "seed: review request failed: " . json_encode($jr->content) . "\n");
    }

    // Comments of each visibility, one anonymous to authors
    $marina = $conf->checked_user_by_email("marina@poema.ru");
    $floyd = $conf->checked_user_by_email("floyd@ee.lbl.gov");
    foreach ([1, 2, 5, 9, 13] as $pid) {
        $prow = $conf->checked_paper_by_id($pid);
        $reviewers = array_values(array_filter($prow->reviews_as_list(),
            function ($r) { return $r->reviewStatus >= ReviewInfo::RS_COMPLETED; }));
        if (empty($reviewers)) {
            continue;
        }
        $r = $conf->user_by_id($reviewers[0]->contactId);
        comment($r, $pid, ["text" => prose(2), "visibility" => "pc"]);
        comment($r, $pid, ["text" => "Question for the authors: " . prose(1),
                           "visibility" => "au", "blind" => true]);
        comment($r, $pid, ["text" => prose(1), "visibility" => "rev"]);
    }
    comment($chair, 5, ["text" => "Chair note: " . prose(1), "visibility" => "admin"]);
    comment($marina, 9, ["text" => "Discussion lead: " . prose(2), "visibility" => "pc"]);
    comment($floyd, 2, ["text" => prose(1), "visibility" => "pc", "topic" => "paper"]);

    // Author responses
    $rrd = $conf->response_round("1");
    foreach ([1, 2] as $pid) {
        if (($au = non_pc_authors($conf, $pid))) {
            $prow = $conf->checked_paper_by_id($pid);
            comment($au[0], $pid, ["text" => "We thank the reviewers. " . prose(3), "submit" => true],
                    CommentInfo::make_response_template($rrd, $prow));
        }
    }
    settings($chair, ["response_active" => false]);

    // Decisions, not yet visible to authors
    assign($chair, "paper,action,decision\n"
           . "1,decision,accept\n2,decision,reject\n5,decision,accept\n"
           . "9,decision,reject\n13,decision,accept\n");

    // Votes, private tags, and review preferences
    foreach ([["marina@poema.ru", "2,~vote#3\n5,~vote#4\n9,~vote#1\n13,~toread\n", "1,10\n2,-5\n4,20\n"],
              ["estrin@usc.edu", "5,~vote#2\n16,~vote#5\n13,~toread\n", "6,3\n8,-20\n"],
              ["floyd@ee.lbl.gov", "2,~vote#1\n16,~vote#2\n", "10,5\n"]] as [$email, $votes, $prefs]) {
        $u = $conf->checked_user_by_email($email);
        assign($u, "paper,tag\n" . $votes);
        assign($u, "paper,preference\n" . $prefs);
    }
}

function populate_small(Conf $conf) {
    $chair = $conf->checked_user_by_email("vern@ee.lbl.gov");
    $papers = [
        ["title" => "Measuring Cross-Site Session Reuse",
         "authors" => [["name" => "Deborah Estrin", "email" => "estrin@usc.edu"],
                       ["name" => "Puneet Sharma", "email" => "puneet@catarina.usc.edu"]]],
        ["title" => "A Second Look at Soft State",
         "authors" => [["name" => "Sally Floyd", "email" => "floyd@ee.lbl.gov"]]]
    ];
    foreach ($papers as $pj) {
        $ps = new PaperStatus($conf->root_user());
        $pj += ["id" => "new", "abstract" => prose(3), "submitted" => true,
                "submission" => ["content" => "%PDF-1.4 sample"]];
        if (!$ps->save_paper_json(json_decode(json_encode($pj)))) {
            fwrite(STDERR, "seed: paper failed:\n" . $ps->full_feedback_text(true));
        }
    }
    assign($chair, "paper,action,email\n1,primary,marina@poema.ru\n2,primary,anja@research.att.com\n");
    review($conf->checked_user_by_email("marina@poema.ru"), 1, true);
}
