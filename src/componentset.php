<?php
// componentset.php -- HotCRP JSON-based component specifications
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class ComponentContext {
    /** @var list<mixed> */
    public $args = [];
    /** @var ?list<callable> */
    public $cleanup;
}

class ComponentSet {
    private $_jall = [];
    /** @var array<string,list<string>> */
    private $_potential_members = [];
    /** @var Conf
     * @readonly */
    public $conf;
    /** @var Contact
     * @readonly */
    public $viewer;
    /** @var XtParams
     * @readonly */
    public $xtp;
    public $root;
    private $_raw = [];
    private $_callables;
    /** @var string */
    private $_section_class = "";
    /** @var string */
    private $_next_section_class = "";
    /** @var string */
    private $_title_class = "";
    /** @var string */
    private $_separator = "";
    /** @var bool */
    private $_need_separator = false;
    /** @var ?string */
    private $_separator_group;
    /** @var ?string */
    private $_section_closer;
    /** @var ComponentContext */
    private $_ctx;
    /** @var list<ComponentContext> */
    private $_ctxstack;
    /** @var list<callable(object):(?bool)> */
    private $_print_callbacks = [];
    private $_annexes = [];

    static private $next_placeholder;

    function add($fj) {
        if (is_array($fj)) {
            $fja = $fj;
            if (count($fja) < 3 || !is_string($fja[0])) {
                return false;
            }
            $fj = (object) [
                "name" => $fja[0], "order" => $fja[1],
                "__source_order" => ++Conf::$next_xt_source_order
            ];
            if (strpos($fja[2], "::")) {
                $fj->print_function = $fja[2];
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
        $this->xtp = new XtParams($this->conf, $viewer);
        $this->xtp->component_set = $this;
        self::$next_placeholder = 1;
        foreach ($args as $arg) {
            expand_json_includes_callback($arg, [$this, "add"]);
        }
        $this->_ctx = new ComponentContext;
        $this->reset_context();
    }

    function reset_context() {
        assert(empty($this->_ctxstack) && empty($this->_ctx->cleanup));
        $this->root = null;
        $this->_raw = [];
        $this->_callables = ["Conf" => $this->conf];
        $this->_next_section_class = $this->_section_class;
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


    /** @param callable(string,object,XtParams):(?bool) $checker
     * @return $this */
    function add_xt_checker($checker) {
        $this->xtp->primitive_checkers[] = $checker;
        return $this;
    }

    /** @param callable(object):(?bool) $f
     * @return $this */
    function add_print_callback($f) {
        $this->_print_callbacks[] = $f;
        return $this;
    }


    /** @param callable(object,ComponentSet):bool $f */
    function apply_filter($f) {
        foreach ($this->_jall as &$jl) {
            $n = count($jl);
            for ($i = 0; $i !== $n; ) {
                if ($f($jl[$i], $this)) {
                    ++$i;
                } else {
                    array_splice($jl, $i, 1);
                    --$n;
                }
            }
        }
        $this->_raw = [];
    }

    /** @param string $key */
    function apply_key_filter($key) {
        $this->apply_filter(function ($jx, $gex) use ($key) {
            return !isset($jx->$key) || $this->xtp->check($jx->$key, $jx);
        });
    }


    /** @param string $name
     * @return ?object */
    function get_raw($name) {
        if (!array_key_exists($name, $this->_raw)) {
            if (($xt = $this->xtp->search_list($this->_jall[$name] ?? []))
                && Conf::xt_enabled($xt)) {
                $this->_raw[$name] = $xt;
            } else {
                $this->_raw[$name] = null;
            }
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

    /** @param string|object $x
     * @return ?string */
    function canonical_group($x) {
        $gj = is_string($x) ? $this->get($x) : $x;
        if ($gj) {
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
        if (!isset($this->_potential_members[$name])
            && ($xj = $this->get($name))) {
            $name = $xj->name;
        }
        $r = [];
        $alias = false;
        foreach (array_unique($this->_potential_members[$name] ?? []) as $subname) {
            if (($gj = $this->get_raw($subname))
                && $gj->group === ($name === "" ? $gj->name : $name)
                && $gj->name !== $name
                && (!isset($gj->alias) || isset($gj->order))
                && (!isset($gj->order) || $gj->order !== false)
                && (!$require_key || isset($gj->alias) || isset($gj->$require_key))) {
                $r[] = $gj;
                $alias = $alias || isset($gj->alias);
            }
        }
        usort($r, "Conf::xt_order_compare");
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


    /** @return bool */
    function allowed($allowed, $gj) {
        return $allowed === null || $this->xtp->check($allowed, $gj);
    }

    /** @template T
     * @param class-string<T> $name
     * @return ?T */
    function callable($name) {
        if (!isset($this->_callables[$name])) {
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

    /** @param ?object $gj
     * @param callable $cb */
    function call_function($gj, $cb, ...$args) {
        Conf::xt_resolve_require($gj);
        if (is_string($cb) && $cb[0] === "*") {
            $colons = strpos($cb, ":");
            $cb = [$this->callable(substr($cb, 1, $colons - 1)), substr($cb, $colons + 2)];
        }
        return $cb(...$this->_ctx->args, ...$args);
    }


    /** @param ?string $root
     * @return $this */
    function set_root($root) {
        $this->root = $root;
        return $this;
    }

    /** @param string $s
     * @return $this */
    function set_section_class($s) {
        $this->_section_class = $this->_next_section_class = $s;
        return $this;
    }

    /** @param string $s
     * @return $this */
    function set_title_class($s) {
        $this->_title_class = $s;
        return $this;
    }

    /** @param string $separator
     * @return $this */
    function set_separator($separator) {
        $this->_separator = $separator;
        $this->_need_separator = false;
        $this->_separator_group = null;
        return $this;
    }

    /** @param string $separator
     * @return string */
    function swap_separator($separator) {
        $old_separator = $this->_separator;
        $this->_separator = $separator;
        $this->_need_separator = false;
        $this->_separator_group = null;
        return $old_separator;
    }

    /** @param mixed ...$args
     * @return $this */
    function set_context_args(...$args) {
        $this->_ctx->args = $args;
        return $this;
    }

    /** @return list<mixed> */
    function args() {
        return $this->_ctx->args;
    }

    /** @param int $i
     * @return mixed */
    function arg($i) {
        return $this->_ctx->args[$i] ?? null;
    }


    private function start_print() {
        $this->_ctxstack[] = $this->_ctx;
        $this->_ctx = clone $this->_ctx;
        $this->_ctx->cleanup = null;
    }

    private function end_print() {
        assert(!empty($this->_ctxstack));
        $cleanup = $this->_ctx->cleanup ?? [];
        for ($i = count($cleanup) - 1; $i >= 0; --$i) {
            $cleaner = $cleanup[$i];
            if (is_string($cleaner) && ($gj = $this->get($cleaner))) {
                $this->print($gj);
            } else if (is_callable($cleaner)) {
                $this->call_function(null, $cleaner);
            }
        }
        $this->_ctx = array_pop($this->_ctxstack);
    }

    function push_print_cleanup($cleaner) {
        assert(!empty($this->_ctxstack));
        $this->_ctx->cleanup[] = $cleaner;
    }

    /** @param string $classes
     * @return $this */
    function add_section_class($classes) {
        $this->_next_section_class = Ht::add_tokens($this->_next_section_class, $classes);
        return $this;
    }

    /** @param ?string $title
     * @param ?string $hashid */
    function print_start_section($title = null, $hashid = null) {
        $title = $title ?? "";
        $hashid_notitle = $title === "" && (string) $hashid !== "";
        $this->print_end_section();
        $this->trigger_separator();
        if ($this->_next_section_class !== "" || $hashid_notitle) {
            echo '<div';
            if ($this->_next_section_class !== "") {
                echo " class=\"", $this->_next_section_class, "\"";
            }
            $this->_next_section_class = $this->_section_class;
            if ($hashid_notitle) {
                echo " id=\"", htmlspecialchars($hashid), "\"";
            }
            echo '>';
            $this->_section_closer = "</div>";
        }
        if ($title !== "") {
            $this->print_title($title, $hashid);
        }
    }

    /** @param string $html */
    function push_end_section($html) {
        $this->_section_closer = $html . ($this->_section_closer ?? "");
    }

    function print_end_section() {
        if ($this->_section_closer !== null) {
            echo $this->_section_closer;
            $this->_section_closer = null;
        }
    }

    /** @param string $ftext
     * @param ?string $hashid */
    function print_title($ftext, $hashid = null) {
        echo '<h3';
        if ($this->_title_class) {
            echo ' class="', $this->_title_class, '"';
        }
        $hashid = $hashid ?? self::title_hashid($ftext);
        if ((string) $hashid !== "") {
            echo ' id="', htmlspecialchars($hashid), '"';
        }
        echo '>', Ftext::as(5, $ftext, 0), "</h3>\n";
    }

    /** @param string $ftext
     * @return ?string */
    static function title_hashid($ftext) {
        $hashid = preg_replace('/\A[^A-Za-z]+|[^A-Za-z0-9:.]+/', "-", Ftext::as(0, strtolower($ftext)));
        if (str_starts_with($hashid, "-")) {
            $hashid = substr($hashid, 1);
        }
        return $hashid !== "" ? $hashid : null;
    }

    /** @param string|object $gj
     * @return ?string */
    function hashid($gj) {
        if (is_string($gj)) {
            $gj = $this->get($gj);
        }
        if (!$gj) {
            return null;
        } else if (($gj->hashid ?? null) !== null) {
            return $gj->hashid !== "" ? $gj->hashid : null;
        } else if (($gj->title ?? "") !== "") {
            return self::title_hashid($gj->title);
        } else {
            return null;
        }
    }

    /** @param string|object $gj */
    function print($gj) {
        if (is_string($gj)) {
            $gj = $this->get($gj);
        }
        if (!$gj) {
            return null;
        }

        $sepgroup = $gj->separator_group ?? null;
        if ($sepgroup !== null
            && $this->_separator_group !== null
            && $this->_separator_group !== $sepgroup) {
            $this->mark_separator();
            $this->trigger_separator();
        } else if ($gj->separator_before ?? false) {
            $this->mark_separator();
        }

        $title = ($gj->print_title ?? true) ? $gj->title ?? "" : "";
        $hashid = $gj->hashid ?? null;
        if ($title !== ""
            || ($this->_section_closer === null && $this->_next_section_class !== "")
            || (string) $hashid !== "") {
            // create default hashid from title
            $this->print_start_section($title, $hashid);
        } else {
            $this->trigger_separator();
        }

        if ($sepgroup !== null) {
            $this->_separator_group = $sepgroup;
        }
        return $this->_print_body($gj, false);
    }

    /** @param object $gj
     * @param bool $print_members
     * @return mixed */
    private function _print_body($gj, $print_members) {
        foreach ($this->_print_callbacks as $f) {
            if ($f($gj) === false)
                return false;
        }
        $result = null;
        if (isset($gj->print_function)) {
            $result = $this->call_function($gj, $gj->print_function, $gj);
        } else if (isset($gj->html_content)) {
            echo $gj->html_content;
        }
        if (isset($gj->print_members)) {
            $print_members = $gj->print_members;
        }
        if ($result !== false && $print_members) {
            $result = $this->print_members($print_members === true ? $gj->name : $print_members);
        }
        if ($gj->separator_after ?? false) {
            $this->mark_separator();
        }
        return $result;
    }

    /** @param string $name
     * @param bool $top
     * @return mixed
     * @deprecated */
    function print_group($name, $top = false) {
        return $top ? $this->print_body_members($name) : $this->print_members($name);
    }

    /** @param string $name
     * @return mixed */
    function print_body_members($name) {
        if (($gj = $this->get($name))) {
            $this->start_print();
            $result = $this->_print_body($gj, true);
            $this->end_print();
        } else {
            $result = $this->print_members($name);
        }
        $this->print_end_section();
        return $result;
    }

    /** @param string $name
     * @return mixed */
    function print_members($name) {
        $this->start_print();
        $result = null;
        foreach ($this->members($name) as $gj) {
            if (($result = $this->print($gj)) === false) {
                break;
            }
        }
        $this->end_print();
        return $result;
    }

    function mark_separator() {
        $this->_need_separator = true;
    }

    function trigger_separator() {
        if ($this->_need_separator) {
            echo $this->_separator;
        }
        $this->_need_separator = false;
        $this->_separator_group = null;
    }

    /** @param string $name
     * @return bool */
    function has_annex($name) {
        return isset($this->_annexes[$name]);
    }

    /** @param string $name
     * @return mixed */
    function annex($name) {
        $x = null;
        if (array_key_exists($name, $this->_annexes)) {
            $x = $this->_annexes[$name];
        }
        return $x;
    }

    /** @param string $name
     * @param mixed $x */
    function set_annex($name, $x) {
        $this->_annexes[$name] = $x;
    }
}
