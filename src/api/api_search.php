<?php
// api_search.php -- HotCRP search-related API calls
// Copyright (c) 2008-2020 Eddie Kohler; see LICENSE.

class Search_API {
    static function search(Contact $user, Qrequest $qreq) {
        $topt = PaperSearch::search_types($user, $qreq->t);
        if (empty($topt) || ($qreq->t && !isset($topt[$qreq->t]))) {
            return new JsonResult(403, "Permission error.");
        }
        $t = $qreq->t ? : key($topt);

        $q = $qreq->q;
        if (isset($q)) {
            $q = trim($q);
            if ($q === "(All)")
                $q = "";
        } else if (isset($qreq->qa) || isset($qreq->qo) || isset($qreq->qx)) {
            $q = PaperSearch::canonical_query((string) $qreq->qa, (string) $qreq->qo, (string) $qreq->qx, $qreq->qt, $user->conf);
        } else {
            return new JsonResult(400, "Missing parameter.");
        }

        $search = new PaperSearch($user, ["t" => $t, "q" => $q, "qt" => $qreq->qt, "urlbase" => $qreq->urlbase, "reviewer" => $qreq->reviewer]);
        $pl = new PaperList($search, ["report" => $qreq->report ? : "pl", "sort" => true], $qreq);
        $ih = $pl->ids_and_groups();
        return ["ok" => true, "ids" => $ih[0], "groups" => $ih[1],
                "hotlist" => $pl->session_list_object()->info_string()];
    }

    static function fieldhtml(Contact $user, Qrequest $qreq, PaperInfo $prow = null) {
        $fdef = $qreq->f ? $user->conf->paper_columns($qreq->f, $user) : [];
        if (count($fdef) > 1) {
            return new JsonResult(400, "“" . htmlspecialchars($qreq->f) . "” expands to more than one field.");
        } else if (!$fdef || !isset($fdef[0]->fold) || !$fdef[0]->fold) {
            return new JsonResult(404, "No such field.");
        }

        if (!isset($qreq->q) && $prow) {
            $qreq->t = $prow->timeSubmitted > 0 ? "s" : "all";
            $qreq->q = $prow->paperId;
        } else if (!isset($qreq->q)) {
            $qreq->q = "";
        }
        if ($qreq->f == "au" || $qreq->f == "authors") {
            $qreq->q = ((int) $qreq->aufull ? "show" : "hide") . ":aufull " . $qreq->q;
        }
        $search = new PaperSearch($user, $qreq);

        $report = "pl";
        if ($qreq->session && str_starts_with($qreq->session, "pf")) {
            $report = "pf";
        }
        $pl = new PaperList($search, ["report" => $report]);
        $response = $pl->column_json($qreq->f);
        if (!$response) {
            return ["ok" => false];
        } else {
            $response["ok"] = true;
            if ($qreq->session && $qreq->post_ok()) {
                Session_API::setsession($user, $qreq->session);
            }
            return $response;
        }
    }

    static function fieldtext(Contact $user, Qrequest $qreq, PaperInfo $prow = null) {
        if ($qreq->f === null) {
            return new JsonResult(400, "Missing parameter.");
        }
        $fdefs = [];
        foreach (preg_split('/\s+/', trim($qreq->f)) as $fid) {
            if ($user->conf->paper_columns($fid, $user)) {
                $fdefs[] = $fid;
            } else if ($fid !== "") {
                return new JsonResult(404, "No such field “{$fid}”.");
            }
        }

        if (!isset($qreq->q) && $prow) {
            $qreq->t = $prow->timeSubmitted > 0 ? "s" : "all";
            $qreq->q = $prow->paperId;
        } else if (!isset($qreq->q)) {
            $qreq->q = "";
        }
        $search = new PaperSearch($user, $qreq);

        $pl = new PaperList($search, ["report" => "pl"]);
        return ["ok" => true, "data" => $pl->text_json($qreq->f)];
    }
}
