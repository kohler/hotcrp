<?php
// cleanhtml.php -- HTML cleaner for CSS prevention
// Copyright (c) 2006-2024 Eddie Kohler; see LICENSE.

class CleanHTMLTag {
    /** @var string */
    public $tag;
    /** @var int */
    public $pos1;
    /** @var int */
    public $pos2;
    /** @var int */
    public $tagfl;
    /** @var ?CleanHTMLTag */
    public $next;

    /** @param string $tag
     * @param int $pos1
     * @param int $pos2
     * @param int $tagfl
     * @param ?CleanHTMLTag $next */
    function __construct($tag, $pos1, $pos2, $tagfl, $next) {
        $this->tag = $tag;
        $this->pos1 = $pos1;
        $this->pos2 = $pos2;
        $this->tagfl = $tagfl;
        $this->next = $next;
    }
}

class CleanHTML {
    const CLEAN_INLINE = 1;
    const CLEAN_STRIP_UNKNOWN = 2;
    const CLEAN_IGNORE_UNKNOWN = 4;

    /** @var int */
    private $flags;
    /** @var ?array<string,int> */
    private $taginfo;
    /** @var list<MessageItem> */
    private $ml = [];
    /** @var string */
    private $context;
    /** @var ?CleanHTMLTag */
    private $opentags;

    /** @var CleanHTML */
    static private $main;

