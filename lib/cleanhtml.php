<?php
// cleanhtml.php -- HTML cleaner for CSS prevention
// Copyright (c) 2006-2026 Eddie Kohler; see LICENSE.

class CleanHTMLTag {
    /** @var string */
    public $tag;
    /** @var int */
    public $pos1;
    /** @var int */
    public $pos2;
    /** @var int */
    public $tagfl;
    /** @var string */
    public $opener;
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
    const CLEAN_FIX = 8;

    /** @var int */
    private $flags;
    /** @var ?array<string,int> */
    private $tagflags;
    /** @var list<MessageItem> */
    private $ml = [];
    /** @var string */
    private $context;
    /** @var ?CleanHTMLTag */
    private $opentags;
    /** @var bool */
    private $fixed_by_adoption = false;

    /** @var CleanHTML */
    static private $main;

    // `tagflags` values consist of zero or more flags, or’ed with an optional
    // established scope (some SC_* << self::SCP), or’ed with an optional
    // required scope (either SC_* << self::REQSCP1 or SC_* << self::REQSCP2).

    // Flags
    const F_DISABLED = 0x1;         // This tag is disabled
    const F_BLOCK = 0x2;            // Tag is in block context
    const F_VOID = 0x4;             // Tag is void (self-closing)
    const F_NOTEXT = 0x8;           // Tag may not contain text
    const F_SPECIAL = 0x10;         // Tag has special parsing rules
    const F_FORMAT = 0x20;          // Formatting elements
    const F_MARKER = 0x40;          // Acts as marker
    const F_DEFAULT_SCOPE = 0x80;   // “Has a particular element in scope” tag
    const F_ENDOPTIONAL = 0x100;    // End tag is optional per HTML spec
    const F_CLOSEP = 0x200;         // Open tag should close an open <p>

    // Scope values
    const SC_COLGROUP = 1;
    const SC_DL = 2;
    const SC_DETAILS = 3;
    const SC_FIELDSET = 4;
    const SC_FIGURE = 5;
    const SC_MEDIA = 6;
    const SC_LIST = 7;
    const SC_RUBY = 8;
    const SC_TABLE = 9;
    const SC_TROWS = 10;
    const SC_TR = 11;
    const SC_INVALID = 31;
    const SCMASK = 0x1F;

    // Scope bitshifts
    const SCP = 16;                 // Bitshift for scope established by tag
    const REQSCP1 = 21;             // Bitshift for scope required by tag
    const REQSCP2 = 26;             // Bitshift for scope required by tag

