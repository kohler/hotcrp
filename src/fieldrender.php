<?php
// fieldrender.php -- HotCRP helper class for multi-format messages
// Copyright (c) 2006-2023 Eddie Kohler; see LICENSE.

class FieldRender {
    /** @var ?Contact
     * @readonly */
    public $user;
    /** @var ?PaperTable
     * @readonly */
    public $table;
    /** @var ?PaperColumn
     * @readonly */
    public $column;
    /** @var int
     * @readonly */
    public $context;
    /** @var null|false|string */
    public $title;
    /** @var ?string */
    public $value;
    /** @var ?int */
    public $value_format;
    /** @var ?bool */
    public $value_long;

    const CFHTML = 0x1;
    const CFTEXT = 0x2;

    const CFPAGE = 0x10;
    const CFFORM = 0x20;
    const CFLIST = 0x40;
    const CFMAIL = 0x80;
    const CFSUGGEST = 0x100;

    const CFCSV = 0x1000;
    const CFROW = 0x2000;
    const CFCOLUMN = 0x4000;
    const CFVERBOSE = 0x8000;

    /** @param int $context */
    function __construct($context, Contact $user = null) {
        assert(($context & 3) !== 0 && ($context & 3) !== 3);
        assert((($context & 0xFF0) & (($context & 0xFF0) - 1)) === 0);
        $this->context = $context;
        $this->user = $user;
    }
    /** @param PaperTable $table
     * @return $this
     * @suppress PhanAccessReadOnlyProperty */
    function make_table($table) {
        assert(($this->context & self::CFHTML) === self::CFHTML);
        assert(($this->context & (self::CFPAGE | self::CFFORM)) !== 0);
        assert(!$this->table && (!$this->user || $this->user === $table->user));
        $this->user = $table->user;
        $this->table = $table;
        return $this;
    }
    /** @param PaperColumn $column
     * @return $this
     * @suppress PhanAccessReadOnlyProperty */
    function make_column($column) {
        $this->column = $column;
        return $this;
    }

    function clear() {
        $this->title = null;
        $this->value = $this->value_format = $this->value_long = null;
    }
    /** @return bool */
    function is_empty() {
        return (string) $this->title === "" && (string) $this->value === "";
    }
    /** @param int $context
     * @return bool */
    function want($context) {
        return ($this->context & $context) === $context;
    }
    /** @return bool
     * @deprecated */
    function for_page() {
        return ($this->context & self::CFPAGE) !== 0;
    }
    /** @return bool
     * @deprecated */
    function for_form() {
        return ($this->context & self::CFFORM) !== 0;
    }
    /** @return bool
     * @deprecated */
    function want_text() {
        return ($this->context & self::CFHTML) === 0;
    }
    /** @return bool
     * @deprecated */
    function want_html() {
        return ($this->context & self::CFHTML) !== 0;
    }
    /** @return bool
     * @deprecated */
    function want_list() {
        return ($this->context & self::CFLIST) !== 0;
    }
    /** @return bool
     * @deprecated */
    function want_list_row() {
        return ($this->context & (self::CFLIST | self::CFCOLUMN)) === self::CFLIST;
    }
    /** @return bool
     * @deprecated */
    function want_list_column() {
        return ($this->context & (self::CFLIST | self::CFCOLUMN)) ===
            (self::CFLIST | self::CFCOLUMN);
    }
    /** @return bool */
    function verbose() {
        return ($this->context & self::CFVERBOSE) !== 0;
    }
    /** @param string $t
     * @return $this */
    function set_text($t) {
        $this->value = $t;
        $this->value_format = 0;
        return $this;
    }
    /** @param string $t
     * @return $this */
    function set_html($t) {
        $this->value = $t;
        $this->value_format = 5;
        return $this;
    }
    /** @param bool $b
     * @return $this */
    function set_bool($b) {
        if (($this->context & self::CFHTML) !== 0) {
            $this->set_text($b ? "✓" : "✗");
        } else if (($this->context & self::CFCSV) !== 0) {
            $this->set_text($b ? "Y" : "N");
        } else {
            $this->set_text($b ? "Yes" : "No");
        }
        return $this;
    }
    /** @return string */
    function value_html($divclass = null) {
        $rest = "";
        if ($this->value === null || $this->value === "") {
            return "";
        } else if ($this->value_format === 5) {
            if ($divclass === null) {
                return $this->value;
            }
            $html = $this->value;
        } else if ($this->value_format === 0) {
            if ($this->value_long) {
                $html = Ht::format0($this->value);
                $divclass = $divclass ? "format0 {$divclass}" : "format0";
            } else {
                $html = htmlspecialchars($this->value);
            }
        } else {
            $html = htmlspecialchars($this->value);
            $divclass = $divclass ? "need-format {$divclass}" : "need-format";
            $rest = " data-format=\"{$this->value_format}\"";
        }
        if ($divclass || $rest) {
            $divclass = $divclass ? " class=\"{$divclass}\"" : "";
            $html = "<div{$divclass}{$rest}>{$html}</div>";
        }
        return $html;
    }
}
