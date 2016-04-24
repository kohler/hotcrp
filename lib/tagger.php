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
    public function order_anno_list() {
        if ($this->order_anno && $this->order_anno_list == false) {
            $this->order_anno_list = Dbl::fetch_objects("select * from PaperTagAnno where tag=?", $this->tag);
            $this->order_anno_list[] = (object) ["tag" => $this->tag, "tagIndex" => TAG_INDEXBOUND, "heading" => "Untagged", "annoId" => null];
            usort($this->order_anno_list, function ($a, $b) {
                return $a->tagIndex < $b->tagIndex ? -1 : ($a->tagIndex > $b->tagIndex ? 1 : 0);
            });
        }
        return $this->order_anno_list;
    }
    public function order_anno_entry($i) {
        return get($this->order_anno_list(), $i);
    }
}

class TagMap implements ArrayAccess, IteratorAggregate {
    public $nchair = 0;
    public $nvote = 0;
    public $napproval = 0;
    public $nsitewide = 0;
    public $nrank = 0;
    public $nbadge = 0;
    public $norder_anno = 0;
    private $storage = array();
    private $sorted = false;
    public function offsetExists($offset) {
        return isset($this->storage[strtolower($offset)]);
    }
    public function offsetGet($offset) {
        $loffset = strtolower($offset);
        if (!isset($this->storage[$loffset])) {
            $n = new TagMapItem($offset);
            if (!TagInfo::basic_check($loffset))
                return $n;
            $this->storage[$loffset] = $n;
            $this->sorted = false;
        }
        return $this->storage[$loffset];
    }
    public function offsetSet($offset, $value) {
    }
    public function offsetUnset($offset) {
    }
    private function sort() {
        ksort($this->storage);
        $this->sorted = true;
    }
    public function getIterator() {
        $this->sorted || $this->sort();
        return new ArrayIterator($this->storage);
    }
    public function tag_array($property) {
        $a = array();
        $this->sorted || $this->sort();
        foreach ($this->storage as $v)
            if ($v->$property)
                $a[$v->tag] = $v->$property;
        return $a;
    }
    public function check($offset) {
        return get($this->storage, strtolower($offset));
    }
}

class TagInfo {
    const BASIC_COLORS = "red|orange|yellow|green|blue|purple|gray|white|bold|italic|underline|strikethrough|big|small|dim";
    const BASIC_COLORS_PLUS = "red|orange|yellow|green|blue|purple|violet|grey|gray|white|bold|italic|underline|strikethrough|big|small|dim";
    const BASIC_BADGES = "normal|red|green|blue|white";

    private static $tagmap = null;
    private static $colorre = null;
    private static $badgere = null;

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

    private static function make_tagmap() {
        global $Conf;
        self::$tagmap = $map = new TagMap;
        if (!$Conf)
            return $map;
        $ct = $Conf->setting_data("tag_chair", "");
        foreach (preg_split('/\s+/', $ct) as $t)
            if ($t !== "" && !$map[self::base($t)]->chair) {
                $map[self::base($t)]->chair = true;
                ++$map->nchair;
            }
        foreach ($Conf->track_tags() as $t)
            if (!$map[self::base($t)]->chair) {
                $map[self::base($t)]->chair = true;
                ++$map->nchair;
            }
        $ct = $Conf->setting_data("tag_sitewide", "");
        foreach (preg_split('/\s+/', $ct) as $t)
            if ($t !== "" && !$map[self::base($t)]->sitewide) {
                $map[self::base($t)]->sitewide = true;
                ++$map->nsitewide;
            }
        $vt = $Conf->setting_data("tag_vote", "");
        if ($vt !== "")
            foreach (preg_split('/\s+/', $vt) as $t)
                if ($t !== "") {
                    list($b, $v) = self::split_index($t);
                    $map[$b]->vote = ($v ? $v : 1);
                    ++$map->nvote;
                }
        $vt = $Conf->setting_data("tag_approval", "");
        if ($vt !== "")
            foreach (preg_split('/\s+/', $vt) as $t)
                if ($t !== "") {
                    list($b, $v) = self::split_index($t);
                    $map[$b]->approval = true;
                    ++$map->napproval;
                }
        $rt = $Conf->setting_data("tag_rank", "");
        if ($rt !== "")
            foreach (preg_split('/\s+/', $rt) as $t) {
                $map[self::base($t)]->rank = true;
                ++$map->nrank;
            }
        $ct = $Conf->setting_data("tag_color", "");
        if ($ct !== "")
            foreach (explode(" ", $ct) as $k)
                if ($k !== "" && ($p = strpos($k, "=")) !== false)
                    arrayappend($map[substr($k, 0, $p)]->colors,
                                self::canonical_color(substr($k, $p + 1)));
        $bt = $Conf->setting_data("tag_badge", "");
        if ($bt !== "")
            foreach (explode(" ", $bt) as $k)
                if ($k !== "" && ($p = strpos($k, "=")) !== false) {
                    arrayappend($map[substr($k, 0, $p)]->badges,
                                self::canonical_color(substr($k, $p + 1)));
                    ++$map->nbadge;
                }
        $xt = $Conf->setting_data("tag_order_anno", "");
        if ($xt !== "" && ($xt = json_decode($xt)))
            foreach (get_object_vars($xt) as $t => $v)
                if (is_object($v)) {
                    $map[$t]->order_anno = $v;
                    ++$map->norder_anno;
                }
        return $map;
    }

