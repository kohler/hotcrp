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
                "hotlist" => $pl->session_list_object()->info_string()];
    }

    static function fieldhtml(Contact $user, Qrequest $qreq, PaperInfo $prow = null) {
        $fdef = $qreq->f ? $user->conf->paper_columns($qreq->f, $user) : null;
        if (count($fdef) > 1) {
            return new JsonResult(400, "“" . htmlspecialchars($qreq->f) . "” expands to more than one field.");
        } else if (!$fdef || !isset($fdef[0]->fold) || !$fdef[0]->fold) {
            return new JsonResult(404, "No such field.");
        }

        if (!isset($qreq->q) && $prow) {
            $qreq->t = $prow->timeSubmitted > 0 ? "s" : "all";
            $qreq->q = $prow->paperId;
        } else if (!isset($qreq->q))
            $qreq->q = "";
        if ($qreq->f == "au" || $qreq->f == "authors")
            $qreq->q = ((int) $qreq->aufull ? "show" : "hide") . ":aufull " . $qreq->q;
        $reviewer = $qreq->reviewer;
        unset($qreq->reviewer);
        $search = new PaperSearch($user, $qreq);
        if ($reviewer && $user->email !== $reviewer
            && ($reviewer = $user->conf->user_by_email($reveiwer)))
            $search->set_reviewer($reviewer);

        $report = "pl";
        if ($qreq->session && str_starts_with($qreq->session, "pf"))
            $report = "pf";
        $pl = new PaperList($search, ["report" => $report]);
        $response = $pl->column_json($qreq->f);
        $response["ok"] = !empty($response);

        if ($qreq->session && $qreq->post_ok())
            $user->setsession_api($qreq->session);

        return $response;
    }
}
