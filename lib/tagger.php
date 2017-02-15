<?php
// tagger.php -- HotCRP helper class for dealing with tags
// HotCRP is Copyright (c) 2006-2017 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

// Note that tags MUST NOT contain HTML or URL special characters:
// no "'&<>.  If you add PHP-protected characters, such as $, make sure you
// check for uses of eval().

class TagMapItem {
    public $tag;
    public $conf;
    public $pattern = false;
    public $pattern_instance = false;
    public $chair = false;
    public $track = false;
    public $votish = false;
    public $vote = false;
    public $approval = false;
    public $sitewide = false;
    public $rank = false;
    public $order_anno = false;
    private $order_anno_list = false;
    public $colors = null;
    public $basic_color = false;
    public $badges = null;
    public $emoji = null;
    function __construct($tag, Conf $conf) {
        $this->conf = $conf;
        $this->set_tag($tag);
    }
    function set_tag($tag) {
        $this->tag = $tag;
        if (preg_match('/\A(?:' . TagInfo::BASIC_COLORS_PLUS . ')\z/i', $tag)) {
            $this->colors[] = TagInfo::canonical_color($tag);
            $this->basic_color = true;
        }
    }
    function merge(TagMapItem $t) {
        foreach (["chair", "track", "votish", "vote", "approval", "sitewide", "rank"] as $property)
            if ($t->$property)
                $this->$property = $t->$property;
        foreach (["colors", "badges", "emoji"] as $property)
            if ($t->$property)
                $this->$property = array_unique(array_merge($this->$property ? : [], $t->$property));
    }
    function tag_regex() {
        $t = preg_quote($this->tag);
        return $this->pattern ? str_replace("\\*", ".*", $t) : $t;
    }
    function order_anno_list() {
        if ($this->order_anno_list == false) {
            $this->order_anno_list = Dbl::fetch_objects($this->conf->dblink, "select * from PaperTagAnno where tag=?", $this->tag);
            $this->order_anno_list[] = (object) ["tag" => $this->tag, "tagIndex" => TAG_INDEXBOUND, "heading" => "Untagged", "annoId" => null, "annoFormat" => 0];
            usort($this->order_anno_list, function ($a, $b) {
                if ($a->tagIndex != $b->tagIndex)
                    return $a->tagIndex < $b->tagIndex ? -1 : 1;
                else if (($x = strcasecmp($a->heading, $b->heading)) != 0)
                    return $x;
                else
                    return $a->annoId < $b->annoId ? -1 : 1;
            });
        }
        return $this->order_anno_list;
    }
    function order_anno_entry($i) {
        return get($this->order_anno_list(), $i);
    }
    function has_order_anno() {
        return count($this->order_anno_list()) > 1;
    }
}

class TagMap implements IteratorAggregate {
    public $conf;
    public $has_pattern = false;
    public $has_chair = true;
    public $has_votish = false;
    public $has_vote = false;
    public $has_approval = false;
    public $has_sitewide = false;
    public $has_rank = false;
    public $has_colors = false;
    public $has_badges = false;
    public $has_emoji = false;
    public $has_decoration = false;
    public $has_order_anno = false;
    private $storage = array();
    private $sorted = false;
    private $pattern_re = null;
    private $pattern_storage = [];
    private $color_re = null;
    private $badge_re = null;
    private $emoji_re = null;
    private $sitewide_re_part = null;

    private static $emoji_code_map = null;
    private static $multicolor_map = [];

