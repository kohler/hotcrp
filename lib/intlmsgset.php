<?php
// intlmsg.php -- HotCRP helper functions for message i18n
// HotCRP is Copyright (c) 2006-2016 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

class IntlMsg {
    public $context;
    public $otext;
    public $require;
    public $priority = 0.0;
    public $next;

    function check_require(IntlMsgSet $ms, $args) {
        if (!$this->require)
            return 0;
        $nreq = 0;
        foreach ($this->require as $req)
            if (preg_match('/\A\s*\$(\w+)\s*(|[=!<>]=?|≠|≤|≥)\s*(|[-+]?(?:\d+\.?\d*|\.\d+))\s*\z/', $req, $m)
                && ($m[2] === "") === ($m[3] === "")) {
                if (ctype_digit($m[1]))
                    $arg = get($args, +$m[1]);
                else
                    $arg = $ms->get($m[1]);
                if ((string) $arg === ""
                    || ($m[2] !== "" && !CountMatcher::compare((float) $arg, $m[2], (float) $m[3])))
                    return false;
                ++$nreq;
            }
        return $nreq;
    }
}

class IntlMsgSet {
    private $ims = [];
    private $defs = [];

    function add($m, $context = null) {
        $im = new IntlMsg;
        if (is_string($m)) {
            $i = 0;
            $args = func_get_args();
            $nargs = count($args);
            $m = [];
            if ($nargs >= 3 && is_string($args[2]))
                $m["context"] = $args[$i++];
            $m["itext"] = $args[$i++];
            $m["otext"] = $args[$i++];
            if ($i < $nargs && (is_int($args[$i]) || is_float($args[$i])))
                $m["priority"] = $args[$i++];
            if ($i < $nargs && is_array($args[$i]))
                $m["require"] = $args[$i++];
            assert($i == $nargs);
        }
        if (is_object($m))
            $m = (array) $m;
        $xcontext = $context;
        if (isset($m["context"]))
            $xcontext = ((string) $xcontext === "" ? "" : $xcontext . "/") . $m["context"];
        if (isset($m["members"]) && is_array($m["members"])) {
            foreach ($m["members"] as $mm)
                $this->add($mm, $xcontext);
            return true;
        }
        if (!isset($m["itext"]) || !is_string($m["itext"]))
            return false;
        $im = new IntlMsg;
        $im->context = $xcontext;
        $im->otext = isset($m["otext"]) ? $m["otext"] : $m["itext"];
        if (isset($m["require"]) && is_array($m["require"]))
            $im->require = $m["require"];
        if (isset($m["priority"]) && (is_float($m["priority"]) || is_int($m["priority"])))
            $im->priority = $m["priority"];
        $id = isset($m["id"]) ? $m["id"] : $m["itext"];
        $im->next = get($this->ims, $id);
        $this->ims[$id] = $im;
        return true;
    }

    function _add_json($fj) {
        if (is_object($fj))
            return $this->add($fj);
        else
            return false;
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
            if ($context !== null && $im->context !== $context)
                continue;
            $nreq = $im->require ? $im->check_require($this, $args) : 0;
            if ($nreq !== false
                && (!$match
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
        $im = $this->find(null, $itext, $args);
        if ($im)
            $args[0] = $im->otext;
        return $this->expand($args);
    }

    function xc($context, $itext) {
        $args = array_slice(func_get_args(), 1);
        $im = $this->find($context, $itext, $args);
        if (!$im)
            $im = $this->find(null, $itext, $args);
        if ($im)
            $args[0] = $im->otext;
        return $this->expand($args);
    }
}
