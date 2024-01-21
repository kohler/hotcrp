<?php
// cleanhtml.php -- HTML cleaner for CSS prevention
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class CleanHTML {
    const CLEAN_INLINE = 1;
    const CLEAN_STRIP_UNKNOWN = 2;
    const CLEAN_IGNORE_UNKNOWN = 4;

    /** @var int */
    private $flags;
    /** @var ?array<string,int> */
    private $tags;
    /** @var list<MessageItem> */
    private $ml = [];

    /** @var CleanHTML */
    static private $main;

    const F_BLOCK = 1;
    const F_VOID = 2;
    const F_NOTEXT = 4;
    const F_DISABLED = 8;
    const FSS = 8;
    const FSP = 16; // must be > FSS
    const FSP2 = 24;
    const FTM = 0xFF;

    const FT_COLGROUP = 1;
    const FT_DL = 2;
    const FT_DETAILS = 3;
    const FT_FIELDSET = 4;
    const FT_FIGURE = 5;
    const FT_MEDIA = 6;
    const FT_LIST = 7;
    const FT_RUBY = 8;
    const FT_TABLE = 9;
    const FT_TROWS = 10;
    const FT_TR = 11;

    /** @var array<string,int> */
    static private $taginfo = [
        "a" => 0,
        "abbr" => 0,
        "acronym" => 0,
        "address" => 0,
        // area
        // article
        // aside
        "audio" => self::F_BLOCK | (self::FT_MEDIA << self::FSS),
        "b" => 0,
        // base
        "bdi" => 0,
        "bdo" => 0,
        "big" => 0,
        "blockquote" => self::F_BLOCK,
        // body
        "br" => self::F_VOID,
        // button
        // canvas
        "caption" => self::F_BLOCK | (self::FT_TABLE << self::FSP),
        "center" => self::F_BLOCK,
        "cite" => 0,
        "code" => 0,
        "col" => self::F_VOID | (self::FT_COLGROUP << self::FSP),
        "colgroup" => (self::FT_COLGROUP << self::FSS) | (self::FT_TABLE << self::FSP),
        // data
        // datalist
        "dd" => self::F_BLOCK | (self::FT_DL << self::FSP),
        "del" => 0,
        "details" => self::F_BLOCK | (self::FT_DETAILS << self::FSS),
        "dfn" => 0,
        // dialog
        "div" => self::F_BLOCK,
        "dl" => self::F_BLOCK | self::F_NOTEXT | (self::FT_DL << self::FSS),
        "dt" => self::F_BLOCK | (self::FT_DL << self::FSP),
        "em" => 0,
        // embed
        "fieldset" => self::F_BLOCK | (self::FT_FIELDSET << self::FSS),
        "figcaption" => self::F_BLOCK | (self::FT_FIGURE << self::FSP),
        "figure" => self::F_BLOCK | (self::FT_FIGURE << self::FSS),
        // font
        // footer
        // form
        // frame
        // frameset
        "h1" => self::F_BLOCK,
        "h2" => self::F_BLOCK,
        "h3" => self::F_BLOCK,
        "h4" => self::F_BLOCK,
        "h5" => self::F_BLOCK,
        "h6" => self::F_BLOCK,
        // head
        // header
        // hgroup
        "hr" => self::F_BLOCK | self::F_VOID,
        // html
        "i" => 0,
        // iframe
        // image
        "img" => self::F_VOID,
        // input
        "ins" => 0,
        "kbd" => 0,
        "label" => 0,
        "legend" => self::F_BLOCK | (self::FT_FIELDSET << self::FSP),
        "li" => self::F_BLOCK | (self::FT_LIST << self::FSP),
        // link
        // main
        // map
        "mark" => 0,
        // marquee
        "menu" => self::F_BLOCK | (self::FT_LIST << self::FSS),
        // menuitem
        // meta
        "meter" => 0,
        // nav
        // nobr
        // noembed
        // noframes
        "noscript" => 0,
        // object
        "ol" => self::F_BLOCK | self::F_NOTEXT | (self::FT_LIST << self::FSS),
        // optgroup
        // option
        "p" => self::F_BLOCK,
        // param
        "picture" => self::F_BLOCK | (self::FT_MEDIA << self::FSS),
        // plaintext
        "pre" => self::F_BLOCK,
        "progress" => 0,
        "q" => 0,
        // rb
        "rp" => self::FT_RUBY << self::FSP,
        "rt" => self::FT_RUBY << self::FSP,
        // rtc
        "ruby" => self::FT_RUBY << self::FSS,
        "s" => 0,
        "samp" => 0,
        // script
        // search
        // select
        // slot
        "small" => 0,
        "source" => self::F_VOID | (self::FT_MEDIA << self::FSP),
        "span" => 0,
        "strike" => 0,
        "strong" => 0,
        // style
        "sub" => 0,
        "summary" => self::F_BLOCK | (self::FT_DETAILS << self::FSP),
        "sup" => 0,
        "table" => self::F_BLOCK | self::F_NOTEXT | (self::FT_TABLE << self::FSS),
        "tbody" => self::F_BLOCK | self::F_NOTEXT | (self::FT_TROWS << self::FSS) | (self::FT_TABLE << self::FSP),
        "td" => self::F_BLOCK | (self::FT_TR << self::FSP),
        // template
        // textarea
        "tfoot" => self::F_BLOCK | self::F_NOTEXT | (self::FT_TROWS << self::FSS) | (self::FT_TABLE << self::FSP),
        "th" => self::F_BLOCK | (self::FT_TR << self::FSP),
        "thead" => self::F_BLOCK | self::F_NOTEXT | (self::FT_TROWS << self::FSS) | (self::FT_TABLE << self::FSP),
        "time" => 0,
        // title
        "tr" => self::F_BLOCK | self::F_NOTEXT | (self::FT_TR << self::FSS) | (self::FT_TROWS << self::FSP) | (self::FT_TABLE << self::FSP2),
        "track" => self::F_BLOCK | (self::FT_MEDIA << self::FSP),
        "tt" => 0,
        "u" => 0,
        "ul" => self::F_BLOCK | self::F_NOTEXT | (self::FT_LIST << self::FSS),
        "var" => 0,
        "video" => self::F_BLOCK | (self::FT_MEDIA << self::FSS),
        "wbr" => self::F_VOID
        // xmp
    ];

    /** @param int $flags */
    function __construct($flags = 0) {
        $this->flags = $flags;
        $this->tags = self::$taginfo;
    }

    /** @return $this */
    function disable_all() {
        foreach ($this->tags as &$tf) {
            $tf |= self::F_DISABLED;
        }
        return $this;
    }

    /** @param string ...$tags
     * @return $this */
    function enable(...$tags) {
        foreach ($tags as $tag) {
            $this->tags[$tag] = ($this->tags[$tag] ?? 0) & ~self::F_DISABLED;
        }
        return $this;
    }

    /** @param string $tag
     * @param int $flag
     * @return $this */
    function define($tag, $flag) {
        $this->tags[$tag] = $flag;
        return $this;
    }

    /** @return MessageItem */
    private function e($str, $pos1, $pos2, $context) {
        $mi = MessageItem::error($str);
        $mi->pos1 = $pos1;
        $mi->pos2 = $pos2;
        $mi->context = $context;
        // if (strpos($context, "\n") !== false) {
        //     $mi->landmark = "line " . (preg_match_all('/\r\n?|\n/', substr($context, 0, $pos1)) + 1);
        // }
        return $mi;
    }

    private function inclusion_context($tag, $tagtf) {
        $tp1 = ($tagtf >> self::FSP) & self::FTM;
        $tp2 = ($tagtf >> self::FSP2) & self::FTM;
        $tlist = [];
        foreach ($this->tags as $n => $tf) {
            if (($t = ($tf >> self::FSS) & self::FTM) !== 0
                && ($t === $tp1 || $t === $tp2)) {
                $tlist[] = "<{$n}>";
            }
        }
        sort($tlist);
        return MessageItem::inform("<0>The <{$tag}> tag can only appear inside " . commajoin($tlist) . ".");
    }

    private function here($tagstack) {
        $nts = count($tagstack);
        if ($nts === 0) {
            return "here";
        } else {
            return "inside <" . $tagstack[$nts - 4] . ">";
        }
    }

    private function check_text($curtf, $tagstack, $pos1, $pos2, $t) {
        if (($curtf & self::F_NOTEXT) !== 0
            && $pos1 !== $pos2
            && !ctype_space(substr($t, $pos1, $pos2 - $pos1))) {
            $this->ml[] = $this->e("<0>Text not allowed " . $this->here($tagstack), $pos1, $pos2, $t);
        }
    }

    /** @param string $t
     * @return string|false */
    function clean($t) {
        $tagstack = [];
        '@phan-var-force array<int|string> $tagstack';
        if (($this->flags & self::CLEAN_INLINE) !== 0) {
            $curtf = 0;
        } else {
            $curtf = self::F_BLOCK;
        }
        $this->ml = [];

        $xp = $p = 0;
        $len = strlen($t);
        $x = "";
        while ($p !== $len && ($nextp = strpos($t, "<", $p)) !== false) {
            if (($curtf & self::F_NOTEXT) !== 0) {
                $this->check_text($curtf, $tagstack, $p, $nextp, $t);
            }
            $p = $nextp;
            if (preg_match('/\G<!\[[ie]\w+/i', $t, $m, 0, $p)) {
                $this->ml[] = $this->e("<0>Conditional HTML comments not allowed", $p, $p + strlen($m[0]), $t);
                return false;
            } else if (preg_match('/\G(<!\[CDATA\[.*?)(\]\]>|\z)/s', $t, $m, 0, $p)) {
                $this->check_text($curtf, $tagstack, $p, $p + strlen($m[0]), $t);
                if ($m[2] === "") {
                    $x .= substr($t, $xp) . "]]>";
                    $p = $xp = $len;
                } else {
                    $p += strlen($m[0]);
                }
            } else if (preg_match('/\G<!--.*?(?:-->|\z)\z/s', $t, $m, 0, $p)) {
                $x .= substr($t, $xp, $p - $xp);
                $p = $xp = $p + strlen($m[0]);
            } else if (preg_match('/\G<!(\S+)/s', $t, $m, 0, $p)) {
                $this->ml[] = $this->e("<0>HTML and XML declarations not allowed", $p, $p + strlen($m[0]), $t);
                return false;
            } else if (preg_match('/\G<(\s*+)([A-Za-z][-A-Za-z0-9]*+)(?=[\s\/>])(\s*+)(?:[^<>\'"]+|\'[^\']*\'|"[^"]*")*+>?/s', $t, $m, 0, $p)) {
                $tag = strtolower($m[2]);
                $tagp = $p;
                $endp = $p + strlen($m[0]);
                $tagtf = $this->tags[$tag] ?? self::F_DISABLED;
                $x .= substr($t, $xp, $tagp - $xp);
                if (($tagtf & self::F_DISABLED) !== 0) {
                    if (($this->flags & self::CLEAN_STRIP_UNKNOWN) !== 0) {
                        $p = $xp = $endp;
                        continue;
                    } else if (($this->flags & self::CLEAN_IGNORE_UNKNOWN) !== 0) {
                        $this->check_text($curtf, $tagstack, $tagp, $tagp + 1, $t);
                        $x .= "&lt;";
                        $p = $xp = $tagp + 1;
                        continue;
                    }
                    $this->ml[] = $this->e("<0>HTML tag <{$m[2]}> not allowed", $tagp, $endp, $t);
                    $tagtf = self::F_VOID;
                }
                $x .= "<{$tag}";
                if (($tagtf & self::F_BLOCK) !== 0
                    && ($curtf & self::F_BLOCK) === 0) {
                    $this->ml[] = $this->e("<0>Block-level element <{$m[2]}> not allowed " . $this->here($tagstack), $tagp, $endp, $t);
                }
                if ($tagtf >= (1 << self::FSP)) {
                    $pt1 = ($tagtf >> self::FSP) & self::FTM;
                    $pt2 = ($tagtf >> self::FSP2) & self::FTM;
                    $curt = ($curtf >> self::FSS) & self::FTM;
                    if ($curt === 0
                        || ($pt1 !== $curt && $pt2 !== $curt)) {
                        $this->ml[] = $this->e("<0>Element not allowed here", $tagp, $endp, $t);
                        $this->ml[] = $this->inclusion_context($tag, $tagtf);
                    }
                }
                $p = $tagp + 1 + strlen($m[1]) + strlen($m[2]) + strlen($m[3]);
                // XXX should sanitize 'id', 'class', 'data-', etc.
                while ($p !== $len && $t[$p] !== "/" && $t[$p] !== ">") {
                    if (!preg_match('/\G([^\s\/<>=\'"]++)\s*+/s', $t, $m, 0, $p)) {
                        $this->ml[] = $this->e("<0>Invalid character in HTML tag attributes", $p, $p, $t);
                        $p = $endp;
                        break;
                    }
                    $ap = $p;
                    $attr = strtolower($m[1]);
                    if ((strlen($attr) > 2 && $attr[0] === "o" && $attr[1] === "n")
                        || $attr === "style"
                        || $attr === "script"
                        || $attr === "id") {
                        $this->ml[] = $this->e("<0>HTML attribute {$m[1]} not allowed", $p, $p + strlen($m[1]), $t);
                    }
                    $x .= " {$attr}";
                    $p += strlen($m[0]);
                    if (preg_match('/\G=\s*+(\'.*?\'|".*?"|\w++)\s*+/s', $t, $m, 0, $p)) {
                        if ($m[1][0] === "'" || $m[1][0] === "\"") {
                            $m[1] = substr($m[1], 1, -1);
                        }
                        $m[1] = html_entity_decode($m[1], ENT_HTML5);
                        if ($attr === "href" && preg_match('/\A\s*javascript\s*:/i', $m[1])) {
                            $this->ml[] = $this->e("<5><code>javascript</code> URLs not allowed", $ap, $p + strlen($m[0]), $t);
                        }
                        $x .= "=\"" . htmlspecialchars($m[1]) . "\"";
                        $p += strlen($m[0]);
                    }
                }
                if ($p === $endp) {
                    if ($endp === $len) {
                        $this->ml[] = $this->e("<0>Unclosed HTML tag", $tagp, $p, $t);
                    }
                    $x .= ">";
                    $xp = $endp - 1;
                } else if ($t[$p] === ">") {
                    $xp = $p;
                } else if (preg_match('/\G\/\s*>/s', $t, $m, 0, $p)) {
                    $xp = $endp - 1;
                } else {
                    $this->ml[] = $this->e("<0>Unexpected character in HTML tag", $p, $p, $t);
                    $x .= ">";
                    $xp = $p - 1;
                }
                $p = $xp + 1;
                if (($tagtf & self::F_VOID) !== 0) {
                    continue;
                }
                array_push($tagstack, $tag, $tagp, $endp, $curtf);
                $curtf = $tagtf;
            } else if (preg_match('/\G<\s*\/\s*([A-Za-z0-9]+)\s*>/s', $t, $m, 0, $p)) {
                $tag = strtolower($m[1]);
                $tagp = $p;
                $endp = $tagp + strlen($m[0]);
                $tagtf = $this->tags[$tag] ?? self::F_DISABLED;
                if (($tagtf & self::F_DISABLED) !== 0) {
                    $x .= substr($t, $xp, $tagp - $xp);
                    if (($this->flags & self::CLEAN_IGNORE_UNKNOWN) !== 0) {
                        $this->check_text($curtf, $tagstack, $tagp, $tagp + 1, $t);
                        $x .= "&lt;";
                        $p = $xp = $tagp + 1;
                        continue;
                    }
                    if (empty($this->ml)) {
                        $this->e("<0>HTML tag <{$m[1]}> not allowed", $tagp, $endp, $t);
                    }
                    $p = $xp = $endp;
                    continue;
                }
                if (($tagtf & self::F_VOID) !== 0) {
                    // ignore close tags for void elements
                    $x .= substr($t, $xp, $p - $xp);
                    $xp = $p = $endp;
                    continue;
                }
                $nts = count($tagstack);
                if ($nts > 0 && $tagstack[$nts - 4] === $tag) {
                    $curtf = $tagstack[$nts - 1];
                    array_splice($tagstack, $nts - 4);
                    if ($endp !== $tagp + 3 + strlen($tag)
                        || $tag !== $m[1]) {
                        $x .= substr($t, $xp, $p - $xp) . "</{$tag}";
                        $xp = $endp - 1;
                    }
                } else {
                    $this->ml[] = $this->e("<0>HTML close tag does not match open tag", $tagp, $endp, $t);
                    if ($nts > 0) {
                        $this->ml[] = $mi = MessageItem::inform("<0>Open tag was here");
                        $mi->pos1 = $tagstack[$nts - 3];
                        $mi->pos2 = $tagstack[$nts - 2];
                        $mi->context = $t;
                    }
                }
                $p = $endp;
            } else {
                $this->check_text($curtf, $tagstack, $p, $p + 1, $t);
                $x .= substr($t, $xp, $p - $xp) . "&lt;";
                $xp = $p = $p + 1;
            }
        }

        if ($xp !== $len) {
            $this->check_text($curtf, $tagstack, $xp, $len, $t);
            $x .= substr($t, $xp);
        }
        if (($nts = count($tagstack)) !== 0) {
            $this->ml[] = $this->e("<0>Unclosed HTML tag", $tagstack[$nts - 3], $tagstack[$nts - 2], $t);
        }
        if (empty($this->ml)) {
            return preg_replace('/\r\n?/', "\n", $x);
        } else {
            return false;
        }
    }

    /** @param string|list<string> $t
     * @return list<string>|false */
    function clean_all($t) {
        $x = [];
        foreach (is_array($t) ? $t : [$t] as $s) {
            if (is_string($s)
                && ($s = $this->clean($s)) !== false) {
                $x[] = $s;
            } else {
                return false;
            }
        }
        return $x;
    }

    /** @return list<MessageItem> */
    function message_list() {
        return $this->ml;
    }

    /** @return CleanHTML */
    static function basic() {
        if (!self::$main) {
            self::$main = new CleanHTML;
        }
        return self::$main;
    }

    /** @param string $t
     * @return string|false */
    static function basic_clean($t) {
        return self::basic()->clean($t);
    }

    /** @param string|list<string> $t
     * @return list<string>|false */
    static function basic_clean_all($t) {
        return self::basic()->clean_all($t);
    }
}
