<?php
// a_tag.php -- HotCRP assignment helper classes
// Copyright (c) 2006-2025 Eddie Kohler; see LICENSE.

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
        $this->pid = $pid;
        $this->ltag = $ltag;
        $this->_tag = $tag ?? $ltag;
        $this->_index = $index;
        $this->_override = $override;
    }
    /** @return string */
    function type() {
        return "tag";
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
        if ($state->mark_type("tag", ["pid", "ltag"], "Tag_Assigner::make")) {
            foreach ($state->prows() as $prow) {
                self::load_prow($state, $prow);
            }
        }
    }
    static function load_prow(AssignmentState $state, PaperInfo $prow) {
        foreach (Tagger::split_unpack($prow->all_tags_text()) as $ti) {
            $state->load(new Tag_Assignable($prow->paperId, strtolower($ti[0]), $ti[0], $ti[1]));
        }
    }
}

class NextTagAssignmentState {
    /** @var AssignmentState */
    private $astate;
    /** @var bool */
    private $all = false;
    /** @var ?string */
    private $prev_ltag;
    /** @var ?int */
    private $expected_state_version;
    /** @var ?float */
    private $prev_value;

    function __construct(AssignmentState $astate) {
        $this->astate = $astate;
    }
    /** @param bool $x
     * @return $this */
    function set_all($x) {
        $this->all = $x;
        return $this;
    }
    private function resolve_all() {
        if ($this->all) {
            return;
        }
        $known = $this->astate->paper_ids();
        $arg = empty($known) ? [] : ["where" => "Paper.paperId not in (" . join(",", $known) . ")"];
        $arg["tags"] = true;
        foreach ($this->astate->user->paper_set($arg) as $prow) {
            $this->astate->add_prow($prow);
            Tag_Assignable::load_prow($this->astate, $prow);
        }
        $this->all = true;
    }
    /** @param string $ltag
     * @param bool $isseq
     * @return float */
    function compute_next(PaperInfo $prow, $ltag, $isseq) {
        if ($this->astate->state_version() === $this->expected_state_version
            && $this->prev_ltag === $ltag) {
            $this->prev_value += Tagger::value_increment($isseq);
            ++$this->expected_state_version;
            return $this->prev_value;
        }
        $this->resolve_all();
        $items = $this->astate->query_items(new Tag_Assignable(null, $ltag));
        $maxvalue = null;
        foreach ($items as $item) {
            $value = $item["_index"];
            if ($item->pid() !== $prow->paperId
                && ($maxvalue === null || $value > $maxvalue)
                && ($p = $this->astate->prow($item->pid()))
                && $this->astate->user->can_view_tag($p, $ltag)) {
                $maxvalue = floor($value);
            }
        }
        $this->prev_value = ($maxvalue ?? 0.0) + Tagger::value_increment($isseq);
        $this->prev_ltag = $ltag;
        $this->expected_state_version = $this->astate->state_version() + 1;
        return $this->prev_value;
    }
}

class TagAssignmentPiece {
    /** @var string */
    public $xuser;
    /** @var ?string */
    public $xuser_match;
    /** @var string */
    public $xtag;
    /** @var null|false|float */
    public $nvalue;
    /** @var 0|1|2|3 */
    public $xitype;
    /** @var ?Formula */
    public $formula;

    /** @param string $xvalue
     * @return bool */
    function parse_value($xvalue, AssignmentState $state) {
        // empty string: no value
        if ($xvalue === "") {
            return true;
        }

        // explicit numeric value
        if (preg_match('/\A[-+]?(?:\.\d+|\d+|\d+\.\d*)\z/', $xvalue)) {
            $this->nvalue = (float) $xvalue;
            return true;
        }

        // special values
        if (strcasecmp($xvalue, "none") === 0
            || strcasecmp($xvalue, "clear") === 0
            || strcasecmp($xvalue, "delete") === 0) {
            $this->nvalue = false;
            return true;
        } else if (strcasecmp($xvalue, "next") === 0
                   || strcasecmp($xvalue, "increasing") === 0) {
            $this->xitype = Tag_AssignmentParser::I_NEXT;
            return true;
        } else if (strcasecmp($xvalue, "seqnext") === 0
                   || strcasecmp($xvalue, "nextseq") === 0
                   || strcasecmp($xvalue, "sequential") === 0) {
            $this->xitype = Tag_AssignmentParser::I_NEXTSEQ;
            return true;
        } else if (strcasecmp($xvalue, "some") === 0) {
            $this->xitype = Tag_AssignmentParser::I_SOME;
            return true;
        }

        // check for formula
        $this->formula = Formula::make($state->user, $xvalue);
        if (!$this->formula->ok()) {
            $state->error("<0>‘{$xvalue}’: Bad tag value");
            return false;
        }
        if (!$this->formula->viewable()) {
            $state->error("<0>‘{$xvalue}’: Can’t compute this formula here");
            return false;
        }
        $this->formula->prepare();
        return true;
    }

