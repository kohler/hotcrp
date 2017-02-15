<?php
// intlmsg.php -- HotCRP helper functions for message i18n
// HotCRP is Copyright (c) 2006-2017 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

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
        foreach ($this->require as $req)
            if (preg_match('/\A\s*\$(\w+)\s*(|[=!<>]=?|≠|≤|≥)\s*(|[-+]?(?:\d+\.?\d*|\.\d+))\s*\z/', $req, $m)
                && ($m[2] === "") === ($m[3] === "")) {
                $arg = $this->arg($ms, $args, $m[1]);
                if ((string) $arg === ""
                    || ($m[2] !== "" && !CountMatcher::compare((float) $arg, $m[2], (float) $m[3])))
                    return false;
                ++$nreq;
            } else if (preg_match('/\A\s*!\s*\$(\w+)\s*\z/', $req, $m)) {
                $arg = $this->arg($ms, $args, $m[1]);
                if ((string) $arg !== "" && $arg !== 0)
                    return false;
                ++$nreq;
            }
        return $nreq;
    }
}

class IntlMsgSet {
    private $ims = [];
    private $defs = [];

    function add($m, $ctx = null) {
        if (is_string($m))
            return $this->addj(func_get_args(), null, null);
        else
            return $this->addj($m, null, $ctx);
    }

    function addj($m, $defaults = null, $ctx = null) {
        if (is_associative_array($m))
            $m = (object) $m;
        if (is_object($m) && isset($m->members) && is_array($m->members)) {
            if (isset($m->context) && is_string($m->context))
                $ctx = ((string) $ctx === "" ? "" : $ctx . "/") . $m->context;
            foreach ($m->members as $mm)
                $this->addj($mm, $ctx);
            return true;
        }
        $im = new IntlMsg;
        if ($defaults && isset($defaults["priority"]))
            $im->priority = (float) $defaults["priority"];
        if (is_array($m)) {
            $i = 0;
            $n = count($m);
            if ($n >= 3 && is_string($m[2]))
                $im->context = $m[$i++];
            if ($n < 2 || !is_string($m[$i]) || !is_string($m[$i+1]))
                return false;
            $itext = $m[$i++];
            $im->otext = $m[$i++];
            if ($i < $n && (is_int($m[$i]) || is_float($m[$i])))
                $im->priority = $m[$i++];
            if ($i < $n && is_array($m[$i]))
                $im->require = $m[$i++];
            if ($i != $n)
                return false;
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
                $im->priority = $m->priority;
            if (isset($m->require) && is_array($m->require))
                $im->require = $m->require;
        } else
            return false;
        if ($ctx)
            $im->context = $ctx . ($im->context ? "/" . $im->context : "");
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
        $matchnreq = 0;
        for ($im = get($this->ims, $itext); $im; $im = $im->next) {
            if ($context !== null && $im->context !== null && $im->context !== $context)
                continue;
            $nreq = $im->require ? $im->check_require($this, $args) : 0;
            if ($nreq !== false
                && (!$match
                    || ($im->context === $context && $match->context !== $context)
                    || ($im->priority > $match->priority)
                    || ($im->priority == $match->priority && $nreq > $matchnreq))) {
                $match = $im;
                $matchnreq = $nreq;
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
