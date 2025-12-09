<?php
// namedformula.php -- HotCRP helper class for named formulas
// Copyright (c) 2009-2025 Eddie Kohler; see LICENSE.

class NamedFormula {
    /** @var Conf
     * @readonly */
    public $conf;
    /** @var ?int */
    public $formulaId;
    /** @var string */
    public $name;
    /** @var string */
    public $expression;
    /** @var int */
    public $createdBy = 0;
    /** @var int */
    public $timeModified = 0;
    /** @var ?string */
    private $_abbreviation;
    /** @var ?Formula */
    private $_fcache;
    /** @var ?int */
    private $_fcache_rights_version;

    function __construct(Conf $conf) {
        $this->conf = $conf;
    }

    private function fetch_incorporate() {
        $this->formulaId = (int) $this->formulaId;
        $this->createdBy = (int) $this->createdBy;
        $this->timeModified = (int) $this->timeModified;
    }

    /** @param Dbl_Result $result
     * @return ?NamedFormula */
    static function fetch(Conf $conf, $result) {
        if (($formula = $result->fetch_object("NamedFormula", [$conf]))) {
            $formula->fetch_incorporate();
        }
        return $formula;
    }

    function assign_search_keyword(AbbreviationMatcher $am) {
        if ($this->_abbreviation === null) {
            $e = new AbbreviationEntry($this->name, $this, Conf::MFLAG_FORMULA);
            $this->_abbreviation = $am->ensure_entry_keyword($e, AbbreviationMatcher::KW_CAMEL);
        } else {
            $am->add_keyword($this->_abbreviation, $this, Conf::MFLAG_FORMULA);
        }
    }

    /** @return string */
    function abbreviation() {
        if ($this->_abbreviation === null) {
            $this->conf->abbrev_matcher();
            assert($this->_abbreviation !== null);
        }
        return $this->_abbreviation;
    }

    /** @return Formula */
    function realize(Contact $user) {
        if (!$this->_fcache
            || $this->_fcache->user !== $user
            || $this->_fcache_rights_version !== Contact::$rights_version) {
            $this->_fcache = Formula::make($user, $this->expression);
            $this->_fcache_rights_version = Contact::$rights_version;
        }
        return $this->_fcache;
    }
}
