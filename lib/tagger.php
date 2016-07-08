<?php
// tagger.php -- HotCRP helper class for dealing with tags
// HotCRP is Copyright (c) 2006-2016 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

// Note that tags MUST NOT contain HTML or URL special characters:
// no "'&<>.  If you add PHP-protected characters, such as $, make sure you
// check for uses of eval().

class TagMapItem {
    public $tag;
    public $chair = false;
    public $votish = false;
    public $vote = false;
    public $approval = false;
    public $sitewide = false;
    public $rank = false;
    public $order_anno = false;
    private $order_anno_list = false;
    public $colors = null;
    public $badges = null;
    public function __construct($tag) {
        $this->tag = $tag;
    }
    public function tag_regex() {
        $t = preg_quote($this->tag);
        return $this->pattern ? str_replace("\\*", ".*", $t) : $t;
    }
    public function order_anno_list() {
        if ($this->order_anno_list == false) {
            $this->order_anno_list = Dbl::fetch_objects("select * from PaperTagAnno where tag=?", $this->tag);
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
    public function order_anno_entry($i) {
        return get($this->order_anno_list(), $i);
    }
    public function has_order_anno() {
        return count($this->order_anno_list()) > 1;
    }
}

class TagMap implements IteratorAggregate {
    public $has_chair = true;
    public $has_votish = false;
    public $has_vote = false;
    public $has_approval = false;
    public $has_sitewide = false;
    public $has_rank = false;
    public $has_colors = false;
    public $has_badges = false;
    public $has_order_anno = false;
    private $storage = array();
    private $sorted = false;
    public function check($tag) {
        return get($this->storage, strtolower($tag));
    }
    public function make($tag) {
        $ltag = strtolower($tag);
        if (!isset($this->storage[$ltag])) {
            $n = new TagMapItem($tag);
            if (!TagInfo::basic_check($ltag))
                return $n;
            $this->storage[$ltag] = $n;
            $this->sorted = false;
        }
        return $this->storage[$ltag];
    }
    private function sort() {
        ksort($this->storage);
        $this->sorted = true;
    }
    public function getIterator() {
        $this->sorted || $this->sort();
        return new ArrayIterator($this->storage);
    }
    public function filter($property) {
        $k = "has_{$property}";
        if (!$this->$k)
            return [];
        $this->sorted || $this->sort();
        return array_filter($this->storage, function ($t) use ($property) { return $t->$property; });
    }
    public function check_property($tag, $property) {
        $k = "has_{$property}";
        return $this->$k
            && ($t = $this->check(TagInfo::base($tag)))
            && $t->$property
            ? $t : null;
    }
}

class TagInfo {
    const BASIC_COLORS = "red|orange|yellow|green|blue|purple|gray|white|bold|italic|underline|strikethrough|big|small|dim";
    const BASIC_COLORS_PLUS = "red|orange|yellow|green|blue|purple|violet|grey|gray|white|bold|italic|underline|strikethrough|big|small|dim";
    const BASIC_BADGES = "normal|red|green|blue|white";

    private static $tagmap = null;
    private static $colorre = null;
    private static $badgere = null;
    private static $sitewidere_part = null;

    private static $multicolor_map = [];

    public static function base($tag) {
        if ($tag && (($pos = strpos($tag, "#")) > 0
                     || ($pos = strpos($tag, "=")) > 0))
            return substr($tag, 0, $pos);
        else
            return $tag;
    }

    public static function split_index($tag) {
        if (!$tag)
            return array(false, false);
        else if (!($pos = strpos($tag, "#")) && !($pos = strpos($tag, "=")))
            return array($tag, false);
        else if ($pos == strlen($tag) - 1)
            return array(substr($tag, 0, $pos), false);
        else
            return array(substr($tag, 0, $pos), (float) substr($tag, $pos + 1));
    }

    public static function split($taglist) {
        preg_match_all(',\S+,', $taglist, $m);
        return $m[0];
    }

    public static function basic_check($tag) {
        return $tag !== "" && strlen($tag) <= TAG_MAXLEN
            && preg_match('{\A' . TAG_REGEX . '\z}', $tag);
    }

    public static function in_list($tag, $taglist) {
        if (is_string($taglist))
            $taglist = explode(" ", $taglist);
        list($base, $index) = self::split_index($tag);
        if (is_associative_array($taglist))
            return isset($taglist[$base]);
        else
            return in_array($base, $taglist);
    }

    private static function split_tlist($tl) {
        return array_map("TagInfo::split_index", self::split($tl));
    }

    private static function make_tagmap() {
        global $Conf;
        self::$tagmap = $map = new TagMap;
        if (!$Conf)
            return $map;
        $ct = $Conf->setting_data("tag_chair", "");
        foreach (self::split_tlist($ct) as $ti)
            $map->make($ti[0])->chair = true;
        foreach ($Conf->track_tags() as $t)
            $map->make(self::base($t))->chair = true;
        $ct = $Conf->setting_data("tag_sitewide", "");
        foreach (self::split_tlist($ct) as $ti)
            $map->make($ti[0])->sitewide = $map->has_sitewide = true;
        $vt = $Conf->setting_data("tag_vote", "");
        foreach (self::split_tlist($vt) as $ti) {
            $t = $map->make($ti[0]);
            $t->vote = ($ti[1] ? : 1);
            $t->votish = $map->has_vote = $map->has_votish = true;
        }
        $vt = $Conf->setting_data("tag_approval", "");
        foreach (self::split_tlist($vt) as $ti) {
            $t = $map->make($ti[0]);
            $t->approval = $t->votish = $map->has_approval = $map->has_votish = true;
        }
        $rt = $Conf->setting_data("tag_rank", "");
        foreach (self::split_tlist($rt) as $t)
            $map->make($ti[0])->rank = $map->has_rank = true;
        $ct = $Conf->setting_data("tag_color", "");
        if ($ct !== "")
            foreach (explode(" ", $ct) as $k)
                if ($k !== "" && ($p = strpos($k, "=")) !== false) {
                    arrayappend($map->make(substr($k, 0, $p))->colors,
                                self::canonical_color(substr($k, $p + 1)));
                    $map->has_colors = true;
                }
        $bt = $Conf->setting_data("tag_badge", "");
        if ($bt !== "")
            foreach (explode(" ", $bt) as $k)
                if ($k !== "" && ($p = strpos($k, "=")) !== false) {
                    arrayappend($map->make(substr($k, 0, $p))->badges,
                                self::canonical_color(substr($k, $p + 1)));
                    $map->has_badges = true;
                }
        $xt = $Conf->setting_data("tag_order_anno", "");
        if ($xt !== "" && ($xt = json_decode($xt)))
            foreach (get_object_vars($xt) as $t => $v)
                if (is_object($v)) {
                    $map->make($t)->order_anno = $v;
                    $map->has_order_anno = true;
                }
        return $map;
    }

    public static function defined_tags() {
        return self::$tagmap ? self::$tagmap : self::make_tagmap();
    }

    public static function defined_tags_with($property) {
        return self::defined_tags()->filter($property);
    }

    public static function defined_tag($tag) {
        $dt = self::defined_tags();
        return $dt->check(self::base($tag));
    }

    public static function make_defined_tag($tag) {
        $dt = self::defined_tags();
        return $dt->make(self::base($tag));
    }

    public static function invalidate_defined_tags() {
        self::$tagmap = self::$colorre = self::$badgere = self::$sitewidere_part = null;
    }

    public static function has_sitewide() {
        return self::defined_tags()->has_sitewide;
    }

    public static function has_vote() {
        return self::defined_tags()->has_vote;
    }

    public static function has_approval() {
        return self::defined_tags()->has_approval;
    }

    public static function has_votish() {
        return self::defined_tags()->has_votish;
    }

    public static function has_rank() {
        return self::defined_tags()->has_rank;
    }

    public static function has_badges() {
        return self::defined_tags()->has_badges;
    }

    public static function has_order_anno() {
        return self::defined_tags()->has_order_anno;
    }

    public static function is_chair($tag) {
        if ($tag[0] === "~")
            return $tag[1] === "~";
        else
            return !!self::defined_tags()->check_property($tag, "chair");
    }

    public static function is_sitewide($tag) {
        return !!self::defined_tags()->check_property($tag, "sitewide");
    }

    public static function sitewide_regex_part() {
        if (self::$sitewidere_part === null) {
            $x = ["\\&"];
            foreach (self::defined_tags_with("sitewide") as $t)
                $x[] = $t->tag_regex() . "[ #=]";
            self::$sitewidere_part = join("|", $x);
        }
        return self::$sitewidere_part;
    }

    public static function is_votish($tag) {
        return !!self::defined_tags()->check_property($tag, "votish");
    }

    public static function is_vote($tag) {
        return !!self::defined_tags()->check_property($tag, "vote");
    }

    public static function vote_setting($tag) {
        $t = self::defined_tags()->check_property($tag, "vote");
        return $t && $t->vote > 0 ? $t->vote : 0;
    }

    public static function is_approval($tag) {
        return !!self::defined_tags()->check_property($tag, "approval");
    }

    public static function votish_base($tag) {
        $dt = self::defined_tags();
        if (!$dt->has_votish
            || ($twiddle = strpos($tag, "~")) === false)
            return false;
        $tbase = substr(self::base($tag), $twiddle + 1);
        $t = $dt->check($tbase);
        return $t && $t->votish ? $tbase : false;
    }

    public static function is_rank($tag) {
        return !!self::defined_tags()->check_property($tag, "rank");
    }

    public static function unparse_anno_json($anno) {
        global $Conf;
        $j = (object) ["annoid" => $anno->annoId === null ? null : +$anno->annoId];
        if ($anno->tagIndex !== null)
            $j->tagval = (float) $anno->tagIndex;
        $j->heading = $anno->heading;
        if (($format = $Conf->check_format($anno->annoFormat, (string) $anno->heading)))
            $j->format = +$format;
        return $j;
    }

    public static function canonical_color($tag) {
        $tag = strtolower($tag);
        if ($tag === "violet")
            return "purple";
        else if ($tag === "grey")
            return "gray";
        else
            return $tag;
    }

    public static function color_tags($color = null) {
        $a = array();
        if ($color) {
            $canonical = self::canonical_color($color);
            $a[] = $canonical;
            if ($canonical === "purple")
                $a[] = "violet";
            else if ($canonical === "gray")
                $a[] = "grey";
            foreach (self::defined_tags() as $v)
                foreach ($v->colors ? : array() as $c)
                    if ($c === $canonical)
                        $a[] = $v->tag;
        } else {
            $a = explode("|", self::BASIC_COLORS);
            foreach (self::defined_tags() as $v)
                if ($v->colors)
                    $a[] = $v->tag;
        }
        return $a;
    }

    public static function color_regex() {
        if (!self::$colorre) {
            $re = "{(?:\\A| )(?:\\d*~|~~|)(" . self::BASIC_COLORS_PLUS;
            foreach (self::defined_tags_with("colors") as $v)
                $re .= "|" . $v->tag_regex();
            self::$colorre = $re . ")(?=\\z|[# ])}i";
        }
        return self::$colorre;
    }

    public static function color_classes($tags, $color_type_bit = 1) {
        if (is_array($tags))
            $tags = join(" ", $tags);
        if (!$tags || $tags === " ")
            return "";
        if (!preg_match_all(self::color_regex(), $tags, $m))
            return false;
        $dt = self::defined_tags();
        $classes = array();
        foreach ($m[1] as $tag)
            if (($t = $dt->check($tag)) && $t->colors) {
                foreach ($t->colors as $k)
                    $classes[] = $k . "tag";
            } else
                $classes[] = self::canonical_color($tag) . "tag";
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

    public static function classes_have_colors($classes) {
        return preg_match('_\b(?:\A|\s)(?:red|orange|yellow|green|blue|purple|gray|white)tag(?:\z|\s)_', $classes);
    }


    public static function badge_regex() {
        if (!self::$badgere) {
            $re = "{(?:\\A| )(?:\\d*~|~~|)(";
            foreach (self::defined_tags_with("badges") as $t)
                $re .= "|" . $t->tag_regex();
            self::$badgere = $re . ")(?:#[\\d.]+)?(?=\\z| )}i";
        }
        return self::$badgere;
    }


    private static $value_increment_map = array(1, 1, 1, 1, 1, 2, 2, 2, 3, 4);

    public static function value_increment($mode) {
        if (strlen($mode) == 2)
            return self::$value_increment_map[mt_rand(0, 9)];
        else
            return 1;
    }


    public static function id_index_compar($a, $b) {
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
    private $contact = null;
    private $_contactId = 0;

    public function __construct($contact = null) {
        global $Me;
        $this->contact = ($contact ? : $Me);
        if ($this->contact && $this->contact->contactId > 0)
            $this->_contactId = $this->contact->contactId;
    }

    private function set_error($e) {
        $this->error_html = $e;
        return false;
    }

    public function check($tag, $flags = 0) {
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
            if ($m[1] !== "~" && !($flags & self::ALLOWCONTACTID)
                && (!$this->_contactId || $m[1] !== $this->_contactId . "~"))
                return $this->set_error("Format error: #{$tag} is an invalid tag.");
            if ($m[1] === "~" && $this->_contactId)
                $m[1] = $this->_contactId . "~";
            if (!($flags & self::NOCHAIR) && $m[1] !== "~"
                && $m[1] !== $this->_contactId . "~")
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

    public function view_score($tag) {
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


    static public function strip_nonviewable($tags, Contact $user = null) {
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

    static public function strip_nonsitewide($tags, Contact $user) {
        $re = "{ (?:(?!" . $user->contactId . "~)\\d+~|~+|(?!"
            . TagInfo::sitewide_regex_part() . ")\\S)\\S*}i";
        return trim(preg_replace($re, "", " $tags "));
    }

    public function unparse($tags) {
        if ($tags === "" || (is_array($tags) && count($tags) == 0))
            return "";
        if (is_array($tags))
            $tags = join(" ", $tags);
        $tags = str_replace("#0 ", " ", " $tags ");
        if ($this->_contactId)
            $tags = str_replace(" " . $this->_contactId . "~", " ~", $tags);
        return trim($tags);
    }

    public function unparse_hashed($tags) {
        if (($tags = $this->unparse($tags)) !== "")
            $tags = str_replace(" ", " #", "#" . $tags);
        return $tags;
    }

    public function unparse_badges_html($tags) {
        if (is_array($tags))
            $tags = join(" ", $tags);
        if (!$tags || $tags === " " || !TagInfo::has_badges())
            return "";
        if (!preg_match_all(TagInfo::badge_regex(), $tags, $m, PREG_SET_ORDER))
            return false;
        $dt = TagInfo::defined_tags();
        $x = "";
        foreach ($m as $mx)
            if (($t = $dt->check($mx[1])) && $t->badges) {
                $tag = $this->unparse(trim($mx[0]));
                $b = '<span class="badge ' . $t->badges[0] . 'badge">#' . $tag . '</span>';
                if (($link = $this->link($tag)))
                    $b = '<a class="qq" href="' . $link . '">' . $b . '</a>';
                $x .= $b;
            }
        return $x;
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

    public function tag_compare($a, $b) {
        return strcasecmp($this->trim_for_sort($a), $this->trim_for_sort($b));
    }

    public function sort(&$tags) {
        usort($tags, array($this, "tag_compare"));
    }

    public function link($tag) {
        if (ctype_digit($tag[0])) {
            $x = strlen($this->_contactId);
            if (substr($tag, 0, $x) != $this->_contactId || $tag[$x] !== "~")
                return false;
            $tag = substr($tag, $x);
        }
        $base = TagInfo::base($tag);
        if (TagInfo::has_votish()
            && (TagInfo::is_votish($base)
                || ($base[0] === "~" && TagInfo::is_vote(substr($base, 1)))))
            $q = "#$base showsort:-#$base";
        else if ($base === $tag)
            $q = "#$base";
        else
            $q = "order:#$base";
        return hoturl("search", ["q" => $q]);
    }

    public function unparse_and_link($viewable, $alltags, $highlight = false) {
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
