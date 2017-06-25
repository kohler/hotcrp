<?php
$ConfSitePATH = preg_replace(',/batch/[^/]+,', '', __FILE__);
require_once("$ConfSitePATH/src/init.php");
require_once("$ConfSitePATH/lib/getopt.php");
require_once("$ConfSitePATH/lib/unicodehelper.php");

$arg = getopt_rest($argv, "hn:f:Vuto:",
    ["help", "name:", "file:", "verbose", "unparse", "time", "output:"]);
if (isset($arg["h"]) || isset($arg["help"])) {
    fwrite(STDOUT, "Usage: php batch/updateutf8trans.php CODEPOINT STRING...\n");
    fwrite(STDOUT, "       php batch/updateutf8trans.php -f UnicodeData.txt\n");
    fwrite(STDOUT, "       php batch/updateutf8trans.php -t [STRING...] [-o OUTPUTFILE]\n");
    exit(0);
}

$verbose = isset($arg["V"]) || isset($arg["verbose"]);
$unparse = isset($arg["u"]) || isset($arg["unparse"]);

if (isset($arg["t"]) || isset($arg["time"])) {
    if (isset($arg["f"]) || isset($arg["file"])) {
        $filename = get($arg, "f", get($arg, "file"));
        if ($filename === "-")
            $text = stream_get_contents(STDIN);
        else
            $text = file_get_contents($filename);
        if ($text === false) {
            fwrite(STDERR, "$filename: Cannot read\n");
            exit(1);
        }
    } else if (!empty($arg["_"]))
        $text = join(" ", $arg["_"]) . "\n";
    else
        $text = stream_get_contents(STDIN);

    $n = 10000000 / strlen($text);
    $begint = microtime(true);
    for ($i = 0; $i < $n; ++$i)
        $textlet = UnicodeHelper::deaccent($text);
    $endt = microtime(true);

    if (isset($arg["o"]) || isset($arg["output"])) {
        if (str_starts_with($textlet, "\xEF\xBB\xBF"))
            $textlet = substr($textlet, 3);
        $filename = get($arg, "o", get($arg, "output"));
        if ($filename === "-")
            fwrite(STDOUT, $textlet);
        else
            file_put_contents($filename, $textlet);
    }

    fwrite(STDERR, sprintf("%f Mchars/sec\n", (strlen($text) * $i / 1000000.0) / ($endt - $begint)));
    exit(0);
}

define("OUTL2", 2);
define("OUTL3", 3);

$trans = [2 => [], 3 => []];
$l = strlen(UTF8_ALPHA_TRANS_2);
assert($l % 2 == 0);
$outl = 2;
assert(strlen(UTF8_ALPHA_TRANS_2_OUT) === ($l / 2) * $outl);
for ($i = 0; $i < $l; $i += $outl)
    $trans[2][substr(UTF8_ALPHA_TRANS_2, $i, 2)] = rtrim(substr(UTF8_ALPHA_TRANS_2_OUT, $i, $outl));
$l = strlen(UTF8_ALPHA_TRANS_3);
assert($l % 3 == 0);
$outl = 3;
assert(strlen(UTF8_ALPHA_TRANS_3_OUT) === ($l / 3) * $outl);
for ($i = $j = 0; $i < $l; $i += 3, $j += $outl)
    $trans[3][substr(UTF8_ALPHA_TRANS_3, $i, 3)] = rtrim(substr(UTF8_ALPHA_TRANS_3_OUT, $j, $outl));

function quote_key($k) {
    if (strlen($k) == 2)
        return sprintf("\\x%02X\\x%02X", ord($k[0]), ord($k[1]));
    else
        return sprintf("\\x%02X\\x%02X\\x%02X", ord($k[0]), ord($k[1]), ord($k[2]));;
}

function numeric_to_utf8($x, $base = 10) {
    $x = is_int($x) ? $x : intval($x, $base);
    if ($x < 0x110000)
        return UnicodeHelper::utf8_chr($x);
    else
        return "";
}

