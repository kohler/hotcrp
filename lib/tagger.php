<?php
// tagger.php -- HotCRP helper class for dealing with tags
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

// Note that tags MUST NOT contain HTML or URL special characters:
// no "'&<>.  If you add PHP-protected characters, such as $, make sure you
// check for uses of eval().

class TagInfo {
    /** @var string */
    public $tag;
    /** @var Conf */
    public $conf;
    /** @var false|string */
    public $pattern = false;
    /** @var bool */
    public $pattern_instance = false;
    /** @var int */
    public $pattern_version = 0;
    /** @var bool */
    public $is_private = false;
    /** @var bool */
    public $chair = false;
    /** @var bool */
    public $readonly = false;
    /** @var bool */
    public $hidden = false;
    /** @var bool */
    public $track = false;
    /** @var bool */
    public $votish = false;
    /** @var bool */
    public $approval = false;
    /** @var false|float */
    public $allotment = false;
    /** @var bool */
    public $sitewide = false;
    /** @var bool */
    public $conflict_free = false;
    /** @var bool */
    public $rank = false;
    /** @var bool */
    public $public_peruser = false;
    /** @var bool */
    public $automatic = false;
    /** @var bool */
    public $order_anno = false;
    /** @var ?list<TagAnno> */
    private $_order_anno_list;
    /** @var int */
    private $_order_anno_search = 0;
    /** @var ?list<string> */
    public $colors;
    /** @var bool */
    public $basic_color = false;
    /** @var ?list<string> */
    public $badges;
    /** @var ?list<string> */
    public $emoji;
    /** @var ?string */
    public $autosearch;
    /** @var ?string */
    public $autosearch_value;
    /** @param string $tag */
    function __construct($tag, TagMap $tagmap) {
        $this->conf = $tagmap->conf;
        $this->set_tag($tag, $tagmap);
    }
    /** @param string $tag */
    function set_tag($tag, TagMap $tagmap) {
        $this->tag = $tag;
        if (($color = $tagmap->known_style($tag))) {
            $this->colors[] = $color;
            $this->basic_color = true;
        }
        if ($tag[0] === "~") {
            if ($tag[1] !== "~") {
                $this->is_private = true;
            } else {
                $this->chair = true;
            }
        }
    }
    function merge(TagInfo $t) {
        foreach (["chair", "readonly", "hidden", "track", "votish", "allotment", "approval", "sitewide", "conflict_free", "rank", "public_peruser", "automatic", "autosearch", "autosearch_value"] as $property) {
            if ($t->$property)
                $this->$property = $t->$property;
        }
        foreach (["colors", "badges", "emoji"] as $property) {
            if ($t->$property)
                $this->$property = array_unique(array_merge($this->$property ?? [], $t->$property));
        }
    }
    /** @return string */
    function tag_regex() {
        $t = preg_quote($this->tag);
        if ($this->pattern) {
            $t = str_replace("\\*", "[^\\s#]*", $t);
        }
        if ($this->is_private) {
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
            usort($this->_order_anno_list, function ($a, $b) {
                return $a->tagIndex <=> $b->tagIndex
                    ? : (strcasecmp($a->heading, $b->heading)
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
        $i = $this->_order_anno_search;
        if ($i > 0 && $tagIndex < $ol[$i - 1]->tagIndex) {
            $i = 0;
        }
        while ($tagIndex >= $ol[$i]->tagIndex) {
            ++$i;
        }
        $this->_order_anno_search = $i;
        return $i ? $ol[$i - 1] : null;
    }
    /** @return bool */
    function has_order_anno() {
        return count($this->order_anno_list()) > 1;
    }
    /** @return ?string */
    function automatic_search() {
        if ($this->autosearch) {
            return $this->autosearch;
        } else if ($this->votish) {
            return "#*~" . $this->tag;
        } else {
            return null;
        }
    }
    /** @return ?string */
    function automatic_formula_expression() {
        if ($this->autosearch) {
            return $this->autosearch_value ?? "0";
        } else if ($this->approval) {
            return "count.pc(#_~{$this->tag}) || null";
        } else if ($this->allotment) {
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
    /** @var float */
    public $tagIndex;
    /** @var ?string */
    public $heading;
    /** @var ?int */
    public $annoFormat;
    public $infoJson;

    /** @var int */
    public $annoIndex;      // index in array
    /** @var ?float */
    public $endTagIndex;    // tagIndex of next anno
    /** @var ?int */
    public $pos;
    /** @var ?int */
    public $count;

    /** @return bool */
    function is_empty() {
        return $this->heading === null || strcasecmp($this->heading, "none") === 0;
    }
    /** @return bool */
    function is_fencepost() {
        return $this->tagIndex >= (float) TAG_INDEXBOUND;
    }
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
        if ($this->is_empty()) {
            $j["empty"] = true;
        }
        if ($this->heading !== null) {
            $j["legend"] = $this->heading; // XXX "heading" backward compat
        }
        if ($this->heading !== null
            && $this->heading !== ""
            && ($format = Conf::$main->check_format($this->annoFormat, $this->heading))) {
            $j["format"] = +$format;
        }
        return $j;
    }
}

class TagMap implements IteratorAggregate {
    /** @var Conf */
    public $conf;
    /** @var bool */
    public $has_pattern = false;
    /** @var bool */
    public $has_chair = true;
    /** @var bool */
    public $has_readonly = true;
    /** @var bool */
    public $has_track = true;
    /** @var bool */
    public $has_hidden = false;
    /** @var bool */
    public $has_public_peruser = false;
    /** @var bool */
    public $has_votish = false;
    /** @var bool */
    public $has_approval = false;
    /** @var bool */
    public $has_allotment = false;
    /** @var bool */
    public $has_sitewide = false;
    /** @var bool */
    public $has_conflict_free = false;
    /** @var bool */
    public $has_rank = false;
    /** @var bool */
    public $has_colors = false;
    /** @var bool */
    public $has_badges = false;
    /** @var bool */
    public $has_emoji = false;
    /** @var bool */
    public $has_decoration = false;
    /** @var bool */
    public $has_order_anno = false;
    /** @var bool */
    public $has_automatic = false;
    /** @var bool */
    public $has_autosearch = false;
    /** @var bool */
    public $has_role_decoration = false;
    /** @var array<string,TagInfo> */
    private $storage = [];
    /** @var bool */
    private $sorted = false;
    /** @var ?string */
    private $pattern_re;
    /** @var list<TagInfo> */
    private $pattern_storage = [];
    /** @var int */
    private $pattern_version = 0; // = count($pattern_storage)
    /** @var ?string */
    private $color_re;
    /** @var ?string */
    private $badge_re;
    /** @var ?string */
    private $emoji_re;

    const STYLE_FG = 1;
    const STYLE_BG = 2;
    const STYLE_FG_BG = 3;
    const STYLE_SYNONYM = 4;
    /** @var array<string,int> */
    private $style_info_lmap = [];
    /** @var array<string,string> */
    private $canonical_style_lmap = [];
    /** @var string */
    private $basic_badges;

    private static $multicolor_map = [];

    function __construct(Conf $conf) {
        $this->conf = $conf;

        $basic_colors = "red&|orange&|yellow&|green&|blue&|purple&|violet=purple|gray&|grey=gray|white&|bold|italic|underline|strikethrough|big|small|dim";
        if (($o = $conf->opt("tagBasicColors"))) {
            if (str_starts_with($o, "|")) {
                $basic_colors .= $o;
            } else {
                $basic_colors = $o;
            }
        }
        preg_match_all('/([a-z@_.][-a-z0-9!@_:.\/]*)(\&?)(?:=([a-z@_.][-a-z0-9!@_:.\/]*))?/', strtolower($basic_colors), $ms, PREG_SET_ORDER);
        foreach ($ms as $m) {
            $m[3] = isset($m[3]) ? $m[3] : $m[1];
            while (isset($this->style_info_lmap[$m[3]])
                   && ($this->style_info_lmap[$m[3]] & self::STYLE_SYNONYM)) {
                $m[3] = $this->canonical_style_lmap[$m[3]];
            }
            if ($m[3] !== $m[1] && isset($this->style_info_lmap[$m[3]])) {
                $this->style_info_lmap[$m[1]] = $this->style_info_lmap[$m[3]] | self::STYLE_SYNONYM;
                $this->canonical_style_lmap[$m[1]] = $m[3];
            } else {
                $this->style_info_lmap[$m[1]] = $m[2] ? self::STYLE_BG : self::STYLE_FG;
                $this->canonical_style_lmap[$m[1]] = $m[1];
            }
        }

        $this->basic_badges = "normal|red|orange|yellow|green|blue|purple|white|pink|gray";
        if (($o = $conf->opt("tagBasicBadges"))) {
            if (str_starts_with($o, "|")) {
                $this->basic_badges .= $o;
            } else {
                $this->basic_badges = $o;
            }
        }
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
    /** @return ?TagInfo */
    private function update_patterns($tag, $ltag, TagInfo $t = null) {
        if (!$this->pattern_re) {
            $a = [];
            foreach ($this->pattern_storage as $p) {
                $a[] = strtolower($p->tag_regex());
            }
            $this->pattern_re = "{\A(?:" . join("|", $a) . ")\z}";
        }
        if (preg_match($this->pattern_re, $ltag)) {
            $version = $t ? $t->pattern_version : 0;
            foreach ($this->pattern_storage as $i => $p) {
                if ($i >= $version && preg_match($p->pattern, $ltag)) {
                    if (!$t) {
                        $t = clone $p;
                        $t->set_tag($tag, $this);
                        $t->pattern = false;
                        $t->pattern_instance = true;
                        $this->storage[$ltag] = $t;
                        $this->sorted = false;
                    } else {
                        $t->merge($p);
                    }
                }
            }
        }
        if ($t) {
            $t->pattern_version = $this->pattern_version;
        }
        return $t;
    }
    /** @param string $tag
     * @return ?TagInfo */
    function check($tag) {
        $ltag = strtolower($tag);
        $t = $this->storage[$ltag] ?? null;
        if (!$t && $ltag && $ltag[0] === ":" && $this->check_emoji_code($ltag)) {
            $t = $this->add($tag);
        }
        if ($this->has_pattern
            && (!$t || $t->pattern_version < $this->pattern_version)) {
            $t = $this->update_patterns($tag, $ltag, $t);
        }
        return $t;
    }
    /** @param string $tag
     * @return ?TagInfo */
    function check_base($tag) {
        return $this->check(Tagger::base($tag));
    }
    /** @param string $tag
     * @return TagInfo */
    function add($tag) {
        $ltag = strtolower($tag);
        $t = $this->storage[$ltag] ?? null;
        if (!$t) {
            $t = new TagInfo($tag, $this);
            if (!Tagger::basic_check($ltag)) {
                return $t;
            }
            $this->storage[$ltag] = $t;
            $this->sorted = false;
            if ($ltag[0] === ":" && ($e = $this->check_emoji_code($ltag))) {
                $t->emoji[] = $e;
                $this->has_emoji = $this->has_decoration = true;
            }
            if (strpos($ltag, "*") !== false) {
                $t->pattern = "{\A" . strtolower(str_replace("\\*", "[^\\s#]*", $t->tag_regex())) . "\z}";
                $this->has_pattern = true;
                $this->pattern_storage[] = $t;
                $this->pattern_re = null;
                ++$this->pattern_version;
            }
        }
        if ($this->has_pattern
            && !$t->pattern
            && $t->pattern_version < $this->pattern_version) {
            $t = $this->update_patterns($tag, $ltag, $t);
            '@phan-var TagInfo $t';
        }
        return $t;
    }
    private function sort_storage() {
        ksort($this->storage);
        $this->sorted = true;
    }
    #[\ReturnTypeWillChange]
    function getIterator() {
        $this->sorted || $this->sort_storage();
        return new ArrayIterator($this->storage);
    }
    /** @param string $property
     * @return array<string,TagInfo> */
    function filter($property) {
        $x = [];
        if ($this->{"has_{$property}"}) {
            $this->sorted || $this->sort_storage();
            foreach ($this->storage as $k => $t) {
                if ($t->$property)
                    $x[$k] = $t;
            }
        }
        return $x;
    }
    /** @param callable $f
     * @return array<string,TagInfo> */
    function filter_by($f) {
        $this->sorted || $this->sort_storage();
        return array_filter($this->storage, $f);
    }
    /** @param string $tag
     * @param non-empty-string $property
     * @return ?TagInfo */
    function check_property($tag, $property) {
        $k = "has_{$property}";
        return $this->$k
            && ($t = $this->check(Tagger::base($tag)))
            && $t->$property
            ? $t : null;
    }


    /** @param string $tag
     * @return bool */
    function is_chair($tag) {
        if ($tag[0] === "~") {
            return $tag[1] === "~";
        } else {
            return !!$this->check_property($tag, "chair");
        }
    }
    /** @param string $tag
     * @return bool */
    function is_readonly($tag) {
        return !!$this->check_property($tag, "readonly");
    }
    /** @param string $tag
     * @return bool */
    function is_track($tag) {
        return !!$this->check_property($tag, "track");
    }
    /** @param string $tag
     * @return bool */
    function is_hidden($tag) {
        return !!$this->check_property($tag, "hidden");
    }
    /** @param string $tag
     * @return bool */
    function is_sitewide($tag) {
        return !!$this->check_property($tag, "sitewide");
    }
    /** @param string $tag
     * @return bool */
    function is_conflict_free($tag) {
        return !!$this->check_property($tag, "conflict_free");
    }
    /** @param string $tag
     * @return bool */
    function is_public_peruser($tag) {
        return !!$this->check_property($tag, "public_peruser");
    }
    /** @param string $tag
     * @return bool */
    function is_votish($tag) {
        return !!$this->check_property($tag, "votish");
    }
    /** @param string $tag
     * @return bool */
    function is_allotment($tag) {
        return !!$this->check_property($tag, "allotment");
    }
    /** @param string $tag
     * @return bool */
    function is_approval($tag) {
        return !!$this->check_property($tag, "approval");
    }
    /** @param string $tag
     * @return string|false */
    function votish_base($tag) {
        if (!$this->has_votish
            || ($twiddle = strpos($tag, "~")) === false) {
            return false;
        }
        $tbase = substr(Tagger::base($tag), $twiddle + 1);
        $t = $this->check($tbase);
        return $t && $t->votish ? $tbase : false;
    }
    /** @param string $tag
     * @return bool */
    function is_rank($tag) {
        return !!$this->check_property($tag, "rank");
    }
    /** @param string $tag
     * @return bool */
    function is_emoji($tag) {
        return !!$this->check_property($tag, "emoji");
    }
    /** @param string $tag
     * @return bool */
    function is_automatic($tag) {
        return !!$this->check_property($tag, "automatic");
    }
    /** @param string $tag
     * @return bool */
    function is_autosearch($tag) {
        return !!$this->check_property($tag, "autosearch");
    }



    /** @return list<string> */
    function known_styles() {
        return array_keys($this->style_info_lmap);
    }
    /** @return ?string */
    function known_style($tag) {
        return $this->canonical_style_lmap[strtolower($tag)] ?? null;
    }
    /** @param string $tag
     * @return bool */
    function is_known_style($tag, $match = self::STYLE_FG_BG) {
        return (($this->style_info_lmap[strtolower($tag)] ?? 0) & $match) !== 0;
    }
    /** @param string $tag
     * @return bool */
    function is_style($tag, $match = self::STYLE_FG_BG) {
        $ltag = strtolower($tag);
        if (($t = $this->check($ltag))) {
            foreach ($t->colors ? : [] as $k) {
                if ($this->style_info_lmap[$k] & $match)
                    return true;
            }
            return false;
        } else {
            return (($this->style_info_lmap[$ltag] ?? 0) & $match) !== 0;
        }
    }

    /** @return string */
    private function color_regex() {
        if (!$this->color_re) {
            $re = "{(?:\\A| )(?:(?:\\d*~|~~|)(" . join("|", array_keys($this->style_info_lmap));
            $any = false;
            foreach ($this->filter("colors") as $t) {
                if (!$any) {
                    $re .= ")|(";
                    $any = true;
                } else {
                    $re .= "|";
                }
                $re .= $t->tag_regex();
            }
            $this->color_re = $re . "))(?=\\z|[# ])}i";
        }
        return $this->color_re;
    }

    /** @return ?list<string> */
    function styles($tags, $match = 0, $no_pattern_fill = false) {
        if (is_array($tags)) {
            $tags = join(" ", $tags);
        }
        if (!$tags
            || $tags === " "
            || !preg_match_all($this->color_regex(), $tags, $ms, PREG_SET_ORDER)) {
            return null;
        }
        $classes = [];
        $info = 0;
        foreach ($ms as $m) {
            $ltag = strtolower($m[1] ? : $m[2]);
            $t = $this->check($ltag);
            $ks = $t ? $t->colors : [$ltag];
            foreach ($ks as $k) {
                if ($match === 0 || ($this->style_info_lmap[$k] & $match)) {
                    $classes[] = $this->canonical_style_lmap[$k] . "tag";
                    $info |= $this->style_info_lmap[$k];
                }
            }
        }
        if (empty($classes)) {
            return null;
        }
        if (count($classes) > 1) {
            sort($classes);
            $classes = array_unique($classes);
        }
        if ($info & self::STYLE_BG) {
            $classes[] = "tagbg";
        }
        // This seems out of place---it's redundant if we're going to
        // generate JSON, for example---but it is convenient.
        if (!$no_pattern_fill
            && count($classes) > ($info & self::STYLE_BG ? 2 : 1)) {
            self::mark_pattern_fill($classes);
        }
        return $classes;
    }

    static function mark_pattern_fill($classes) {
        $key = is_array($classes) ? join(" ", $classes) : $classes;
        if (!isset(self::$multicolor_map[$key]) && strpos($key, " ") !== false) {
            Ht::stash_script("hotcrp.make_pattern_fill(" . json_encode_browser($key) . ")");
            self::$multicolor_map[$key] = true;
        }
    }

    function color_classes($tags, $no_pattern_fill = false) {
        $s = $this->styles($tags, 0, $no_pattern_fill);
        return $s ? join(" ", $s) : "";
    }

    /** @return list<string> */
    function canonical_colors() {
        $colors = [];
        foreach ($this->canonical_style_lmap as $ltag => $canon_ltag) {
            if ($ltag === $canon_ltag)
                $colors[] = $ltag;
        }
        return $colors;
    }


    /** @return string */
    function badge_regex() {
        if (!$this->badge_re) {
            $re = "{(?:\\A| )(?:\\d*~|)(";
            foreach ($this->filter("badges") as $t) {
                $re .= $t->tag_regex() . "|";
            }
            $this->badge_re = substr($re, 0, -1) . ")(?:#[-\\d.]+)?(?=\\z| )}i";
        }
        return $this->badge_re;
    }

    /** @return list<string> */
    function canonical_badges() {
        return explode("|", $this->basic_badges);
    }

    /** @return string */
    function emoji_regex() {
        if (!$this->emoji_re) {
            $re = "{(?:\\A| )(?:\\d*~|~~|)(:\\S+:";
            foreach ($this->filter("emoji") as $t) {
                $re .= "|" . $t->tag_regex();
            }
            $this->emoji_re = $re . ")(?:#[\\d.]+)?(?=\\z| )}i";
        }
        return $this->emoji_re;
    }


    /** @param ?string $s
     * @return bool */
    static function is_tag_string($s, $strict = false) {
        return (string) $s === ""
            || preg_match($strict ? '/\A(?: [^#\s]+#-?[\d.]+)+\z/' : '/\A(?: \S+)+\z/', $s);
    }

    static function assert_tag_string($tags, $strict = false) {
        if (!self::is_tag_string($tags, $strict)) {
            trigger_error("Bad tag string $tags");
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
        if ($view_most
            && (($ctype === self::CENSOR_SEARCH && $allow_admin)
                || (!$this->has_hidden && strpos($tags, "~") === false))) {
            return $tags;
        } else if (!$view_most
                   && !$this->has_conflict_free
                   && (!$user->privChair || !$this->has_sitewide)) {
            return "";
        }

        // go tag by tag
        $strip_hidden = $this->has_hidden && !$user->can_view_hidden_tags($prow);
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
                    $dt = $this->check($t);
                    $ok = $dt
                        && ($dt->conflict_free
                            || ($user->privChair && $dt->sitewide));
                }
            } else if ($tw !== false) {
                if ($tw === $mine_tw
                    && str_starts_with($t, (string) $user->contactId)) {
                    $ok = true;
                } else if ($ctype === self::CENSOR_VIEW) {
                    $ok = false;
                } else if ($allow_admin && $view_most) {
                    $ok = true;
                } else if (!$this->has_public_peruser) {
                    $ok = false;
                } else {
                    $dt = $this->check(substr($t, $tw + 1));
                    $ok = $dt
                        && $dt->public_peruser
                        && ($view_most
                            || $dt->conflict_free
                            || ($user->privChair && $dt->sitewide));
                }
            } else if (!$view_most) {
                $dt = $this->check($t);
                $ok = $dt
                    && (!$strip_hidden || !$dt->hidden)
                    && ($dt->conflict_free || ($user->privChair && $dt->sitewide));
            } else if ($strip_hidden) {
                $dt = $this->check($t);
                $ok = !$dt || !$dt->hidden;
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
        $suffix = $value ? "#$value" : "";
        $hash = ($flags & self::UNPARSE_HASH ? "#" : "");
        if (($twiddle = strpos($tag, "~")) > 0) {
            $cid = (int) substr($tag, 0, $twiddle);
            if ($cid !== 0 && $cid === $viewer->contactId) {
                $tag = substr($tag, $twiddle);
            } else if (($p = $viewer->conf->cached_user_by_id($cid))) {
                if ($flags & self::UNPARSE_TEXT) {
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


    static function make(Conf $conf) {
        $map = new TagMap($conf);
        $t = $map->add("perm:*");
        $t->chair = $t->readonly = true;
        $ct = $conf->setting_data("tag_chair") ?? "";
        foreach (Tagger::split_unpack($ct) as $ti) {
            $t = $map->add($ti[0]);
            $t->chair = $t->readonly = true;
        }
        foreach ($conf->track_tags() as $tn) {
            $t = $map->add(Tagger::base($tn));
            $t->chair = $t->readonly = $t->track = true;
        }
        $ct = $conf->setting_data("tag_hidden") ?? "";
        foreach (Tagger::split_unpack($ct) as $ti) {
            $map->add($ti[0])->hidden = $map->has_hidden = true;
        }
        $ct = $conf->setting_data("tag_sitewide") ?? "";
        foreach (Tagger::split_unpack($ct) as $ti) {
            $map->add($ti[0])->sitewide = $map->has_sitewide = true;
        }
        $ct = $conf->setting_data("tag_conflict_free") ?? "";
        foreach (Tagger::split_unpack($ct) as $ti) {
            $map->add($ti[0])->conflict_free = $map->has_conflict_free = true;
        }
        $ppu = $conf->setting("tag_vote_private_peruser")
            || $conf->opt("secretPC");
        $vt = $conf->setting_data("tag_vote") ?? "";
        foreach (Tagger::split_unpack($vt) as $ti) {
            $t = $map->add($ti[0]);
            $t->allotment = ($ti[1] ?? 1.0);
            $map->has_allotment = true;
            $t->votish = $map->has_votish = true;
            $t->automatic = $map->has_automatic = true;
            if (!$ppu) {
                $t->public_peruser = $map->has_public_peruser = true;
            }
        }
        $vt = $conf->setting_data("tag_approval") ?? "";
        foreach (Tagger::split_unpack($vt) as $ti) {
            $t = $map->add($ti[0]);
            $t->approval = $map->has_approval = true;
            $t->votish = $map->has_votish = true;
            $t->automatic = $map->has_automatic = true;
            if (!$ppu) {
                $t->public_peruser = $map->has_public_peruser = true;
            }
        }
        $rt = $conf->setting_data("tag_rank") ?? "";
        foreach (Tagger::split_unpack($rt) as $ti) {
            $t = $map->add($ti[0]);
            $t->rank = $map->has_rank = true;
            if (!$ppu) {
                $t->public_peruser = $map->has_public_peruser = true;
            }
        }
        $ct = $conf->setting_data("tag_color") ?? "";
        if ($ct !== "") {
            foreach (explode(" ", $ct) as $k) {
                if ($k !== "" && ($p = strpos($k, "=")) !== false
                    && ($kk = $map->known_style(substr($k, $p + 1)))) {
                    $map->add(substr($k, 0, $p))->colors[] = $kk;
                    $map->has_colors = true;
                }
            }
        }
        $bt = $conf->setting_data("tag_badge") ?? "";
        if ($bt !== "") {
            foreach (explode(" ", $bt) as $k) {
                if ($k !== "" && ($p = strpos($k, "=")) !== false) {
                    $map->add(substr($k, 0, $p))->badges[] = substr($k, $p + 1);
                    $map->has_badges = true;
                }
            }
        }
        $bt = $conf->setting_data("tag_emoji") ?? "";
        if ($bt !== "") {
            foreach (explode(" ", $bt) as $k) {
                if ($k !== "" && ($p = strpos($k, "=")) !== false) {
                    $map->add(substr($k, 0, $p))->emoji[] = substr($k, $p + 1);
                    $map->has_emoji = true;
                }
            }
        }
        $tx = $conf->setting_data("tag_autosearch") ?? "";
        if ($tx !== "") {
            foreach (json_decode($tx) ? : [] as $tag => $search) {
                $t = $map->add($tag);
                $t->autosearch = $search->q;
                if (isset($search->v)) {
                    $t->autosearch_value = $search->v;
                }
                $t->automatic = $map->has_automatic = $map->has_autosearch = true;
            }
        }
        if (($od = $conf->opt("definedTags"))) {
            foreach (is_string($od) ? [$od] : $od as $ods) {
                foreach (json_decode($ods) as $tag => $data) {
                    $t = $map->add($tag);
                    if ($data->chair ?? false) {
                        $t->chair = $t->readonly = true;
                    }
                    if ($data->readonly ?? false) {
                        $t->readonly = true;
                    }
                    if ($data->hidden ?? false) {
                        $t->hidden = $map->has_hidden = true;
                    }
                    if ($data->sitewide ?? false) {
                        $t->sitewide = $map->has_sitewide = true;
                    }
                    if ($data->conflict_free ?? false) {
                        $t->conflict_free = $map->has_conflict_free = true;
                    }
                    if (($x = $data->autosearch ?? null)) {
                        $t->autosearch = $x;
                        if (isset($data->autosearch_value)) {
                            $t->autosearch_value = $data->autosearch_value;
                        }
                        $t->automatic = true;
                        $map->has_autosearch = $map->has_automatic = true;
                    }
                    if (($x = $data->color ?? null)) {
                        foreach (is_string($x) ? [$x] : $x as $c) {
                            if (($kk = $map->known_style($c))) {
                                $t->colors[] = $kk;
                                $map->has_colors = true;
                            }
                        }
                    }
                    if (($x = $data->badge ?? null)) {
                        foreach (is_string($x) ? [$x] : $x as $c) {
                            $t->badges[] = $c;
                            $map->has_badges = true;
                        }
                    }
                    if (($x = $data->emoji ?? null)) {
                        foreach (is_string($x) ? [$x] : $x as $c) {
                            $t->emoji[] = $c;
                            $map->has_emoji = true;
                        }
                    }
                }
            }
        }
        if ($map->has_badges || $map->has_emoji || $conf->setting("has_colontag")) {
            $map->has_decoration = true;
        }
        if (($map->has_colors || $map->has_badges || $map->has_emoji)
            && ($t = $map->check("pc"))
            && ($t->colors || $t->badges || $t->emoji)) {
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
    const NOTAGKEYWORD = 64;
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


    /** @param string $tag
     * @return string */
    static function base($tag) {
        if (($pos = strpos($tag, "#")) > 0
            || ($pos = strpos($tag, "=")) > 0) {
            return substr($tag, 0, $pos);
        } else {
            return $tag;
        }
    }

    /** @param string $tag
     * @return array{false|string,?float} */
    static function unpack($tag) {
        if (!$tag) {
            return [false, null];
        } else if (!($pos = strpos($tag, "#")) && !($pos = strpos($tag, "="))) {
            return [$tag, null];
        } else if ($pos === strlen($tag) - 1) {
            return [substr($tag, 0, $pos), null];
        } else {
            return [substr($tag, 0, $pos), (float) substr($tag, $pos + 1)];
        }
    }

    /** @param string $taglist
     * @return list<string> */
    static function split($taglist) {
        preg_match_all('/\S+/', $taglist, $m);
        return $m[0];
    }

    /** @param string $taglist
     * @return list<array{false|string,?float}> */
    static function split_unpack($taglist) {
        return array_map("Tagger::unpack", self::split($taglist));
    }

    /** @param string $tag
     * @return bool */
    static function basic_check($tag) {
        return $tag !== "" && strlen($tag) <= TAG_MAXLEN
            && preg_match('{\A' . TAG_REGEX . '\z}', $tag);
    }

    /** @param bool $sequential */
    static function value_increment($sequential) {
        return $sequential ? 1 : self::$value_increment_map[mt_rand(0, 9)];
    }


    /** @param bool $verbose
     * @return ?string */
    function error_html($verbose = false) {
        $t = $verbose ? " ‘" . htmlspecialchars($this->errtag ?? "") . "’" : "";
        switch ($this->errcode) {
        case 0:
            return null;
        case self::EEMPTY:
            return "Tag required";
        case self::EMULTIPLE:
            return "Single tag required";
        case self::E2BIG:
            return "Tag too long";
        case self::ALLOWSTAR:
            return "Invalid tag{$t} (stars aren’t allowed here)";
        case self::NOCHAIR:
            if ($this->contact->privChair) {
                return "Invalid tag{$t} (chair tags aren’t allowed here)";
            } else {
                return "Invalid tag{$t} (tag reserved for chair)";
            }
        case self::NOPRIVATE:
            return "Private tags aren’t allowed here";
        case self::ALLOWCONTACTID:
            if ($verbose && ($twiddle = strpos($this->errtag ?? "", "~"))) {
                return "Invalid tag{$t} (did you mean ‘#" . substr($this->errtag, $twiddle) . "’?)";
            } else {
                return "Invalid private tag";
            }
        case self::NOVALUE:
            return "Tag values aren’t allowed here";
        case self::ALLOWRESERVED:
            return $verbose ? "Tag{$t} is reserved" : "Tag reserved";
        case self::EINVAL:
        default:
            return "Invalid tag{$t}";
        }
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
        if (!($flags & self::ALLOWSTAR) && strpos($tag, "*") !== false) {
            return $this->set_error_code($tag, self::ALLOWSTAR);
        }
        // After this point we know `$tag` contains no HTML specials
        if ($m[1] === "") {
            // OK
        } else if ($m[1] === "~~") {
            if ($flags & self::NOCHAIR) {
                return $this->set_error_code($tag, self::NOCHAIR);
            }
        } else {
            if ($flags & self::NOPRIVATE) {
                return $this->set_error_code($tag, self::NOPRIVATE);
            } else if ($m[1] === "~") {
                if ($this->_contactId) {
                    $m[1] = $this->_contactId . "~";
                }
            } else if ($m[1] !== $this->_contactId . "~"
                       && !($flags & self::ALLOWCONTACTID)) {
                return $this->set_error_code($tag, self::ALLOWCONTACTID);
            }
        }
        if ($m[3] !== "" && ($flags & self::NOVALUE)) {
            return $this->set_error_code($tag, self::NOVALUE);
        }
        if (!($flags & self::ALLOWRESERVED)
            && (!strcasecmp("none", $m[2]) || !strcasecmp("any", $m[2]))) {
            return $this->set_error_code($tag, self::ALLOWRESERVED);
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

    static function check_tag_keyword($text, Contact $user, $flags = 0) {
        $re = '/\A(?:#|tagval:\s*'
            . ($flags & self::NOTAGKEYWORD ? '' : '|tag:\s*')
            . ')(\S+)\z/i';
        if (preg_match($re, $text, $m)) {
            $tagger = new Tagger($user);
            return $tagger->check($m[1], $flags);
        } else {
            return false;
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
        if ($count == 0 || $count == 1) {
            $b .= $e;
        } else if ($count >= 5.0625) {
            $b .= str_repeat($e, 5) . "<sup>+</sup>";
        } else {
            $f = floor($count + 0.0625);
            $d = round(max($count - $f, 0) * 8);
            $b .= str_repeat($e, (int) $f);
            if ($d) {
                $b .= '<span style="display:inline-block;overflow-x:hidden;vertical-align:bottom;position:relative;bottom:0;width:' . ($d / 8) . 'em">' . $e . '</span>';
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
        if ($dt->has_decoration
            && preg_match_all($dt->emoji_regex(), $tags, $m, PREG_SET_ORDER)) {
            $emoji = [];
            foreach ($m as $mx) {
                if (($t = $dt->check($mx[1])) && $t->emoji) {
                    foreach ($t->emoji as $e)
                        $emoji[$e][] = ltrim($mx[0]);
                }
            }
            foreach ($emoji as $e => $ts) {
                $links = [];
                $count = 0;
                foreach ($ts as $t) {
                    if (($link = $this->link_base($t)))
                        $links[] = "#" . $link;
                    list($base, $value) = Tagger::unpack($t);
                    $count = max($count, (float) $value);
                }
                $b = self::unparse_emoji_html($e, $count);
                if ($type === self::DECOR_PAPER && !empty($links)) {
                    $b = '<a class="q" href="' . $this->conf->hoturl("search", ["q" => join(" OR ", $links)]) . '">' . $b . '</a>';
                }
                if ($x === "") {
                    $x = " ";
                }
                $x .= $b;
            }
        }
        if ($dt->has_badges
            && preg_match_all($dt->badge_regex(), $tags, $m, PREG_SET_ORDER)) {
            foreach ($m as $mx) {
                if (($t = $dt->check($mx[1])) && $t->badges) {
                    /** @phan-suppress-next-line PhanTypeArraySuspiciousNullable */
                    $klass = " class=\"badge {$t->badges[0]}badge\"";
                    $tag = $this->unparse(trim($mx[0]));
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
            }
        }
        return $x === "" ? "" : "<span class=\"tagdecoration\">{$x}</span>";
    }

    /** @param string $tag
     * @return string|false */
    function link_base($tag) {
        if (ctype_digit($tag[0])) {
            $p = strlen((string) $this->_contactId);
            if (substr($tag, 0, $p) != $this->_contactId || $tag[$p] !== "~") {
                return false;
            }
            $tag = substr($tag, $p);
        }
        return Tagger::base($tag);
    }

    /** @param string $tag
     * @return string|false */
    function link($tag) {
        if (ctype_digit($tag[0])) {
            $p = strlen((string) $this->_contactId);
            if (substr($tag, 0, $p) != $this->_contactId || $tag[$p] !== "~") {
                return false;
            }
            $tag = substr($tag, $p);
        }
        $base = Tagger::base($tag);
        $dt = $this->conf->tags();
        if ($dt->has_votish
            && ($dt->is_votish($base)
                || ($base[0] === "~" && $dt->is_allotment(substr($base, 1))))) {
            $q = "#$base showsort:-#$base";
        } else if ($base === $tag) {
            $q = "#$base";
        } else {
            $q = "order:#$base";
        }
        return $this->conf->hoturl("search", ["q" => $q]);
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
        foreach (preg_split('/\s+/', $tags) as $tag) {
            if (!($base = Tagger::base($tag))) {
                continue;
            }
            $lbase = strtolower($base);
            if (($link = $this->link($tag))) {
                $tsuf = substr($tag, strlen($base));
                $tx = "<a class=\"qo pw\" href=\"{$link}\"><u class=\"x\">#{$base}</u>{$tsuf}</a>";
            } else {
                $tx = "#{$tag}";
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
