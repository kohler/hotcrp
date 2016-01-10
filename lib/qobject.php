<?php
// qobject.php -- HotCRP helper class for quiet objects (no warnings)
// HotCRP is Copyright (c) 2006-2016 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

class Qobject implements ArrayAccess, IteratorAggregate {
    private $storage = array();
    public function __construct($x = array()) {
        if ($x)
            foreach ($x as $k => $v)
                $this->storage[$k] = $v;
    }
    public function offsetExists($offset) {
        return isset($this->storage[$offset]);
    }
    public function& offsetGet($offset) {
        if (isset($this->storage[$offset]))
            return $this->storage[$offset];
        else
            return null;
    }
    public function offsetSet($offset, $value) {
        if ($offset === null)
            $this->storage[] = $value;
        else
            $this->storage[$offset] = $value;
    }
    public function offsetUnset($offset) {
        unset($this->storage[$offset]);
    }
    public function getIterator() {
        return new ArrayIterator($this->storage);
    }
    public function __set($name, $value) {
        $this->storage[$name] = $value;
    }
    public function& __get($name) {
        if (isset($this->storage[$name]))
            return $this->storage[$name];
        else
            return null;
    }
    public function __isset($name) {
        return isset($this->storage[$name]);
    }
    public function __unset($name) {
        unset($this->storage[$name]);
    }
    public function make_array() {
        return $this->storage;
    }
    public function make_object() {
        return (object) $this->storage;
    }
}
