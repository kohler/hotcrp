<?php
// decisioninfo.php -- HotCRP helper class for decisions
// Copyright (c) 2006-2025 Eddie Kohler; see LICENSE.

class DecisionInfo {
    /** @var int
     * @readonly */
    public $id;
    /** @var string
     * @readonly */
    public $name;
    /** @var int
     * @readonly */
    public $category;
    /** @var -2|-1|0|1
     * @readonly */
    public $sign;
    /** @var bool
     * @readonly */
    public $placeholder = false;
    /** @var int
     * @readonly */
    public $order = 0;


    const CAT_YES = 1;
    const CAT_STDREJECT = 2;
    const CAT_DESKREJECT = 4;
    const CAT_OTHER = 8;
    const CM_NO = 6;
    // see also DecisionSet::matchexpr


    /** @param int $id
     * @param string $name
     * @param ?string $category */
    function __construct($id, $name, $category = null) {
        $this->id = $id;
        $this->name = $name;
        if ($category !== null) {
            $this->category = self::parse_category($category);
        }
        if ($this->category === null) {
            if ($this->id === 0) {
                $this->category = self::CAT_OTHER;
            } else if ($this->id > 0) {
                $this->category = self::CAT_YES;
            } else {
                $this->category = self::CAT_STDREJECT;
            }
        }
        if ($this->category === self::CAT_YES) {
            $this->sign = 1;
        } else if ($this->category === self::CAT_OTHER) {
            $this->sign = 0;
        } else if ($this->category === self::CAT_STDREJECT) {
            $this->sign = -1;
        } else {
            $this->sign = -2;
        }
    }

    /** @param int $id
     * @return DecisionInfo
     * @suppress PhanAccessReadOnlyProperty */
    static function make_placeholder($id) {
        $dec = new DecisionInfo($id, "[#{$id}]");
        $dec->placeholder = true;
        return $dec;
    }

    /** @param 0|5 $format
     * @return string */
    function name_as($format) {
        return $format === 5 ? htmlspecialchars($this->name) : $this->name;
    }

    /** @param string $s
     * @return ?int */
    static function parse_category($s) {
        if ($s === "maybe") {
            return self::CAT_OTHER;
        } else if ($s === "accept") {
            return self::CAT_YES;
        } else if ($s === "reject") {
            return self::CAT_STDREJECT;
        } else if ($s === "desk_reject" || $s === "deskreject") {
            return self::CAT_DESKREJECT;
        }
        return null;
    }

    /** @param int $category
     * @return string */
    static function unparse_category($category) {
        if ($category === self::CAT_YES) {
            return "accept";
        } else if ($category === self::CAT_STDREJECT) {
            return "reject";
        } else if ($category === self::CAT_DESKREJECT) {
            return "desk_reject";
        }
        return "maybe";
    }

    /** @param int $category
     * @return string */
    static function unparse_category_class($category) {
        if (($category & (self::CAT_YES | self::CM_NO)) === 0) {
            return "dec-maybe";
        }
        return $category === self::CAT_YES ? "dec-yes" : "dec-no";
    }

    /** @return string */
    function category_name() {
        return self::unparse_category($this->category);
    }

    /** @return string */
    function status_class() {
        return self::unparse_category_class($this->category);
    }

    /** @return Decision_Setting */
    function export_setting() {
        $ds = new Decision_Setting;
        $ds->id = $this->id;
        $ds->name = $this->name;
        $ds->category = $this->category_name();
        return $ds;
    }
}
