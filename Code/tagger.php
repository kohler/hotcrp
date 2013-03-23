<?php
// tagger.php -- HotCRP helper class for dealing with tags
// HotCRP is Copyright (c) 2006-2013 Eddie Kohler and Regents of the UC
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
        return isset($this->storage[$offset]);
    }
    public function offsetGet($offset) {
        if (!isset($this->storage[$offset])) {
            $n = (object) array("tag" => $offset, "chair" => false, "vote" => false, "rank" => false, "colors" => null);
            if (!Tagger::basic_check($offset))
                return $n;
            $this->storage[$offset] = $n;
            $this->sorted = false;
        }
        return $this->storage[$offset];
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
}

class Tagger {

    const ALLOWRESERVED = 1;
    const NOPRIVATE = 2;
    const NOVALUE = 4;
    const NOCHAIR = 8;
    const ALLOWSTAR = 16;

    public $error_html = false;
    private $contact = null;
    private $color_re = null;
    private $color_tagmap = null;

    private static $main_tagmap = null;
    private static $value_increment_map = array(1, 1, 1, 1, 1, 2, 2, 2, 3, 4);


    public function __construct($contact = null) {
        global $Me;
        $this->contact = ($contact ? $contact : $Me);
    }


    public static function base($tag) {
        if ($tag && (($pos = strpos($tag, "#")) > 0
                     || ($pos = strpos($tag, "=")) > 0))
            return substr($tag, 0, $pos);
        else
            return $tag;
    }

    public static function split($tag) {
        if (!$tag)
            return array(false, false);
        else if (!($pos = strpos($tag, "#")) && !($pos = strpos($tag, "=")))
            return array($tag, false);
        else if ($pos == strlen($tag) - 1)
            return array(substr($tag, 0, $pos), false);
        else
            return array(substr($tag, 0, $pos), (int) substr($tag, $pos + 1));
    }

    public static function basic_check($tag) {
        return $tag != "" && strlen($tag) <= TAG_MAXLEN
            && preg_match('{\A' . TAG_REGEX . '\z}', $tag);
    }


    public function defined_tags() {
        self::$main_tagmap || self::make_tagmap();
        return self::$main_tagmap;
    }


    public function has_vote() {
        return $this->defined_tags()->nvote;
    }

    public function has_rank() {
        return $this->defined_tags()->nrank;
    }


    public function is_chair($tag) {
        $dt = $this->defined_tags();
        $tag = self::base($tag);
        return (isset($dt[$tag]) && $dt[$tag]->chair)
            || ($tag[0] == "~" && $tag[1] == "~");
    }

    public function chair_tags() {
        return $this->defined_tags()->tag_array("chair");
    }


    public function is_vote($tag) {
        $dt = $this->defined_tags();
        $tag = self::base($tag);
        return isset($dt[$tag]) && $dt[$tag]->vote;
    }

    public function vote_tags() {
        $dt = $this->defined_tags();
        return $dt->nvote ? $dt->tag_array("vote") : array();
    }

    public function vote_setting($tag) {
        $dt = $this->defined_tags();
        $tag = self::base($tag);
        return isset($dt[$tag]) && $dt[$tag]->vote > 0 ? $dt[$tag]->vote : 0;
    }


    public function is_rank($tag) {
        $dt = $this->defined_tags();
        $tag = self::base($tag);
        return isset($dt[$tag]) && $dt[$tag]->rank;
    }

    public function rank_tags() {
        $dt = $this->defined_tags();
        return $dt->nrank ? $dt->tag_array("rank") : array();
    }


    private function analyze_colors() {
        $re = "{(?:\\A| )";
        if ($this->contact)
            $re .= "(?:" . $this->contact->cid . "~"
                . ($this->contact->privChair ? "|~~" : "") .")?";
        $re .= "(red|orange|yellow|green|blue|violet|purple|grey|gray|bold|italic|big|small";
        $this->color_tagmap = $this->defined_tags();
        foreach ($this->color_tagmap as $v)
            if ($v->colors)
                $re .= "|" . $v->tag;
	$this->color_re = $re . ")[# ]}";
    }

