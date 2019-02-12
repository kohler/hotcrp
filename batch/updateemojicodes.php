<?php
$ConfSitePATH = preg_replace(',/batch/[^/]+,', '', __FILE__);
require_once("$ConfSitePATH/src/init.php");

$arg = getopt("cdt", ["common", "dups", "terminators"]);

function parse_emoji_data_stdin() {
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
    fwrite(STDOUT, "{\n\"emoji\":{\n");
    $n = count($x);
    foreach ($x as $a => $e) {
        --$n;
        fwrite(STDOUT, json_encode((string) $a) . ":" . json_encode($e, JSON_UNESCAPED_UNICODE) . ($n ? ",\n" : "\n"));
    }
    fwrite(STDOUT, "}\n}\n");
}

function list_duplicate_codes() {
    global $ConfSitePATH;
    $emoji = json_decode(file_get_contents("$ConfSitePATH/scripts/emojicodes.json"));
    $codes = $dups = [];
    foreach ((array) $emoji->emoji as $code => $text) {
        if (!isset($codes[$text]))
            $codes[$text] = [$code];
        else {
            $dups[$text] = true;
            $codes[$text][] = $code;
        }
    }
    fwrite(STDOUT, json_encode(array_intersect_key($codes, $dups), JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT) . "\n");
}

function emoji_to_preferred_code($emoji) {
    $codes = [];
    $pref = get($emoji, "preferred_codes", []);
    foreach ((array) $emoji->emoji as $code => $text) {
        if (!isset($codes[$text])
            || (!in_array($codes[$text], $pref)
                && (in_array($code, $pref) || strlen($code) < strlen($codes[$text]))))
            $codes[$text] = $code;
    }
    return $codes;
}

function emoji_to_code_set($emoji) {
    $codes = [];
    $pref = get($emoji, "preferred_codes", []);
    foreach ((array) $emoji->emoji as $code => $text) {
        if (!isset($codes[$text])
            || in_array($code, $pref))
            $codes[$text] = [$code];
        else if (!in_array($codes[$text][0], $pref))
            $codes[$text][] = $code;
    }
    return $codes;
}

function list_common_emoji() {
    global $ConfSitePATH;
    $emoji = json_decode(file_get_contents("$ConfSitePATH/scripts/emojicodes.json"));
    $back = emoji_to_code_set($emoji);

    $rankings = json_decode(stream_get_contents(STDIN));
    $total_score = 0;
    foreach ($rankings as $j) {
        $total_score += $j->score;
    }

    $fcodes = $fscores = [];
    $slice_score = 0;
    foreach ($rankings as $j) {
        foreach (get($back, $j->char, []) as $code) {
            $ch = $code[0];
            if (!isset($fcodes[$ch])
                || $j->score >= 0.001 * $total_score
                || ($j->score >= 0.0001 * $total_score && $fscores[$ch] < 0.001 * $total_score)) {
                if (!isset($fcodes[$ch])) {
                    $fcodes[$ch] = [];
                    $fscores[$ch] = 0;
                }
                $fcodes[$ch][] = (string) $code;
                $fscores[$ch] += $j->score;
                $slice_score += $j->score;
            }
        }
    }

    $codes = [];
    foreach ($fcodes as $x) {
        $codes = array_merge($codes, $x);
    }
    sort($codes);

    fwrite(STDERR, sprintf("Sliced %.2f%% of total scores.\n", $slice_score / $total_score * 100));
    fwrite(STDOUT, json_encode($codes) . "\n");
}

function list_terminators() {
    global $ConfSitePATH;
    $emoji = json_decode(file_get_contents("$ConfSitePATH/scripts/emojicodes.json"));
    $x = [];
    foreach ((array) $emoji->emoji as $text) {
        preg_match('/.\z/u', $text, $m);
        $x[UnicodeHelper::utf8_ord($m[0])] = true;
    }
    $x = array_keys($x);
    sort($x);
    fwrite(STDOUT, json_encode(array_map("dechex", $x)) . "\n");
}

if (isset($arg["c"]) || isset($arg["common"])) {
    list_common_emoji();
} else if (isset($arg["d"]) || isset($arg["dups"])) {
    list_duplicate_codes();
} else if (isset($arg["t"]) || isset($arg["terminators"])) {
    list_terminators();
} else {
    parse_emoji_data_stdin();
}
