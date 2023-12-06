<?php
// a_tag.php -- HotCRP assignment helper classes
// Copyright (c) 2006-2023 Eddie Kohler; see LICENSE.

class Tag_Assignable extends Assignable {
    /** @var string */
    public $ltag;
    /** @var string */
    public $_tag;
    /** @var ?float */
    public $_index;
    /** @var ?bool */
    public $_override;
    /** @param ?int $pid
     * @param string $ltag
     * @param ?string $tag
     * @param ?float $index
     * @param ?bool $override */
    function __construct($pid, $ltag, $tag = null, $index = null, $override = null) {
        $this->type = "tag";
        $this->pid = $pid;
        $this->ltag = $ltag;
        $this->_tag = $tag ?? $ltag;
        $this->_index = $index;
        $this->_override = $override;
    }
    /** @return self */
    function fresh() {
        return new Tag_Assignable($this->pid, $this->ltag, $this->_tag);
    }
    /** @param Assignable $q
     * @return bool */
    function match($q) {
        '@phan-var-force Tag_Assignable $q';
        return ($q->pid ?? $this->pid) === $this->pid
            && ($q->ltag ?? $this->ltag) === $this->ltag
            && ($q->_index ?? $this->_index) === $this->_index;
    }
    /** @param Assignable $q
     * @return bool */
    function equals($q) {
        '@phan-var-force Tag_Assignable $q';
        return ($q->pid ?? $this->pid) === $this->pid
            && ($q->_tag ?? $this->_tag) === $this->_tag
            && ($q->_index ?? $this->_index) === $this->_index;
    }
    static function load(AssignmentState $state) {
        if (!$state->mark_type("tag", ["pid", "ltag"], "Tag_Assigner::make")) {
            return;
        }
        $result = $state->conf->qe("select paperId, tag, tagIndex from PaperTag where paperId?a", $state->paper_ids());
        while (($row = $result->fetch_row())) {
            $state->load(new Tag_Assignable(+$row[0], strtolower($row[1]), $row[1], (float) $row[2]));
        }
        Dbl::free($result);
    }
}

