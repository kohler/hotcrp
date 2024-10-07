<?php
// viewoptionlist.php -- HotCRP helper class for view options
// Copyright (c) 2006-2024 Eddie Kohler; see LICENSE.

class ViewOptionList implements IteratorAggregate {
    /** @var array<string,mixed> */
    private $d = [];

    /** @return bool */
    function is_empty() {
        return empty($this->d);
    }

    /** @param string $n
     * @return bool */
    function has($n) {
        return isset($this->d[$n]);
    }

    /** @param string $n
     * @return mixed */
    function value($n) {
        return $this->d[$n] ?? null;
    }

    #[\ReturnTypeWillChange]
    function getIterator() {
        return new ArrayIterator($this->d);
    }

    /** @param string $n
     * @param mixed $v
     * @return $this */
    function add($n, $v) {
        unset($this->d[$n]);
        $this->d[$n] = $v;
        return $this;
    }

    /** @param string ...$ns
     * @return $this */
    function remove(...$ns) {
        foreach ($ns as $n) {
            unset($this->d[$n]);
        }
        return $this;
    }

    /** @param string $s
     * @return array{string,mixed} */
    static function parse($s) {
        if (str_starts_with($s, "-")) {
            return [substr($s, 1), false];
        } else if (str_starts_with($s, "+")) {
            return [substr($s, 1), true];
        } else if (($eq = strpos($s, "=")) !== false) {
            return [substr($s, 0, $eq), substr($s, $eq + 1)];
        } else {
            return [$s, true];
        }
    }

    /** @param string $s
     * @return $this */
    function add_parse($s) {
        if ($s === "") {
            return $this;
        }
        list($n, $v) = self::parse($s);
        if ($n === "") {
            return $this;
        }
        if ($n !== "clear") {
            $this->add($n, $v);
        } else if ($v !== false) {
            $this->d = [];
        }
        return $this;
    }

    /** @param string $n
     * @param mixed $v
     * @return string */
    static function unparse_option($n, $v) {
        if ($v === false) {
            return "-{$n}";
        } else if ($v === true) {
            return $n;
        } else {
            return "{$n}={$v}";
        }
    }

    /** @return string */
    function unparse() {
        if (empty($this->d)) {
            return "";
        }
        $x = [];
        foreach ($this->d as $n => $v) {
            $x[] = self::unparse_option($n, $v);
        }
        return "[" . join(",", $x) . "]";
    }


    /** @param array<string,string> &$dsv
     * @param list<string> $dslist */
    static function build_spec(&$dsv, $dslist) {
        $dsvl = [];
        foreach ($dslist as $ds) {
            if (($eq = strpos($ds, "=")) !== false) {
                $n = substr($ds, 0, $eq);
                $dsv[$n] = substr($ds, $eq);
                $dsvl[] = $n;
            } else if (($sl = strpos($ds, "/")) !== false) {
                $dsv[substr($ds, 0, $sl)] = substr($ds, $sl);
            } else if (str_ends_with($ds, "\$")) {
                $dsv[substr($ds, 0, -1)] = "\$";
            } else if (str_ends_with($ds, "!")) {
                $n = substr($ds, 0, -1);
                $dsv[$n] = "\$";
                $dsv[""] = $n;
            } else {
                $dsv[$ds] = ".";
            }
        }
        foreach ($dsvl as $n) {
            if (str_starts_with($dsv[$n], "=")) {
                foreach (explode("|", substr($dsv[$n], 1)) as $v) {
                    if (!isset($dsv[$v]))
                        $dsv[$v] = "/{$n}";
                }
            }
        }
    }

    /** @param string $n
     * @param mixed $v
     * @param array<string,string> $dsv
     * @return $this */
    function spec_add($n, $v, $dsv) {
        if ($n === "") {
            return $this;
        }
        if ($n === "clear") {
            if ($v !== false) {
                $this->d = [];
            }
            return $this;
        }
        $spec = $dsv[$n] ?? null;
        if ($spec !== null) {
            $nn = $n;
        } else if (isset($dsv[""])) {
            $nn = $dsv[""];
            $spec = $dsv[$nn];
        } else {
            return $this;
        }
        while (str_starts_with($spec, "/")) {
            if ($n !== $nn) {
                $n = $nn;
            }
            $nn = substr($spec, 1);
            if (str_starts_with($nn, "!")) {
                $v = !$v;
                $nn = substr($nn, 1);
            }
            $spec = $dsv[$nn];
        }
        if ($spec === ".") {
            if (($v = friendly_boolean($v)) !== null) {
                $this->add($nn, $v);
            }
        } else if (str_starts_with($spec, "=")) {
            $vs = explode("|", substr($spec, 1));
            if (is_bool($v)) {
                if ($v === true && $n !== $nn && in_array($n, $vs)) {
                    $v = $n;
                } else if (in_array($v ? "yes" : "no", $vs)) {
                    $v = $v ? "yes" : "no";
                }
            }
            if (is_string($v) && in_array($v, $vs)) {
                $this->add($nn, $v);
            }
        } else {
            $this->add($nn, $v);
        }
        return $this;
    }

    /** @param string $s
     * @param array<string,string> $dsv
     * @return $this */
    function spec_parse($s, $dsv) {
        list($n, $v) = self::parse($s);
        $this->spec_add($n, $v, $dsv);
        return $this;
    }
}
