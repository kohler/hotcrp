<?php
require_once(dirname(__DIR__) . "/src/init.php");

$optind = null;
$arg = getopt("acdmn:t", ["absent", "common", "dups", "modifier-bases", "name:", "terminators"], $optind);

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
    $emoji = json_decode(file_get_contents(SiteLoader::find("scripts/emojicodes.json")));
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
    $pref = $emoji->preferred_codes ?? [];
    foreach ((array) $emoji->emoji as $code => $text) {
        if (!isset($codes[$text])
            || (!in_array($codes[$text], $pref)
                && (in_array($code, $pref) || strlen($code) < strlen($codes[$text]))))
            $codes[$text] = $code;
    }
    return $codes;
}

/** @return array<string,list<string>> */
function emoji_to_code_set($emoji) {
    $codes = [];
    $pref = $emoji->preferred_codes ?? [];
    foreach ((array) $emoji->emoji as $code => $text) {
        if (!isset($codes[$text])
            || in_array($code, $pref)) {
            $codes[$text] = [$code];
        } else if (!in_array($codes[$text][0], $pref)) {
            $codes[$text][] = $code;
        }
    }
    return $codes;
}

function list_common_emoji() {
    $emoji = json_decode(file_get_contents(SiteLoader::find("scripts/emojicodes.json")));
    $back = emoji_to_code_set($emoji);

    $rankings = json_decode(stream_get_contents(STDIN));
    $total_score = 0;
    foreach ($rankings as $j) {
        $total_score += $j->score;
    }

    $fcodes = $fscores = [];
    $slice_score = 0;
    foreach ($rankings as $j) {
        foreach ($back[$j->char] ?? [] as $code) {
            $ch = $code[0];
            if (!isset($fcodes[$ch])
                || !isset($fscores[$ch])
                || $j->score >= 0.001 * $total_score
                || ($j->score >= 0.0001 * $total_score
                    && $fscores[$ch] < 0.001 * $total_score)) {
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
    $emoji = json_decode(file_get_contents(SiteLoader::find("scripts/emojicodes.json")));
    $x = [];
    foreach ((array) $emoji->emoji as $text) {
        preg_match('/.\z/u', $text, $m);
        $x[UnicodeHelper::utf8_ord($m[0])] = true;
    }
    $x = array_keys($x);
    sort($x);
    fwrite(STDOUT, json_encode(array_map("dechex", $x)) . "\n");
}

function modifier_base_regex() {
    $x = [];
    foreach (explode("\n", stream_get_contents(STDIN)) as $line) {
        if (preg_match('/\A([0-9A-F]+)(\.\.[0-9A-F]+|)\s.*Emoji_Modifier_Base/', $line, $m)) {
            $i = hexdec($m[1]);
            $j = $m[2] === "" ? $i : hexdec(substr($m[2], 2));
            for (; $i <= $j; ++$i) {
                $x[] = UnicodeHelper::utf16_ord(UnicodeHelper::utf8_chr($i));
            }
        }
    }
    fwrite(STDOUT, "([");
    $n = count($x);
    for ($i = 0; $i !== $n && count($x[$i]) === 1; $i = $j) {
        for ($j = $i + 1;
             $j !== $n && count($x[$j]) === 1 && $x[$j][0] === $x[$i][0] + ($j - $i);
             ++$j) {
        }
        if ($j - $i === 1) {
            fwrite(STDOUT, sprintf("\\u%04x", $x[$i][0]));
        } else {
            fwrite(STDOUT, sprintf("\\u%04x-\\u%04x", $x[$i][0], $x[$j-1][0]));
        }
    }
    fwrite(STDOUT, "]");
    for (; $i !== $n; $i = $j) {
        fwrite(STDOUT, sprintf("|\\u%04x", $x[$i][0]));
        for ($j = $i + 1; $j !== $n && $x[$j][0] === $x[$i][0]; ++$j) {
        }
        if ($j - $i === 1) {
            fwrite(STDOUT, sprintf("\\u%04x", $x[$i][1]));
        } else {
            fwrite(STDOUT, "[");
            for ($k = $i; $k !== $j; $k = $l) {
                for ($l = $k + 1; $l !== $j && $x[$l][1] === $x[$k][1] + ($l - $k); ++$l) {
                }
                if ($l - $k === 1) {
                    fwrite(STDOUT, sprintf("\\u%04x", $x[$k][1]));
                } else {
                    fwrite(STDOUT, sprintf("\\u%04x-\\u%04x", $x[$k][1], $x[$l-1][1]));
                }
            }
            fwrite(STDOUT, "]");
        }
    }
    fwrite(STDOUT, ")\n");
}

function list_absent($args) {
    $emoji = json_decode(file_get_contents(SiteLoader::find("scripts/emojicodes.json")));
    $codes = [];
    foreach ((array) $emoji->emoji as $text) {
        $codes[$text] = true;
        if (str_ends_with($text, "\xEF\xB8\x8F"))
            $codes[substr($text, 0, -3)] = true;
    }

    if (count($args) !== 2) {
        fwrite(STDERR, "Expected arguments `EMOJIDATA.TXT NAMESLIST.TXT`\n");
        exit(1);
    }

    $emoji = [];
    $modbase = [];
    foreach (explode("\n", file_get_contents($args[0])) as $line) {
        if (preg_match('/\A([0-9A-F]+)(\.\.[0-9A-F]+|)\s.*(Emoji\S*)/', $line, $m)) {
            $i = hexdec($m[1]);
            $j = $m[2] === "" ? $i : hexdec(substr($m[2], 2));
            for (; $i <= $j; ++$i) {
                if ($m[3] === "Emoji") {
                    $emoji[$i] = $codes[$i] ?? false;
                } else if ($m[3] === "Emoji_Presentation") {
                    $emoji[$i] = true;
                } else if ($m[3] === "Emoji_Modifier_Base") {
                    $modbase[$i] = true;
                }
            }
        }
    }

    $l = [];
    foreach (explode("\n", file_get_contents($args[1])) as $line) {
        if (preg_match('/\A([0-9A-F]+)\s*(.*)/', $line, $m)) {
            $i = hexdec($m[1]);
            if (isset($emoji[$i])) {
                $ch = UnicodeHelper::utf8_chr($i);
                if (!isset($codes[$ch])) {
                    $l[] = "\"" . str_replace(" ", "_", strtolower($m[2])) . "\":\""
                            . $ch . ($emoji[$i] ? "" : "\xEF\xB8\x8F") . "\"";
                    if (isset($modbase[$i])) {
                        $l[] = "\"man_" . str_replace(" ", "_", strtolower($m[2])) . "\":\""
                            . $ch . ($emoji[$i] ? "" : "\xEF\xB8\x8F") . "\xE2\x80\x8D\xE2\x99\x82\"";
                        $l[] = "\"woman_" . str_replace(" ", "_", strtolower($m[2])) . "\":\""
                            . $ch . ($emoji[$i] ? "" : "\xEF\xB8\x8F") . "\xE2\x80\x8D\xE2\x99\x80\"";
                    }
                }
            }
        }
    }

    sort($l);
    fwrite(STDOUT, join(",\n", $l) . "\n");
}

if (isset($arg["c"]) || isset($arg["common"])) {
    list_common_emoji();
} else if (isset($arg["d"]) || isset($arg["dups"])) {
    list_duplicate_codes();
} else if (isset($arg["t"]) || isset($arg["terminators"])) {
    list_terminators();
} else if (isset($arg["m"]) || isset($arg["modifier-bases"])) {
    modifier_base_regex();
} else if (isset($arg["a"]) || isset($arg["absent"])) {
    list_absent(array_slice($argv, $optind));
} else {
    parse_emoji_data_stdin();
}
