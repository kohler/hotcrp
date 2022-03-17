<?php
// updateemojicodes.php -- HotCRP maintenance script
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

if (realpath($_SERVER["PHP_SELF"]) === __FILE__) {
    define("HOTCRP_NOINIT", 1);
    require_once(dirname(__DIR__) . "/src/init.php");
    exit(UpdateEmojiCodes_Batch::make_args($argv)->run());
}

class UpdateEmojiCodes_Batch {
    /** @var string */
    public $mode = "parse";
    /** @var list<string> */
    public $args;

    function __construct($arg) {
        $this->mode = isset($arg["common"]) ? "common"
            : (isset($arg["dups"]) ? "dups"
               : (isset($arg["terminators"]) ? "terminators"
                  : (isset($arg["modifier-bases"]) ? "modifier-bases"
                     : (isset($arg["absent"]) ? "absent" : "parse"))));
        if ($this->mode === "absent") {
            if (count($arg["_"])) {
                throw new RuntimeException("Expected arguments `EMOJIDATA.TXT NAMESLIST.TXT`");
            }
            $this->args = $arg["_"];
        }
    }

    /** @return int */
    function run() {
        if ($this->mode === "absent") {
            return $this->list_absent($this->args[0], $this->args[1]);
        } else if ($this->mode === "common") {
            return $this->list_common_emoji();
        } else if ($this->mode === "dups") {
            return $this->list_duplicate_codes();
        } else if ($this->mode === "modifier-bases") {
            return $this->modifier_base_regex();
        } else if ($this->mode === "terminators") {
            return $this->list_terminators();
        } else {
            return $this->parse_emoji_data();
        }
    }

    /** @return int */
    function parse_emoji_data() {
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
                } else {
                    error_log($j);
                }
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
        return 0;
    }

    /** @return int */
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
        return 0;
    }

    /** @return array<string,string> */
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

    /** @return int */
    function list_common_emoji() {
        $emoji = json_decode(file_get_contents(SiteLoader::find("scripts/emojicodes.json")));
        $back = $this->emoji_to_code_set($emoji);

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
        return 0;
    }

    /** @return int */
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
        return 0;
    }

    /** @return int */
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
        return 0;
    }

    /** @param string $emojidata
     * @param string $nameslist
     * @return int */
    function list_absent($emojidata, $nameslist) {
        $emoji = json_decode(file_get_contents(SiteLoader::find("scripts/emojicodes.json")));
        $codes = [];
        foreach ((array) $emoji->emoji as $text) {
            $codes[$text] = true;
            if (str_ends_with($text, "\xEF\xB8\x8F"))
                $codes[substr($text, 0, -3)] = true;
        }

        $emoji = [];
        $modbase = [];
        foreach (explode("\n", file_get_contents($emojidata)) as $line) {
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
        foreach (explode("\n", file_get_contents($nameslist)) as $line) {
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
        return 0;
    }

    /** @param list<string> $argv
     * @return UpdateEmojiCodes_Batch */
    static function make_args($argv) {
        $arg = (new Getopt)->long(
            "absent,a",
            "common,c",
            "dups,d",
            "terminators,t",
            "modifier-bases,m",
            "help,h !"
        )->description("Update HotCRP emojicodes.json.
Usage: php batch/updateemojicodes.php [-a | -c | -d | -m | -t]")
         ->helpopt("help")
         ->parse($argv);

        return new UpdateEmojiCodes_Batch($arg);
    }
}
