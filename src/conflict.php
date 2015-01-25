<?php
// conflict.php -- HotCRP conflict type class
// HotCRP is Copyright (c) 2008-2015 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

class Conflict {
    static $type_descriptions = array(0 => "No conflict",
                                      3 => "Advisor/student",
                                      2 => "Recent collaborator",
                                      4 => "Institutional",
                                      5 => "Personal",
                                      6 => "Other");
    static $type_names = array(0 => false,
                               1 => true,
                               2 => "collaborator",
                               3 => "advisor",
                               4 => "institution",
                               5 => "personal",
                               6 => "other",
                               CONFLICT_CHAIRMARK => "confirmed",
                               CONFLICT_AUTHOR => "author",
                               CONFLICT_CONTACTAUTHOR => "author");

    public $value;

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