    /** @var array<string,int> */
    static private $base_tagflags = [
        "a" => self::F_FORMAT,
        "abbr" => 0,
        "acronym" => 0,
        "address" => self::F_SPECIAL | self::F_CLOSEP,
        "applet" => self::F_DISABLED | self::F_SPECIAL | self::F_MARKER | self::F_DEFAULT_SCOPE,
        // area: self::F_SPECIAL
        // article: self::F_SPECIAL | self::F_CLOSEP
        // aside: self::F_SPECIAL | self::F_CLOSEP
        "audio" => self::F_BLOCK | (self::SC_MEDIA << self::SCP),
        "b" => self::F_FORMAT,
        // base: self::F_SPECIAL
        // basefont: self::F_SPECIAL
        "bdi" => 0,
        "bdo" => 0,
        "big" => self::F_FORMAT,
        // bgsound: self::F_SPECIAL
        "blockquote" => self::F_BLOCK | self::F_SPECIAL | self::F_CLOSEP,
        // body: self::F_SPECIAL
        "br" => self::F_VOID | self::F_SPECIAL,
        // button: self::F_SPECIAL
        // canvas
        "caption" => self::F_BLOCK | self::F_SPECIAL | self::F_MARKER | self::F_DEFAULT_SCOPE | (self::SC_TABLE << self::REQSCP1),
        "center" => self::F_BLOCK | self::F_SPECIAL | self::F_CLOSEP,
        "cite" => 0,
        "code" => self::F_FORMAT,
        "col" => self::F_VOID | self::F_SPECIAL | (self::SC_COLGROUP << self::REQSCP1),
        "colgroup" => self::F_SPECIAL | self::F_ENDOPTIONAL | (self::SC_COLGROUP << self::SCP) | (self::SC_TABLE << self::REQSCP1),
        // data
        // datalist
        "dd" => self::F_BLOCK | self::F_SPECIAL | self::F_ENDOPTIONAL | self::F_CLOSEP | (self::SC_DL << self::REQSCP1),
        "del" => 0,
        "details" => self::F_BLOCK | self::F_SPECIAL | self::F_CLOSEP | (self::SC_DETAILS << self::SCP),
        "dfn" => 0,
        "dialog" => self::F_DISABLED | self::F_CLOSEP,
        // dir: self::F_SPECIAL | self::F_CLOSEP
        "div" => self::F_BLOCK | self::F_SPECIAL | self::F_CLOSEP,
        "dl" => self::F_BLOCK | self::F_SPECIAL | self::F_NOTEXT | self::F_CLOSEP | (self::SC_DL << self::SCP),
        "dt" => self::F_BLOCK | self::F_SPECIAL | self::F_ENDOPTIONAL | self::F_CLOSEP | (self::SC_DL << self::REQSCP1),
        "em" => self::F_FORMAT,
        // embed: self::F_SPECIAL
        "fieldset" => self::F_BLOCK | self::F_SPECIAL | self::F_CLOSEP | (self::SC_FIELDSET << self::SCP),
        "figcaption" => self::F_BLOCK | self::F_SPECIAL | self::F_CLOSEP | (self::SC_FIGURE << self::REQSCP1),
        "figure" => self::F_BLOCK | self::F_SPECIAL | self::F_CLOSEP | (self::SC_FIGURE << self::SCP),
        // font: self::F_FORMAT
        // footer: self::F_SPECIAL | self::F_CLOSEP
        // form: self::F_SPECIAL | self::F_CLOSEP
        // frame: self::F_SPECIAL
        // frameset: self::F_SPECIAL
        "h1" => self::F_BLOCK | self::F_SPECIAL | self::F_CLOSEP,
        "h2" => self::F_BLOCK | self::F_SPECIAL | self::F_CLOSEP,
        "h3" => self::F_BLOCK | self::F_SPECIAL | self::F_CLOSEP,
        "h4" => self::F_BLOCK | self::F_SPECIAL | self::F_CLOSEP,
        "h5" => self::F_BLOCK | self::F_SPECIAL | self::F_CLOSEP,
        "h6" => self::F_BLOCK | self::F_SPECIAL | self::F_CLOSEP,
        // head: self::F_SPECIAL
        // header: self::F_SPECIAL | self::F_CLOSEP
        // hgroup: self::F_SPECIAL | self::F_CLOSEP
        "hotcrp-multimeter" => 0, /* special! */
        "hr" => self::F_BLOCK | self::F_SPECIAL | self::F_CLOSEP | self::F_VOID,
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
        "legend" => self::F_BLOCK | (self::SC_FIELDSET << self::REQSCP1),
        "li" => self::F_BLOCK | self::F_SPECIAL | self::F_ENDOPTIONAL | self::F_CLOSEP | (self::SC_LIST << self::REQSCP1),
        // link: self::F_SPECIAL
        // listing: self::F_SPECIAL | self::F_CLOSEP
        // main: self::F_SPECIAL | self::F_CLOSEP
        // map
        "mark" => 0,
        "marquee" => self::F_DISABLED | self::F_SPECIAL | self::F_MARKER | self::F_DEFAULT_SCOPE,
        "menu" => self::F_BLOCK | self::F_SPECIAL | self::F_CLOSEP | (self::SC_LIST << self::SCP),
        // menuitem
        // meta: self::F_SPECIAL
        "meter" => 0,
        // nav: self::F_SPECIAL | self::F_CLOSEP
        // nobr: self::F_FORMAT
        // noembed: self::F_SPECIAL
        // noframes: self::F_SPECIAL
        "noscript" => self::F_SPECIAL,
        "object" => self::F_DISABLED | self::F_SPECIAL | self::F_MARKER | self::F_DEFAULT_SCOPE,
        "ol" => self::F_BLOCK | self::F_SPECIAL | self::F_NOTEXT | self::F_CLOSEP | (self::SC_LIST << self::SCP),
        // optgroup: self::F_ENDOPTIONAL | (self::SC_OPTGROUP << self::SCP) | (self::SC_SELECT << self::REQSCP1)
        // option: self::F_ENDOPTIONAL | (self::SC_OPTGROUP << self::REQSCP1) | (self::SC_SELECT << self::REQSCP2)
        "p" => self::F_BLOCK | self::F_SPECIAL | self::F_CLOSEP | self::F_ENDOPTIONAL,
        // param: self::F_SPECIAL
        "picture" => self::F_BLOCK | (self::SC_MEDIA << self::SCP),
        // plaintext: self::F_SPECIAL
        "pre" => self::F_BLOCK | self::F_SPECIAL | self::F_CLOSEP,
        "progress" => 0,
        "q" => 0,
        // rb: self::F_ENDOPTIONAL
        "rp" => self::F_ENDOPTIONAL | (self::SC_RUBY << self::REQSCP1),
        "rt" => self::F_ENDOPTIONAL | (self::SC_RUBY << self::REQSCP1),
        // rtc: self::F_ENDOPTIONAL
        "ruby" => self::SC_RUBY << self::SCP,
        "s" => self::F_FORMAT,
        "samp" => 0,
        // script: self::F_SPECIAL
        // search: self::F_SPECIAL | self::F_CLOSEP
        // section: self::F_SPECIAL | self::F_CLOSEP
        // select: self::F_SPECIAL | (self::SC_SELECT << self::SCP)
        // slot
        "small" => self::F_FORMAT,
        "source" => self::F_VOID | self::F_SPECIAL | (self::SC_MEDIA << self::REQSCP1),
        "span" => 0,
        "strike" => self::F_FORMAT,
        "strong" => self::F_FORMAT,
        // style: self::F_SPECIAL
        "sub" => 0,
        "summary" => self::F_BLOCK | self::F_SPECIAL | self::F_CLOSEP | (self::SC_DETAILS << self::REQSCP1),
        "sup" => 0,
        "table" => self::F_BLOCK | self::F_SPECIAL | self::F_NOTEXT | self::F_DEFAULT_SCOPE | self::F_CLOSEP | (self::SC_TABLE << self::SCP),
        "tbody" => self::F_BLOCK | self::F_SPECIAL | self::F_NOTEXT | self::F_ENDOPTIONAL | (self::SC_TROWS << self::SCP) | (self::SC_TABLE << self::REQSCP1),
        "td" => self::F_BLOCK | self::F_SPECIAL | self::F_MARKER | self::F_DEFAULT_SCOPE | self::F_ENDOPTIONAL | (self::SC_TR << self::REQSCP1),
        "template" => self::F_DISABLED | self::F_SPECIAL | self::F_MARKER | self::F_DEFAULT_SCOPE,
        // textarea: self::F_SPECIAL
        "tfoot" => self::F_BLOCK | self::F_SPECIAL | self::F_NOTEXT | self::F_ENDOPTIONAL | (self::SC_TROWS << self::SCP) | (self::SC_TABLE << self::REQSCP1),
        "th" => self::F_BLOCK | self::F_SPECIAL | self::F_MARKER | self::F_DEFAULT_SCOPE | self::F_ENDOPTIONAL | (self::SC_TR << self::REQSCP1),
        "thead" => self::F_BLOCK | self::F_SPECIAL | self::F_NOTEXT | self::F_ENDOPTIONAL | (self::SC_TROWS << self::SCP) | (self::SC_TABLE << self::REQSCP1),
        "time" => 0,
        // title: self::F_SPECIAL
        "tr" => self::F_BLOCK | self::F_SPECIAL | self::F_NOTEXT | self::F_ENDOPTIONAL | (self::SC_TR << self::SCP) | (self::SC_TROWS << self::REQSCP1) | (self::SC_TABLE << self::REQSCP2),
        "track" => self::F_BLOCK | self::F_SPECIAL | (self::SC_MEDIA << self::REQSCP1),
        "tt" => self::F_FORMAT,
        "u" => self::F_FORMAT,
        "ul" => self::F_BLOCK | self::F_SPECIAL | self::F_NOTEXT | self::F_CLOSEP | (self::SC_LIST << self::SCP),
        "var" => 0,
        "video" => self::F_BLOCK | (self::SC_MEDIA << self::SCP),
        "wbr" => self::F_VOID | self::F_SPECIAL
        // xmp: self::F_SPECIAL | self::F_CLOSEP
    ];
    // XXX SVG
    // XXX MathML

