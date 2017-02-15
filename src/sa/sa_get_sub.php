<?php
// sa/sa_get_sub.php -- HotCRP helper classes for search actions
// HotCRP is Copyright (c) 2006-2017 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

class Get_SearchAction extends SearchAction {
    function list_actions(Contact $user, $qreq, PaperList $pl, &$actions) {
        $xactions = SearchAction::list_subactions("get", $user, $qreq, $pl);
        foreach ($user->user_option_list() as $o)
            if ($pl->has("opt{$o->id}") && $o->is_document())
                $xactions[] = GetDocument_SearchAction::make_option_action($o);
        usort($xactions, function ($a, $b) { return $a[0] - $b[0]; });
        $sel_opt = array();
        $last_group = null;
        foreach ($xactions as $xact) {
            if ($xact[2] !== $last_group) {
                $sel_opt[] = ["optgroup", $xact[2]];
                $last_group = $xact[2];
            }
            $sel_opt[] = ["value" => $xact[1], "label" => $xact[3]];
        }
        if (!empty($sel_opt)) {
            $actions[] = [0, "get", "Download", "<b>:</b> &nbsp;"
                . Ht::select("getfn", $sel_opt, $qreq->getfn,
                             ["tabindex" => 6, "class" => "want-focus", "style" => "max-width:10em"])
                . "&nbsp; " . Ht::submit("fn", "Go", ["value" => "get", "tabindex" => 6, "onclick" => "return plist_submit.call(this)", "data-plist-submit-all" => 1])];
        }
    }
    function run(Contact $user, $qreq, $ssel) {
        if (($opts = $user->conf->paper_opts->search($qreq->getfn))
            && count($opts) == 1
            && ($o = current($opts))
            && $user->can_view_some_paper_option($o)) {
            $ga = new GetDocument_SearchAction($o->id);
            return $ga->run($user, $qreq, $ssel);
        } else
            return self::ENOENT;
    }
}

class GetDocument_SearchAction extends SearchAction {
    private $dt;
    public function __construct($dt) {
        $this->dt = $dt;
    }
    static function make_option_action(PaperOption $opt) {
        return [$opt->position + ($opt->final ? 0 : 100),
                $opt->abbreviation(),
                "Documents", $opt->id <= 0 ? pluralize($opt->name) : $opt->name];
    }
    function list_actions(Contact $user, $qreq, PaperList $pl, &$actions) {
        $opt = $user->conf->paper_opts->find_document($this->dt);
        if ($user->can_view_some_paper_option($opt) && $pl->has($opt->field_key()))
            $actions[] = self::make_option_action($opt);
    }
    static function error_document(PaperOption $opt, PaperInfo $row, $error_html = "") {
        if (!$error_html)
            $error_html = $row->conf->_("Submission #%d has no %s.", $row->paperId, $opt->message_name);
        $x = new DocumentInfo(["documentType" => $opt->id, "paperId" => $row->paperId, "error" => true, "error_html" => $error_html], $row->conf);
        if (($mimetypes = $opt->mimetypes()) && count($mimetypes) == 1)
            $x->mimetype = $mimetypes[0]->mimetype;
        return $x;
    }
    function run(Contact $user, $qreq, $ssel) {
        $result = $user->paper_result(["paperId" => $ssel->selection()]);
        $downloads = $errors = [];
        $opt = $user->conf->paper_opts->find_document($this->dt);
        while (($row = PaperInfo::fetch($result, $user)))
            if (($whyNot = $user->perm_view_paper_option($row, $opt, true)))
                $errors[] = self::error_document($opt, $row, whyNotText($whyNot, "view"));
            else if (($doc = $row->document($opt->id)))
                $downloads[] = $doc;
            else
                $errors[] = self::error_document($opt, $row);
        if (count($downloads)) {
            session_write_close(); // it can take a while to generate the download
            $downloads = array_merge($downloads, $errors);
            if ($user->conf->download_documents($downloads, true))
                exit;
        } else if (count($errors))
            Conf::msg_error("Nothing to download.<br />" . join("<br />", array_map(function ($ed) { return $ed->error_html; }, $errors)));
        // XXX how to return errors?
    }
}

