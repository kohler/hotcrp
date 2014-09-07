<?php
// searchactions.php -- HotCRP helper class for paper search actions
// HotCRP is Copyright (c) 2006-2014 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

class SearchActions {

    static public $sel = array();
    static public $selmap = array();

    static function any() {
        return count(self::$sel);
    }

    static function selection() {
        return self::$sel;
    }

    static function selection_at($i) {
        return @self::$sel[$i];
    }

    static function set_selection($papers) {
        self::$sel = self::$selmap = array();
        foreach ($papers as $pid)
            if (($pid = cvtint($pid)) > 0 && !isset(self::$selmap[$pid])) {
                self::$selmap[$pid] = count(self::$sel);
                self::$sel[] = $pid;
            }
    }

    static function parse_requested_selection($user) {
        global $papersel, $paperselmap;
        if (!isset($_REQUEST["p"]) && isset($_REQUEST["pap"]))
            $_REQUEST["p"] = $_REQUEST["pap"];
        if (isset($_REQUEST["p"]) && $_REQUEST["p"] == "all") {
            $s = new PaperSearch($user, $_REQUEST);
            $_REQUEST["p"] = $s->paperList();
        }
        if (isset($_REQUEST["p"]) && is_string($_REQUEST["p"]))
            $_REQUEST["p"] = preg_split('/\s+/', $_REQUEST["p"]);
        if (isset($_REQUEST["p"]) && is_array($_REQUEST["p"]))
            self::set_selection($_REQUEST["p"]);
        return self::any();
    }

    static function clear_requested_selection() {
        unset($_REQUEST["p"], $_REQUEST["pap"], $_GET["p"], $_GET["pap"],
              $_POST["p"], $_POST["pap"]);
    }

    static function sql_predicate() {
        return sql_in_numeric_set(self::$sel);
    }

    static function reorder($a) {
        $ax = array();
        foreach (self::$sel as $pid)
            if (array_key_exists($pid, $a))
                $ax[] = $a[$pid];
        return $ax;
    }

}
