<?php
// api_potentialconflicts.php -- HotCRP user-related API calls
// Copyright (c) 2008-2026 Eddie Kohler; see LICENSE.

class PotentialConflicts_API {
    /** @return JsonResult */
    static function run(Contact $user, Qrequest $qreq, ?PaperInfo $prow) {
        if (!isset($qreq->p) && !$user->privChair) {
            return JsonResult::make_missing_error("p");
        } else if (!$prow && $qreq->p !== "new") {
            return JsonResult::make_parameter_error("p");
        } else if (!$prow) {
            $prow = PaperInfo::make_new($user, $qreq->sclass);
        }

        // API accessible to authors and administrators
        if (!$prow->has_author($user) && !$user->is_admin($prow)) {
            return JsonResult::make_permission_error("p");
        }

        // authors can access API only if PC conflicts field exists
        $pcconf = $prow->conf->option_by_id(PaperOption::PCCONFID);
        if (!$pcconf->test_exists($prow)
            || !$user->allow_view_option($prow, $pcconf)) {
            return JsonResult::make_permission_error("p", "<0>PC conflicts not visible");
        }

        // apply changes
        $ps = new PaperStatus($user);
        $ps->set_ignore_errors(true);
        if (isset($qreq->json)) {
            $json = json_decode($qreq->json);
            if (!is_object($json)) {
                return JsonResult::make_parameter_error("json");
            }
            $njson = (object) [];
            if (isset($json->authors)) {
                $njson->authors = $json->authors;
            }
            if (isset($json->collaborators)) {
                $njson->collaborators = $json->collaborators;
            }
            if (count((array) $njson) > 0) {
                $ps->prepare_save_paper_json($njson, $prow);
            }
        } else {
            $nqreq = new Qrequest("POST");
            $hasau = false;
            foreach ($qreq as $k => $v) {
                if ($k === "collaborators") {
                    $nqreq[$k] = $v;
                } else if (str_starts_with($k, "authors:")) {
                    $nqreq[$k] = $v;
                    $hasau = true;
                }
            }
            if ($hasau) {
                $nqreq["has_authors"] = "1";
            }
            if ($nqreq->count() > 0) {
                $ps->prepare_save_paper_web($nqreq, $prow);
            }
        }

        // compute potential conflict list
        $potconfs = [];
        foreach ($prow->conf->pc_members() as $pcm) {
            if ($prow->has_author($pcm)) {
                $potconfs[] = (object) [
                    "uid" => $pcm->contactId,
                    "email" => $pcm->email,
                    "type" => "author"
                ];
            } else if (($potconf = $prow->potential_conflict_list($pcm))) {
                $potconfs[] = (object) [
                    "uid" => $pcm->contactId,
                    "email" => $pcm->email,
                    "type" => "potentialconflict",
                    "description" => "<0>" . $potconf->description_text(),
                    "tooltip" => "<5>" . $potconf->tooltip_html($prow)
                ];
            }
        }

        $prow->abort_prop();
        return new JsonResult([
            "ok" => true,
            "potential_conflicts" => $potconfs
        ]);
    }
}
