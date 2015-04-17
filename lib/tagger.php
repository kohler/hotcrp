<?php
// tagger.php -- HotCRP helper class for dealing with tags
// HotCRP is Copyright (c) 2006-2015 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

// Note that tags MUST NOT contain HTML or URL special characters:
// no "'&<>.  If you add PHP-protected characters, such as $, make sure you
// check for uses of eval().

class TagMap implements ArrayAccess, IteratorAggregate {
    public $nchair = 0;
    public $nvote = 0;
    public $nrank = 0;
    private $storage = array();
    private $sorted = false;
    public function offsetExists($offset) {
        return isset($this->storage[strtolower($offset)]);
    }
    public function offsetGet($offset) {
        $loffset = strtolower($offset);
        if (!isset($this->storage[$loffset])) {
            $n = (object) array("tag" => $offset, "chair" => false, "vote" => false, "rank" => false, "colors" => null);
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
        return @$this->storage[strtolower($offset)];
    }
}

class TagInfo {

    const ALLOWRESERVED = 1;
    const NOPRIVATE = 2;
    const NOVALUE = 4;
    const NOCHAIR = 8;
    const ALLOWSTAR = 16;

    private static $tagmap = null;
    private static $colorre = null;

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
            return array(substr($tag, 0, $pos), (int) substr($tag, $pos + 1));
    }

    public static function split($taglist) {
        preg_match_all(',\S+,', $taglist, $m);
        return $m[0];
    }

    public static function basic_check($tag) {
        return $tag != "" && strlen($tag) <= TAG_MAXLEN
            && preg_match('{\A' . TAG_REGEX . '\z}', $tag);
    }