    function __construct(Conf $conf) {
        $this->conf = $conf;
    }
    function check_emoji_code($ltag) {
        $len = strlen($ltag);
        if ($len < 3 || $ltag[0] !== ":" || $ltag[$len - 1] !== ":")
            return false;
        return get($this->conf->emoji_code_map(), substr($ltag, 1, $len - 2), false);
    }
    function check($tag) {
        $ltag = strtolower($tag);
        $t = get($this->storage, $ltag);
        if (!$t && $ltag && $ltag[0] === ":" && $this->check_emoji_code($ltag))
            $t = $this->add($tag);
        if (!$t && $this->has_pattern) {
            if (!$this->pattern_re) {
                $a = [];
                foreach ($this->pattern_storage as $p)
                    $a[] = strtolower($p->tag_regex());
                $this->pattern_re = "{\A(?:" . join("|", $a) . ")\z}";
            }
            if (preg_match($this->pattern_re, $ltag))
                foreach ($this->pattern_storage as $p)
                    if (preg_match($p->pattern, $ltag)) {
                        if (!$t) {
                            $t = clone $p;
                            $t->set_tag($tag);
                            $t->pattern = false;
                            $t->pattern_instance = true;
                            $this->storage[$ltag] = $t;
                            $this->sorted = false;
                        } else
                            $t->merge($p);
                    }
        }
        return $t;
    }
    function check_base($tag) {
        return $this->check(TagInfo::base($tag));
    }
    function add($tag) {
        $ltag = strtolower($tag);
        $t = get($this->storage, $ltag);
        if (!$t) {
            $t = new TagMapItem($tag, $this->conf);
            if (TagInfo::basic_check($ltag)) {
                $this->storage[$ltag] = $t;
                if (strpos($ltag, "*") !== false) {
                    $t->pattern = "{\A" . strtolower(str_replace("\\*", ".*", $t->tag_regex())) . "\z}";
                    $this->has_pattern = true;
                    $this->pattern_storage[] = $t;
                    $this->pattern_re = null;
                }
                if ($ltag[0] === ":" && ($e = $this->check_emoji_code($ltag))) {
                    $t->emoji[] = $e;
                    $this->has_emoji = $this->has_decoration = true;
                }
                $this->sorted = false;
            }
        }
        return $t;
    }
    private function sort() {
        ksort($this->storage);
        $this->sorted = true;
    }
    function getIterator() {
        $this->sorted || $this->sort();
        return new ArrayIterator($this->storage);
    }
    function filter($property) {
        $k = "has_{$property}";
        if (!$this->$k)
            return [];
        $this->sorted || $this->sort();
        return array_filter($this->storage, function ($t) use ($property) { return $t->$property; });
    }
    function filter_by($f) {
        $this->sorted || $this->sort();
        return array_filter($this->storage, $f);
    }
    function check_property($tag, $property) {
        $k = "has_{$property}";
        return $this->$k
            && ($t = $this->check(TagInfo::base($tag)))
            && $t->$property
            ? $t : null;
    }


    function is_chair($tag) {
        if ($tag[0] === "~")
            return $tag[1] === "~";
        else
            return !!$this->check_property($tag, "chair");
    }
    function is_sitewide($tag) {
        return !!$this->check_property($tag, "sitewide");
    }
    function is_votish($tag) {
        return !!$this->check_property($tag, "votish");
    }
    function is_vote($tag) {
        return !!$this->check_property($tag, "vote");
    }
    function is_approval($tag) {
        return !!$this->check_property($tag, "approval");
    }
    function votish_base($tag) {
        if (!$this->has_votish
            || ($twiddle = strpos($tag, "~")) === false)
            return false;
        $tbase = substr(TagInfo::base($tag), $twiddle + 1);
        $t = $this->check($tbase);
        return $t && $t->votish ? $tbase : false;
    }
    function is_rank($tag) {
        return !!$this->check_property($tag, "rank");
    }
    function is_emoji($tag) {
        return !!$this->check_property($tag, "emoji");
    }

    function sitewide_regex_part() {
        if ($this->sitewide_re_part === null) {
            $x = ["\\&"];
            foreach ($this->filter("sitewide") as $t)
                $x[] = $t->tag_regex() . "[ #=]";
            $this->sitewide_re_part = join("|", $x);
        }
        return $this->sitewide_re_part;
    }

