<?php
// toposort.php -- topological sort utility
// Copyright (c) 2026 Eddie Kohler; see LICENSE.

class Toposort {
    /** Topological sort using Kahn's algorithm.
     * @param array<string,list<string>> $deps  name => list of dependency names
     * @return list<string>  eval order (subset of keys if cycles exist) */
    static function sort($deps) {
        $in_degree = [];
        $dependents = []; // dep => list of names that depend on it
        foreach ($deps as $name => $dep_list) {
            $in_degree[$name] = 0;
            foreach ($dep_list as $dep) {
                if (isset($deps[$dep])) {
                    ++$in_degree[$name];
                    $dependents[$dep][] = $name;
                }
            }
        }

        $queue = [];
        foreach ($in_degree as $name => $degree) {
            if ($degree === 0) {
                $queue[] = $name;
            }
        }

        $order = [];
        while (!empty($queue)) {
            $name = array_shift($queue);
            $order[] = $name;
            foreach ($dependents[$name] ?? [] as $dep_name) {
                --$in_degree[$dep_name];
                if ($in_degree[$dep_name] === 0) {
                    $queue[] = $dep_name;
                }
            }
        }
        return $order;
    }
}
