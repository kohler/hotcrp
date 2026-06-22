<?php
// tokenscope.php -- manage scopes for HotCRP OAuth tokens
// Copyright (c) 2008-2026 Eddie Kohler; see LICENSE.

final class TokenSubsetScope {
    /** @var int */
    public $bits;
    /** @var int */
    public $type;
    /** @var mixed */
    public $selector;
    /** @var SearchTerm */
    public $term;

    /** @param int $bits
     * @param 1|2|3 $type
     * @param mixed $selector */
    function __construct($bits, $type, $selector) {
        $this->bits = $bits;
        $this->type = $type;
        $this->selector = $selector;
    }
}

final class TokenScope {
    const S_SUB_READ = 0x1;
    const S_SUB_WRITE = 0x2;
    const S_SUB_ADMIN = 0x4;
    const S_DOC_READ = 0x10;
    const S_DOC_WRITE = 0x20;
    const S_DOC_ADMIN = 0x40;
    const S_REV_READ = 0x100;
    const S_REV_WRITE = 0x200;
    const S_REV_ADMIN = 0x400;
    const S_TAG_READ = 0x1000;
    const S_TAG_WRITE = 0x2000;
    const S_TAG_ADMIN = 0x4000;
    const S_CMT_READ = 0x10000;
    const S_CMT_WRITE = 0x20000;
    const S_CMT_ADMIN = 0x40000;
    const S_OTH_READ = 0x100000;
    const S_OTH_WRITE = 0x200000;
    const S_OTH_ADMIN = 0x400000;

    /** @var int */
    private $_all_bits;
    /** @var int */
    private $_any_bits;
    /** @var ?list<TokenSubsetScope> */
    private $_rest;
    /** @var Contact */
    private $_user;

    /** @var array<string,int> */
    static public $scopes = [
        "*" => -1, "all" => -1, "none" => 0,
        "openid" => -2, "email" => -2, "profile" => -2, "address" => -2, "phone" => -2,
        "write" => 0x33333333, "read" => 0x11111111, "admin" => -1,
        "paper:admin" => 0x77777, "paper:write" => 0x33333, "paper:read" => 0x11111,
        "submission:admin" => 0x77, "submission:write" => 0x33, "submission:read" => 0x11,
        "document:admin" => 0x73, "document:write" => 0x33, "document:read" => 0x11,
        "review:admin" => 0x701, "review:write" => 0x301, "review:read" => 0x101,
        "tag:admin" => 0x7001, "tag:write" => 0x3001, "tag:read" => 0x1001,
        "comment:admin" => 0x70001, "comment:write" => 0x30001, "comment:read" => 0x10001,
        "other:admin" => 0x700000, "other:write" => 0x300000, "other:read" => 0x100000,
        "submeta:admin" => 0x7, "submeta:write" => 0x3, "submeta:read" => 0x1
    ];

    /** @param int $all_bits
     * @param ?list<TokenSubsetScope> $rest
     * @param ?Contact $user */
    function __construct($all_bits, $rest = null, $user = null) {
        $this->_all_bits = $this->_any_bits = $all_bits;
        foreach ($rest ?? [] as $tss) {
            if (($this->_all_bits & $tss->bits) === $tss->bits) {
                continue;
            }
            $this->_any_bits |= $tss->bits;
            foreach ($this->_rest ?? [] as $xtss) {
                if ($xtss->type === $tss->type
                    && $xtss->selector === $tss->selector) {
                    $xtss->bits |= $tss->bits;
                    continue 2;
                }
            }
            $this->_rest[] = clone $tss;
        }
        $this->_user = $user;
    }

