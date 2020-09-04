<?php
// src/groupedextensions.php -- HotCRP extensible groups
// Copyright (c) 2006-2020 Eddie Kohler; see LICENSE.

class GroupedExtensions implements XtContext {
    private $_jall = [];
    private $_potential_members = [];
    /** @var Conf */
    private $conf;
    /** @var Contact */
    private $viewer;
    public $root;
    private $_raw = [];
    private $_callables;
    /** @var array{?list<mixed>,?string,?string,?string} */
    private $_render_state;
    private $_render_stack;
    private $_annexes = [];
    /** @var list<callable(string,object,?Contact,Conf):(?bool)> */
    private $_xt_checkers = [];
    static private $next_placeholder;

    function add($fj) {
        if (is_array($fj)) {
            $fja = $fj;
            if (count($fja) < 3 || !is_string($fja[0])) {
                return false;
            }
            $fj = (object) [
                "name" => $fja[0], "position" => $fja[1],
                "__subposition" => ++Conf::$next_xt_subposition
            ];
            if (strpos($fja[2], "::")) {
                $fj->render_callback = $fja[2];
            } else {
                $fj->alias = $fja[2];
            }
            if (isset($fja[3]) && is_number($fja[3])) {
                $fj->priority = $fja[3];
            }
        }
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
        if (!isset($fj->anchorid)
            && !str_starts_with($fj->name, "__")
            && ($pos = strpos($fj->name, "/")) !== false) {
            $x = substr($fj->name, $pos + 1);
            $fj->anchorid = preg_replace('/\A[^A-Za-z]+|[^A-Za-z0-9_:.]+/', "-", strtolower($x));
        }
        $this->_jall[$fj->name][] = $fj;
        if ($fj->group === $fj->name) {
            assert(strpos($fj->group, "/") === false);
            $this->_potential_members[""][] = $fj->name;
        } else {
            $this->_potential_members[$fj->group][] = $fj->name;
        }
        if (!empty($this->_raw)) {
            $this->_raw = [];
        }
        return true;
    }

    function __construct(Contact $viewer, ...$args) {
        $this->conf = $viewer->conf;
        $this->viewer = $viewer;
        self::$next_placeholder = 1;
        foreach ($args as $arg) {
            if ($arg)
                expand_json_includes_callback($arg, [$this, "add"]);
        }
        $this->reset_context();
    }
    function reset_context() {
        assert(empty($this->_render_stack));
        $this->root = null;
        $this->_raw = [];
        $this->_callables = ["Conf" => $this->conf];
        $this->_render_state = [null, null, "h3", null];
    }
    /** @return Contact */
    function viewer() {
        return $this->viewer;
    }
    /** @return ?string */
    function root() {
        return $this->root;
    }

    /** @param callable(string,object,?Contact,Conf):(?bool) $checker */
    function add_xt_checker($checker) {
        $this->_xt_checkers[] = $checker;
    }
    function xt_check_element($str, $xt, $user, Conf $conf) {
        foreach ($this->_xt_checkers as $cf) {
            if (($x = $cf($str, $xt, $user, $conf)) !== null)
                return $x;
        }
        return null;
    }

    /** @param string $key */
    function filter_by($key) {
        $old_context = $this->conf->xt_swap_context($this);
        foreach ($this->_jall as &$jl) {
            for ($i = 0; $i !== count($jl); ) {
                if (isset($jl[$i]->$key)
                    && !$this->conf->xt_check($jl[$i]->$key, $jl[$i], $this->viewer)) {
                    array_splice($jl, $i, 1);
                } else {
                    ++$i;
                }
            }
        }
        $this->_raw = [];
        $this->conf->xt_context = $old_context;
    }

    function get_raw($name) {
        if (!array_key_exists($name, $this->_raw)) {
            $old_context = $this->conf->xt_swap_context($this);
            if (($xt = $this->conf->xt_search_name($this->_jall, $name, $this->viewer, null, true))
                && Conf::xt_enabled($xt)) {
                $this->_raw[$name] = $xt;
            } else {
                $this->_raw[$name] = null;
            }
            $this->conf->xt_context = $old_context;
        }
        return $this->_raw[$name];
    }
    function get($name) {
        $gj = $this->get_raw($name);
        for ($nalias = 0; $gj && isset($gj->alias) && $nalias < 5; ++$nalias) {
            $gj = $this->get_raw($gj->alias);
        }
        return $gj;
    }
    /** @return string|false */
    function canonical_group($name) {
        if (($gj = $this->get($name))) {
            $pos = strpos($gj->group, "/");
            return $pos === false ? $gj->group : substr($gj->group, 0, $pos);
        } else {
            return false;
        }
    }
    /** @return list<object> */
    function members($name, $require_key = false) {
        if (($gj = $this->get($name))) {
            $name = $gj->name;
        }
        $r = [];
        $alias = false;
        foreach (array_unique($this->_potential_members[$name] ?? []) as $subname) {
            if (($gj = $this->get_raw($subname))
                && $gj->group === ($name === "" ? $gj->name : $name)
                && $gj->name !== $name
                && (!$require_key || isset($gj->$require_key))
                && (!isset($gj->alias) || isset($gj->position))
                && (!isset($gj->position) || $gj->position !== false)) {
                $r[] = $gj;
                $alias = $alias || isset($gj->alias);
            }
        }
        usort($r, "Conf::xt_position_compare");
        if ($alias && !empty($r)) {
            $rr = [];
            foreach ($r as $gj) {
                $rr[] = isset($gj->alias) ? $this->get($gj->alias) : $gj;
            }
            return $rr;
        } else {
            return $r;
        }
    }
    /** @return list<object> */
    function groups() {
        return $this->members("");
    }

