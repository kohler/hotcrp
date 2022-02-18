<?php
// listactions/la_getauthors.php -- HotCRP helper classes for list actions
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class GetAuthors_ListAction extends ListAction {
    /** @return array<mixed,Contact> */
    static function contact_map(Conf $conf, SearchSelection $ssel) {
        $result = $conf->qe_raw("select ContactInfo.contactId, firstName, lastName, affiliation, email, country, roles, contactTags, primaryContactId from ContactInfo join PaperConflict on (PaperConflict.contactId=ContactInfo.contactId) where conflictType>=" . CONFLICT_AUTHOR . " and paperId" . $ssel->sql_predicate() . " group by ContactInfo.contactId");
        $users = [];
        while (($u = Contact::fetch($result, $conf))) {
            $users[strtolower($u->email)] = $users[$u->contactId] = $u;
        }
        return $users;
    }
    function allow(Contact $user, Qrequest $qreq) {
        return $user->can_view_some_authors();
    }
    function run(Contact $user, Qrequest $qreq, SearchSelection $ssel) {
        $texts = [];
        $users = null;
        $has_iscontact = $has_country = false;
        foreach ($ssel->paper_set($user, ["allConflictType" => 1]) as $prow) {
            if (!$user->allow_view_authors($prow)) {
                continue;
            }
            if ($users === null) {
                $users = self::contact_map($user->conf, $ssel);
                foreach ($users as $u) {
                    $user->conf->prefetch_cdb_user_by_email($u->email);
                }
            }
            $admin = $user->allow_administer($prow);
            $aucid = [];
            foreach ($prow->author_list() as $au) {
                $line = [
                    "paper" => $prow->paperId,
                    "title" => $prow->title,
                    "first" => $au->firstName,
                    "last" => $au->lastName,
                    "email" => $au->email,
                    "affiliation" => $au->affiliation
                ];
                $lemail = strtolower($au->email);
                if ($lemail !== "" && ($u = $users[$lemail] ?? null)) {
                    $line["country"] = $u->country();
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
                foreach ($prow->contacts() as $cid => $c) {
                    if (!isset($aucid[$cid])) {
                        $u = $users[$cid];
                        $texts[] = $line = [
                            "paper" => $prow->paperId,
                            "title" => $prow->title,
                            "first" => $u->firstName,
                            "last" => $u->lastName,
                            "email" => $u->email,
                            "affiliation" => $u->affiliation,
                            "country" => $u->country(),
                            "iscontact" => "nonauthor"
                        ];
                        $has_country = $has_country || $line["country"] !== "";
                        $has_iscontact = true;
                    }
                }
            }
        }
        $header = ["paper", "title", "first", "last", "email", "affiliation"];
        if ($has_country) {
            $header[] = "country";
        }
        if ($has_iscontact) {
            $header[] = "iscontact";
        }
        return $user->conf->make_csvg("authors")->select($header)->append($texts);
    }
}
