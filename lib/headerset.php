<?php
// headerset.php -- header-emitting helper class
// Copyright (c) 2006-2026 Eddie Kohler; see LICENSE.

class HeaderSet implements IteratorAggregate, Countable {
    /** @var list<string> */
    private $_headers = [];

    /** @param string &$header
     * @return int */
    static private function search_length(&$header) {
        if (($n = strpos($header, ":")) !== false) {
            return $n + 1;
        }
        $header .= ":";
        return strlen($header);
    }

    /** @param string $header
     * @return ?string */
    function get($header) {
        $n = self::search_length($header);
        foreach ($this->_headers as $s) {
            if (substr_compare($s, $header, 0, $n, true) === 0) {
                return ltrim(substr($s, $n));
            }
        }
        return null;
    }

    /** @param string $header
     * @return list<string> */
    function get_all($header) {
        $n = self::search_length($header);
        $a = [];
        foreach ($this->_headers as $s) {
            if (substr_compare($s, $header, 0, $n, true) === 0) {
                $a[] = ltrim(substr($s, $n));
            }
        }
        return $a;
    }

    /** @param string $header
     * @param bool $replace
     * @return $this */
    function set($header, $replace = true) {
        if ($replace) {
            $this->remove($header);
        }
        $this->_headers[] = $header;
        return $this;
    }

    /** @param string $h
     * @return $this */
    function remove($header) {
        $n = self::search_length($header);
        $r = [];
        foreach ($this->_headers as $k => $s) {
            if (substr_compare($s, $header, 0, $n, true) === 0) {
                unset($this->_headers[$k]);
            }
        }
        return $this;
    }

    /** @return $this */
    function clear() {
        $this->_headers = [];
        return $this;
    }

    #[\ReturnTypeWillChange]
    /** @return int */
    function count() {
        return count($this->_headers);
    }

    /** @return list<string> */
    function as_list() {
        return array_values($this->_headers);
    }

    #[\ReturnTypeWillChange]
    /** @return Iterator<int,string> */
    function getIterator() {
        return new ArrayIterator($this->_headers);
    }

    /** @return Generator<string> */
    function by_name() {
        foreach ($this->_headers as $h) {
            if (($p = strpos($h, ":")) === false) {
                yield "" => $h;
            } else {
                for ($q = $p + 1; $q !== strlen($h) && $h[$q] === " "; ++$q) {
                }
                yield substr($h, 0, $p) => substr($h, $q);
            }
        }
    }
}
