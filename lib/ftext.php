<?php
// ftext.php -- formatted text
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class Ftext {
    /** @param ?string $s
     * @return int|false */
    static function is_ftext($s) {
        if ($s !== null
            && ($len = strlen($s)) >= 3
            && $s[0] === "<") {
            $i = 1;
            while ($i !== $len && ctype_digit($s[$i])) {
                ++$i;
            }
            if ($i !== 1 && $i !== $len && $s[$i] === ">") {
                return $i + 1;
            }
        }
        return false;
    }

    /** @param ?string $s
     * @return array{?int,string} */
    static function parse($s) {
        if ($s !== null
            && ($len = strlen($s)) >= 3
            && $s[0] === "<") {
            $i = 1;
            while ($i !== $len && ctype_digit($s[$i])) {
                ++$i;
            }
            if ($i !== 1 && $i !== $len && $s[$i] === ">") {
                return [intval(substr($s, 1, $i - 1)), substr($s, $i + 1)];
            }
        }
        return [null, $s];
    }

    /** @param string $s
     * @param int $default_format
     * @return string */
    static function ensure($s, $default_format) {
        if (self::is_ftext($s) !== false) {
            return $s;
        } else {
            return "<{$default_format}>{$s}";
        }
    }

    /** @param string $ftext
     * @param int $want_format
     * @return string */
    static function unparse_as($ftext, $want_format) {
        list($format, $s) = self::parse($ftext);
        if (($format ?? 0) === 5 && $want_format !== 5) {
            // XXX <p>, <ul>, <ol>
            return html_entity_decode(preg_replace_callback('/<\s*(\/?)\s*(a|b|i|u|strong|em|code|samp|pre|tt|span|br)(?=[>\s]).*?>/i',
                function ($m) {
                    $tag = strtolower($m[2]);
                    if ($tag === "code" || $tag === "samp" || $tag === "tt") {
                        return "\`";
                    } else if ($tag === "pre") {
                        return "\`\`\`\n";
                    } else if ($tag === "b" || $tag === "strong") {
                        return "**";
                    } else if ($tag === "i" || $tag === "em") {
                        return "*";
                    } else if ($tag === "u") {
                        return "_";
                    } else if ($tag === "br") {
                        return "\n";
                    } else {
                        return "";
                    }
                }, $s), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, "UTF-8");
        } else if (($format ?? 5) !== 5 && $want_format === 5) {
            return htmlspecialchars($s);
        } else {
            return $s;
        }
    }

    /** @param string ...$ftexts
     * @return string */
    static function concat(...$ftexts) {
        $parses = [];
        $format = null;
        foreach ($ftexts as $ftext) {
            $parses[] = $parse = self::parse($ftext);
            if ($format === null || $parse[0] === 5) {
                $format = $parse[0];
            }
        }
        $ts = [];
        foreach ($parses as $parse) {
            if ($parse[0] !== 5 && $format === 5) {
                $ts[] = htmlspecialchars($parse[1]);
            } else {
                $ts[] = $parse[1];
            }
        }
        if ($format !== null) {
            return "<{$format}>" . join("", $ts);
        } else {
            return join("", $ts);
        }
    }

    /** @param string $separator
     * @param iterable<string> $ftexts
     * @return string */
    static function join($separator, $ftexts) {
        $nftexts = [];
        foreach ($ftexts as $ftext) {
            if (!empty($nftexts)) {
                $nftexts[] = $separator;
            }
            $nftexts[] = $ftext;
        }
        return self::concat(...$nftexts);
    }
}
