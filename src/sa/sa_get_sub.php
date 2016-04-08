<?php
// sa/sa_get_sub.php -- HotCRP helper classes for search actions
// HotCRP is Copyright (c) 2006-2016 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

class GetDocument_SearchAction extends SearchAction {
    private $dt;
    public function __construct($dt) {
        $this->dt = $dt;
    }
    function run(Contact $user, $qreq, $ssel) {
        global $Conf;
        $result = Dbl::qe_raw($Conf->paperQuery($user, ["paperId" => $ssel->selection()]));
        $downloads = [];
        $opt = PaperOption::find_document($this->dt);
        while (($row = PaperInfo::fetch($result, $user)))
            if (($whyNot = $user->perm_view_paper_option($row, $opt, true)))
                Conf::msg_error(whyNotText($whyNot, "view"));
            else
                $downloads[] = $row->paperId;
        if (count($downloads)) {
            session_write_close(); // it can take a while to generate the download
            if ($Conf->downloadPaper($downloads, true, $this->dt))
                exit;
        }
        // XXX how to return errors?
    }
}

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
                $contactline[$key] = array($row->paperId, $row->title, $row->firstName, $row->lastName, $row->email, $row->affiliation, "contact_only");
            }
        }

        $result = Dbl::qe_raw($Conf->paperQuery($user, array("paperId" => $ssel->selection())));
        $texts = array();
        while (($prow = PaperInfo::fetch($result, $user))) {
            if (!$user->can_view_authors($prow, true))
                continue;
            foreach ($prow->author_list() as $au) {
                $line = array($prow->paperId, $prow->title, $au->firstName, $au->lastName, $au->email, $au->affiliation);

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

        $header = ["paper", "title", "first", "last", "email", "affiliation"];
        if ($user->privChair)
            $header[] = "type";
        downloadCSV($ssel->reorder($texts), $header, "authors");
    }
}

/* NB this search action is actually unavailable via the UI */
class GetContacts_SearchAction extends SearchAction {
    function run(Contact $user, $qreq, $ssel) {
        if (!$user->privChair)
            return self::EPERM;
        $result = Dbl::qe_raw("select Paper.paperId, title, firstName, lastName, email
    from Paper join PaperConflict on (PaperConflict.paperId=Paper.paperId and PaperConflict.conflictType>=" . CONFLICT_AUTHOR . ")
    join ContactInfo on (ContactInfo.contactId=PaperConflict.contactId)
    where Paper.paperId" . $ssel->sql_predicate() . " order by Paper.paperId");
        $texts = [];
        while (($row = edb_row($result)))
            arrayappend($texts[$row[0]], $row);
        downloadCSV($ssel->reorder($texts), ["paper", "title", "first", "last", "email"], "contacts");
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

SearchActions::register("get", "paper", SiteLoader::API_GET | SiteLoader::API_PAPER, new GetDocument_SearchAction(DTYPE_SUBMISSION));
SearchActions::register("get", "final", SiteLoader::API_GET | SiteLoader::API_PAPER, new GetDocument_SearchAction(DTYPE_FINAL));
foreach (PaperOption::option_list() as $o)
    if ($o->is_document())
        SearchActions::register("get", "opt-{$o->abbr}", SiteLoader::API_GET | SiteLoader::API_PAPER, new GetDocument_SearchAction($o->id));
SearchActions::register("get", "abstract", SiteLoader::API_GET | SiteLoader::API_PAPER, new GetAbstract_SearchAction);
SearchActions::register("get", "authors", SiteLoader::API_GET | SiteLoader::API_PAPER, new GetAuthors_SearchAction);
SearchActions::register("get", "contact", SiteLoader::API_GET | SiteLoader::API_PAPER, new GetContacts_SearchAction);
SearchActions::register("get", "topics", SiteLoader::API_GET | SiteLoader::API_PAPER, new GetTopics_SearchAction);