class GetCheckFormat_SearchAction extends SearchAction {
    function list_actions(Contact $user, $qreq, PaperList $pl, &$actions) {
        if ($user->is_manager() && $pl->has("paper"))
            $actions[] = [999, $this->subname, "Documents", "Format check"];
    }
    function run(Contact $user, $qreq, $ssel) {
        $result = $user->paper_result(["paperId" => $ssel->selection()]);
        $papers = [];
        while (($prow = PaperInfo::fetch($result, $user)))
            if ($user->can_view_pdf($prow))
                $papers[$prow->paperId] = $prow;
        $csvg = downloadCSV(false, ["paper", "title", "pages", "format"], "formatcheck");
        echo $csvg->headerline;
        $cf = new CheckFormat;
        foreach ($ssel->reorder($papers) as $prow) {
            $pages = "?";
            if ($prow->mimetype == "application/pdf") {
                $dtype = $prow->finalPaperStorageId ? DTYPE_FINAL : DTYPE_SUBMISSION;
                if (($doc = $cf->fetch_document($prow, $dtype)))
                    $cf->check_document($prow, $doc);
                if ($doc && !$cf->failed) {
                    $errf = $cf->problem_fields();
                    $format = empty($errf) ? "ok" : join(",", $errf);
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
    function list_actions(Contact $user, $qreq, PaperList $pl, &$actions) {
        if ($pl->has("abstract"))
            $actions[] = [1000, $this->subname, "Paper information", "Abstracts"];
    }
    function run(Contact $user, $qreq, $ssel) {
        $result = $user->paper_result(["paperId" => $ssel->selection(), "topics" => 1]);
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
    static public function contact_map(Conf $conf, $ssel) {
        $result = $conf->qe_raw("select ContactInfo.contactId, firstName, lastName, affiliation, email from ContactInfo join PaperConflict on (PaperConflict.contactId=ContactInfo.contactId) where conflictType>=" . CONFLICT_AUTHOR . " and paperId" . $ssel->sql_predicate() . " group by ContactInfo.contactId");
        $contact_map = [];
        while (($row = edb_orow($result))) {
            $row->contactId = (int) $row->contactId;
            $contact_map[$row->contactId] = $row;
        }
        return $contact_map;
    }
    function allow(Contact $user) {
        return $user->can_view_some_authors();
    }
    function list_actions(Contact $user, $qreq, PaperList $pl, &$actions) {
        $actions[] = [1001, $this->subname, "Paper information", $user->is_manager() ? "Authors &amp; contacts" : "Authors"];
    }
    function run(Contact $user, $qreq, $ssel) {
        $contact_map = self::contact_map($user->conf, $ssel);
        $result = $user->paper_result(["paperId" => $ssel->selection(), "allConflictType" => 1]);
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
        return new Csv_SearchResult("authors", $header, $ssel->reorder($texts));
    }
}

/* NB this search action is actually unavailable via the UI */
class GetContacts_SearchAction extends SearchAction {
    function allow(Contact $user) {
        return $user->is_manager();
    }
    function run(Contact $user, $qreq, $ssel) {
        $contact_map = GetAuthors_SearchAction::contact_map($user->conf, $ssel);
        $result = $user->paper_result(["paperId" => $ssel->selection(), "allConflictType" => 1]);
        while (($prow = PaperInfo::fetch($result, $user)))
            if ($user->can_administer($prow, true))
                foreach ($prow->contacts() as $cid => $c) {
                    $a = $contact_map[$cid];
                    $aa = $prow->author_by_email($a->email) ? : $a;
                    arrayappend($texts[$prow->paperId], [$prow->paperId, $prow->title, $aa->firstName, $aa->lastName, $aa->email, $aa->affiliation]);
                }
        return new Csv_SearchResult("contacts", ["paper", "title", "first", "last", "email", "affiliation"], $ssel->reorder($texts));
    }
}

class GetPcconflicts_SearchAction extends SearchAction {
    function allow(Contact $user) {
        return $user->is_manager();
    }
    function list_actions(Contact $user, $qreq, PaperList $pl, &$actions) {
        $actions[] = [1060, $this->subname, "Paper information", "PC conflicts"];
    }
    function run(Contact $user, $qreq, $ssel) {
        $allConflictTypes = Conflict::$type_descriptions;
        $allConflictTypes[CONFLICT_CHAIRMARK] = "Chair-confirmed";
        $allConflictTypes[CONFLICT_AUTHOR] = "Author";
        $allConflictTypes[CONFLICT_CONTACTAUTHOR] = "Contact";
        $result = $user->paper_result(["paperId" => $ssel->selection(), "allConflictType" => 1]);
        $pcm = $user->conf->pc_members();
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
        return new Csv_SearchResult("pcconflicts", ["paper", "title", "first", "last", "email", "conflicttype"], $ssel->reorder($texts));
    }
}

class GetTopics_SearchAction extends SearchAction {
    function list_actions(Contact $user, $qreq, PaperList $pl, &$actions) {
        if ($user->conf->has_topics())
            $actions[] = [1050, $this->subname, "Paper information", "Topics"];
    }
    function run(Contact $user, $qreq, $ssel) {
        $result = $user->paper_result(array("paperId" => $ssel->selection(), "topics" => 1));
        $texts = array();
        $tmap = $user->conf->topic_map();
        while (($row = PaperInfo::fetch($result, $user)))
            if ($user->can_view_paper($row)) {
                $out = array();
                foreach ($row->topics() as $t)
                    $out[] = [$row->paperId, $row->title, $tmap[$t]];
                if (!count($out))
                    $out[] = [$row->paperId, $row->title, "<none>"];
                arrayappend($texts[$row->paperId], $out);
            }
        return new Csv_SearchResult("topics", ["paper", "title", "topic"], $ssel->reorder($texts));
    }
}

class GetCSV_SearchAction extends SearchAction {
    function list_actions(Contact $user, $qreq, PaperList $pl, &$actions) {
        $actions[] = [1089, $this->subname, "Paper information", "CSV"];
    }
    function run(Contact $user, $qreq, $ssel) {
        $search = new PaperSearch($user, $qreq, $qreq->attachment("reviewer_contact"));
        $pl = new PaperList($search, ["sort" => true, "display" => $qreq->display], $qreq);
        $pl->set_selection($ssel, true);
        $pl->set_view("sel", false);
        list($header, $data) = $pl->text_csv($search->limitName);
        return new Csv_SearchResult("data", $header, $data);
    }
}

SearchAction::register("get", null, SiteLoader::API_GET | SiteLoader::API_PAPER, new Get_SearchAction);
SearchAction::register("get", "paper", SiteLoader::API_GET | SiteLoader::API_PAPER, new GetDocument_SearchAction(DTYPE_SUBMISSION));
SearchAction::register("get", "final", SiteLoader::API_GET | SiteLoader::API_PAPER, new GetDocument_SearchAction(DTYPE_FINAL));
SearchAction::register("get", "checkformat", SiteLoader::API_GET | SiteLoader::API_PAPER, new GetCheckFormat_SearchAction);
SearchAction::register("get", "abstract", SiteLoader::API_GET | SiteLoader::API_PAPER, new GetAbstract_SearchAction);
SearchAction::register("get", "authors", SiteLoader::API_GET | SiteLoader::API_PAPER, new GetAuthors_SearchAction);
SearchAction::register("get", "contact", SiteLoader::API_GET | SiteLoader::API_PAPER, new GetContacts_SearchAction);
SearchAction::register("get", "pcconf", SiteLoader::API_GET | SiteLoader::API_PAPER, new GetPcconflicts_SearchAction);
SearchAction::register("get", "csv", SiteLoader::API_GET | SiteLoader::API_PAPER, new GetCSV_SearchAction);