    /** @param string $s
     * @param ?Contact $user
     * @return ?TokenScope */
    static function parse($s, $user) {
        $all_bits = 0;
        $any = false;
        $rest = null;
        '@phan-var-force ?list $rest';
        foreach (explode(" ", $s) as $w) {
            if ($w === "") {
                continue;
            }
            $b = self::$scopes[$w] ?? 0;
            if ($b === -1) {
                return null;
            }
            if ($b === -2) {
                continue;
            }
            if ($b > 0) {
                $all_bits |= $b;
                $any = true;
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
                $ld = urldecode(substr($w, $h + 1));
                if ($ld === "") {
                    // ignore
                } else if (ctype_digit($ld)) {
                    $lt = 1;
                    $ld = stoi($ld);
                } else if (preg_match('/\A' . TAG_REGEX . '\z/', $ld)) {
                    $lt = 2;
                } else {
                    $lt = 3;
                    $ld = "#" . $ld;
                }
                if ($lt !== 0) {
                    $b = self::$scopes[substr($w, 0, $h)] ?? 0;
                }
            }
            if ($b !== 0 && $lt !== 0 && $ld !== null) {
                $rest[] = new TokenSubsetScope($b, $lt, $ld);
            }
            $any = true;
        }
        if (!$any) {
            return null;
        }
        return new TokenScope($all_bits, $rest, $user);
    }


    /** @param ?PaperInfo $prow
     * @param int $test_bits
     * @return int */
    private function __bits($prow, $test_bits) {
        $b = $this->_all_bits;
        if ($this->_rest === null
            || $prow === null
            || ($b & $test_bits) === $test_bits) {
            return $b;
        }
        $overrides = null;
        foreach ($this->_rest as $tss) {
            if (($b & $tss->bits) === $tss->bits
                || ($tss->bits & $test_bits) === 0) {
                continue;
            }
            $lt = $tss->type;
            if ($lt === 1) {
                $ok = $prow->paperId === $tss->selector;
            } else if ($lt === 2) {
                if ($overrides === null) {
                    $overrides = $this->_user->add_overrides(Contact::OVERRIDE_SCOPE);
                }
                $ok = $prow->has_viewable_tag($tss->selector, $this->_user);
            } else /* $lt === 3 */ {
                if ($overrides === null) {
                    $overrides = $this->_user->add_overrides(Contact::OVERRIDE_SCOPE);
                }
                if (!$tss->term) {
                    $srch = new PaperSearch($this->_user, $tss->selector);
                    $tss->term = $srch->main_term();
                }
                $ok = $this->_user->can_view_paper($prow)
                    && $tss->term->test($prow, null);
            }
            if (!$ok) {
                continue;
            }
            $b |= $tss->bits;
            if (($b & $test_bits) === $test_bits) {
                break;
            }
        }
        if ($overrides !== null) {
            $this->_user->set_overrides($overrides);
        }
        return $b;
    }

    /** @param ?PaperInfo $prow
     * @return int */
    function bits($prow) {
        return $this->__bits($prow, ~0);
    }

    /** @return int */
    function any_bits() {
        return $this->_any_bits;
    }

    /** @param int $bit
     * @param ?PaperInfo $prow
     * @return bool */
    function allows($bit, $prow = null) {
        return ($this->__bits($prow, $bit) & $bit) === $bit;
    }

    /** @param int $bit
     * @return bool */
    function allows_some($bit) {
        return ($this->_any_bits & $bit) === $bit;
    }

