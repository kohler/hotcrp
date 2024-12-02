<?php
// listaction.php -- HotCRP helper class for paper search actions
// Copyright (c) 2006-2024 Eddie Kohler; see LICENSE.

class ListAction {
    /** @return bool */
    function allow(Contact $user, Qrequest $qreq) {
        return true;
    }

    /** @return null|JsonResult|Downloader|Redirection|CsvGenerator|DocumentInfo|DocumentInfoSet */
    function run(Contact $user, Qrequest $qreq, SearchSelection $ssel) {
        return JsonResult::make_not_found_error(null, "<0>Action not found");
    }

    /** @return ComponentSet */
    static function components(Contact $user) {
        $cs = new ComponentSet($user, ["etc/listactions.json"], $user->conf->opt("listActions"));
        foreach ($cs->members("__expand") as $gj) {
            if (isset($gj->allow_if) && !$cs->allowed($gj->allow_if, $gj)) {
                continue;
            }
            Conf::xt_resolve_require($gj);
            call_user_func($gj->expand_function, $cs, $gj);
        }
        return $cs;
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

    const LOOKUP_API = 1;

    /** @param string $name
     * @param int $flags
     * @return JsonResult|ListAction */
    static function lookup($name, Contact $user, Qrequest $qreq,
                           SearchSelection $selection, $flags = 0) {
        if ($qreq->method() !== "GET"
            && $qreq->method() !== "HEAD"
            && !$qreq->valid_token()) {
            return JsonResult::make_error(403, "<0>Missing credentials");
        }
        $cs = self::components($user);
        $slash = strpos($name, "/");
        $namepfx = $slash > 0 ? substr($name, 0, $slash) : null;
        $cs->xtp->set_require_key_for_method($qreq->method());
        $uf = $cs->get($name) ?? ($namepfx ? $cs->get($namepfx) : null);
        // XXX allow `POST` requests to `get`
        if (!$uf && $qreq->is_post()) {
            $cs->xtp->set_require_key_for_method("GET");
            $uf = $cs->get($name) ?? ($namepfx ? $cs->get($namepfx) : null);
        }
        if (!$uf) {
            $cs->reset_context();
            $cs->xtp->set_require_key_for_method(null);
            $uf1 = $cs->get($name) ?? ($namepfx ? $cs->get($namepfx) : null);
            if ($uf1) {
                return JsonResult::make_error(405, "<0>Method not supported");
            }
        }
        if (!$uf
            || !Conf::xt_resolve_require($uf)
            || (isset($uf->allow_if) && !$cs->allowed($uf->allow_if, $uf))
            || !is_string($uf->function)
            || (($flags & self::LOOKUP_API) !== 0 && !($uf->allow_api ?? false))) {
            return JsonResult::make_error(404, "<0>Action not found");
        } else if (($uf->paper ?? false) && $selection->is_empty()) {
            return JsonResult::make_error(400, "<0>Empty selection");
        } else if ($uf->function[0] === "+") {
            $class = substr($uf->function, 1);
            /** @phan-suppress-next-line PhanTypeExpectedObjectOrClassName */
            $action = new $class($user->conf, $uf);
        } else {
            $action = call_user_func($uf->function, $user->conf, $uf);
        }
        if (!$action || !$action->allow($user, $qreq)) {
            return JsonResult::make_permission_error();
        }
        return $action;
    }

    /** @param string $name */
    static function call($name, Contact $user, Qrequest $qreq,
                         SearchSelection $selection) {
        $res = self::lookup($name, $user, $qreq, $selection);
        if ($res instanceof ListAction) {
            $res = $res->run($user, $qreq, $selection);
        }
        $res = self::resolve_document($res, $qreq);
        if ($res instanceof JsonResult) {
            if (isset($res->content["message_list"]) && !$qreq->ajax) {
                $user->conf->feedback_msg($res->content["message_list"]);
            }
            if ($qreq->ajax) {
                json_exit($res);
            }
        } else if ($res instanceof Downloader) {
            $res->emit();
            exit(0);
        } else if ($res instanceof Redirection) {
            $user->conf->redirect($res->url);
            exit(0);
        }
    }

    /** @param null|JsonResult|Downloader|Redirection|CsvGenerator|DocumentInfo|DocumentInfoSet $res
     * @return null|JsonResult|Downloader|Redirection */
    static function resolve_document($res, Qrequest $qreq) {
        if ($res instanceof DocumentInfo
            || $res instanceof DocumentInfoSet
            || $res instanceof CsvGenerator) {
            $dopt = new Downloader;
            $dopt->parse_qreq($qreq);
            $dopt->set_attachment(true);
            if ($res->prepare_download($dopt)) {
                return $dopt;
            }
            return JsonResult::make_message_list(400, $res->message_list());
        }
        return $res;
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
            if (!$user->allow_administer($prow)) {
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
