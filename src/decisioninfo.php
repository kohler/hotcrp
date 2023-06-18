<?php
// decisioninfo.php -- HotCRP helper class for decisions
// Copyright (c) 2006-2023 Eddie Kohler; see LICENSE.

class DecisionInfo {
    /** @var int */
    public $id;
    /** @var string */
    public $name;
    /** @var int */
    public $catbits;
    /** @var -2|-1|0|1 */
    public $sign;
    /** @var bool */
    public $placeholder = false;
    /** @var int */
    public $order = 0;


    const CAT_YES = 0x10;
    const CAT_NO = 0x20;
    const CAT_OTHER = 0x40;
    const CAT_SUBTYPE = 0x0F;
    const CB_DESKREJECT = 0x21;
    // see also DecisionSet::matchexpr


    /** @param int $id
     * @param string $name
     * @param ?string $category */
    function __construct($id, $name, $category = null) {
        $this->id = $id;
        $this->name = $name;
        if ($category !== null) {
            $this->catbits = self::parse_category($category);
        }
        if ($this->catbits === null) {
            if ($this->id === 0) {
                $this->catbits = self::CAT_OTHER;
            } else {
                $this->catbits = $this->id > 0 ? self::CAT_YES : self::CAT_NO;
            }
        }
        if (($this->catbits & self::CAT_YES) !== 0) {
            $this->sign = 1;
        } else if (($this->catbits & self::CAT_NO) !== 0) {
            $this->sign = $this->catbits === self::CB_DESKREJECT ? -2 : -1;
        } else {
            $this->sign = 0;
        }
    }

    /** @param int $id
     * @return DecisionInfo */
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
            return self::CAT_NO;
        } else if ($s === "desk_reject" || $s === "deskreject") {
            return self::CB_DESKREJECT;
        } else {
            return null;
        }
    }

    /** @param int $catbits
     * @return string */
    static function unparse_category($catbits) {
        if (($catbits & self::CAT_YES) !== 0) {
            return "accept";
        } else if (($catbits & self::CAT_NO) !== 0) {
            return $catbits === self::CB_DESKREJECT ? "desk_reject" : "reject";
        } else {
            return "maybe";
        }
    }

    /** @param int $catbits
     * @return string */
    static function unparse_category_class($catbits) {
        if (($catbits & (self::CAT_YES | self::CAT_NO)) === 0) {
            return "dec-maybe";
        } else {
            return ($catbits & self::CAT_YES) !== 0 ? "dec-yes" : "dec-no";
        }
    }

    /** @return string */
    function category_name() {
        return self::unparse_category($this->catbits);
    }

    /** @return string */
    function status_class() {
        return self::unparse_category_class($this->catbits);
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
