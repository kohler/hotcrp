<?php
// a_taganno.php -- HotCRP assignment helper classes
// Copyright (c) 2006-2023 Eddie Kohler; see LICENSE.

class TagAnno_Assignable extends Assignable {
    /** @var string */
    public $ltag;
    /** @var int */
    public $annoId;
    /** @var string */
    public $_tag;
    /** @var ?float */
    public $_tagIndex;
    /** @var ?string */
    public $_heading;
    /** @var ?string */
    public $_infoJson;
    /** @param string $tag
     * @param int $annoId
     * @param ?float $index
     * @param ?string $heading
     * @param ?string $infoJson */
    function __construct($tag, $annoId, $index = null, $heading = null, $infoJson = null) {
        $this->type = "taganno";
        $this->pid = 0;
        $this->ltag = strtolower($tag);
        $this->annoId = $annoId;
        $this->_tag = $tag;
        $this->_tagIndex = $index;
        $this->_heading = $heading;
        $this->_infoJson = $infoJson;
    }
    /** @return self */
    function fresh() {
        return new TagAnno_Assignable($this->_tag, $this->annoId);
    }
    /** @param string $t
     * @return self */
    function with_tag($t) {
        $x = clone $this;
        $x->ltag = strtolower($t);
        $x->_tag = $t;
        return $x;
    }
    /** @param Assignable $q
     * @return bool */
    function match($q) {
        '@phan-var-force TagAnno_Assignable $q';
        return ($q->ltag ?? $this->ltag) === $this->ltag
            && ($q->annoId ?? $this->annoId) === $this->annoId;
    }
    static function load(AssignmentState $state) {
        if (!$state->mark_type("taganno", ["ltag", "annoId"], "TagAnno_Assigner::make")) {
            return;
        }
        $result = $state->conf->qe("select tag, annoId, tagIndex, heading, infoJson from PaperTagAnno");
        while (($row = $result->fetch_row())) {
            $state->load(new TagAnno_Assignable($row[0], +$row[1], +$row[2], $row[3], $row[4]));
        }
        Dbl::free($result);
    }
}

class TagAnno_Assigner extends Assigner {
    function __construct(AssignmentItem $item, AssignmentState $state) {
        parent::__construct($item, $state);
    }
    static function make(AssignmentItem $item, AssignmentState $state) {
        if (!$state->user->can_edit_tag_anno($item["ltag"])) {
            throw new AssignmentError("<0>You can’t edit this tag’s annotations");
        }
        return new TagAnno_Assigner($item, $state);
    }
    function unparse_description() {
        return "tag annotation";
    }
    function add_locks(AssignmentSet $aset, &$locks) {
        $locks["PaperTagAnno"] = "write";
    }
    function execute(AssignmentSet $aset) {
        if ($this->item->deleted()) {
            $aset->stage_qe("delete from PaperTagAnno where tag=? and annoId=?",
                            $this->item->pre("_tag"), $this->item->pre("annoId"));
        } else {
            $aset->stage_qe("insert into PaperTagAnno set tag=?, annoId=?, tagIndex=?, heading=?, infoJson=? ?U on duplicate key update tagIndex=?U(tagIndex), heading=?U(heading), infoJson=?U(infoJson)",
                            $this->item->pre("_tag"), $this->item->pre("annoId"),
                            $this->item->post("_tagIndex"), $this->item->post("_heading"),
                            $this->item->post("_infoJson"));
        }
    }
}
