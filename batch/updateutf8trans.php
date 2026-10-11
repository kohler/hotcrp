<?php
// updateutf8trans.php -- HotCRP maintenance script
// Copyright (c) 2006-2026 Eddie Kohler; see LICENSE.

if (realpath($_SERVER["PHP_SELF"]) === __FILE__) {
    require_once(dirname(__DIR__) . "/src/init.php");
    require_once(dirname(__DIR__) . "/lib/unicodehelper.php");
    exit(UpdateUTF8Trans_Batch::make_args($argv)->run());
}

/** Generate the `UTF8_DEACCENT_*` tables used by `UnicodeHelper::deaccent`.
 *
 * The table is a secondary-strength collation folding (UTS #10 §11.1)
 * derived from the Default Unicode Collation Element Table (DUCET,
 * allkeys.txt). Each BMP character outside ASCII maps to the representative
 * characters of its collation elements’ primary weights, so accents and other
 * secondary differences disappear. Characters with no primary weights, such
 * as combining marks and format controls, map to the empty string. Tertiary
 * differences fold to a representative form, except that case is preserved:
 * fullwidth, superscript, and ligature forms fold to plain characters, and
 * uppercase stays uppercase. Hiragana and katakana remain distinct.
 *
 * With `--latin`, the table includes only mappings whose result is ASCII and
 * whose input has script Latin or Common, or has script Inherited and is in
 * a Combining Diacritical Marks block (according to Scripts.txt). These cover
 * accented Latin letters, Latin ligatures, combining diacritical marks, and
 * ASCII-equivalent forms such as fullwidth and superscript characters.
 * Squared CJK compatibility characters, such as `㎒`, are excluded.
 *
 * A mapping is skipped if its result is longer, in UTF-8, than its input,
 * since `UnicodePiecewiseTranslation` maps offsets within a replacement
 * one-to-one. Negated symbols, such as `≠`, are not folded, since DUCET
 * treats their negation overlay (U+0338) as an accent. */
class UpdateUTF8Trans_Batch {
    /** @var bool */
    public $verbose;
    /** @var bool */
    public $unparse;
    /** @var bool */
    public $time;
    /** @var ?string */
    public $file;
    /** @var list<string> */
    public $arg;
    /** @var ?string */
    public $output;
    /** @var bool */
    public $latin;
    /** @var ?string */
    public $scripts_file;

    /** @var array<string,string> */
    public $trans = [];
    /** @var ?string */
    private $ducet_version;
    /** @var array<int,list<array{int,int,int}>> */
    private $ces = [];
    /** @var array<int,string> */
    private $names = [];
    /** @var array<string,int> */
    private $reps = [];
    /** @var ?array<int,true> */
    private $latin_cps;

    /** Combining Diacritical Marks blocks, whose Inherited characters are
     * included by `--latin`. */
    const LATIN_INHERITED_BLOCKS = [
        [0x0300, 0x036F], [0x1AB0, 0x1AFF], [0x1DC0, 0x1DFF],
        [0x20D0, 0x20FF], [0xFE20, 0xFE2F]
    ];

    /** HotCRP-specific mappings applied on top of the DUCET folding. DUCET
     * gives these characters distinct primary weights, but HotCRP treats them
     * as equivalent to ASCII for matching: users commonly type these Latin
     * letters without their strokes or dots, and these dashes as hyphens. */
    const EXTRA_MAPPINGS = [
        0x0131 /* ı */ => "i", 0x0166 /* Ŧ */ => "T", 0x0167 /* ŧ */ => "t",
        0x0180 /* ƀ */ => "b", 0x0181 /* Ɓ */ => "B",
        0x2010 => "-", 0x2011 => "-", 0x2012 => "-", 0x2013 => "-"
    ];

