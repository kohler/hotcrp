<?php
// searchactions.php -- HotCRP helper class for paper search actions
// HotCRP is Copyright (c) 2006-2016 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

class SearchActions {
    static public $sel = array();

    static function any() {
        return count(self::$sel);
    }

    static function selection() {
        return self::$sel;
    }

    static function selection_at($i) {
        return get(self::$sel, $i);
    }

    static function selection_map() {
        $selmap = array();
        foreach (self::$sel as $i => $pid)
            $selmap[$pid] = $i + 1;
        return $selmap;
    }

    static function set_selection($papers) {
        self::$sel = $selmap = array();
        foreach ($papers as $pid)
            if (($pid = cvtint($pid)) > 0 && !isset($selmap[$pid]))
                self::$sel[] = $selmap[$pid] = $pid;
    }

    static function parse_requested_selection($user, $key = null) {
        if ($key === null) {
            $ps = isset($_POST["p"]) ? $_POST["p"] : get($_GET, "p");
            if ($ps === null)
                $ps = isset($_POST["pap"]) ? $_POST["pap"] : get($_GET, "pap");
        } else
            $ps = isset($_POST[$key]) ? $_POST[$key] : get($_GET, $key);
        if ($user && $ps === "all") {
            $s = new PaperSearch($user, $_REQUEST);
            $ps = $s->paperList();
        } else if ($ps === "all")
            $ps = null;
        if (is_string($ps))
            $ps = preg_split('/\s+/', $ps);
        if (is_array($ps))
            self::set_selection($ps);
        return self::any();
    }

    static function clear_requested_selection() {
        unset($_REQUEST["p"], $_REQUEST["pap"], $_GET["p"], $_GET["pap"],
              $_POST["p"], $_POST["pap"]);
    }

    static function selection_equals_search($search) {
        if ($search instanceof PaperSearch)
            $search = $search->paperList();
        if (count($search) !== count(self::$sel))
            return false;
        sort($search);
        $sel = self::$sel;
        sort($sel);
        return join(" ", $search) === join(" ", $sel);
    }

    static function sql_predicate() {
        return sql_in_numeric_set(self::$sel);
    }

    static function reorder($a) {
        $ax = array();
        foreach (self::$sel as $pid)
            if (array_key_exists($pid, $a))
                $ax[$pid] = $a[$pid];
        return $ax;
    }


    static function pcassignments_csv_data($user, $selection) {
        global $Conf;
        $pcm = pcMembers();
        $round_list = $Conf->round_list();
        $reviewnames = array(REVIEW_PC => "pcreview", REVIEW_SECONDARY => "secondary", REVIEW_PRIMARY => "primary");
        $any_round = false;
        $texts = array();
        $result = Dbl::qe_raw($Conf->paperQuery($user, array("paperId" => $selection, "assignments" => 1)));
        while (($prow = PaperInfo::fetch($result, $user)))
            if (!$user->allow_administer($prow)) {
                $texts[] = array();
                $texts[] = array("paper" => $prow->paperId,
                                 "action" => "none",
                                 "title" => "You cannot override your conflict with this paper");
            } else if ($prow->all_reviewers()) {
                $texts[] = array();
                $texts[] = array("paper" => $prow->paperId,
                                 "action" => "clearreview",
                                 "email" => "#pc",
                                 "round" => "any",
                                 "title" => $prow->title);
                foreach ($prow->all_reviewers() as $cid)
                    if (($pc = get($pcm, $cid))
                        && ($rtype = $prow->review_type($cid)) >= REVIEW_PC) {
                        $round = $prow->review_round($cid);
                        $round_name = $round ? $round_list[$round] : "none";
                        $any_round = $any_round || $round != 0;
                        $texts[] = array("paper" => $prow->paperId,
                                         "action" => $reviewnames[$rtype],
                                         "email" => $pc->email,
                                         "round" => $round_name);
                    }
            }
        $header = array("paper", "action", "email");
        if ($any_round)
            $header[] = "round";
        $header[] = "title";
        return [$header, $texts];
    }
}
