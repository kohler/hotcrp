<?php
// formulaconfig.php -- HotCRP helper class for formula construction
// Copyright (c) 2009-2026 Eddie Kohler; see LICENSE.

final class FormulaConfig {
    /** @var bool */
    public $allow_indexed = false;
    /** @var array<string,?list{int,mixed}> */
    public $params = [];
    /** @var bool */
    public $deferred = false;

    /** @param bool $x
     * @return $this */
    function set_allow_indexed($x) {
        $this->allow_indexed = $x;
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

    /** @param bool $x
     * @return $this */
    function set_deferred($x) {
        $this->deferred = $x;
        return $this;
    }
}
