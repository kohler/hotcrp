<?php
// fieldrender.php -- HotCRP helper class for rendering submission fields
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
    const CFHELP = 0x200;
    const CFAPI = 0x400;
    const CFSORT = 0x800;

    const CFCSV = 0x1000;
    const CFROW = 0x2000;
    const CFCOLUMN = 0x4000;
    const CFVERBOSE = 0x8000;

    /** @param int $context */
    function __construct($context, ?Contact $user = null) {
        assert(($context & 3) !== 0 && ($context & 3) !== 3);
        assert((($context & 0xFF0) & (($context & 0xFF0) - 1)) === 0);
        $this->context = $context;
        $this->user = $user;
    }

    /** @param PaperTable $table
     * @return $this
     * @suppress PhanAccessReadOnlyProperty */
    function set_table($table) {
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
    function set_column($column) {
        $this->column = $column;
        return $this;
    }

    /** @param int $context
     * @return $this
     * @suppress PhanAccessReadOnlyProperty */
    function set_context($context) {
        $this->context = $context;
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
    function value_text() {
        if ($this->value === null || $this->value === "") {
            return "";
        }
        return Ftext::convert_to(0, $this->value_format, $this->value);
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

    /** @param ?int $wl
     * @param ?int $hwl */
    function apply_wordlimit($wl = 0, $hwl = 0) {
        if ($this->value === null || $this->value === "" || $this->value_format === 5) {
            return;
        }
        $wl = $wl ?? 0;
        $hwl = $hwl ?? 0;
        if ($hwl > 0 && ($wl <= 0 || $wl > $hwl)) {
            $wl = $hwl;
        }
        if ($wl <= 0 || ($wc = count_words($this->value)) <= $wl) {
            return;
        }
        if ($hwl > 0 && $wc > $hwl) {
            list($prefix, $suffix) = count_words_split($this->value, $hwl);
            $this->value = rtrim($prefix) . "… ✖";
        }
        if ($wl > 0 && $wc > $wl && ($hwl <= 0 || $wl < $hwl)) {
            list($prefix, $suffix) = count_words_split($this->value, $wl);
            $formatclass = $this->value_format === 0 ? "format0" : "need-format";
            $formatattr = $this->value_format === 0 ? "" : " data-format=\"{$this->value_format}\"";
            $this->value = "<div class=\"has-overlong overlong-collapsed\"><div class=\"overlong-divider\"><div class=\"overlong-allowed {$formatclass}\"{$formatattr}>"
                . ($this->value_format === 0 ? Ht::format0($prefix) : htmlspecialchars($prefix))
                . "</div><div class=\"overlong-mark\"><div class=\"overlong-expander\">"
                . Ht::button("Show more", ["class" => "ui js-overlong-expand", "aria-expanded" => "false"])
                . "</div></div></div><div class=\"overlong-content {$formatclass}\"{$formatattr}>"
                . ($this->value_format === 0 ? Ht::format0($this->value) : htmlspecialchars($this->value))
                . "</div></div>";
            $this->value_format = 5;
        }
    }
}
