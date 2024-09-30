<?php
// listactions/la_getjsonrqc.php -- HotCRP helper classes for list actions
// Copyright (c) 2006-2023 Eddie Kohler; see LICENSE.

class GetJsonRQC_ListAction extends ListAction {
    function allow(Contact $user, Qrequest $qreq) {
        return $user->is_manager();
    }
    function run(Contact $user, Qrequest $qreq, SearchSelection $ssel) {
        $old_overrides = $user->add_overrides(Contact::OVERRIDE_CONFLICT);
        $results = ["hotcrp_version" => HOTCRP_VERSION];
        if (($git_data = Conf::git_status())) {
            $results["hotcrp_commit"] = $git_data[0];
        }
        $rf = $user->conf->review_form();
        $fj = [];
        foreach ($rf->bound_viewable_fields(VIEWSCORE_REVIEWERONLY) as $f) {
            $fj[$f->uid()] = $f->export_json(ReviewField::UJ_EXPORT);
        }
        $results["reviewform"] = $fj;
        $pj = [];
        $pex = new PaperExport($user);
        $pex->set_include_permissions(false);
        $pex->set_override_ratings(true);
        foreach ($ssel->paper_set($user, ["topics" => true, "options" => true]) as $prow) {
            if ($user->allow_administer($prow)) {
                $pj[] = $j = $pex->paper_json($prow);
                $prow->ensure_full_reviews();
                foreach ($prow->viewable_reviews_as_display($user) as $rrow) {
                    if ($rrow->reviewSubmitted) {
                        $j->reviews[] = $pex->review_json($prow, $rrow);
                    }
                }
            } else {
                $pj[] = (object) ["pid" => $prow->paperId, "error" => "You don’t have permission to administer this paper."];
            }
        }
        $user->set_overrides($old_overrides);
        $results["papers"] = $pj;
        $dopt = new Downloader;
        $dopt->set_attachment(true);
        $dopt->set_filename($user->conf->download_prefix . "rqc.json");
        $dopt->set_mimetype(Mimetype::JSON_UTF8_TYPE);
        $dopt->set_content(json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n");
        return $dopt;
    }
}