    private static function make_tagmap() {
        global $Conf;
        self::$tagmap = $map = new TagMap;
        if (!$Conf)
            return $map;
        $ct = $Conf->setting_data("tag_chair", "");
        foreach (preg_split('/\s+/', $ct) as $t)
            if ($t != "" && !$map[self::base($t)]->chair) {
                $map[self::base($t)]->chair = true;
                ++$map->nchair;
            }
        foreach ($Conf->track_tags() as $t)
            if (!$map[self::base($t)]->chair) {
                $map[self::base($t)]->chair = true;
                ++$map->nchair;
            }
        $vt = $Conf->setting_data("tag_vote", "");
        if ($vt != "")
            foreach (preg_split('/\s+/', $vt) as $t)
                if ($t != "") {
                    list($b, $v) = self::split_index($t);
                    $map[$b]->vote = ($v ? $v : 1);
                    ++$map->nvote;
                }
        $rt = $Conf->setting_data("tag_rank", "");
        if ($rt != "")
            foreach (preg_split('/\s+/', $rt) as $t) {
                $map[self::base($t)]->rank = true;
                ++$map->nrank;
            }
        $ct = $Conf->setting_data("tag_color", "");
        if ($ct != "")
            foreach (explode(" ", $ct) as $k)
                if ($k != "" && ($p = strpos($k, "=")) !== false)
                    arrayappend($map[substr($k, 0, $p)]->colors,
                                self::canonical_color(substr($k, $p + 1)));
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

    public static function has_vote() {
        return self::defined_tags()->nvote;
    }

    public static function has_rank() {
        return self::defined_tags()->nrank;
    }

    public static function is_chair($tag) {
        if ($tag[0] == "~")
            return $tag[1] == "~";
        $dt = self::defined_tags();
        $t = $dt->check(self::base($tag));
        return $t && $t->chair;
    }

    public static function chair_tags() {
        return self::defined_tags()->tag_array("chair");
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

    public static function vote_base($tag) {
        $dt = self::defined_tags();
        if (!$dt->nvote || ($twiddle = strpos($tag, "~")) === false)
            return false;
        $tbase = substr(self::base($tag), $twiddle + 1);
        $t = $dt->check($tbase);
        return $t && $t->vote ? $tbase : false;
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
        if (strcasecmp($tag, "violet") == 0)
            return "purple";
        else if (strcasecmp($tag, "grey") == 0)
            return "gray";
        else
            return $tag;
    }

    public static function color_regex() {
        if (!self::$colorre) {
            $re = "{(?:\\A| )(?:\\d*~|~~|)(red|orange|yellow|green|blue|purple|violet|grey|gray|white|dim|bold|italic|underline|strikethrough|big|small";
            foreach (self::defined_tags() as $v)
                if ($v->colors)
                    $re .= "|" . $v->tag;
            self::$colorre = $re . ")(?=\\z|[# ])}";
        }
        return self::$colorre;
    }

    public static function color_classes($tags, $colors_only = false) {
        if (is_array($tags))
            $tags = join(" ", $tags);
        if (!$tags || $tags === " ")
            return "";
        if (!preg_match_all(self::color_regex(), strtolower($tags), $m))
            return false;
        $dt = self::defined_tags();
        $classes = array();
        foreach ($m[1] as $tag)
            if (($t = $dt->check($tag)) && $t->colors) {
                foreach ($t->colors as $k)
                    $classes[] = $k . "tag";
            } else
                $classes[] = self::canonical_color($tag) . "tag";
        if ($colors_only)
            $classes = array_filter($classes, "TagInfo::classes_have_colors");
        return join(" ", $classes);
    }

    public static function classes_have_colors($classes) {
        return preg_match('_\b(?:\A|\s)(?:red|orange|yellow|green|blue|purple|gray|white)tag(?:\z|\s)_', $classes);
    }


    private static $value_increment_map = array(1, 1, 1, 1, 1, 2, 2, 2, 3, 4);

    public static function value_increment($mode) {
        if (strlen($mode) == 2)
            return self::$value_increment_map[mt_rand(0, 9)];
        else
            return 1;
    }

}

class Tagger {

    const ALLOWRESERVED = 1;
    const NOPRIVATE = 2;
    const NOVALUE = 4;
    const NOCHAIR = 8;
    const ALLOWSTAR = 16;

    public $error_html = false;
    private $contact = null;
    private $_contactId = 0;

    public function __construct($contact = null) {
        global $Me;
        $this->contact = ($contact ? $contact : $Me);
        if ($this->contact && $this->contact->contactId > 0)
            $this->_contactId = $this->contact->contactId;
    }

    private static function analyze($tag, $flags) {
        if ($tag == "")
            return "Empty tag.";
        else if (!preg_match('/\A' . TAG_REGEX_OPTVALUE . '\z/', $tag, $m)
                 || (!($flags & self::ALLOWSTAR) && strpos($tag, "*") !== false))
            return "Tag “" . htmlspecialchars($tag) . "” contains characters not allowed in tags.";
        else if (strlen($tag) > TAG_MAXLEN)
            return "Tag “${tag}” is too long; maximum " . TAG_MAXLEN . " characters.";
        else if (count($m) > 1 && $m[1] && ($flags & self::NOVALUE))
            return "Tag values aren’t allowed here.";
        else if ($tag[0] === "~" && $tag[1] === "~" && ($flags & self::NOCHAIR))
            return "Tag “${tag}” is exclusively for chairs.";
        else if ($tag[0] === "~" && $tag[1] !== "~" && ($flags & self::NOPRIVATE))
            return "Twiddle tags aren’t allowed here.";
        else if (!($flags & self::ALLOWRESERVED) && strlen($tag) <= 4
                 && preg_match('/\A(?:none|any)\z/i', $tag))
            return "Tag “${tag}” is reserved.";
        else
            return false;
    }

    public function check($tag, $flags = 0) {
        if (!($this->contact && $this->contact->privChair))
            $flags |= self::NOCHAIR;
        if ($tag[0] == "#")
            $tag = substr($tag, 1);
        if (($this->error_html = self::analyze($tag, $flags)))
            return false;
        else if ($tag[0] == "~" && $tag[1] != "~" && $this->_contactId)
            return $this->_contactId . $tag;
        else
            return $tag;
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


    public function viewable($tags) {
        if (strpos($tags, "~") !== false) {
            $re = "{ (?:";
            if ($this->_contactId)
                $re .= "(?!" . $this->_contactId . "~)";
            $re .= "\\d+~";
            if (!($this->contact && $this->contact->privChair))
                $re .= "|~+";
            $tags = trim(preg_replace($re . ")\\S+}", "", " $tags "));
        }
        return $tags;
    }

    public function paper_editable($prow) {
        $tags = $this->viewable($prow->all_tags_text());
        if ($tags != "") {
            $privChair = $this->contact
                && $this->contact->allow_administer($prow);
            $dt = TagInfo::defined_tags();
            $etags = array();
            foreach (explode(" ", $tags) as $t)
                if (!($t === ""
                      || (($v = $dt->check(TagInfo::base($t)))
                          && ($v->vote
                              || ($v->chair && !$privChair)
                              || ($v->rank && !$privChair)))))
                    $etags[] = $t;
            $tags = join(" ", $etags);
        }
        return $tags;
    }

    public function unparse($tags) {
        if ($tags == "" || (is_array($tags) && count($tags) == 0))
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

    private function trim_for_sort($x) {
        if ($x[0] == "#")
            $x = substr($x, 1);
        if ($x[0] == "~" && $x[1] != "~")
            $x = $this->_contactId . $x;
        else if ($x[0] == "~")
            $x = ";" . $x;
        return $x;
    }

    public function sorter($a, $b) {
        return strcasecmp($this->trim_for_sort($a), $this->trim_for_sort($b));
    }

    public function sort(&$tags) {
        usort($tags, array($this, "sorter"));
    }

    public function unparse_and_link($viewable, $alltags, $highlight = false,
                                     $votereport = false) {
        $vtags = $this->unparse($viewable);
        if ($vtags == "")
            return "";

        // track votes for vote report
        $dt = TagInfo::defined_tags();
        if ($votereport && $dt->nvote) {
            preg_match_all('{ (\d+)~(\S+)#([1-9]\d*)}',
                           strtolower(" $alltags"), $m, PREG_SET_ORDER);
            $vote = array();
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
                $tag = $h->tag;
                if (($pos = strpos($tag, "~")) !== false)
                    $tag = substr($tag, $pos);
                $byhighlight[strtolower($tag)] = "";
            }

        foreach (preg_split('/\s+/', $vtags) as $tag) {
            if (!($base = TagInfo::base($tag)))
                continue;
            $lbase = strtolower($base);
            if (TagInfo::is_vote($lbase)) {
                $v = array();
                if ($votereport)
                    foreach (pcMembers() as $pcm)
                        if (($count = defval($vote[$lbase], $pcm->contactId, 0)) > 0)
                            $v[] = Text::name_html($pcm) . ($count > 1 ? " ($count)" : "");
                $title = ($v ? "PC votes: " . join(", ", $v) : "Vote search");
                $link = "rorder:";
            } else if ($base[0] === "~" && TagInfo::is_vote(substr($lbase, 1))) {
                $title = "Vote search";
                $link = "rorder:";
            } else {
                $title = "Tag search";
                $link = ($base === $tag ? "%23" : "order:");
            }
            $tx = "<a class=\"q\" href=\"" . hoturl("search", "q=$link$base") . "\" title=\"$title\">" . $base . "</a>" . substr($tag, strlen($base));
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
