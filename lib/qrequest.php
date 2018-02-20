<?php
// qrequest.php -- HotCRP helper class for request objects (no warnings)
// Copyright (c) 2006-2018 Eddie Kohler; see LICENSE.

class Qrequest implements ArrayAccess, IteratorAggregate, Countable, JsonSerializable {
    // NB see also count()
    private $____method;
    private $____a = [];
    private $____files = [];
    private $____x = [];
    private $____post_ok = false;
    private $____post_empty = false;
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
        unset($this->____a[$offset]);
    }
    function offsetUnset($offset) {
        unset($this->$offset);
    }
    function getIterator() {
        return new ArrayIterator($this->make_array());
    }
    function __set($name, $value) {
        $this->$name = $value;
        unset($this->____a[$name]);
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
    function get_a($name, $default = null) {
        if (property_exists($this, $name)) {
            $default = $this->$name;
            if ($default === "__array__" && isset($this->____a[$name]))
                $default = $this->____a[$name];
        }
        return $default;
    }
    function allow_a(/* ... */) {
        foreach (func_get_args() as $name) {
            if (property_exists($this, $name)
                && $this->$name === "__array__"
                && isset($this->____a[$name])) {
                $this->$name = $this->____a[$name];
                unset($this->____a[$name]);
            }
        }
    }
    function set_req($name, $value) {
        if (is_array($value)) {
            $this->$name = "__array__";
            $this->____a[$name] = $value;
        } else {
            $this->$name = $value;
        }
    }
    function count() {
        return count(get_object_vars($this)) - 6;
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
    function keys() {
        $d = [];
        foreach (array_keys(get_object_vars($this)) as $k)
            if (substr($k, 0, 4) !== "____")
                $d[] = $k;
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
    function file_filename($name) {
        $fn = false;
        if (array_key_exists($name, $this->____files))
            $fn = $this->____files[$name]["name"];
        return $fn;
    }
    function file_contents($name) {
        $data = false;
        if (array_key_exists($name, $this->____files))
            $data = @file_get_contents($this->____files[$name]["tmp_name"]);
        return $data;
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
    function approve_post() {
        $this->____post_ok = true;
    }
    function post_ok() {
        return $this->____post_ok;
    }
    function set_post_empty() {
        $this->____post_empty = true;
    }
    function post_empty() {
        return $this->____post_empty;
    }
}
