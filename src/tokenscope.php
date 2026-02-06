<?php
// tokenscope.php -- manage scopes for HotCRP OAuth tokens
// Copyright (c) 2008-2026 Eddie Kohler; see LICENSE.

final class TokenScope {
    const S_METHOD_GET = 0x1;
    const S_METHOD_POST = 0x2;
    const S_METHOD_DELETE = 0x4;
    const SM_METHOD = 0xF;
    const S_SUB_READ = 0x10;
    const S_SUB_WRITE = 0x20;
    const S_SUB_ADMIN = 0x40;
    const S_REV_READ = 0x100;
    const S_REV_WRITE = 0x200;
    const S_REV_ADMIN = 0x400;
    const S_TAG_READ = 0x1000;
    const S_TAG_WRITE = 0x2000;
    const S_TAG_ADMIN = 0x4000;
    const S_CMT_READ = 0x10000;
    const S_CMT_WRITE = 0x20000;
    const S_CMT_ADMIN = 0x40000;

    /** @var int */
    private $_bits;
    /** @var list */
    private $_rest;
    /** @var Contact */
    private $_user;

    static public $scopes = [
        "*" => -1, "all" => -1,
        "method:get" => 0x1, "method:post" => 0x2, "method:delete" => 0x4,
        "paper:read" => 0x11110, "paper:write" => 0x33330, "paper:admin" => 0x77770,
        "submission:read" => 0x10, "submission:write" => 0x30, "submission:admin" => 0x70,
        "review:read" => 0x100, "review:write" => 0x300, "review:admin" => 0x700,
        "tag:read" => 0x1000, "tag:write" => 0x3000, "tag:admin" => 0x7000,
        "comment:read" => 0x10000, "comment:write" => 0x30000, "comment:admin" => 0x70000
    ];

    /** @param int $bits
     * @param list $rest
     * @param Contact $user */
    function __construct($bits, $rest, $user) {
        $this->_bits = $bits;
        $this->_rest = $rest;
        $this->_user = $user;
    }

    /** @param string $s
     * @param Contact $user
     * @return ?TokenScope */
    static function parse($s, $user) {
        $bits = 0;
        $mask = ~0;
        $rest = null;
        foreach (explode(" ", $s) as $w) {
            if ($w === "") {
                continue;
            }
            $b = self::$scopes[$w] ?? 0;
            if ($b < 0) {
                return null;
            }
            if ($b > 0) {
                $bits |= $b;
                $mask &= $b & self::SM_METHOD ? ~self::SM_METHOD : self::SM_METHOD;
                continue;
            }
            $q = strpos($w, "?");
            $h = strpos($w, "#");
            $lt = 0;
            $ld = null;
            if ($q !== false && ($h === false || $h > $q)) {
                if (preg_match('/\G\?q=[^&;\s]*+\z/', $w, $m, 0, $q)) {
                    $b = self::$scopes[substr($w, 0, $q)] ?? 0;
                    $lt = 3;
                    $ld = urldecode(substr($w, $q + 3));
                }
            } else if ($h !== false) {
                $t = substr($w, $h + 1);
                if (ctype_digit($t) && !str_starts_with($t, "0")) {
                    $lt = 1;
                    $ld = stoi($t);
                } else if ($t !== "") {
                    $lt = 2;
                    $ld = urldecode($t);
                }
                if ($lt !== 0) {
                    $b = self::$scopes[substr($w, 0, $h)] ?? 0;
                }
            }
            if ($b === 0 || $lt === 0 || $ld === null) {
                $b = str_starts_with($w, "method:") ? self::SM_METHOD : 0;
            } else {
                $rest[] = $b;
                $rest[] = $lt;
                $rest[] = $ld;
            }
            $mask &= $b & self::SM_METHOD ? ~self::SM_METHOD : self::SM_METHOD;
        }
        if ($mask === ~0) {
            return null;
        }
        return new TokenScope($bits | $mask, $rest, $user);
    }

    /** @param int $bit
     * @param ?PaperInfo $prow
     * @return bool */
    function allow($bit, $prow = null) {
        if (($this->_bits & $bit) !== 0) {
            return true;
        }
        if ($this->_rest === null || $prow === null) {
            return false;
        }
        $overrides = null;
        $ok = false;
        for ($i = 0; ($b = $this->_rest[$i] ?? null) !== null && !$ok; $i += 3) {
            if (($b & $bit) === 0) {
                continue;
            }
            $lt = $this->_rest[$i + 1];
            $ld = $this->_rest[$i + 2];
            if ($lt === 1) {
                $ok = $prow->paperId === $ld;
            } else if ($lt === 2) {
                if ($overrides === null) {
                    $overrides = $this->_user->add_overrides(Contact::OVERRIDE_SCOPE);
                }
                $ok = $prow->has_viewable_tag($ld, $this->_user);
            } else if ($lt === 3) {
                if ($overrides === null) {
                    $overrides = $this->_user->add_overrides(Contact::OVERRIDE_SCOPE);
                }
                if (is_string($ld)) {
                    $srch = new PaperSearch($this->_user, $ld);
                    $ld = $this->_rest[$i + 2] = $srch->main_term();
                }
                $ok = $this->_user->can_view_paper($prow)
                    && $ld->test($prow, null);
            }
        }
        if ($overrides !== null) {
            $this->_user->set_overrides($overrides);
        }
        return $ok;
    }

    /** @param int $bit
     * @return bool */
    function allow_some($bit) {
        if (($this->_bits & $bit) !== 0) {
            return true;
        }
        if ($this->_rest === null) {
            return false;
        }
        for ($i = 0; ($b = $this->_rest[$i] ?? null) !== null; $i += 3) {
            if (($b & $bit) !== 0) {
                return true;
            }
        }
        return false;
    }

    /** @return bool */
    function checks_method() {
        return ($this->_bits & self::SM_METHOD) !== self::SM_METHOD;
    }
}
