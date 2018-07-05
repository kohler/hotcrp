<?php
// a_tag.php -- HotCRP assignment helper classes
// Copyright (c) 2006-2018 Eddie Kohler; see LICENSE.

class NextTagAssigner {
    private $tag;
    public $pidindex = array();
    private $first_index;
    private $next_index;
    private $isseq;
    function __construct($state, $tag, $index, $isseq) {
        $this->tag = $tag;
        $ltag = strtolower($tag);
        $res = $state->query(array("type" => "tag", "ltag" => $ltag));
        foreach ($res as $x)
            $this->pidindex[$x["pid"]] = (float) $x["_index"];
        asort($this->pidindex);
        if ($index === null) {
            $indexes = array_values($this->pidindex);
            sort($indexes);
            $index = count($indexes) ? $indexes[count($indexes) - 1] : 0;
            $index += ($isseq ? 1 : self::$value_increment_map[mt_rand(0, 9)]);
        }
        $this->first_index = $this->next_index = ceil($index);
        $this->isseq = $isseq;
    }
    private static $value_increment_map = array(1, 1, 1, 1, 1, 2, 2, 2, 3, 4);
    function next_index($isseq) {
        $index = $this->next_index;
        $this->next_index += ($isseq ? 1 : self::$value_increment_map[mt_rand(0, 9)]);
        return (float) $index;
    }
    function apply_finisher(AssignmentState $state) {
        if ($this->next_index == $this->first_index)
            return;
        $ltag = strtolower($this->tag);
        foreach ($this->pidindex as $pid => $index)
            if ($index >= $this->first_index && $index < $this->next_index) {
                $x = $state->query_unmodified(array("type" => "tag", "pid" => $pid, "ltag" => $ltag));
                if (!empty($x))
                    $item = $state->add(["type" => "tag", "pid" => $pid, "ltag" => $ltag,
                                         "_tag" => $this->tag,
                                         "_index" => $this->next_index($this->isseq),
                                         "_override" => true]);
            }
    }
}

