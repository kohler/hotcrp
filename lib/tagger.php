<?php
// tagger.php -- HotCRP helper class for dealing with tags
// Copyright (c) 2006-2024 Eddie Kohler; see LICENSE.

// Note that tags MUST NOT contain HTML or URL special characters:
// no "'&<>.  If you add PHP-protected characters, such as $, make sure you
// check for uses of eval().

class TagInfo {
    /** @var string */
    public $tag;
    /** @var Conf */
    public $conf;
    /** @var int */
    public $flags = 0;
    /** @var int */
    public $pattern_version = 0;
    /** @var ?list<TagAnno> */
    private $_order_anno_list;
    /** @var int */
    private $_order_anno_search = 0;
    /** @var ?string */
    public $autosearch;
    /** @var ?string */
    public $autosearch_value;
    /** @var ?SearchTerm */
    private $_autosearch_term;
    /** @var ?float */
    public $allotment;
    /** @var ?list<TagStyle> */
    public $styles;
    /** @var ?TagStyle */
    public $badge;
    /** @var ?list<string> */
    public $emoji;

    const TF_TRACK = 0x1;
    const TF_SCLASS = 0x2;
    const TF_CHAIR = 0x4;
    const TF_PRIVATE = 0x8;
    const TF_READONLY = 0x10;
    const TF_HIDDEN = 0x20;
    const TF_APPROVAL = 0x40;
    const TF_ALLOTMENT = 0x80;
    const TF_RANK = 0x100;
    const TF_SITEWIDE = 0x200;
    const TF_CONFLICT_FREE = 0x400;
    const TF_PUBLIC_PERUSER = 0x800;
    const TF_AUTOMATIC = 0x1000;
    const TF_AUTOSEARCH = 0x2000;
    const TF_STYLE = 0x4000;
    const TF_BADGE = 0x8000;
    const TF_EMOJI = 0x10000;
    const TF_IS_SETTINGS = 0x20000;
    const TF_IS_PATTERN = 0x40000;

    const TFM_VOTES = 0xC0;
    const TFM_DECORATION = 0x1C000;

    /** @param string $tag
     * @param int $flags */
    function __construct($tag, TagMap $tagmap, $flags = 0) {
        $this->conf = $tagmap->conf;
        $this->tag = $tag;
        $this->flags = $flags;
        if (($ks = $tagmap->known_style($tag)) !== null) {
            $this->styles[] = $ks;
        } else if (str_starts_with($tag, ":")
                   && ($e = $tagmap->check_emoji_code(strtolower($tag))) !== false) {
            $this->emoji[] = $e;
        }
        if ($tag[0] === "~") {
            if ($tag[1] !== "~") {
                $this->flags |= self::TF_PRIVATE;
            } else {
                $this->flags |= self::TF_CHAIR;
            }
        }
    }
    /** @template T
     * @param ?list<T> $l1
     * @param list<T> $l2
     * @return list<T> */
    static private function merge_lists($l1, $l2) {
        if (empty($l1)) {
            return $l2;
        }
        foreach ($l2 as $x) {
            if (!in_array($x, $l1))
                $l1[] = $x;
        }
        return $l1;
    }
    /** @param int|TagInfo $ti */
    function merge($ti) {
        if (is_int($ti)) {
            $this->flags |= $ti;
        } else {
            $this->flags |= $ti->flags & ~(self::TF_IS_PATTERN | self::TF_IS_SETTINGS);
            if ($ti->autosearch !== null) {
                $this->autosearch = $ti->autosearch;
                $this->autosearch_value = $ti->autosearch_value;
                $this->_autosearch_term = null;
            }
            if ($ti->allotment !== null) {
                $this->allotment = $ti->allotment;
            }
            if ($ti->styles) {
                $this->styles = self::merge_lists($this->styles, $ti->styles);
            }
            if ($ti->badge) {
                $this->badge = $ti->badge;
            }
            if ($ti->emoji) {
                $this->emoji = self::merge_lists($this->emoji, $ti->emoji);
            }
        }
    }

    /** @return string */
    function ltag() {
        return strtolower($this->tag);
    }
    /** @param int $f
     * @return bool */
    function is($f) {
        return ($this->flags & $f) !== 0;
    }

    /** @return string */
    function tag_regex() {
        $t = preg_quote($this->tag);
        if (($this->flags & self::TF_IS_PATTERN) !== 0) {
            $t = str_replace("\\*", "[^\\s#~]*", $t);
        }
        if (($this->flags & self::TF_PRIVATE) !== 0) {
            $t = "\\d*" . $t;
        }
        return $t;
    }
    /** @return list<TagAnno> */
    function order_anno_list() {
        if ($this->_order_anno_list === null) {
            $this->_order_anno_list = [];
            $this->_order_anno_search = 0;
            $result = $this->conf->qe("select * from PaperTagAnno where tag=?", $this->tag);
            while (($ta = TagAnno::fetch($result, $this->conf))) {
                $this->_order_anno_list[] = $ta;
            }
            Dbl::free($result);
            $this->_order_anno_list[] = TagAnno::make_tag_fencepost($this->tag);
            $collator = $this->conf->collator();
            usort($this->_order_anno_list, function ($a, $b) use ($collator) {
                return $a->tagIndex <=> $b->tagIndex
                    ? : ($collator->compare($a->heading, $b->heading)
                         ? : $a->annoId <=> $b->annoId);
            });
            $last_la = null;
            foreach ($this->_order_anno_list as $i => $la) {
                $la->annoIndex = $i;
                if ($last_la) {
                    $last_la->endTagIndex = $la->tagIndex;
                }
                $last_la = $la;
            }
        }
        return $this->_order_anno_list;
    }
    /** @param int $i
     * @return ?TagAnno */
    function order_anno_entry($i) {
        return ($this->order_anno_list())[$i] ?? null;
    }
    /** @param int|float $tagIndex
     * @return ?TagAnno */
    function order_anno_search($tagIndex) {
        $ol = $this->order_anno_list();
        $l = $this->_order_anno_search;
        $r = count($ol);
        if ($l !== 0 && $tagIndex < $ol[$l-1]->tagIndex) {
            $l = 0;
        } else if ($tagIndex < $ol[$l]->tagIndex) {
            $r = $l;
        }
        while ($l < $r) {
            $m = $l + (($r - $l) >> 1);
            if ($tagIndex < $ol[$m]->tagIndex) {
                $r = $m;
            } else {
                $l = $m + 1;
            }
        }
        $this->_order_anno_search = $l;
        return $l !== 0 ? $ol[$l - 1] : null;
    }
    /** @return bool */
    function has_order_anno() {
        return count($this->order_anno_list()) > 1;
    }
    function invalidate_order_anno() {
        $this->_order_anno_list = null;
        $this->_order_anno_search = 0;
    }
    /** @return ?string */
    function automatic_search() {
        if ($this->autosearch !== null) {
            return $this->autosearch;
        } else if (($this->flags & self::TFM_VOTES) !== 0) {
            return "#*~" . $this->tag;
        } else {
            return null;
        }
    }
    /** @return ?SearchTerm */
    function automatic_search_term() {
        if ($this->_autosearch_term === null
            && ($q = $this->automatic_search()) !== null) {
            $this->_autosearch_term = (new PaperSearch($this->conf->root_user(), ["q" => $q, "t" => "all"]))
                ->set_expand_automatic(true)
                ->main_term();
        }
        return $this->_autosearch_term;
    }
    /** @return ?string */
    function automatic_formula_expression() {
        if ($this->autosearch !== null) {
            return $this->autosearch_value ?? "0";
        } else if (($this->flags & self::TF_APPROVAL) !== 0) {
            return "count.pc(#_~{$this->tag}) || null";
        } else if (($this->flags & self::TF_ALLOTMENT) !== 0) {
            return "sum.pc(#_~{$this->tag}) || null";
        } else {
            return null;
        }
    }
}

class TagAnno implements JsonSerializable {
    /** @var string */
    public $tag;
    /** @var int */
    public $annoId;
    /** @var ?float */
    public $tagIndex;
    /** @var ?string */
    public $heading;
    /** @var ?int */
    public $annoFormat;
    /** @var ?string */
    public $infoJson;

