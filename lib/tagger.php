<?php
// tagger.php -- HotCRP helper class for dealing with tags
// Copyright (c) 2006-2023 Eddie Kohler; see LICENSE.

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
        $j["annoid"] = $this->annoId;
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
    /** @var string */
    public $name;
    /** @var string */
    public $style;
    /** @var int */
    public $sclass;
    /** @var ?bool */
    private $dark;
    /** @var ?OklchColor */
    private $oklch;

    const DYNAMIC = 1;
    const UNLISTED = 2;
    const BADGE = 4;
    const BG = 8;
    const TEXT = 16;
    const STYLE = 24; // BG | TEXT

    // see also style.css
    const KNOWN_COLORS = " red:ffd8d8 orange:fdebcc yellow:fdffcb green:d8ffd8 blue:d8d8ff purple:f2d8f8 gray:e2e2e2 white:ffffff";

    /** @param string $s
     * @return ?string */
    static function dynamic_style($s) {
        if (str_starts_with($s, "rgb-")
            && (strlen($s) === 7 || strlen($s) === 10)
            && ctype_xdigit(substr($s, 4))) {
            if (strlen($s) === 7) {
                return "rgb-{$s[4]}{$s[4]}{$s[5]}{$s[5]}{$s[6]}{$s[6]}";
            } else {
                return $s;
            }
        } else if (str_starts_with($s, "text-rgb-")
                   && (strlen($s) === 12 || strlen($s) === 15)
                   && ctype_xdigit(substr($s, 9))) {
            if (strlen($s) === 12) {
                return "text-rgb-{$s[9]}{$s[9]}{$s[10]}{$s[10]}{$s[11]}{$s[11]}";
            } else {
                return $s;
            }
        } else if (str_starts_with($s, "font-")
                   || str_starts_with($s, "weight-")) {
            return $s;
        } else {
            return null;
        }
    }

    /** @param string $text */
    function __construct($text) {
        // $text format: [name=]style[^][@][#][-][*]
        // ^ text, # background, @ badge (default is background);
        // - means unlisted (do not show in settings by default);
        // * means dark mode (light mode by default unless dynamic)
        $sclass = 0;
        $p0 = 0;
        $p1 = strlen($text);
        while (true) {
            $lch = $text[$p1 - 1];
            if ($lch === "^") {
                $sclass |= self::TEXT;
            } else if ($lch === "#") {
                $sclass |= self::BG;
            } else if ($lch === "@") {
                $sclass |= self::BADGE;
            } else if ($lch === "-") {
                $sclass |= self::UNLISTED;
            } else if ($lch === "*") {
                $this->dark = true;
            } else {
                break;
            }
            --$p1;
        }
        if (($eq = strpos($text, "=")) !== false) {
            $this->name = substr($text, 0, $eq);
            $p0 = $eq + 1;
        }
        $this->style = substr($text, $p0, $p1 - $p0);
        $this->name = $this->name ?? $this->style;
        if ($p1 === strlen($text)
            && ($dstyle = self::dynamic_style($this->style)) !== null) {
            $this->style = $dstyle;
            $sclass |= self::DYNAMIC;
            if ($dstyle[0] === "r") { // `rgb-`
                $sclass |= self::BG | self::BADGE;
            } else {
                $sclass |= self::TEXT;
            }
        }
        if (($sclass & (self::STYLE | self::BADGE)) === 0) {
            $sclass |= self::BG;
        }
        $this->sclass = $sclass;
        if ($this->dark === null
            && (($sclass & self::DYNAMIC) === 0 || ($sclass & self::BG) === 0)) {
            $this->dark = false;
        }
    }

    /** @return bool */
    function dark() {
        if ($this->dark === null) {
            $rgb = intval(substr($this->style, 4), 16);
            $r = $rgb >> 16;
            $g = ($rgb >> 8) & 255;
            $b = $rgb & 255;
            $rx = $r <= 10.31475 ? $r / 3294.60 : pow(($r + 14.025) / 269.025, 2.4);
            $gx = $g <= 10.31475 ? $g / 3294.60 : pow(($g + 14.025) / 269.025, 2.4);
            $bx = $b <= 10.31475 ? $b / 3294.60 : pow(($b + 14.025) / 269.025, 2.4);
            $l = 0.2126 * $rx + 0.7152 * $gx + 0.0722 * $bx;
            $this->dark = $l < 0.3;
        }
        return $this->dark;
    }

    /** @return ?OklchColor
     * @suppress PhanParamSuspiciousOrder */
    function oklch() {
        if (($this->sclass & self::BG) === 0) {
            return null;
        }
        if ($this->oklch === null) {
            if (($this->sclass & self::DYNAMIC) !== 0) {
                $rgb = intval(substr($this->style, 4), 16);
            } else if (($p = strpos(self::KNOWN_COLORS, " {$this->style}:")) !== false) {
                $rgb = intval(substr(self::KNOWN_COLORS, $p + 2 + strlen($this->style), 6), 16);
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

        $known_styles = ["black@ red@# orange@# yellow@# green@# blue@# purple@# gray@# white@# pink@ bold^ italic^ underline^ strikethrough^ big^ small^ dim^ violet=purple@# grey=gray@# normal=black@ default=black@"];
        $opt = $conf->opt("tagKnownStyles") ?? null;
        if (!empty($opt)) {
            $known_styles = array_merge($known_styles, is_array($opt) ? $opt : [$opt]);
        }
        foreach ($known_styles as $ks) {
            foreach (explode(" ", $ks) as $s) {
                if ($s !== "") {
                    $ts = new TagStyle($s);
                    $this->style_lmap[$ts->name] = $ts;
                }
            }
        }
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
    private function update_patterns($tag, $ltag, TagInfo $ti = null) {
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
                || (str_starts_with($ltag, "rgb-") && ctype_xdigit(substr($ltag, 4)))
                || (str_starts_with($ltag, "text-rgb-") && ctype_xdigit(substr($ltag, 9)))
                || str_starts_with($ltag, "font-")
                || str_starts_with($ltag, "weight-"))) {
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


    /** @param string $s
     * @param 4|8|12|16|20|24|28 $sclassmatch
     * @return ?TagStyle */
    function known_style($s, $sclassmatch = TagStyle::STYLE) {
        $s = strtolower($s);
        $ks = $this->style_lmap[$s] ?? null;
        if ($ks === null
            && ($dstyle = TagStyle::dynamic_style($s)) !== null) {
            $ks = new TagStyle($dstyle);
        }
        if ($ks && ($ks->sclass & $sclassmatch) !== 0) {
            return $ks;
        } else {
            return null;
        }
    }

    /** @param string $s
     * @return ?TagStyle */
    function known_badge($s) {
        return $this->known_style($s, TagStyle::BADGE);
    }

    /** @param 4|8|12|16|20|24|28 $sclassmatch
     * @return list<TagStyle> */
    function canonical_listed_styles($sclassmatch) {
        $kss = [];
        foreach ($this->style_lmap as $ltag => $ks) {
            if (($ks->sclass & $sclassmatch) !== 0
                && ($ks->sclass & TagStyle::UNLISTED) === 0
                && $ks->style === $ltag)
                $kss[] = $ks;
        }
        return $kss;
    }


    /** @return string */
    private function color_regex() {
        if (!$this->color_re) {
            $rex = [
                "{(?:\\A| )(?:(?:\\d*~|~~|)(font-[^\s#]+|weight-(?:[a-z]+|\d+)|(?:text-|)rgb-[0-9a-f]{3}(?:|[0-9a-f]{3})"
            ];
            foreach ($this->style_lmap as $style => $ks) {
                if (($ks->sclass & TagStyle::STYLE) !== 0)
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
     * @param 0|8|16|24 $sclassmatch
     * @return list<TagStyle> */
    function unique_tagstyles($tags, $sclassmatch = 0) {
        if (is_array($tags)) {
            $tags = join(" ", $tags);
        }
        if (!$tags
            || $tags === " "
            || !preg_match_all($this->color_regex(), $tags, $ms)) {
            return [];
        }
        $sclassmatch = $sclassmatch ? : TagStyle::STYLE;
        $kss = [];
        $sclass = 0;
        foreach ($ms[1] as $m) {
            $t = $this->find(strtolower($m));
            if ($t === null || empty($t->styles)) {
                continue;
            }
            foreach ($t->styles as $ks) {
                if (($ks->sclass & $sclassmatch) !== 0
                    && !in_array($ks, $kss))
                    $kss[] = $ks;
            }
        }
        return $kss;
    }

    /** @param string|list<string> $tags
     * @param 0|8|16|24 $sclassmatch
     * @param bool $no_ensure_pattern
     * @return ?list<string> */
    function styles($tags, $sclassmatch = 0, $no_ensure_pattern = false) {
        $kss = $this->unique_tagstyles($tags, $sclassmatch);
        if (empty($kss)) {
            return null;
        }
        $classes = [];
        $sclass = $nbg = $ndarkbg = 0;
        foreach ($kss as $ks) {
            $classes[] = "tag-{$ks->style}";
            $sclass |= $ks->sclass;
            if (($ks->sclass & TagStyle::BG) !== 0) {
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
            && ($sclass & TagStyle::BG) !== 0
            && (($sclass & TagStyle::DYNAMIC) !== 0 || count($classes) > 2)) {
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
    function censor($ctype, $tags, Contact $user, PaperInfo $prow = null) {
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
        $mine_tw = $user->contactId > 0 ? strlen((string) $user->contactId) : 0;
        $p = 0;
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
                if ($tw === $mine_tw
                    && str_starts_with($t, (string) $user->contactId)) {
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
            if ($ok) {
                $p = $np;
            } else {
                $tags = substr($tags, 0, $p) . substr($tags, $np);
                $l -= $np - $p;
            }
        }

        return $tags;
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
    const EEMPTY = -1;
    const EINVAL = -2;
    const EMULTIPLE = -3;
    const E2BIG = -4;

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
     * @return string
     * @deprecated */
    static function base($tv) {
        return self::tv_tag($tv);
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
        preg_match_all('/\S+/', $tvlist, $m);
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
        case self::ALLOWSTAR:
            return "<0>Invalid tag{$t} (stars aren’t allowed here)";
        case self::NOCHAIR:
            if ($this->contact->privChair) {
                return "<0>Invalid tag{$t} (chair tags aren’t allowed here)";
            } else {
                return "<0>Invalid tag{$t} (tag reserved for chair)";
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
        case self::EINVAL:
        default:
            return "<0>Invalid tag{$t}";
        }
    }

    /** @return int */
    function error_code() {
        return $this->errcode;
    }

    /** @return false */
    private function set_error_code($tag, $errcode) {
        $this->errcode = $errcode;
        $this->errtag = $tag;
        return false;
    }

    /** @param ?string $tag
     * @param int $flags
     * @return string|false */
    function check($tag, $flags = 0) {
        if ($tag === null || $tag === "" || $tag === "#") {
            return $this->set_error_code($tag, self::EEMPTY);
        }
        if (!$this->contact->privChair) {
            $flags |= self::NOCHAIR;
        }
        if ($tag[0] === "#") {
            $tag = substr($tag, 1);
        }
        if (!preg_match('/\A(|~|~~|[1-9][0-9]*~)(' . TAG_REGEX_NOTWIDDLE . ')(|[#=](?:-?\d+(?:\.\d*)?|-?\.\d+|))\z/', $tag, $m)) {
            if (preg_match('/\A([-a-zA-Z0-9!@*_:.\/#=]+)[\s,]+\S+/', $tag, $m)
                && $this->check($m[1], $flags)) {
                return $this->set_error_code($tag, self::EMULTIPLE);
            } else {
                return $this->set_error_code($tag, self::EINVAL);
            }
        }
        if (($flags & self::ALLOWSTAR) === 0
            && strpos($tag, "*") !== false) {
            return $this->set_error_code($tag, self::ALLOWSTAR);
        }
        if ($m[1] === "") {
            // OK
        } else if ($m[1] === "~~") {
            if (($flags & self::NOCHAIR) !== 0) {
                return $this->set_error_code($tag, self::NOCHAIR);
            }
        } else {
            if (($flags & self::NOPRIVATE) !== 0) {
                return $this->set_error_code($tag, self::NOPRIVATE);
            } else if ($m[1] === "~") {
                if ($this->_contactId) {
                    $m[1] = $this->_contactId . "~";
                }
            } else if ($m[1] !== $this->_contactId . "~"
                       && ($flags & self::ALLOWCONTACTID) === 0) {
                return $this->set_error_code($tag, self::ALLOWCONTACTID);
            }
        }
        if ($m[3] !== "" && ($flags & self::NOVALUE) !== 0) {
            return $this->set_error_code($tag, self::NOVALUE);
        }
        if (($flags & self::ALLOWRESERVED) === 0) {
            $l2 = strlen($m[2]);
            if (($l2 === 4 && strcasecmp($m[2], "none") === 0)
                || ($l2 === 3 && strcasecmp($m[2], "any") === 0)
                || ($l2 === 3 && strcasecmp($m[2], "all") === 0)
                || ($l2 === 9 && strcasecmp($m[2], "undefined") === 0)
                || ($l2 === 7 && strcasecmp($m[2], "default") === 0)) {
                return $this->set_error_code($tag, self::ALLOWRESERVED);
            }
        }
        $t = $m[1] . $m[2];
        if (strlen($t) > TAG_MAXLEN) {
            return $this->set_error_code($tag, self::E2BIG);
        }
        if ($m[3] !== "") {
            $t .= "#" . substr($m[3], 1);
        }
        $this->errcode = 0;
        return $t;
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
        $tags = str_replace("#0 ", " ", " $tags ");
        if ($this->_contactId) {
            $tags = str_replace(" " . $this->_contactId . "~", " ~", $tags);
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
                $url = $this->conf->hoturl("search", ["q" => "emoji:{$e}"]);
                $b = "<a class=\"q\" href=\"{$url}\">{$b}</a>";
            }
            if ($x === "") {
                $x = " ";
            }
            $x .= $b;
        }
        foreach ($dt->badges($tags) as $tb) {
            $klass = " class=\"badge badge-{$tb[1]}\"";
            if (str_starts_with($tb[1], "rgb-")) {
                TagMap::stash_ensure_pattern("badge-{$tb[1]}");
            }
            $tag = $this->unparse($tb[0]);
            if ($type === self::DECOR_PAPER && ($link = $this->link($tag))) {
                $b = "<a href=\"{$link}\"{$klass}>#{$tag}</a>";
            } else {
                if ($type !== self::DECOR_USER) {
                    $tag = "#{$tag}";
                }
                $b = "<span{$klass}>{$tag}</span>";
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
     * @param int $flags
     * @return string|false */
    function link($tv, $flags = 0) {
        if (ctype_digit($tv[0])) {
            $p = strlen((string) $this->_contactId);
            if (substr($tv, 0, $p) != $this->_contactId || $tv[$p] !== "~") {
                return false;
            }
            $tv = substr($tv, $p);
        }
        $base = Tagger::tv_tag($tv);
        $dt = $this->conf->tags();
        if ($dt->has(TagInfo::TFM_VOTES)
            && ($dt->is_votish($base)
                || ($base[0] === "~" && $dt->is_allotment(substr($base, 1))))) {
            $q = "#{$base} showsort:-#{$base}";
        } else if ($base === $tv) {
            $q = "#{$base}";
        } else {
            $q = "order:#{$base}";
        }
        return $this->conf->hoturl("search", ["q" => $q], $flags);
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
            if (($link = $this->link($tv))) {
                $tsuf = substr($tv, strlen($base));
                $tx = "<a class=\"qo ibw\" href=\"{$link}\"><u class=\"x\">#{$base}</u>{$tsuf}</a>";
            } else {
                $tx = "#{$tv}";
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