    function __construct($arg) {
        $this->verbose = isset($arg["verbose"]);
        $this->unparse = isset($arg["unparse"]);
        $this->time = isset($arg["time"]);
        $this->file = $arg["file"] ?? null;
        $this->output = $arg["output"] ?? null;
        $this->latin = isset($arg["latin"]);
        $this->scripts_file = $arg["scripts"] ?? null;
        if ($this->latin && $this->scripts_file === null) {
            throw new CommandLineException("`--latin` requires `--scripts`");
        }
        $this->arg = $arg["_"];
    }

    /** Return a PHP string literal for `$s`. Marks, separators, controls,
     * and other invisible characters are written as `\u{...}` escapes.
     * @param string $s
     * @return string */
    static function php_literal($s) {
        $t = "";
        foreach (preg_split('//u', $s, -1, PREG_SPLIT_NO_EMPTY) as $ch) {
            $c = UnicodeHelper::utf8_ord($ch);
            if ($ch === "\\" || $ch === "\"" || $ch === "\$") {
                $t .= "\\{$ch}";
            } else if ($c < 0x20
                       || $c === 0x7F
                       || ($c >= 0x80 && preg_match('/[\pM\pZ\pC]/u', $ch))) {
                $t .= sprintf("\\u{%04X}", $c);
            } else {
                $t .= $ch;
            }
        }
        return "\"{$t}\"";
    }

    /** @param int|string $x
     * @param int $base
     * @return string */
    static function numeric_to_utf8($x, $base = 10) {
        $x = is_int($x) ? $x : intval($x, $base);
        if ($x < 0x110000) {
            return UnicodeHelper::utf8_chr($x);
        } else {
            return "";
        }
    }

    /** @param string $ch
     * @return string */
    private function describe($ch) {
        $c = UnicodeHelper::utf8_ord($ch);
        $name = $this->names[$c] ?? null;
        return sprintf("U+%04X", $c) . ($name ? " {$name}" : "");
    }

    /** @param int|string $sin
     * @param string $sout */
    function assign_it($sin, $sout) {
        if (is_int($sin)) {
            $sin = UnicodeHelper::utf8_chr($sin);
        }
        if ($sin === ""
            || ord($sin[0]) < 0xC0
            || ord($sin[0]) >= 0xF0
            || strlen($sin) !== (ord($sin[0]) < 0xE0 ? 2 : 3)) {
            fwrite(STDERR, "input argument " . $this->describe($sin) . " is bad\n");
        } else if (strlen($sout) > strlen($sin)) {
            fwrite(STDERR, "output argument " . $this->describe($sin) . " => {$sout} too long\n");
        } else if ($sout === $sin) {
            unset($this->trans[$sin]);
        } else {
            if ($this->verbose) {
                fwrite(STDERR, $this->describe($sin) . " => {$sout}\n");
            }
            $this->trans[$sin] = $sout;
        }
    }

    /** @return int */
    function run_time() {
        if ($this->file !== null) {
            if ($this->file === "-") {
                $text = stream_get_contents(STDIN);
            } else {
                $text = file_get_contents($this->file);
            }
            if ($text === false) {
                throw error_get_last_as_exception($this->file);
            }
        } else if (!empty($this->arg)) {
            $text = join(" ", $this->arg) . "\n";
        } else {
            $text = stream_get_contents(STDIN);
        }

        $n = 10000000 / strlen($text);
        $begint = microtime(true);
        $textlet = "";
        for ($i = 0; $i < $n; ++$i) {
            $textlet = UnicodeHelper::deaccent($text);
        }
        $endt = microtime(true);

        if ($this->output !== null) {
            if (str_starts_with($textlet, "\xEF\xBB\xBF")) {
                $textlet = substr($textlet, 3);
            }
            if ($this->output === "-") {
                fwrite(STDOUT, $textlet);
            } else {
                file_put_contents($this->output, $textlet);
            }
        }

        fwrite(STDERR, sprintf("%f Mchars/sec\n", (strlen($text) * $i / 1000000.0) / ($endt - $begint)));
        return 0;
    }


