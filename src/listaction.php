<?php
// listaction.php -- HotCRP helper class for paper search actions
// Copyright (c) 2006-2018 Eddie Kohler; see LICENSE.

class ListAction {
    public $subname;
    const ENOENT = "No such action.";
    const EPERM = "Permission error.";
    function allow(Contact $user) {
        return true;
    }
    function run(Contact $user, $qreq, $selection) {
        return "Unsupported.";
    }

    static private function do_call($name, Contact $user, Qrequest $qreq, $selection) {
        if ($qreq->method() !== "GET" && $qreq->method() !== "HEAD" && !$qreq->post_ok())
            return new JsonResult(403, ["ok" => false, "error" => "Missing credentials."]);
        $uf = $user->conf->list_action($name, $user, $qreq->method());
        if (!$uf) {
            if ($user->conf->has_list_action($name, $user, null))
                return new JsonResult(405, ["ok" => false, "error" => "Method not supported."]);
            else if ($user->conf->has_list_action($name, null, $qreq->method()))
                return new JsonResult(403, ["ok" => false, "error" => "Permission error."]);
            else
                return new JsonResult(404, ["ok" => false, "error" => "Function not found."]);
        }
        if (is_array($selection))
            $selection = new SearchSelection($selection);
        if (get($uf, "paper") && $selection->is_empty())
            return new JsonResult(400, ["ok" => false, "error" => "No papers selected."]);
        if ($uf->callback[0] === "+") {
            $class = substr($uf->callback, 1);
            $action = new $class($user->conf, $uf);
        } else
            $action = call_user_func($uf->callback, $user->conf, $uf);
        if (!$action || !$action->allow($user))
            return new JsonResult(403, ["ok" => false, "error" => "Permission error."]);
        else
            return $action->run($user, $qreq, $selection);
    }

    static function call($name, Contact $user, Qrequest $qreq, $selection) {
        $res = self::do_call($name, $user, $qreq, $selection);
        if (is_string($res))
            $res = new JsonResult(400, ["ok" => false, "error" => $res]);
        if ($res instanceof JsonResult) {
            if ($res->status >= 300 && !$qreq->ajax)
                Conf::msg_error($res->content["error"]);
            else
                json_exit($res);
        } else if ($res instanceof CsvGenerator) {
            csv_exit($res);
        }
    }


    static function pcassignments_csv_data(Contact $user, $selection) {
        require_once("assignmentset.php");
        $pcm = $user->conf->pc_members();
        $token_users = [];

        $round_list = $user->conf->round_list();
        $any_round = $any_token = false;

        $texts = array();
        foreach ($user->paper_set($selection, ["reviewSignatures" => true]) as $prow) {
            if (!$user->allow_administer($prow)) {
                $texts[] = array();
                $texts[] = array("paper" => $prow->paperId,
                                 "action" => "none",
                                 "title" => "You cannot override your conflict with this paper");
            } else if (($rrows = $prow->reviews_by_display())) {
                $texts[] = array();
                $texts[] = array("paper" => $prow->paperId,
                                 "action" => "clearreview",
                                 "email" => "#pc",
                                 "round" => "any",
                                 "title" => $prow->title);
                foreach ($rrows as $rrow) {
                    if ($rrow->reviewToken) {
                        if (!array_key_exists($rrow->contactId, $token_users))
                            $token_users[$rrow->contactId] = $user->conf->user_by_id($rrow->contactId);
                        $u = $token_users[$rrow->contactId];
                    } else if ($rrow->reviewType >= REVIEW_PC)
                        $u = get($pcm, $rrow->contactId);
                    else
                        $u = null;
                    if (!$u)
                        continue;

                    $round = $rrow->reviewRound;
                    $d = ["paper" => $prow->paperId,
                          "action" => ReviewInfo::unparse_assigner_action($rrow->reviewType),
                          "email" => $u->email,
                          "round" => $round ? $round_list[$round] : "none"];
                    if ($rrow->reviewToken)
                        $d["review_token"] = $any_token = encode_token((int) $rrow->reviewToken);
                    $texts[] = $d;
                    $any_round = $any_round || $round != 0;
                }
            }
        }
        $header = array("paper", "action", "email");
        if ($any_round)
            $header[] = "round";
        if ($any_token)
            $header[] = "review_token";
        $header[] = "title";
        return [$header, $texts];
    }
}
