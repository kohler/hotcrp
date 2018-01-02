<?php
// qobject.php -- HotCRP helper class for quiet objects (no warnings)
// Copyright (c) 2006-2018 Eddie Kohler; see LICENSE.

class Qobject implements ArrayAccess, IteratorAggregate, Countable, JsonSerializable {
    function __construct($x = null) {
        if ($x) {
            if (is_object($x))
                $x = (array) $x;
            foreach ($x as $k => $v)
                $this->$k = $v;
        }
    }
    function offsetExists($offset) {
        return isset($this->$offset);
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
        return new ArrayIterator(get_object_vars($this));
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
        return count(get_object_vars($this));
    }
    function jsonSerialize() {
        return get_object_vars($this);
    }
    function make_array() {
        return get_object_vars($this);
    }
    function make_object() {
        return (object) get_object_vars($this);
    }
    function contains($key) {
        return property_exists($this, $offset);
    }
}