    /** Return the representative tertiary weight for tertiary weight `$t`.
     *
     * Uppercase variants map to 0x08 (uppercase), small and narrow
     * hiragana and katakana map to 0x0E and 0x11 (normal hiragana and
     * katakana), and all other variants map to 0x02 (UTS #10 Table 17).
     * @param int $t
     * @return int */
    static function tertiary_class($t) {
        if (($t >= 0x08 && $t <= 0x0C) || $t === 0x1D) {
            return 0x08;
        } else if ($t === 0x0D || $t === 0x0E) {
            return 0x0E;
        } else if ($t >= 0x0F && $t <= 0x13) {
            return 0x11;
        }
        return 0x02;
    }

    /** @return int */
    function parse_ducet() {
        if ($this->file === "-") {
            $text = stream_get_contents(STDIN);
        } else {
            $text = file_get_contents($this->file);
        }
        if ($text === false) {
            throw error_get_last_as_exception($this->file);
        }
        foreach (explode("\n", $text) as $line) {
            if (str_starts_with($line, "@version ")) {
                $this->ducet_version = trim(substr($line, 9));
            }
            if (!preg_match('/\A([0-9A-F]{4,6})\s*;\s*((?:\[[.*][0-9A-F]{4}\.[0-9A-F]{4}\.[0-9A-F]{4}\]\s*)+)(?:#\s*(.*))?\z/', $line, $m)) {
                continue;
            }
            $c = intval($m[1], 16);
            if ($c > 0xFFFF) {
                continue;
            }
            preg_match_all('/\[[.*]([0-9A-F]{4})\.([0-9A-F]{4})\.([0-9A-F]{4})\]/', $m[2], $mm, PREG_SET_ORDER);
            $ces = [];
            foreach ($mm as $ce) {
                $ces[] = [intval($ce[1], 16), intval($ce[2], 16), intval($ce[3], 16)];
            }
            $this->ces[$c] = $ces;
            $this->names[$c] = trim($m[3] ?? "");
            [$p, $s, $t] = $ces[0];
            if (count($ces) === 1 && $p !== 0 && $s === 0x20) {
                $k = "{$p}.{$t}";
                if (!isset($this->reps[$k]) || $c < $this->reps[$k]) {
                    $this->reps[$k] = $c;
                }
            }
        }
        if ($this->ducet_version === null || empty($this->ces)) {
            throw new CommandLineException("{$this->file}: Not a DUCET allkeys.txt file");
        }
        return 0;
    }

    /** Read Scripts.txt and record the BMP code points with script Latin or
     * Common, or with script Inherited in `LATIN_INHERITED_BLOCKS`. */
    function parse_scripts() {
        $text = file_get_contents($this->scripts_file);
        if ($text === false) {
            throw error_get_last_as_exception($this->scripts_file);
        }
        $this->latin_cps = [];
        preg_match_all('/^([0-9A-F]{4,6})(?:\.\.([0-9A-F]{4,6}))?\s*;\s*(Latin|Common|Inherited)\b/m', $text, $mm, PREG_SET_ORDER);
        foreach ($mm as $m) {
            $hi = min(intval($m[2] !== "" ? $m[2] : $m[1], 16), 0xFFFF);
            for ($c = intval($m[1], 16); $c <= $hi; ++$c) {
                if ($m[3] !== "Inherited" || self::in_latin_inherited_block($c))
                    $this->latin_cps[$c] = true;
            }
        }
        if (empty($this->latin_cps)) {
            throw new CommandLineException("{$this->scripts_file}: Not a Scripts.txt file");
        }
    }

    /** @param int $c
     * @return bool */
    static function in_latin_inherited_block($c) {
        foreach (self::LATIN_INHERITED_BLOCKS as $b) {
            if ($c >= $b[0] && $c <= $b[1])
                return true;
        }
        return false;
    }

