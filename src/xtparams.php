<?php
// xtparams.php -- HotCRP class for expanding extensions
// Copyright (c) 2006-2025 Eddie Kohler; see LICENSE.

class XtParams {
    /** @var Conf
     * @readonly */
    public $conf;
    /** @var ?Contact
     * @readonly */
    public $user;
    /** @var bool */
    private $follow_alias = true;
    /** @var bool */
    private $warn_deprecated = true;
    /** @var string */
    private $reflags = "";
    /** @var ?string */
    private $require_key;
    /** @var ?list<callable(string,object,XtParams):(?bool)> */
    public $primitive_checkers;
    /** @var ?object */
    public $last_match;
    /** @var ?Qrequest */
    public $qreq;
    /** @var ?ComponentSet */
    public $component_set;
    /** @var ?PaperList */
    public $paper_list;

    /** @param Conf $conf
     * @param ?Contact $user */
    function __construct($conf, $user) {
        $this->conf = $conf;
        $this->user = $user;
    }

    /** @param bool $match_ignores_case
     * @return $this */
    function set_match_ignores_case($match_ignores_case) {
        $this->reflags = $match_ignores_case ? "i" : "";
        return $this;
    }

    /** @param bool $follow_alias
     * @return $this */
    function set_follow_alias($follow_alias) {
        $this->follow_alias = $follow_alias;
        return $this;
    }

    /** @param bool $warn_deprecated
     * @return $this */
    function set_warn_deprecated($warn_deprecated) {
        $this->warn_deprecated = $warn_deprecated;
        return $this;
    }

    /** @param ?string $method
     * @return $this */
    function set_require_key_for_method($method) {
        if ($method === null || $method === "") {
            $this->require_key = null;
        } else if ($method === "GET" || $method === "HEAD") {
            $this->require_key = "get";
        } else {
            $this->require_key = strtolower($method);
        }
        return $this;
    }

    /** @return bool */
    function match_ignores_case() {
        return $this->reflags === "i";
    }

    /** @param object $xt
     * @return bool */
    function checkf($xt) {
        return !isset($xt->allow_if) || $this->check($xt->allow_if, $xt);
    }

    /** @param string $s
     * @param ?object $xt
     * @param bool $simple
     * @return bool */
    function check_string($s, $xt, $simple = false) {
        $user = $this->user;
        if ($s === "chair" || $s === "admin") {
            return !$user || $user->privChair;
        } else if ($s === "manager") {
            return !$user || $user->is_manager();
        } else if ($s === "pc") {
            return !$user || $user->isPC;
        } else if ($s === "pc_member") {
            return !$user || $user->is_pc_member();
        } else if ($s === "author") {
            return !$user || $user->is_author();
        } else if ($s === "reviewer") {
            return !$user || $user->is_reviewer();
        } else if ($s === "view_review") {
            return !$user || $user->can_view_some_review();
        } else if ($s === "!empty") {
            return !$user || !$user->is_empty();
        } else if ($s === "empty") {
            return $user && $user->is_empty();
        } else if ($s === "email") {
            return !$user || $user->has_email();
        } else if ($s === "disabled") {
            return $user && $user->is_disabled();
        } else if ($s === "!disabled") {
            return !$user || !$user->is_disabled();
        } else if ($s === "allow" || $s === "true") {
            return true;
        } else if ($s === "deny" || $s === "false") {
            return false;
        } else if (!$simple && strcspn($s, " !&|()") !== strlen($s)) {
            $e = $this->check_complex_string($s, $xt);
            if ($e === null) {
                throw new UnexpectedValueException("xt_check syntax error in `{$s}`");
            }
            return $e;
        } else if (strpos($s, "::") !== false) {
            Conf::xt_resolve_require($xt);
            return call_user_func($s, $xt, $this);
        } else if (str_starts_with($s, "opt.")) {
            list($x, $compar, $compval) = self::split_comparison($s);
            $v = $this->conf->opt(substr($x, 4));
            return self::resolve_comparison($v, $compar, $compval);
        } else if (str_starts_with($s, "setting.")) {
            list($x, $compar, $compval) = self::split_comparison($s);
            $v = $this->conf->setting(substr($x, 8));
            return self::resolve_comparison($v, $compar, $compval);
        } else if (str_starts_with($s, "conf.")) {
            $f = substr($s, 5);
            return !!$this->conf->$f();
        } else if (str_starts_with($s, "user.")) {
            $f = substr($s, 5);
            return !$user || $user->$f();
        }
        if (isset($this->primitive_checkers)) {
            foreach ($this->primitive_checkers as $checker) {
                if (($x = $checker($s, $xt, $this)) !== null)
                    return $x;
            }
        }
        error_log("unknown xt_check {$s}");
        return false;
    }

