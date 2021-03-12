<?php
// src/groupedextensions.php -- HotCRP extensible groups
// Copyright (c) 2006-2021 Eddie Kohler; see LICENSE.

class GroupedExtensionsContext {
    /** @var ?list<mixed> */
    public $args;
    /** @var ?list<callable> */
    public $cleanup;
}

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
    /** @var string|false */
    private $_section_class = "";
    /** @var string|false */
    private $_title_class = "";
    /** @var bool */
    private $_in_section = false;
    /** @var ?string */
    private $_section_closer;
    /** @var GroupedExtensionsContext */
    private $_ctx;
    /** @var list<GroupedExtensionsContext> */
    private $_ctxstack;
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
                $fj->render_function = $fja[2];
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
        if (!isset($fj->hashid)
            && !str_starts_with($fj->name, "__")
            && ($pos = strpos($fj->name, "/")) !== false) {
            $x = substr($fj->name, $pos + 1);
            $fj->hashid = preg_replace('/\A[^A-Za-z]+|[^A-Za-z0-9_:.]+/', "-", strtolower($x));
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
        $this->_ctx = new GroupedExtensionsContext;
        $this->reset_context();
    }
    function reset_context() {
        assert(empty($this->_ctxstack) && empty($this->_ctx->cleanup));
        $this->root = null;
        $this->_raw = [];
        $this->_callables = ["Conf" => $this->conf];
        $this->_in_section = false;
        $this->_section_closer = null;
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

    /** @param string $name
     * @return ?object */
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
    /** @param string $name
     * @return ?object */
    function get($name) {
        $gj = $this->get_raw($name);
        for ($nalias = 0; $gj && isset($gj->alias) && $nalias < 5; ++$nalias) {
            $gj = $this->get_raw($gj->alias);
        }
        return $gj;
    }
    /** @param string $name
     * @return ?string */
    function canonical_group($name) {
        if (($gj = $this->get($name))) {
            $pos = strpos($gj->group, "/");
            return $pos === false ? $gj->group : substr($gj->group, 0, $pos);
        } else {
            return null;
        }
    }
    /** @param string $name
     * @param ?string $require_key
     * @return list<object> */
    function members($name, $require_key = null) {
        if (($gj = $this->get($name))) {
            $name = $gj->name;
        }
        $r = [];
        $alias = false;
        foreach (array_unique($this->_potential_members[$name] ?? []) as $subname) {
            if (($gj = $this->get_raw($subname))
                && $gj->group === ($name === "" ? $gj->name : $name)
                && $gj->name !== $name
                && (!isset($gj->alias) || isset($gj->position))
                && (!isset($gj->position) || $gj->position !== false)
                && (!$require_key || isset($gj->alias) || isset($gj->$require_key))) {
                $r[] = $gj;
                $alias = $alias || isset($gj->alias);
            }
        }
        usort($r, "Conf::xt_position_compare");
        if ($alias && !empty($r)) {
            $rr = [];
            foreach ($r as $gj) {
                if (!isset($gj->alias)
                    || (($gj = $this->get($gj->alias))
                        && (!$require_key || isset($gj->$require_key)))) {
                    $rr[] = $gj;
                }
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
        if (!isset($this->_callables[$name]) && $this->_ctx->args !== null) {
            /** @phan-suppress-next-line PhanTypeExpectedObjectOrClassName */
            $this->_callables[$name] = new $name(...$this->_ctx->args);
        }
        return $this->_callables[$name] ?? null;
    }
    /** @param string $name
     * @param callable|mixed $callable
     * @return $this */
    function set_callable($name, $callable) {
        assert(!isset($this->_callables[$name]));
        $this->_callables[$name] = $callable;
        return $this;
    }
    function call_function($cb, $gj) {
        Conf::xt_resolve_require($gj);
        if (is_string($cb) && $cb[0] === "*") {
            $colons = strpos($cb, ":");
            $cb = [$this->callable(substr($cb, 1, $colons - 1)), substr($cb, $colons + 2)];
        }
        return $cb(...$this->_ctx->args, ...[$gj]);
    }
    /** @deprecated */
    function call_callback($cb, $gj) {
        return $this->call_function($cb, $gj);
    }

    /** @param ?string $root
     * @return $this */
    function set_root($root) {
        $this->root = $root;
        return $this;
    }
    /** @param string|false $s
     * @return $this */
    function set_section_class($s) {
        $this->_section_class = $s;
        return $this;
    }
    /** @param string|false $s
     * @return $this */
    function set_title_class($s) {
        $this->_title_class = $s;
        return $this;
    }
    /** @param list<mixed> $args
     * @return $this */
    function set_context_args($args) {
        $this->_ctx->args = $args;
        return $this;
    }
    /** @deprecated */
    function set_context($options) {
        if (isset($options["root"]))  {
            assert(is_string($options["root"]));
            $this->root = $options["root"];
        }
        if (isset($options["args"])) {
            assert(is_array($options["args"]));
            $this->_ctx->args = $options["args"];
        }
        if (isset($options["hclass"])) {
            assert(is_string($options["hclass"]));
            $this->_title_class = $options["hclass"];
        }
    }
    /** @return list<mixed> */
    function args() {
        return $this->_ctx->args;
    }
    function arg($i) {
        return $this->_ctx->args[$i] ?? null;
    }

    function start_render() {
        $this->_ctxstack[] = $this->_ctx;
        $this->_ctx = clone $this->_ctx;
        $this->_ctx->cleanup = null;
    }
    function push_render_cleanup($cleaner) {
        assert(!empty($this->_ctxstack));
        $this->_ctx->cleanup[] = $cleaner;
    }
    function end_render() {
        assert(!empty($this->_ctxstack));
        $cleanup = $this->_ctx->cleanup ?? [];
        for ($i = count($cleanup) - 1; $i >= 0; --$i) {
            $cleaner = $cleanup[$i];
            if (is_string($cleaner) && ($gj = $this->get($cleaner))) {
                $this->render($gj);
            } else if (is_callable($cleaner)) {
                $this->call_function($cleaner, null);
            }
        }
        $this->_ctx = array_pop($this->_ctxstack);
    }

    /** @param ?string $classes
     * @param ?string $id */
    function render_open_section($classes = null, $id = null) {
        $this->render_close_section();
        if ($this->_section_class !== false
            && ($this->_section_class !== "" || ($classes ?? "") !== "")) {
            $klasses = trim("{$this->_section_class} " . ($classes ?? ""));
            if ($klasses !== "" || ($id ?? "") !== "") {
                echo '<div';
                if ($klasses !== "") {
                    echo " class=\"{$klasses}\"";
                }
                if (($id ?? "") !== "") {
                    echo " id=\"", htmlspecialchars($id), "\"";
                }
                echo '>';
                $this->_section_closer = "</div>";
            }
            $this->_in_section = true;
        }
    }
    function push_close_section($html) {
        $this->_section_closer = $html . ($this->_section_closer ?? "");
    }
    function render_close_section() {
        if ($this->_in_section) {
            echo $this->_section_closer ?? "";
            $this->_section_closer = null;
            $this->_in_section = false;
        }
    }
    /** @param string $title
     * @param ?string $hashid */
    function render_title($title, $hashid = null) {
        echo '<h3';
        if ($this->_title_class) {
            echo ' class="', $this->_title_class, '"';
        }
        if ($hashid) {
            echo ' id="', htmlspecialchars($hashid), '"';
        }
        echo '>', $title, "</h3>\n";
    }
    /** @param string $title
     * @param ?string $hashid */
    function render_section($title, $hashid = null) {
        $this->render_open_section();
        if ($this->_title_class !== false && ($title !== "" && $title !== false)) {
            $this->render_title($title, $hashid);
        }
    }

    /** @param string|object $gj */
    function render($gj) {
        if (is_string($gj) && !($gj = $this->get($gj))) {
            return null;
        }
        if (($gj->section ?? null) !== false
            && $this->_section_class !== false
            && (isset($gj->title) || !$this->_in_section)
            && $gj->group !== $gj->name) {
            $this->render_section($gj->title ?? "", $gj->hashid ?? null);
        }
        if (isset($gj->render_function)) {
            return $this->call_function($gj->render_function, $gj);
        } else if (isset($gj->render_callback)) { /* XXX */
            return $this->call_function($gj->render_callback, $gj);
        } else if (isset($gj->render_html)) {
            echo $gj->render_html;
            return null;
        } else {
            return null;
        }
    }
    /** @param string $name
     * @param bool $top */
    function render_group($name, $top = false) {
        $this->start_render();
        $result = null;
        if ($top && ($gj = $this->get($name))) {
            $result = $this->render($gj);
        }
        foreach ($this->members($name) as $gj) {
            if ($result !== false) {
                $result = $this->render($gj);
            }
        }
        $this->end_render();
        $top && $this->render_close_section();
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
