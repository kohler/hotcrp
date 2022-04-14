<?php
// updateemojicodes.php -- HotCRP maintenance script
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

if (realpath($_SERVER["PHP_SELF"]) === __FILE__) {
    require_once(dirname(__DIR__) . "/src/init.php");
    exit(UpdateEmojiCodes_Batch::make_args($argv)->run());
}

class UpdateEmojiCodes_Batch {
    /** @var string */
    public $mode = "parse";
    /** @var list<string> */
    public $args;
    /** @var array<int,int> */
    public $emojiprop;

    const EMOJI = 1;
    const EPRES = 2;
    const EMODBASE = 4;
    const EMOD = 8;
    const ECOMP = 16;
    const ERI = 32;

    function __construct($arg) {
        $this->mode = isset($arg["common"]) ? "common"
            : (isset($arg["dups"]) ? "dups"
               : (isset($arg["terminators"]) ? "terminators"
                  : (isset($arg["modifier-bases"]) ? "modifier-bases"
                     : (isset($arg["absent"]) ? "absent"
                        : (isset($arg["regex"]) ? "regex" : "parse")))));
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
        } else if ($this->mode === "regex") {
            return $this->print_regex();
        } else {
            return $this->parse_emoji_codes();
        }
    }

    /** @return int */
    function parse_emoji_codes() {
        $x = [];
        if (posix_isatty(STDIN)) {
            $context = stream_context_create([
                "http" => [
                    "protocol_version" => 1.1
                ]
            ]);
            $file = fopen("https://api.github.com/emojis", "rb");
        } else {
            $file = STDIN;
        }
        foreach (json_decode(stream_get_contents($file)) as $i => $j) {
            if (is_string($j)) {
                if (preg_match('/emoji\/unicode\/([\da-f]{4,6}(?:-[\da-f]{4,6})*)\./i', $j, $m)) {
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
        if ($file !== STDIN) {
            fclose($file);
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

    /** @param string $fn */
    function parse_emoji_data($fn) {
        if ($fn === "" || $fn === "-") {
            $f = STDIN;
        } else {
            $f = @fopen("file://{$fn}", "rb");
            if (!$f) {
                throw error_get_last_as_exception("{$fn}: ");
            }
        }
        $s = stream_get_contents($f);
        if ($f !== STDIN) {
            fclose($f);
        }
        if ($s === false
            || !preg_match('/\A\# emoji-data/', $s)) {
            throw new RuntimeException("input should be emoji-data.txt");
        }

        $this->emojiprop = [];
        foreach (explode("\n", $s) as $line) {
            if (preg_match('/\A([0-9A-F]+)(\.\.[0-9A-F]+|)\s.*(Emoji\S*)/', $line, $m)) {
                $i = hexdec($m[1]);
                $j = $m[2] === "" ? $i : hexdec(substr($m[2], 2));
                if ($m[3] === "Emoji") {
                    $fl = self::EMOJI;
                } else if ($m[3] === "Emoji_Presentation") {
                    $fl = self::EPRES;
                } else if ($m[3] === "Emoji_Modifier") {
                    $fl = self::EMOD;
                } else if ($m[3] === "Emoji_Modifier_Base") {
                    $fl = self::EMODBASE;
                } else if ($m[3] === "Emoji_Component") {
                    $fl = self::ECOMP;
                } else {
                    $fl = 0;
                }
                for (; $i <= $j; ++$i) {
                    $this->emojiprop[$i] = ($this->emojiprop[$i] ?? 0) | $fl;
                    if ($i >= 0x1F1E6 && $i <= 0x1F1FF) {
                        $this->emojiprop[$i] |= self::ERI;
                    }
                }
            }
        }
        ksort($this->emojiprop, SORT_NUMERIC);
    }

    /** @param list<int> $ch
     * @return string */
    static function ch_list_to_utf16_regex($ch) {
        $f1 = [];
        sort($ch);
        $n = count($ch);
        for ($i = 0; $i !== $n && $ch[$i] <= 0xFFFF; $i = $j) {
            for ($j = $i + 1;
                 $j !== $n && $ch[$j] <= 0xFFFF && $ch[$j] === $ch[$i] + ($j - $i);
                 ++$j) {
            }
            if ($j - $i === 1) {
                $f1[] = sprintf("\\u%04x", $ch[$i]);
            } else if ($j - $i === 2) {
                $f1[] = sprintf("\\u%04x\\u%04x", $ch[$i], $ch[$j-1]);
            } else {
                $f1[] = sprintf("\\u%04x-\\u%04x", $ch[$i], $ch[$j-1]);
            }
        }
        $f = empty($f1) ? [] : ["[" . join("", $f1) . "]"];
        for (; $i !== $n; $i = $j) {
            $b1 = ($ch[$i] - 0x10000) >> 10;
            $lo = ($b1 << 10) + 0x10000;
            $hi = $lo + (1 << 10);
            for ($j = $i + 1; $j !== $n && $ch[$j] >= $lo && $ch[$j] < $hi; ++$j) {
            }
            if ($j - $i === 1) {
                $f[] = sprintf("\\u%04x\\u%04x", 0xd800 + $b1, 0xdc00 + ($ch[$i] & 0x3FF));
            } else {
                $m = [sprintf("\\u%04x[", 0xd800 + $b1)];
                for ($k = $i; $k !== $j; $k = $l) {
                    for ($l = $k + 1; $l !== $j && $ch[$l] === $ch[$k] + ($l - $k); ++$l) {
                    }
                    if ($l - $k === 1) {
                        $m[] = sprintf("\\u%04x", 0xdc00 + ($ch[$k] & 0x3FF));
                    } else if ($l - $k === 2) {
                        $m[] = sprintf("\\u%04x\\u%04x", 0xdc00 + ($ch[$k] & 0x3FF), 0xdc00 + ($ch[$l-1] & 0x3FF));
                    } else {
                        $m[] = sprintf("\\u%04x-\\u%04x", 0xdc00 + ($ch[$k] & 0x3FF), 0xdc00 + ($ch[$l-1] & 0x3FF));
                    }
                }
                $m[] = "]";
                $f[] = join("", $m);
            }
        }
        return join("|", $f);
    }

    /** @return int */
    function print_regex() {
        $this->parse_emoji_data("-");

        $regex = [];

        // Regional_Indicator Regional_Indicator
        $ris = [];
        for ($i = 0x1f1e6; $i <= 0x1f1ff; ++$i) {
            $ris[] = $i;
        }
        $ri = self::ch_list_to_utf16_regex($ris);

        // Emoji properties
        $epres = [];
        $enpres = [];
        $emod = [];
        foreach ($this->emojiprop as $c => $p) {
            if (($p & self::EPRES) !== 0) {
                $epres[] = $c;
            } else if (($p & self::EMOJI) !== 0) {
                $enpres[] = $c;
            }
            if (($p & self::EMOD) !== 0) {
                $emod[] = $c;
            }
        }

        $etag = [];
        for ($i = 0xe0020; $i <= 0xe007e; ++$i) {
            $etag[] = $i;
        }

        $eregex = "(?:(?:" . self::ch_list_to_utf16_regex($epres) . ")\\ufe0f?|"
            . "(?:" . self::ch_list_to_utf16_regex($enpres) . ")\\ufe0f)"
            . "\\u20e3?"
            . "(?:" . self::ch_list_to_utf16_regex($emod)
            . "|(?:" . self::ch_list_to_utf16_regex($etag) . ")+"
            . self::ch_list_to_utf16_regex([0xe007f]) . ")?";

        fwrite(STDOUT, "(?:{$ri}{$ri}|{$eregex}(?:\\u200d{$eregex})*)\n");
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
        $this->parse_emoji_data("-");
        $x = [];
        foreach ($this->emojiprop as $c => $p) {
            if (($p & self::EMODBASE) !== 0)
                $x[] = $c;
        }
        fwrite(STDOUT, "(?:" . self::ch_list_to_utf16_regex($x) . ")\n");
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

        $this->parse_emoji_data($emojidata);

        $l = [];
        foreach (explode("\n", file_get_contents($nameslist)) as $line) {
            if (preg_match('/\A([0-9A-F]+)\s*(.*)/', $line, $m)) {
                $i = hexdec($m[1]);
                $p = $this->emojiprop[$i] ?? 0;
                if (($p & self::EMOJI) !== 0) {
                    $ch = UnicodeHelper::utf8_chr($i);
                    if (!isset($codes[$ch])) {
                        $l[] = "\"" . str_replace(" ", "_", strtolower($m[2])) . "\":\""
                                . $ch . ($p & self::EPRES ? "" : "\xEF\xB8\x8F") . "\"";
                        if ($p & self::EMODBASE) {
                            $l[] = "\"man_" . str_replace(" ", "_", strtolower($m[2])) . "\":\""
                                . $ch . ($p & self::EPRES ? "" : "\xEF\xB8\x8F") . "\xE2\x80\x8D\xE2\x99\x82\"";
                            $l[] = "\"woman_" . str_replace(" ", "_", strtolower($m[2])) . "\":\""
                                . $ch . ($p & self::EPRES ? "" : "\xEF\xB8\x8F") . "\xE2\x80\x8D\xE2\x99\x80\"";
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
            "regex,r",
            "help,h !"
        )->description("Update HotCRP emojicodes.json.
Usage: php batch/updateemojicodes.php -[acdtmr]")
         ->helpopt("help")
         ->parse($argv);

        return new UpdateEmojiCodes_Batch($arg);
    }
}
