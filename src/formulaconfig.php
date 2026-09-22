<?php
// formulaconfig.php -- HotCRP helper class for formula construction
// Copyright (c) 2009-2026 Eddie Kohler; see LICENSE.

final class FormulaConfig {
    /** @var int */
    public $flags = 0;
    /** @var ?SearchStringContext */
    public $string_context;
    /** @var array<string,?list{int,mixed}> */
    public $params = [];

    /** @param int $f
     * @param bool $x
     * @return $this */
    private function set_flag($f, $x) {
        $this->flags = $x ? $this->flags | $f : $this->flags & ~$f;
        return $this;
    }

    /** @param bool $x
     * @return $this */
    function set_allow_indexed($x) {
        return $this->set_flag(Formula::ALLOW_INDEXED, $x);
    }

    /** @param bool $x
     * @return $this */
    function set_deferred($x) {
        return $this->set_flag(Formula::DEFERRED, $x);
    }

    /** @param bool $x
     * @return $this */
    function set_use_viewer_permissions($x) {
        return $this->set_flag(Formula::USE_VIEWER_PERMISSIONS, $x);
    }

    /** @param ?SearchStringContext $x
     * @return $this
     *
     * `$x` is a skeleton context (no string yet) that the formula parser
     * fills in, so a config with a string context serves one formula. */
    function set_string_context($x) {
        assert(!$x || $x->q === null);
        $this->string_context = $x;
        return $this;
    }

    /** @param string $name
     * @param ?int $format
     * @param mixed $format_detail
     * @return $this */
    function add_param($name, $format = null, $format_detail = null) {
        $this->params[$name] = $format !== null ? [$format, $format_detail] : null;
        return $this;
    }
}