    /** @param ?TokenScope $tsa
     * @param null|TokenScope|string $tsb
     * @return ?TokenScope */
    static function intersect($tsa, $tsb) {
        if (is_string($tsb)) {
            $tsb = TokenScope::parse($tsb, null);
        }
        if (!$tsa || !$tsb) {
            return $tsa ?? $tsb;
        }
        $a_bits = $tsa->_all_bits;
        $b_bits = $tsb->_all_bits;
        $all = $a_bits & $b_bits;
        $rest = [];
        $a_rest = $tsa->_rest ?? [];
        foreach ($tsb->_rest ?? [] as $btss) {
            foreach ($a_rest as $i => $atss) {
                if ($atss->type === $btss->type
                    && $atss->selector === $btss->selector) {
                    $b = ($a_bits | $atss->bits) & ($b_bits | $btss->bits);
                    if (($b & ~$all) !== 0) {
                        $rest[] = new TokenSubsetScope($b, $btss->type, $btss->selector);
                    }
                    array_splice($a_rest, $i, 1);
                    continue 2;
                }
            }
            $b = $a_bits & ($b_bits | $btss->bits);
            if (($b & ~$all) !== 0) {
                $rest[] = new TokenSubsetScope($b, $btss->type, $btss->selector);
            }
        }
        foreach ($a_rest as $atss) {
            $b = ($a_bits | $atss->bits) & $b_bits;
            if (($b & ~$all) !== 0) {
                $rest[] = new TokenSubsetScope($b, $atss->type, $atss->selector);
            }
        }
        return new TokenScope($all, $rest, $tsa->_user ?? $tsb->_user);
    }

    /** @param int $bits
     * @return list<string> */
    static function unparse_bits($bits) {
        if ($bits === ~0 || $bits < -1) {
            return ["all"];
        } else if ($bits === 0) {
            return ["none"];
        }
        $a = [];
        $need = $bits;
        foreach (self::$scopes as $name => $mask) {
            if (($bits & $mask) === $mask && ($need & $mask) !== 0) {
                $a[] = $name;
                $need &= ~$mask;
            }
        }
        if ($need !== 0) {
            $a[] = "error";
        }
        return $a;
    }

    /** @param int $bits
     * @return list<string> */
    static function unparse_missing_bits($bits) {
        if ($bits === ~0 || $bits < -1) {
            return ["all"];
        }
        $a = [];
        while ($bits !== 0) {
            $found = "error";
            $fmask = PHP_INT_MAX;
            $top_bit = $bits & ($bits - 1) ? : $bits;
            foreach (self::$scopes as $name => $mask) {
                if ($mask > 0 && ($top_bit & $mask) !== 0) {
                    $found = $name;
                    $fmask = $mask;
                    if ($mask === $bits) {
                        break;
                    }
                }
            }
            $a[] = $found;
            $bits &= ~$fmask;
        }
        return $a;
    }

    /** @return string */
    static function unparse(?TokenScope $ts) {
        if (!$ts || $ts->_all_bits === ~0) {
            return "all";
        }
        $a = $ts->_all_bits !== 0 ? self::unparse_bits($ts->_all_bits) : [];
        foreach ($ts->_rest ?? [] as $tss) {
            if ($tss->type === 1) {
                $sfx = "#{$tss->selector}";
            } else if ($tss->type === 2) {
                $sfx = "#" . urlencode($tss->selector);
            } else /* $lt === 3 */ {
                $sfx = "?q=" . urlencode($tss->selector);
            }
            foreach (self::unparse_bits($tss->bits) as $pfx) {
                $a[] = $pfx . $sfx;
            }
        }
        return empty($a) ? "none" : join(" ", $a);
    }

    /** @param ?string $haystack
     * @param string $needle
     * @return bool */
    static function scope_str_contains($haystack, $needle) {
        if ($haystack === null || $needle === "") {
            return false;
        }
        $hl = strlen($haystack);
        $nl = strlen($needle);
        $p = 0;
        while (true) {
            $p = strpos($haystack, $needle, $p);
            if ($p === false) {
                return false;
            }
            if (($p === 0 || $haystack[$p - 1] === " ")
                && ($p + $nl === $hl || $haystack[$p + $nl] === " ")) {
                return true;
            }
            $p += $nl;
        }
    }

    /** @param ?string $scope
     * @return bool */
    static function scope_str_all_openid($scope) {
        if ($scope === null || $scope === "") {
            return false;
        }
        $any = false;
        foreach (explode(" ", $scope) as $w) {
            if ($w === "") {
                continue;
            }
            $any = true;
            if ((self::$scopes[$w] ?? null) !== -2) {
                return false;
            }
        }
        return $any;
    }
}