    /** Return the folding of code point `$c`, or null if it cannot be folded.
     *
     * If the cased letters in `$c`’s compatibility decomposition are all
     * uppercase or all lowercase, the folding takes that case. DUCET’s
     * tertiary weights sometimes lose case for compatibility characters (e.g.,
     * U+1D2D MODIFIER LETTER CAPITAL AE has the weights of lowercase “ae”).
     * @param int $c
     * @return ?string */
    function fold($c) {
        $out = $this->fold_ces($c);
        $nfkd = Normalizer::normalize(UnicodeHelper::utf8_chr($c), Normalizer::NFKD);
        if ($out === null || $nfkd === false) {
            return $out;
        }
        $upper = $lower = false;
        foreach (preg_split('//u', $nfkd, -1, PREG_SPLIT_NO_EMPTY) as $dch) {
            $upper = $upper || IntlChar::isupper($dch);
            $lower = $lower || IntlChar::islower($dch);
        }
        if ($upper !== $lower) {
            $chs = preg_split('//u', $out, -1, PREG_SPLIT_NO_EMPTY);
            $out = join("", array_map($upper ? "IntlChar::toupper" : "IntlChar::tolower", $chs));
        }
        return $out;
    }

    /** Return the folding of code point `$c` according to its DUCET
     * collation elements, or null if it cannot be folded.
     * @param int $c
     * @return ?string */
    function fold_ces($c) {
        $ces = $this->ces[$c];
        $out = "";
        for ($i = 0; $i !== count($ces); ++$i) {
            [$p, $s, $t] = $ces[$i];
            if ($p === 0) {
                continue;
            }
            // implicit weights for Han characters (UTS #10 §10.1.3)
            if ($p >= 0xFB40 && $p <= 0xFBBF
                && $i + 1 < count($ces)
                && $ces[$i + 1][1] === 0
                && ($ces[$i + 1][0] & 0x8000) !== 0) {
                $base = $p >= 0xFB80 ? 0xFB80 : 0xFB40;
                $out .= UnicodeHelper::utf8_chr((($p - $base) << 15) | ($ces[$i + 1][0] & 0x7FFF));
                ++$i;
                continue;
            }
            $rep = $this->reps["{$p}." . self::tertiary_class($t)]
                ?? $this->reps["{$p}.2"]
                ?? null;
            if ($rep === null) {
                return null;
            }
            $out .= UnicodeHelper::utf8_chr($rep);
        }
        return $out;
    }

    function derive_trans() {
        $this->trans = [];
        $extra = [];
        foreach (self::EXTRA_MAPPINGS as $c => $out) {
            $extra[UnicodeHelper::utf8_chr($c)] = $out;
        }
        ksort($this->ces);
        foreach ($this->ces as $c => $ces) {
            if ($c < 0x80
                || ($c >= 0xD800 && $c <= 0xDFFF)
                || ($this->latin_cps !== null
                    && (!isset($this->latin_cps[$c])
                        || IntlChar::getIntPropertyValue($c, IntlChar::PROPERTY_DECOMPOSITION_TYPE) === IntlChar::DT_SQUARE))) {
                continue;
            }
            $ch = UnicodeHelper::utf8_chr($c);
            $out = $this->fold($c);
            if ($out === null || $out === $ch) {
                continue;
            }
            $out = strtr($out, $extra);
            if (strlen($out) > strlen($ch)
                || ($this->latin_cps !== null && !is_usascii($out))
                || ($c !== 0x338
                    && str_contains(Normalizer::normalize($ch, Normalizer::NFD), "\u{338}"))) {
                if ($this->verbose) {
                    fwrite(STDERR, "skipping " . $this->describe($ch) . " => {$out}\n");
                }
                continue;
            }
            $this->trans[$ch] = $out;
        }
        foreach (self::EXTRA_MAPPINGS as $c => $out) {
            $this->assign_it($c, $out);
        }
    }