    /** @var int */
    public $annoIndex;      // index in array
    /** @var ?float */
    public $endTagIndex;    // tagIndex of next anno
    /** @var ?int */
    public $pos;
    /** @var ?int */
    public $count;
    /** @var ?object */
    private $_props;

    /** @return ?TagAnno */
    static function fetch($result, Conf $conf) {
        $ta = $result ? $result->fetch_object("TagAnno") : null;
        '@phan-var ?TagAnno $ta';
        if ($ta) {
            $ta->annoId = (int) $ta->annoId;
            $ta->tagIndex = (float) $ta->tagIndex;
            if ($ta->annoFormat !== null) {
                $ta->annoFormat = (int) $ta->annoFormat;
            }
        }
        return $ta;
    }
    /** @return TagAnno */
    static function make_empty() {
        return new TagAnno;
    }
    /** @param string $h
     * @return TagAnno */
    static function make_legend($h) {
        $ta = new TagAnno;
        $ta->heading = $h;
        return $ta;
    }
    /** @return TagAnno */
    static function make_tag_fencepost($tag) {
        $ta = new TagAnno;
        $ta->tag = $tag;
        $ta->tagIndex = $ta->endTagIndex = (float) TAG_INDEXBOUND;
        $ta->heading = "Untagged";
        return $ta;
    }

    /** @return bool */
    function is_blank() {
        return $this->heading === null || strcasecmp($this->heading, "none") === 0;
    }
    /** @return bool */
    function is_fencepost() {
        return $this->tagIndex >= (float) TAG_INDEXBOUND;
    }
    private function decode_props() {
        $j = json_decode($this->infoJson ?? "{}");
        $this->_props = is_object($j) ? $j : null;
    }
    /** @param string $k
     * @return mixed */
    function prop($k) {
        if ($this->_props === null && $this->infoJson !== null) {
            $this->decode_props();
        }
        return $this->_props ? $this->_props->$k : null;
    }
    /** @param string $k
     * @param mixed $v */
    function set_prop($k, $v) {
        if ($this->_props === null) {
            $this->decode_props();
            $this->_props = $this->_props ?? (object) [];
        }
        $this->_props->$k = $v;
    }

    #[\ReturnTypeWillChange]
    function jsonSerialize() {
        $j = [];
        if ($this->pos !== null) {
            $j["pos"] = $this->pos;
        }
        if ($this->annoId !== null) {
            $j["annoid"] = $this->annoId;
        }
        if ($this->tag) {
            $j["tag"] = $this->tag;
        }
        if ($this->tagIndex !== null) {
            $j["tagval"] = $this->tagIndex;
        }
        if ($this->is_blank()) {
            $j["blank"] = true;
        }
        if ($this->heading !== null) {
            $j["legend"] = $this->heading; // XXX "heading" backward compat
            if ($this->heading !== ""
                && ($format = Conf::$main->check_format(null, $this->heading))) {
                $j["format"] = +$format;
            }
        }
        if ($this->_props === null && $this->infoJson !== null) {
            $this->decode_props();
        }
        if ($this->_props !== null) {
            foreach ($this->_props as $k => $v) {
                if (!in_array($k, ["pos", "annoid", "tag", "tagval", "blank", "legend", "format"]))
                    $j[$k] = $v;
            }
        }
        return $j;
    }
}

class TagStyle {
    /** @var string
     * @readonly */
    public $style;
    /** @var int
     * @readonly */
    public $styleflags;
    /** @var ?int
     * @readonly */
    public $rgb;
    /** @var ?OklchColor */
    private $oklch;

    const DYNAMIC = 0x1;
    const UNLISTED = 0x2;
    const BADGE = 0x4;
    const BG = 0x8;
    const TEXT = 0x10;
    const STYLE = 0x18; // BG | TEXT
    const IMAGE = 0x20;
    const DARK = 0x40;
    const NEEDDARK = 0x80;


    /** @param string $style
     * @param int $styleflags
     * @param ?int $rgb */
    function __construct($style, $styleflags, $rgb = null) {
        $this->style = $style;
        $this->styleflags = $styleflags;
        $this->rgb = $rgb;
    }

    /** @param string $style
     * @return TagStyle */
    static function make_dynamic($style) {
        $first = $style[0];
        if ($first === "r") { // `rgb-`
            $sf = self::BG | self::NEEDDARK;
        } else if ($first === "d") { // `dot-`
            $sf = self::BG | self::IMAGE;
        } else if ($first === "b") { // `badge-`
            $sf = self::BADGE;
        } else {
            $sf = self::TEXT;
        }
        return new TagStyle($style, self::DYNAMIC | $sf);
    }

    /** @param string $s
     * @param TagMap $tm
     * @return ?string */
    static function dynamic_style($s, $tm) {
        $len = strlen($s);
        if (str_starts_with($s, "rgb-")) {
            return self::expand_color($s, 4, null);
        } else if (str_starts_with($s, "text-rgb-")) {
            return self::expand_color($s, 9, null);
        } else if (str_starts_with($s, "dot-")) {
            return self::expand_color($s, 4, $tm);
        } else if (str_starts_with($s, "font-")
                   || str_starts_with($s, "weight-")) {
            return $s;
        } else {
            return null;
        }
    }

    /** @param string $color
     * @param int $pfxlen
     * @param ?TagMap $tm
     * @return string */
    static function expand_color($color, $pfxlen, $tm) {
        if ($tm && substr($color, $pfxlen, 4) === "rgb-") {
            $tm = null;
            $pfxlen += 4;
        }
        if ($tm) {
            if (($ts = $tm->find_style(strtolower(substr($color, $pfxlen))))
                && $ts->rgb !== null) {
                return substr($color, 0, $pfxlen) . "rgb-" . sprintf("%06x", $ts->rgb);
            }
        } else if (ctype_xdigit(substr($color, $pfxlen))) {
            if (strlen($color) === $pfxlen + 3) {
                $r = $color[$pfxlen];
                $g = $color[$pfxlen + 1];
                $b = $color[$pfxlen + 2];
                return substr($color, 0, $pfxlen) . "{$r}{$r}{$g}{$g}{$b}{$b}";
            } else if (strlen($color) === $pfxlen + 6) {
                return $color;
            }
        }
        return null;
    }

    /** @return bool */
    function dark() {
        if (($this->styleflags & self::NEEDDARK) !== 0) {
            $rgb = intval(substr($this->style, -6), 16);
            $r = $rgb >> 16;
            $g = ($rgb >> 8) & 255;
            $b = $rgb & 255;
            $rx = $r <= 10.31475 ? $r / 3294.60 : pow(($r + 14.025) / 269.025, 2.4);
            $gx = $g <= 10.31475 ? $g / 3294.60 : pow(($g + 14.025) / 269.025, 2.4);
            $bx = $b <= 10.31475 ? $b / 3294.60 : pow(($b + 14.025) / 269.025, 2.4);
            $l = 0.2126 * $rx + 0.7152 * $gx + 0.0722 * $bx;
            /** @phan-suppress-next-line PhanAccessReadOnlyProperty */
            $this->styleflags = ($this->styleflags & ~self::NEEDDARK)
                | ($l < 0.3 ? self::DARK : 0);
        }
        return ($this->styleflags & self::DARK) !== 0;
    }

    /** @return ?OklchColor
     * @suppress PhanParamSuspiciousOrder */
    function oklch() {
        if (($this->styleflags & (self::BG | self::IMAGE)) !== self::BG) {
            return null;
        }
        if ($this->oklch === null) {
            if ($this->rgb !== null) {
                $rgb = $this->rgb;
            } else if (($this->styleflags & self::DYNAMIC) !== 0) {
                $rgb = intval(substr($this->style, -6), 16);
            } else {
                return null;
            }
            $this->oklch = OklchColor::from_rgb($rgb >> 16, ($rgb >> 8) & 255, $rgb & 255);
        }
        return $this->oklch;
    }
}

class TagMap {
    /** @var Conf */
    public $conf;
    /** @var int */
    public $flags;
    /** @var bool */
    public $has_role_decoration = false;
    /** @var array<string,TagInfo> */
    private $storage = [];
    /** @var list<TagInfo> */
    private $setting_storage = [];
    /** @var list<string> */
    private $patterns = [];
    /** @var list<TagInfo> */
    private $pattern_storage = [];
    /** @var ?string */
    private $pattern_re;
    /** @var int */
    private $pattern_version = 0; // = count($pattern_storage)
    /** @var ?string */
    private $color_re;
    /** @var ?string */
    private $badge_re;
    /** @var ?string */
    private $emoji_re;
    /** @var ?list<TagInfo> */
    private $automatic_entries;