    /** @param int $flags */
    function __construct($flags = 0) {
        $this->flags = $flags;
        $this->tagflags = self::$base_tagflags;
    }

    /** @return $this */
    function disable_all() {
        foreach ($this->tagflags as &$tf) {
            $tf |= self::F_DISABLED;
        }
        return $this;
    }

    /** @param string ...$tags
     * @return $this */
    function enable(...$tags) {
        foreach ($tags as $tag) {
            $this->tagflags[$tag] = ($this->tagflags[$tag] ?? self::F_DISABLED) & ~self::F_DISABLED;
        }
        return $this;
    }

    /** @param string $tag
     * @param int $flag
     * @return $this */
    function define($tag, $flag) {
        $this->tagflags[$tag] = $flag;
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
        $tp1 = ($tagtf >> self::REQSCP1) & self::SCMASK;
        $tp2 = (($tagtf >> self::REQSCP2) & self::SCMASK) ? : self::SC_INVALID;
        $tlist = [];
        foreach ($this->tagflags as $n => $tf) {
            if (($t = ($tf >> self::SCP) & self::SCMASK) === $tp1 || $t === $tp2) {
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
        }
        return "here";
    }

    /** @param int &$curtf
     * @param string $tag
     * @param int $tagtf
     * @return string */
    private function fix_before_open(&$curtf, $tag, $tagtf) {
        $insertion = "";
        // Close formatting elements and then an open <p> before F_CLOSEP
        // elements. Unlike the spec's "close a p element" algorithm, we
        // check for <p> directly: in any fixable input, <p> will not contain
        // any F_ENDOPTIONAL element.
        if (($tagtf & self::F_CLOSEP) !== 0) {
            while (($curtf & self::F_FORMAT) !== 0) {
                $insertion .= "</{$this->opentags->tag}>";
                $curtf = $this->opentags->tagfl;
                $this->opentags = $this->opentags->next;
            }
            if ($this->opentags && $this->opentags->tag === "p") {
                $insertion .= "</p>";
                $curtf = $this->opentags->tagfl;
                $this->opentags = $this->opentags->next;
            }
        }
        // Close F_ENDOPTIONAL elements until reaching required scope
        if ($tagtf >= (1 << self::REQSCP1)) {
            $pt1 = ($tagtf >> self::REQSCP1) & self::SCMASK;
            $pt2 = ($tagtf >> self::REQSCP2) & self::SCMASK ? : self::SC_INVALID;
            while (($curtf & self::F_ENDOPTIONAL) !== 0
                   && ($oscp = ($curtf >> self::SCP) & self::SCMASK) !== $pt1
                   && $oscp !== $pt2) {
                $insertion .= "</{$this->opentags->tag}>";
                $curtf = $this->opentags->tagfl;
                $this->opentags = $this->opentags->next;
            }
        }
        return $insertion;
    }

    /** @param int &$curtf
     * @param string $tag
     * @return string */
    private function fix_before_close(&$curtf, $tag, $tagtf) {
        $insertion = "";
        if ($this->opentags !== null && $this->opentags->tag === $tag) {
            return $insertion;
        }
        if (($tagtf & self::F_FORMAT) !== 0
            && ($insertion = $this->adoption_agency($curtf, $tag))) {
            $this->fixed_by_adoption = true;
            return $insertion;
        } else if (($tagtf & self::F_CLOSEP) !== 0) {
            while (($curtf & self::F_FORMAT) !== 0) {
                $insertion .= "</{$this->opentags->tag}>";
                $curtf = $this->opentags->tagfl;
                $this->opentags = $this->opentags->next;
            }
        }
        while ($this->opentags !== null
               && $this->opentags->tag !== $tag
               && ($curtf & self::F_ENDOPTIONAL) !== 0) {
            $insertion .= "</{$this->opentags->tag}>";
            $curtf = $this->opentags->tagfl;
            $this->opentags = $this->opentags->next;
        }
        return $insertion;
    }

    /** @param int &$curtf
     * @param string $tag
     * @return string */
    private function adoption_agency(&$curtf, $tag) {
        $prevtag = null;
        $travtag = $this->opentags;
        $travtf = $curtf;
        $a = $b = "";
        while (($travtf & self::F_FORMAT) !== 0) {
            if ($travtag->tag === $tag) {
                $prevtag->next = $travtag->next;
                return "{$b}</{$tag}>{$a}";
            }
            $a = ($travtag->opener ?? "<{$travtag->tag}>") . $a;
            $b .= "</{$travtag->tag}>";
            $prevtag = $travtag;
            $travtf = $travtag->tagfl;
            $travtag = $travtag->next;
        }
        return "";
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
        }
        return true;
    }