    /** @return string */
    function unparse_unicodehelper() {
        ksort($this->trans, SORT_STRING);
        $lead = "";
        foreach ($this->trans as $k => $v) {
            if (strpos($lead, $k[0]) === false)
                $lead .= $k[0];
        }
        $leadx = "";
        foreach (str_split($lead) as $ch) {
            $leadx .= sprintf("\\x%02X", ord($ch));
        }
        $gen = "// Generated by batch/updateutf8trans.php"
            . ($this->ducet_version ? " from DUCET {$this->ducet_version}" : "")
            . ($this->latin ? " (--latin)" : "") . "\n";
        $consts = $gen
            . "// First bytes of UTF8_DEACCENT_MAP keys\n"
            . "const UTF8_DEACCENT_LEAD = \"{$leadx}\";\n\n"
            . "const UTF8_DEACCENT_MAP = [\n";
        foreach ($this->trans as $k => $v) {
            $consts .= "    " . self::php_literal($k) . " => " . self::php_literal($v) . ",\n";
        }
        $consts .= "];\n";

        $uh = file_get_contents(SiteLoader::resolve("lib/unicodehelper.php"));
        if (!preg_match('/^\/\/ Generated by batch\/updateutf8trans\.php.*\n(?:\/\/.*\n)*const UTF8_DEACCENT_LEAD = .*\n\nconst UTF8_DEACCENT_MAP = \[\n.*?^\];\n/ms', $uh, $m, PREG_OFFSET_CAPTURE)) {
            throw new CommandLineException("lib/unicodehelper.php: Translation table not found");
        }
        return substr($uh, 0, $m[0][1]) . $consts . substr($uh, $m[0][1] + strlen($m[0][0]));
    }

    /** @return int */
    function run() {
        if ($this->time) {
            return $this->run_time();
        }

        if ($this->file !== null) {
            $this->parse_ducet();
        }
        if ($this->latin) {
            $this->parse_scripts();
        }
        if ($this->unparse || $this->file === null) {
            $this->trans = UTF8_DEACCENT_MAP;
        }
        if ($this->unparse) {
            ksort($this->trans, SORT_STRING);
            foreach ($this->trans as $k => $v) {
                fwrite(STDOUT, $this->describe($k) . " => {$v}\n");
            }
            return 0;
        }
        if ($this->file !== null) {
            $this->derive_trans();
        }

        for ($i = 0; $i < count($this->arg); $i += 2) {
            $sin = $this->arg[$i];
            $sout = trim($this->arg[$i + 1] ?? "");
            if ($sin !== "" && is_numeric($sin)) {
                $sin = self::numeric_to_utf8($sin, 0);
            }
            if ($sout === "") {
                unset($this->trans[$sin]);
            } else {
                $this->assign_it($sin, $sout === "*" ? "" : $sout);
            }
        }

        $out = $this->unparse_unicodehelper();
        if ($this->output === null || $this->output === "-") {
            fwrite(STDOUT, $out);
        } else {
            file_put_contents($this->output, $out);
        }
        fwrite(STDERR, sprintf("%d mappings\n", count($this->trans)));
        return 0;
    }

    /** @param list<string> $argv
     * @return UpdateUTF8Trans_Batch */
    static function make_args($argv) {
        $arg = (new Getopt)->long(
            "help,h !",
            "file:,f: =FILE Read DUCET allkeys.txt from FILE",
            "time,t Measure deaccent speed",
            "output:,o: =OUTPUTFILE Write output to OUTPUTFILE",
            "latin,l Only include Latin mappings with ASCII results",
            "scripts:,s: =FILE Read Scripts.txt from FILE",
            "unparse,u List current mappings",
            "verbose,V Be verbose"
        )->description("Update or query HotCRP’s built-in UTF8 translations.
Usage: php batch/updateutf8trans.php -f allkeys.txt [--latin -s Scripts.txt]
           [-o OUTPUTFILE] [CODEPOINT STRING]...
       php batch/updateutf8trans.php -u [-f allkeys.txt]
       php batch/updateutf8trans.php -t [STRING...] [-o OUTPUTFILE]

Without -u or -t, prints a new lib/unicodehelper.php. CODEPOINT STRING
arguments override individual mappings; STRING `*` maps the character to
the empty string, and an empty STRING removes its mapping.")
         ->helpopt("help")
         ->parse($argv);

        return new UpdateUTF8Trans_Batch($arg);
    }
}