    function color_regex() {
        if (!$this->color_re) {
            $re = "{(?:\\A| )(?:\\d*~|~~|)(" . TagInfo::BASIC_COLORS_PLUS;
            foreach ($this->filter("colors") as $t)
                $re .= "|" . $t->tag_regex();
            $this->color_re = $re . ")(?=\\z|[# ])}i";
        }
        return $this->color_re;
    }

    function color_classes($tags, $color_type_bit = 1) {
        if (is_array($tags))
            $tags = join(" ", $tags);
        if (!$tags || $tags === " ")
            return "";
        if (!preg_match_all($this->color_regex(), $tags, $m))
            return false;
        $classes = array();
        foreach ($m[1] as $tag)
            if (($t = $this->check($tag)) && $t->colors) {
                foreach ($t->colors as $k)
                    $classes[] = $k . "tag";
            } else
                $classes[] = TagInfo::canonical_color($tag) . "tag";
        if ($color_type_bit > 1)
            $classes = array_filter($classes, "TagInfo::classes_have_colors");
        if (count($classes) > 1) {
            sort($classes);
            $classes = array_unique($classes);
        }
        $key = join(" ", $classes);
        // This seems out of place---it's redundant if we're going to
        // generate JSON, for example---but it is convenient.
        if (count($classes) > 1) {
            $m = (int) get(self::$multicolor_map, $key);
            if (!($m & $color_type_bit)) {
                if ($color_type_bit == 1)
                    Ht::stash_script("make_pattern_fill(" . json_encode($key) . ")");
                self::$multicolor_map[$key] = $m | $color_type_bit;
            }
        }
        return $key;
    }

    function tags_with_color($f) {
        $a = [];
        foreach ($this as $t)
            if (call_user_func($f, $t->tag, $t->colors ? : []))
                $a[] = $t->tag;
        foreach (explode("|", TagInfo::BASIC_COLORS_PLUS) as $ltag)
            if (!isset($this->storage[$ltag])
                && call_user_func($f, $ltag, [TagInfo::canonical_color($ltag)]))
                $a[] = $ltag;
        return $a;
    }

    function badge_regex() {
        if (!$this->badge_re) {
            $re = "{(?:\\A| )(?:\\d*~|~~|)(";
            foreach ($this->filter("badges") as $t)
                $re .= $t->tag_regex() . "|";
            $this->badge_re = substr($re, 0, -1) . ")(?:#[\\d.]+)?(?=\\z| )}i";
        }
        return $this->badge_re;
    }

    function emoji_regex() {
        if (!$this->badge_re) {
            $re = "{(?:\\A| )(?:\\d*~|~~|)(:\\S+:";
            foreach ($this->filter("emoji") as $t)
                $re .= "|" . $t->tag_regex();
            $this->emoji_re = $re . ")(?:#[\\d.]+)?(?=\\z| )}i";
        }
        return $this->emoji_re;
    }


