<?php
// conflict.php -- HotCRP conflict type class
// HotCRP is Copyright (c) 2008-2014 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

class Conflict {

    var $value;

    function __construct($value) {
	$this->value = $value;
    }

    static function make_nonconflict() {
	return new Conflict(0);
    }
    static function force_author_mark($value, $privChair) {
	$max = $privChair ? CONFLICT_CHAIRMARK : CONFLICT_MAXAUTHORMARK;
	return new Conflict(max(min($value, $max), CONFLICT_AUTHORMARK));
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
