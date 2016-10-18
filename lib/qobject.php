<?php
// qobject.php -- HotCRP helper class for quiet objects (no warnings)
// HotCRP is Copyright (c) 2006-2016 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

class Qobject implements ArrayAccess, IteratorAggregate, Countable, JsonSerializable {
    public function __construct($x = array()) {
        if ($x) {
            if (is_object($x))
                $x = (array) $x;
            foreach ($x as $k => $v)
                $this->$k = $v;
        }
    }
    public function offsetExists($offset) {
        return isset($this->$offset);
    }
    public function& offsetGet($offset) {
        $x = null;
        if (property_exists($this, $offset))
            $x =& $this->$offset;
        return $x;
    }
    public function offsetSet($offset, $value) {
        $this->$offset = $value;
    }
    public function offsetUnset($offset) {
        unset($this->$offset);
    }
    public function getIterator() {
        return new ArrayIterator(get_object_vars($this));
    }
    public function __set($name, $value) {
        $this->$name = $value;
    }
    public function& __get($name) {
        $x = null;
        if (property_exists($this, $name))
            $x =& $this->$name;
        return $x;
    }
    public function __isset($name) {
        return isset($this->$name);
    }
    public function __unset($name) {
        unset($this->$name);
    }
    public function get($name, $default = null) {
        if (property_exists($this, $name))
            $default = $this->$name;
        return $default;
    }
    public function count() {
        return count(get_object_vars($this));
    }
    public function jsonSerialize() {
        return get_object_vars($this);
    }
    public function make_array() {
        return get_object_vars($this);
    }
    public function make_object() {
        return (object) get_object_vars($this);
    }
    public function contains($key) {
        return property_exists($this, $offset);
    }
}
