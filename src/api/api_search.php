<?php
// api_search.php -- HotCRP search-related API calls
// Copyright (c) 2008-2020 Eddie Kohler; see LICENSE.

class Search_API {
    static function search(Contact $user, Qrequest $qreq) {
        $topt = PaperSearch::search_types($user, $qreq->t);
        if (empty($topt) || ($qreq->t && !isset($topt[$qreq->t]))) {
            return new JsonResult(403, "Permission error.");
        }

        $t = $qreq->t ?? key($topt);
        $q = $qreq->q;
        if ($qreq->urlbase) {
            error_log("{$user->conf->dbname}: api/search with urlbase"); // XXX
        }

        if (isset($q)) {
            $q = trim($q);
            if ($q === "(All)")
                $q = "";
        } else if (isset($qreq->qa) || isset($qreq->qo) || isset($qreq->qx)) {
            $q = PaperSearch::canonical_query((string) $qreq->qa, (string) $qreq->qo, (string) $qreq->qx, $qreq->qt, $user->conf);
        } else {
            return new JsonResult(400, "Missing parameter.");
        }

        $search = new PaperSearch($user, ["t" => $t, "q" => $q, "qt" => $qreq->qt, "reviewer" => $qreq->reviewer]);
        $pl = new PaperList($qreq->report ? : "pl", $search, ["sort" => true], $qreq);
        $pl->apply_view_report_default();
        $pl->apply_view_session();
        $ih = $pl->ids_and_groups();
        return [
            "ok" => true,
            "ids" => $ih[0],
            "groups" => $ih[1],
            "hotlist" => $pl->session_list_object()->info_string()
        ];
    }

    static function fieldhtml(Contact $user, Qrequest $qreq, PaperInfo $prow = null) {
        if ($qreq->f === null) {
            return new JsonResult(400, "Missing parameter.");
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
            $pl->set_view("aufull", (bool) $qreq->aufull);
        }
        $pl->parse_view($qreq->f, null);
        $response = $pl->table_html_json();

        $j = ["ok" => !empty($response["fields"])] + $response;
        foreach ($pl->message_set()->message_texts() as $m) {
            $j["errors"][] = $m;
        }
        if ($j["ok"] && $qreq->session && $qreq->valid_token() && !$qreq->is_head()) {
            Session_API::setsession($user, $qreq->session);
        }
        return $j;
    }

    static function fieldtext(Contact $user, Qrequest $qreq, PaperInfo $prow = null) {
        if ($qreq->f === null) {
            return new JsonResult(400, "Missing parameter.");
        }

        if (!isset($qreq->q) && $prow) {
            $qreq->t = $prow->timeSubmitted > 0 ? "s" : "all";
            $qreq->q = $prow->paperId;
        } else if (!isset($qreq->q)) {
            $qreq->q = "";
        }
        $search = new PaperSearch($user, $qreq);
        $pl = new PaperList("empty", $search);
        $pl->parse_view($qreq->f, null);
        $response = $pl->text_json();

        $j = ["ok" => !empty($response), "data" => $response];
        foreach ($pl->message_set()->message_texts() as $m) {
            $j["errors"][] = $m;
        }
        return $j;
    }
}