    function allowed($allowed, $gj) {
        if (isset($allowed)) {
            $old_context = $this->conf->xt_swap_context($this);
            $ok = $this->conf->xt_check($allowed, $gj, $this->viewer);
            $this->conf->xt_context = $old_context;
            return $ok;
        } else {
            return true;
        }
    }
    function callable($name) {
        if (!isset($this->_callables[$name])
            && ($args = $this->_render_state[0]) !== null) {
            /** @phan-suppress-next-line PhanTypeExpectedObjectOrClassName */
            $this->_callables[$name] = new $name(...$args);
        }
        return $this->_callables[$name] ?? null;
    }
    function set_callable($name, $callable) {
        assert(!isset($this->_callables[$name]));
        $this->_callables[$name] = $callable;
    }
    function call_callback($cb, $gj) {
        Conf::xt_resolve_require($gj);
        if (is_string($cb) && $cb[0] === "*") {
            $colons = strpos($cb, ":");
            $cb = [$this->callable(substr($cb, 1, $colons - 1)), substr($cb, $colons + 2)];
        }
        return $cb(...$this->_render_state[0], ...[$gj]);
    }

    function set_context($options) {
        if (isset($options["root"]))  {
            assert(is_string($options["root"]));
            $this->root = $options["root"];
        }
        if (isset($options["args"])) {
            assert(is_array($options["args"]));
            $this->_render_state[0] = $options["args"];
        }
        if (isset($options["htag"])) {
            assert(is_string($options["htag"]));
            $this->_render_state[2] = $options["htag"];
        }
        if (isset($options["hclass"])) {
            assert(is_string($options["hclass"]));
            $this->_render_state[3] = $options["hclass"];
        }
    }
    function args() {
        return $this->_render_state[0];
    }
    function arg($i) {
        return $this->_render_state[0][$i] ?? null;
    }

    function start_render($options = null) {
        $this->_render_stack[] = $this->_render_state;
        $this->_render_state = array_slice($this->_render_state, 0, 4);
        if (!empty($options)) {
            $this->set_context($options);
        }
    }
    function push_render_cleanup($cleaner) {
        assert(!empty($this->_render_stack));
        $this->_render_state[] = $cleaner;
    }
    function end_render() {
        assert(!empty($this->_render_stack));
        for ($i = count($this->_render_state) - 1; $i > 3; --$i) {
            $cleaner = $this->_render_state[$i];
            if (is_string($cleaner) && ($gj = $this->get($cleaner))) {
                $this->render($gj);
            } else if (is_callable($cleaner)) {
                $this->call_callback($cleaner, null);
            }
        }
        $this->_render_state = array_pop($this->_render_stack);
    }
    function render($gj) {
        if (is_string($gj)) {
            if (!($gj = $this->get($gj))) {
                return null;
            }
        }
        if (isset($gj->title)
            && $gj->title !== $this->_render_state[1]
            && $gj->group !== $gj->name) {
            echo '<', $this->_render_state[2];
            if ($this->_render_state[3]) {
                echo ' class="', $this->_render_state[3], '"';
            }
            if (isset($gj->anchorid)) {
                echo ' id="', htmlspecialchars($gj->anchorid), '"';
            }
            echo '>', $gj->title, '</', $this->_render_state[2], ">\n";
            $this->_render_state[1] = $gj->title;
        }
        if (isset($gj->render_callback)) {
            return $this->call_callback($gj->render_callback, $gj);
        } else if (isset($gj->render_html)) {
            echo $gj->render_html;
            return null;
        } else {
            return null;
        }
    }
    function render_group($name, $options = null) {
        $this->start_render($options);
        $result = null;
        if (!empty($options) && isset($options["top"]) && $options["top"]) {
            if (($gj = $this->get($name))) {
                $result = $this->render($gj);
            }
        }
        foreach ($this->members($name) as $gj) {
            if ($result !== false) {
                $result = $this->render($gj);
            }
        }
        $this->end_render();
        return $result;
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
