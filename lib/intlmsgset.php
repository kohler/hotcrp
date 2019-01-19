<?php
// intlmsg.php -- HotCRP helper functions for message i18n
// Copyright (c) 2006-2019 Eddie Kohler; see LICENSE.

class IntlMsg {
    public $context;
    public $otext;
    public $require;
    public $priority = 0.0;
    public $format;
    public $no_conversions;
    public $next;

    private function resolve_arg(IntlMsgSet $ms, $args, $argname, &$val) {
        if ($argname[0] === "\$") {
            $which = substr($argname, 1);
            if (ctype_digit($which)) {
                $val = get($args, +$which);
                return true;
            } else
                return false;
        } else if (($ans = $ms->resolve_requirement_argument($argname))) {
            $val = is_array($ans) ? $ans[0] : $ans;
            return true;
        } else
            return false;
    }
    function check_require(IntlMsgSet $ms, $args) {
        if (!$this->require)
            return 0;
        $nreq = 0;
        foreach ($this->require as $req) {
            if (preg_match('/\A\s*(\S+?)\s*([=!<>]=?|≠|≤|≥)\s*([-+]?(?:\d+\.?\d*|\.\d+))\s*\z/', $req, $m)
                && $this->resolve_arg($ms, $args, $m[1], $val)) {
                if ((string) $val === ""
                    || !CountMatcher::compare((float) $val, $m[2], (float) $m[3]))
                    return false;
                ++$nreq;
            } else if (preg_match('/\A\s*(\S+?)\s*([=!]=?|≠|!?\^=)\s*(\S+)\s*\z/', $req, $m)
                       && $this->resolve_arg($ms, $args, $m[1], $val)) {
                if ((string) $val === "")
                    return false;
                if ($m[2] === "^=" || $m[2] === "!^=") {
                    $have = str_starts_with($val, $m[3]);
                    $weight = 0.9;
                } else {
                    $have = $val === $m[3];
                    $weight = 1;
                }
                $want = ($m[2] === "=" || $m[2] === "==" || $m[2] === "^=");
                if ($have !== $want)
                    return false;
                $nreq += $weight;
            } else if (preg_match('/\A\s*(!*)\s*(\S+)\s*\z/', $req, $m)
                       && $this->resolve_arg($ms, $args, $m[2], $val)) {
                $bool_arg = $val !== null && $val !== false && $val !== "" && $val !== 0 && $val !== [];
                if ($bool_arg !== (strlen($m[1]) % 2 === 0))
                    return false;
                ++$nreq;
            } else if (($weight = $ms->resolve_requirement($req)) !== null) {
                if ($weight <= 0)
                    return false;
                $nreq += $weight;
            }
        }
        return $nreq;
    }
}

class IntlMsgSet {
    private $ims = [];
    private $template = [];
    private $require_resolvers = [];
    private $_ctx;
    private $_default_priority;

    const PRIO_OVERRIDE = 1000.0;

    function set_default_priority($p) {
        $this->_default_priority = (float) $p;
    }
    function clear_default_priority() {
        $this->_default_priority = null;
    }

    function add($m, $ctx = null) {
        if (is_string($m))
            $x = $this->addj(func_get_args());
        else if (!$ctx)
            $x = $this->addj($m);
        else {
            $octx = $this->_ctx;
            $this->_ctx = $ctx;
            $x = $this->addj($m);
            $this->_ctx = $octx;
        }
        return $x;
    }

    function addj($m) {
        if (is_associative_array($m))
            $m = (object) $m;
        if (is_object($m) && isset($m->members) && is_array($m->members)) {
            $octx = $this->_ctx;
            if (isset($m->context) && is_string($m->context))
                $this->_ctx = ((string) $this->_ctx === "" ? "" : $this->_ctx . "/") . $m->context;
            foreach ($m->members as $mm)
                $this->addj($mm);
            $this->_ctx = $octx;
            return true;
        }
        if (is_object($m)
            && isset($m->id)
            && isset($m->template)
            && (is_object($m->template) || is_associative_array($m->template))) {
            if (!isset($this->template[$m->id]))
                $this->template[$m->id] = [];
            foreach ((array) $m->template as $k => $v)
                $this->template[$m->id][strtolower($k)] = $v;
            return true;
        }
        $im = new IntlMsg;
        if ($this->_default_priority !== null)
            $im->priority = $this->_default_priority;
        if (is_array($m)) {
            $n = count($m);
            $p = false;
            while ($n > 0 && !is_string($m[$n - 1])) {
                if ((is_int($m[$n - 1]) || is_float($m[$n - 1])) && $p === false)
                    $p = $im->priority = (float) $m[$n - 1];
                else if (is_array($m[$n - 1]) && $im->require === null)
                    $im->require = $m[$n - 1];
                else
                    return false;
                --$n;
            }
            if ($n < 2 || $n > 3 || !is_string($m[0]) || !is_string($m[1])
                || ($n === 3 && !is_string($m[2])))
                return false;
            if ($n === 3) {
                $im->context = $m[0];
                $itext = $m[1];
                $im->otext = $m[2];
            } else {
                $itext = $m[0];
                $im->otext = $m[1];
            }
        } else if (is_object($m)) {
            if (isset($m->context) && is_string($m->context))
                $im->context = $m->context;
            if (isset($m->id) && is_string($m->id))
                $itext = $m->id;
            else if (isset($m->itext) && is_string($m->itext))
                $itext = $m->itext;
            else
                return false;
            if (isset($m->otext) && is_string($m->otext))
                $im->otext = $m->otext;
            else if (isset($m->itext) && is_string($m->itext))
                $im->otext = $m->itext;
            else
                return false;
            if (isset($m->priority) && (is_float($m->priority) || is_int($m->priority)))
                $im->priority = (float) $m->priority;
            if (isset($m->require) && is_array($m->require))
                $im->require = $m->require;
            if (isset($m->format) && (is_int($m->format) || is_string($m->format)))
                $im->format = $m->format;
            if (isset($m->no_conversions) && is_bool($m->no_conversions))
                $im->no_conversions = $m->no_conversions;
        } else
            return false;
        if ($this->_ctx)
            $im->context = $this->_ctx . ($im->context ? "/" . $im->context : "");
        $im->next = get($this->ims, $itext);
        $this->ims[$itext] = $im;
        return true;
    }