    public function color_classes($tags) {
        $dt = $this->defined_tags();
        if ($dt != $this->color_tagmap)
            $this->analyze_colors();
        if (is_array($tags))
            $tags = join(" ", $tags);
        if (!preg_match_all($this->color_re, $tags, $m))
            return false;
        $classes = array();
        foreach ($m[1] as $tag)
            if (isset($dt[$tag]) && $dt[$tag]->colors) {
                foreach ($dt[$tag]->colors as $k)
                    $classes[] = $k . "tag";
            } else
                $classes[] = $tag . "tag";
        return join(" ", $classes);
    }


    private static function analyze($tag, $flags) {
        if ($tag == "")
            return "Empty tag.";
        else if (strlen($tag) > TAG_MAXLEN)
            return "Tag “${tag}” is too long; maximum " . TAG_MAXLEN . " characters.";
        else if (!preg_match('/\A' . TAG_REGEX_OPTVALUE . '\z/', $tag, $m)
                 || (!($flags & self::ALLOWSTAR) && strpos($tag, "*") !== false))
            return "Tag “${tag}” contains characters not allowed in tags.";
        else if (count($m) > 1 && $m[1] && ($flags & self::NOVALUE))
            return "Tag values aren’t allowed here.";
        else if ($tag[0] === "~" && $tag[1] === "~" && ($flags & self::NOCHAIR))
            return "Tag “${tag}” is exclusively for chairs.";
        else if ($tag[0] === "~" && ($flags & self::NOPRIVATE))
            return "Twiddle tags aren’t allowed here.";
        else if (($tag === "none" || $tag === "any") && !($flags & self::ALLOWRESERVED))
            return "Tag “${tag}” is reserved.";
        else
            return false;
    }

    public function check($tag, $flags = 0) {
        if (!$this->contact->privChair)
            $flags |= self::NOCHAIR;
        if ($tag[0] == "#")
            $tag = substr($tag, 1);
        if (($this->error_html = self::analyze($tag, $flags)))
            return false;
        else if ($tag[0] == "~" && $tag[1] != "~")
            return $this->contact->cid . $tag;
        else
            return $tag;
    }

    public function view_score($tag) {
        if ($tag === false)
            return VIEWSCORE_FALSE;
        else if (($pos = strpos($tag, "~")) !== false) {
            if (($pos == 0 && $tag[1] === "~")
                || substr($tag, 0, $pos) != $this->contact->cid)
                return VIEWSCORE_ADMINONLY;
            else
                return VIEWSCORE_REVIEWERONLY;
        } else
            return VIEWSCORE_PC;
    }


    public function viewable($tags) {
        if (strpos($tags, "~") !== false) {
            $re = "{ (?:(?!" . $this->contact->cid . "~)\\d+~";
            if (!$this->contact->privChair)
                $re .= "|~+";
            $tags = trim(preg_replace($re . ")\\S+}", "", " $tags "));
        }
        return $tags;
    }

    public function editable($tags) {
        $tags = $this->viewable($tags);
        if ($tags != "") {
            $bad = array();
            foreach ($this->defined_tags() as $v)
                if ($v->vote || (($v->chair || $v->rank) && !$this->contact->privChair))
                    $bad[] = $v->tag;
            $tags = trim(preg_replace("{ (?:" . join("|", $bad) . ")(?:#-?\\d*)? }", " ", " $tags "));
        }
        return $tags;
    }

    public function unparse($tags, $highlight = false) {
        if ($tags == "")
            return "";
        $tags = str_replace("#0 ", " ", " $tags ");
        $tags = str_replace(" " . $this->contact->cid . "~", " ~", $tags);
        return trim($tags);
    }

