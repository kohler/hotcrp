<?php
// conflict.php -- HotCRP conflict type class
// Copyright (c) 2008-2025 Eddie Kohler; see LICENSE.

class Conflict {
    /** @var Conf */
    private $conf;
    /** @var bool */
    private $_desc;
    /** @var array<int,string> */
    private $_tmap = [];

    /** @deprecated */
    const GENERAL = 2;
    const CT_DEFAULT = 2;
    const CT_ADMINISTRATIVE = 28;
    const CT_GENERIC = 30;
    const F_PIN = 1;
    const FM_PC = 31;
    const FM_PCTYPE = 30;

    static private $desc_map = [
        2 => "Recent collaborator",
        4 => "Advisor/advisee",
        6 => "Institutional",
        8 => "Personal",
        10 => "Other",
        28 => "Administrative"
    ];
    static private $json_map = [
        2 => "collaborator",
        4 => "advisor",
        6 => "institutional",
        8 => "personal",
        10 => "other",
        28 => "administrative"
    ];

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
        return $ct & self::FM_PC;
    }

    /** @param int $old
     * @param int $new
     * @param bool $admin
     * @return int */
    static function apply_pc($old, $new, $admin) {
        assert(($new & ~self::FM_PC) === 0);
        if (!$admin && ($old & 1) !== 0) {
            return $old;
        }
        if (!$admin && ($new & self::FM_PCTYPE) === self::CT_ADMINISTRATIVE) {
            $new = ($new & ~self::FM_PCTYPE)
                | ($old & self::FM_PCTYPE ? : self::CT_GENERIC);
        }
        if (($new & self::FM_PCTYPE) === self::CT_GENERIC) {
            $new = ($new & ~self::FM_PCTYPE)
                | ($old & self::FM_PCTYPE ? : ($admin ? self::CT_ADMINISTRATIVE : self::CT_DEFAULT));
        }
        return $admin ? $new : $new & ~1;
    }

    /** @param int $ct1
     * @param int $ct2
     * @return int */
    static function merge($ct1, $ct2) {
        if (($ct2 & CONFLICT_CONTACTAUTHOR) !== 0
            || ($ct2 >= CONFLICT_AUTHOR && $ct1 < CONFLICT_AUTHOR)) {
            $ct1 |= CONFLICT_CONTACTAUTHOR;
        }
        if (($ct2 & self::FM_PC) !== 0
            && (($ct1 & self::FM_PC) === 0
                || (($ct1 & 1) === 0 && ($ct2 & 1) !== 0))) {
            $ct1 = ($ct1 & ~self::FM_PC) | ($ct2 & self::FM_PC);
        }
        return $ct1;
    }


    function __construct(Conf $conf) {
        $this->conf = $conf;
        $this->_desc = !!$conf->setting("sub_pcconfsel");
    }

    /** @return bool */
    function want_description() {
        return $this->_desc;
    }

    /** @return list<int> */
    function basic_conflict_types() {
        return array_keys(self::$desc_map);
    }

    /** @param string $text
     * @return int|false */
    function parse_assignment($text) {
        if ($text === true) {
            return Conflict::CT_DEFAULT;
        } else if ($text === false) {
            return 0;
        }

        $pin = $ct = null;
        $au = 0;
        foreach (explode(" ", strtolower($text)) as $w) {
            if ($w === "pin" || $w === "pinned" || $w === "unpin" || $w === "unpinned") {
                $p = $w[0] === "p";
                if ($pin !== null && $pin !== $p) {
                    return false;
                }
                $pin = $p;
                continue;
            } else if ($w === "author") {
                $au |= CONFLICT_AUTHOR;
                continue;
            } else if ($w === "contact") {
                $au |= CONFLICT_CONTACTAUTHOR;
                continue;
            }

            $xct = null;
            if ($w === "none" || $w === "n" || $w === "no" || $w === "0" || $w === "off"
                || $w === "unconflicted" || $w === "noconflict"
                || $w === "nonconflict" || $w === "non-conflict") {
                $xct = 0;
            } else if ($w === "y" || $w === "yes" || $w === "1" || $w === "on"
                       || $w === "conflicted" || $w === "conflict") {
                $xct = self::CT_GENERIC;
            } else if ($w === "collab" || $w === "collaborator") {
                $xct = 2;
            } else if ($w === "student" || $w === "advisor" || $w === "advisee") {
                $xct = 4;
            } else if ($w === "institution" || $w === "institutional") {
                $xct = 6;
            } else if ($w === "personal") {
                $xct = 8;
            } else if ($w === "other") {
                $xct = 10;
            } else if ($w === "admin" || $w === "administrative" || $w === "administrator") {
                $xct = self::CT_ADMINISTRATIVE;
            } else if (ctype_digit($w)) {
                $xct = intval($w);
                if (($xct & 1) !== 0 || $w > 30) {
                    return false;
                }
            } else if ($w !== "") {
                return false;
            }

            if ($ct === self::CT_GENERIC && $xct > 0) {
                $ct = $xct;
            } else if ($xct === self::CT_GENERIC && $ct !== null && $ct > 0) {
                $xct = $ct;
            }
            if ($ct !== null && $xct !== $ct) {
                return false;
            }
            $ct = $xct;
        }

        if ($au === 0 && $ct === null && $pin === null) {
            return false;
        }
        if ($pin !== null && $ct === null) {
            $ct = self::CT_GENERIC;
        }
        return $au | ($ct ?? 0) | ($pin ? 1 : 0);
    }

    /** @param mixed $j
     * @return int|false */
    function parse_json($j) {
        if (is_bool($j)) {
            return $j ? self::CT_GENERIC : 0;
        } else if (is_int($j)) {
            return $j;
        } else if (is_string($j)) {
            return $this->parse_assignment($j);
        }
        return false;
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
            } else if ($ct === (self::CT_DEFAULT | 1)
                       || $ct === (self::CT_ADMINISTRATIVE | 1)) {
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

    /** @param list<int> $cts
     * @param bool $admin
     * @return array */
    function selector_options($cts, $admin) {
        $sopt = ["No conflict"];
        foreach ($this->basic_conflict_types() as $ct) {
            if ($admin || $ct !== self::CT_ADMINISTRATIVE)
                $sopt[$ct] = $this->unparse_text($ct);
        }
        foreach ($cts as $ct) {
            if (!isset($sopt[$ct & ~1]))
                $sopt[$ct & ~1] = $this->unparse_text($ct & ~1);
        }
        if ($admin) {
            $sopt["xsep"] = null;
            $sopt[self::CT_ADMINISTRATIVE | self::F_PIN] = "Pinned conflict";
            foreach ($cts as $ct) {
                if (!isset($sopt[$ct]))
                    $sopt[$ct] = "Pinned " . lcfirst($this->unparse_text($ct & ~1));
            }
        }
        return $sopt;
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
        }
        return $this->unparse_text($ct);
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
        }
        return $this->unparse_text($ct);
    }

    /** @param int $ct
     * return bool|string */
    function unparse_json($ct) {
        if ($ct <= 0) {
            return false;
        } else if (!$this->_desc && $ct === self::CT_DEFAULT) {
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
        }
        return $j;
    }
}
