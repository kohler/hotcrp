<?php
// fieldrender.php -- HotCRP helper class for multi-format messages
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class FieldRender {
    /** @var ?Contact */
    public $user;
    /** @var ?PaperTable */
    public $table;
    /** @var int */
    public $context;
    /** @var null|false|string */
    public $title;
    /** @var ?string */
    public $value;
    /** @var ?int */
    public $value_format;
    /** @var ?bool */
    public $value_long;

    const CFHTML = 1;
    const CFPAGE = 2;
    const CFLIST = 4;
    const CFCOLUMN = 8;
    const CFSUGGEST = 16;
    const CFCSV = 32;
    const CFMAIL = 64;
    const CFFORM = 128;
    const CFVERBOSE = 256;

    const CTEXT = 0;
    const CPAGE = 3;

    /** @param int $context */
    function __construct($context, Contact $user = null) {
        $this->context = $context;
        $this->user = $user;
    }
    /** @param ?int $context */
    function clear($context = null) {
        if ($context !== null) {
            $this->context = $context;
        }
        $this->title = null;
        $this->value = $this->value_format = $this->value_long = null;
    }
    function is_empty() {
        return (string) $this->title === "" && (string) $this->value === "";
    }
    function for_page() {
        return ($this->context & self::CFPAGE) !== 0;
    }
    function want_text() {
        return ($this->context & self::CFHTML) === 0;
    }
    function want_html() {
        return ($this->context & self::CFHTML) !== 0;
    }
    function want_list() {
        return ($this->context & self::CFLIST) !== 0;
    }
    function want_list_row() {
        return ($this->context & (self::CFLIST | self::CFCOLUMN)) === self::CFLIST;
        }
    function want_list_column() {
        return ($this->context & (self::CFLIST | self::CFCOLUMN)) ===
            (self::CFLIST | self::CFCOLUMN);
    }
    function verbose() {
        return ($this->context & self::CFVERBOSE) !== 0;
    }
    /** @param string $t */
    function set_text($t) {
        $this->value = $t;
        $this->value_format = 0;
    }
    /** @param string $t */
    function set_html($t) {
        $this->value = $t;
        $this->value_format = 5;
    }
    /** @param bool $b */
    function set_bool($b) {
        $v = $this->verbose();
        if ($this->context & self::CFHTML) {
            $this->set_text($b ? "✓" : ($v ? "✗" : ""));
        } else if ($this->context & self::CFCSV) {
            $this->set_text($b ? "Y" : ($v ? "N" : ""));
        } else {
            $this->set_text($b ? "Yes" : ($v ? "No" : ""));
        }
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
                $divclass = $divclass ? "format0 " . $divclass : "format0";
            } else {
                $html = htmlspecialchars($this->value);
            }
        } else {
            $html = htmlspecialchars($this->value);
            $divclass = $divclass ? "need-format " . $divclass : "need-format";
            $rest = ' data-format="' . $this->value_format . '"';
        }
        if ($divclass || $rest) {
            $html = '<div' . ($divclass ? ' class="' . $divclass . '"' : "")
                . $rest . '>' . $html . '</div>';
        }
        return $html;
    }
}