    public function unparse_link_viewable($tags, $highlight = false, $votereport = false) {
        $vtags = $this->unparse($this->viewable($tags));
        if ($vtags == "")
            return "";

        // track votes for vote report
        $dt = $this->defined_tags();
        if ($votereport && $dt->nvote) {
            preg_match_all('{ (\d+)~(\S+)#([1-9]\d*)}', " $tags", $m, PREG_SET_ORDER);
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
                $byhighlight[$tag] = "";
            }

        foreach (preg_split('/\s+/', $vtags) as $tag) {
            if (!($base = Tagger::base($tag)))
                continue;
            if ($this->is_vote($base)) {
                $v = array();
                if ($votereport)
                    foreach (pcMembers() as $pcm)
                        if (($count = defval($vote[$base], $pcm->cid, 0)) > 0)
                            $v[] = Text::name_html($pcm) . ($count > 1 ? " ($count)" : "");
                $title = ($v ? "PC votes: " . join(", ", $v) : "Vote search");
                $link = "rorder:";
            } else if ($base[0] === "~" && $this->is_vote(substr($base, 1))) {
                $title = "Vote search";
                $link = "rorder:";
            } else {
                $title = "Tag search";
                $link = ($base === $tag ? "%23" : "order:");
            }
            $tx = "<a class=\"q\" href=\"" . hoturl("search", "q=$link$base") . "\" title=\"$title\">" . $base . "</a>" . substr($tag, strlen($base));
            if (isset($byhighlight[$base])) {
                $byhighlight[$base] .= "<strong>" . $tx . "</strong> ";
                $anyhighlight = true;
            } else
                $tt .= $tx . " ";
        }

        if ($anyhighlight)
            return rtrim(join("", $byhighlight) . $tt);
        else
            return rtrim($tt);
    }


    private static function make_tagmap() {
        global $Conf;
        self::$main_tagmap = $map = new TagMap;
        if (!$Conf)
            return;
        $ct = $Conf->setting("tag_chair") ? $Conf->settingText("tag_chair", "") : "";
        foreach (preg_split('/\s+/', $ct) as $t)
            if ($t != "") {
                $map[self::base($t)]->chair = true;
                ++$map->nchair;
            }
        $vt = $Conf->setting("tag_vote") ? $Conf->settingText("tag_vote", "") : "";
        if ($vt != "")
            foreach (preg_split('/\s+/', $vt) as $t)
                if ($t != "") {
                    list($b, $v) = self::split($t);
                    $map[$b]->vote = ($v ? $v : 1);
                    ++$map->nvote;
                }
        $rt = $Conf->setting("tag_rank") ? $Conf->settingText("tag_rank", "") : "";
        if ($rt != "")
            foreach (preg_split('/\s+/', $rt) as $t) {
                $map[self::base($t)]->rank = true;
                ++$map->nrank;
            }
        $ct = $Conf->settingText("tag_color", "");
        if ($ct != "")
            foreach (explode(" ", $ct) as $k)
                if ($k != "" && ($p = strpos($k, "=")) !== false)
                    arrayappend($map[substr($k, 0, $p)]->colors, substr($k, $p + 1));
    }

    public static function invalidate_defined_tags() {
        self::$main_tagmap = null;
    }


