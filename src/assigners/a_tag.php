<?php
// a_tag.php -- HotCRP assignment helper classes
// Copyright (c) 2006-2020 Eddie Kohler; see LICENSE.

class NextTagAssigner {
    private $tag;
    public $pidindex = array();
    private $first_index;
    private $next_index;
    private $isseq;
    function __construct($state, $tag, $index, $isseq) {
        $this->tag = $tag;
        $ltag = strtolower($tag);
        $res = $state->query(["type" => "tag", "ltag" => $ltag]);
        foreach ($res as $x) {
            $this->pidindex[$x["pid"]] = (float) $x["_index"];
        }
        asort($this->pidindex);
        if ($index === null) {
            $indexes = array_values($this->pidindex);
            sort($indexes);
            $index = count($indexes) ? $indexes[count($indexes) - 1] : 0;
            $index += Tagger::value_increment($isseq);
        }
        $this->first_index = $this->next_index = ceil($index);
        $this->isseq = $isseq;
    }
    function next_index($isseq) {
        $index = $this->next_index;
        $this->next_index += Tagger::value_increment($isseq);
        return (float) $index;
    }
    function apply_finisher(AssignmentState $state) {
        if ($this->next_index == $this->first_index) {
            return;
        }
        $ltag = strtolower($this->tag);
        foreach ($this->pidindex as $pid => $index) {
            if ($index >= $this->first_index && $index < $this->next_index) {
                $x = $state->query_unmodified(["type" => "tag", "pid" => $pid, "ltag" => $ltag]);
                if (!empty($x)) {
                    $item = $state->add(["type" => "tag", "pid" => $pid, "ltag" => $ltag,
                                         "_tag" => $this->tag,
                                         "_index" => $this->next_index($this->isseq),
                                         "_override" => true]);
                }
            }
        }
    }
}

