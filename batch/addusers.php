<?php
require_once("src/init.php");
require_once("lib/getopt.php");

$arg = getopt_rest($argv, "hn:me:", array("help", "name:", "no-email", "modify", "expression:", "expr:"));
if (isset($arg["h"]) || isset($arg["help"])
    || count($arg["_"]) > 1
    || (count($arg["_"]) && $arg["_"][0] !== "-" && $arg["_"][0][0] === "-")) {
    $status = isset($arg["h"]) || isset($arg["help"]) ? 0 : 1;
    fwrite($status ? STDERR : STDOUT,
           "Usage: php batch/addusers.php [-n CONFID] [--modify] [--no-email] [JSONFILE | CSVFILE | -e JSON]\n");
    exit($status);
}
if (isset($arg["modify"]))
    $arg["m"] = false;
if (isset($arg["expr"]))
    $arg["e"] = $arg["expr"];
else if (isset($arg["expression"]))
    $arg["e"] = $arg["expression"];

$file = count($arg["_"]) ? $arg["_"][0] : "-";
if (isset($arg["e"])) {
    $content = $arg["e"];
    $file = "<expr>";
} else if ($file === "-") {
    $content = stream_get_contents(STDIN);
    $file = "<stdin>";
} else
    $content = file_get_contents($file);
if ($content === false) {
    fwrite(STDERR, "$file: Read error\n");
    exit(1);
} else if (!preg_match(',\A\s*[\[\{],i', $content)) {
    $csv = new CsvParser($content);
    $csv->set_comment_chars("#%");
    $line = $csv->next();
    if ($line && array_search("email", $line) !== false)
        $csv->set_header($line);
    else {
        fwrite(STDERR, "$file: 'email' field missing from CSV header\n");
        exit(1);
    }
    $content = array();
    while (($line = $csv->next()))
        $content[] = (object) $line;
} else if (($content = json_decode($content)) === null) {
    fwrite(STDERR, "$file: " . (json_last_error_msg() ? : "JSON parse error") . "\n");
    exit(1);
}

if (!is_array($content))
    $content = array($content);
$status = 0;
foreach ($content as $cj) {
    $us = new UserStatus(array("no_email" => isset($arg["no-email"])));
    if (!isset($cj->id) && !isset($arg["m"]))
        $cj->id = "new";
    $acct = $us->save($cj);
    if ($acct)
        fwrite(STDOUT, "Saved account $acct->email.\n");
    else {
        foreach ($us->error_messages() as $msg) {
            fwrite(STDERR, $msg . "\n");
            if (!isset($arg["m"]) && $us->has_error("email_inuse"))
                fwrite(STDERR, "(Use --modify to modify existing users.)\n");
        }
        $status = 1;
    }
}

exit($status);
