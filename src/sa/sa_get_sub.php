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

class GetCheckFormat_SearchAction extends SearchAction {
    function run(Contact $user, $qreq, $ssel) {
        global $Conf;
        $result = Dbl::qe_raw($Conf->paperQuery($user, ["paperId" => $ssel->selection()]));
        $papers = [];
        while (($prow = PaperInfo::fetch($result, $user)))
            if ($user->can_view_pdf($prow))
                $papers[$prow->paperId] = $prow;
        $csvg = downloadCSV(false, ["paper", "title", "pages", "format"], "formatcheck");
        echo $csvg->headerline;
        $format = $Conf->setting_data("sub_banal", "");
        foreach ($ssel->reorder($papers) as $prow) {
            $pages = "?";
            if ($prow->mimetype == "application/pdf") {
                $cf = new CheckFormat;
                $dtype = $prow->finalPaperStorageId ? DTYPE_FINAL : DTYPE_SUBMISSION;
                if ($cf->analyzePaper($prow->paperId, $dtype, $format)) {
                    $format = array();
                    foreach (CheckFormat::$error_types as $en => $etxt)
                        if ($cf->errors & $en)
                            $format[] = $etxt;
                    $format = (empty($format) ? "ok" : join(",", $format));
                    $pages = $cf->pages;
                } else
                    $format = "error";
            } else
                $format = "notpdf";
            echo $prow->paperId, ",", CsvGenerator::quote($prow->title), ",", $pages, ",", CsvGenerator::quote($format), "\n";
            ob_flush();
            flush();
        }
        exit;
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
    static public function contact_map($ssel) {
        $result = Dbl::qe_raw("select ContactInfo.contactId, firstName, lastName, affiliation, email from ContactInfo join PaperConflict on (PaperConflict.contactId=ContactInfo.contactId) where conflictType>=" . CONFLICT_AUTHOR . " and paperId" . $ssel->sql_predicate() . " group by ContactInfo.contactId");
        $contact_map = [];
        while (($row = edb_orow($result))) {
            $row->contactId = (int) $row->contactId;
            $contact_map[$row->contactId] = $row;
        }
        return $contact_map;
    }
    function run(Contact $user, $qreq, $ssel) {
        global $Conf;
        if (!($user->is_manager() || ($user->isPC && !$Conf->subBlindAlways())))
            return self::EPERM;
        $contact_map = self::contact_map($ssel);
        $result = Dbl::qe_raw($Conf->paperQuery($user, ["paperId" => $ssel->selection(), "allConflictType" => 1]));
        $texts = array();
        $want_contacttype = false;
        while (($prow = PaperInfo::fetch($result, $user))) {
            if (!$user->can_view_authors($prow, true))
                continue;
            $admin = $user->can_administer($prow, true);
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
                } else if ($admin)
                    $line[] = "no";
                arrayappend($texts[$prow->paperId], $line);
            }
            foreach ($contact_emails as $c)
                arrayappend($texts[$prow->paperId], [$prow->paperId, $prow->title, $c->firstName, $c->lastName, $c->email, $c->affiliation, "contact_only"]);
        }
        $header = ["paper", "title", "first", "last", "email", "affiliation"];
        if ($want_contacttype)
            $header[] = "iscontact";
        downloadCSV($ssel->reorder($texts), $header, "authors");
    }
}

/* NB this search action is actually unavailable via the UI */
class GetContacts_SearchAction extends SearchAction {
    function run(Contact $user, $qreq, $ssel) {
        global $Conf;
        if (!$user->is_manager())
            return self::EPERM;
        $contact_map = GetAuthors_SearchAction::contact_map($ssel);
        $result = Dbl::qe_raw($Conf->paperQuery($user, ["paperId" => $ssel->selection(), "allConflictType" => 1]));
        while (($prow = PaperInfo::fetch($result, $user)))
            if ($user->can_administer($prow, true))
                foreach ($prow->contacts() as $cid => $c) {
                    $a = $contact_map[$cid];
                    $aa = $prow->author_by_email($a->email) ? : $a;
                    arrayappend($texts[$prow->paperId], [$prow->paperId, $prow->title, $aa->firstName, $aa->lastName, $aa->email, $aa->affiliation]);
                }
        downloadCSV($ssel->reorder($texts), ["paper", "title", "first", "last", "email", "affiliation"], "contacts");
    }
}

class GetPcconflicts_SearchAction extends SearchAction {
    function run(Contact $user, $qreq, $ssel) {
        global $Conf;
        if (!$user->is_manager())
            return self::EPERM;
        $allConflictTypes = Conflict::$type_descriptions;
        $allConflictTypes[CONFLICT_CHAIRMARK] = "Chair-confirmed";
        $allConflictTypes[CONFLICT_AUTHOR] = "Author";
        $allConflictTypes[CONFLICT_CONTACTAUTHOR] = "Contact";
        $result = Dbl::qe_raw($Conf->paperQuery($user, ["paperId" => $ssel->selection(), "allConflictType" => 1]));
        $pcm = pcMembers();
        $texts = array();
        while (($prow = PaperInfo::fetch($result, $user)))
            if ($user->can_view_conflicts($prow, true)) {
                $m = [];
                foreach ($prow->conflicts() as $cid => $c)
                    if (isset($pcm[$cid])) {
                        $pc = $pcm[$cid];
                        $m[$pc->sort_position] = [$prow->paperId, $prow->title, $pc->firstName, $pc->lastName, $pc->email, get($allConflictTypes, $c->conflictType, "Conflict")];
                    }
                if ($m) {
                    ksort($m);
                    $texts[$prow->paperId] = $m;
                }
            }
        downloadCSV($ssel->reorder($texts), ["paper", "title", "first", "last", "email", "conflicttype"], "pcconflicts");
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
SearchActions::register("get", "checkformat", SiteLoader::API_GET | SiteLoader::API_PAPER, new GetCheckFormat_SearchAction);
SearchActions::register("get", "abstract", SiteLoader::API_GET | SiteLoader::API_PAPER, new GetAbstract_SearchAction);
SearchActions::register("get", "authors", SiteLoader::API_GET | SiteLoader::API_PAPER, new GetAuthors_SearchAction);
SearchActions::register("get", "contact", SiteLoader::API_GET | SiteLoader::API_PAPER, new GetContacts_SearchAction);
SearchActions::register("get", "pcconf", SiteLoader::API_GET | SiteLoader::API_PAPER, new GetPcconflicts_SearchAction);
SearchActions::register("get", "topics", SiteLoader::API_GET | SiteLoader::API_PAPER, new GetTopics_SearchAction);