class Tag_AssignmentParser extends UserlessAssignmentParser {
    const NEXT = 1;
    const NEXTSEQ = 2;
    private $remove;
    private $isnext;
    function __construct(Conf $conf, $aj) {
        parent::__construct("tag");
        $this->remove = $aj->remove;
        if (!$this->remove && $aj->next)
            $this->isnext = $aj->next === "seq" ? self::NEXTSEQ : self::NEXT;
    }
    function expand_papers(&$req, AssignmentState $state) {
        return $this->isnext ? "ALL" : false;
    }
    static function load_tag_state(AssignmentState $state) {
        if (!$state->mark_type("tag", ["pid", "ltag"], "Tag_Assigner::make"))
            return;
        $result = $state->conf->qe("select paperId, tag, tagIndex from PaperTag where paperId?a", $state->paper_ids());
        while (($row = edb_row($result)))
            $state->load(["type" => "tag", "pid" => +$row[0], "ltag" => strtolower($row[1]), "_tag" => $row[1], "_index" => (float) $row[2]]);
        Dbl::free($result);
    }
    function load_state(AssignmentState $state) {
        self::load_tag_state($state);
    }
    function allow_paper(PaperInfo $prow, AssignmentState $state) {
        if (($whyNot = $state->user->perm_change_some_tag($prow)))
            return whyNotText($whyNot);
        else
            return true;
    }
    private function cannot_view_error(PaperInfo $prow, $tag, AssignmentState $state) {
        if ($prow->conflict_type($state->user))
            $state->paper_error("You have a conflict with #{$prow->paperId}.");
        else
            $state->paper_error("You can’t view that tag for #{$prow->paperId}.");
        return false;
    }
    function apply(PaperInfo $prow, Contact $contact, &$req, AssignmentState $state) {
        // tag argument (can have multiple space-separated tags)
        if (($tag = trim(get($req, "tag", ""))) === "")
            return "Tag missing.";
        $tags = preg_split('/\s+/', $tag);
        while (count($tags) > 1) {
            $req["tag"] = array_pop($tags);
            $this->apply($prow, $contact, $req, $state);
        }
        $tag = $tags[0];

        // index argument
        $xindex = get($req, "index");
        if ($xindex === null)
            $xindex = get($req, "value");
        if ($xindex !== null && ($xindex = trim($xindex)) !== "") {
            $tag = preg_replace(',\A(#?.+)(?:[=!<>]=?|#|≠|≤|≥)(?:|-?\d+(?:\.\d*)?|-?\.\d+|any|all|none|clear)\z,i', '$1', $tag);
            if (!preg_match(',\A(?:[=!<>]=?|#|≠|≤|≥),i', $xindex))
                $xindex = "#" . $xindex;
            $tag .= $xindex;
        }

        // tag parsing; see also PaperSearch::_check_tag
        $remove = $this->remove;
        if ($tag[0] === "-" && !$remove) {
            $remove = true;
            $tag = substr($tag, 1);
        } else if ($tag[0] === "+" && !$remove)
            $tag = substr($tag, 1);
        if ($tag[0] === "#")
            $tag = substr($tag, 1);
        $m = array(null, "", "", "", "");
        $xtag = $tag;
        if (preg_match(',\A(.*?)([=!<>]=?|#|≠|≤|≥)(.*?)\z,', $xtag, $xm))
            list($xtag, $m[3], $m[4]) = array($xm[1], $xm[2], strtolower($xm[3]));
        if (!preg_match(',\A(|[^#]*~)([a-zA-Z!@*_:.]+[-a-zA-Z0-9!@*_:.\/]*)\z,i', $xtag, $xm))
            return "“" . htmlspecialchars($tag) . "”: Invalid tag.";
        else if ($m[3] && $m[4] === "")
            return "“" . htmlspecialchars($tag) . "”: Tag value missing.";
        else if ($m[3] && !preg_match(',\A([-+]?(?:\d+(?:\.\d*)?|\.\d+)|any|all|none|clear)\z,', $m[4]))
            return "“" . htmlspecialchars($tag) . "”: Tag value should be a number.";
        else
            list($m[1], $m[2]) = array($xm[1], $xm[2]);
        if ($m[1] == "~" || strcasecmp($m[1], "me~") == 0)
            $m[1] = ($contact->contactId ? : $state->user->contactId) . "~";
        // ignore attempts to change vote tags
        if (!$m[1] && $state->conf->tags()->is_votish($m[2]))
            return true;

        // add and remove use different paths
        $remove = $remove || $m[4] === "none" || $m[4] === "clear";
        if ($remove)
            return $this->apply_remove($prow, $state, $m);
        else if (strpos($tag, "*") !== false)
            return "Tag wildcards aren’t allowed when adding tags.";

        // resolve twiddle portion
        if ($m[1] && $m[1] != "~~" && !ctype_digit(substr($m[1], 0, strlen($m[1]) - 1))) {
            $c = substr($m[1], 0, strlen($m[1]) - 1);
            $twiddlecids = ContactSearch::make_pc($c, $state->user)->ids;
            if (empty($twiddlecids))
                return "“" . htmlspecialchars($c) . "” doesn’t match a PC member.";
            else if (count($twiddlecids) > 1)
                return "“" . htmlspecialchars($c) . "” matches more than one PC member; be more specific to disambiguate.";
            $m[1] = $twiddlecids[0] . "~";
        }

        // resolve tag portion
        if (preg_match(',\A(?:none|any|all)\z,i', $m[2]))
            return "Tag “{$tag}” is reserved.";
        $tag = $m[1] . $m[2];

        // resolve index portion
        if ($m[3] && $m[3] != "#" && $m[3] != "=" && $m[3] != "==")
            return "“" . htmlspecialchars($m[3]) . "” isn’t allowed when adding tags.";
        if ($this->isnext)
            $index = $this->apply_next_index($prow->paperId, $tag, $state, $m);
        else
            $index = $m[3] ? cvtnum($m[4], 0) : null;

        // if you can't view the tag, you can't set the tag
        // (information exposure)
        if (!$state->user->can_view_tag($prow, $tag))
            return $this->cannot_view_error($prow, $tag, $state);

        // save assignment
        $ltag = strtolower($tag);
        if ($index === null
            && ($x = $state->query(["type" => "tag", "pid" => $prow->paperId, "ltag" => $ltag])))
            $index = $x[0]["_index"];
        $vtag = $state->conf->tags()->votish_base($tag);
        if ($vtag && $state->conf->tags()->is_vote($vtag) && !$index)
            $state->remove(["type" => "tag", "pid" => $prow->paperId, "ltag" => $ltag]);
        else
            $state->add(["type" => "tag", "pid" => $prow->paperId, "ltag" => $ltag,
                         "_tag" => $tag, "_index" => (float) $index]);
        if ($vtag)
            $this->account_votes($prow->paperId, $vtag, $state);
        return true;
    }
    private function apply_next_index($pid, $tag, AssignmentState $state, $m) {
        $ltag = strtolower($tag);
        $index = cvtnum($m[3] ? $m[4] : null, null);
        // NB ignore $index on second & subsequent nexttag assignments
        if (!($fin = get($state->finishers, "seqtag $ltag")))
            $fin = $state->finishers["seqtag $ltag"] =
                new NextTagAssigner($state, $tag, $index, $this->isnext === self::NEXTSEQ);
        unset($fin->pidindex[$pid]);
        return $fin->next_index($this->isnext === self::NEXTSEQ);
    }
    private function apply_remove(PaperInfo $prow, AssignmentState $state, $m) {
        // resolve twiddle portion
        if ($m[1] && $m[1] != "~~" && !ctype_digit(substr($m[1], 0, strlen($m[1]) - 1))) {
            $c = substr($m[1], 0, strlen($m[1]) - 1);
            if (strcasecmp($c, "any") == 0 || strcasecmp($c, "all") == 0 || $c === "*") {
                $m[1] = "(?:\\d+)~";
            } else {
                $twiddlecids = ContactSearch::make_pc($c, $state->user)->ids;
                if (empty($twiddlecids))
                    return "“" . htmlspecialchars($c) . "” doesn’t match a PC member.";
                else if (count($twiddlecids) == 1)
                    $m[1] = $twiddlecids[0] . "~";
                else
                    $m[1] = "(?:" . join("|", $twiddlecids) . ")~";
            }
        }

        // resolve tag portion
        $search_ltag = null;
        if (strcasecmp($m[2], "none") == 0)
            return true;
        else if (strcasecmp($m[2], "any") == 0 || strcasecmp($m[2], "all") == 0) {
            $cid = $state->user->contactId;
            if ($state->user->privChair)
                $cid = $state->reviewer->contactId;
            if ($m[1])
                $m[2] = "[^~]*";
            else if ($state->user->privChair && $state->reviewer->privChair)
                $m[2] = "(?:~~|{$cid}~|)[^~]*";
            else
                $m[2] = "(?:{$cid}~|)[^~]*";
        } else {
            if (!preg_match(',[*(],', $m[1] . $m[2]))
                $search_ltag = strtolower($m[1] . $m[2]);
            $m[2] = str_replace("\\*", "[^~]*", preg_quote($m[2]));
        }

        // resolve index comparator
        if (preg_match(',\A(?:any|all|none|clear)\z,i', $m[4]))
            $m[3] = $m[4] = "";
        else {
            if ($m[3] == "#")
                $m[3] = "=";
            $m[4] = cvtint($m[4], 0);
        }

        // if you can't view the tag, you can't clear the tag
        // (information exposure)
        if ($search_ltag && !$state->user->can_view_tag($prow, $search_ltag))
            return $this->cannot_view_error($prow, $search_ltag, $state);

        // query
        $res = $state->query(array("type" => "tag", "pid" => $prow->paperId, "ltag" => $search_ltag));
        $tag_re = '{\A' . $m[1] . $m[2] . '\z}i';
        $vote_adjustments = array();
        foreach ($res as $x)
            if (preg_match($tag_re, $x["ltag"])
                && (!$m[3] || CountMatcher::compare($x["_index"], $m[3], $m[4]))
                && ($search_ltag
                    || $state->user->can_change_tag($prow, $x["ltag"], $x["_index"], null))) {
                $state->remove($x);
                if (($v = $state->conf->tags()->votish_base($x["ltag"])))
                    $vote_adjustments[$v] = true;
            }
        foreach ($vote_adjustments as $vtag => $v)
            $this->account_votes($prow->paperId, $vtag, $state);
        return true;
    }
    private function account_votes($pid, $vtag, AssignmentState $state) {
        $res = $state->query(array("type" => "tag", "pid" => $pid));
        $tag_re = '{\A\d+~' . preg_quote($vtag) . '\z}i';
        $is_vote = $state->conf->tags()->is_vote($vtag);
        $total = 0.0;
        foreach ($res as $x)
            if (preg_match($tag_re, $x["ltag"]))
                $total += $is_vote ? (float) $x["_index"] : 1.0;
        $state->add(array("type" => "tag", "pid" => $pid, "ltag" => strtolower($vtag),
                          "_tag" => $vtag, "_index" => $total, "_vote" => true));
    }
}

