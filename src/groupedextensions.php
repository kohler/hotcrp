<?php
// src/groupedextensions.php -- HotCRP extensible groups
// Copyright (c) 2006-2019 Eddie Kohler; see LICENSE.

class GroupedExtensions {
    private $user;
    private $_groups;
    private $_all;
    private $_is_group;
    private $_render_state;
    private $_render_stack;
    private $_render_classes;
    private $_synonym_subgroups = [];
    private $_annexes = [];
    static private $next_placeholder;

    function _add_json($fj) {
        if (!isset($fj->name)) {
            $fj->name = "__" . self::$next_placeholder . "__";
            ++self::$next_placeholder;
        }
        if (!isset($fj->group)) {
            if (($pos = strrpos($fj->name, "/")) !== false) {
                $fj->group = substr($fj->name, 0, $pos);
            } else {
                $fj->group = $fj->name;
            }
        }
        if (!isset($fj->synonym)) {
            $fj->synonym = [];
        } else if (is_string($fj->synonym)) {
            $fj->synonym = [$fj->synonym];
        }
        if (!isset($fj->anchorid)
            && !str_starts_with($fj->name, "__")
            && ($pos = strpos($fj->name, "/")) !== false) {
            $x = substr($fj->name, $pos + 1);
            $fj->anchorid = preg_replace('/\A[^A-Za-z]+|[^A-Za-z0-9_:.]+/', "-", strtolower($x));
        }
        $this->_all[$fj->name][] = $fj;
        return true;
    }
    function __construct(Contact $user, $args /* ... */) {
        $conf = $user->conf;
        $this->user = $user;
        self::$next_placeholder = 1;
        // read all arguments; produce _all: name => array
        $this->_all = [];
        foreach (func_get_args() as $i => $arg) {
            if ($i > 0 && $arg)
                expand_json_includes_callback($arg, [$this, "_add_json"]);
        }
        // reduce _all to one entry per name, produce _groups
        $sgs = $this->_all;
        $this->_all = $this->_groups = [];
        foreach ($sgs as $name => $xtl) {
            if (($xt = $conf->xt_search_name($sgs, $name, $user))
                && Conf::xt_enabled($xt)
                && (!isset($xt->position) || $xt->position !== false)) {
                $this->_all[$name] = $xt;
                if ($xt->name === $xt->group && !isset($xt->alias)) {
                    $this->_groups[$name] = $xt;
                }
                if (!empty($xt->synonym)) {
                    $this->_synonym_subgroups[] = $xt;
                }
            }
        }
        $this->reset_render();
    }
    function subgroup_compare($aj, $bj) {
        if ($aj->group !== $bj->group) {
            if (isset($this->_groups[$aj->group])) {
                $aj = $this->_groups[$aj->group];
            }
            if (isset($this->_groups[$bj->group])) {
                $bj = $this->_groups[$bj->group];
            }
        }
        $aisg = $aj->group === $aj->name;
        $bisg = $bj->group === $bj->name;
        if ($aisg !== $bisg) {
            return $aisg ? -1 : 1;
        } else {
            return Conf::xt_position_compare($aj, $bj);
        }
    }
    function is_group($name) {
        if ($this->_is_group === null) {
            $this->_is_group = [];
            foreach ($this->_all as $gj) {
                $this->_is_group[$gj->group] = true;
            }
        }
        return isset($this->_is_group[$name]);
    }
    function get($name) {
        if (isset($this->_all[$name])) {
            return $this->_all[$name];
        }
        foreach ($this->_synonym_subgroups as $gj) {
            if (in_array($name, $gj->synonym))
                return $gj;
        }
        return null;
    }
    function canonical_group($name) {
        if (($gj = $this->get($name))) {
            return $gj->group;
        } else if ($this->is_group($name)) {
            return $name;
        } else {
            return false;
        }
    }
    function members($name) {
        if ((string) $name === "") {
            return $this->groups();
        }
        if (($gj = $this->get($name))) {
            $name = $gj->name;
        }
        $r = [];
        foreach ($this->_all as $gj) {
            if ($gj->group === $name && $gj->name !== $name) {
                while (isset($gj->alias) && ($xgj = $this->get($gj->alias))) {
                    $gj = $xgj;
                }
                $r[] = $gj;
            }
        }
        usort($r, [$this, "subgroup_compare"]);
        return $r;
    }
    function all() {
        uasort($this->_all, [$this, "subgroup_compare"]);
        return $this->_all;
    }
    function groups() {
        uasort($this->_groups, "Conf::xt_position_compare");
        return $this->_groups;
    }

    private function call_callback($cb, $args) {
        if ($cb[0] === "*") {
            $colons = strpos($cb, ":");
            $klass = substr($cb, 1, $colons - 1);
            if (!isset($this->_render_classes[$klass])) {
                $this->_render_classes[$klass] = new $klass(...$args);
            }
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
        $this->_render_classes = ["Conf" => $this->user->conf];
    }
    function start_render($heading_number = 3, $heading_class = null) {
        $this->_render_stack[] = $this->_render_state;
        $this->_render_state = [null, $heading_number, $heading_class];
    }
    function push_render_cleanup($name) {
        assert(isset($this->_render_state));
        $this->_render_state[] = $name;
    }
    function end_render() {
        assert(!empty($this->_render_stack));
        for ($i = count($this->_render_state) - 1; $i > 2; --$i) {
            if (($gj = $this->get($this->_render_state[$i])))
                $this->render($gj, [$this]);
        }
        $this->_render_state = array_pop($this->_render_stack);
    }
    function render($gj, $args) {
        assert($this->_render_state !== null);
        if (isset($gj->title)
            && $gj->title !== $this->_render_state[0]
            && $gj->group !== $gj->name) {
            echo '<h', $this->_render_state[1];
            if ($this->_render_state[2]) {
                echo ' class="', $this->_render_state[2], '"';
            }
            if (isset($gj->anchorid)) {
                echo ' id="', htmlspecialchars($gj->anchorid), '"';
            }
            echo '>', $gj->title, "</h", $this->_render_state[1], ">\n";
            $this->_render_state[0] = $gj->title;
        }
        if (isset($gj->render_callback)) {
            Conf::xt_resolve_require($gj);
            return $this->call_callback($gj->render_callback, $args);
        } else if (isset($gj->render_html)) {
            echo $gj->render_html;
        }
    }

    function has_annex($name) {
        return isset($this->_annexes[$name]);
    }
    function annex($name) {
        $x = null;
        if (array_key_exists($name, $this->_annexes)) {
            $x = $this->_annexes[$name];
        }
        return $x;
    }
    function set_annex($name, $x) {
        $this->_annexes[$name] = $x;
    }
}
