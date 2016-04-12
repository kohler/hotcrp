<?php
$ConfSitePATH = preg_replace(',/batch/[^/]+,', '', __FILE__);
require_once("$ConfSitePATH/src/init.php");
require_once("$ConfSitePATH/lib/getopt.php");
require_once("$ConfSitePATH/lib/unicodehelper.php");

$trans = [2 => [], 3 => []];
for ($i = 0; $i < strlen(UTF8_ALPHA_TRANS_2); $i += 2)
    $trans[2][substr(UTF8_ALPHA_TRANS_2, $i, 2)] = rtrim(substr(UTF8_ALPHA_TRANS_2_OUT, $i, 2));
for ($i = $j = 0; $i < strlen(UTF8_ALPHA_TRANS_3); $i += 3, $j += 2)
    $trans[3][substr(UTF8_ALPHA_TRANS_3, $i, 3)] = rtrim(substr(UTF8_ALPHA_TRANS_3_OUT, $j, 2));

$arg = getopt_rest($argv, "hn:", array("help", "name:"));
if (isset($arg["h"]) || isset($arg["help"])) {
    fwrite(STDOUT, "Usage: php batch/updateutf8trans.php CODEPOINT STRING...\n");
    exit(0);
}

function quote_key($k) {
    if (strlen($k) == 2)
        return sprintf("\\x%02X\\x%02X", ord($k[0]), ord($k[1]));
    else
        return sprintf("\\x%02X\\x%02X\\x%02X", ord($k[0]), ord($k[1]), ord($k[2]));;
}

for ($i = 0; $i < count($arg["_"]); $i += 2) {
    $sin = $arg["_"][$i];
    $sout = trim(get_s($arg["_"], $i + 1));
    if ($sin !== "" && is_numeric($sin)) {
        $cp = (int) $sin;
        if ($cp < 0x80)
            $sin = chr($cp);
        else if ($cp < 0x800)
            $sin = chr(0xC0 | ($cp >> 6)) . chr(0x80 | ($cp & 0x3F));
        else if ($cp < 0x10000)
            $sin = chr(0xE0 | ($cp >> 12)) . chr(0x80 | (($cp >> 6) & 0x3F)) . chr(0x80 | ($cp & 0x3F));
        else if ($cp < 0x800)
            $sin = chr(0xF0 | ($cp >> 18)) . chr(0x80 | (($cp >> 12) & 0x3F)) . chr(0x80 | (($cp >> 6) & 0x3F)) . chr(0x80 | ($cp & 0x3F));
        else
            $sin = "";
    }
    if ($sin === "" || ord(substr($sin, 0, 1)) < 0xC0 || ord(substr($sin, 0, 1)) > 0xE0)
        fwrite(STDERR, "input argument $i is bad\n");
    else if (strlen($sout) > 2)
        fwrite(STDERR, "output argument $i too long\n");
    else if ($sout === "") {
        fwrite(STDERR, "unset $sin=" . quote_key($sin) . "\n");
        unset($trans[strlen($sin)][$sin]);
    } else {
        fwrite(STDERR, "set $sin=" . quote_key($sin) . " to $sout\n");
        $trans[strlen($sin)][$sin] = $sout;
    }
}

ksort($trans[2], SORT_STRING);
ksort($trans[3], SORT_STRING);

$unicode_helper = file_get_contents("$ConfSitePATH/lib/unicodehelper.php");
fwrite(STDOUT, substr($unicode_helper, 0, strpos($unicode_helper, "define(")));

$m = $n = "";
foreach ($trans[2] as $k => $v) {
    $m .= quote_key($k);
    $n .= addcslashes(substr($v . " ", 0, 2), "\\\"");
}
fwrite(STDOUT, "define(\"UTF8_ALPHA_TRANS_2\", \"$m\");\n\ndefine(\"UTF8_ALPHA_TRANS_2_OUT\", \"$n\");\n\n");

$m = $n = "";
foreach ($trans[3] as $k => $v) {
    $m .= quote_key($k);
    $n .= addcslashes(substr($v . " ", 0, 2), "\\\"");
}
fwrite(STDOUT, "define(\"UTF8_ALPHA_TRANS_3\", \"$m\");\n\ndefine(\"UTF8_ALPHA_TRANS_3_OUT\", \"$n\");\n\n");
