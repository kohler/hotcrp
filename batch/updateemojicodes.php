<?php
$ConfSitePATH = preg_replace(',/batch/[^/]+,', '', __FILE__);
require_once("$ConfSitePATH/src/init.php");

$x = [];
foreach (json_decode(stream_get_contents(STDIN)) as $i => $j) {
    if (is_string($j)) {
        if (preg_match('{emoji/unicode/([\da-f]{4,6}(?:-[\da-f]{4,6})*)\.}i', $j, $m)) {
            $t = "";
            foreach (explode("-", $m[1]) as $n => $c) {
                $c = intval($c, 16);
                if ($n !== 0) {
                    if ($c === 0x20E3)
                        $t .= UnicodeHelper::utf8_chr(0xFE0F);
                    else if ($c < 0x1F1E6 || $c > 0x1F1FF)
                        $t .= UnicodeHelper::utf8_chr(0x200D);
                }
                $t .= UnicodeHelper::utf8_chr($c);
            }
            assert(!isset($x[$i]));
            $x[$i] = $t;
        } else
        error_log($j);
    } else if (is_object($j) && isset($j->aliases) && isset($j->emoji)) {
        foreach ($j->aliases as $a) {
            assert(!isset($x[$a]));
            $x[$a] = $j->emoji;
        }
    }
}

ksort($x, SORT_STRING);
fwrite(STDOUT, "{\n");
$n = count($x);
foreach ($x as $a => $e) {
    --$n;
    fwrite(STDOUT, json_encode((string) $a) . ":" . json_encode($e, JSON_UNESCAPED_UNICODE) . ($n ? ",\n" : "\n"));
}
fwrite(STDOUT, "}\n");
