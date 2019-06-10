<?php
// src/groupedextensions.php -- HotCRP extensible groups
// Copyright (c) 2006-2019 Eddie Kohler; see LICENSE.

class GroupedExtensions {
    private $user;
    private $_subgroups;
    private $_render_state;
    private $_render_stack;
    private $_render_classes;
    private $_annexes = [];
    static private $next_placeholder;

    function _add_json($fj) {
        if (!isset($fj->name)) {
            $fj->name = "__" . self::$next_placeholder . "__";
            ++self::$next_placeholder;
        }
        if (!isset($fj->group)) {
            if (($pos = strrpos($fj->name, "/")) !== false)
                $fj->group = substr($fj->name, 0, $pos);
            else
                $fj->group = $fj->name;
        }
        if (!isset($fj->synonym))
            $fj->synonym = [];
        else if (is_string($fj->synonym))
            $fj->synonym = [$fj->synonym];
        if (!isset($fj->anchorid)
            && !str_starts_with($fj->name, "__")
            && ($pos = strpos($fj->name, "/")) !== false) {
            $x = substr($fj->name, $pos + 1);
            $fj->anchorid = preg_replace('/\A[^A-Za-z]+|[^A-Za-z0-9_:.]+/', "-", strtolower($x));
        }
        $this->_subgroups[$fj->name][] = $fj;
        return true;
    }
    function __construct(Contact $user, $args /* ... */) {
        $conf = $user->conf;
        $this->user = $user;
        self::$next_placeholder = 1;
        $this->_subgroups = [];
        foreach (func_get_args() as $i => $arg) {
            if ($i > 0 && $arg)
                expand_json_includes_callback($arg, [$this, "_add_json"]);
        }
        $sgs = [];
        foreach ($this->_subgroups as $name => $xtl) {
            if (($xt = $conf->xt_search_name($this->_subgroups, $name, $user))
                && Conf::xt_enabled($xt)
                && (!isset($xt->position) || $xt->position !== false)) {
                $sgs[$name] = $xt;
            }
        }
        uasort($sgs, function ($aj, $bj) use ($sgs) {
            if ($aj->group !== $bj->group) {
                if (isset($sgs[$aj->group]))
                    $aj = $sgs[$aj->group];
                if (isset($sgs[$bj->group]))
                    $bj = $sgs[$bj->group];
            }
            $aisg = $aj->group === $aj->name;
            $bisg = $bj->group === $bj->name;
            if ($aisg !== $bisg)
                return $aisg ? -1 : 1;
            else
                return Conf::xt_position_compare($aj, $bj);
        });
        $this->_subgroups = $sgs;
    }
    function get($name) {
        if (isset($this->_subgroups[$name]))
            return $this->_subgroups[$name];
        foreach ($this->_subgroups as $gj) {
            if (in_array($name, $gj->synonym))
                return $gj;
        }
        return null;
    }
    function canonical_group($name) {
        $gj = $this->get($name);
        return $gj ? $gj->group : false;
    }
    function members($name) {
        if ((string) $name === "")
            return $this->groups();
        if (($subgroup = str_ends_with($name, "/*")))
            $name = substr($name, 0, -2);
        if (($gj = $this->get($name)))
            $name = $gj->name;
        return array_filter($this->_subgroups, function ($gj) use ($name, $subgroup) {
            return $gj->name === $name ? !$subgroup : $gj->group === $name;
        });
    }
    function all() {
        return $this->_subgroups;
    }
    function groups() {
        return array_filter($this->_subgroups, function ($gj) {
            return $gj->name === $gj->group;
        });
    }

    private function call_callback($cb, $args) {
        if ($cb[0] === "*") {
            $colons = strpos($cb, ":");
            $klass = substr($cb, 1, $colons - 1);
            if (!$this->_render_classes
                || !isset($this->_render_classes[$klass]))
                $this->_render_classes[$klass] = new $klass(...$args);
            $cb = [$this->_render_classes[$klass], substr($cb, $colons + 2)];
        }
        return call_user_func_array($cb, $args);
    }

    function request($gj, Qrequest $qreq, $args) {
        if (isset($gj->request_callback)) {
            Conf::xt_resolve_require($gj);
            if (!isset($gj->allow_request_if)
                || $this->user->conf->xt_check($gj->allow_request_if, $gj, $this->user, $qreq))
                $this->call_callback($gj->request_callback, $args);
        }
    }

    function reset_render() {
        assert(!isset($this->_render_state));
        $this->_render_classes = null;
    }
    function start_render($heading_number = 3, $heading_class = null) {
        $this->_render_stack[] = $this->_render_state;
        $this->_render_state = [null, $heading_number, $heading_class];
    }
    function end_render() {
        assert(!empty($this->_render_stack));
        $this->_render_state = array_pop($this->_render_stack);
    }
    function render($gj, $args) {
        assert($this->_render_state !== null);
        if (isset($gj->title)
            && $gj->title !== $this->_render_state[0]
            && $gj->group !== $gj->name) {
            echo '<h', $this->_render_state[1];
            if ($this->_render_state[2])
                echo ' class="', $this->_render_state[2], '"';
            if (isset($gj->anchorid))
                echo ' id="', htmlspecialchars($gj->anchorid), '"';
            echo '>', $gj->title, "</h", $this->_render_state[1], ">\n";
            $this->_render_state[0] = $gj->title;
        }
        if (isset($gj->render_callback)) {
            Conf::xt_resolve_require($gj);
            $this->call_callback($gj->render_callback, $args);
        } else if (isset($gj->render_html))
            echo $gj->render_html;
    }

    function has_annex($name) {
        return isset($this->_annexes[$name]);
    }
    function annex($name) {
        $x = null;
        if (array_key_exists($name, $this->_annexes))
            $x = $this->_annexes[$name];
        return $x;
    }
    function set_annex($name, $x) {
        $this->_annexes[$name] = $x;
    }
}
