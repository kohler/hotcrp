<?php
// decisioninfo.php -- HotCRP helper class for decisions
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class DecisionInfo {
    /** @var int */
    public $id;
    /** @var string */
    public $name;
    /** @var 1|2|4 */
    public $category; // must be suitable for masking
    /** @var -1|0|1 */
    public $sign;
    /** @var bool */
    public $placeholder = false;
    /** @var int */
    public $order = 0;


    const CAT_NONE = 1;
    const CAT_YES = 2;
    const CAT_NO = 4;
    const CAT_ALL = 7;
    // see also DecisionSet::matchexpr

    static private $class_name = [null, null, "dec-yes", null, "dec-no"];
    static private $category_name = [null, null, "accept", null, "reject"];


    /** @param int $id
     * @param string $name */
    function __construct($id, $name) {
        $this->id = $id;
        $this->name = $name;
        if ($this->id === 0) {
            $this->category = self::CAT_NONE;
            $this->sign = 0;
        } else if ($this->id > 0) {
            $this->category = self::CAT_YES;
            $this->sign = 1;
        } else {
            $this->category = self::CAT_NO;
            $this->sign = -1;
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

    /** @return string */
    function status_class() {
        return self::$class_name[$this->category] ?? "dec-maybe";
    }

    /** @param Decision_Setting $ds */
    function unparse_setting($ds) {
        $ds->id = $this->id;
        $ds->name = $this->name;
        $ds->category = self::$category_name[$this->category] ?? "maybe";
    }
}
