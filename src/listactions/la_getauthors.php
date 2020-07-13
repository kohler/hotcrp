<?php
// listactions/la_getauthors.php -- HotCRP helper classes for list actions
// Copyright (c) 2006-2020 Eddie Kohler; see LICENSE.

class GetAuthors_ListAction extends ListAction {
    static function contact_map(Conf $conf, $ssel) {
        $result = $conf->qe_raw("select ContactInfo.contactId, firstName, lastName, affiliation, email, roles, contactTags from ContactInfo join PaperConflict on (PaperConflict.contactId=ContactInfo.contactId) where conflictType>=" . CONFLICT_AUTHOR . " and paperId" . $ssel->sql_predicate() . " group by ContactInfo.contactId");
        $contact_map = [];
        while (($row = $result->fetch_object())) {
            $row->contactId = (int) $row->contactId;
            $contact_map[$row->contactId] = $row;
        }
        return $contact_map;
    }
    function allow(Contact $user, Qrequest $qreq) {
        return $user->can_view_some_authors();
    }
    function run(Contact $user, Qrequest $qreq, SearchSelection $ssel) {
        $contact_map = self::contact_map($user->conf, $ssel);
        $texts = array();
        $want_contacttype = false;
        foreach ($ssel->paper_set($user, ["allConflictType" => 1]) as $prow) {
            if (!$user->allow_view_authors($prow)) {
                continue;
            }
            $admin = $user->allow_administer($prow);
            $contact_emails = [];
            if ($admin) {
                $want_contacttype = true;
                foreach ($prow->contacts() as $cid => $c) {
                    $c = $contact_map[$cid];
                    $contact_emails[strtolower($c->email)] = $c;
                }
            }
            foreach ($prow->author_list() as $au) {
                $line = [$prow->paperId, $prow->title, $au->firstName, $au->lastName, $au->email, $au->affiliation];
                $lemail = strtolower($au->email);
                if ($admin && $lemail && isset($contact_emails[$lemail])) {
                    $line[] = "yes";
                    unset($contact_emails[$lemail]);
                } else if ($admin) {
                    $line[] = "no";
                }
                $texts[] = $line;
            }
            foreach ($contact_emails as $c) {
                $texts[] = [$prow->paperId, $prow->title, $c->firstName, $c->lastName, $c->email, $c->affiliation, "contact_only"];
            }
        }
        $header = ["paper", "title", "first", "last", "email", "affiliation"];
        if ($want_contacttype) {
            $header[] = "iscontact";
        }
        return $user->conf->make_csvg("authors")->select($header)->append($texts);
    }
}
