<?php
$ConfSitePATH = preg_replace(',/batch/[^/]+,', '', __FILE__);
require_once("$ConfSitePATH/src/init.php");
require_once("$ConfSitePATH/lib/getopt.php");

$arg = getopt_rest($argv, "hn:qr", ["help", "name:", "quiet", "disable", "disable-users",
                                    "reviews", "match-title", "ignore-pid"]);
if (isset($arg["h"]) || isset($arg["help"])
    || count($arg["_"]) > 1
    || (count($arg["_"]) && $arg["_"][0] !== "-" && $arg["_"][0][0] === "-")) {
    fwrite(STDOUT, "Usage: php batch/savepapers.php [-n CONFID] [OPTIONS] FILE

Options include:
  --quiet          Don't print progress information
  --disable-users  Newly created users are disabled
  --match-title    Match papers by title if no `pid`
  --ignore-pid     Ignore `pid` elements in JSON
  --reviews        Save JSON reviews\n");
    exit(0);
}

$file = count($arg["_"]) ? $arg["_"][0] : "-";
$quiet = isset($arg["q"]) || isset($arg["quiet"]);
$disable_users = isset($arg["disable"]) || isset($arg["disable-users"]);
$reviews = isset($arg["r"]) || isset($arg["reviews"]);
$match_title = isset($arg["match-title"]);
$ignore_pid = isset($arg["ignore-pid"]);
$site_contact = $Conf->site_contact();

if ($file === "-")
    $content = stream_get_contents(STDIN);
else
    $content = file_get_contents($file);
if ($content === false) {
    fwrite(STDERR, "$file: Read error\n");
    exit(1);
}

if (($jp = json_decode($content)) === null) {
    Json::decode($content); // our JSON decoder provides error positions
    fwrite(STDERR, "$file: invalid JSON: " . Json::last_error_msg() . "\n");
    exit(1);
} else if (!is_object($jp) && !is_array($jp)) {
    fwrite(STDERR, "$file: invalid JSON, expected array of objects\n");
    exit(1);
}

if (is_object($jp))
    $jp = array($jp);
$index = 0;
foreach ($jp as $j) {
    ++$index;
    if ($ignore_pid)
        unset($j->pid, $j->id);
    if (!isset($j->pid) && !isset($j->id) && isset($j->title) && is_string($j->title)) {
        $pids = Dbl::fetch_first_columns("select paperId from Paper where title=?", simplify_whitespace($j->title));
        if (count($pids) == 1)
            $j->pid = (int) $pids[0];
    }
    if (isset($j->pid) && is_int($j->pid) && $j->pid > 0)
        $pidtext = "#$j->pid";
    else if (!isset($j->pid) && isset($j->id) && is_int($j->id) && $j->id > 0)
        $pidtext = "#$j->id";
    else if (!isset($j->pid) && !isset($j->id))
        $pidtext = "new paper @$index";
    else {
        fwrite(STDERR, "paper @$index: bad pid\n");
        exit(1);
    }
    if (!$quiet) {
        if (isset($j->title) && is_string($j->title))
            fwrite(STDERR, $pidtext . " (" . UnicodeHelper::utf8_abbreviate($j->title, 40) . "): ");
        else
            fwrite(STDERR, $pidtext . ": ");
    }
    $ps = new PaperStatus($Conf, null, ["no_email" => true,
                                        "disable_users" => $disable_users,
                                        "allow_error" => ["topics", "options"]]);
    $pid = $ps->save_paper_json($j);
    if ($pid && str_starts_with($pidtext, "new")) {
        fwrite(STDERR, "-> #" . $pid . ": ");
        $pidtext = "#$pid";
    }
    if (!$quiet)
        fwrite(STDERR, $pid ? "saved\n" : "failed\n");
    $prefix = $pidtext . ": ";
    foreach ($ps->messages() as $msg)
        fwrite(STDERR, $prefix . htmlspecialchars_decode($msg) . "\n");
    if (!$pid)
        exit(1);
    // XXX more validation here
    if (isset($j->reviews) && is_array($j->reviews) && $reviews) {
        $rform = $Conf->review_form();
        $tf = $rform->blank_text_form();
        foreach ($j->reviews as $reviewindex => $reviewj)
            if (($rreq = $rform->parse_json($reviewj))
                && isset($rreq["reviewerEmail"])
                && validate_email($rreq["reviewerEmail"])) {
                $rreq["paperId"] = $pid;
                $user_req = Text::analyze_name(["name" => get($rreq, "reviewerName"), "email" => $rreq["reviewerEmail"], "affiliation" => get($rreq, "reviewerAffiliation")]);
                $user = Contact::create($Conf, $user_req);
                $rform->check_save_review($site_contact, $rreq, $tf, $user);
            } else
                $tf["err"][] = "invalid review @$reviewindex";
        foreach ($tf["err"] as $te)
            fwrite(STDERR, $prefix . htmlspecialchars_decode($te) . "\n");
    }
}
