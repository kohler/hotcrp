<?php
// conflict.php -- HotCRP conflict type class
// Copyright (c) 2008-2020 Eddie Kohler; see LICENSE.

class Conflict {
    private $conf;
    private $_desc;
    private $_tmap;

    const GENERAL = 2;
    const PINNED = 8;
    const PLACEHOLDER = 1000;

    static private $desc_map = [2 => "Recent collaborator",
                                3 => "Advisor/advisee",
                                4 => "Institutional",
                                5 => "Personal",
                                6 => "Other"];
    static private $json_map = [0 => false,
                                1 => true,
                                2 => "collaborator",
                                3 => "advisor",
                                4 => "institution",
                                5 => "personal",
                                6 => "other",
                                7 => true,
                                8 => "pinned",
                                CONFLICT_AUTHOR => "author",
                                CONFLICT_CONTACTAUTHOR => "author"];

    static function is_conflicted($ct) {
        return $ct > 0;
    }
    static function is_author($ct) {
        return $ct >= CONFLICT_AUTHOR;
    }
    static function is_pinned($ct) {
        return $ct >= self::PINNED;
    }
    static function set_pinned($ct, $pinned) {
        if (self::is_author($ct) || (self::is_pinned($ct) === !!$pinned)) {
            return $ct;
        } else {
            return $pinned ? self::PINNED : self::GENERAL;
        }
    }
    static function strip($ct) {
        return $ct >= CONFLICT_AUTHOR ? $ct & 96 : $ct;
    }

    function __construct(Conf $conf) {
        $this->conf = $conf;
        $this->_desc = $conf->setting("sub_pcconfdesc");
    }
    function basic_conflict_types() {
        return array_keys(self::$desc_map);
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
        } else if (is_int($j) && isset(self::$json_map[$j])) {
            return $j;
        } else if (is_string($j)) {
            return $this->parse_assignment($j, self::GENERAL);
        } else {
            return false;
        }
    }

    private function tmap() {
        if ($this->_tmap === null) {
            $this->_tmap = [];
            foreach ([0 => "No conflict",
                      1 => "Conflict",
                      self::PINNED => "Pinned conflict",
                      CONFLICT_AUTHOR => "Author",
                      CONFLICT_CONTACTAUTHOR => "Contact"] as $n => $t) {
                $this->_tmap[$n] = $this->conf->_c("conflict_type", $t);
            }
            foreach (self::$desc_map as $n => $t) {
                $this->_tmap[$n] = $this->conf->_c("conflict_type", $t);
            }
        }
        return $this->_tmap;
    }
    function unparse_text($ct) {
        $tm = $this->tmap();
        return $tm[self::strip($ct)] ?? $tm[1];
    }
    function unparse_html($ct) {
        return htmlspecialchars($this->unparse_text($ct));
    }
    function unparse_csv($ct) {
        if ($ct <= 0 || $ct === self::PINNED || !$this->_desc) {
            return $ct <= 0 ? "N" : "Y";
        } else {
            return $this->unparse_text($ct);
        }
    }
    function unparse_json($ct) {
        return self::$json_map[self::strip($ct)];
    }
    function unparse_assignment($ct) {
        $j = self::$json_map[self::strip($ct)] ?? null;
        if (is_bool($j)) {
            return $j ? "yes" : "none";
        } else {
            return $j;
        }
    }
}
