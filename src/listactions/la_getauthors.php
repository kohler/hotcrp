<?php
// listactions/la_getauthors.php -- HotCRP helper classes for list actions
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class GetAuthors_ListAction extends ListAction {
    function allow(Contact $user, Qrequest $qreq) {
        return $user->can_view_some_authors();
    }
    function run(Contact $user, Qrequest $qreq, SearchSelection $ssel) {
        $conf = $user->conf;
        $prows = $ssel->paper_set($user, ["allConflictType" => 1]);
        $prows->apply_filter(function ($prow) use ($user) {
            return $user->allow_view_authors($prow);
        });
        foreach ($prows as $prow) {
            foreach ($prow->author_list() as $au) {
                if ($au->email) {
                    $conf->prefetch_user_by_email($au->email);
                    $conf->prefetch_cdb_user_by_email($au->email);
                }
            }
        }
        $texts = [];
        $has_iscontact = $has_country = $has_orcid = false;
        foreach ($prows as $prow) {
            $admin = $user->allow_administer($prow);
            $aucid = [];
            foreach ($prow->author_list() as $au) {
                $line = [
                    "paper" => $prow->paperId,
                    "title" => $prow->title,
                    "given_name" => $au->firstName,
                    "family_name" => $au->lastName,
                    "email" => $au->email,
                    "affiliation" => $au->affiliation
                ];
                $lemail = strtolower($au->email);
                if ($au->email !== ""
                    && ($u = $conf->user_by_email($au->email))) {
                    $line["orcid"] = $u->decorated_orcid();
                    $line["country"] = $u->country_code();
                    $has_orcid = $has_orcid || $line["orcid"] !== "";
                    $has_country = $has_country || $line["country"] !== "";
                    if ($admin) {
                        $line["iscontact"] = "yes";
                        $has_iscontact = true;
                    }
                    $aucid[$u->contactId] = true;
                }
                $texts[] = $line;
            }
            if ($admin) {
                foreach ($prow->contact_list() as $u) {
                    if (isset($aucid[$u->contactId])) {
                        continue;
                    }
                    $texts[] = $line = [
                        "paper" => $prow->paperId,
                        "title" => $prow->title,
                        "given_name" => $u->firstName,
                        "family_name" => $u->lastName,
                        "email" => $u->email,
                        "affiliation" => $u->affiliation,
                        "orcid" => $u->decorated_orcid(),
                        "country" => $u->country_code(),
                        "iscontact" => "nonauthor"
                    ];
                    $has_orcid = $has_orcid || $line["orcid"] !== "";
                    $has_country = $has_country || $line["country"] !== "";
                    $has_iscontact = true;
                }
            }
        }
        $header = ["paper", "title", "given_name", "family_name", "email", "affiliation"];
        if ($has_orcid) {
            $header[] = "orcid";
        }
        if ($has_country) {
            $header[] = "country";
        }
        if ($has_iscontact) {
            $header[] = "iscontact";
        }
        return $user->conf->make_csvg("authors")->select($header)->append($texts);
    }
}