    /** @param string $xuser
     * @param string $tag
     * @return bool */
    function parse_xuser($xuser, AssignmentState $state, $tag) {
        if ($xuser === "" || ctype_digit(substr($xuser, 0, -1))) {
            $this->xuser = $xuser;
            return true;
        }

        $c = substr($xuser, 0, -1);
        if (strcasecmp($c, "any") === 0
            || strcasecmp($c, "all") === 0
            || $c === "*") {
            if ($this->nvalue !== false) {
                $state->error("<0>‘{$tag}’: Invalid private tag");
                return false;
            }
            $this->xuser_match = "(?:\\d+)~";
            return true;
        }

        $twiddlecids = ContactSearch::make_pc($c, $state->user)->user_ids();
        if (empty($twiddlecids)) {
            $state->error("<0>’{$tag}’: No matching PC members");
            return false;
        }
        if (count($twiddlecids) === 1) {
            $this->xuser = $twiddlecids[0] . "~";
            return true;
        }
        if ($this->nvalue !== false) {
            $state->error("<0>‘{$tag}’: Multiple matching PC members; be more specific to disambiguate");
            return false;
        }
        $this->xuser_match = "(?:" . join("|", $twiddlecids) . ")~";
        return true;
    }
}

class Tag_AssignmentParser extends UserlessAssignmentParser {
    const I_SET = 0;
    const I_SOME = 1;
    const I_NEXT = 2;
    const I_NEXTSEQ = 3;
    /** @var ?bool */
    private $remove;
    /** @var 0|1|2|3 */
    private $itype = 0;
    /** @var ?Formula */
    private $formula;
    /** @var list<TagAssignmentPiece> */
    private $pieces;

