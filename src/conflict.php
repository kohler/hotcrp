<?php
// conflict.php -- HotCRP conflict type class
// Copyright (c) 2008-2020 Eddie Kohler; see LICENSE.

class Conflict {
    private $conf;
    private $_typemap;
    private $_typemap_html;

    const GENERAL = 2;
    const PINNED = 8;
    const PLACEHOLDER = 1000;

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
                               8 => "pinned",
                               CONFLICT_AUTHOR => "author",
                               CONFLICT_CONTACTAUTHOR => "author");

    static function is_conflicted($ct) {
        return $ct > 0;
    }
    static function is_author($ct) {
        return $ct >= CONFLICT_AUTHOR;
    }
    static function is_pinned($ct) {
        return $ct >= self::PINNED && $ct < CONFLICT_AUTHOR;
    }
    static function set_pinned($ct, $pinned) {
        if (self::is_pinned($ct) === !!$pinned) {
            return $ct;
        } else if ($pinned) {
            return self::PINNED;
        } else {
            return self::GENERAL;
        }
    }

    function __construct(Conf $conf) {
        $this->conf = $conf;
    }
    function basic_conflict_types() {
        return array_keys(self::$typedesc);
    }
    function parse_assignment($text, $default_yes) {
        // Returns a conflict type; never is_author
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
            return self::GENERAL /* 2 */;
        } else if ($text === "advisor" || $text === "student" || $text === "advisor/student" || $text === "advisee") {
            return 3;
        } else if ($text === "institution" || $text === "institutional") {
            return 4;
        } else if ($text === "personal") {
            return 5;
        } else if ($text === "other") {
            return 6;
        } else if ($text === "confirmed" || $text === "chair-confirmed" || $text === "pinned") {
            return self::PINNED;
        } else {
            return false;
        }
    }
    function parse_json($j) {
        if (is_bool($j)) {
            return $j ? self::GENERAL : 0;
        } else if (is_int($j) && isset(self::$type_names[$j])) {
            return $j;
        } else if (is_string($j)) {
            return $this->parse_assignment($j, self::GENERAL);
        } else {
            return false;
        }
    }

    private function type_map() {
        if ($this->_typemap === null) {
            $this->_typemap = [];
            foreach ([0 => "No conflict",
                      1 => "Conflict",
                      self::PINNED => "Pinned conflict",
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
    function unparse_assignment($ct) {
        $j = self::$type_names[$ct] ?? null;
        if (is_bool($j)) {
            return $j ? "yes" : "none";
        } else {
            return $j;
        }
    }
}