    static function make(Conf $conf) {
        $map = new TagMap($conf);
        $ct = $conf->setting_data("tag_chair", "");
        foreach (TagInfo::split_unpack($ct) as $ti)
            $map->add($ti[0])->chair = true;
        foreach ($conf->track_tags() as $tn) {
            $t = $map->add(TagInfo::base($tn));
            $t->chair = $t->track = true;
        }
        $ct = $conf->setting_data("tag_sitewide", "");
        foreach (TagInfo::split_unpack($ct) as $ti)
            $map->add($ti[0])->sitewide = $map->has_sitewide = true;
        $vt = $conf->setting_data("tag_vote", "");
        foreach (TagInfo::split_unpack($vt) as $ti) {
            $t = $map->add($ti[0]);
            $t->vote = ($ti[1] ? : 1);
            $t->votish = $map->has_vote = $map->has_votish = true;
        }
        $vt = $conf->setting_data("tag_approval", "");
        foreach (TagInfo::split_unpack($vt) as $ti) {
            $t = $map->add($ti[0]);
            $t->approval = $t->votish = $map->has_approval = $map->has_votish = true;
        }
        $rt = $conf->setting_data("tag_rank", "");
        foreach (TagInfo::split_unpack($rt) as $ti)
            $map->add($ti[0])->rank = $map->has_rank = true;
        $ct = $conf->setting_data("tag_color", "");
        if ($ct !== "")
            foreach (explode(" ", $ct) as $k)
                if ($k !== "" && ($p = strpos($k, "=")) !== false) {
                    $map->add(substr($k, 0, $p))->colors[] = TagInfo::canonical_color(substr($k, $p + 1));
                    $map->has_colors = true;
                }
        $bt = $conf->setting_data("tag_badge", "");
        if ($bt !== "")
            foreach (explode(" ", $bt) as $k)
                if ($k !== "" && ($p = strpos($k, "=")) !== false) {
                    $map->add(substr($k, 0, $p))->badges[] = substr($k, $p + 1);
                    $map->has_badges = true;
                }
        $bt = $conf->setting_data("tag_emoji", "");
        if ($bt !== "")
            foreach (explode(" ", $bt) as $k)
                if ($k !== "" && ($p = strpos($k, "=")) !== false) {
                    $map->add(substr($k, 0, $p))->emoji[] = substr($k, $p + 1);
                    $map->has_emoji = true;
                }
        $xt = $conf->setting_data("tag_order_anno", "");
        if ($xt !== "" && ($xt = json_decode($xt)))
            foreach (get_object_vars($xt) as $t => $v)
                if (is_object($v)) {
                    $map->add($t)->order_anno = $v;
                    $map->has_order_anno = true;
                }
        if (($od = $conf->opt("definedTags")))
            foreach (is_string($od) ? [$od] : $od as $ods)
                foreach (json_decode($ods) as $tag => $data) {
                    $t = $map->add($tag);
                    if (get($data, "chair"))
                        $t->chair = $map->has_chair = true;
                    if (get($data, "sitewide"))
                        $t->sitewide = $map->has_sitewide = true;
                    if (($x = get($data, "color")))
                        foreach (is_string($x) ? [$x] : $x as $c) {
                            $t->colors[] = TagInfo::canonical_color($c);
                            $map->has_colors = true;
                        }
                    if (($x = get($data, "badge")))
                        foreach (is_string($x) ? [$x] : $x as $c) {
                            $t->badges[] = $c;
                            $map->has_badges = true;
                        }
                    if (($x = get($data, "emoji")))
                        foreach (is_string($x) ? [$x] : $x as $c) {
                            $t->emoji[] = $c;
                            $map->has_emoji = true;
                        }
                }
        if ($map->has_badges || $map->has_emoji || $conf->setting("has_colontag"))
            $map->has_decoration = true;
        return $map;
    }
}

class TagInfo {
    const BASIC_COLORS = "red|orange|yellow|green|blue|purple|gray|white|bold|italic|underline|strikethrough|big|small|dim";
    const BASIC_COLORS_PLUS = "red|orange|yellow|green|blue|purple|violet|grey|gray|white|bold|italic|underline|strikethrough|big|small|dim";
    const BASIC_COLORS_NOSTYLES = "red|orange|yellow|green|blue|purple|gray|white";
    const BASIC_BADGES = "normal|red|yellow|green|blue|white|pink|gray";

    static function base($tag) {
        if ($tag && (($pos = strpos($tag, "#")) > 0
                     || ($pos = strpos($tag, "=")) > 0))
            return substr($tag, 0, $pos);
        else
            return $tag;
    }

    static function unpack($tag) {
        if (!$tag)
            return [false, false];
        else if (!($pos = strpos($tag, "#")) && !($pos = strpos($tag, "=")))
            return [$tag, false];
        else if ($pos == strlen($tag) - 1)
            return [substr($tag, 0, $pos), false];
        else
            return [substr($tag, 0, $pos), (float) substr($tag, $pos + 1)];
    }