    public static function defined_tags() {
        return self::$tagmap ? self::$tagmap : self::make_tagmap();
    }

    public static function defined_tag($tag) {
        $dt = self::defined_tags();
        return $dt->check(self::base($tag));
    }

    public static function invalidate_defined_tags() {
        self::$tagmap = self::$colorre = null;
    }

    public static function has_sitewide() {
        return !!self::defined_tags()->nsitewide;
    }

    public static function has_vote() {
        return !!self::defined_tags()->nvote;
    }

    public static function has_approval() {
        return !!self::defined_tags()->napproval;
    }

    public static function has_votish() {
        return self::defined_tags()->nvote || self::defined_tags()->napproval;
    }

    public static function has_rank() {
        return !!self::defined_tags()->nrank;
    }

    public static function has_badges() {
        return !!self::defined_tags()->nbadge;
    }

    public static function has_order_anno() {
        return !!self::defined_tags()->norder_anno;
    }

    public static function is_chair($tag) {
        if ($tag[0] === "~")
            return $tag[1] === "~";
        $dt = self::defined_tags();
        $t = $dt->check(self::base($tag));
        return $t && $t->chair;
    }

    public static function chair_tags() {
        return self::defined_tags()->tag_array("chair");
    }

    public static function is_sitewide($tag) {
        $dt = self::defined_tags();
        $t = $dt->check(self::base($tag));
        return $t && $t->sitewide;
    }

    public static function sitewide_tags() {
        return self::defined_tags()->tag_array("sitewide");
    }

    public static function is_votish($tag) {
        $dt = self::defined_tags();
        $t = $dt->check(self::base($tag));
        return $t && ($t->vote || $t->approval);
    }

    public static function is_vote($tag) {
        $dt = self::defined_tags();
        $t = $dt->check(self::base($tag));
        return $t && $t->vote;
    }

    public static function vote_tags() {
        $dt = self::defined_tags();
        return $dt->nvote ? $dt->tag_array("vote") : array();
    }

    public static function vote_setting($tag) {
        $dt = self::defined_tags();
        $t = $dt->check(self::base($tag));
        return $t && $t->vote > 0 ? $t->vote : 0;
    }

    public static function is_approval($tag) {
        $dt = self::defined_tags();
        $t = $dt->check(self::base($tag));
        return $t && $t->approval;
    }

    public static function approval_tags() {
        $dt = self::defined_tags();
        return $dt->napproval ? $dt->tag_array("approval") : array();
    }

    public static function votish_base($tag) {
        $dt = self::defined_tags();
        if ((!$dt->nvote && !$dt->napproval)
            || ($twiddle = strpos($tag, "~")) === false)
            return false;
        $tbase = substr(self::base($tag), $twiddle + 1);
        $t = $dt->check($tbase);
        return $t && ($t->vote || $t->approval) ? $tbase : false;
    }

    public static function is_rank($tag) {
        $dt = self::defined_tags();
        $t = $dt->check(self::base($tag));
        return $t && $t->rank;
    }

    public static function rank_tags() {
        $dt = self::defined_tags();
        return $dt->nrank ? $dt->tag_array("rank") : array();
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
            foreach (self::defined_tags() as $v)
                if ($v->colors)
                    $re .= "|" . $v->tag;
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
            foreach (self::defined_tags() as $v)
                if ($v->badges)
                    $re .= "|" . $v->tag;
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
            if ($m[1] !== "~" && !($flags & self::ALLOWCONTACTID))
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
        $x = ["\\&"];
        foreach (TagInfo::sitewide_tags() as $t => $tinfo)
            $x[] = preg_quote($t) . "[ #=]";
        $re = "{ (?:(?!" . $user->contactId . "~)\\d+~|~+|(?!"
            . join("|", $x) . ")\\S)\\S*}i";
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

    public function unparse_and_link($viewable, $alltags, $highlight = false,
                                     $votereport = false) {
        $vtags = $this->unparse($viewable);
        if ($vtags === "")
            return "";

        // track votes for vote report
        $dt = TagInfo::defined_tags();
        $vote = array();
        if ($votereport && ($dt->nvote || $dt->napproval)) {
            preg_match_all('{ (\d+)~(\S+)#(\d+)}',
                           strtolower(" $alltags"), $m, PREG_SET_ORDER);
            foreach ($m as $x)
                $vote[$x[2]][$x[1]] = (int) $x[3];
        }

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
            if (($link = $this->link($tag))) {
                $tx = '<a class="qq nw" href="' . $link . '"';
                if (count($vote) && TagInfo::is_votish($base)) {
                    $v = array();
                    $limit = TagInfo::is_vote($base) ? 1 : 0;
                    foreach (pcMembers() as $p)
                        if (($count = get($vote[$lbase], $p->contactId, $limit - 1)) >= $limit)
                            $v[] = Text::name_html($p) . ($count > 1 ? " ($count)" : "");
                    if (count($v))
                        $tx .= ' title="PC votes: ' . htmlspecialchars(join(", ", $v)) . '"';
                }
                $tx .= '>#' . $base . '</a>';
            } else
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
