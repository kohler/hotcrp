<?php
// searchactions.php -- HotCRP helper class for paper search actions
// HotCRP is Copyright (c) 2006-2016 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

class SearchAction {
    public $subname;
    const ENOENT = "No such action.";
    const EPERM = "Permission error.";
    public function allow(Contact $user) {
        return true;
    }
    public function list_actions(Contact $user, $qreq, PaperList $pl, &$actions) {
    }
    public function run(Contact $user, $qreq, $selection) {
        return "Unsupported.";
    }
}

class SearchActions {
    static private $loaded = false;
    static private $byname = [];

    static function load() {
        global $ConfSitePATH, $Opt;
        if (self::$loaded)
            return;
        self::$loaded = true;
        foreach (expand_includes($ConfSitePATH, "src/sa/*.php") as $f)
            include $f;
        if (isset($Opt["searchaction_include"]) && $Opt["searchaction_include"])
            read_included_options($ConfSitePATH, $Opt["searchaction_include"]);
    }

    static function register($name, $subname, $flags, SearchAction $fn) {
        if (!isset(self::$byname[$name]))
            self::$byname[$name] = [];
        assert(!isset(self::$byname[$name][(string) $subname]));
        self::$byname[$name][(string) $subname] = [$fn, $flags];
        $fn->subname = $subname;
    }

    static function has_function($name, $subname = null) {
        if (isset(self::$byname[$name])) {
            $ufm = self::$byname[$name];
            return isset($ufm[(string) $subname]) || isset($ufm[""]);
        } else
            return false;
    }

    static function call($name, $subname, Contact $user, $qreq, $selection) {
        $uf = null;
        if (isset(self::$byname[$name])) {
            $ufm = self::$byname[$name];
            if ((string) $subname !== "" && isset($ufm[$subname]))
                $uf = $ufm[$subname];
            else if (isset($ufm[""]))
                $uf = $ufm[""];
        }
        if (is_array($selection))
            $selection = new SearchSelection($selection);
        if (!$uf)
            $error = "No such search action.";
        else if (!($uf[1] & SiteLoader::API_GET) && !check_post($qreq))
            $error = "Missing credentials.";
        else if (($uf[1] & SiteLoader::API_PAPER) && $selection->is_empty())
            $error = "No papers selected.";
        else if (!$uf[0]->allow($user))
            $error = "Permission error.";
        else
            $error = $uf[0]->run($user, $qreq, $selection);
        if (is_string($error) && $qreq->ajax)
            json_exit(["ok" => false, "error" => $error]);
        else if (is_string($error))
            Conf::msg_error($error);
        return $error;
    }

    static function list_actions(Contact $user, $qreq, PaperList $pl) {
        self::load();
        $actions = [];
        foreach (self::$byname as $ufm)
            if (isset($ufm[""]) && $ufm[""][0]->allow($user))
                $ufm[""][0]->list_actions($user, $qreq, $pl, $actions);
        return $actions;
    }

    static function list_subactions($name, Contact $user, $qreq, PaperList $pl) {
        $actions = [];
        if (isset(self::$byname[$name]))
            foreach (self::$byname[$name] as $subname => $uf)
                if ($subname !== "" && $uf[0]->allow($user))
                    $uf[0]->list_actions($user, $qreq, $pl, $actions);
        return $actions;
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
