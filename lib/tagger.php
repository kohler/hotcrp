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
            $re = "{(?:\\A| )(?:\\d*~|~~|)(red|orange|yellow|green|blue|purple|violet|grey|gray|white|dim|bold|italic|big|small";
            foreach (self::defined_tags() as $v)
                if ($v->colors)
                    $re .= "|" . $v->tag;
            self::$colorre = $re . ")(?=\\z|[# ])}";
        }
        return self::$colorre;
    }

    public static function color_classes($tags) {
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
        return join(" ", $classes);
    }

    public static function classes_have_colors($classes) {
        return preg_match('_\b(?:\A|\s)(?:red|orange|yellow|green|blue|purple|gray|white|dim)tag(?:\z|\s)_', $classes);
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


    // $mode is one of:
    //   p -- set all tags (= delete all tags from $pids, then add tags in $tagtext)
    //   a -- add
    //   ao -- add to order
    //   aos -- add to gapless [strict] order
    //   s -- define (= remove tags from all papers, then add to papers in $pids)
    //   so -- define order
    //   sos -- define gapless order
    //   sor -- define random order
    //   d -- delete tags
    //   da -- delete ALL tags, including twiddles (chair only)
    function save($pids, $tagtext, $mode) {
        global $Conf, $Error;
        list($table, $pidcol) = array("PaperTag", "paperId");
        $mytagprefix = $this->_contactId . "~";

        // check chairness
        if (!is_array($pids))
            $pids = array($pids);

        // check modes
        if ($mode == "da" && !$this->contact->privChair)
            $mode = "d";

        // check tags for direct validity
        $ok = true;
        $tags = array();
        foreach (preg_split('/\s+/', $tagtext) as $tag) {
            if ($tag != "" && ($tag = $this->check($tag)))
                $tags[] = $tag;
            else if ($tag != "") {
                defappend($Error["tags"], $this->error_html . "<br />\n");
                $ok = false;
            }
        }
        if (!$ok)
            return false;

        // lock
        $Conf->qe("lock tables $table write");

        // check chair tags
        $badtags = array();
        if (!$this->contact->privChair) {
            $nexttags = array();
            foreach ($tags as $tag) {
                if (TagInfo::is_chair($tag)) {
                    defappend($Error["tags"], "Only the chair can change tag “" . htmlspecialchars(TagInfo::base($tag)) . "”.<br />\n");
                    $badtags[] = $tag;
                } else
                    $nexttags[] = $tag;
            }
            $tags = $nexttags;
        }

        // check vote tags
        $vchanges = array();
        if (TagInfo::has_vote()) {
            $nexttags = array();
            $multivote = 0;
            foreach ($tags as $tag) {
                $base = TagInfo::base($tag);
                $lbase = strtolower($base);
                $twiddle = strpos($base, "~");
                if (TagInfo::is_vote($base)) {
                    $baseview = htmlspecialchars($base);
                    defappend($Error["tags"], "The shared tag “{$baseview}” keeps track of vote totals and cannot be modified.  Use the private tag “~{$baseview}” to change your vote (for instance, “~{$baseview}#1” is one vote).<br />\n");
                    $badtags[] = $tag;
                } else if ($twiddle > 0 && TagInfo::is_vote(substr($base, $twiddle + 1))) {
                    if (isset($vchanges[$lbase])) // only one vote per tag
                        $multivote++;
                    else {
                        if (strlen($base) == strlen($tag)
                            && $mode != "d" && $mode != "da")
                            $tag .= "#1";
                        $nexttags[] = $tag;
                        $vchanges[$lbase] = 0;
                    }
                } else
                    $nexttags[] = $tag;
            }
            $tags = $nexttags;
        }

        // check rank tag
        if (!$this->contact->privChair && TagInfo::has_rank()) {
            $nexttags = array();
            foreach ($tags as $tag) {
                if (TagInfo::is_rank($tag)) {
                    $baseview = htmlspecialchars(TagInfo::base($tag));
                    defappend($Error["tags"], "The shared tag “{$baseview}” keeps track of the global ranking and cannot be modified.  Use the private tag “~{$baseview}” to change your ranking.<br />\n");
                    $badtags[] = $tag;
                } else
                    $nexttags[] = $tag;
            }
            $tags = $nexttags;
        }

        // exit if nothing to do
        if (count($tags) == 0 && $mode != 'p') {
            $Conf->qe("unlock tables");
            if (count($badtags) == 0)
                defappend($Error["tags"], "No tags specified.<br />\n");
            return false;
        }

        // delete tags
        if ($mode != "a" && $mode != "ao" && $mode != "aos") {
            $q = "delete from $table where ";
            if ($mode == "s" || $mode == "so" || $mode == "sos" || $mode == "sor")
                $q .= "true";
            else
                $q .= "$pidcol in (" . join(",", $pids) . ")";
            $dels = array();
            if ($mode != "p") {
                foreach ($tags as $tag) {
                    $ts = TagInfo::split_index($tag);
                    $qx = "";
                    if ($ts[1] !== false && ($mode == "d" || $mode == "da"))
                        $qx = " and tagIndex=$ts[1]";
                    if ($mode == "da"
                        && substr($ts[0], 0, strlen($mytagprefix)) === $mytagprefix) {
                        $dels[] = "(tag like '%~" . sqlq_for_like(substr($ts[0], strlen($mytagprefix))) . "'$qx)";
                        continue;
                    } else if ($mode == "da")
                        $dels[] = "(tag like '%~" . sqlq_for_like($ts[0]) . "'$qx)";
                    $dels[] = "(tag='" . sqlq($ts[0]) . "'$qx)";
                }
                $q .= " and (" . join(" or ", $dels) . ")";
            } else {
                if (!$this->contact->privChair) {
                    foreach (TagInfo::chair_tags() as $ct => $x)
                        if ($ct[0] == '~')
                            $q .= " and tag!='" . $this->_contactId . sqlq($ct) . "'";
                        else
                            $q .= " and tag!='" . sqlq($ct) . "'";
                }
                $q .= " and (tag like '$mytagprefix%' or tag not like '%~%'";
                if ($this->contact->privChair)
                    $q .= " or tag like '~~%'";
                $q .= ")";
            }
            $Conf->qe($q);
        }

        // check for vote changes
        if (count($vchanges) && $mode != "d" && $mode != "da") {
            $q = "";
            foreach ($vchanges as $base => &$val) {
                $q .= ($q === "" ? "" : ",") . "'" . sqlq($base) . "'";
                $val = TagInfo::vote_setting(substr($base, strpos($base, "~") + 1));
            }
            unset($val);
            if ($mode != "p")   // must delete old versions for correct totals
                $Conf->qe("delete from $table where $pidcol in (" . join(",", $pids) . ") and tag in ($q)");
            $result = $Conf->qe("select tag, sum(tagIndex) from $table where tag in ($q) group by tag");
            while (($row = edb_row($result))) {
                $lbase = strtolower($row[0]);
                $vchanges[$lbase] = max($vchanges[$lbase] - $row[1], 0);
            }
        }

        // extract tag indexes into a separate array
        $tagIndex = array();
        $explicitIndex = array();
        $modeOrdered = ($mode == "so" || $mode == "ao" || $mode == "sos"
                        || $mode == "sor" || $mode == "aos");
        foreach ($tags as $tag) {
            $base = TagInfo::base($tag);
            $lbase = strtolower($base);
            if (strlen($base) + 1 < strlen($tag)) {
                $tagIndex[$lbase] = $explicitIndex[$lbase] =
                    (int) substr($tag, strlen($base) + 1);
            } else if (strlen($base) + 1 == strlen($tag) || $modeOrdered) {
                $result = $Conf->qe("select max(tagIndex) from $table where tag='" . sqlq($base) . "'");
                if (($row = edb_row($result)))
                    $tagIndex[$lbase] = $row[0] + TagInfo::value_increment($mode);
                else
                    $tagIndex[$lbase] = TagInfo::value_increment($mode);
            }
        }

        // if inserting tags into an order, shift existing tags
        $reorders = array();
        if (($mode == "ao" || $mode == "aos") && count($explicitIndex)) {
            $q = "";
            foreach ($explicitIndex as $base => $index)
                $q .= "(tag='" . sqlq($base) . "' and tagIndex>=$index) or ";
            $result = $Conf->qe("select $pidcol, tag, tagIndex from $table where " . substr($q, 0, strlen($q) - 4) . " order by tagIndex");
            while (($row = edb_row($result)))
                if (!in_array($row[0], $pids))
                    $reorders[$row[1]][] = array($row[0], $row[2]);
        }

        // add tags
        $vreduced = array();
        if ($mode != "d" && $mode != "da" && count($tags)) {
            $q_keepold = $q_keepnew = "";
            $delvotes = array();
            foreach ($tags as $tag) {
                if ($mode == "sor")
                    shuffle($pids);
                foreach ($pids as $pid) {
                    $base = TagInfo::base($tag);
                    $lbase = strtolower($base);
                    // choose index, bump running index in ordered mode
                    $index = defval($tagIndex, $lbase, 0);
                    if ($modeOrdered)
                        $tagIndex[$lbase] += TagInfo::value_increment($mode);
                    // check vote totals
                    if (isset($vchanges[$lbase])) {
                        if ($index > $vchanges[$lbase]) {
                            $vmarker = substr($lbase, strpos($base, "~"));
                            $vreduced[$vmarker] = @max($vreduced[$vmarker], $index - $vchanges[$lbase]);
                            $index = $vchanges[$lbase];
                        } else if ($index < 0) // no negative votes, smarty
                            $index = 0;
                        $vchanges[$lbase] -= $index;
                        if ($index == 0) {
                            $delvotes[] = "($pidcol=$pid and tag='" . sqlq($base) . "')";
                            continue;
                        }
                    }
                    // add to the right query, which differ in behavior on setting
                    // a tag that's already set.  $q_keepnew keeps the new value,
                    // $q_keepold keeps the old value.
                    $thisq = "($pid, '" . sqlq($base) . "', " . $index . "), ";
                    if (isset($explicitIndex[$lbase]))
                        $q_keepnew .= $thisq;
                    else
                        $q_keepold .= $thisq;
                }
            }
            // if adding ordered tags in the middle of an order, reorder old tags
            foreach ($reorders as $base => $pairs) {
                $lbase = strtolower($base);
                $last = null;
                foreach ($pairs as $p)
                    if ($p[1] < $tagIndex[$lbase]) {
                        $thisq = "($p[0], '" . sqlq($base) . "', " . $tagIndex[$lbase] . "), ";
                        $q_keepnew .= $thisq;
                        if ($last === null || $last != $p[1])
                            $tagIndex[$lbase] += TagInfo::value_increment($mode);
                        $last = $p[1];
                    } else
                        break;
            }
            // store changes
            if ($q_keepnew != "")
                $Conf->qe("insert into $table ($pidcol, tag, tagIndex) values " . substr($q_keepnew, 0, strlen($q_keepnew) - 2) . " on duplicate key update tagIndex=values(tagIndex)");
            if ($q_keepold != "")
                $Conf->qe("insert into $table ($pidcol, tag, tagIndex) values " . substr($q_keepold, 0, strlen($q_keepold) - 2) . " on duplicate key update tagIndex=tagIndex");
            if (count($delvotes))
                $Conf->qe("delete from $table where " . join(" or ", $delvotes));
        }

        // update vote totals
        if (count($vchanges) > 0 || (TagInfo::has_vote() && $mode == "p")) {
            // Can't "insert from ... select ..." or "create temporary table"
            // because those unlock tables implicitly.

            // Find relevant vote tags.
            if ($mode == "p")
                $myvtags = array_keys(TagInfo::vote_tags());
            else {
                $myvtags = array();
                foreach ($vchanges as $tag => $val) {
                    $base = (($p = strpos($tag, "~")) !== false ? substr($tag, $p + 1) : $tag);
                    $myvtags[] = $base;
                }
            }
            $vtag_casemap = array();
            foreach ($myvtags as $tag)
                $vtag_casemap[strtolower($tag)] = $tag;

            // Defining a tag can update vote totals for more than the selected
            // papers.
            $xpids = $pids;
            if ($mode == "s" || $mode == "so" || $mode == "sos") {
                $q = "";
                foreach ($myvtags as $base)
                    $q .= "'" . sqlq($base) . "',";
                $result = $Conf->qe("select $pidcol from $table where tag in (" . substr($q, 0, strlen($q) - 1) . ") group by $pidcol");
                while (($row = edb_row($result)))
                    if (!in_array($row[0], $xpids))
                        $xpids[] = $row[0];
            }

            // count votes
            $vcount = array();
            $q = "select $pidcol, substring(tag from position('~' in tag) + 1) as tagBase, sum(tagIndex) from $table where $pidcol in (" . join(",", $xpids) . ") and (false or ";
            foreach ($myvtags as $base) {
                $q .= "tag like '%~" . sqlq_for_like($base) . "' or ";
                foreach ($xpids as $p)
                    $vcount[$p . ", '" . sqlq($base) . "'"] = 0;
            }
            $result = $Conf->qe(substr($q, 0, strlen($q) - 4) . ") group by $pidcol, tagBase");
            while (($row = edb_row($result))) {
                $lbase = strtolower($row[1]);
                $x = $row[0] . ", '" . sqlq($vtag_casemap[$lbase]) . "'";
                $vcount[$x] = $row[2];
            }

            // develop queries
            $ins = array();
            $del = array();
            foreach ($vcount as $k => $v) {
                if ($v <= 0) {
                    $p = strpos($k, ",");
                    $del[] = "($pidcol=" . substr($k, 0, $p) . " and tag=" . substr($k, $p + 2) . ")";
                } else
                    $ins[] = "($k, $v)";
            }

            // execute queries
            if (count($ins))
                $Conf->qe("insert into $table ($pidcol, tag, tagIndex) values " . join(", ", $ins) . " on duplicate key update tagIndex=values(tagIndex)");
            if (count($del))
                $Conf->qe("delete from $table where " . join(" or ", $del));
        }

        $Conf->qe("unlock tables");

        // complain about reduced tags
        if (count($vreduced) > 0)
            foreach ($vreduced as $k => $v) {
                $href = hoturl("search", "q=" . urlencode("edit:#$k sort:-#$k"));
                defappend($Error["tags"], "You exhausted your allotment for “<a href=\"$href\">#" . htmlspecialchars($k) . "</a>”, so your vote was reduced by $v. You may want to <a href=\"$href\">examine your votes</a>.");
            }

        $modeexplanation = array("so" => "define order", "ao" => "add to order", "sos" => "define gapless order", "sor" => "define random order", "aos" => "add to gapless order", "d" => "remove", "da" => "clear twiddle", "s" => "define", "a" => "add", "p" => "set");
        $this->contact->log_activity("Tag " . $modeexplanation[$mode] . ": " . join(", ", $tags), $pids);

        return true;
    }

}