    const F_DISABLED = 0x1;
    const F_BLOCK = 0x2;
    const F_VOID = 0x4;
    const F_NOTEXT = 0x8;
    const F_SPECIAL = 0x10;
    const F_FORMAT = 0x20;
    const F_DEFAULT_SCOPE = 0x40;
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
    static private $base_taginfo = [
        "a" => self::F_FORMAT,
        "abbr" => 0,
        "acronym" => 0,
        "address" => self::F_SPECIAL,
        "applet" => self::F_DISABLED | self::F_DEFAULT_SCOPE,
        // area: self::F_SPECIAL
        // article: self::F_SPECIAL
        // aside: self::F_SPECIAL
        "audio" => self::F_BLOCK | (self::FT_MEDIA << self::FSS),
        "b" => self::F_FORMAT,
        // base: self::F_SPECIAL
        // basefont: self::F_SPECIAL
        "bdi" => 0,
        "bdo" => 0,
        "big" => self::F_FORMAT,
        // bgsound: self::F_SPECIAL
        "blockquote" => self::F_BLOCK | self::F_SPECIAL,
        // body: self::F_SPECIAL
        "br" => self::F_VOID | self::F_SPECIAL,
        // button: self::F_SPECIAL
        // canvas
        "caption" => self::F_BLOCK | self::F_SPECIAL | self::F_DEFAULT_SCOPE | (self::FT_TABLE << self::FSP),
        "center" => self::F_BLOCK | self::F_SPECIAL,
        "cite" => 0,
        "code" => self::F_FORMAT,
        "col" => self::F_VOID | self::F_SPECIAL | (self::FT_COLGROUP << self::FSP),
        "colgroup" => self::F_SPECIAL | (self::FT_COLGROUP << self::FSS) | (self::FT_TABLE << self::FSP),
        // data
        // datalist
        "dd" => self::F_BLOCK | self::F_SPECIAL | (self::FT_DL << self::FSP),
        "del" => 0,
        "details" => self::F_BLOCK | self::F_SPECIAL | (self::FT_DETAILS << self::FSS),
        "dfn" => 0,
        "dialog" => self::F_DISABLED | self::F_SPECIAL,
        "div" => self::F_BLOCK | self::F_SPECIAL,
        "dl" => self::F_BLOCK | self::F_SPECIAL | self::F_NOTEXT | (self::FT_DL << self::FSS),
        "dt" => self::F_BLOCK | self::F_SPECIAL | (self::FT_DL << self::FSP),
        "em" => self::F_FORMAT,
        // embed: self::F_SPECIAL
        "fieldset" => self::F_BLOCK | self::F_SPECIAL | (self::FT_FIELDSET << self::FSS),
        "figcaption" => self::F_BLOCK | self::F_SPECIAL | (self::FT_FIGURE << self::FSP),
        "figure" => self::F_BLOCK | self::F_SPECIAL | (self::FT_FIGURE << self::FSS),
        // font: self::F_FORMAT
        // footer: self::F_SPECIAL
        // form: self::F_SPECIAL
        // frame: self::F_SPECIAL
        // frameset: self::F_SPECIAL
        "h1" => self::F_BLOCK | self::F_SPECIAL,
        "h2" => self::F_BLOCK | self::F_SPECIAL,
        "h3" => self::F_BLOCK | self::F_SPECIAL,
        "h4" => self::F_BLOCK | self::F_SPECIAL,
        "h5" => self::F_BLOCK | self::F_SPECIAL,
        "h6" => self::F_BLOCK | self::F_SPECIAL,
        // head: self::F_SPECIAL
        // header: self::F_SPECIAL
        // hgroup: self::F_SPECIAL
        "hr" => self::F_BLOCK | self::F_SPECIAL | self::F_VOID,
        "html" => self::F_DISABLED | self::F_SPECIAL | self::F_DEFAULT_SCOPE,
        "i" => self::F_FORMAT,
        // iframe: self::F_SPECIAL
        // image: self::F_SPECIAL
        "img" => self::F_VOID | self::F_SPECIAL,
        // input: self::F_SPECIAL
        "ins" => 0,
        "kbd" => 0,
        // keygen: self::F_SPECIAL
        "label" => 0,
        "legend" => self::F_BLOCK | (self::FT_FIELDSET << self::FSP),
        "li" => self::F_BLOCK | self::F_SPECIAL | (self::FT_LIST << self::FSP),
        // link: self::F_SPECIAL
        // main: self::F_SPECIAL
        // map
        "mark" => 0,
        "marquee" => self::F_DISABLED | self::F_SPECIAL | self::F_DEFAULT_SCOPE,
        "menu" => self::F_BLOCK | self::F_SPECIAL | (self::FT_LIST << self::FSS),
        // menuitem
        // meta: self::F_SPECIAL
        "meter" => 0,
        // nav: self::F_SPECIAL
        // nobr: self::F_FORMAT
        // noembed: self::F_SPECIAL
        // noframes: self::F_SPECIAL
        "noscript" => self::F_SPECIAL,
        "object" => self::F_DISABLED | self::F_SPECIAL | self::F_DEFAULT_SCOPE,
        "ol" => self::F_BLOCK | self::F_SPECIAL | self::F_NOTEXT | (self::FT_LIST << self::FSS),
        // optgroup
        // option
        "p" => self::F_BLOCK | self::F_SPECIAL,
        // param: self::F_SPECIAL
        "picture" => self::F_BLOCK | (self::FT_MEDIA << self::FSS),
        // plaintext: self::F_SPECIAL
        "pre" => self::F_BLOCK | self::F_SPECIAL,
        "progress" => 0,
        "q" => 0,
        // rb
        "rp" => self::FT_RUBY << self::FSP,
        "rt" => self::FT_RUBY << self::FSP,
        // rtc
        "ruby" => self::FT_RUBY << self::FSS,
        "s" => self::F_FORMAT,
        "samp" => 0,
        // script: self::F_SPECIAL
        // search: self::F_SPECIAL
        // select: self::F_SPECIAL
        // slot
        "small" => self::F_FORMAT,
        "source" => self::F_VOID | self::F_SPECIAL | (self::FT_MEDIA << self::FSP),
        "span" => 0,
        "strike" => self::F_FORMAT,
        "strong" => self::F_FORMAT,
        // style: self::F_SPECIAL
        "sub" => 0,
        "summary" => self::F_BLOCK | self::F_SPECIAL | (self::FT_DETAILS << self::FSP),
        "sup" => 0,
        "table" => self::F_BLOCK | self::F_SPECIAL | self::F_NOTEXT | self::F_DEFAULT_SCOPE | (self::FT_TABLE << self::FSS),
        "tbody" => self::F_BLOCK | self::F_SPECIAL | self::F_NOTEXT | (self::FT_TROWS << self::FSS) | (self::FT_TABLE << self::FSP),
        "td" => self::F_BLOCK | self::F_SPECIAL | self::F_DEFAULT_SCOPE | (self::FT_TR << self::FSP),
        "template" => self::F_DISABLED | self::F_SPECIAL | self::F_DEFAULT_SCOPE,
        // textarea: self::F_SPECIAL
        "tfoot" => self::F_BLOCK | self::F_SPECIAL | self::F_NOTEXT | (self::FT_TROWS << self::FSS) | (self::FT_TABLE << self::FSP),
        "th" => self::F_BLOCK | self::F_SPECIAL | self::F_DEFAULT_SCOPE | (self::FT_TR << self::FSP),
        "thead" => self::F_BLOCK | self::F_SPECIAL | self::F_NOTEXT | (self::FT_TROWS << self::FSS) | (self::FT_TABLE << self::FSP),
        "time" => 0,
        // title: self::F_SPECIAL
        "tr" => self::F_BLOCK | self::F_SPECIAL | self::F_NOTEXT | (self::FT_TR << self::FSS) | (self::FT_TROWS << self::FSP) | (self::FT_TABLE << self::FSP2),
        "track" => self::F_BLOCK | self::F_SPECIAL | (self::FT_MEDIA << self::FSP),
        "tt" => self::F_FORMAT,
        "u" => self::F_FORMAT,
        "ul" => self::F_BLOCK | self::F_SPECIAL | self::F_NOTEXT | (self::FT_LIST << self::FSS),
        "var" => 0,
        "video" => self::F_BLOCK | (self::FT_MEDIA << self::FSS),
        "wbr" => self::F_VOID | self::F_SPECIAL
        // xmp: self::F_SPECIAL
    ];
    // XXX SVG
    // XXX MathML