    /** @var array<string,TagStyle> */
    private $style_lmap = [];

    private static $multicolor_map = [];

    function __construct(Conf $conf) {
        $this->conf = $conf;
        $this->flags = TagInfo::TF_CHAIR | TagInfo::TF_READONLY;

        // RGB colors taken from style.css
        $this->define_style("red", new TagStyle("red", TagStyle::BG | TagStyle::BADGE, 0xffd8d8));
        $this->define_style("orange", new TagStyle("orange", TagStyle::BG | TagStyle::BADGE, 0xfdebcc));
        $this->define_style("yellow", new TagStyle("yellow", TagStyle::BG | TagStyle::BADGE, 0xfdffcb));
        $this->define_style("green", new TagStyle("green", TagStyle::BG | TagStyle::BADGE, 0xd8ffd8));
        $this->define_style("blue", new TagStyle("blue", TagStyle::BG | TagStyle::BADGE, 0xd8d8ff));
        $this->define_style("purple", new TagStyle("purple", TagStyle::BG | TagStyle::BADGE, 0xf2d8f8));
        $this->define_style("violet", new TagStyle("purple", TagStyle::BG | TagStyle::BADGE | TagStyle::UNLISTED, 0xf2d8f8));
        $this->define_style("gray", new TagStyle("gray", TagStyle::BG | TagStyle::BADGE, 0xe2e2e2));
        $this->define_style("grey", new TagStyle("gray", TagStyle::BG | TagStyle::BADGE | TagStyle::UNLISTED, 0xe2e2e2));
        $this->define_style("white", new TagStyle("white", TagStyle::BG | TagStyle::BADGE, 0xffffff));

        $this->define_style("black", new TagStyle("black", TagStyle::BADGE));
        $this->define_style("default", new TagStyle("black", TagStyle::BADGE | TagStyle::UNLISTED));
        $this->define_style("normal", new TagStyle("black", TagStyle::BADGE | TagStyle::UNLISTED));
        $this->define_style("pink", new TagStyle("pink", TagStyle::BADGE));

        $this->define_style("bold", new TagStyle("bold", TagStyle::TEXT));
        $this->define_style("italic", new TagStyle("italic", TagStyle::TEXT));
        $this->define_style("underline", new TagStyle("underline", TagStyle::TEXT));
        $this->define_style("strikethrough", new TagStyle("strikethrough", TagStyle::TEXT));
        $this->define_style("big", new TagStyle("big", TagStyle::TEXT));
        $this->define_style("small", new TagStyle("small", TagStyle::TEXT));
        $this->define_style("dim", new TagStyle("dim", TagStyle::TEXT));

        if (($styles = $conf->opt("tagStyles"))) {
            expand_json_includes_callback($styles, [$this, "_add_style_json"]);
        }
    }

    /** @param string $name
     * @param TagStyle $ts */
    private function define_style($name, $ts) {
        $this->style_lmap[$name] = $ts;
    }

    /** @param object $x */
    function _add_style_json($x) {
        $name = $x->name ?? null;
        if (!is_string($name) || $name === "") {
            return false;
        }
        $sf = 0;
        if ($x->text ?? false) {
            $sf |= TagStyle::TEXT;
        }
        if ($x->bg ?? false) {
            $sf |= TagStyle::BG;
        }
        if ($x->badge ?? false) {
            $sf |= TagStyle::BADGE;
        }
        if ($x->image ?? false) {
            $sf |= TagStyle::BG | TagStyle::IMAGE;
        }
        if ($sf === 0) {
            $sf = TagStyle::BG;
        }
        if ($x->hidden ?? false) {
            $sf |= TagStyle::UNLISTED;
        }
        $rgb = null;
        if (isset($x->color)
            && is_string($x->color)
            && strlen($x->color) === 7
            && $x->color[0] === "#"
            && ctype_xdigit(($s = substr($x->color, 1)))) {
            $rgb = intval($s, 16);
        }
        if (property_exists($x, "dark")) {
            if ($x->dark === null && $rgb !== null) {
                $sf |= TagStyle::NEEDDARK;
            } else if ($x->dark) {
                $sf |= TagStyle::DARK;
            }
        }
        $style = $name;
        if (isset($x->style) && is_string($x->style)) {
            $style = $x->style;
        }
        $this->style_lmap[strtolower($name)] = new TagStyle($style, $sf, $rgb);
        return true;
    }


    /** @param int $flags
     * @return bool */
    function has($flags) {
        return ($this->flags & $flags) !== 0;
    }

    /** @param string $ltag
     * @return string|false */
    function check_emoji_code($ltag) {
        $len = strlen($ltag);
        if ($len >= 3 && $ltag[0] === ":" && $ltag[$len - 1] === ":") {
            $m = $this->conf->emoji_code_map();
            return $m[substr($ltag, 1, $len - 2)] ?? false;
        } else {
            return false;
        }
    }

    /** @param string $tag
     * @param string $ltag
     * @return ?TagInfo */
    private function update_patterns($tag, $ltag, ?TagInfo $ti) {
        if (!$this->pattern_re) {
            $a = [];
            foreach ($this->pattern_storage as $p) {
                $a[] = strtolower($p->tag_regex());
            }
            $this->pattern_re = '{\A(?:' . join("|", $a) . ')\z}';
        }
        if (preg_match($this->pattern_re, $ltag)) {
            $i = $ti ? $ti->pattern_version : 0;
            while ($i < $this->pattern_version) {
                if (preg_match($this->patterns[$i], $ltag)) {
                    if (!$ti) {
                        $ti = new TagInfo($tag, $this);
                        $this->storage[$ltag] = $ti;
                    } else if (($ti->flags & TagInfo::TF_IS_SETTINGS) !== 0) {
                        $ti = clone $ti;
                        $ti->flags &= ~TagInfo::TF_IS_SETTINGS;
                        $this->storage[$ltag] = $ti;
                    }
                    $ti->merge($this->pattern_storage[$i]);
                }
                ++$i;
            }
        }
        if ($ti) {
            $ti->pattern_version = $this->pattern_version;
        }
        return $ti;
    }

    /** @param string $tag
     * @return ?TagInfo */
    function find($tag) {
        $ltag = strtolower($tag);
        $ti = $this->storage[$ltag] ?? null;
        if (!$ti
            && $ltag !== ""
            && (($ltag[0] === ":" && $this->check_emoji_code($ltag))
                || isset($this->style_lmap[$ltag])
                || TagStyle::dynamic_style($ltag, $this))) {
            $ti = $this->ensure($tag);
        }
        if ($this->pattern_version > 0
            && (!$ti || $ti->pattern_version < $this->pattern_version)) {
            $ti = $this->update_patterns($tag, $ltag, $ti);
        }
        return $ti;
    }

    /** @param string $tag
     * @return TagInfo */
    function ensure($tag) {
        $ltag = strtolower($tag);
        $ti = $this->storage[$ltag] ?? null;
        if (!$ti) {
            $ti = new TagInfo($tag, $this);
            if (!Tagger::basic_check($ltag)) {
                return $ti;
            }
            $this->storage[$ltag] = $ti;
        }
        if ($ti->pattern_version < $this->pattern_version) {
            $ti = $this->update_patterns($tag, $ltag, $ti);
            '@phan-var TagInfo $ti';
        }
        return $ti;
    }

    /** @param string $tag
     * @param int $flags
     * @return ?TagInfo */
    function find_having($tag, $flags) {
        return ($this->flags & $flags) !== 0
            && ($ti = $this->find(Tagger::tv_tag($tag)))
            && ($ti->flags & $flags) !== 0
            ? $ti : null;
    }