    /** @param string $s
     * @return array{string,string,string} */
    static private function split_comparison($s) {
        $len = strlen($s);
        $p = strcspn($s, "!=<>");
        if ($p === $len) {
            return [$s, "", ""];
        }
        $op = $s[$p];
        if ($p + 1 !== $len && $s[$p+1] === "=") {
            $op .= "=";
        }
        return [substr($s, 0, $p), $op, substr($s, $p + strlen($op))];
    }

    /** @param mixed $v
     * @param string $compar
     * @param string $compval
     * @return bool */
    static private function resolve_comparison($v, $compar, $compval) {
        if ($compar === "") {
            return !!$v;
        } else if (!is_scalar($v)) {
            return false;
        } else if (is_numeric($v) && is_numeric($compval)) {
            return CountMatcher::compare((float) $v, $compar, (float) $compval);
        } else if ($compar === "!=") {
            return $v !== $compval;
        } else if ($compar === "=" || $compar === "==") {
            return $v === $compval;
        }
        return false;
    }

    /** @param string $s
     * @param object $xt
     * @return ?bool */
    private function check_complex_string($s, $xt) {
        $stk = [];
        $p = 0;
        $l = strlen($s);
        $e = null;
        $eval = true;
        while ($p !== $l) {
            $ch = $s[$p];
            if ($ch === " ") {
                ++$p;
            } else if ($ch === "(" || $ch === "!") {
                if ($e !== null) {
                    return null;
                }
                $stk[] = [$ch === "(" ? 0 : 9, null, $eval];
                ++$p;
            } else if ($ch === "&" || $ch === "|") {
                if ($e === null || $p + 1 === $l || $s[$p + 1] !== $ch) {
                    return null;
                }
                $prec = $ch === "&" ? 2 : 1;
                $e = self::check_complex_resolve_stack($stk, $e, $prec);
                $stk[] = [$prec, $e, $eval];
                $eval = self::check_complex_want_eval($stk);
                $e = null;
                $p += 2;
            } else if ($ch === ")") {
                if ($e === null) {
                    return null;
                }
                $e = self::check_complex_resolve_stack($stk, $e, 1);
                if (empty($stk)) {
                    return null;
                }
                array_pop($stk);
                $eval = self::check_complex_want_eval($stk);
                ++$p;
            } else {
                if ($e !== null) {
                    return null;
                }
                $wl = strcspn($s, " &|()", $p);
                $e = $eval && $this->check_string(substr($s, $p, $wl), $xt, true);
                $p += $wl;
            }
        }
        if (!empty($stk) && $e !== null) {
            $e = self::check_complex_resolve_stack($stk, $e, 1);
        }
        return empty($stk) ? $e : null;
    }

    /** @param list<array{int,?bool,bool}> &$stk
     * @param bool $e
     * @param int $prec
     * @return bool */
    static private function check_complex_resolve_stack(&$stk, $e, $prec) {
        $n = count($stk) - 1;
        while ($n >= 0 && $stk[$n][0] >= $prec) {
            $se = array_pop($stk);
            '@phan-var array{int,?bool,bool} $se';
            --$n;
            if ($se[0] === 9) {
                $e = !$e;
            } else if ($se[0] === 2) {
                $e = $se[1] && $e;
            } else {
                $e = $se[1] || $e;
            }
        }
        return $e;
    }

    /** @param list<array{int,?bool,bool}> $stk
     * @return bool */
    static private function check_complex_want_eval($stk) {
        $n = count($stk);
        $se = $n ? $stk[$n - 1] : null;
        return !$se || ($se[2] && ($se[0] !== 1 || !$se[1]) && ($se[0] !== 2 || $se[1]));
    }

    /** @param null|bool|string|list<bool|string> $expr
     * @param ?object $xt
     * @return bool */
    function check($expr, $xt = null) {
        if ($expr === null) {
            return true;
        } else if (is_bool($expr)) {
            return $expr;
        } else if (is_string($expr)) {
            return $this->check_string($expr, $xt);
        }
        foreach ($expr as $e) {
            if (!(is_bool($e) ? $e : $this->check_string($e, $xt)))
                return false;
        }
        return true;
    }

    /** @param ?object $xt
     * @return bool */
    function allowed($xt) {
        return $xt && (!isset($xt->allow_if) || $this->check($xt->allow_if, $xt));
    }