    function __construct(Conf $conf, $aj) {
        parent::__construct("tag");
        $this->remove = $aj->remove;
        if (!$this->remove && $aj->next) {
            $this->itype = $aj->next === "seq" ? self::I_NEXTSEQ : self::I_NEXT;
        }
    }
    function expand_papers($req, AssignmentState $state) {
        if ($this->itype >= self::I_NEXT) {
            $state->callable("NextTagAssignmentState")->set_all(true);
            return "ALL";
        }
        return parent::expand_papers($req, $state);
    }
    function set_req($req, AssignmentState $state) {
        $this->pieces = [];
        $sp = new SearchParser($req["tag"] ?? "");
        $ok = true;
        $any = false;
        while (true) {
            $sp->skip_span(" ,;");
            if ($sp->is_empty()) {
                break;
            }
            $any = true;
            $ok = $this->add_piece($sp->shift_balanced_parens(" ,;"), $req, $state) && $ok;
        }
        if (!$any && $ok) {
            $state->error("<0>Tag required");
            $ok = false;
        }
        return $ok;
    }
    /** @param CsvRow $req */
    private function add_piece($tag, $req, AssignmentState $state) {
        // parse tag into parts
        if (!preg_match('/\A([-+]?+#?+)(|~~|[^-~+#]*+~)([a-zA-Z@*_:.][-+a-zA-Z0-9!@*_:.\/]*)(\z|#|#?[=!<>]=?|#?≠|#?≤|#?≥)(.*)\z/', $tag, $m)
            || ($m[4] !== "" && $m[4] !== "#")) {
            $state->error("<0>Invalid tag ‘{$tag}’");
            return false;
        }
        if (($this->remove && str_starts_with($m[1], "+"))
            || ($this->remove === false && str_starts_with($m[1], "-"))) {
            $state->error("<0>Tag ‘{$tag}’ is incompatible with this action");
            return false;
        }

        // set parts
        $piece = new TagAssignmentPiece;
        $piece->xitype = $this->itype;
        if ($this->remove || str_starts_with($m[1], "-")) {
            $piece->nvalue = false;
        }
        $piece->xtag = $m[3];
        if ($m[2] === "~" || strcasecmp($m[2], "me~") === 0) {
            $xuser = "{$state->user->contactId}~";
        } else if ($m[2] === "~~") {
            $xuser = "";
            $piece->xtag = "~~{$piece->xtag}";
        } else {
            $xuser = $m[2];
        }

        // parse value
        $xvalue = trim((string) $req["tag_value"]);
        if (($m[5] = trim($m[5])) !== "") {
            if ($xvalue !== "" && $m[5] !== $xvalue) {
                $state->error("<0>‘{$tag}’: Value conflicts with ‘tag_value’");
                return false;
            }
            $xvalue = $m[5];
        }
        if ($xvalue !== ""
            && ($piece->nvalue === false || $piece->xitype >= self::I_NEXT)) {
            $state->warning("<0>‘{$tag}’: Tag value ignored with this action");
            $xvalue = "";
        }
        if (!$piece->parse_value($xvalue, $state)) {
            return false;
        }

        // star only allowed on remove
        if ($piece->nvalue !== false
            && strpos($piece->xtag, "*") !== false) {
            $state->error("<0>Invalid tag ‘{$tag}’ (wildcards aren’t allowed here)");
            return false;
        }

        // resolve user
        if (!$piece->parse_xuser($xuser, $state, $tag)) {
            return false;
        }

        // if adding, check tag
        if ($piece->nvalue !== false) {
            $tagger = new Tagger($state->user);
            if (!$tagger->check($piece->xtag)) {
                $state->error($tagger->error_ftext(true));
                return false;
            }
        }

        // piece OK
        $this->pieces[] = $piece;
        return true;
    }
    function load_state(AssignmentState $state) {
        Tag_Assignable::load($state);
    }
    function allow_paper(PaperInfo $prow, AssignmentState $state) {
        if (($whyNot = $state->user->perm_edit_some_tag($prow))) {
            return new AssignmentError($whyNot);
        }
        return true;
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
        $ok = true;
        foreach ($this->pieces as $piece) {
            $ok = $this->apply_piece($piece, $prow, $contact, $state) && $ok;
        }
        return $ok;
    }
    private function apply_piece(TagAssignmentPiece $piece, PaperInfo $prow,
                                 Contact $contact, AssignmentState $state) {
        // ignore attempts to change vote & automatic tags
        // (NB: No private tags are automatic.)
        $tagmap = $state->conf->tags();
        if (!$state->conf->is_updating_automatic_tags()
            && $piece->xuser === ""
            && $tagmap->is_automatic($piece->xtag)) {
            return true;
        }

        // resolve formula
        if ($piece->formula) {
            $nvalue = $piece->formula->eval($prow, null);
            if ($nvalue === null || $nvalue === false) {
                $nvalue = false;
            } else if ($nvalue === true) {
                $nvalue = 0.0;
            } else if (is_int($nvalue) || is_float($nvalue)) {
                $nvalue = (float) $nvalue;
            } else {
                $state->error("<0>‘{$piece->formula->expression}’: Bad tag value");
                return false;
            }
        } else {
            $nvalue = $piece->nvalue;
        }

        // handle removes
        if ($nvalue === false) {
            return $this->apply_remove($piece, $prow, $state);
        }

        // compute final tag and value
        $ntag = $piece->xuser . $piece->xtag;
        $ltag = strtolower($ntag);
        if ($piece->xitype === self::I_NEXT
            || $piece->xitype === self::I_NEXTSEQ) {
            $nvalue = $state->callable("NextTagAssignmentState")->compute_next($prow, $ltag, $piece->xitype === self::I_NEXTSEQ);
        } else if ($nvalue === null) {
            $items = $state->query_items(new Tag_Assignable($prow->paperId, $ltag),
                                         AssignmentState::INCLUDE_DELETED);
            $item = $items[0] ?? null;
            if ($item && !$item->deleted()) {
                $nvalue = $item->post("_index");
            } else if ($item && $item->existed() && $piece->xitype === self::I_SOME) {
                $nvalue = $item->pre("_index");
            }
            // Even inserted items might have null `_index`; e.g.,
            // WithdrawVotesAssigner
            $nvalue = $nvalue ?? 0.0;
        }
        if ($nvalue <= 0
            && ($dt = $tagmap->find_having($piece->xtag, TagInfo::TF_ALLOTMENT))
            && !$dt->is(TagInfo::TF_APPROVAL)) {
            $nvalue = false;
        }

        // perform assignment
        if ($nvalue === false) {
            $state->remove(new Tag_Assignable($prow->paperId, $ltag));
        } else {
            assert(is_float($nvalue));
            if ($nvalue <= -TAG_INDEXBOUND || $nvalue >= TAG_INDEXBOUND) {
                $state->error("<0>Tag value out of range");
                return false;
            }
            $state->add(new Tag_Assignable($prow->paperId, $ltag, $ntag, $nvalue));
        }
        return true;
    }

    private function apply_remove(TagAssignmentPiece $piece, PaperInfo $prow,
                                  AssignmentState $state) {
        // resolve tag portion
        $search_ltag = null;
        $xtag = $piece->xtag;
        $xuser = $piece->xuser_match ?? $piece->xuser;
        if (strcasecmp($xtag, "none") === 0) {
            return true;
        }
        if (strcasecmp($xtag, "any") === 0
            || strcasecmp($xtag, "all") == 0) {
            $cid = $state->user->contactId;
            if ($state->user->privChair) {
                $cid = $state->reviewer->contactId;
            }
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
            $aset->register_cleanup_function("colontag", function ($aset) {
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
