<?php
// api_search.php -- HotCRP search-related API calls
// Copyright (c) 2008-2022 Eddie Kohler; see LICENSE.

class Search_API {
    /** @return JsonResult|PaperList */
    static function make_list(Contact $user, Qrequest $qreq) {
        $q = $qreq->q;
        if (isset($q)) {
            $q = trim($q);
            if ($q === "(All)") {
                $q = "";
            }
        } else if (isset($qreq->qa) || isset($qreq->qo) || isset($qreq->qx)) {
            $q = PaperSearch::canonical_query((string) $qreq->qa, (string) $qreq->qo, (string) $qreq->qx, $qreq->qt, $user->conf);
        } else {
            return JsonResult::make_missing_error("q");
        }

        $search = new PaperSearch($user, [
            "t" => $qreq->t ?? "",
            "q" => $q,
            "qt" => $qreq->qt,
            "reviewer" => $qreq->reviewer,
            "sort" => $qreq->sort,
            "scoresort" => $qreq->scoresort
        ]);
        $pl = new PaperList($qreq->report ? : "pl", $search, ["sort" => true], $qreq);
        $pl->apply_view_report_default();
        $pl->apply_view_session($qreq);
        return $pl;
    }

    /** @return JsonResult */
    static function search(Contact $user, Qrequest $qreq) {
        $pl = self::make_list($user, $qreq);
        if ($pl instanceof JsonResult) {
            return $pl;
        }
        $ih = $pl->ids_and_groups();
        return new JsonResult([
            "ok" => true,
            "ids" => $ih[0],
            "groups" => $ih[1],
            "hotlist" => $pl->session_list_object()->info_string(),
            "search_params" => $pl->encoded_search_params()
        ]);
    }

    static function apply_search(JsonResult $jr, Contact $user, Qrequest $qreq, $search) {
        $search = ltrim($search);
        if (str_starts_with($search, "{")) {
            $param = json_decode($search, true);
        } else {
            $pos = str_starts_with($search, "?") ? 1 : 0;
            preg_match_all('/([^&;=\s]*)=([^&;=\s]*)/', $search, $m, PREG_SET_ORDER, $pos);
            $param = [];
            foreach ($m as $mx) {
                $param[urldecode($mx[1])] = urldecode($mx[2]);
            }
        }
        if (is_array($param) && isset($param["q"])) {
            $nqreq = new Qrequest("GET", $param);
            $nqreq->set_user($user)->set_qsession($qreq->qsession());
            $njr = self::search($user, $nqreq);
            if ($njr->content["ok"]) {
                foreach ($njr->content as $k => $v) {
                    if (!isset($jr->content[$k]))
                        $jr->content[$k] = $v;
                }
            }
        }
    }

    static function fieldhtml(Contact $user, Qrequest $qreq, ?PaperInfo $prow) {
        if ($qreq->f === null) {
            return JsonResult::make_missing_error("f");
        }
        if (!isset($qreq->q) && $prow) {
            $qreq->t = $prow->timeSubmitted > 0 ? "s" : "all";
            $qreq->q = $prow->paperId;
        } else if (!isset($qreq->q)) {
            $qreq->q = "";
        }

        $search = new PaperSearch($user, $qreq);
        $pl = new PaperList("empty", $search);
        if (isset($qreq->aufull)) {
            $pl->set_view("aufull", (bool) $qreq->aufull, PaperList::VIEWORIGIN_SESSION);
        }
        $pl->parse_view($qreq->f, PaperList::VIEWORIGIN_MAX);
        $response = $pl->table_html_json();

        $j = [
            "ok" => !empty($response["fields"]),
            "message_list" => $pl->message_set()->message_list()
        ] + $response;
        if ($j["ok"] && $qreq->session && $qreq->valid_token() && !$qreq->is_head()) {
            Session_API::change_session($qreq, $qreq->session);
        }
        return $j;
    }

    static function fieldtext(Contact $user, Qrequest $qreq, ?PaperInfo $prow) {
        if ($qreq->f === null) {
            return JsonResult::make_missing_error("f");
        }

        if (!isset($qreq->q) && $prow) {
            $qreq->t = $prow->timeSubmitted > 0 ? "s" : "all";
            $qreq->q = $prow->paperId;
        } else if (!isset($qreq->q)) {
            $qreq->q = "";
        }
        $search = new PaperSearch($user, $qreq);
        $pl = new PaperList("empty", $search);
        $pl->parse_view($qreq->f, PaperList::VIEWORIGIN_MAX);
        $response = $pl->text_json();
        return [
            "ok" => !empty($response),
            "message_list" => $pl->message_set()->message_list(),
            "data" => $response
        ];
    }

    static function searchaction(Contact $user, Qrequest $qreq, ?PaperInfo $prow) {
        if ($qreq->is_get() && ($qreq->action ?? "") === "") {
            return self::searchaction_list($user);
        } else if (($qreq->action ?? "") === "") {
            return JsonResult::make_missing_error("action");
        }
        $qreq->p = $qreq->p ?? "all";
        $ssel = SearchSelection::make($qreq, $user, "p");
        $action = ListAction::lookup($qreq->action, $user, $qreq, $ssel, ListAction::LOOKUP_API);
        if ($action instanceof ListAction) {
            $action = $action->run($user, $qreq, $ssel);
        }
        return ListAction::resolve_document($action, $qreq);
    }

    static function searchaction_list(Contact $user) {
        $fjs = [];
        $cs = ListAction::components($user);
        foreach ($cs->members("") as $rf) {
            if (str_starts_with($rf->name, "__")) {
                continue;
            }
            foreach ($cs->members($rf->name) as $uf) {
                if (str_starts_with($uf->name, "__")
                    || !($uf->allow_api ?? false)
                    || (isset($uf->allow_if) && !$cs->allowed($uf->allow_if, $uf))
                    || !isset($uf->function)) {
                    continue;
                }
                $fj = ["action" => $uf->name];
                if (isset($uf->description)) {
                    $fj["description"] = $uf->description;
                }
                if ($uf->get ?? false) {
                    $fj["get"] = true;
                }
                if ($uf->post ?? false) {
                    $fj["post"] = true;
                }
                $fjs[] = $fj;
            }
        }
        return new JsonResult(["ok" => true, "actions" => $fjs]);
    }
}