    /** @param string $tag
     * @param int|TagInfo $data */
    private function ensure_setting($tag, $data) {
        if (!Tagger::basic_check($tag)) {
            return;
        }
        if (strpos($tag, "*") !== false
            || ($tag[0] === "~" && $tag[1] !== "~")) {
            $ti = is_int($data) ? new TagInfo($tag, $this, $data) : $data;
            $ti->flags |= TagInfo::TF_IS_PATTERN | TagInfo::TF_IS_SETTINGS;
            $this->setting_storage[] = $ti;
            $this->pattern_storage[] = $ti;
            $this->patterns[] = '{\A' . strtolower($ti->tag_regex()) . '\z}';
            $this->pattern_re = null;
            ++$this->pattern_version;
        } else {
            $ltag = strtolower($tag);
            $tix = $this->storage[$ltag] ?? null;
            if ($tix && ($tix->flags & TagInfo::TF_IS_SETTINGS) !== 0) {
                $tix->merge($data);
                $ti = $tix;
            } else {
                $ti = is_int($data) ? new TagInfo($tag, $this, $data) : $data;
                $this->setting_storage[] = $ti;
                if ($tix) {
                    $tix->merge($ti);
                } else {
                    $this->storage[$ltag] = $ti;
                }
                $ti->flags |= TagInfo::TF_IS_SETTINGS;
            }
        }
        $this->flags |= $ti->flags;
        if (($ti->flags & TagInfo::TF_AUTOMATIC) !== 0) {
            $this->automatic_entries = null;
        }
        if (($ti->flags & TagInfo::TF_STYLE) !== 0) {
            $this->color_re = null;
        }
        if (($ti->flags & TagInfo::TF_BADGE) !== 0) {
            $this->badge_re = null;
        }
        if (($ti->flags & TagInfo::TF_EMOJI) !== 0) {
            $this->emoji_re = null;
        }
    }
    /** @param string $tag
     * @param int $flags */
    function set($tag, $flags) {
        $this->ensure_setting($tag, $flags);
    }
    /** @param TagInfo $ti */
    function merge($ti) {
        $this->ensure_setting($ti->tag, $ti);
    }

    /** @param list<TagInfo> $tis
     * @return list<TagInfo> $tis */
    private function sorted($tis) {
        if (count($tis) > 1) {
            $collator = $this->conf->collator();
            usort($tis, function ($a, $b) use ($collator) {
                return $collator->compare($a->tag, $b->tag);
            });
        }
        return $tis;
    }

    /** @param int $flags
     * @return list<TagInfo> */
    function entries_having($flags) {
        if ($flags === TagInfo::TF_AUTOMATIC
            && $this->automatic_entries !== null) {
            return $this->automatic_entries;
        }
        $tis = [];
        if (($this->flags & $flags) !== 0) {
            foreach ($this->storage as $ti) {
                if (($ti->flags & $flags) !== 0)
                    $tis[] = $ti;
            }
        }
        if ($flags === TagInfo::TF_AUTOMATIC) {
            $this->automatic_entries = $tis;
        }
        return $tis;
    }
    /** @param int $flags
     * @return list<TagInfo> */
    function sorted_entries_having($flags) {
        return $this->sorted($this->entries_having($flags));
    }

    /** @param int $flags
     * @return list<TagInfo> */
    function settings_having($flags) {
        $tis = [];
        if (($this->flags & $flags) !== 0) {
            foreach ($this->setting_storage as $ti) {
                if (($ti->flags & $flags) !== 0)
                    $tis[] = $ti;
            }
        }
        return $tis;
    }
    /** @param int $flags
     * @return list<TagInfo> */
    function sorted_settings_having($flags) {
        return $this->sorted($this->settings_having($flags));
    }

    /** @param string $tag
     * @return bool */
    function is_chair($tag) {
        if ($tag[0] === "~") {
            return $tag[1] === "~";
        } else {
            return !!$this->find_having($tag, TagInfo::TF_CHAIR);
        }
    }
    /** @param string $tag
     * @return bool */
    function is_readonly($tag) {
        return !!$this->find_having($tag, TagInfo::TF_READONLY);
    }
    /** @param string $tag
     * @return bool */
    function is_track($tag) {
        return !!$this->find_having($tag, TagInfo::TF_TRACK);
    }
    /** @param string $tag
     * @return bool */
    function is_hidden($tag) {
        return !!$this->find_having($tag, TagInfo::TF_HIDDEN);
    }
    /** @param string $tag
     * @return bool */
    function is_sitewide($tag) {
        return !!$this->find_having($tag, TagInfo::TF_SITEWIDE);
    }
    /** @param string $tag
     * @return bool */
    function is_conflict_free($tag) {
        return !!$this->find_having($tag, TagInfo::TF_CONFLICT_FREE);
    }
    /** @param string $tag
     * @return bool */
    function is_public_peruser($tag) {
        return !!$this->find_having($tag, TagInfo::TF_PUBLIC_PERUSER);
    }
    /** @param string $tag
     * @return bool */
    function is_votish($tag) {
        return !!$this->find_having($tag, TagInfo::TFM_VOTES);
    }
    /** @param string $tag
     * @return bool */
    function is_allotment($tag) {
        return !!$this->find_having($tag, TagInfo::TF_ALLOTMENT);
    }
    /** @param string $tag
     * @return bool */
    function is_approval($tag) {
        return !!$this->find_having($tag, TagInfo::TF_APPROVAL);
    }
    /** @param string $tag
     * @return string|false */
    function votish_base($tag) {
        if (($this->flags & TagInfo::TFM_VOTES) === 0
            || ($twiddle = strpos($tag, "~")) === false) {
            return false;
        }
        $tbase = substr(Tagger::tv_tag($tag), $twiddle + 1);
        $t = $this->find($tbase);
        return $t && ($t->flags & TagInfo::TFM_VOTES) !== 0 ? $tbase : false;
    }
    /** @param string $tag
     * @return bool */
    function is_rank($tag) {
        return !!$this->find_having($tag, TagInfo::TF_RANK);
    }
    /** @param string $tag
     * @return bool */
    function is_emoji($tag) {
        return !!$this->find_having($tag, TagInfo::TF_EMOJI);
    }
    /** @param string $tag
     * @return bool */
    function is_automatic($tag) {
        return !!$this->find_having($tag, TagInfo::TF_AUTOMATIC);
    }
    /** @param string $tag
     * @return bool */
    function is_autosearch($tag) {
        return !!$this->find_having($tag, TagInfo::TF_AUTOSEARCH);
    }


    /** @param string $lname
     * @return ?TagStyle */
    function find_style($lname) {
        return $this->style_lmap[$lname] ?? null;
    }

    /** @param string $name
     * @param 4|8|12|16|20|24|28 $stylematch
     * @return ?TagStyle */
    function known_style($name, $stylematch = TagStyle::STYLE) {
        $name = strtolower($name);
        $ks = $this->style_lmap[$name] ?? null;
        if ($ks === null
            && ($dstyle = TagStyle::dynamic_style($name, $this)) !== null) {
            $ks = TagStyle::make_dynamic($dstyle);
        }
        if ($ks && ($ks->styleflags & $stylematch) !== 0) {
            return $ks;
        } else {
            return null;
        }
    }

    /** @param string $name
     * @return ?TagStyle */
    function known_badge($name) {
        return $this->known_style($name, TagStyle::BADGE);
    }

    /** @param 4|8|12|16|20|24|28 $stylematch
     * @return list<string> */
    function listed_style_names($stylematch) {
        $kss = [];
        foreach ($this->style_lmap as $ltag => $ks) {
            if (($ks->styleflags & $stylematch) !== 0
                && ($ks->styleflags & TagStyle::UNLISTED) === 0)
                $kss[] = $ltag;
        }
        return $kss;
    }


    /** @return string */
    private function color_regex() {
        if (!$this->color_re) {
            $rex = [
                "{(?:\\A| )(?:(?:\\d*~|~~|)(font-[^\s#]+|weight-(?:[a-z]+|\d+)|dot-[-0-9a-z]+|(?:text-|)rgb-[0-9a-f]{3}(?:|[0-9a-f]{3})"
            ];
            foreach ($this->style_lmap as $style => $ks) {
                if (($ks->styleflags & TagStyle::STYLE) !== 0)
                    $rex[] = $style;
            }
            $any = false;
            if (($this->flags & TagInfo::TF_STYLE) !== 0) {
                foreach ($this->setting_storage as $ti) {
                    if (!empty($ti->styles))
                        $rex[] = $ti->tag_regex();
                }
            }
            $this->color_re = join("|", $rex) . "))(?=\\z|[# ])}i";
        }
        return $this->color_re;
    }