function assign_it($sin, $sout, $override, $description = false) {
    global $trans;
    if (is_int($sin))
        $sin = UnicodeHelper::utf8_chr($sin);
    $sxin = sprintf("U+%04X", UnicodeHelper::utf8_ord($sin));
    if ($description)
        $sxin .= ";" . $description;
    $l = strlen($sin);
    $soutch = $sout === "*" ? "" : $sout;
    if ($sin === ""
        || ord(substr($sin, 0, 1)) < 0xC0
        || ord(substr($sin, 0, 1)) >= 0xF0)
        fwrite(STDERR, "input argument $sxin->$sout is bad\n");
    else if (strlen($sout) > ($l > 2 ? OUTL3 : OUTL2))
        fwrite(STDERR, "output argument $sxin->$sout too long\n");
    else if (($sout === "" && !isset($trans[$l][$sin]))
             || (isset($trans[$l][$sin]) && $trans[$l][$sin] === $soutch))
        /* skip */;
    else if (!$override && isset($trans[$l][$sin])) {
        fwrite(STDERR, "ignore $sxin->$sout, have $sxin->" . sprintf("U+%04X", UnicodeHelper::utf8_ord($trans[$l][$sin])) . "\n");
    } else if ($sout === "") {
        fwrite(STDERR, "change $sxin->\n");
        unset($trans[strlen($sin)][$sin]);
    } else {
        fwrite(STDERR, "change $sxin->$sout\n");
        $trans[strlen($sin)][$sin] = $soutch;
    }
}

if (isset($arg["f"]) || isset($arg["file"])) {
    $ignore_latin = [0x212B /* ANGSTROM SIGN */ => true];
    $filename = get($arg, "f", get($arg, "file", null));
    if ($filename === "-")
        $unidata = stream_get_contents(STDIN);
    else
        $unidata = file_get_contents($filename);
    if ($unidata === false) {
        fwrite(STDERR, "$filename: Cannot read\n");
        exit(1);
    }
    foreach (explode("\n", $unidata) as $line) {
        $line = rtrim($line);
        if ($line === "")
            continue;
        $uc = explode(";", $line);
        if (count($uc) < 10)
            continue;
        $c = intval($uc[0], 16);
        if ($unparse) {
            $ch = numeric_to_utf8($c);
            if (isset($trans[strlen($ch)][$ch]))
                fwrite(STDOUT, "U+$uc[0];" . quote_key($ch) . ";$uc[1] -> " . $trans[strlen($ch)][$ch] . "\n");
        } else if ($uc[2][0] === "M") {
            if ($c < 0x363)
                assign_it($c, "*", false, $uc[1]);
        } else if ($uc[2][0] === "L" && $uc[5]) {
            $comb = preg_replace('/\A<(?:compat|wide|narrow|circle)>\s*/', "", $uc[5]);
            $latin = $latinmark = $all = [];
            foreach (explode(" ", $comb) as $comp)
                if ($comp !== "") {
                    $compc = intval($comp, 16);
                    $compch = UnicodeHelper::utf8_chr($compc);
                    if (($compc >= 0x41 && $compc <= 0x5A)
                        || ($compc >= 0x61 && $compc <= 0x7A))
                        $latin[] = $latinmark[] = $all[] = $compch;
                    else if ($compc >= 0x300 && $compc <= 0x362)
                        $latinmark[] = $all[] = $compch;
                    else if (isset($trans[strlen($compch)][$compch]))
                        $latin[] = $latinmark[] = $all[] = $trans[strlen($compch)][$compch];
                    else
                        $all[] = $compch;
                }
            if (!isset($ignore_latin[$c])
                && !empty($latin)
                && count($latinmark) === count($all))
                assign_it($c, join("", $latin), false, $uc[1]);
            else if ($verbose)
                fwrite(STDERR, "ignoring U+{$uc[0]};{$uc[1]};{$uc[5]}\n");
        }
    }
    if ($unparse)
        exit(0);
}

for ($i = 0; $i < count($arg["_"]); $i += 2) {
    $sin = $arg["_"][$i];
    $sout = trim(get_s($arg["_"], $i + 1));
    if ($sin !== "" && is_numeric($sin))
        $sin = numeric_to_utf8($sin, 0);
    assign_it($sin, $sout, true);
}

ksort($trans[2], SORT_STRING);
ksort($trans[3], SORT_STRING);

$unicode_helper = file_get_contents("$ConfSitePATH/lib/unicodehelper.php");
fwrite(STDOUT, substr($unicode_helper, 0, strpos($unicode_helper, "define(")));

$m = $n = "";
foreach ($trans[2] as $k => $v) {
    $m .= quote_key($k);
    $n .= addcslashes(substr($v . "   ", 0, OUTL2), "\\\"");
}
fwrite(STDOUT, "define(\"UTF8_ALPHA_TRANS_2\", \"$m\");\n\ndefine(\"UTF8_ALPHA_TRANS_2_OUT\", \"$n\");\n\n");

$m = $n = "";
foreach ($trans[3] as $k => $v) {
    $m .= quote_key($k);
    $n .= addcslashes(substr($v . "   ", 0, OUTL3), "\\\"");
}
fwrite(STDOUT, "define(\"UTF8_ALPHA_TRANS_3\", \"$m\");\n\ndefine(\"UTF8_ALPHA_TRANS_3_OUT\", \"$n\");\n\n");
