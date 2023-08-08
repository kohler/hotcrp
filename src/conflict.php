<?php
// conflict.php -- HotCRP conflict type class
// Copyright (c) 2008-2022 Eddie Kohler; see LICENSE.

class Conflict {
    /** @var Conf */
    private $conf;
    /** @var bool */
    private $_desc;
    /** @var array<int,string> */
    private $_tmap = [];

    const GENERAL = 2;

    static private $desc_map = [2 => "Recent collaborator",
                                4 => "Advisor/advisee",
                                6 => "Institutional",
                                8 => "Personal",
                                10 => "Other"];
    static private $json_map = [2 => "collaborator",
                                4 => "advisor",
                                6 => "institutional",
                                8 => "personal",
                                10 => "other"];

    /** @param int $ct
     * @return bool */
    static function is_conflicted($ct) {
        return $ct > CONFLICT_MAXUNCONFLICTED;
    }
    /** @param int $ct
     * @return bool */
    static function is_author($ct) {
        return $ct >= CONFLICT_AUTHOR;
    }
    /** @param int $ct
     * @return bool */
    static function is_pinned($ct) {
        return ($ct & 1) !== 0;
    }
    /** @param int $ct
     * @param bool $pinned
     * @return int */
    static function set_pinned($ct, $pinned) {
        return $pinned ? $ct | 1 : $ct & ~1;
    }
    /** @param int $ct
     * @return int */
    static function pc_part($ct) {
        return $ct & 31;
    }
    /** @param int $ct1
     * @param int $ct2
     * @return int */
    static function merge($ct1, $ct2) {
        if ($ct2 >= CONFLICT_AUTHOR && $ct1 < CONFLICT_AUTHOR) {
            $ct1 |= CONFLICT_CONTACTAUTHOR;
        }
        if (($ct2 & CONFLICT_PCMASK) !== 0
            && (($ct1 & CONFLICT_PCMASK) === 0
                || (($ct1 & 1) === 0 && ($ct2 & 1) !== 0))) {
            $ct1 = ($ct1 & ~CONFLICT_PCMASK) | ($ct2 & CONFLICT_PCMASK);
        }
        return $ct1;
    }

    function __construct(Conf $conf) {
        $this->conf = $conf;
        $this->_desc = !!$conf->setting("sub_pcconfsel");
    }

    /** @return list<int> */
    function basic_conflict_types() {
        return array_keys(self::$desc_map);
    }

    /** @param string $text
     * @param int $old
     * @return int|false */
    function parse_assignment($text, $old) {
        // Returns a conflict type
        if ($text === true) {
            return $old > CONFLICT_MAXUNCONFLICTED ? $old : Conflict::GENERAL;
        } else if ($text === false) {
            return 0;
        }
        $pinned = null;
        $ct = null;
        $au = 0;
        foreach (explode(" ", strtolower($text)) as $w) {
            $thisct = null;
            if ($w === "pin" || $w === "pinned") {
                $pinned = true;
            } else if ($w === "unpin" || $w === "unpinned") {
                $pinned = false;
            } else if ($w === "none" || $w === "unconflicted" || $w === "noconflict" || $w === "n" || $w === "no" || $w === "0") {
                $thisct = 0;
            } else if ($w === "conflict" || $w === "conflicted" || $w === "y" || $w === "yes" || $w === "1") {
                $thisct = $old > CONFLICT_MAXUNCONFLICTED ? $old : Conflict::GENERAL;
            } else if ($w === "author") {
                $au |= CONFLICT_AUTHOR;
            } else if ($w === "contact") {
                $au |= CONFLICT_CONTACTAUTHOR;
            } else if ($w === "collab" || $w === "collaborator") {
                $thisct = 2;
            } else if ($w === "student" || $w === "advisor" || $w === "advisee") {
                $thisct = 4;
            } else if ($w === "institution" || $w === "institutional") {
                $thisct = 6;
            } else if ($w === "personal") {
                $thisct = 8;
            } else if ($w === "other") {
                $thisct = 10;
            } else if (ctype_digit($w)) {
                $thisct = (int) $w;
            } else if ($w !== "") {
                return false;
            }
            if ($thisct !== null) {
                if ($ct !== null && $ct !== $thisct) {
                    return false;
                }
                $ct = $thisct;
            }
        }
        if (($au !== 0 || is_bool($pinned)) && $ct === null) {
            $ct = $old;
        }
        if ($ct !== null) {
            return self::set_pinned($au | $ct, $pinned ?? (($ct & 1) !== 0));
        } else {
            return false;
        }
    }