    /** @return null */
    private function fail() {
        $this->context = $this->opentags = null;
        return null;
    }

    /** @param string $tag
     * @param string $attr
     * @param ?string $value
     * @return string */
    function clean_attribute($tag, $attr, $value, $attrpos, $endpos) {
        $lattr = strtolower($attr);
        if ((strlen($lattr) > 2 && $lattr[0] === "o" && $lattr[1] === "n")
            || $lattr === "style"
            || $lattr === "script"
            || $lattr === "id"
            || (str_starts_with($lattr, "data-")
                && !str_starts_with($lattr, "data-tooltip"))) {
            $this->lerror("<0>HTML attribute {$attr} not allowed", $attrpos, $endpos);
            return "";
        }
        if ($lattr === "class") {
            $xvalue = "";
            preg_match_all('/\S++/', $value ?? "", $m);
            $stripped = false;
            foreach ($m[0] as $class) {
                if (str_starts_with($class, "ui")
                    || str_starts_with($class, "js-")
                    || str_starts_with($class, "s-")
                    || (str_starts_with($class, "pl")
                        && !preg_match('/\Apl-\d\z/', $class))) {
                    $this->lerror("<0>HTML class {$class} not allowed", $attrpos, $endpos);
                } else {
                    $xvalue .= ($xvalue === "" ? $class : " {$class}");
                }
            }
            if (($value = $xvalue) === "") {
                return "";
            }
        }
        if ($lattr === "href"
            && $value !== null
            && preg_match('/\A\s*+((?!http[\s:]|https[\s:])[a-z][-+.a-z0-9]*+)\s*+:/i', $value, $m)) {
            $this->lerror("<0>URL scheme {$m[1]} not allowed in links", $attr, $endpos);
            return "";
        }
        return $value === null ? " {$lattr}" : " {$lattr}=\"" . htmlspecialchars($value) . "\"";
    }