    /** @param string|list<string> $tags
     * @param 0|8|16|24 $stylematch
     * @return list<TagStyle> */
    function unique_tagstyles($tags, $stylematch = 0) {
        if (is_array($tags)) {
            $tags = join(" ", $tags);
        }
        if (!$tags
            || $tags === " "
            || !preg_match_all($this->color_regex(), $tags, $ms)) {
            return [];
        }
        $stylematch = $stylematch ? : TagStyle::STYLE;
        $kss = [];
        $sclass = 0;
        foreach ($ms[1] as $m) {
            $t = $this->find(strtolower($m));
            if ($t === null || empty($t->styles)) {
                continue;
            }
            foreach ($t->styles as $ks) {
                if (($ks->styleflags & $stylematch) !== 0
                    && !in_array($ks, $kss))
                    $kss[] = $ks;
            }
        }
        return $kss;
    }

    /** @param string|list<string> $tags
     * @param 0|8|16|24 $stylematch
     * @param bool $no_ensure_pattern
     * @return ?list<string> */
    function styles($tags, $stylematch = 0, $no_ensure_pattern = false) {
        $kss = $this->unique_tagstyles($tags, $stylematch);
        if (empty($kss)) {
            return null;
        }
        $classes = [];
        $sf = $nbg = $ndarkbg = 0;
        foreach ($kss as $ks) {
            $classes[] = "tag-{$ks->style}";
            $sf |= $ks->styleflags;
            if (($ks->styleflags & TagStyle::BG) !== 0) {
                ++$nbg;
                if ($ks->dark())
                    ++$ndarkbg;
            }
        }
        if ($nbg > 0 && $ndarkbg * 2 > $nbg) {
            $classes[] = "dark";
        }
        if ($nbg > 0) {
            $classes[] = "tagbg"; // NB if present, tagbg must come last
        }
        // This seems out of place---it's redundant if we're going to
        // generate JSON, for example---but it is convenient.
        if (!$no_ensure_pattern
            && ($sf & TagStyle::BG) !== 0
            && (($sf & TagStyle::DYNAMIC) !== 0 || count($classes) > 2)) {
            self::stash_ensure_pattern($classes);
        }
        return $classes;
    }

    /** @param list<string>|string $classes */
    static function stash_ensure_pattern($classes) {
        $key = is_array($classes) ? join(" ", $classes) : $classes;
        if (!isset(self::$multicolor_map[$key])) {
            $arg = json_encode_browser($key);
            if (str_starts_with($key, "badge-")) {
                $arg .= ",\"badge\"";
            }
            Ht::stash_script("hotcrp.ensure_pattern({$arg})");
            self::$multicolor_map[$key] = true;
        }
    }

    /** @return string */
    function color_classes($tags, $no_pattern_fill = false) {
        $s = $this->styles($tags, 0, $no_pattern_fill);
        return !empty($s) ? join(" ", $s) : "";
    }


    /** @return string */
    function badge_regex() {
        if (!$this->badge_re) {
            $re = "{(?:\\A| )(?:\\d*~|)(";
            foreach ($this->settings_having(TagInfo::TF_BADGE) as $t) {
                $re .= $t->tag_regex() . "|";
            }
            $this->badge_re = substr($re, 0, -1) . ")(?:#[-\\d.]+)?(?=\\z| )}i";
        }
        return $this->badge_re;
    }

    /** @return string */
    function emoji_regex() {
        if (!$this->emoji_re) {
            $re = "{(?:\\A| )(?:\\d*~|~~|)(:\\S+:";
            foreach ($this->settings_having(TagInfo::TF_EMOJI) as $t) {
                $re .= "|" . $t->tag_regex();
            }
            $this->emoji_re = $re . ")(?:#[\\d.]+)?(?=\\z| )}i";
        }
        return $this->emoji_re;
    }

    /** @param string $tags
     * @return array<string,list<string>> */
    function emoji($tags) {
        if (($this->flags & TagInfo::TF_EMOJI) === 0 || $tags === "" || $tags === " ") {
            return [];
        }
        preg_match_all($this->emoji_regex(), $tags, $m, PREG_SET_ORDER);
        $emoji = [];
        foreach ($m as $mx) {
            if (($t = $this->find($mx[1])) && $t->emoji) {
                foreach ($t->emoji as $e)
                    $emoji[$e][] = ltrim($mx[0]);
            }
        }
        return $emoji;
    }

    /** @param string $tags
     * @return list<array{string,string}> */
    function badges($tags) {
        if (($this->flags & TagInfo::TF_BADGE) === 0 || $tags === "" || $tags === " ") {
            return [];
        }
        preg_match_all($this->badge_regex(), $tags, $m, PREG_SET_ORDER);
        $badges = [];
        foreach ($m as $mx) {
            if (($t = $this->find($mx[1])) && $t->badge) {
                $badges[] = [ltrim($mx[0]), $t->badge->style];
            }
        }
        return $badges;
    }


    /** @param ?string $s
     * @return bool */
    static function is_tag_string($s, $strict = false) {
        return (string) $s === ""
            || preg_match($strict ? '/\A(?: [^#\s]+#-?[\d.]+)+\z/' : '/\A(?: \S+)+\z/', $s);
    }

    static function assert_tag_string($tags, $strict = false) {
        if (!self::is_tag_string($tags, $strict)) {
            trigger_error("Bad tag string {$tags}");
        }
    }

    const CENSOR_SEARCH = 0;
    const CENSOR_VIEW = 1;
    /** @param 0|1 $ctype
     * @param ?string $tags
     * @return string */
    function censor($ctype, $tags, Contact $user, ?PaperInfo $prow = null) {
        // empty tag optimization
        if ($tags === null || $tags === "") {
            return "";
        }

        // preserve all tags/show no tags optimization
        $view_most = $user->can_view_most_tags($prow);
        $allow_admin = $user->allow_administer($prow);
        $conflict_free = TagInfo::TF_CONFLICT_FREE | ($user->privChair ? TagInfo::TF_SITEWIDE : 0);
        if ($view_most) {
            if (($ctype === self::CENSOR_SEARCH && $allow_admin)
                || (($this->flags & TagInfo::TF_HIDDEN) === 0 && strpos($tags, "~") === false)) {
                return $tags;
            }
        } else {
            if (($this->flags & $conflict_free) === 0) {
                return "";
            }
        }

        // go tag by tag
        $strip_hidden = ($this->flags & TagInfo::TF_HIDDEN) !== 0
            && !$user->can_view_hidden_tags($prow);
        $my_uid = $user->contactId > 0 ? (string) $user->contactId : "";
        $my_tw = strlen($my_uid);
        $p = $ip = 0;
        $l = strlen($tags);
        while ($p < $l) {
            $np = strpos($tags, " ", $p + 1) ? : $l;
            $t = substr($tags, $p + 1, strpos($tags, "#", $p + 1) - $p - 1);
            $tw = strpos($t, "~");
            if ($tw === 0) {
                if (!$user->privChair) {
                    $ok = false;
                } else if ($view_most) {
                    $ok = true;
                } else {
                    $dt = $this->find($t);
                    $ok = $dt && ($dt->flags & $conflict_free) !== 0;
                }
            } else if ($tw !== false) {
                if ($tw === $my_tw
                    && str_starts_with($t, $my_uid)) {
                    $ok = true;
                } else if ($ctype === self::CENSOR_VIEW) {
                    $ok = false;
                } else if ($allow_admin && $view_most) {
                    $ok = true;
                } else if (($this->flags & TagInfo::TF_PUBLIC_PERUSER) === 0) {
                    $ok = false;
                } else {
                    $dt = $this->find(substr($t, $tw + 1));
                    $ok = $dt
                        && ($dt->flags & TagInfo::TF_PUBLIC_PERUSER) !== 0
                        && ($view_most
                            || ($dt->flags & $conflict_free) !== 0);
                }
            } else if (!$view_most) {
                $dt = $this->find($t);
                $ok = $dt
                    && (!$strip_hidden || ($dt->flags & TagInfo::TF_HIDDEN) !== 0)
                    && ($dt->flags & $conflict_free) !== 0;
            } else if ($strip_hidden) {
                $dt = $this->find($t);
                $ok = !$dt || ($dt->flags & TagInfo::TF_HIDDEN) === 0;
            } else {
                $ok = true;
            }
            if ($ok && $ip < $p) {
                $tags = substr($tags, 0, $ip) . substr($tags, $p);
                $l -= $p - $ip;
                $p = $ip = $np - ($p - $ip);
            } else if ($ok) {
                $p = $ip = $np;
            } else {
                $p = $np;
            }
        }
        return $ip < $p ? substr($tags, 0, $ip) : $tags;
    }