    /** @param int $flags */
    function __construct($flags = 0) {
        $this->flags = $flags;
        $this->taginfo = self::$base_taginfo;
    }

    /** @return $this */
    function disable_all() {
        foreach ($this->taginfo as &$tf) {
            $tf |= self::F_DISABLED;
        }
        return $this;
    }

    /** @param string ...$tags
     * @return $this */
    function enable(...$tags) {
        foreach ($tags as $tag) {
            $this->taginfo[$tag] = ($this->taginfo[$tag] ?? self::F_DISABLED) & ~self::F_DISABLED;
        }
        return $this;
    }

    /** @param string $tag
     * @param int $flag
     * @return $this */
    function define($tag, $flag) {
        $this->taginfo[$tag] = $flag;
        return $this;
    }

    /** @return MessageItem */
    private function lerror($str, $pos1, $pos2) {
        $mi = MessageItem::error($str);
        $mi->pos1 = $pos1;
        $mi->pos2 = $pos2;
        $mi->context = $this->context;
        // if (strpos($context, "\n") !== false) {
        //     $mi->landmark = "line " . (preg_match_all('/\r\n?|\n/', substr($context, 0, $pos1)) + 1);
        // }
        $this->ml[] = $mi;
        return $mi;
    }

    /** @return MessageItem */
    private function inclusion_context($tag, $tagtf) {
        $tp1 = ($tagtf >> self::FSP) & self::FTM;
        $tp2 = ($tagtf >> self::FSP2) & self::FTM;
        $tlist = [];
        foreach ($this->taginfo as $n => $tf) {
            if (($t = ($tf >> self::FSS) & self::FTM) !== 0
                && ($t === $tp1 || $t === $tp2)) {
                $tlist[] = "<{$n}>";
            }
        }
        sort($tlist);
        return MessageItem::inform("<0>The <{$tag}> tag can only appear inside " . commajoin($tlist) . ".");
    }

    /** @return string */
    private function here() {
        if ($this->opentags) {
            return "inside <{$this->opentags->tag}>";
        } else {
            return "here";
        }
    }

    private function check_text($curtf, $pos1, $pos2) {
        if (($curtf & self::F_NOTEXT) !== 0
            && $pos1 !== $pos2
            && !ctype_space(substr($this->context, $pos1, $pos2 - $pos1))) {
            $this->lerror("<0>Text not allowed " . $this->here(), $pos1, $pos2);
        }
    }

    /** @param string $comment
     * @param int $pos
     * @return bool */
    private function check_comment($comment, $pos) {
        if (str_starts_with($comment, "<!-->")) {
            $this->lerror("<0>Incorrectly closed HTML comment", $pos, $pos + 5);
            return false;
        } else if (str_starts_with($comment, "<!--->")) {
            $this->lerror("<0>Incorrectly closed HTML comment", $pos, $pos + 6);
            return false;
        } else if (str_ends_with($comment, "<!--->")) {
            $this->lerror("<0>Incorrectly closed HTML comment", $pos, $pos + strlen($comment));
            return false;
        } else if (($xp = strpos($comment, "<!--", 4)) !== false
                   && $xp + 5 !== strlen($comment)) {
            $this->lerror("<0>HTML comments may not be nested", $pos + $xp, $pos + $xp + 4);
            return false;
        } else if (($xp = strpos($comment, "--!>", 4)) !== false) {
            $this->lerror("<0>Incorrectly closed HTML comment", $pos, $pos + $xp + 4);
            return false;
        } else if (!str_ends_with($comment, "-->")) {
            $this->lerror("<0>Unclosed HTML comment", $pos, $pos + strlen($comment));
            return false;
        } else {
            return true;
        }
    }

    /** @return false */
    private function fail() {
        $this->context = $this->opentags = null;
        return false;
    }