    /** @param string $t
     * @return ?string */
    function clean($t) {
        if (($this->flags & self::CLEAN_INLINE) !== 0) {
            $curtf = 0;
        } else {
            $curtf = self::F_BLOCK;
        }
        $this->ml = [];
        $this->context = $t;
        $this->fixed_by_adoption = false;

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
                $tagtf = $this->tagflags[$tag] ?? self::F_DISABLED;
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
                if (($this->flags & self::CLEAN_FIX) !== 0) {
                    $x .= $this->fix_before_open($curtf, $tag, $tagtf);
                }
                $tagstart = strlen($x);
                $x .= "<{$tag}";
                if (($tagtf & self::F_BLOCK) !== 0
                    && ($curtf & self::F_BLOCK) === 0) {
                    $this->lerror("<0>Block-level element <{$m[2]}> not allowed " . $this->here(), $tagp, $endp);
                }
                if ($tagtf >= (1 << self::REQSCP1)) {
                    $pt1 = ($tagtf >> self::REQSCP1) & self::SCMASK;
                    $pt2 = ($tagtf >> self::REQSCP2) & self::SCMASK ? : self::SC_INVALID;
                    $curt = ($curtf >> self::SCP) & self::SCMASK;
                    if ($curt !== $pt1 && $curt !== $pt2) {
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
                    $p += strlen($m[0]);
                    $value = null;
                    if (preg_match('/\G=\s*+(\'[^\']*+\'|"[^\"]*+"|\w++)\s*+/s', $t, $mm, 0, $p)) {
                        if ($mm[1][0] === "'" || $mm[1][0] === "\"") {
                            $mm[1] = substr($mm[1], 1, -1);
                        }
                        $value = html_entity_decode($mm[1], ENT_HTML5);
                        $p += strlen($mm[0]);
                    } else if ($p !== $len && $t[$p] === "=") {
                        $this->lerror("<0>Broken value on HTML attribute {$m[1]}", $ap, $p + 1);
                        $p = $endp;
                        break;
                    }
                    $x .= $this->clean_attribute($tag, $m[1], $value, $ap, $p);
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
                $opentag = substr($x, $tagstart) . ">";
                $this->opentags = new CleanHTMLTag($tag, $tagp, $endp, $curtf, $this->opentags);
                if (($tagtf & self::F_FORMAT) !== 0
                    && strlen($x) > $tagstart + strlen($tag) + 1) {
                    $this->opentags->opener = substr($x, $tagstart);
                    if (!str_ends_with($x, ">")) {
                        $this->opentags->opener .= ">";
                    }
                }
                $curtf = $tagtf;
            } else if (preg_match('/\G<\s*+\/\s*+([A-Za-z][-A-Za-z0-9]*+)\s*+>/s', $t, $m, 0, $p)) {
                $tag = strtolower($m[1]);
                $tagp = $p;
                $endp = $tagp + strlen($m[0]);
                $tagtf = $this->tagflags[$tag] ?? self::F_DISABLED;
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
                if (($this->flags & self::CLEAN_FIX) !== 0
                    && ($ins = $this->fix_before_close($curtf, $tag, $tagtf)) !== "") {
                    $x .= substr($t, $xp, $p - $xp) . $ins;
                    if ($this->fixed_by_adoption) {
                        $xp = $p = $endp;
                        continue;
                    }
                    $xp = $p;
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
        if (($this->flags & self::CLEAN_FIX) !== 0
            && ($ins = $this->fix_before_close($curtf, "\0", 0)) !== "") {
            $x .= $ins;
        }
        if ($this->opentags) {
            $this->lerror("<0>Unclosed tag", $this->opentags->pos1, $this->opentags->pos2);
        }

        $this->context = $this->opentags = null;
        if (!empty($this->ml)) {
            return null;
        }
        return preg_replace('/\r\n?/', "\n", $x);
    }

    /** @param string|list<string> $t
     * @return ?list<string> */
    function clean_all($t) {
        $x = [];
        foreach (is_array($t) ? $t : [$t] as $s) {
            if (is_string($s)
                && ($s = $this->clean($s)) !== null) {
                $x[] = $s;
            } else {
                return null;
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
     * @return ?string */
    static function basic_clean($t) {
        return self::basic()->clean($t);
    }

    /** @param string|list<string> $t
     * @return ?list<string> */
    static function basic_clean_all($t) {
        return self::basic()->clean_all($t);
    }
}
