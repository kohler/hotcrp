<?php
// api_search.php -- HotCRP search-related API calls
// Copyright (c) 2008-2026 Eddie Kohler; see LICENSE.

class Search_API {
    /** @return JsonResult|PaperSearch */
    static private function make_search(Contact $user, Qrequest $qreq, ?PaperInfo $prow) {
        $sq = PaperSearch::qreq_subset($qreq);
        if (!isset($sq["q"])) {
            if ($prow) {
                $sq["q"] = (string) $prow->paperId;
                $sq["t"] = "viewable";
            } else if (isset($qreq->qa) || isset($qreq->qo) || isset($qreq->qx)) {
                $sq["q"] = PaperSearch::canonical_query((string) $qreq->qa, (string) $qreq->qo, (string) $qreq->qx, $qreq->qt, $user->conf);
            } else {
                return JsonResult::make_missing_error("q");
            }
        }
        $search = new PaperSearch($user, $sq);
        if (friendly_boolean($qreq->warn_missing)) {
            $search->set_warn_missing(true);
        }
        return $search;
    }

    /** @return JsonResult|PaperList */
    static private function make_list(Contact $user, Qrequest $qreq) {
        $search = self::make_search($user, $qreq, null);
        if ($search instanceof JsonResult) {
            return $search;
        }
        $pl = new PaperList($qreq->report ? : "empty", $search, ["sort" => true], $qreq);
        $pl->apply_view_report_default();
        if (friendly_boolean($qreq->session) !== false) {
            $pl->apply_view_session($qreq);
        }
        return $pl;
    }

    /** @return JsonResult */
    static function search(Contact $user, Qrequest $qreq) {
        $pl = self::make_list($user, $qreq);
        if ($pl instanceof JsonResult) {
            return $pl;
        }
        $format = 0;
        if ($qreq->format || $qreq->f) {
            if (!isset($qreq->format)) {
                return JsonResult::make_missing_error("format");
            } else if (!isset($qreq->f)) {
                return JsonResult::make_missing_error("f");
            } else if ($qreq->format === "html") {
                $format = PaperList::FORMAT_HTML;
            } else if ($qreq->format === "text" || $qreq->format === "csv") {
                $format = PaperList::FORMAT_CSV;
            } else if ($qreq->format === "json") {
                $format = PaperList::FORMAT_JSON;
            } else {
                return JsonResult::make_parameter_error("format");
            }
            $pl->parse_view($qreq->f, PaperList::VIEWORIGIN_MAX);
        }
        $ih = $pl->ids_and_groups();
        $jr = JsonResult::make_ok();
        if ($pl->has_message()) {
            $jr->set("message_list", $pl->message_list());
        }
        $jr->set("ids", $ih[0]);
        $jr->set("groups", $ih[1]);
        $jr->set("search_params", $pl->encoded_search_params());
        if (friendly_boolean($qreq->hotlist)) {
            $jr->set("hotlist", $pl->session_list_object()->info_string());
        }
        if ($format > 0) {
            foreach ($pl->format_json($format, PaperList::VIEWORIGIN_MAX) as $k => $v) {
                $jr->set($k, $v);
            }
        }
        if (isset($qreq->session)
            && $qreq->valid_token()
            && !$qreq->is_head()
            && friendly_boolean($qreq->session) === null) {
            Session_API::change_session($qreq, $qreq->session);
        }
        return $jr;
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
        $search = self::make_search($user, $qreq, $prow);
        if ($search instanceof JsonResult) {
            return $search;
        }
        $pl = new PaperList("empty", $search);
        $pl->parse_view($qreq->f, PaperList::VIEWORIGIN_MAX);
        $response = $pl->table_html_json();

        $j = [
            "ok" => !empty($response["fields"]),
            "message_list" => $pl->message_list()
        ] + $response;
        if ($j["ok"]
            && $qreq->session
            && $qreq->valid_token()
            && !$qreq->is_head()
            && friendly_boolean($qreq->session) === null) {
            Session_API::change_session($qreq, $qreq->session);
        }
        return $j;
    }

    static function fieldtext(Contact $user, Qrequest $qreq, ?PaperInfo $prow) {
        if ($qreq->f === null) {
            return JsonResult::make_missing_error("f");
        }
        $search = self::make_search($user, $qreq, $prow);
        if ($search instanceof JsonResult) {
            return $search;
        }
        $pl = new PaperList("empty", $search);
        $pl->parse_view($qreq->f, PaperList::VIEWORIGIN_MAX);
        $response = $pl->text_json();

        return [
            "ok" => !empty($response),
            "message_list" => $pl->message_list(),
            "data" => $response
        ];
    }

    static function searchaction(Contact $user, Qrequest $qreq, ?PaperInfo $prow) {
        if (($qreq->action ?? "") === "") {
            return JsonResult::make_missing_error("action");
        }
        if (!isset($qreq->p)) {
            $ssel = SearchSelection::make_default($qreq, $user);
        } else {
            $ssel = SearchSelection::make($qreq, $user, "p");
        }
        $action = ListAction::lookup($qreq->action, $user, $qreq, $ssel, ListAction::F_API);
        if ($action instanceof ListAction) {
            $action = $action->run($user, $qreq, $ssel);
        }
        return ListAction::resolve_document($action, $qreq);
    }

    static function searchactions(Contact $user) {
        $fjs = [];
        $cs = ListAction::components($user, ListAction::F_API);
        foreach ($cs->members("") as $rf) {
            if (str_starts_with($rf->name, "__")) {
                continue;
            }
            $ufs = make_array($rf, ...$cs->members($rf->name));
            foreach ($ufs as $uf) {
                if (str_starts_with($uf->name, "__")
                    || (isset($uf->allow_if) && !$cs->allowed($uf->allow_if, $uf))
                    || ($uf->api ?? null) === false
                    || !isset($uf->function)) {
                    continue;
                }
                $fj = ["name" => $uf->name];
                if ($uf->get ?? false) {
                    $fj["get"] = true;
                }
                if ($uf->post ?? false) {
                    $fj["post"] = true;
                }
                if (isset($uf->title)) {
                    if ($uf !== $rf && isset($rf->title)) {
                        $fj["title"] = $rf->title . "/" . $uf->title;
                    } else {
                        $fj["title"] = $uf->title;
                    }
                }
                if (isset($uf->description)) {
                    $fj["description"] = $uf->description;
                }
                if (isset($uf->parameters)) {
                    if (is_string($uf->parameters)) {
                        $vos = new ViewOptionSchema(...explode(" ", $uf->parameters));
                    } else {
                        $vos = new ViewOptionSchema(...$uf->parameters);
                    }
                    foreach ($vos->help_order() as $vot) {
                        $fj["parameters"][] = $vot->unparse_export();
                    }
                }
                $fjs[] = $fj;
            }
        }
        return new JsonResult(["ok" => true, "actions" => $fjs]);
    }
}
