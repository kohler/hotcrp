<?php
// search/sa_get_authors.php -- HotCRP helper class for paper search actions
// HotCRP is Copyright (c) 2006-2016 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

class GetAuthors_SearchAction extends SearchAction {
    function run(Contact $user, $qreq, $ssel) {
        global $Conf;
        if (!($user->privChair || ($user->isPC && !$Conf->subBlindAlways())))
            return self::EPERM;

        // first fetch contacts if chair
        $contactline = array();
        if ($user->privChair) {
            $result = Dbl::qe_raw("select Paper.paperId, title, firstName, lastName, email, affiliation from Paper join PaperConflict on (PaperConflict.paperId=Paper.paperId and PaperConflict.conflictType>=" . CONFLICT_AUTHOR . ") join ContactInfo on (ContactInfo.contactId=PaperConflict.contactId) where Paper.paperId" . $ssel->sql_predicate());
            while (($row = edb_orow($result))) {
                $key = $row->paperId . " " . $row->email;
                if ($row->firstName && $row->lastName)
                    $a = $row->firstName . " " . $row->lastName;
                else
                    $a = $row->firstName . $row->lastName;
                $contactline[$key] = array($row->paperId, $row->title, $a, $row->email, $row->affiliation, "contact_only");
            }
        }

        $result = Dbl::qe_raw($Conf->paperQuery($user, array("paperId" => $ssel->selection())));
        $texts = array();
        while (($prow = PaperInfo::fetch($result, $user))) {
            if (!$user->can_view_authors($prow, true))
                continue;
            foreach ($prow->author_list() as $au) {
                $line = array($prow->paperId, $prow->title, $au->name(), $au->email, $au->affiliation);

                if ($user->privChair) {
                    $key = $au->email ? $prow->paperId . " " . $au->email : "XXX";
                    if (isset($contactline[$key])) {
                        unset($contactline[$key]);
                        $line[] = "contact_author";
                    } else
                        $line[] = "author";
                }

                arrayappend($texts[$prow->paperId], $line);
            }
        }

        // If chair, append the remaining non-author contacts
        if ($user->privChair)
            foreach ($contactline as $key => $line) {
                $paperId = (int) $key;
                arrayappend($texts[$paperId], $line);
            }

        $header = array("paper", "title", "name", "email", "affiliation");
        if ($user->privChair)
            $header[] = "type";
        downloadCSV($ssel->reorder($texts), $header, "authors");
    }
}

SearchActions::register("get", "authors", SiteLoader::API_GET | SiteLoader::API_PAPER, new GetAuthors_SearchAction);
