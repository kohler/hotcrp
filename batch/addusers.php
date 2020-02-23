<?php
$ConfSitePATH = preg_replace(',/batch/[^/]+,', '', __FILE__);
require_once("$ConfSitePATH/lib/getopt.php");

$arg = getopt_rest($argv, "hn:me:", array("help", "name:", "no-email", "no-notify", "modify", "expression:", "expr:"));
if (isset($arg["h"]) || isset($arg["help"])
    || count($arg["_"]) > 1
    || (count($arg["_"]) && $arg["_"][0] !== "-" && $arg["_"][0][0] === "-")) {
    $status = isset($arg["h"]) || isset($arg["help"]) ? 0 : 1;
    fwrite($status ? STDERR : STDOUT,
           "Usage: php batch/addusers.php [-n CONFID] [--modify] [--no-notify] [JSONFILE | CSVFILE | -e JSON]\n");
    exit($status);
}
if (isset($arg["modify"])) {
    $arg["m"] = false;
}
if (isset($arg["expr"])) {
    $arg["e"] = $arg["expr"];
} else if (isset($arg["expression"])) {
    $arg["e"] = $arg["expression"];
}

require_once("$ConfSitePATH/src/init.php");

function save_contact($ustatus, $key, $cj, $arg) {
    global $status;
    if (!isset($cj->id) && !isset($arg["m"])) {
        $cj->id = "new";
    }
    if (!isset($cj->email) && validate_email($key)) {
        $cj->email = $key;
    }
    $acct = $ustatus->save($cj);
    if ($acct) {
        fwrite(STDOUT, "Saved account {$acct->email}.\n");
    } else {
        foreach ($ustatus->errors() as $msg) {
            fwrite(STDERR, $msg . "\n");
            if (!isset($arg["m"]) && $ustatus->has_error_at("email_inuse")) {
                fwrite(STDERR, "(Use --modify to modify existing users.)\n");
            }
        }
        $status = 1;
    }
}

$file = count($arg["_"]) ? $arg["_"][0] : "-";
if (isset($arg["e"])) {
    $content = $arg["e"];
    $file = "<expr>";
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

$no_notify = isset($arg["no-email"]) || isset($arg["no-notify"]);
$ustatus = new UserStatus($Conf->site_contact(), ["no_notify" => $no_notify]);
$status = 0;
if (!preg_match(',\A\s*[\[\{],i', $content)) {
    $csv = new CsvParser(cleannl(convert_to_utf8($content)));
    $csv->set_comment_chars("#%");
    $line = $csv->next_array();
    if ($line && preg_grep('{\Aemail\z}i', $line)) {
        $csv->set_header($line);
    } else {
        fwrite(STDERR, "$file: 'email' field missing from CSV header\n");
        exit(1);
    }
    $ustatus->add_csv_synonyms($csv);
    while (($line = $csv->next_row())) {
        $ustatus->set_user(new Contact(null, $Conf));
        $ustatus->clear_messages();
        $cj = (object) ["id" => null];
        $ustatus->parse_csv_group("", $cj, $line);
        save_contact($ustatus, null, $cj, $arg);
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
        $ustatus->set_user(new Contact(null, $Conf));
        $ustatus->clear_messages();
        save_contact($ustatus, $key, $cj, $arg);
    }
}

exit($status);