    /** @param array<string,mixed> &$tagmap */
    function ksort(&$tagmap) {
        uksort($tagmap, [$this->conf->collator(), "compare"]);
    }

    /** @param list<string> $tags
     * @return list<string> */
    function sort_array($tags) {
        if (count($tags) > 1) {
            $this->conf->collator()->sort($tags);
        }
        return $tags;
    }

    /** @param string $tags
     * @return string */
    function sort_string($tags) {
        // Prerequisite: self::assert_tag_string($tags)
        if ($tags !== "") {
            $tags = join(" ", $this->sort_array(explode(" ", $tags)));
        }
        return $tags;
    }

    const UNPARSE_HASH = 1;
    const UNPARSE_TEXT = 2;
    function unparse($tag, $value, Contact $viewer, $flags = 0) {
        $prefix = "";
        $suffix = $value ? "#{$value}" : "";
        $hash = ($flags & self::UNPARSE_HASH ? "#" : "");
        if (($twiddle = strpos($tag, "~")) > 0) {
            $cid = (int) substr($tag, 0, $twiddle);
            if ($cid !== 0 && $cid === $viewer->contactId) {
                $tag = substr($tag, $twiddle);
            } else if (($p = $viewer->conf->user_by_id($cid, USER_SLICE))) {
                if (($flags & self::UNPARSE_TEXT) !== 0) {
                    $tag = substr($tag, $twiddle);
                    return "{$hash}{$p->email}{$tag}{$suffix}";
                }
                $emailh = htmlspecialchars($p->email);
                if (($cc = $p->viewable_color_classes($viewer))) {
                    $prefix = "{$hash}<span class=\"{$cc} taghh\">{$emailh}</span>";
                    $hash = "";
                } else {
                    $hash .= $emailh;
                }
                $tag = substr($tag, $twiddle);
            }
        }
        if (($flags & self::UNPARSE_TEXT)
            || !($cc = $this->styles($tag))) {
            return "{$prefix}{$hash}{$tag}{$suffix}";
        } else {
            $ccs = join(" ", $cc);
            return "{$prefix}<span class=\"{$ccs} taghh\">{$hash}{$tag}{$suffix}</span>";
        }
    }


    private function merge_settings(Conf $conf) {
        foreach ($conf->track_tags() as $tn) {
            $this->set($tn, TagInfo::TF_TRACK | TagInfo::TF_CHAIR);
        }
        if ($conf->has_named_submission_rounds()) {
            foreach ($conf->submission_round_list() as $sr) {
                if ($sr->tag !== "") {
                    $this->set($sr->tag, TagInfo::TF_SCLASS | TagInfo::TF_CHAIR);
                }
            }
        }
        $ct = $conf->setting_data("tag_chair") ?? "";
        foreach (Tagger::split_unpack($ct) as $tv) {
            $this->set($tv[0], TagInfo::TF_READONLY);
        }
        $ct = $conf->setting_data("tag_hidden") ?? "";
        foreach (Tagger::split_unpack($ct) as $tv) {
            $this->set($tv[0], TagInfo::TF_HIDDEN);
        }
        $ct = $conf->setting_data("tag_sitewide") ?? "";
        foreach (Tagger::split_unpack($ct) as $tv) {
            $this->set($tv[0], TagInfo::TF_SITEWIDE);
        }
        $ct = $conf->setting_data("tag_conflict_free") ?? "";
        foreach (Tagger::split_unpack($ct) as $tv) {
            $this->set($tv[0], TagInfo::TF_CONFLICT_FREE);
        }
        $ppu = $conf->setting("tag_vote_private_peruser")
            || $conf->opt("secretPC");
        $ppuf = $ppu ? 0 : TagInfo::TF_PUBLIC_PERUSER;
        $vt = $conf->setting_data("tag_vote") ?? "";
        foreach (Tagger::split_unpack($vt) as $tv) {
            $ti = new TagInfo($tv[0], $this, TagInfo::TF_ALLOTMENT | TagInfo::TF_AUTOMATIC | $ppuf);
            $ti->allotment = ($tv[1] ?? 1.0);
            $this->merge($ti);
        }
        $vt = $conf->setting_data("tag_approval") ?? "";
        foreach (Tagger::split_unpack($vt) as $tv) {
            $this->set($tv[0], TagInfo::TF_APPROVAL | TagInfo::TF_AUTOMATIC | $ppuf);
        }
        $rt = $conf->setting_data("tag_rank") ?? "";
        foreach (Tagger::split_unpack($rt) as $tv) {
            $this->set($tv[0], TagInfo::TF_RANK | $ppuf);
        }
        $ct = $conf->setting_data("tag_color") ?? "";
        if ($ct !== "") {
            foreach (explode(" ", $ct) as $k) {
                if ($k !== ""
                    && ($p = strpos($k, "=")) > 0
                    && ($ks = $this->known_style(substr($k, $p + 1))) !== null) {
                    $ti = new TagInfo(substr($k, 0, $p), $this, TagInfo::TF_STYLE);
                    $ti->styles[] = $ks;
                    $this->merge($ti);
                }
            }
        }
        $bt = $conf->setting_data("tag_badge") ?? "";
        if ($bt !== "") {
            foreach (explode(" ", $bt) as $k) {
                if ($k !== ""
                    && ($p = strpos($k, "=")) > 0
                    && ($ks = $this->known_badge(substr($k, $p + 1))) !== null) {
                    $ti = new TagInfo(substr($k, 0, $p), $this, TagInfo::TF_BADGE);
                    $ti->badge = $ks;
                    $this->merge($ti);
                }
            }
        }
        $bt = $conf->setting_data("tag_emoji") ?? "";
        if ($bt !== "") {
            foreach (explode(" ", $bt) as $k) {
                if ($k !== "" && ($p = strpos($k, "=")) !== false) {
                    $ti = new TagInfo(substr($k, 0, $p), $this, TagInfo::TF_EMOJI);
                    $ti->emoji[] = substr($k, $p + 1);
                    $this->merge($ti);
                }
            }
        }
        $tx = $conf->setting_data("tag_autosearch") ?? "";
        if ($tx !== "") {
            foreach (json_decode($tx) ? : [] as $tag => $search) {
                $ti = new TagInfo($tag, $this, TagInfo::TF_AUTOMATIC | TagInfo::TF_AUTOSEARCH);
                $ti->autosearch = $search->q;
                $ti->autosearch_value = $search->v ?? null;
                $this->merge($ti);
            }
        }
    }

    private function merge_json($tag, $data) {
        $flags = 0;
        if ($data->chair ?? false) {
            $flags |= TagInfo::TF_CHAIR;
        }
        if ($data->readonly ?? false) {
            $flags |= TagInfo::TF_READONLY;
        }
        if ($data->hidden ?? false) {
            $flags |= TagInfo::TF_HIDDEN;
        }
        if ($data->sitewide ?? false) {
            $flags |= TagInfo::TF_SITEWIDE;
        }
        if ($data->conflict_free ?? false) {
            $flags |= TagInfo::TF_CONFLICT_FREE;
        }
        if ($data->autosearch ?? null) {
            $flags |= TagInfo::TF_AUTOMATIC | TagInfo::TF_AUTOSEARCH;
        }
        if ($data->style ?? $data->color /* XXX */ ?? null) {
            $flags |= TagInfo::TF_STYLE;
        }
        if ($data->badge ?? null) {
            $flags |= TagInfo::TF_BADGE;
        }
        if ($data->emoji ?? null) {
            $flags |= TagInfo::TF_EMOJI;
        }
        if ($flags === 0) {
            return;
        }
        $ti = new TagInfo($tag, $this, $flags);
        if (($flags & TagInfo::TF_AUTOSEARCH) !== 0) {
            $ti->autosearch = $data->autosearch;
            $ti->autosearch_value = $data->autosearch_value ?? null;
        }
        if (($flags & TagInfo::TF_STYLE) !== 0) {
            $x = $data->style ?? $data->color;
            foreach (is_string($x) ? [$x] : $x as $c) {
                if (($ks = $this->known_style($c)) !== null) {
                    $ti->styles[] = $ks;
                }
            }
        }
        if (($flags & TagInfo::TF_BADGE) !== 0) {
            $x = $data->badge;
            foreach (is_string($x) ? [$x] : $x as $c) {
                if (($ks = $this->known_badge($c)) !== null) {
                    $ti->badge = $ks;
                }
            }
        }
        if (($flags & TagInfo::TF_EMOJI) !== 0) {
            $x = $data->badge;
            foreach (is_string($x) ? [$x] : $x as $c) {
                $ti->emoji[] = $c;
            }
        }
        $this->merge($ti);
    }

