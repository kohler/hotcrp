<?php
$ConfSitePATH = preg_replace(',/batch/[^/]+,', '', __FILE__);
require_once("$ConfSitePATH/src/init.php");
require_once("$ConfSitePATH/lib/getopt.php");

$arg = getopt_rest($argv, "hn:qr", ["help", "name:", "quiet", "disable", "disable-users", "reviews"]);
if (isset($arg["h"]) || isset($arg["help"])
    || count($arg["_"]) > 1
    || (count($arg["_"]) && $arg["_"][0] !== "-" && $arg["_"][0][0] === "-")) {
    fwrite(STDOUT, "Usage: php batch/savepapers.php [-n CONFID] [--disable-users] FILE\n");
    exit(0);
}

$file = count($arg["_"]) ? $arg["_"][0] : "-";
$quiet = isset($arg["q"]) || isset($arg["quiet"]);
$disable_users = isset($arg["disable"]) || isset($arg["disable-users"]);
$reviews = isset($arg["r"]) || isset($arg["reviews"]);
$site_contact = $Conf->site_contact();

if ($file === "-")
    $content = stream_get_contents(STDIN);
else
    $content = file_get_contents($file);
if ($content === false) {
    fwrite(STDERR, "$file: Read error\n");
    exit(1);
}

if (($jp = json_decode($content)) === false) {
    fwrite(STDERR, "$file: bad JSON (" . json_last_error_msg() . ")\n");
    exit(1);
}

if (is_object($jp))
    $jp = array($jp);
$index = 0;
foreach ($jp as $j) {
    ++$index;
    if (isset($j->pid) && is_int($j->pid) && $j->pid > 0)
        $prefix = "#$j->pid: ";
    else if (!isset($j->pid) && isset($j->id) && is_int($j->id) && $j->id > 0)
        $prefix = "#$j->id: ";
    else if (!isset($j->pid) && !isset($j->id))
        $prefix = "new paper #$index: ";
    else {
        fwrite(STDERR, "paper #$index: bad pid\n");
        exit(1);
    }
    if (!$quiet)
        fwrite(STDERR, $prefix);
    $ps = new PaperStatus($Conf, null, ["no_email" => true,
                                        "disable_users" => $disable_users,
                                        "allow_error" => ["topics", "options"]]);
    $pid = $ps->save_paper_json($j);
    if (!$quiet)
        fwrite(STDERR, $pid ? "saved\n" : "failed\n");
    foreach ($ps->messages() as $msg)
        fwrite(STDERR, $prefix . htmlspecialchars_decode($msg) . "\n");
    if (!$pid)
        exit(1);
    // XXX more validation here
    if (isset($j->reviews) && is_array($j->reviews) && $reviews) {
        $rform = $Conf->review_form();
        $tf = $rform->blank_text_form();
        foreach ($j->reviews as $reviewj)
            if (($rreq = $rform->parse_json($reviewj))
                && isset($rreq["reviewerEmail"])
                && validate_email($rreq["reviewerEmail"])) {
                $rreq["paperId"] = $pid;
                $user = Contact::create($Conf, ["name" => get($rreq, "reviewerName"), "email" => $rreq["reviewerEmail"]]);
                $rform->check_save_review($site_contact, $rreq, $tf, $user);
            }
        foreach ($tf["err"] as $te)
            fwrite(STDERR, $prefix . htmlspecialchars_decode($te) . "\n");
    }
}