    /** @param string $t
     * @return string|false */
    function clean($t) {
        if (($this->flags & self::CLEAN_INLINE) !== 0) {
            $curtf = 0;
        } else {
            $curtf = self::F_BLOCK;
        }
        $this->ml = [];
        $this->context = $t;

        $xp = $p = 0;
        $len = strlen($t);
        $x = "";
        while ($p !== $len && ($nextp = strpos($t, "<", $p)) !== false) {
            if (($curtf & self::F_NOTEXT) !== 0) {
                $this->check_text($curtf, $p, $nextp);
            }
            $p = $nextp;
            if ($p + 1 < $len && $t[$p + 1] === "!") {
                if (preg_match('/\G<!\[CDATA\[(.*?)(\]\]>|\z)/s', $t, $m, 0, $p)) {
                    if ($m[2] === "") {
                        $this->lerror("<0>Unclosed CDATA section", $p, $p + strlen($m[0]));
                        return $this->fail();
                    }
                    $this->check_text($curtf, $p, $p + strlen($m[0]));
                    $x .= substr($t, $xp, $p - $xp) . htmlspecialchars($m[1]);
                    $p = $xp = $p + strlen($m[0]);
                } else if (preg_match('/\G<!--.*?(-->|\z)/s', $t, $m, 0, $p)) {
                    if (!$this->check_comment($m[0], $p)) {
                        return $this->fail();
                    }
                    $x .= substr($t, $xp, $p - $xp);
                    $p = $xp = $p + strlen($m[0]);
                } else {
                    preg_match('/\G<!\s*(\S+)/s', $t, $m, 0, $p);
                    if (str_starts_with(strtolower($m[1]), "doctype")) {
                        $this->lerror("<0>HTML DOCTYPE declarations not allowed", $p, $p + strlen($m[0]));
                    } else if (str_starts_with(strtolower($m[1]), "[i")
                               || str_starts_with(strtolower($m[1]), "[e")) {
                        $this->lerror("<0>Conditional HTML comments not allowed", $p, $p + strlen($m[0]));
                    } else {
                        $this->lerror("<0>Incorrectly opened HTML comment", $p, $p + strlen($m[0]));
                    }
                    return $this->fail();
                }
            } else if (preg_match('/\G<(\s*+)([A-Za-z][-A-Za-z0-9]*+)(?=[\s\/>])(\s*+)(?:[^<>\'"]+|\'[^\']*\'|"[^"]*")*+>?/s', $t, $m, 0, $p)) {
                $tag = strtolower($m[2]);
                $tagp = $p;
                $endp = $p + strlen($m[0]);
                $tagtf = $this->taginfo[$tag] ?? self::F_DISABLED;
                $x .= substr($t, $xp, $tagp - $xp);
                if (($tagtf & self::F_DISABLED) !== 0) {
                    if (($this->flags & self::CLEAN_STRIP_UNKNOWN) !== 0) {
                        $p = $xp = $endp;
                        continue;
                    } else if (($this->flags & self::CLEAN_IGNORE_UNKNOWN) !== 0) {
                        $this->check_text($curtf, $tagp, $tagp + 1);
                        $x .= "&lt;";
                        $p = $xp = $tagp + 1;
                        continue;
                    }
                    $this->lerror("<0>HTML tag <{$m[2]}> not allowed", $tagp, $endp);
                    $tagtf = self::F_VOID;
                }
                $x .= "<{$tag}";
                if (($tagtf & self::F_BLOCK) !== 0
                    && ($curtf & self::F_BLOCK) === 0) {
                    $this->lerror("<0>Block-level element <{$m[2]}> not allowed " . $this->here(), $tagp, $endp);
                }
                if ($tagtf >= (1 << self::FSP)) {
                    $pt1 = ($tagtf >> self::FSP) & self::FTM;
                    $pt2 = ($tagtf >> self::FSP2) & self::FTM;
                    $curt = ($curtf >> self::FSS) & self::FTM;
                    if ($curt === 0
                        || ($pt1 !== $curt && $pt2 !== $curt)) {
                        $this->lerror("<0>Element not allowed here", $tagp, $endp);
                        $this->ml[] = $this->inclusion_context($tag, $tagtf);
                    }
                }
                $p = $tagp + 1 + strlen($m[1]) + strlen($m[2]) + strlen($m[3]);
                // XXX should sanitize 'id', 'class', 'data-', etc.
                while ($p !== $len && $t[$p] !== "/" && $t[$p] !== ">") {
                    if (!preg_match('/\G([^\s\/<>=\'"]++)\s*+/s', $t, $m, 0, $p)) {
                        $this->lerror("<0>Invalid character in HTML tag attributes", $p, $p);
                        $p = $endp;
                        break;
                    }
                    $ap = $p;
                    $attr = strtolower($m[1]);
                    if ((strlen($attr) > 2 && $attr[0] === "o" && $attr[1] === "n")
                        || $attr === "style"
                        || $attr === "script"
                        || $attr === "id") {
                        $this->lerror("<0>HTML attribute {$m[1]} not allowed", $p, $p + strlen($m[1]));
                    }
                    $x .= " {$attr}";
                    $p += strlen($m[0]);
                    if (preg_match('/\G=\s*+(\'.*?\'|".*?"|\w++)\s*+/s', $t, $m, 0, $p)) {
                        if ($m[1][0] === "'" || $m[1][0] === "\"") {
                            $m[1] = substr($m[1], 1, -1);
                        }
                        $m[1] = html_entity_decode($m[1], ENT_HTML5);
                        if ($attr === "href" && preg_match('/\A\s*javascript\s*:/i', $m[1])) {
                            $this->lerror("<5><code>javascript</code> URLs not allowed", $ap, $p + strlen($m[0]));
                        }
                        $x .= "=\"" . htmlspecialchars($m[1]) . "\"";
                        $p += strlen($m[0]);
                    }
                }
                if ($p === $endp) {
                    if ($endp === $len) {
                        $this->lerror("<0>Unclosed tag", $tagp, $p);
                    }
                    $x .= ">";
                    $xp = $endp - 1;
                } else if ($t[$p] === ">") {
                    $xp = $p;
                } else if (preg_match('/\G\/\s*+>/s', $t, $m, 0, $p)) {
                    if (($tagtf & self::F_VOID) === 0) {
                        $this->lerror("<0>HTML tag <{$tag}> cannot be self-closed", $p, $p);
                    }
                    $xp = $endp - 1;
                } else {
                    $this->lerror("<0>Unexpected character in HTML tag", $p, $p);
                    $x .= ">";
                    $xp = $p - 1;
                }
                $p = $xp + 1;
                if (($tagtf & self::F_VOID) !== 0) {
                    continue;
                }
                $this->opentags = new CleanHTMLTag($tag, $tagp, $endp, $curtf, $this->opentags);
                $curtf = $tagtf;
            } else if (preg_match('/\G<\s*+\/\s*+([A-Za-z][-A-Za-z0-9]*+)\s*+>/s', $t, $m, 0, $p)) {
                $tag = strtolower($m[1]);
                $tagp = $p;
                $endp = $tagp + strlen($m[0]);
                $tagtf = $this->taginfo[$tag] ?? self::F_DISABLED;
                if (($tagtf & self::F_DISABLED) !== 0) {
                    $x .= substr($t, $xp, $tagp - $xp);
                    if (($this->flags & self::CLEAN_IGNORE_UNKNOWN) !== 0) {
                        $this->check_text($curtf, $tagp, $tagp + 1);
                        $x .= "&lt;";
                        $p = $xp = $tagp + 1;
                        continue;
                    }
                    if (empty($this->ml)) {
                        $this->lerror("<0>HTML tag <{$m[1]}> not allowed", $tagp, $endp);
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
                if ($this->opentags && $this->opentags->tag === $tag) {
                    $curtf = $this->opentags->tagfl;
                    $this->opentags = $this->opentags->next;
                    if ($endp !== $tagp + 3 + strlen($tag)
                        || $tag !== $m[1]) {
                        $x .= substr($t, $xp, $p - $xp) . "</{$tag}";
                        $xp = $endp - 1;
                    }
                } else {
                    $this->lerror("<0>HTML close tag does not match open tag", $tagp, $endp);
                    if ($this->opentags) {
                        $this->ml[] = $mi = MessageItem::inform("<0>Open tag was here");
                        $mi->pos1 = $this->opentags->pos1;
                        $mi->pos2 = $this->opentags->pos2;
                        $mi->context = $t;
                    }
                }
                $p = $endp;
            } else {
                $this->check_text($curtf, $p, $p + 1);
                $x .= substr($t, $xp, $p - $xp) . "&lt;";
                $xp = $p = $p + 1;
            }
        }

        if ($xp !== $len) {
            $this->check_text($curtf, $xp, $len);
            $x .= substr($t, $xp);
        }
        if ($this->opentags) {
            $this->lerror("<0>Unclosed tag", $this->opentags->pos1, $this->opentags->pos2);
        }

        $this->context = $this->opentags = null;
        if (!empty($this->ml)) {
            return false;
        }
        return preg_replace('/\r\n?/', "\n", $x);
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

    /** @return string */
    function full_feedback_text() {
        return MessageSet::feedback_text($this->ml);
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
