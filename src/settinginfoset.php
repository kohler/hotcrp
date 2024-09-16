<?php
// settinginfoset.php -- HotCRP conference settings set class
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class SettingInfoSet {
    /** @var ComponentSet
     * @readonly */
    private $cs;
    /** @var array<string,Si> */
    private $map = [];
    /** @var array<string,list<object>> */
    private $xmap = [];
    /** @var bool */
    private $xmap_sorted = false;
    /** @var list<string|list<string|object>> */
    private $xlist = [];
        // => [firstpart, [lastpart, object, ...], firstpart, ...]
    /** @var list<string> */
    private $potential_aliases = [];
    /** @var bool */
    private $has_descriptions = false;

    function __construct(ComponentSet $cs, ...$args) {
        $this->cs = $cs;
        foreach ($args as $arg) {
            expand_json_includes_callback($arg, [$this, "_add_item"]);
        }
        foreach ($this->cs->members("") as $gj) {
            $this->_assign_pages($gj, [$gj->group], true);
        }
    }

    /** @return SettingInfoSet */
    static function make_conf(Conf $conf) {
        $cs = new ComponentSet($conf->root_user(), ["etc/settinggroups.json"], $conf->opt("settingGroups"));
        return new SettingInfoSet($cs, ["etc/settinginfo.json"], $conf->opt("settingInfo"));
    }

    function _add_item($xt, $k, $landmark) {
        if (isset($xt->name_pattern)) {
            $parts = [];
            $pos = 0;
            while (($pos1 = strpos($xt->name_pattern, '$', $pos)) !== false) {
                $pos2 = $pos1 + strspn($xt->name_pattern, '$', $pos1);
                assert($pos2 - $pos1 === count($parts) / 2 + 1);
                $parts[] = substr($xt->name_pattern, $pos, $pos1 - $pos);
                $parts[] = "";
                $pos = $pos2;
            }
            $parts[] = substr($xt->name_pattern, $pos);
            $xt->name_parts = $parts;
            $i = 0;
            while ($i !== count($this->xlist) && $this->xlist[$i] !== $parts[0]) {
                $i += 2;
            }
            if ($i === count($this->xlist)) {
                array_push($this->xlist, $parts[0], []);
            }
            array_push($this->xlist[$i + 1], $parts[count($parts) - 1], $xt);
        } else {
            assert(is_string($xt->name));
            $this->xmap[$xt->name][] = $xt;
            if (isset($xt->alias)) {
                $this->potential_aliases[] = $xt->name;
            }
            $this->xmap_sorted = false;
        }
        return true;
    }

    /** @suppress PhanAccessReadOnlyProperty */
    function _add_description_item($xt, $k, $landmark) {
        if (isset($xt->description) || isset($xt->summary)) {
            $x = (object) [
                "merge" => $xt->merge ?? true,
                "__source_order" => $xt->__source_order
            ];
            foreach (["name_pattern", "summary", "description", "name", "priority"] as $k) {
                if (isset($xt->$k))
                    $x->$k = $xt->$k;
            }
            $this->_add_item($x, $k, $landmark);
            if (isset($x->name)
                && ($si = $this->map[$x->name] ?? null) !== null
                && Conf::xt_priority_compare($si, $x) <= 0) {
                if (isset($x->description)) {
                    $si->description = $x->description;
                }
                if (isset($x->summary)) {
                    $si->summary = $x->summary;
                }
            }
        }
        return true;
    }

    /** @param object $gj
     * @param list<string> $pages
     * @param bool $members */
    private function _assign_pages($gj, $pages, $members) {
        if (strpos($gj->group, "/") === false
            && !in_array($gj->group, $pages)) {
            $pages[] = $gj->group;
        }
        foreach ($gj->settings ?? [] as $s) {
            foreach ($this->_get_sij($s) as $sij) {
                $sij->pages = $sij->pages ?? [];
                array_push($sij->pages, ...$pages);
                //error_log(json_encode($sij));
            }
        }
        $group = null;
        if (isset($gj->print_members) && is_string($gj->print_members)) {
            $group = $gj->print_members;
        } else if ($members || ($gj->print_members ?? false) === true) {
            $group = $gj->name;
        } else {
            $group = null;
        }
        if ($group !== null) {
            foreach ($this->cs->members($group) as $gjx) {
                $this->_assign_pages($gjx, $pages, false);
            }
        }
    }

    /** @param string $name
     * @return list<object> */
    function _get_sij($name) {
        if (($pos1 = strpos($name, '$')) !== false) {
            $part0 = substr($name, 0, $pos1);
            $pos2 = strrpos($name, '$');
            $part2 = substr($name, $pos2 + 1);
            $result = [];
            for ($i = 0; $i !== count($this->xlist); $i += 2) {
                if ($this->xlist[$i] === $part0) {
                    $xlist = $this->xlist[$i + 1];
                    for ($j = 0; $j !== count($xlist); $j += 2) {
                        if ($xlist[$j] === $part2 && $xlist[$j+1]->name_pattern === $name) {
                            $result[] = $xlist[$j+1];
                        }
                    }
                }
            }
            return $result;
        } else {
            $x = $this->xmap[$name] ?? null;
            if ($x === null) {
                $x = $this->xmap[$name] = [(object) ["name" => $name, "merge" => true, "priority" => INF]];
                $this->xmap_sorted = false;
            }
            return $x;
        }
    }

    /** @param string $name
     * @param list<string> $parts
     * @return ?list<string> */
    private function _match_parts($name, $parts) {
        $nparts = count($parts);
        $pos = strlen($parts[0]);
        $splitlen = [];
        $slashpos = strpos($name, "/");
        for ($i = 1; $i !== $nparts; $i += 2) {
            if ($i === $nparts - 2) {
                $npos = strlen($name) - strlen($parts[$i + 1]);
            } else {
                $npos = strpos($name, $parts[$i + 1], $pos);
            }
            if ($slashpos !== false
                && $slashpos < $pos) {
                $slashpos = strpos($name, "/", $pos);
            }
            if ($npos === false
                || $npos <= $pos
                || ($slashpos !== false && $slashpos < $npos)) {
                return null;
            }
            $splitlen[] = $npos - $pos;
            $pos = $npos + strlen($parts[$i + 1]);
        }
        $result = [$parts[0]];
        $pos = strlen($parts[0]);
        for ($i = 1, $j = 0; $i !== $nparts; $i += 2, ++$j) {
            $result[] = substr($name, $pos, $splitlen[$j]);
            $result[] = $parts[$i + 1];
            $pos += $splitlen[$j] + strlen($parts[$i + 1]);
        }
        return $result;
    }

    /** @param string $name
     * @param stdClass $xt
     * @return ?stdClass */
    private function _instantiate_match($name, $xt) {
        if (($parts = $this->_match_parts($name, $xt->name_parts))) {
            $xt = clone $xt;
            $xt->name = $name;
            $xt->name_parts = $parts;
            if (isset($xt->alias_pattern)) {
                $xt->alias = $this->_expand_pattern($xt->alias_pattern, $parts);
            }
            return $xt;
        } else {
            return null;
        }
    }

    /** @param string $s
     * @param list<string> $parts
     * @return ?string */
    private function _expand_pattern($s, $parts) {
        $p0 = 0;
        $l = strlen($s);
        while ($p0 < $l && ($p1 = strpos($s, '$', $p0)) !== false) {
            $n = strspn($s, '$', $p1);
            if ($n * 2 - 1 < count($parts)) {
                $t = $parts[$n * 2 - 1];
                $s = substr($s, 0, $p1) . $t . substr($s, $p1 + $n);
                $p0 = $p1 + strlen($t);
            } else {
                $p0 = $p1 + 1;
            }
        }
        return $s;
    }

    /** @param string $name
     * @param string $prefix
     * @param list<string|object> $items
     * @param list<object> &$curlist */
    private function _expand($name, $prefix, $items, &$curlist) {
        $plen = strlen($prefix);
        $nlen = strlen($name);
        $nitems = count($items);
        for ($j = 0; $j !== $nitems; $j += 2) {
            if ($plen + strlen($items[$j]) < $nlen
                && str_ends_with($name, $items[$j])
                && ($xt = $this->_instantiate_match($name, $items[$j + 1]))) {
                $curlist[] = $xt;
            }
        }
    }

    /** @param string $name
     * @return ?Si */
    private function _make_si($name) {
        $cs = $this->cs;
        $jx = null;
        for ($aliases = 0; $aliases < 5; ++$aliases) {
            // check cache
            if (array_key_exists($name, $this->map)) {
                return $this->map[$name];
            }
            // expand patterns
            $curlist = $this->xmap[$name] ?? [];
            for ($i = 0; $i !== count($this->xlist); $i += 2) {
                if (str_starts_with($name, $this->xlist[$i])) {
                    $this->_expand($name, $this->xlist[$i], $this->xlist[$i + 1], $curlist);
                }
            }
            // look up entry
            $jx = $cs->xtp->search_list($curlist);
            // check for alias
            if ($jx && isset($jx->alias) && is_string($jx->alias)) {
                $name = $jx->alias;
                $jx = null;
            } else {
                break;
            }
        }
        Conf::xt_resolve_require($jx);
        return $jx ? new Si($cs->conf, $jx) : null;
    }

    /** @param string $name
     * @return ?Si */
    function get($name) {
        if (!array_key_exists($name, $this->map)) {
            $this->map[$name] = $this->_make_si($name);
        }
        return $this->map[$name];
    }

    /** @return array<string,string> */
    function aliases() {
        $a = [];
        foreach ($this->potential_aliases as $n) {
            if (($si = $this->get($n)) && $si->name !== $n)
                $a[$n] = $si->name;
        }
        return $a;
    }

    /** @return list<Si> */
    function top_list() {
        $sis = [];
        foreach (array_keys($this->xmap) as $k) {
            if (($si = $this->get($k)) !== null
                && $si->is_top()
                && $si->name === $k /* no aliases */)
                $sis[] = $si;
        }
        usort($sis, "Conf::xt_order_compare");
        return $sis;
    }

    /** @param string $pfx
     * @return array<string,list<object>> */
    function _xmap_members($pfx) {
        if (!$this->xmap_sorted) {
            ksort($this->xmap, SORT_STRING);
            $this->xmap_sorted = true;
        }
        $xkeys = array_keys($this->xmap);
        $l = str_list_lower_bound($pfx, $xkeys);
        $mxmap = [];
        while ($l < count($xkeys) && str_starts_with($xkeys[$l], $pfx)) {
            if (strpos($xkeys[$l], "/", strlen($pfx)) === false) {
                $mxmap[$xkeys[$l]] = $this->xmap[$xkeys[$l]];
            }
            ++$l;
        }
        return $mxmap;
    }

    /** @param string $pfx
     * @return list<Si> */
    function member_list($pfx) {
        assert(!str_ends_with($pfx, "/"));
        $pfx .= "/";
        // collect members by specific name
        $mxmap = $this->_xmap_members($pfx);
        // collect members by expansion
        for ($i = 0; $i !== count($this->xlist); $i += 2) {
            if (str_starts_with($pfx, $this->xlist[$i])) {
                $items = $this->xlist[$i + 1];
                $nitems = count($items);
                for ($j = 0; $j !== $nitems; $j += 2) {
                    if (str_starts_with($items[$j], "/")) {
                        $name = $pfx . substr($items[$j], 1);
                        if (($xt = $this->_instantiate_match($name, $items[$j + 1]))) {
                            $mxmap[$name][] = $xt;
                        }
                    }
                }
            }
        }
        // instantiate members
        $sis = [];
        $cs = $this->cs;
        foreach ($mxmap as $name => $curlist) {
            if (array_key_exists($name, $this->map)) {
                if (($si = $this->map[$name]))
                    $sis[] = $si;
            } else if (($jx = $cs->xtp->search_list($curlist))
                       && !isset($jx->alias)) {
                Conf::xt_resolve_require($jx);
                $sis[] = new Si($cs->conf, $jx);
            }
        }
        return $sis;
    }

    static function parse_description_markdown($s) {
        if (!str_starts_with($s, "#")) {
            return null;
        }
        $m = preg_split('/^#\s+([\w$\/]+)\s*\n/m', $s, -1, PREG_SPLIT_DELIM_CAPTURE);
        $xs = [];
        for ($i = 1; $i < count($m); $i += 2) {
            $x = [];
            $key = $m[$i];
            $x[strpos($key, "\$") === false ? "name" : "name_pattern"] = $key;
            $d = cleannl(ltrim($m[$i + 1]));
            if (str_starts_with($d, "> ")) {
                preg_match('/\A(?:^> .*?\n)+/m', $d, $mx);
                $x["summary"] = "<3>" . simplify_whitespace(str_replace("\n> ", "", substr($mx[0], 2)));
                $d = ltrim(substr($d, strlen($mx[0])));
            }
            if ($d !== "") {
                $x["description"] = "<3>" . $d;
            }
            $xs[] = (object) $x;
        }
        return $xs;
    }

    function ensure_descriptions() {
        if (!$this->has_descriptions) {
            $this->has_descriptions = true;
            foreach ([["?etc/settingdescriptions.md"], $this->cs->conf->opt("settingDescriptions")] as $arg) {
                expand_json_includes_callback($arg, [$this, "_add_description_item"], "SettingInfoSet::parse_description_markdown");
            }
        }
    }
}