    public static function value_increment($mode) {
        if (strlen($mode) == 2)
            return self::$value_increment_map[mt_rand(0, 9)];
        else
            return 1;
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
        if (!is_array($pids))
            $pids = array($pids);
        $mytagprefix = $this->contact->cid . "~";

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
        $while = "while tagging";
        $Conf->qe("lock tables $table write", $while);

        // check chair tags
        $badtags = array();
        if (!$this->contact->privChair) {
            $nexttags = array();
            foreach ($tags as $tag) {
                if ($this->is_chair($tag))
                    $badtags[] = $tag;
                else
                    $nexttags[] = $tag;
            }
            if (count($nexttags) != count($tags))
                defappend($Error["tags"], "Tag “" . htmlspecialchars(self::base($badtags[0])) . "” can only be changed by the chair.<br />\n");
            $tags = $nexttags;
        }

        // check vote tags
        $vchanges = array();
        if ($this->has_vote()) {
            $nexttags = array();
            $multivote = 0;
            foreach ($tags as $tag) {
                $base = self::base($tag);
                $twiddle = strpos($base, "~");
                if ($this->is_vote($base))
                    $badtags[] = $tag;
                else if ($twiddle > 0 && $this->is_vote(substr($base, $twiddle + 1))) {
                    if (isset($vchanges[$base])) // only one vote per tag
                        $multivote++;
                    else {
                        if (strlen($base) == strlen($tag)
                            && $mode != "d" && $mode != "da")
                            $tag .= "#1";
                        $nexttags[] = $tag;
                        $vchanges[$base] = 0;
                    }
                } else
                    $nexttags[] = $tag;
            }
            if (count($nexttags) + $multivote != count($tags)) {
                $t = htmlspecialchars(self::base($badtags[count($badtags) - 1]));
                defappend($Error["tags"], "The shared tag “${t}” keeps track of vote totals and cannot be modified.  Use the private tag “~${t}” to change your vote (for instance, “~${t}#1” is one vote).<br />\n");
            }
            $tags = $nexttags;
        }

        // check rank tag
        if (!$this->contact->privChair && $this->has_rank()) {
            $nexttags = array();
            foreach ($tags as $tag) {
                if ($this->is_rank($tag))
                    $badtags[] = $tag;
                else
                    $nexttags[] = $tag;
            }
            if (count($nexttags) != count($tags)) {
                $t = htmlspecialchars(self::base($badtags[count($badtags) - 1]));
                defappend($Error["tags"], "The shared tag “${t}” keeps track of the global ranking and cannot be modified.  Use the private tag “~${t}” to change your ranking.<br />\n");
            }
            $tags = $nexttags;
        }

        // exit if nothing to do
        if (count($tags) == 0 && $mode != 'p') {
            $Conf->qe("unlock tables", $while);
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
                    $ts = self::split($tag);
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
                    foreach ($this->chair_tags() as $ct => $x)
                        if ($ct[0] == '~')
                            $q .= " and tag!='" . $this->contact->cid . sqlq($ct) . "'";
                        else
                            $q .= " and tag!='" . sqlq($ct) . "'";
                }
                $q .= " and (tag like '$mytagprefix%' or tag not like '%~%'";
                if ($this->contact->privChair)
                    $q .= " or tag like '~~%'";
                $q .= ")";
            }
            $Conf->qe($q, $while);
        }

        // check for vote changes
        if (count($vchanges) && $mode != "d" && $mode != "da") {
            $q = "";
            foreach ($vchanges as $base => &$val) {
                $q .= ($q === "" ? "" : ",") . "'" . sqlq($base) . "'";
                $val = $this->vote_setting(substr($base, strpos($base, "~") + 1));
            }
            unset($val);
            if ($mode != "p")	// must delete old versions for correct totals
                $Conf->qe("delete from $table where $pidcol in (" . join(",", $pids) . ") and tag in ($q)", "while deleting old votes");
            $result = $Conf->qe("select tag, sum(tagIndex) from $table where tag in ($q) group by tag", "while checking vote totals");
            while (($row = edb_row($result)))
                $vchanges[$row[0]] = max($vchanges[$row[0]] - $row[1], 0);
        }

        // extract tag indexes into a separate array
        $tagIndex = array();
        $explicitIndex = array();
        $modeOrdered = ($mode == "so" || $mode == "ao" || $mode == "sos"
                        || $mode == "sor" || $mode == "aos");
        foreach ($tags as $tag) {
            $base = self::base($tag);
            if (strlen($base) + 1 < strlen($tag)) {
                $tagIndex[$base] = $explicitIndex[$base] =
                    (int) substr($tag, strlen($base) + 1);
            } else if (strlen($base) + 1 == strlen($tag) || $modeOrdered) {
                $result = $Conf->qe("select max(tagIndex) from $table where tag='" . sqlq($base) . "'", $while);
                if (($row = edb_row($result)))
                    $tagIndex[$base] = $row[0] + self::value_increment($mode);
                else
                    $tagIndex[$base] = self::value_increment($mode);
            }
        }

        // if inserting tags into an order, shift existing tags
        $reorders = array();
        if (($mode == "ao" || $mode == "aos") && count($explicitIndex)) {
            $q = "";
            foreach ($explicitIndex as $base => $index)
                $q .= "(tag='" . sqlq($base) . "' and tagIndex>=$index) or ";
            $result = $Conf->qe("select $pidcol, tag, tagIndex from $table where " . substr($q, 0, strlen($q) - 4) . " order by tagIndex", "while reordering tags");
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
                    $base = self::base($tag);
                    // choose index, bump running index in ordered mode
                    $index = defval($tagIndex, $base, 0);
                    if ($modeOrdered)
                        $tagIndex[$base] += self::value_increment($mode);
                    // check vote totals
                    if (isset($vchanges[$base])) {
                        if ($index > $vchanges[$base]) {
                            $vreduced[substr($base, strpos($base, "~"))] = true;
                            $index = $vchanges[$base];
                        } else if ($index < 0) // no negative votes, smarty
                            $index = 0;
                        $vchanges[$base] -= $index;
                        if ($index == 0) {
                            $delvotes[] = "($pidcol=$pid and tag='" . sqlq($base) . "')";
                            continue;
                        }
                    }
                    // add to the right query, which differ in behavior on setting
                    // a tag that's already set.  $q_keepnew keeps the new value,
                    // $q_keepold keeps the old value.
                    $thisq = "($pid, '" . sqlq($base) . "', " . $index . "), ";
                    if (isset($explicitIndex[$base]))
                        $q_keepnew .= $thisq;
                    else
                        $q_keepold .= $thisq;
                }
            }
            // if adding ordered tags in the middle of an order, reorder old tags
            foreach ($reorders as $base => $pairs) {
                $last = null;
                foreach ($pairs as $p)
                    if ($p[1] < $tagIndex[$base]) {
                        $thisq = "($p[0], '" . sqlq($base) . "', " . $tagIndex[$base] . "), ";
                        $q_keepnew .= $thisq;
                        if ($last === null || $last != $p[1])
                            $tagIndex[$base] += self::value_increment($mode);
                        $last = $p[1];
                    } else
                        break;
            }
            // store changes
            if ($q_keepnew != "")
                $Conf->qe("insert into $table ($pidcol, tag, tagIndex) values " . substr($q_keepnew, 0, strlen($q_keepnew) - 2) . " on duplicate key update tagIndex=values(tagIndex)", $while);
            if ($q_keepold != "")
                $Conf->qe("insert into $table ($pidcol, tag, tagIndex) values " . substr($q_keepold, 0, strlen($q_keepold) - 2) . " on duplicate key update tagIndex=tagIndex", $while);
            if (count($delvotes))
                $Conf->qe("delete from $table where " . join(" or ", $delvotes), "while deleting zero votes");
        }

        // update vote totals
        if (count($vchanges) > 0 || ($this->has_vote() && $mode == "p")) {
            // Can't "insert from ... select ..." or "create temporary table"
            // because those unlock tables implicitly.

            // Find relevant vote tags.
            if ($mode == "p")
                $myvtags = array_keys($this->vote_tags());
            else {
                $myvtags = array();
                foreach ($vchanges as $tag => $val) {
                    $base = (($p = strpos($tag, "~")) !== false ? substr($tag, $p + 1) : $tag);
                    $myvtags[] = $base;
                }
            }

            // Defining a tag can update vote totals for more than the selected
            // papers.
            $xpids = $pids;
            if ($mode == "s" || $mode == "so" || $mode == "sos") {
                $q = "";
                foreach ($myvtags as $base)
                    $q .= "'" . sqlq($base) . "',";
                $result = $Conf->qe("select $pidcol from $table where tag in (" . substr($q, 0, strlen($q) - 1) . ") group by $pidcol", "while counting votes");
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
            $result = $Conf->qe(substr($q, 0, strlen($q) - 4) . ") group by $pidcol, tagBase", "while counting votes");
            while (($row = edb_row($result))) {
                $x = $row[0] . ", '" . sqlq($row[1]) . "'";
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
                $Conf->qe("insert into $table ($pidcol, tag, tagIndex) values " . join(", ", $ins) . " on duplicate key update tagIndex=values(tagIndex)", "while counting votes");
            if (count($del))
                $Conf->qe("delete from $table where " . join(" or ", $del), "while counting votes");
        }

        $Conf->qe("unlock tables", $while);

        // complain about reduced tags
        if (count($vreduced) > 0) {
            ksort($vreduced);
            $vtext = array();
            $q = "";
            foreach ($vreduced as $k => $v) {
                $vtext[] = "<a href=\"" . hoturl("search", "q=rorder:$k&amp;showtags=1") . "\">" . htmlspecialchars($k) . "</a>";
                $q .= ($q === "" ? "" : "+") . "rorder:$k";
            }
            defappend($Error["tags"], "You exhausted your vote allotment for " . commajoin($vtext) . ".  You may want to change <a href=\"" . hoturl("search", "q=$q&amp;showtags=1") . "\">your other votes</a> and try again.<br />\n");
        }

        $modeexplanation = array("so" => "define order", "ao" => "add to order", "sos" => "define gapless order", "sor" => "define random order", "aos" => "add to gapless order", "d" => "remove", "da" => "clear twiddle", "s" => "define", "a" => "add", "p" => "set");
        $Conf->log("Tag " . $modeexplanation[$mode] . ": " . join(", ", $tags),
                   $this->contact, $pids);

        return true;
    }

}
