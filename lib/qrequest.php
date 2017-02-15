<?php
// qrequest.php -- HotCRP helper class for request objects (no warnings)
// HotCRP is Copyright (c) 2006-2017 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

class Qrequest implements ArrayAccess, IteratorAggregate, Countable, JsonSerializable {
    private $____method;
    private $____files = [];
    private $____x = [];
    function __construct($method, $data = null) {
        $this->____method = $method;
        if ($data)
            foreach ((array) $data as $k => $v)
                $this->$k = $v;
    }
    function method() {
        return $this->____method;
    }
    function offsetExists($offset) {
        return property_exists($this, $offset);
    }
    function& offsetGet($offset) {
        $x = null;
        if (property_exists($this, $offset))
            $x =& $this->$offset;
        return $x;
    }
    function offsetSet($offset, $value) {
        $this->$offset = $value;
    }
    function offsetUnset($offset) {
        unset($this->$offset);
    }
    function getIterator() {
        return new ArrayIterator($this->make_array());
    }
    function __set($name, $value) {
        $this->$name = $value;
    }
    function& __get($name) {
        $x = null;
        if (property_exists($this, $name))
            $x =& $this->$name;
        return $x;
    }
    function __isset($name) {
        return isset($this->$name);
    }
    function __unset($name) {
        unset($this->$name);
    }
    function get($name, $default = null) {
        if (property_exists($this, $name))
            $default = $this->$name;
        return $default;
    }
    function count() {
        return count(get_object_vars($this)) - 3;
    }
    function jsonSerialize() {
        return $this->make_array();
    }
    function make_array() {
        $d = [];
        foreach (get_object_vars($this) as $k => $v)
            if (substr($k, 0, 4) !== "____")
                $d[$k] = $v;
        return $d;
    }
    function make_object() {
        return (object) $this->make_array();
    }
    function contains($key) {
        return property_exists($this, $key);
    }
    function set_file($name, $finfo) {
        $this->____files[$name] = $finfo;
    }
    function has_files() {
        return !empty($this->____files);
    }
    function has_file($name) {
        return isset($this->____files[$name]);
    }
    function file($name) {
        $f = null;
        if (array_key_exists($name, $this->____files))
            $f = $this->____files[$name];
        return $f;
    }
    function file_contents($name) {
        $f = false;
        if (array_key_exists($name, $this->____files))
            $f = @file_get_contents($this->____files[$name]["tmp_name"]);
        return $f;
    }
    function files() {
        return $this->____files;
    }
    function set_attachment($name, $x) {
        $this->____x[$name] = $x;
    }
    function has_attachments() {
        return !empty($this->____x);
    }
    function has_attachment($name) {
        return isset($this->____x[$name]);
    }
    function attachment($name) {
        $x = null;
        if (array_key_exists($name, $this->____x))
            $x = $this->____x[$name];
        return $x;
    }
    function attachments() {
        return $this->____x;
    }
}
