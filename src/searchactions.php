<?php
// searchactions.php -- HotCRP helper class for paper search actions
// HotCRP is Copyright (c) 2006-2016 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

class SearchActions {
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