    /** @return int|false */
    function parse_json($j) {
        if (is_bool($j)) {
            return $j ? self::GENERAL : 0;
        } else if (is_int($j)) {
            return $j;
        } else if (is_string($j)) {
            return $this->parse_assignment($j, 0);
        } else {
            return false;
        }
    }

    /** @param int $ct */
    function unparse_text($ct) {
        if (!isset($this->_tmap[$ct])) {
            if ($ct <= CONFLICT_MAXUNCONFLICTED) {
                $t = "No conflict";
            } else if ($ct >= CONFLICT_CONTACTAUTHOR) {
                $t = "Contact";
            } else if ($ct >= CONFLICT_AUTHOR) {
                $t = "Author";
            } else if ($ct === (self::GENERAL | 1)) {
                $t = "Pinned conflict";
            } else if ($this->_desc && isset(self::$desc_map[$ct & ~1])) {
                $t = self::$desc_map[$ct & ~1];
            } else {
                $t = "Conflict";
            }
            $this->_tmap[$ct] = $this->conf->_c("conflict_type", $t);
        }
        return $this->_tmap[$ct];
    }

    /** @param int $ct */
    function unparse_selector_text($ct) {
        if (($ct & 1) !== 0 && $ct !== (self::GENERAL | 1)) {
            return "Pinned " . lcfirst($this->unparse_text($ct & ~1));
        } else {
            return $this->unparse_text($ct);
        }
    }

    /** @param int $ct
     * @return string */
    function unparse_html($ct) {
        return htmlspecialchars($this->unparse_text($ct));
    }

    /** @param int $ct
     * @return string */
    function unparse_text_description($ct)  {
        if (!$this->_desc && isset(self::$desc_map[$ct & ~1])) {
            return $this->conf->_c("conflict_type", self::$desc_map[$ct & ~1]);
        } else {
            return $this->unparse_text($ct);
        }
    }

    /** @param int $ct
     * @return string */
    function unparse_html_description($ct) {
        return htmlspecialchars($this->unparse_text_description($ct));
    }

    /** @param int $ct
     * @return string */
    function unparse_csv($ct) {
        if ($ct <= CONFLICT_MAXUNCONFLICTED) {
            return "N";
        } else if (!$this->_desc) {
            return "Y";
        } else {
            return $this->unparse_text($ct);
        }
    }

    /** @param int $ct
     * return bool|string */
    function unparse_json($ct) {
        if ($ct <= 0) {
            return false;
        } else if (!$this->_desc && $ct === self::GENERAL) {
            return true;
        }
        $w = [];
        if ($ct & 1) {
            $w[] = "pinned";
        }
        if (isset(self::$json_map[$ct & 30])) {
            $w[] = self::$json_map[$ct & 30];
        } else if ($ct & 30) {
            $w[] = (string) ($ct & 30);
        } else if ($ct & 1) {
            $w[] = "unconflicted";
        }
        if ($ct & CONFLICT_AUTHOR) {
            $w[] = "author";
        }
        if ($ct & CONFLICT_CONTACTAUTHOR) {
            $w[] = "contact";
        }
        return join(" ", $w);
    }

    /** @param int $ct
     * @return string */
    function unparse_assignment($ct) {
        $j = $this->unparse_json($ct);
        if (is_bool($j)) {
            return $j ? "conflict" : "no";
        } else {
            return $j;
        }
    }
}