    /** @param bool $all
     * @return TagMap */
    static function make(Conf $conf, $all) {
        $map = new TagMap($conf);
        if ($all) {
            $map->merge_settings($conf);
        }
        if (($od = $conf->opt("definedTags"))) {
            foreach (is_string($od) ? [$od] : $od as $ods) {
                foreach (json_decode($ods) as $tag => $data) {
                    $map->merge_json($tag, $data);
                }
            }
        }
        if ($conf->setting("has_colontag")) {
            $map->flags |= TagInfo::TF_EMOJI;
        }
        if (($t = $map->find("pc"))
            && ($t->styles || $t->badge || $t->emoji)) {
            $map->has_role_decoration = true;
        }
        return $map;
    }
}

class Tagger {
    const ALLOWRESERVED = 1;
    const NOPRIVATE = 2;
    const NOVALUE = 4;
    const NOCHAIR = 8;
    const ALLOWSTAR = 16;
    const ALLOWCONTACTID = 32;
    const ALLOWNONE = 64;
    const EEMPTY = -1;
    const EINVAL = -2;
    const EMULTIPLE = -3;
    const E2BIG = -4;
    const EFORMAT = -5;
    const ERANGE = -6;

    /** @var Conf */
    private $conf;
    /** @var Contact */
    private $contact;
    /** @var int */
    private $_contactId = 0;
    /** @var int */
    private $errcode = 0;
    /** @var ?string */
    private $errtag;

    /** @readonly */
    private static $value_increment_map = [1, 1, 1, 1, 1, 2, 2, 2, 3, 4];


    function __construct(Contact $contact) {
        $this->conf = $contact->conf;
        $this->contact = $contact;
        if ($contact->contactId > 0) {
            $this->_contactId = $contact->contactId;
        }
    }


    /** @param string $tv
     * @return string
     *
     * Given a tag string optionally including a value (`tag[#value]`),
     * return the `tag`. Does not handle initial `#`. */
    static function tv_tag($tv) {
        $pos = strpos($tv, "#");
        return $pos ? substr($tv, 0, $pos) : $tv;
    }

    /** @param string $tv
     * @return ?float
     *
     * Given a tag string optionally including a value (`tag[#value]`),
     * return the value, or `null` if there is no value. */
    static function tv_value($tv) {
        $pos = strpos($tv, "#");
        return $pos && $pos < strlen($tv) - 1 ? (float) substr($tv, $pos + 1) : null;
    }

    /** @param string $tv
     * @return array{false|string,?float} */
    static function unpack($tv) {
        if (!$tv) {
            return [false, null];
        } else if (!($pos = strpos($tv, "#"))) {
            return [$tv, null];
        } else if ($pos === strlen($tv) - 1) {
            return [substr($tv, 0, $pos), null];
        } else {
            return [substr($tv, 0, $pos), (float) substr($tv, $pos + 1)];
        }
    }

    /** @param string $tvlist
     * @return list<string> */
    static function split($tvlist) {
        preg_match_all('/[^\s,]+/', $tvlist, $m);
        return $m[0];
    }

    /** @param string $tvlist
     * @return list<array{false|string,?float}> */
    static function split_unpack($tvlist) {
        return array_map("Tagger::unpack", self::split($tvlist));
    }

    /** @param string $tag
     * @return bool */
    static function basic_check($tag) {
        return $tag !== "" && strlen($tag) <= TAG_MAXLEN
            && preg_match('{\A' . TAG_REGEX . '\z}', $tag);
    }

    /** @param bool $gapless */
    static function value_increment($gapless) {
        return $gapless ? 1 : self::$value_increment_map[mt_rand(0, 9)];
    }


    /** @param bool $verbose
     * @return ?string */
    function error_ftext($verbose = false) {
        $t = $verbose && $this->errtag ? " ‘{$this->errtag}’" : "";
        switch ($this->errcode) {
        case 0:
            return null;
        case self::EEMPTY:
            return "<0>Tag required";
        case self::EMULTIPLE:
            return "<0>Single tag required";
        case self::E2BIG:
            return "<0>Tag too long";
        case self::ERANGE:
            return "<0>Tag value out of range";
        case self::ALLOWSTAR:
            return "<0>Invalid tag{$t} (stars aren’t allowed here)";
        case self::NOCHAIR:
            if ($this->contact->privChair) {
                return "<0>Invalid tag{$t} (chair tags aren’t allowed here)";
            } else {
                return "<0>Tag{$t} reserved for chairs";
            }
        case self::NOPRIVATE:
            return "<0>Private tags aren’t allowed here";
        case self::ALLOWCONTACTID:
            if ($verbose && ($twiddle = strpos($this->errtag ?? "", "~"))) {
                return "<0>Invalid tag{$t} (did you mean ‘#" . substr($this->errtag, $twiddle) . "’?)";
            } else {
                return "<0>Invalid private tag";
            }
        case self::NOVALUE:
            return "<0>Tag values aren’t allowed here";
        case self::ALLOWRESERVED:
            return "<0>Tag{$t} reserved";
        case self::EFORMAT:
            return "<0>Format error";
        case self::EINVAL:
        default:
            return "<0>Invalid tag{$t}";
        }
    }

    /** @return int */
    function error_code() {
        return $this->errcode;
    }

    /** @param int $errcode
     * @param ?string $tag
     * @return false */
    private function set_error_code($errcode, $tag = null) {
        $this->errcode = $errcode;
        $this->errtag = $tag;
        return false;
    }

    /** @param ?string $tag
     * @param int $flags
     * @return string|false */
    function check($tag, $flags = 0) {
        if (($tag = $tag ?? "") !== "" && $tag[0] === "#") {
            $tag = substr($tag, 1);
        }
        if (($flags & self::ALLOWNONE) !== 0
            && ($tag === "" || strcasecmp($tag, "none") === 0)) {
            $this->errcode = 0;
            return "";
        } else if ($tag === "") {
            return $this->set_error_code(self::EEMPTY, $tag);
        }
        if (!$this->contact->privChair) {
            $flags |= self::NOCHAIR;
        }
        if (!preg_match('/\A(|~|~~|[1-9][0-9]*~)(' . TAG_REGEX_NOTWIDDLE . ')(|[#=](?:-?\d+(?:\.\d*)?|-?\.\d+|))\z/', $tag, $m)) {
            if (preg_match('/\A([-a-zA-Z0-9!@*_:.\/#=]+)[\s,]+\S+/', $tag, $m)
                && $this->check($m[1], $flags)) {
                return $this->set_error_code(self::EMULTIPLE, $tag);
            } else {
                return $this->set_error_code(self::EINVAL, $tag);
            }
        }
        if (($flags & self::ALLOWSTAR) === 0
            && strpos($tag, "*") !== false) {
            return $this->set_error_code(self::ALLOWSTAR, $tag);
        }
        if ($m[1] === "") {
            // OK
        } else if ($m[1] === "~~") {
            if (($flags & self::NOCHAIR) !== 0) {
                return $this->set_error_code(self::NOCHAIR, $tag);
            }
        } else {
            if (($flags & self::NOPRIVATE) !== 0) {
                return $this->set_error_code(self::NOPRIVATE, $tag);
            } else if ($m[1] === "~") {
                if ($this->_contactId) {
                    $m[1] = $this->_contactId . "~";
                }
            } else if ($m[1] !== $this->_contactId . "~"
                       && ($flags & self::ALLOWCONTACTID) === 0) {
                return $this->set_error_code(self::ALLOWCONTACTID, $tag);
            }
        }
        if ($m[3] !== "" && ($flags & self::NOVALUE) !== 0) {
            return $this->set_error_code(self::NOVALUE, $tag);
        }
        if (($flags & self::ALLOWRESERVED) === 0) {
            $l2 = strlen($m[2]);
            if (($l2 === 4 && strcasecmp($m[2], "none") === 0)
                || ($l2 === 3 && strcasecmp($m[2], "any") === 0)
                || ($l2 === 3 && strcasecmp($m[2], "all") === 0)
                || ($l2 === 9 && strcasecmp($m[2], "undefined") === 0)
                || ($l2 === 7 && strcasecmp($m[2], "default") === 0)) {
                return $this->set_error_code(self::ALLOWRESERVED, $tag);
            }
        }
        $t = $m[1] . $m[2];
        if (strlen($t) > TAG_MAXLEN) {
            return $this->set_error_code(self::E2BIG, $tag);
        }
        if ($m[3] !== "") {
            $t .= "#" . substr($m[3], 1);
        }
        $this->errcode = 0;
        return $t;
    }

