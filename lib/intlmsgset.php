<?php
// intlmsg.php -- HotCRP helper functions for message i18n
// Copyright (c) 2006-2018 Eddie Kohler; see LICENSE.

class IntlMsg {
    public $context;
    public $otext;
    public $require;
    public $priority = 0.0;
    public $next;

    private function arg(IntlMsgSet $ms, $args, $which) {
        if (ctype_digit($which))
            return get($args, +$which);
        else
            return $ms->get($which);
    }
    function check_require(IntlMsgSet $ms, $args) {
        if (!$this->require)
            return 0;
        $nreq = 0;
        foreach ($this->require as $req) {
            if (preg_match('/\A\s*\$(\w+)\s*([=!<>]=?|≠|≤|≥)\s*([-+]?(?:\d+\.?\d*|\.\d+))\s*\z/', $req, $m)) {
                $arg = $this->arg($ms, $args, $m[1]);
                if ((string) $arg === ""
                    || !CountMatcher::compare((float) $arg, $m[2], (float) $m[3]))
                    return false;
                ++$nreq;
            } else if (preg_match('/\A\s*\$(\w+)\s*([=!]=?|≠|!?\^=)\s*(\S+)\s*\z/', $req, $m)) {
                $arg = $this->arg($ms, $args, $m[1]);
                if ((string) $arg === "")
                    return false;
                if ($m[2] === "^=" || $m[2] === "!^=") {
                    $have = str_starts_with($arg, $m[3]);
                    $weight = 0.9;
                } else {
                    $have = $arg === $m[3];
                    $weight = 1;
                }
                $want = ($m[2] === "=" || $m[2] === "==" || $m[2] === "^=");
                if ($have !== $want)
                    return false;
                $nreq += $weight;
            } else if (preg_match('/\A\s*(|!)\s*\$(\w+)\s*\z/', $req, $m)) {
                $arg = $this->arg($ms, $args, $m[2]);
                $bool_arg = (string) $arg !== "" && $arg !== 0;
                if ($bool_arg !== ($m[1] === ""))
                    return false;
                ++$nreq;
            }
        }
        return $nreq;
    }
}

class IntlMsgSet {
    private $ims = [];
    private $defs = [];
    private $_ctx;
    private $_default_priority;

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
        } else
            return false;
        if ($this->_ctx)
            $im->context = $this->_ctx . ($im->context ? "/" . $im->context : "");
        $im->next = get($this->ims, $itext);
        $this->ims[$itext] = $im;
        return true;
    }

    function set($name, $value) {
        $this->defs[$name] = $value;
    }

    function get($name) {
        return get($this->defs, $name);
    }

    private function find($context, $itext, $args) {
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

    private function expand($args) {
        $pos = 0;
        while (($pos = strpos($args[0], "%", $pos)) !== false) {
            if (preg_match('/\A(?!\d+)\w+(?=[$%])/', substr($args[0], $pos + 1), $m)
                && isset($this->defs[$m[0]])) {
                $args[] = $this->defs[$m[0]];
                $t = substr($args[0], 0, $pos + 1) . (count($args) - 1);
                $pos += 1 + strlen($m[0]);
                if ($args[0][$pos] == "%") {
                    $t .= "\$s";
                    ++$pos;
                }
                $args[0] = $t . substr($args[0], $pos);
                $pos = strlen($t);
            } else
                $pos += 2;
        }
        return call_user_func_array("sprintf", $args);
    }

    function x($itext) {
        $args = func_get_args();
        if (($im = $this->find(null, $itext, $args)))
            $args[0] = $im->otext;
        return $this->expand($args);
    }

    function xc($context, $itext) {
        $args = array_slice(func_get_args(), 1);
        if (($im = $this->find($context, $itext, $args)))
            $args[0] = $im->otext;
        return $this->expand($args);
    }

    function xi($id, $itext) {
        $args = array_slice(func_get_args(), 1);
        if (($im = $this->find(null, $id, $args)))
            $args[0] = $im->otext;
        return $this->expand($args);
    }

    function xci($context, $id, $itext) {
        $args = array_slice(func_get_args(), 2);
        if (($im = $this->find($context, $id, $args)))
            $args[0] = $im->otext;
        return $this->expand($args);
    }
}
