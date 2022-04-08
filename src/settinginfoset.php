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
    /** @var list<string|list<string|object>> */
    private $xlist = [];
    /** @var list<string> */
    private $potential_aliases = [];

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

    function _add_item($v, $k, $landmark) {
        if (isset($v->name_pattern)) {
            $parts = [];
            $pos = 0;
            while (($pos1 = strpos($v->name_pattern, '$', $pos)) !== false) {
                $pos2 = $pos1 + strspn($v->name_pattern, '$', $pos1);
                assert($pos2 - $pos1 === count($parts) / 2 + 1);
                $parts[] = substr($v->name_pattern, $pos, $pos1 - $pos);
                $parts[] = "";
                $pos = $pos2;
            }
            $parts[] = substr($v->name_pattern, $pos);
            $v->parts = $parts;
            $i = 0;
            while ($i !== count($this->xlist) && $this->xlist[$i] !== $parts[0]) {
                $i += 2;
            }
            if ($i === count($this->xlist)) {
                array_push($this->xlist, $parts[0], []);
            }
            array_push($this->xlist[$i + 1], $parts[count($parts) - 1], $v);
        } else {
            assert(is_string($v->name));
            $this->xmap[$v->name][] = $v;
            if (isset($v->alias)) {
                $this->potential_aliases[] = $v->name;
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
        $result = [$parts[0]];
        for ($i = 1; $i !== $nparts; $i += 2) {
            if ($i === $nparts - 2) {
                $npos = strlen($name) - strlen($parts[$i + 1]);
            } else {
                $npos = strpos($name, $parts[$i + 1], $pos);
            }
            if ($npos === false
                || $npos < $pos
                || ($m = substr($name, $pos, $npos - $pos)) === ""
                || strpos($m, "__") !== false) {
                return null;
            }
            $result[] = $m;
            $result[] = $parts[$i + 1];
            $pos += strlen($m) + strlen($parts[$i + 1]);
        }
        return $result;
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
     * @param list<string|object> $items */
    private function _expand($name, $prefix, $items) {
        $plen = strlen($prefix);
        $nlen = strlen($name);
        $nitems = count($items);
        for ($j = 0; $j !== $nitems; $j += 2) {
            if ($plen + strlen($items[$j]) < $nlen
                && str_ends_with($name, $items[$j])
                && ($parts = $this->_match_parts($name, $items[$j + 1]->parts))) {
                $jx = clone $items[$j + 1];
                $jx->name = $name;
                $jx->parts = $parts;
                if (isset($jx->alias_pattern)) {
                    $jx->alias = $this->_expand_pattern($jx->alias_pattern, $parts);
                }
                $this->xmap[$name][] = $jx;
            }
        }
    }

    /** @param string $name
     * @return ?Si */
    function get($name) {
        if (!array_key_exists($name, $this->map)) {
            $cs = $this->cs;
            $jx = null;
            for ($aliases = 0; $aliases < 5; ++$aliases) {
                // expand patterns
                for ($i = 0; $i !== count($this->xlist); $i += 2) {
                    if (str_starts_with($name, $this->xlist[$i])) {
                        $this->_expand($name, $this->xlist[$i], $this->xlist[$i + 1]);
                    }
                }
                // look up entry
                $jx = $cs->conf->xt_search_name($this->xmap, $name, $cs->viewer, true);
                // check for alias
                if (!isset($jx->alias) || !is_string($jx->alias)) {
                    break;
                }
                $name = $jx->alias;
            }
            if ($jx && !isset($jx->alias)) {
                Conf::xt_resolve_require($jx);
            } else {
                $jx = null;
            }
            $this->map[$name] = $jx ? new Si($cs->conf, $jx) : null;
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
        $a = [];
        foreach (array_keys($this->xmap) as $k) {
            if (($si = $this->get($k)) !== null
                && empty($si->parts)
                && !$si->internal
                && $si->name === $k)
                $a[] = $si;
        }
        usort($a, "Conf::xt_order_compare");
        return $a;
    }
}
