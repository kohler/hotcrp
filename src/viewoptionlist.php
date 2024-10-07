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
    function get($n) {
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

    /** @param ViewOptionList|array<string,string> $list
     * @param ViewOptionSchema $schema
     * @return $this */
    function append_validate($list, $schema) {
        foreach ($list as $n => $v) {
            if (($pair = $schema->validate($n, $v)))
                $this->add($pair[0], $pair[1]);
        }
        return $this;
    }

    /** @param ?string $s
     * @return ?array{string,bool|string} */
    static function parse_pair($s) {
        if ($s === null || $s === "") {
            return null;
        }
        if (($eq = strpos($s, "=")) !== false) {
            $pair = [substr($s, 0, $eq), substr($s, $eq + 1)];
        } else if ((str_starts_with($s, "-") || str_starts_with($s, "+"))
                   && strlen($s) > 1
                   && !ctype_digit($s[1])
                   && $s[1] !== ".") {
            $pair = [substr($s, 1), $s[0] === "+"];
        } else {
            $pair = [$s, true];
        }
        return $pair[0] !== "" ? $pair : null;
    }

    /** @param string $n
     * @param mixed $v
     * @return string */
    static function unparse_pair($n, $v) {
        if ($v === true) {
            return $n;
        } else if ($v === false) {
            return "{$n}=no";
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
            $x[] = self::unparse_pair($n, $v);
        }
        return "[" . join(",", $x) . "]";
    }
}