    static function split($taglist) {
        preg_match_all(',\S+,', $taglist, $m);
        return $m[0];
    }

    static function split_unpack($taglist) {
        return array_map("TagInfo::unpack", self::split($taglist));
    }

    static function basic_check($tag) {
        return $tag !== "" && strlen($tag) <= TAG_MAXLEN
            && preg_match('{\A' . TAG_REGEX . '\z}', $tag);
    }

    static function unparse_anno_json($anno) {
        global $Conf;
        $j = (object) ["annoid" => $anno->annoId === null ? null : +$anno->annoId];
        if ($anno->tagIndex !== null)
            $j->tagval = (float) $anno->tagIndex;
        $j->heading = $anno->heading;
        if (($format = $Conf->check_format($anno->annoFormat, (string) $anno->heading)))
            $j->format = +$format;
        return $j;
    }

    static function canonical_color($tag) {
        $tag = strtolower($tag);
        if ($tag === "violet")
            return "purple";
        else if ($tag === "grey")
            return "gray";
        else
            return $tag;
    }

    static function classes_have_colors($classes) {
        return preg_match('_\b(?:\A|\s)(?:red|orange|yellow|green|blue|purple|gray|white)tag(?:\z|\s)_', $classes);
    }


    private static $value_increment_map = array(1, 1, 1, 1, 1, 2, 2, 2, 3, 4);

    static function value_increment($mode) {
        if (strlen($mode) == 2)
            return self::$value_increment_map[mt_rand(0, 9)];
        else
            return 1;
    }


    static function id_index_compar($a, $b) {
        if ($a[1] != $b[1])
            return $a[1] < $b[1] ? -1 : 1;
        else
            return $a[0] - $b[0];
    }
}

class Tagger {
    const ALLOWRESERVED = 1;
    const NOPRIVATE = 2;
    const NOVALUE = 4;
    const NOCHAIR = 8;
    const ALLOWSTAR = 16;
    const ALLOWCONTACTID = 32;

    public $error_html = false;
    private $conf;
    private $contact;
    private $_contactId = 0;

    function __construct($contact = null) {
        global $Conf, $Me;
        $this->contact = ($contact ? : $Me);
        if ($this->contact && $this->contact->contactId > 0)
            $this->_contactId = $this->contact->contactId;
        $this->conf = $this->contact ? $this->contact->conf : $Conf;
    }

    private function set_error($e) {
        $this->error_html = $e;
        return false;
    }

    function check($tag, $flags = 0) {
        if (!($this->contact && $this->contact->privChair))
            $flags |= self::NOCHAIR;
        if ($tag !== "" && $tag[0] === "#")
            $tag = substr($tag, 1);
        if ((string) $tag === "")
            return $this->set_error("Tag missing.");
        if (!preg_match('/\A(|~|~~|[1-9][0-9]*~)(' . TAG_REGEX_NOTWIDDLE . ')(|[#=](?:-?\d+(?:\.\d*)?|-?\.\d+|))\z/', $tag, $m))
            return $this->set_error("Format error: #" . htmlspecialchars($tag) . " is an invalid tag.");
        if (!($flags & self::ALLOWSTAR) && strpos($tag, "*") !== false)
            return $this->set_error("Wildcards aren’t allowed in tag names.");
        // After this point we know `$tag` contains no HTML specials
        if ($m[1] === "")
            /* OK */;
        else if ($m[1] === "~~") {
            if ($flags & self::NOCHAIR)
                return $this->set_error("Tag #{$tag} is exclusively for chairs.");
        } else {
            if ($flags & self::NOPRIVATE)
                return $this->set_error("Twiddle tags aren’t allowed here.");
            if ($m[1] === "~" && $this->_contactId)
                $m[1] = $this->_contactId . "~";
            if ($m[1] !== "~" && $m[1] !== $this->_contactId . "~"
                && !($flags & self::ALLOWCONTACTID))
                return $this->set_error("Format error: #{$tag} is an invalid tag.");
        }
        if ($m[3] !== "" && ($flags & self::NOVALUE))
            return $this->set_error("Tag values aren’t allowed here.");
        if (!($flags & self::ALLOWRESERVED)
            && (!strcasecmp("none", $m[2]) || !strcasecmp("any", $m[2])))
            return $this->set_error("Tag #{$m[2]} is reserved.");
        $t = $m[1] . $m[2];
        if (strlen($t) > TAG_MAXLEN)
            return $this->set_error("Tag #{$tag} is too long.");
        if ($m[3] !== "")
            $t .= "#" . substr($m[3], 1);
        return $t;
    }

