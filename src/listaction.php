<?php
// listaction.php -- HotCRP helper class for paper search actions
// Copyright (c) 2006-2026 Eddie Kohler; see LICENSE.

class ListAction {
    /** @return bool */
    function allow(Contact $user, Qrequest $qreq) {
        return true;
    }

    /** @return JsonResult|Downloader|Redirection|CsvGenerator|DocumentInfo|DocumentInfoSet */
    function run(Contact $user, Qrequest $qreq, SearchSelection $ssel) {
        return JsonResult::make_not_found_error(null, "<0>Action not found");
    }

    const F_API = 1;

    /** @return ComponentSet
     * @deprecated */
    static function components(Contact $user, $flags = 0) {
        return (new ListActionCall($user, $flags))->cs();
    }

    /** @param ComponentSet $cs
     * @param string $group
     * @return list */
    static function members_selector_options($cs, $group) {
        $last_group = null;
        $sel_opt = [];
        $p = strlen($group) + 1;
        foreach ($cs->members($group) as $rf) {
            if (str_starts_with($rf->name, "__")) {
                continue;
            }
            $as = strpos($rf->title, "/");
            if ($as === false) {
                if ($last_group) {
                    $sel_opt[] = ["optgroup", false];
                }
                $last_group = null;
            } else {
                $group = substr($rf->title, 0, $as);
                if ($group !== $last_group) {
                    $sel_opt[] = ["optgroup", $group];
                    $last_group = $group;
                }
            }
            $opt = [
                "value" => substr($rf->name, $p),
                "label" => $as === false ? $rf->title : substr($rf->title, $as + 1)
            ];
            foreach ($rf as $k => $v) {
                if (str_starts_with($k, "data-"))
                    $opt[$k] = $v;
            }
            $sel_opt[] = $opt;
        }
        return $sel_opt;
    }


    /** @param list<int> $pids
     * @return array{list<string>,list<array{paper?:int,action?:string,title?:string,email?:string,round?:string,review_token?:string}>} */
    static function pcassignments_csv_data(Contact $user, $pids) {
        require_once("assignmentset.php");
        $pcm = $user->conf->pc_members();

        $round_list = $user->conf->round_list();
        $any_round = $any_token = false;

        $texts = [];
        foreach ($user->paper_set(["paperId" => $pids, "reviewSignatures" => true]) as $prow) {
            if (!$user->allow_admin($prow)) {
                $texts[] = [];
                $texts[] = ["paper" => $prow->paperId,
                            "action" => "none",
                            "title" => "You cannot override your conflict with this paper"];
            } else {
                $any_this_paper = false;
                foreach ($prow->reviews_as_display() as $rrow) {
                    $cid = $rrow->contactId;
                    if ($rrow->reviewToken) {
                        $u = $user->conf->user_by_id($cid);
                    } else if ($rrow->reviewType >= REVIEW_PC) {
                        $u = $pcm[$cid] ?? null;
                    } else {
                        $u = null;
                    }
                    if (!$u) {
                        continue;
                    }

                    if (!$any_this_paper) {
                        $texts[] = [];
                        $texts[] = ["paper" => $prow->paperId,
                                    "action" => "clearreview",
                                    "email" => "#pc",
                                    "round" => "any",
                                    "title" => $prow->title];
                        $any_this_paper = true;
                    }

                    $round = $rrow->reviewRound;
                    $d = ["paper" => $prow->paperId,
                          "action" => ReviewInfo::unparse_assigner_action($rrow->reviewType),
                          "email" => $u->email,
                          "round" => $round ? $round_list[$round] : "none"];
                    if ($rrow->reviewToken) {
                        $d["review_token"] = $any_token = encode_token((int) $rrow->reviewToken);
                    }
                    $texts[] = $d;
                    $any_round = $any_round || $round != 0;
                }
            }
        }

        $header = ["paper", "action", "email"];
        if ($any_round) {
            $header[] = "round";
        }
        if ($any_token) {
            $header[] = "review_token";
        }
        $header[] = "title";
        return [$header, $texts];
    }
}
