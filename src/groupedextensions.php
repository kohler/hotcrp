<?php
// src/groupedextensions.php -- HotCRP settings > decisions page
// Copyright (c) 2006-2017 Eddie Kohler; see LICENSE.

class GroupedExtensions {
    private $_subgroups;

    function _add_json($fj) {
        if (isset($fj->name) && is_string($fj->name)) {
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
            $this->_subgroups[] = $fj;
            return true;
        } else
            return false;
    }
    function __construct(Contact $user, $args /* ... */) {
        $this->_subgroups = [];
        foreach (func_get_args() as $i => $arg) {
            if ($i > 0 && $arg)
                expand_json_includes_callback($arg, [$this, "_add_json"]);
        }
        usort($this->_subgroups, "Conf::xt_priority_compare");
        $sgs = $known = [];
        foreach ($this->_subgroups as $gj) {
            if (isset($known[$gj->name]) || !$user->conf->xt_allowed($gj, $user))
                continue;
            $known[$gj->name] = true;
            foreach ($gj->synonym as $syn)
                $known[$syn] = true;
            if (Conf::xt_enabled($gj))
                $sgs[$gj->name] = $gj;
        }
        $this->_subgroups = $sgs;
        uasort($this->_subgroups, function ($aj, $bj) {
            if ($aj->group !== $bj->group) {
                if (isset($this->_subgroups[$aj->group]))
                    $aj = $this->_subgroups[$aj->group];
                if (isset($this->_subgroups[$bj->group]))
                    $bj = $this->_subgroups[$bj->group];
            }
            return Conf::xt_position_compare($aj, $bj);
        });
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
}