    /** @param ?object $xt
     * @param Conf $conf
     * @param ?Contact $user
     * @return bool */
    static function static_allowed($xt, $conf, $user) {
        if (!$xt) {
            return false;
        } else if (!isset($xt->allow_if)) {
            return true;
        } else if (is_bool($xt->allow_if)) {
            return $xt->allow_if;
        }
        return (new XtParams($conf, $user))->check($xt->allow_if, $xt);
    }

    /** @param ?object $xt
     * @return list<string> */
    static function allow_list($xt) {
        if ($xt && isset($xt->allow_if)) {
            return is_array($xt->allow_if) ? $xt->allow_if : [$xt->allow_if];
        }
        return [];
    }

    /** @param array<string,list<object>> $map
     * @param string $name
     * @return ?object */
    function search_name($map, $name) {
        for ($aliases = 0;
             $aliases < 5 && $name !== null && isset($map[$name]);
             ++$aliases) {
            $xt = $this->search_list($map[$name]);
            if ($xt && isset($xt->alias) && is_string($xt->alias) && $this->follow_alias) {
                $name = $xt->alias;
            } else {
                return $xt;
            }
        }
        return null;
    }

    /** @param list<object> $list
     * @param int $first
     * @param int $last
     * @return ?object */
    function search_slice($list, $first, $last) {
        $reqkey = $this->require_key;
        while ($first < $last) {
            $xt = $list[$first];
            ++$first;
            if ($reqkey !== null && !($xt->{$reqkey} ?? null)) {
                continue;
            }
            while ($first < $last && ($xt->merge ?? false)) {
                $nxt = $list[$first];
                ++$first;
                if ($reqkey !== null && !($nxt->{$reqkey} ?? null)) {
                    continue;
                }
                // apply overlay ($xt) to new base ($nxt)
                $nxt = clone $nxt;
                foreach (get_object_vars($xt) as $k => $v) {
                    if ($k === "merge" || $k === "__source_order") {
                        // skip
                    } else if (!property_exists($nxt, $k)
                               || !is_object($v)
                               || !is_object($nxt->{$k})) {
                        $nxt->{$k} = $v;
                    } else {
                        object_replace_recursive($nxt->{$k}, $v);
                    }
                }
                // replace base
                $xt = $nxt;
            }
            if (isset($xt->deprecated) && $xt->deprecated && $this->warn_deprecated) {
                $name = $xt->name ?? "<unknown>";
                error_log("{$this->conf->dbname}: deprecated use of `{$name}`\n" . debug_string_backtrace());
            }
            $this->last_match = $xt;
            if ($this->checkf($xt)) {
                return $xt;
            }
        }
        return null;
    }

    /** @param list<object> $list
     * @return ?object */
    function search_list($list) {
        $n = count($list);
        if ($n > 1) {
            usort($list, "Conf::xt_priority_compare");
        }
        return $this->search_slice($list, 0, $n);
    }

    /** @param list<object> $factories
     * @param string $name
     * @param ?object $found
     * @return non-empty-list<?object> */
    function search_factories($factories, $name, $found) {
        $xts = [$found];
        foreach ($factories as $fxt) {
            if (Conf::xt_priority_compare($fxt, $found ?? $this->last_match) > 0) {
                break;
            }
            if (!isset($fxt->match)) {
                continue;
            } else if ($fxt->match === ".*") {
                $m = [$name];
            } else if (!preg_match("\1\\A(?:{$fxt->match})\\z\1{$this->reflags}", $name, $m)) {
                continue;
            }
            if (!$this->checkf($fxt)) {
                continue;
            }
            Conf::xt_resolve_require($fxt);
            /** @phan-suppress-next-line PhanAccessReadOnlyProperty */
            $this->user = $this->user ?? $this->conf->root_user();
            if (isset($fxt->expand_function)) {
                $r = call_user_func($fxt->expand_function, $name, $this, $fxt, $m);
            } else {
                $r = (object) ["name" => $name, "match_data" => $m];
            }
            if (is_object($r)) {
                $r = [$r];
            }
            foreach ($r ? : [] as $xt) {
                self::xt_combine($xt, $fxt);
                $prio = Conf::xt_priority_compare($xt, $found);
                if ($prio <= 0 && $this->checkf($xt)) {
                    if ($prio < 0) {
                        $xts = [$xt];
                        $found = $xt;
                    } else {
                        $xts[] = $xt;
                    }
                }
            }
        }
        return $xts;
    }

    /** @param object $xt1
     * @param object $xt2 */
    static private function xt_combine($xt1, $xt2) {
        foreach (get_object_vars($xt2) as $k => $v) {
            if (!property_exists($xt1, $k)
                && $k !== "match"
                && $k !== "expand_function") {
                $xt1->$k = $v;
            }
        }
    }
}
