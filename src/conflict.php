<?php
// conflict.php -- HotCRP conflict type class
// Copyright (c) 2008-2020 Eddie Kohler; see LICENSE.

class Conflict {
    private $conf;
    private $_typemap;
    private $_typemap_html;

    static $typedesc = [3 => "Advisor/advisee",
                        2 => "Recent collaborator",
                        4 => "Institutional",
                        5 => "Personal",
                        6 => "Other"];
    static $type_names = array(0 => false,
                               1 => true,
                               2 => "collaborator",
                               3 => "advisor",
                               4 => "institution",
                               5 => "personal",
                               6 => "other",
                               7 => true,
                               CONFLICT_CHAIRMARK => "confirmed",
                               CONFLICT_AUTHOR => "author",
                               CONFLICT_CONTACTAUTHOR => "author");

    static function is_author_mark($ct) {
        return $ct >= CONFLICT_AUTHORMARK && $ct <= CONFLICT_MAXAUTHORMARK;
    }
    static function constrain_editable($ct, $admin) {
        if (is_string($ct)) {
            $ct = cvtint($ct, 0);
        }
        if ($ct > 0) {
            $max = $admin ? CONFLICT_CHAIRMARK : CONFLICT_MAXAUTHORMARK;
            return max(min($ct, $max), CONFLICT_AUTHORMARK);
        } else {
            return 0;
        }
    }

    function __construct(Conf $conf) {
        $this->conf = $conf;
    }
    function basic_conflict_types() {
        return array_keys(self::$typedesc);
    }
    function parse_text($text, $default_yes) {
        if (is_bool($text)) {
            return $text ? $default_yes : 0;
        }
        $text = strtolower(trim($text));
        if ($text === "none") {
            return 0;
        } else if (($b = friendly_boolean($text)) !== null) {
            return $b ? $default_yes : 0;
        } else if ($text === "conflict") {
            return $default_yes;
        } else if ($text === "collab" || $text === "collaborator" || $text === "recent collaborator") {
            return CONFLICT_AUTHORMARK /* 2 */;
        } else if ($text === "advisor" || $text === "student" || $text === "advisor/student" || $text === "advisee") {
            return 3;
        } else if ($text === "institution" || $text === "institutional") {
            return 4;
        } else if ($text === "personal") {
            return 5;
        } else if ($text === "other") {
            return 6;
        } else if ($text === "confirmed" || $text === "chair-confirmed") {
            return CONFLICT_CHAIRMARK;
        } else {
            return false;
        }
    }
    function parse_json($j) {
        if (is_bool($j)) {
            return $j ? CONFLICT_AUTHORMARK : 0;
        } else if (is_int($j) && isset(self::$type_names[$j])) {
            return $j;
        } else if (is_string($j)) {
            return $this->parse_text($j, CONFLICT_AUTHORMARK);
        } else {
            return false;
        }
    }
    private function type_map() {
        if ($this->_typemap === null) {
            $this->_typemap = [];
            foreach ([0 => "No conflict",
                      1 => "Conflict",
                      CONFLICT_CHAIRMARK => "Pinned conflict",
                      CONFLICT_AUTHOR => "Author",
                      CONFLICT_CONTACTAUTHOR => "Contact"] as $n => $t) {
                $this->_typemap[$n] = $this->conf->_c("conflict_type", $t);
            }
            foreach (self::$typedesc as $n => $t) {
                $this->_typemap[$n] = $this->conf->_c("conflict_type", $t);
            }
        }
        return $this->_typemap;
    }
    function unparse_text($ct) {
        $ct = min($ct, CONFLICT_CONTACTAUTHOR);
        $tm = $this->type_map();
        return $tm[isset($tm[$ct]) ? $ct : 1];
    }
    function unparse_html($ct) {
        if ($this->_typemap_html === null) {
            $this->_typemap_html = array_map("htmlspecialchars", $this->type_map());
        }
        $ct = min($ct, CONFLICT_CONTACTAUTHOR);
        return $this->_typemap_html[isset($this->_typemap_html[$ct]) ? $ct : 1];
    }
    function unparse_json($ct) {
        return self::$type_names[$ct];
    }
}