class Tag_Assigner extends Assigner {
    private $tag;
    private $index;
    function __construct(AssignmentItem $item, AssignmentState $state) {
        parent::__construct($item, $state);
        $this->tag = $item["_tag"];
        $this->index = $item->get(false, "_index");
        if ($this->index == 0 && $item["_vote"])
            $this->index = null;
    }
    static function make(AssignmentItem $item, AssignmentState $state) {
        $prow = $state->prow($item["pid"]);
        // check permissions
        if (!$item["_vote"] && !$item["_override"]) {
            $whyNot = $state->user->perm_change_tag($prow, $item["ltag"],
                $item->get(true, "_index"), $item->get(false, "_index"));
            if ($whyNot) {
                if (get($whyNot, "otherTwiddleTag"))
                    return null;
                throw new Exception(whyNotText($whyNot));
            }
        }
        return new Tag_Assigner($item, $state);
    }
    function unparse_description() {
        return "tag";
    }
    private function unparse_item($before) {
        $index = $this->item->get($before, "_index");
        return "#" . htmlspecialchars($this->item->get($before, "_tag"))
            . ($index ? "#$index" : "");
    }
    function unparse_display(AssignmentSet $aset) {
        $t = [];
        if ($this->item->existed())
            $t[] = '<del>' . $this->unparse_item(true) . '</del>';
        if (!$this->item->deleted())
            $t[] = '<ins>' . $this->unparse_item(false) . '</ins>';
        return join(" ", $t);
    }
    function unparse_csv(AssignmentSet $aset, AssignmentCsv $acsv) {
        $t = $this->tag;
        if ($this->index === null)
            return ["pid" => $this->pid, "action" => "cleartag", "tag" => $t];
        else {
            if ($this->index)
                $t .= "#{$this->index}";
            return ["pid" => $this->pid, "action" => "tag", "tag" => $t];
        }
    }
    function account(AssignmentSet $aset, AssignmentCountSet $deltarev) {
        $aset->show_column("tags");
    }
    function add_locks(AssignmentSet $aset, &$locks) {
        $locks["PaperTag"] = "write";
    }
    function execute(AssignmentSet $aset) {
        if ($this->index === null)
            $aset->stage_qe("delete from PaperTag where paperId=? and tag=?", $this->pid, $this->tag);
        else
            $aset->stage_qe("insert into PaperTag set paperId=?, tag=?, tagIndex=? on duplicate key update tagIndex=values(tagIndex)", $this->pid, $this->tag, $this->index);
        if ($this->index !== null
            && str_ends_with($this->tag, ':'))
            $aset->cleanup_callback("colontag", function ($aset) {
                $aset->conf->save_setting("has_colontag", 1);
                $aset->conf->invalidate_caches("taginfo");
            });
        $aset->user->log_activity("Tag: " . ($this->index === null ? "-" : "+") . "#$this->tag" . ($this->index ? "#$this->index" : ""), $this->pid);
        $aset->cleanup_notify_tracker($this->pid);
    }
}