class Tag_AssignmentParser extends UserlessAssignmentParser {
    const NEXT = 1;
    const NEXTSEQ = 2;
    private $remove;
    private $isnext;
    private $formula;
    private $formulaf;
    function __construct(Conf $conf, $aj) {
        parent::__construct("tag");
        $this->remove = $aj->remove;
        if (!$this->remove && $aj->next) {
            $this->isnext = $aj->next === "seq" ? self::NEXTSEQ : self::NEXT;
        }
    }
    function expand_papers($req, AssignmentState $state) {
        return $this->isnext ? "ALL" : (string) $req["paper"];
    }
    static function load_tag_state(AssignmentState $state) {
        if (!$state->mark_type("tag", ["pid", "ltag"], "Tag_Assigner::make")) {
            return;
        }
        $result = $state->conf->qe("select paperId, tag, tagIndex from PaperTag where paperId?a", $state->paper_ids());
        while (($row = $result->fetch_row())) {
            $state->load(["type" => "tag", "pid" => +$row[0], "ltag" => strtolower($row[1]), "_tag" => $row[1], "_index" => (float) $row[2]]);
        }
        Dbl::free($result);
    }
    function load_state(AssignmentState $state) {
        self::load_tag_state($state);
    }
    function allow_paper(PaperInfo $prow, AssignmentState $state) {
        if (($whyNot = $state->user->perm_change_some_tag($prow))) {
            return whyNotText($whyNot);
        } else {
            return true;
        }
    }
    private function cannot_view_error(PaperInfo $prow, $tag, AssignmentState $state) {
        if ($prow->has_conflict($state->user)) {
            $state->paper_error("You have a conflict with #{$prow->paperId}.");
        } else {
            $state->paper_error("You can’t view that tag for #{$prow->paperId}.");
        }
        return false;
    }
    function apply(PaperInfo $prow, Contact $contact, $req, AssignmentState $state) {
        // tag argument (can have multiple space-separated tags)
        if (!isset($req["tag"])) {
            return $state->error("Tag missing.");
        }
        $tag = $req["tag"];
        $ok = true;
        while (true) {
            $tag = preg_replace('/\A[,;\s]+/', '', $tag);
            if ($tag === "") {
                break;
            }
            $span = SearchSplitter::span_balanced_parens($tag, 0, function ($ch) {
                return ctype_space($ch) || $ch === "," || $ch === ";";
            });
            $ok = $this->apply1(substr($tag, 0, $span), $prow, $contact, $req, $state)
                && $ok;
            $tag = substr($tag, $span);
        }
        return $ok;
    }
    private function apply1($tag, PaperInfo $prow, Contact $contact, $req,
                            AssignmentState $state) {
        // parse tag into parts
        $xvalue = trim((string) $req["tag_value"]);
        if (!preg_match('/\A([-+]?#?)(|~~|[^-~+#]*~)([a-zA-Z@*_:.][-+a-zA-Z0-9!@*_:.\/]*)(\z|#|#?[=!<>]=?|#?≠|#?≤|#?≥)(.*)\z/', $tag, $m)
            || ($m[4] !== "" && $m[4] !== "#")) {
            return $state->error("“" . htmlspecialchars($tag) . "”: Invalid tag.");
        } else if ($xvalue !== "" && $m[5] !== "") {
            return $state->error("“" . htmlspecialchars($tag) . "”: You have a <code>tag value</code> column, so the tag value specified here is ignored.");
        } else if (($this->remove || str_starts_with($m[1], "-")) && $m[5] !== "") {
            $state->warning("“" . htmlspecialchars($tag) . "”: Tag values ignored when removing a tag.");
        } else if (($this->remove && str_starts_with($m[1], "+"))
                   || ($this->remove === false && str_starts_with($m[1], "-"))) {
            return $state->error("“" . htmlspecialchars($tag) . "” is incompatible with this action.");
        }

        $xremove = $this->remove || str_starts_with($m[1], "-");
        if ($m[2] === "~" || strcasecmp($m[2], "me~") === 0) {
            $xuser = ($contact->contactId ? : $state->user->contactId) . "~";
        } else {
            $xuser = $m[2];
        }
        $xtag = $m[3];
        $xvalue = $xvalue !== "" ? $xvalue : trim($m[5]);
        $xnext = $this->isnext;

        // parse index
        if ($xremove) {
            $nvalue = false;
        } else if ($xvalue === "") {
            $nvalue = null;
        } else if (strcasecmp($xvalue, "none") === 0
                   || strcasecmp($xvalue, "clear") === 0) {
            $nvalue = false;
        } else if (strcasecmp($xvalue, "next") === 0) {
            $xnext = self::NEXT;
            $nvalue = null;
        } else if (strcasecmp($xvalue, "seqnext") === 0
                   || strcasecmp($xvalue, "nextseq") === 0) {
            $xnext = self::NEXTSEQ;
            $nvalue = null;
        } else if (preg_match('/\A[-+]?(?:\.\d+|\d+|\d+\.\d*)\z/', $xvalue)) {
            $nvalue = (float) $xvalue;
        } else {
            if (!$this->formula
                || $this->formula->expression !== $xvalue
                || ($this->formula->user && $this->formula->user !== $state->user)) {
                $this->formula = new Formula($xvalue);
                if (!$this->formula->check($state->user)) {
                    return $state->error("“" . htmlspecialchars($xvalue) . "”: Bad tag value.");
                }
                $this->formulaf = $this->formula->compile_function();
            }
            if ($this->formula->view_score($state->user) < $state->user->view_score_bound($prow)) {
                return $state->error("“" . htmlspecialchars($xvalue) . "”: Can’t compute this formula here.");
            }
            $nvalue = call_user_func($this->formulaf, $prow, null, $state->user);
            if ($nvalue === null || $nvalue === false) {
                $nvalue = false;
            } else if ($nvalue === true) {
                $nvalue = 0.0;
            } else if (is_int($nvalue)) {
                $nvalue = (float) $nvalue;
            } else if (!is_float($nvalue)) {
                return $state->error("“" . htmlspecialchars($xvalue) . "”: Bad tag value.");
            }
        }

        // ignore attempts to change vote & autosearch tags
        $tagmap = $state->conf->tags();
        if (!$state->conf->is_updating_autosearch_tags()
            && $xuser === ""
            && $tagmap->is_automatic($xtag)) {
            return true;
        }

        // handle removes
        if ($nvalue === false) {
            return $this->apply_remove($prow, $state, $xuser, $xtag);
        }

        // otherwise handle adds
        if (strpos($xtag, "*") !== false) {
            return $state->error("“" . htmlspecialchars($tag) . "”: Wildcards aren’t allowed here.");
        }
        if ($xuser !== ""
            && $xuser !== "~~"
            && !ctype_digit(substr($xuser, 0, -1))) {
            $c = substr($xuser, 0, -1);
            $twiddlecids = ContactSearch::make_pc($c, $state->user)->user_ids();
            if (empty($twiddlecids)) {
                return $state->error("“" . htmlspecialchars($c) . "” doesn’t match a PC member.");
            } else if (count($twiddlecids) > 1) {
                return $state->error("“" . htmlspecialchars($c) . "” matches more than one PC member; be more specific to disambiguate.");
            }
            $xuser = $twiddlecids[0] . "~";
        }
        $tagger = new Tagger($state->user);
        if (!$tagger->check($xtag, Tagger::CHECKVERBOSE)) {
            return $state->error($tagger->error_html);
        }

        // compute final tag and value
        $ntag = $xuser . $xtag;
        $ltag = strtolower($ntag);
        if ($xnext) {
            $nvalue = $this->apply_next_index($prow->paperId, $xnext, $ntag, $nvalue, $state);
        } else if ($nvalue === null) {
            if (($x = $state->query(["type" => "tag", "pid" => $prow->paperId, "ltag" => $ltag]))) {
                $nvalue = $x[0]["_index"];
            } else {
                $nvalue = 0.0;
            }
        }
        if ($nvalue <= 0
            && $tagmap->has_allotment
            && ($dt = $tagmap->check($xtag))
            && $dt->allotment
            && !$dt->approval) {
            $nvalue = false;
        }
        if (str_starts_with($ltag, "perm:") && $nvalue !== false) {
            if (!$state->conf->is_known_perm_tag($ltag)) {
                $state->warning("#" . htmlspecialchars($ntag) . ": Unknown permission.");
            } else if ($nvalue != 1 && $nvalue != -1) {
                $state->warning("#" . htmlspecialchars($ntag) . ": Permission tags should have value 1 (allow) or -1 (deny).");
            }
        }

        // perform assignment
        if ($nvalue === false) {
            $state->remove(["type" => "tag", "pid" => $prow->paperId, "ltag" => $ltag]);
        } else {
            assert(is_float($nvalue));
            $state->add(["type" => "tag", "pid" => $prow->paperId, "ltag" => $ltag,
                         "_tag" => $ntag, "_index" => (float) $nvalue]);
        }
        return true;
    }
    /** @param int $pid
     * @param int $xnext
     * @param string $tag
     * @param ?float $nvalue
     * @return float */
    private function apply_next_index($pid, $xnext, $tag, $nvalue, AssignmentState $state) {
        $ltag = strtolower($tag);
        // NB ignore $index on second & subsequent nexttag assignments
        if (!($fin = $state->finisher_map["seqtag $ltag"] ?? null)) {
            $fin = $state->finishers[] = $state->finisher_map["seqtag $ltag"] =
                new NextTagAssigner($state, $tag, $nvalue, $xnext === self::NEXTSEQ);
        }
        unset($fin->pidindex[$pid]);
        return $fin->next_index($xnext === self::NEXTSEQ);
    }
    /** @param string $xuser
     * @param string $xtag */
    private function apply_remove(PaperInfo $prow, AssignmentState $state, $xuser, $xtag) {
        // resolve twiddle portion
        if ($xuser
            && $xuser !== "~~"
            && !ctype_digit(substr($xuser, 0, -1))) {
            $c = substr($xuser, 0, -1);
            if (strcasecmp($c, "any") === 0 || strcasecmp($c, "all") === 0 || $c === "*") {
                $xuser = "(?:\\d+)~";
            } else {
                $twiddlecids = ContactSearch::make_pc($c, $state->user)->user_ids();
                if (empty($twiddlecids)) {
                    return $state->error("“" . htmlspecialchars($c) . "” doesn’t match a PC member.");
                } else if (count($twiddlecids) === 1) {
                    $xuser = $twiddlecids[0] . "~";
                } else {
                    $xuser = "(?:" . join("|", $twiddlecids) . ")~";
                }
            }
        }

        // resolve tag portion
        $search_ltag = null;
        if (strcasecmp($xtag, "none") == 0) {
            return true;
        } else if (strcasecmp($xtag, "any") == 0 || strcasecmp($xtag, "all") == 0) {
            $cid = $state->user->contactId;
            if ($state->user->privChair)
                $cid = $state->reviewer->contactId;
            if ($xuser) {
                $xtag = "[^~]*";
            } else if ($state->user->privChair && $state->reviewer->privChair) {
                $xtag = "(?:~~|{$cid}~|)[^~]*";
            } else {
                $xtag = "(?:{$cid}~|)[^~]*";
            }
        } else {
            if (!preg_match('/[*(]/', $xuser . $xtag)) {
                $search_ltag = strtolower($xuser . $xtag);
            }
            $xtag = str_replace("\\*", "[^~]*", preg_quote($xtag));
        }

        // if you can't view the tag, you can't clear the tag
        // (information exposure)
        if ($search_ltag && !$state->user->can_view_tag($prow, $search_ltag)) {
            return $this->cannot_view_error($prow, $search_ltag, $state);
        }

        // query
        $res = $state->query(["type" => "tag", "pid" => $prow->paperId, "ltag" => $search_ltag]);
        $tag_re = '{\A' . $xuser . $xtag . '\z}i';
        foreach ($res as $x) {
            if (preg_match($tag_re, $x["ltag"])
                && ($search_ltag
                    || $state->user->can_change_tag($prow, $x["ltag"], $x["_index"], null))) {
                $state->remove($x);
            }
        }
        return true;
    }
}

