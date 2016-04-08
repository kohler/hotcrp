<?php
// search/sa_get_authors.php -- HotCRP helper class for paper search actions
// HotCRP is Copyright (c) 2006-2016 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

class GetAbstract_SearchAction extends SearchAction {
    function run(Contact $user, $qreq, $ssel) {
        global $Conf;
        $result = Dbl::qe_raw($Conf->paperQuery($user, array("paperId" => $ssel->selection(), "topics" => 1)));
        $texts = array();
        while ($prow = PaperInfo::fetch($result, $user)) {
            if (($whyNot = $user->perm_view_paper($prow)))
                Conf::msg_error(whyNotText($whyNot, "view"));
            else {
                $text = "===========================================================================\n";
                $n = "Paper #" . $prow->paperId . ": ";
                $l = max(14, (int) ((75.5 - strlen($prow->title) - strlen($n)) / 2) + strlen($n));
                $text .= prefix_word_wrap($n, $prow->title, $l);
                $text .= "---------------------------------------------------------------------------\n";
                $l = strlen($text);
                if ($user->can_view_authors($prow, $qreq->t == "a"))
                    $text .= prefix_word_wrap("Authors: ", $prow->pretty_text_author_list(), 14);
                if ($prow->topicIds != ""
                    && ($tt = $prow->unparse_topics_text()))
                    $text .= prefix_word_wrap("Topics: ", $tt, 14);
                if ($l != strlen($text))
                    $text .= "---------------------------------------------------------------------------\n";
                $text .= rtrim($prow->abstract) . "\n\n";
                defappend($texts[$prow->paperId], $text);
                $rfSuffix = (count($texts) == 1 ? $prow->paperId : "s");
            }
        }

        if (count($texts))
            downloadText(join("", $ssel->reorder($texts)), "abstract$rfSuffix");
    }
}

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

class GetTopics_SearchAction extends SearchAction {
    function run(Contact $user, $qreq, $ssel) {
        global $Conf;
        $result = Dbl::qe_raw($Conf->paperQuery($user, array("paperId" => $ssel->selection(), "topics" => 1)));
        $texts = array();
        $tmap = $Conf->topic_map();
        while (($row = PaperInfo::fetch($result, $user)))
            if ($user->can_view_paper($row)) {
                $out = array();
                foreach ($row->topics() as $t)
                    $out[] = [$row->paperId, $row->title, $tmap[$t]];
                if (!count($out))
                    $out[] = [$row->paperId, $row->title, "<none>"];
                arrayappend($texts[$row->paperId], $out);
            }
        downloadCSV($ssel->reorder($texts), array("paper", "title", "topic"), "topics");
    }
}

SearchActions::register("get", "abstract", SiteLoader::API_GET | SiteLoader::API_PAPER, new GetAbstract_SearchAction);
SearchActions::register("get", "authors", SiteLoader::API_GET | SiteLoader::API_PAPER, new GetAuthors_SearchAction);
SearchActions::register("get", "topics", SiteLoader::API_GET | SiteLoader::API_PAPER, new GetTopics_SearchAction);
