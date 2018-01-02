<?php
// api_search.php -- HotCRP search-related API calls
// Copyright (c) 2008-2018 Eddie Kohler; see LICENSE.

class Search_API {
    static function search(Contact $user, Qrequest $qreq) {
        $topt = PaperSearch::search_types($user, $qreq->t);
        if (empty($topt) || ($qreq->t && !isset($topt[$qreq->t])))
            return new JsonResult(403, "Permission error.");
        $t = $qreq->t ? : key($topt);

        $q = $qreq->q;
        if (isset($q)) {
            $q = trim($q);
            if ($q === "(All)")
                $q = "";
        } else if (isset($qreq->qa) || isset($qreq->qo) || isset($qreq->qx))
            $q = PaperSearch::canonical_query((string) $qreq->qa, (string) $qreq->qo, (string) $qreq->qx, $user->conf);
        else
            return new JsonResult(400, "Missing parameter.");

        $sarg = ["t" => $t, "q" => $q];
        if ($qreq->qt)
            $sarg["qt"] = $qreq->qt;
        if ($qreq->urlbase)
            $sarg["urlbase"] = $qreq->urlbase;

        $search = new PaperSearch($user, $sarg);
        $pl = new PaperList($search, ["sort" => true], $qreq);
        $ih = $pl->ids_and_groups();
        return ["ok" => true, "ids" => $ih[0], "groups" => $ih[1],
                "hotlist_info" => $pl->session_list_object()->info_string()];
    }

    static function fieldhtml(Contact $user, Qrequest $qreq, PaperInfo $prow = null) {
        $fdef = $qreq->f ? $user->conf->paper_columns($qreq->f, $user) : null;
        if (count($fdef) > 1) {
            return new JsonResult(400, "“" . htmlspecialchars($qreq->f) . "” expands to more than one field.");
        } else if (!$fdef || !isset($fdef[0]->fold) || !$fdef[0]->fold) {
            return new JsonResult(404, "No such field.");
        }
        $fdef = PaperColumn::make($user->conf, $fdef[0]);
        if ($qreq->f == "au" || $qreq->f == "authors")
            PaperList::change_display($user, "pl", "aufull", (int) $qreq->aufull);
        if (!isset($qreq->q) && $prow) {
            $qreq->t = $prow->timeSubmitted > 0 ? "s" : "all";
            $qreq->q = $prow->paperId;
        } else if (!isset($qreq->q))
            $qreq->q = "";
        $reviewer = null;
        if ($qreq->reviewer && $user->email !== $qreq->reviewer)
            $reviewer = $user->conf->user_by_email($qreq->reviewer);
        unset($qreq->reviewer);
        $search = new PaperSearch($user, $qreq, $reviewer);
        $pl = new PaperList($search, ["report" => "pl"]);
        $response = $pl->column_json($qreq->f);
        $response["ok"] = !empty($response);
        return $response;
    }
}