class Tag_Assigner extends Assigner {
    private $tag;
    private $index;
    function __construct(AssignmentItem $item, AssignmentState $state) {
        parent::__construct($item, $state);
        $this->tag = $item["_tag"];
        $this->index = $item->post("_index");
    }
    static function make(AssignmentItem $item, AssignmentState $state) {
        $prow = $state->prow($item["pid"]);
        // check permissions
        if (!$item["_override"]) {
            $whyNot = $state->user->perm_change_tag($prow, $item["ltag"],
                $item->pre("_index"), $item->post("_index"));
            if ($whyNot) {
                if ($whyNot["otherTwiddleTag"] ?? null) {
                    return null;
                }
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
        if ($this->item->existed()) {
            $t[] = '<del>' . $this->unparse_item(true) . '</del>';
        }
        if (!$this->item->deleted()) {
            $t[] = '<ins>' . $this->unparse_item(false) . '</ins>';
        }
        return join(" ", $t);
    }
    function unparse_csv(AssignmentSet $aset, AssignmentCsv $acsv) {
        $t = $this->tag;
        if ($this->index === null) {
            $acsv->add(["pid" => $this->pid, "action" => "cleartag", "tag" => $t]);
        } else {
            if ($this->index) {
                $t .= "#{$this->index}";
            }
            $acsv->add(["pid" => $this->pid, "action" => "tag", "tag" => $t]);
        }
    }
    function account(AssignmentSet $aset, AssignmentCountSet $deltarev) {
        $aset->show_column("tags");
    }
    function add_locks(AssignmentSet $aset, &$locks) {
        $locks["PaperTag"] = "write";
    }
    function execute(AssignmentSet $aset) {
        if ($this->index === null) {
            $aset->stage_qe("delete from PaperTag where paperId=? and tag=?", $this->pid, $this->tag);
        } else {
            $aset->stage_qe("insert into PaperTag set paperId=?, tag=?, tagIndex=? on duplicate key update tagIndex=values(tagIndex)", $this->pid, $this->tag, $this->index);
        }
        if ($this->index !== null
            && str_ends_with($this->tag, ':')) {
            $aset->cleanup_callback("colontag", function ($aset) {
                $aset->conf->save_setting("has_colontag", 1);
                $aset->conf->invalidate_caches("tags");
            });
        }
        $isperm = strncasecmp($this->tag, 'perm:', 5) === 0;
        if ($this->index !== null && $isperm) {
            $aset->cleanup_callback("permtag", function ($aset) {
                $aset->conf->save_setting("has_permtag", 1);
            });
        }
        if ($aset->conf->tags()->is_track($this->tag) || $isperm) {
            $aset->cleanup_update_rights();
        }
        $aset->user->log_activity("Tag " . ($this->index === null ? "-" : "+") . "#$this->tag" . ($this->index ? "#$this->index" : ""), $this->pid);
        $aset->cleanup_notify_tracker($this->pid);
    }
}