    function view_score($tag) {
        if ($tag === false)
            return VIEWSCORE_FALSE;
        else if (($pos = strpos($tag, "~")) !== false) {
            if (($pos == 0 && $tag[1] === "~")
                || substr($tag, 0, $pos) != $this->_contactId)
                return VIEWSCORE_ADMINONLY;
            else
                return VIEWSCORE_REVIEWERONLY;
        } else
            return VIEWSCORE_PC;
    }


    static function strip_nonviewable($tags, Contact $user = null) {
        if (strpos($tags, "~") !== false) {
            $re = "{ (?:";
            if ($user && $user->contactId)
                $re .= "(?!" . $user->contactId . "~)";
            $re .= "\\d+~";
            if (!($user && $user->privChair))
                $re .= "|~+";
            $tags = trim(preg_replace($re . ")\\S+}", "", " $tags "));
        }
        return $tags;
    }

    static function strip_nonsitewide($tags, Contact $user) {
        $re = "{ (?:(?!" . $user->contactId . "~)\\d+~|~+|(?!"
            . $user->conf->tags()->sitewide_regex_part() . ")\\S)\\S*}i";
        return trim(preg_replace($re, "", " $tags "));
    }

    function unparse($tags) {
        if ($tags === "" || (is_array($tags) && count($tags) == 0))
            return "";
        if (is_array($tags))
            $tags = join(" ", $tags);
        $tags = str_replace("#0 ", " ", " $tags ");
        if ($this->_contactId)
            $tags = str_replace(" " . $this->_contactId . "~", " ~", $tags);
        return trim($tags);
    }

    function unparse_hashed($tags) {
        if (($tags = $this->unparse($tags)) !== "")
            $tags = str_replace(" ", " #", "#" . $tags);
        return $tags;
    }

    static function unparse_emoji_html($e, $count) {
        if ($count == 0)
            $count = 1;
        $b = '<span class="tagemoji">';
        if ($count == 0 || $count == 1)
            $b .= $e;
        else if ($count >= 5.0625)
            $b .= str_repeat($e, 5) . "<sup>+</sup>";
        else {
            $f = floor($count + 0.0625);
            $d = round(max($count - $f, 0) * 8);
            $b .= str_repeat($e, $f);
            if ($d)
                $b .= '<span style="display:inline-block;overflow-x:hidden;vertical-align:bottom;position:relative;bottom:0;width:' . ($d / 8) . 'em">' . $e . '</span>';
        }
        return $b . '</span>';
    }

