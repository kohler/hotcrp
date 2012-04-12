<?php

class Conflict {

    var $value;

    function __construct($value) {
	$this->value = $value;
    }

    static function make_nonconflict() {
	return new Conflict(0);
    }
    static function force_author_mark($value) {
	return new Conflict(max(min($this->value, CONFLICT_MAXAUTHORMARK), CONFLICT_AUTHORMARK));
    }

    function is_conflict() {
	return $this->value > 0;
    }
    function is_author_mark() {
	return $this->value >= CONFLICT_AUTHORMARK && $this->value <= CONFLICT_MAXAUTHORMARK;
    }
    function is_author() {
	return $this->value >= CONFLICT_AUTHOR;
    }

}