    /** @param mixed $x
     * @param int $flags
     * @return ?list<array{string,?float}> */
    function check_json($x, $flags = 0) {
        if (is_string($x)) {
            $x = self::split($x);
        }
        if (!is_list($x)) {
            $this->set_error_code(self::EFORMAT);
            return null;
        }
        $errcode = 0;
        $tlist = [];
        foreach ($x as $v) {
            if (is_string($v)) {
                if (($tv = $this->check($v, $flags)) === false) {
                    continue;
                }
                $tx = self::unpack($tv);
                $tx[1] = $tx[1] ?? 0.0;
                $tlist[] = $tx;
            } else if (is_object($v)
                       && isset($v->tag)
                       && is_string($v->tag)) {
                if (($t = $this->check($v->tag, $flags | self::NOVALUE)) === false) {
                    continue;
                }
                $tx = [$t, 0.0];
                if (isset($v->value)) {
                    if (is_float($v->value)) {
                        $tx[1] = $v->value;
                    } else if (is_int($v->value)) {
                        $tx[1] = (float) $v->value;
                    } else {
                        $errcode = $errcode ? : self::ERANGE;
                        continue;
                    }
                }
            } else {
                $errcode = self::EFORMAT;
                continue;
            }
            if ($tx[1] > -TAG_INDEXBOUND && $tx[1] < TAG_INDEXBOUND) {
                $tlist[] = $tx;
            } else {
                $errcode = $errcode ? : self::ERANGE;
            }
        }
        $this->errcode = $errcode;
        return $tlist;
    }

    function expand($tag) {
        if (strlen($tag) > 2 && $tag[0] === "~" && $tag[1] !== "~" && $this->_contactId) {
            return $this->_contactId . $tag;
        } else {
            return $tag;
        }
    }


    /** @param list<string>|string $tags
     * @return string */
    function unparse($tags) {
        if ($tags === "" || (is_array($tags) && count($tags) == 0)) {
            return "";
        }
        if (is_array($tags)) {
            $tags = join(" ", $tags);
        }
        $tags = str_replace("#0 ", " ", " {$tags} ");
        if ($this->_contactId) {
            $tags = str_replace(" {$this->_contactId}~", " ~", $tags);
        }
        return trim($tags);
    }

    /** @param list<string>|string $tags
     * @return string */
    function unparse_hashed($tags) {
        if (($tags = $this->unparse($tags)) !== "") {
            $tags = str_replace(" ", " #", "#" . $tags);
        }
        return $tags;
    }

    /** @param string $e
     * @param float $count
     * @return string */
    static function unparse_emoji_html($e, $count) {
        $b = '<span class="tagemoji">';
        if ($count <= 0 || $count == 1) {
            $b .= $e;
        } else if ($count >= 5.0625) {
            $b .= str_repeat($e, 5) . "<sup>+</sup>";
        } else {
            $f = floor($count + 0.0625);
            $d = round(max($count - $f, 0) * 8);
            $b .= str_repeat($e, (int) $f);
            if ($d) {
                $b .= '<span class="tagemoji-fraction" style="width:' . ($d / 8) . 'em">' . $e . '</span>';
            }
        }
        return $b . '</span>';
    }

    const DECOR_PAPER = 0;
    const DECOR_USER = 1;

    /** @param list<string>|string $tags
     * @param int $type
     * @return string */
    function unparse_decoration_html($tags, $type = 0) {
        if (is_array($tags)) {
            $tags = join(" ", $tags);
        }
        if (!$tags || $tags === " ") {
            return "";
        }
        $dt = $this->conf->tags();
        $x = "";
        foreach ($dt->emoji($tags) as $e => $ts) {
            $count = 0;
            foreach ($ts as $t) {
                list($unused, $value) = Tagger::unpack($t);
                $count = max($count, (float) $value);
            }
            $b = self::unparse_emoji_html($e, $count);
            if ($type === self::DECOR_PAPER) {
                $q = htmlspecialchars("emoji:{$e}");
                $b = "<a href=\"\" class=\"q uic js-sq\" data-q=\"{$q}\">{$b}</a>";
            }
            if ($x === "") {
                $x = " ";
            }
            $x .= $b;
        }
        foreach ($dt->badges($tags) as $tb) {
            $klass = "badge badge-{$tb[1]}";
            if (str_starts_with($tb[1], "rgb-")) {
                TagMap::stash_ensure_pattern("badge-{$tb[1]}");
            }
            $tag = $this->unparse($tb[0]);
            if ($type === self::DECOR_PAPER && ($q = $this->js_sq($tag, false)) !== null) {
                $dq = $q === "" ? "" : " data-q=\"" . htmlspecialchars($q) . "\"";
                $b = "<a href=\"\" class=\"uic js-sq {$klass}\"{$dq}>#{$tag}</a>";
            } else {
                if ($type !== self::DECOR_USER) {
                    $tag = "#{$tag}";
                }
                $b = "<span class=\"{$klass}\">{$tag}</span>";
            }
            $x .= ' ' . $b;
        }
        return $x === "" ? "" : "<span class=\"tagdecoration\">{$x}</span>";
    }

    /** @param string $tv
     * @return string|false */
    function link_base($tv) {
        if (ctype_digit($tv[0])) {
            $p = strlen((string) $this->_contactId);
            if (substr($tv, 0, $p) != $this->_contactId || $tv[$p] !== "~") {
                return false;
            }
            $tv = substr($tv, $p);
        }
        return Tagger::tv_tag($tv);
    }

    /** @param string $tv
     * @param bool $always
     * @return ?string */
    private function js_sq($tv, $always) {
        if (ctype_digit($tv[0])) {
            $p = strlen((string) $this->_contactId);
            if (substr($tv, 0, $p) != $this->_contactId || $tv[$p] !== "~") {
                return null;
            }
            $tv = substr($tv, $p);
        }
        $base = Tagger::tv_tag($tv);
        $dt = $this->conf->tags();
        if ($dt->has(TagInfo::TFM_VOTES)
            && ($dt->is_votish($base)
                || ($base[0] === "~" && $dt->is_allotment(substr($base, 1))))) {
            return "#{$base} showsort:-#{$base}";
        } else if (!$always) {
            return "";
        } else if ($base === $tv) {
            $q = "#{$base}";
        } else {
            $q = "order:#{$base}";
        }
    }

    /** @param list<string>|string $viewable
     * @return string */
    function unparse_link($viewable) {
        $tags = $this->unparse($viewable);
        if ($tags === "") {
            return "";
        }

        // decorate with URL matches
        $dt = $this->conf->tags();
        $tt = [];
        foreach (preg_split('/\s+/', $tags) as $tv) {
            if (!($base = Tagger::tv_tag($tv))) {
                continue;
            }
            $q = $this->js_sq($tv, false);
            if ($q === null) {
                $tx = "#{$tv}";
            } else {
                $tsuf = substr($tv, strlen($base));
                $dq = $q === "" ? "" : " data-q=\"" . htmlspecialchars($q) . "\"";
                $tx = "<a href=\"\" class=\"qo ibw uic js-sq\"{$dq}><u class=\"x\">#{$base}</u>{$tsuf}</a>";
            }
            if (($cc = $dt->styles($base))) {
                $ccs = join(" ", $cc);
                $tx = "<span class=\"{$ccs} taghh\">{$tx}</span>";
            }
            $tt[] = $tx;
        }
        return join(" ", $tt);
    }
}