class NextTagAssigner implements AssignmentPreapplyFunction {
    /** @var string */
    private $tag;
    /** @var array<int,?float> */
    public $pidindex = [];
    /** @var float */
    private $first_index;
    /** @var float */
    private $next_index;
    /** @var bool */
    private $isseq;
    function __construct($state, $tag, $index, $isseq) {
        $this->tag = $tag;
        $ltag = strtolower($tag);
        $res = $state->query(new Tag_Assignable(null, $ltag));
        foreach ($res as $x) {
            $this->pidindex[$x->pid] = $x->_index;
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
    function preapply(AssignmentState $state) {
        if ($this->next_index == $this->first_index) {
            return;
        }
        $ltag = strtolower($this->tag);
        foreach ($this->pidindex as $pid => $index) {
            if ($index >= $this->first_index && $index < $this->next_index) {
                $x = $state->query_unedited(new Tag_Assignable($pid, $ltag));
                if (!empty($x)) {
                    $state->add(new Tag_Assignable($pid, $ltag, $this->tag, $this->next_index($this->isseq), true));
                }
            }
        }
    }
}

class Tag_AssignmentParser extends UserlessAssignmentParser {
    const I_SET = 0;
    const I_NEXT = 1;
    const I_NEXTSEQ = 2;
    const I_SOME = 3;
    /** @var ?bool */
    private $remove;
    /** @var 0|1|2|3 */
    private $itype = 0;
    /** @var ?Formula */
    private $formula;
    /** @var ?callable(PaperInfo,?int,Contact):mixed */
    private $formulaf;
    function __construct(Conf $conf, $aj) {
        parent::__construct("tag");
        $this->remove = $aj->remove;
        if (!$this->remove && $aj->next) {
            $this->itype = $aj->next === "seq" ? self::I_NEXTSEQ : self::I_NEXT;
        }
    }
    function expand_papers($req, AssignmentState $state) {
        return $this->itype ? "ALL" : (string) $req["paper"];
    }
    function load_state(AssignmentState $state) {
        Tag_Assignable::load($state);
    }
    function allow_paper(PaperInfo $prow, AssignmentState $state) {
        if (($whyNot = $state->user->perm_edit_some_tag($prow))) {
            return new AssignmentError($whyNot);
        } else {
            return true;
        }
    }
    /** @return false */
    static function cannot_view_error(PaperInfo $prow, $tag, AssignmentState $state) {
        if ($prow->has_conflict($state->user)) {
            $state->paper_error("<0>You have a conflict with #{$prow->paperId}");
        } else {
            $state->paper_error("<0>You can’t view that tag for #{$prow->paperId}");
        }
        return false;
    }
    function apply(PaperInfo $prow, Contact $contact, $req, AssignmentState $state) {
        // tag argument (can have multiple space-separated tags)
        if (!isset($req["tag"])) {
            $state->error("<0>Tag required");
            return false;
        }
        $tag = $req["tag"];
        $ok = true;
        while (true) {
            $tag = preg_replace('/\A[,;\s]+/', '', $tag);
            if ($tag === "") {
                break;
            }
            $span = SearchSplitter::span_balanced_parens($tag, 0, " \n\r\t\v\f,;");
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
        if (!preg_match('/\A([-+]?+#?+)(|~~|[^-~+#]*+~)([a-zA-Z@*_:.][-+a-zA-Z0-9!@*_:.\/]*)(\z|#|#?[=!<>]=?|#?≠|#?≤|#?≥)(.*)\z/', $tag, $m)
            || ($m[4] !== "" && $m[4] !== "#")) {
            $state->error("<0>Invalid tag ‘{$tag}’");
            return false;
        }

        // check parts
        $m[5] = trim($m[5]);
        if ($xvalue !== "" && $m[5] !== "" && $m[5] !== $xvalue) {
            $state->error("<0>‘{$tag}’: Value conflicts with ‘tag_value’");
            return false;
        } else if (($this->remove || str_starts_with($m[1], "-")) && $m[5] !== "") {
            $state->warning("<0>‘{$tag}’: Tag values ignored when removing a tag");
        } else if (($this->remove && str_starts_with($m[1], "+"))
                   || ($this->remove === false && str_starts_with($m[1], "-"))) {
            $state->error("<0>Tag ‘{$tag}’ is incompatible with this action");
            return false;
        }

        $xremove = $this->remove || str_starts_with($m[1], "-");
        $xtag = $m[3];
        if ($m[2] === "~" || strcasecmp($m[2], "me~") === 0) {
            $xuser = ($contact->contactId ? : $state->user->contactId) . "~";
        } else if ($m[2] === "~~") {
            $xuser = "";
            $xtag = "~~{$xtag}";
        } else {
            $xuser = $m[2];
        }
        $xvalue = $xvalue !== "" ? $xvalue : $m[5];
        $xitype = $this->itype;

        // parse index
        if ($xremove) {
            $nvalue = false;
        } else if ($xvalue === "") {
            $nvalue = null;
        } else if (preg_match('/\A[-+]?(?:\.\d+|\d+|\d+\.\d*)\z/', $xvalue)) {
            $nvalue = (float) $xvalue;
        } else if (strcasecmp($xvalue, "none") === 0
                   || strcasecmp($xvalue, "clear") === 0) {
            $nvalue = false;
        } else if (strcasecmp($xvalue, "next") === 0) {
            $xitype = self::I_NEXT;
            $nvalue = null;
        } else if (strcasecmp($xvalue, "seqnext") === 0
                   || strcasecmp($xvalue, "nextseq") === 0) {
            $xitype = self::I_NEXTSEQ;
            $nvalue = null;
        } else if (strcasecmp($xvalue, "some") === 0) {
            $xitype = self::I_SOME;
            $nvalue = null;
        } else {
            if (!$this->formula
                || $this->formula->expression !== $xvalue
                || ($this->formula->user && $this->formula->user !== $state->user)) {
                $this->formula = new Formula($xvalue);
                if (!$this->formula->check($state->user)) {
                    $state->error("<0>‘{$xvalue}’: Bad tag value");
                    return false;
                }
                $this->formulaf = $this->formula->compile_function();
            }
            if (!$state->user->can_view_formula($this->formula)) {
                $state->error("<0>‘{$xvalue}’: Can’t compute this formula here");
                return false;
            }
            $nvalue = call_user_func($this->formulaf, $prow, null, $state->user);
            if ($nvalue === null || $nvalue === false) {
                $nvalue = false;
            } else if ($nvalue === true) {
                $nvalue = 0.0;
            } else if (is_int($nvalue)) {
                $nvalue = (float) $nvalue;
            } else if (!is_float($nvalue)) {
                $state->error("<0>‘{$xvalue}’: Bad tag value");
                return false;
            }
        }

        // ignore attempts to change vote & automatic tags
        // (NB: No private tags are automatic.)
        $tagmap = $state->conf->tags();
        if (!$state->conf->is_updating_automatic_tags()
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
            $state->error("<0>Invalid tag ‘{$tag}’ (stars aren’t allowed here)");
            return false;
        }
        if ($xuser !== ""
            && !ctype_digit(substr($xuser, 0, -1))) {
            $c = substr($xuser, 0, -1);
            $twiddlecids = ContactSearch::make_pc($c, $state->user)->user_ids();
            if (empty($twiddlecids)) {
                $state->error("<0>‘{$c}’ doesn’t match a PC member");
                return false;
            } else if (count($twiddlecids) > 1) {
                $state->error("<0>‘{$c}’ matches more than one PC member; be more specific to disambiguate");
                return false;
            }
            $xuser = $twiddlecids[0] . "~";
        }
        $tagger = new Tagger($state->user);
        if (!$tagger->check($xtag)) {
            $state->error($tagger->error_ftext(true));
            return false;
        }

        // compute final tag and value
        $ntag = $xuser . $xtag;
        $ltag = strtolower($ntag);
        if ($xitype === self::I_NEXT || $xitype === self::I_NEXTSEQ) {
            $nvalue = $this->apply_next_index($prow->paperId, $xitype, $ntag, $nvalue, $state);
        } else if ($nvalue === null) {
            $items = $state->query_items(new Tag_Assignable($prow->paperId, $ltag),
                                         AssignmentState::INCLUDE_DELETED);
            $item = $items[0] ?? null;
            if ($item && !$item->deleted()) {
                $nvalue = $item->post("_index");
            } else if ($item && $item->existed() && $xitype === self::I_SOME) {
                $nvalue = $item->pre("_index");
            } else {
                $nvalue = 0.0;
            }
        }
        if ($nvalue <= 0
            && ($dt = $tagmap->find_having($xtag, TagInfo::TF_ALLOTMENT))
            && !$dt->is(TagInfo::TF_APPROVAL)) {
            $nvalue = false;
        }

        // perform assignment
        if ($nvalue === false) {
            $state->remove(new Tag_Assignable($prow->paperId, $ltag));
        } else {
            assert(is_float($nvalue));
            $state->add(new Tag_Assignable($prow->paperId, $ltag, $ntag, $nvalue));
        }
        return true;
    }
    /** @param int $pid
     * @param 1|2 $xitype
     * @param string $tag
     * @param ?float $nvalue
     * @return float */
    private function apply_next_index($pid, $xitype, $tag, $nvalue, AssignmentState $state) {
        $ltag = strtolower($tag);
        // NB ignore $index on second & subsequent nexttag assignments
        $fin = $state->register_preapply_function("seqtag $ltag", new NextTagAssigner($state, $tag, $nvalue, $xitype === self::I_NEXTSEQ));
        assert($fin instanceof NextTagAssigner);
        unset($fin->pidindex[$pid]);
        return $fin->next_index($xitype === self::I_NEXTSEQ);
    }
    /** @param string $xuser
     * @param string $xtag */
    private function apply_remove(PaperInfo $prow, AssignmentState $state, $xuser, $xtag) {
        // resolve twiddle portion
        if ($xuser
            && !ctype_digit(substr($xuser, 0, -1))) {
            $c = substr($xuser, 0, -1);
            if (strcasecmp($c, "any") === 0 || strcasecmp($c, "all") === 0 || $c === "*") {
                $xuser = "(?:\\d+)~";
            } else {
                $twiddlecids = ContactSearch::make_pc($c, $state->user)->user_ids();
                if (empty($twiddlecids)) {
                    $state->error("<0>‘{$c}’ doesn’t match a PC member");
                    return false;
                } else if (count($twiddlecids) === 1) {
                    $xuser = $twiddlecids[0] . "~";
                } else {
                    $xuser = "(?:" . join("|", $twiddlecids) . ")~";
                }
            }
        }

        // resolve tag portion
        $search_ltag = null;
        if (strcasecmp($xtag, "none") === 0) {
            return true;
        } else if (strcasecmp($xtag, "any") === 0
                   || strcasecmp($xtag, "all") == 0) {
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
        } else if (strcasecmp($xtag, "~~any") === 0
                   || strcasecmp($xtag, "~~all") === 0) {
            assert($xuser === "");
            $xtag = "~~[^~]*";
        } else {
            if (!preg_match('/[*(]/', $xuser . $xtag)) {
                $search_ltag = strtolower($xuser . $xtag);
            }
            $xtag = str_replace("\\*", "[^~]*", preg_quote($xtag));
        }

        // if you can't view the tag, you can't clear the tag
        // (information exposure)
        if ($search_ltag && !$state->user->can_view_tag($prow, $search_ltag)) {
            return self::cannot_view_error($prow, $search_ltag, $state);
        }

        // query
        $res = $state->query(new Tag_Assignable($prow->paperId, $search_ltag));
        $tag_re = '{\A' . $xuser . $xtag . '\z}i';
        foreach ($res as $x) {
            if (preg_match($tag_re, $x->ltag)
                && ($search_ltag
                    || $state->user->can_edit_tag($prow, $x->ltag, $x->_index, null))) {
                $state->remove($x);
            }
        }
        return true;
    }
}

class Tag_Assigner extends Assigner {
    /** @var string
     * @readonly */
    public $tag;
    /** @var null|int|float
     * @readonly */
    public $index;
    /** @var bool
     * @readonly */
    public $case_only;
    function __construct(AssignmentItem $item, AssignmentState $state) {
        parent::__construct($item, $state);
        $this->tag = $item["_tag"];
        $this->index = $item->post("_index");
        $this->case_only = $item->existed()
            && !$item->deleted()
            && $item->before->match($item->after);
    }
    static function make(AssignmentItem $item, AssignmentState $state) {
        $prow = $state->prow($item["pid"]);
        // check permissions
        if (!$item["_override"]) {
            $whyNot = $state->user->perm_edit_tag($prow, $item["ltag"],
                $item->pre("_index"), $item->post("_index"));
            if ($whyNot) {
                if ($whyNot["otherTwiddleTag"] ?? null) {
                    return null;
                }
                throw new AssignmentError($whyNot);
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
            . ($index ? "#{$index}" : "");
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
            $aset->stage_qe("insert into PaperTag set paperId=?, tag=?, tagIndex=? on duplicate key update tag=?, tagIndex=?", $this->pid, $this->tag, $this->index, $this->tag, $this->index);
        }
        if ($this->index !== null
            && str_ends_with($this->tag, ':')) {
            $aset->register_cleanup_function("colontag", function () use ($aset) {
                $aset->conf->save_refresh_setting("has_colontag", 1);
            });
        }
        if (!$this->case_only) {
            if ($aset->conf->tags()->is_track($this->tag)) {
                $aset->register_update_rights();
            }
            $aset->user->log_activity("Tag " . ($this->index === null ? "-" : "+") . "#{$this->tag}" . ($this->index ? "#{$this->index}" : ""), $this->pid);
        }
        $aset->register_notify_tracker($this->pid);
    }
}
