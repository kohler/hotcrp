<?php
// headerset.php -- header-emitting helper class
// Copyright (c) 2006-2026 Eddie Kohler; see LICENSE.

class HeaderSet implements IteratorAggregate, Countable {
    /** @var array<string,list<string>> */
    private $_headers = [];
    /** @var int */
    private $_nheaders = 0;

    /** @param string $header
     * @return string */
    static function header_name($header) {
        $colon = strpos($header, ":");
        return $colon > 0 ? strtolower(substr($header, 0, $colon)) : "";
    }

    /** @param string $header
     * @param string $name
     * @return bool */
    static function header_matches($header, $name) {
        $len = strlen($name);
        return strlen($header) > $len
            && substr_compare($header, $name, 0, $len, true) === 0
            && $header[$len] === ":";
    }

    #[\ReturnTypeWillChange]
    /** @return int */
    function count() {
        return $this->_nheaders;
    }

    /** @param string $name
     * @return bool */
    function has($name) {
        return isset($this->_headers[strtolower($name)]);
    }

    /** @param string $name
     * @return ?string */
    function get($name) {
        $h = $this->_headers[strtolower($name)][0] ?? null;
        return $h ? ltrim(substr($h, strlen($name) + 1)) : null;
    }

    /** @param string $name
     * @return list<string> */
    function get_all($name) {
        $hs = [];
        foreach ($this->_headers[strtolower($name)] ?? [] as $h) {
            $hs[] = ltrim(substr($h, strlen($name) + 1));
        }
        return $hs;
    }

    /** @param string $header
     * @param bool $replace
     * @return $this */
    function set($header, $replace = true) {
        $name = self::header_name($header);
        if ($replace) {
            $this->_nheaders += 1 - count($this->_headers[$name] ?? []);
            $this->_headers[$name] = [$header];
        } else {
            ++$this->_nheaders;
            $this->_headers[$name][] = $header;
        }
        return $this;
    }

    /** @param string $name
     * @return $this */
    function remove($name) {
        unset($this->_headers[strtolower($name)]);
        return $this;
    }

    /** @return $this */
    function clear() {
        $this->_headers = [];
        $this->_nheaders = 0;
        return $this;
    }

    /** @return list<string> */
    function as_list() {
        $hs = [];
        foreach ($this->_headers as $hx) {
            array_push($hs, ...$hx);
        }
        return $hs;
    }

    #[\ReturnTypeWillChange]
    /** @return Generator<string> */
    function getIterator() {
        foreach ($this->_headers as $hx) {
            yield from $hx;
        }
    }

    /** @return Generator<string> */
    function by_name() {
        foreach ($this->_headers as $n => $hx) {
            $nl = strlen($n);
            foreach ($hx as $h) {
                for ($p = $nl + 1; $p !== strlen($h) && $h[$p] === " "; ++$p) {
                }
                yield substr($h, 0, $nl) => substr($h, $p);
            }
        }
    }
}