    function add_override($id, $otext) {
        return $this->addj(["id" => $id, "otext" => $otext, "priority" => self::PRIO_OVERRIDE, "no_conversions" => true]);
    }

    function add_requirement_resolver($function) {
        $this->require_resolvers[] = $function;
    }
    function resolve_requirement($requirement) {
        foreach ($this->require_resolvers as $fn)
            if (($x = call_user_func($fn, $requirement, true)) !== null)
                return $x;
        return null;
    }
    function resolve_requirement_argument($argname) {
        foreach ($this->require_resolvers as $fn)
            if (($x = call_user_func($fn, $argname, false)) !== null)
                return $x;
        return null;
    }

    private function find($context, $itext, $args, $priobound) {
        $match = null;
        $matchnreq = $matchctxlen = 0;
        for ($im = get($this->ims, $itext); $im; $im = $im->next) {
            $ctxlen = $nreq = 0;
            if ($context !== null && $im->context !== null) {
                if ($context === $im->context)
                    $ctxlen = 10000;
                else {
                    $ctxlen = (int) min(strlen($context), strlen($im->context));
                    if (strncmp($context, $im->context, $ctxlen) !== 0
                        || ($ctxlen < strlen($context) && $context[$ctxlen] !== "/")
                        || ($ctxlen < strlen($im->context) && $im->context[$ctxlen] !== "/"))
                        continue;
                }
            } else if ($context === null && $im->context !== null)
                continue;
            if ($im->require
                && ($nreq = $im->check_require($this, $args)) === false)
                continue;
            if ($priobound !== null
                && $im->priority >= $priobound)
                continue;
            if (!$match
                || $im->priority > $match->priority
                || ($im->priority == $match->priority
                    && ($ctxlen > $matchctxlen
                        || ($ctxlen == $matchctxlen
                            && $nreq > $matchnreq)))) {
                $match = $im;
                $matchnreq = $nreq;
                $matchctxlen = $ctxlen;
            }
        }
        return $match;
    }

    private function expand($s, $args, $id, $im) {
        if ($s === null || $s === false || $s === "")
            return $s;
        $tmpl = $id ? get($this->template, $id) : null;
        if (count($args) > 1 || $tmpl !== null) {
            $pos = $argnum = 0;
            while (($pos = strpos($s, "%", $pos)) !== false) {
                ++$pos;
                if ($tmpl !== null
                    && preg_match('{(?!\d+)\w+(?=%)}A', $s, $m, 0, $pos)
                    && ($t = get($tmpl, strtolower($m[0]))) !== null) {
                    $t = substr($s, 0, $pos - 1) . $this->expand($t, $args, null, null);
                    $s = $t . substr($s, $pos + strlen($m[0]) + 1);
                    $pos = strlen($t);
                } else if ($im && $im->no_conversions) {
                    /* do nothing */
                } else if ($pos < strlen($s) && $s[$pos] === "%") {
                    $s = substr($s, 0, $pos) . substr($s, $pos + 1);
                } else if (preg_match('/(?:(\d+)\$)?(\d*(?:\.\d+)?)([deEifgosxX])/A', $s, $m, 0, $pos)) {
                    $argi = $m[1] ? +$m[1] : ++$argnum;
                    if (isset($args[$argi])) {
                        $args[0] = "%{$argi}\${$m[2]}{$m[3]}";
                        $x = call_user_func_array("sprintf", $args);
                        $s = substr($s, 0, $pos - 1) . $x . substr($s, $pos + strlen($m[0]));
                        $pos = $pos - 1 + strlen($x);
                    }
                }
            }
        }
        return $s;
    }

    function x($itext) {
        $args = func_get_args();
        if (($im = $this->find(null, $itext, $args, null)))
            $args[0] = $im->otext;
        return $this->expand($args[0], $args, null, $im);
    }

    function xc($context, $itext) {
        $args = array_slice(func_get_args(), 1);
        if (($im = $this->find($context, $itext, $args, null)))
            $args[0] = $im->otext;
        return $this->expand($args[0], $args, null, $im);
    }

    function xi($id, $itext) {
        $args = array_slice(func_get_args(), 1);
        if (($im = $this->find(null, $id, $args, null)))
            $args[0] = $im->otext;
        return $this->expand($args[0], $args, $id, $im);
    }

    function xci($context, $id, $itext) {
        $args = array_slice(func_get_args(), 2);
        if (($im = $this->find($context, $id, $args, null)))
            $args[0] = $im->otext;
        return $this->expand($args[0], $args, $id, $im);
    }

    function default_itext($id, $itext) {
        $args = array_slice(func_get_args(), 1);
        if (($im = $this->find(null, $id, $args, self::PRIO_OVERRIDE)))
            $args[0] = $im->otext;
        return $args[0];
    }
}