    function unparse_decoration_html($tags) {
        if (is_array($tags))
            $tags = join(" ", $tags);
        if (!$tags || $tags === " ")
            return "";
        $dt = $this->conf->tags();
        $x = "";
        if ($dt->has_decoration
            && preg_match_all($dt->emoji_regex(), $tags, $m, PREG_SET_ORDER)) {
            $emoji = [];
            foreach ($m as $mx)
                if (($t = $dt->check($mx[1])) && $t->emoji)
                    foreach ($t->emoji as $e)
                        $emoji[$e][] = ltrim($mx[0]);
            foreach ($emoji as $e => $ts) {
                $links = [];
                $count = 0;
                foreach ($ts as $t) {
                    if (($link = $this->link_base($t)))
                        $links[] = "#" . $link;
                    list($base, $value) = TagInfo::unpack($t);
                    $count = max($count, (float) $value);
                }
                $b = self::unparse_emoji_html($e, $count);
                if (!empty($links))
                    $b = '<a class="qq" href="' . hoturl("search", ["q" => join(" OR ", $links)]) . '">' . $b . '</a>';
                $x .= ' ' . $b;
            }
        }
        if ($dt->has_badges
            && preg_match_all($dt->badge_regex(), $tags, $m, PREG_SET_ORDER))
            foreach ($m as $mx)
                if (($t = $dt->check($mx[1])) && $t->badges) {
                    $tag = $this->unparse(trim($mx[0]));
                    $b = '<span class="badge ' . $t->badges[0] . 'badge">#' . $tag . '</span>';
                    if (($link = $this->link($tag)))
                        $b = '<a class="qq" href="' . $link . '">' . $b . '</a>';
                    $x .= ' ' . $b;
                }
        return $x === "" ? "" : '<span class="tagdecoration">' . $x . '</span>';
    }

    private function trim_for_sort($x) {
        if ($x[0] === "#")
            $x = substr($x, 1);
        if ($x[0] === "~" && $x[1] !== "~")
            $x = $this->_contactId . $x;
        else if ($x[0] === "~")
            $x = ";" . $x;
        return $x;
    }

    function tag_compare($a, $b) {
        return strcasecmp($this->trim_for_sort($a), $this->trim_for_sort($b));
    }

    function sort(&$tags) {
        usort($tags, array($this, "tag_compare"));
    }

    function link_base($tag) {
        if (ctype_digit($tag[0])) {
            $p = strlen($this->_contactId);
            if (substr($tag, 0, $p) != $this->_contactId || $tag[$p] !== "~")
                return false;
            $tag = substr($tag, $p);
        }
        return TagInfo::base($tag);
    }

    function link($tag) {
        if (ctype_digit($tag[0])) {
            $p = strlen($this->_contactId);
            if (substr($tag, 0, $p) != $this->_contactId || $tag[$p] !== "~")
                return false;
            $tag = substr($tag, $p);
        }
        $base = TagInfo::base($tag);
        $dt = $this->conf->tags();
        if ($dt->has_votish
            && ($dt->is_votish($base)
                || ($base[0] === "~" && $dt->is_vote(substr($base, 1)))))
            $q = "#$base showsort:-#$base";
        else if ($base === $tag)
            $q = "#$base";
        else
            $q = "order:#$base";
        return hoturl("search", ["q" => $q]);
    }

    function unparse_and_link($viewable, $highlight = false) {
        $vtags = $this->unparse($viewable);
        if ($vtags === "")
            return "";

        // decorate with URL matches
        $tt = "";

        // clean $highlight
        $byhighlight = array();
        $anyhighlight = false;
        assert($highlight === false || is_array($highlight));
        if ($highlight)
            foreach ($highlight as $h) {
                $tag = is_object($h) ? $h->tag : $h;
                if (($pos = strpos($tag, "~")) !== false)
                    $tag = substr($tag, $pos);
                $byhighlight[strtolower($tag)] = "";
            }

        foreach (preg_split('/\s+/', $vtags) as $tag) {
            if (!($base = TagInfo::base($tag)))
                continue;
            $lbase = strtolower($base);
            if (($link = $this->link($tag)))
                $tx = '<a class="qq nw" href="' . $link . '">#' . $base . '</a>';
            else
                $tx = "#" . $base;
            $tx .= substr($tag, strlen($base));
            if (isset($byhighlight[$lbase])) {
                $byhighlight[$lbase] .= "<strong>" . $tx . "</strong> ";
                $anyhighlight = true;
            } else
                $tt .= $tx . " ";
        }

        if ($anyhighlight)
            return rtrim(join("", $byhighlight) . $tt);
        else
            return rtrim($tt);
    }
}
