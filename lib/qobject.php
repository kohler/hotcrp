<?php
// qobject.php -- HotCRP helper class for quiet objects (no warnings)
// HotCRP is Copyright (c) 2006-2015 Eddie Kohler and Regents of the UC
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
    public function offsetGet($offset) {
        return isset($this->storage[$offset]) ? $this->storage[$offset] : null;
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
    public function __get($name) {
        return isset($this->storage[$name]) ? $this->storage[$name] : null;
    }
    public function __isset($name) {
        return isset($this->storage[$name]);
    }
    public function __unset($name) {
        unset($this->storage[$name]);
    }
}
