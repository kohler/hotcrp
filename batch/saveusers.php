<?php
// saveusers.php -- HotCRP maintenance script
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

require_once(dirname(__DIR__) . "/src/siteloader.php");

$arg = Getopt::rest($argv, "hn:e:u:r:", ["help", "name:", "no-email", "no-notify", "notify", "modify-only", "create-only", "no-modify", "no-create", "expression:", "expr:", "user:", "roles:", "uname:"]);
foreach (["expr" => "e", "expression" => "e", "no-email" => "no-notify",
          "no-create" => "modify-only", "no-modify" => "create-only",
          "user" => "u", "roles" => "r", "help" => "h"] as $long => $short) {
    if (isset($arg[$long]) && !isset($arg[$short]))
        $arg[$short] = $arg[$long];
}
if (isset($arg["h"])
    || count($arg["_"]) > 1
    || (!empty($arg["_"]) && $arg["_"][0] !== "-" && $arg["_"][0][0] === "-")
    || (!empty($arg["_"]) && isset($arg["u"]))
    || ((isset($arg["r"]) || isset($arg["uname"])) && !isset($arg["u"]))
    || (isset($arg["create-only"]) && isset($arg["modify-only"]))) {
    $status = isset($arg["h"]) || isset($arg["help"]) ? 0 : 1;
    fwrite($status ? STDERR : STDOUT,
           "Usage: php batch/saveusers.php [OPTION]... [JSONFILE | CSVFILE | -e JSON]
Or:    php batch/saveusers.php [OPTION]... -u EMAIL [--roles ROLES] \\
                               [--uname NAME]

Options: -n CONFID, --no-modify, --no-create, --no-notify\n");
    exit($status);
}

require_once(SiteLoader::find("src/init.php"));

function save_contact(UserStatus $ustatus, $key, $cj, $arg) {
    global $status;
    if (!isset($cj->email)
        && is_string($key)
        && validate_email($key)) {
        $cj->email = $key;
    }
    $acct = $ustatus->save($cj);
    if ($acct) {
        if (empty($ustatus->diffs)) {
            fwrite(STDOUT, "{$acct->email}: No changes.\n");
        } else {
            fwrite(STDOUT, "{$acct->email}: Saved " . join(", ", array_keys($ustatus->diffs)) . ".\n");
        }
    } else {
        if (isset($arg["create-only"]) && $ustatus->has_error_at("email_inuse")) {
            $ustatus->msg_at("email_inuse", "Use `--modify` to modify existing users.", MessageSet::INFORM);
        }
        fwrite(STDERR, $ustatus->full_feedback_text());
        $status = 1;
    }
}

$file = count($arg["_"]) ? $arg["_"][0] : "-";
if (isset($arg["e"])) {
    $content = $arg["e"];
    $file = "<expr>";
} else if (isset($arg["u"])) {
    $content = null;
} else if ($file === "-") {
    $content = stream_get_contents(STDIN);
    $file = "<stdin>";
} else {
    $content = file_get_contents($file);
}
if ($content === false) {
    fwrite(STDERR, "$file: Read error\n");
    exit(1);
}

$ustatus = new UserStatus($Conf->root_user());
$ustatus->notify = isset($arg["notify"]) || !isset($arg["no-notify"]);
$ustatus->no_create = isset($arg["modify-only"]);
$ustatus->no_modify = isset($arg["create-only"]);
$status = 0;
if (isset($arg["u"])) {
    $cj = (object) ["email" => $arg["u"]];
    if (isset($arg["r"])) {
        $cj->roles = $arg["r"];
    }
    if (isset($arg["uname"])) {
        $cj->name = $arg["uname"];
    }
    $ustatus->set_user(Contact::make($Conf));
    $ustatus->clear_messages();
    save_contact($ustatus, null, $cj, $arg);
} else if (!preg_match('/\A\s*[\[\{]/i', $content)) {
    $csv = new CsvParser(cleannl(convert_to_utf8($content)));
    $csv->set_comment_chars("#%");
    $line = $csv->next_list();
    if ($line !== null && preg_grep('/\Aemail\z/i', $line)) {
        $csv->set_header($line);
    } else {
        fwrite(STDERR, "$file: email field missing from CSV header\n");
        exit(1);
    }
    $ustatus->add_csv_synonyms($csv);
    while (($line = $csv->next_row())) {
        $ustatus->set_user(Contact::make($Conf));
        $ustatus->clear_messages();
        $ustatus->csvreq = $line;
        $ustatus->jval = (object) ["id" => null];
        $ustatus->parse_csv_group("");
        save_contact($ustatus, null, $ustatus->jval, $arg);
    }
} else {
    $content = json_decode($content);
    if (is_object($content)) {
        if (count((array) $content)
            && validate_email(array_keys((array) $content)[0])) {
            $content = (array) $content;
        } else {
            $content = [$content];
        }
    }
    if ($content === null || !is_array($content)) {
        fwrite(STDERR, "$file: " . (json_last_error_msg() ? : "JSON parse error") . "\n");
        exit(1);
    }
    foreach ($content as $key => $cj) {
        $ustatus->set_user(Contact::make($Conf));
        $ustatus->clear_messages();
        save_contact($ustatus, $key, $cj, $arg);
    }
}

exit($status);
