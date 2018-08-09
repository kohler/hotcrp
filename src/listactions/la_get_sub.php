<?php
// listactions/la_get_sub.php -- HotCRP helper classes for list actions
// Copyright (c) 2006-2018 Eddie Kohler; see LICENSE.

class Get_ListAction extends ListAction {
    static function render(PaperList $pl) {
        $actions = array_values($pl->displayable_list_actions("get/"));
        foreach ($pl->user->user_option_list() as $o)
            if ($pl->user->can_view_some_paper_option($o)
                && $o->is_document()
                && $pl->has($o->field_key()))
                $actions[] = GetDocument_ListAction::make_list_action($o);
        usort($actions, "Conf::xt_position_compare");
        $last_group = null;
        foreach ($actions as $fj) {
            $as = strpos($fj->selector, "/");
            if ($as === false) {
                if ($last_group)
                    $sel_opt[] = ["optgroup", false];
                $last_group = null;
                $sel_opt[] = ["value" => substr($fj->name, 4), "label" => $fj->selector];
            } else {
                $group = substr($fj->selector, 0, $as);
                if ($group !== $last_group) {
                    $sel_opt[] = ["optgroup", $group];
                    $last_group = $group;
                }
                $sel_opt[] = ["value" => substr($fj->name, 4), "label" => substr($fj->selector, $as + 1)];
            }
        }
        if (!empty($sel_opt)) {
            return Ht::select("getfn", $sel_opt, $pl->qreq->getfn,
                              ["class" => "want-focus js-submit-action-info-get", "style" => "max-width:10em"])
                . "&nbsp; " . Ht::submit("fn", "Go", ["value" => "get", "data-default-submit-all" => 1, "class" => "btn uix js-submit-mark"]);
        } else
            return null;
    }
    function run(Contact $user, $qreq, $ssel) {
        if (($opts = $user->conf->paper_opts->find_all($qreq->getfn))
            && count($opts) == 1
            && ($o = current($opts))
            && $user->can_view_some_paper_option($o)) {
            $ga = new GetDocument_ListAction($o->id);
            return $ga->run($user, $qreq, $ssel);
        } else
            return self::ENOENT;
    }
}

class GetCheckFormat_ListAction extends ListAction {
    function run(Contact $user, $qreq, $ssel) {
        $papers = [];
        foreach ($user->paper_set($ssel) as $prow)
            if ($user->can_view_pdf($prow))
                $papers[$prow->paperId] = $prow;
        $csvg = $user->conf->make_csvg("formatcheck")->select(["paper", "title", "pages", "format"]);
        $csvg->download_headers();
        echo $csvg->headerline;
        $cf = new CheckFormat($user->conf);
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

class GetAbstract_ListAction extends ListAction {
    const WIDTH = 96;
    static private function render_option(PaperOption $o, $otxt) {
        $dtype = array_shift($otxt);
        if ($dtype === PaperOption::PAGE_HTML_NAME)
            $n = join(" ", $otxt);
        else
            $n = $o->title;
        $text = prefix_word_wrap("", $n, 0, self::WIDTH);
        $text .= str_repeat("-", min(self::WIDTH, strlen($text) - 1)) . "\n";
        if ($dtype === PaperOption::PAGE_HTML_DATA && !empty($otxt)) {
            if (count($otxt) === 1)
                $text .= rtrim($otxt[0]);
            else
                $text .= join("", array_map(function ($t) { return "* " . rtrim($t) . "\n"; }, $otxt));
            $text .= "\n";
        }
        return $text . "\n";
    }
    static function render_displayed_options(PaperInfo $prow, Contact $user, $display) {
        $text = "";
        foreach ($prow->options() as $ov) {
            if ($ov->option->display() === $display
                && $user->can_view_paper_option($prow, $ov->option)
                && ($otxt = $ov->option->unparse_page_text($prow, $ov)))
                $text .= self::render_option($ov->option, $otxt);
        }
        return $text;
    }
    static function render(PaperInfo $prow, Contact $user) {
        $n = prefix_word_wrap("", "Submission #{$prow->paperId}: {$prow->title}", 0, self::WIDTH);
        $text = $n . str_repeat("=", min(self::WIDTH, strlen($n) - 1)) . "\n\n";

        $text .= self::render_displayed_options($prow, $user, PaperOption::DISP_SUBMISSION);

        if ($user->can_view_authors($prow) && ($alist = $prow->author_list())) {
            if (count($alist) == 1)
                $text .= "Author\n------\n"
                    . prefix_word_wrap("", $alist[0]->name_email_aff_text(), 0, self::WIDTH);
            else {
                $text .= "Authors\n-------\n";
                foreach ($alist as $i => $au) {
                    $marker = ($i + 1) . ". ";
                    $text .= prefix_word_wrap($marker, $au->name_email_aff_text(), strlen($marker), self::WIDTH);
                }
            }
            $text .= "\n";
        }

        if ($prow->abstract)
            $text .= "Abstract\n--------\n" . rtrim($prow->abstract) . "\n\n";

        $text .= self::render_displayed_options($prow, $user, PaperOption::DISP_PROMINENT);

        if (($tlist = $prow->named_topic_map())) {
            $text .= "Topics\n------\n";
            foreach ($tlist as $t)
                $text .= prefix_word_wrap("* ", $t, 2, self::WIDTH);
            $text .= "\n";
        }

        $text .= self::render_displayed_options($prow, $user, PaperOption::DISP_TOPICS);

        return $text . "\n";
    }
    function run(Contact $user, $qreq, $ssel) {
        $texts = array();
        foreach ($user->paper_set($ssel, ["topics" => 1]) as $prow) {
            if (($whyNot = $user->perm_view_paper($prow)))
                Conf::msg_error(whyNotText($whyNot));
            else {
                defappend($texts[$prow->paperId], $this->render($prow, $user));
                $rfSuffix = (count($texts) == 1 ? $prow->paperId : "s");
            }
        }
        if (count($texts))
            downloadText(join("", $ssel->reorder($texts)), "abstract$rfSuffix");
    }
}

class GetAuthors_ListAction extends ListAction {
    static function contact_map(Conf $conf, $ssel) {
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
    function run(Contact $user, $qreq, $ssel) {
        $contact_map = self::contact_map($user->conf, $ssel);
        $texts = array();
        $want_contacttype = false;
        foreach ($user->paper_set($ssel, ["allConflictType" => 1]) as $prow) {
            if (!$user->allow_view_authors($prow))
                continue;
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
        return $user->conf->make_csvg("authors")->select($header)
            ->add($ssel->reorder($texts));
    }
}

/* NB this search action is actually unavailable via the UI */
class GetContacts_ListAction extends ListAction {
    function allow(Contact $user) {
        return $user->is_manager();
    }
    function run(Contact $user, $qreq, $ssel) {
        $contact_map = GetAuthors_ListAction::contact_map($user->conf, $ssel);
        foreach ($user->paper_set($ssel, ["allConflictType" => 1]) as $prow)
            if ($user->allow_administer($prow))
                foreach ($prow->contacts() as $cid => $c) {
                    $a = $contact_map[$cid];
                    $aa = $prow->author_by_email($a->email) ? : $a;
                    arrayappend($texts[$prow->paperId], [$prow->paperId, $prow->title, $aa->firstName, $aa->lastName, $aa->email, $aa->affiliation]);
                }
        return $user->conf->make_csvg("contacts")
            ->select(["paper", "title", "first", "last", "email", "affiliation"])
            ->add($ssel->reorder($texts));
    }
}

class GetPcconflicts_ListAction extends ListAction {
    function allow(Contact $user) {
        return $user->is_manager();
    }
    function run(Contact $user, $qreq, $ssel) {
        $allConflictTypes = Conflict::$type_descriptions;
        $allConflictTypes[CONFLICT_CHAIRMARK] = "Chair-confirmed";
        $allConflictTypes[CONFLICT_AUTHOR] = "Author";
        $allConflictTypes[CONFLICT_CONTACTAUTHOR] = "Contact";
        $pcm = $user->conf->pc_members();
        $texts = array();
        $old_overrides = $user->add_overrides(Contact::OVERRIDE_CONFLICT);
        foreach ($user->paper_set($ssel, ["allConflictType" => 1]) as $prow) {
            if ($user->can_view_conflicts($prow)) {
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
        }
        $user->set_overrides($old_overrides);
        return $user->conf->make_csvg("pcconflicts")
            ->select(["paper", "title", "first", "last", "email", "conflicttype"])
            ->add($ssel->reorder($texts));
    }
}

class GetTopics_ListAction extends ListAction {
    function run(Contact $user, $qreq, $ssel) {
        $texts = array();
        foreach ($user->paper_set($ssel, ["topics" => 1]) as $row)
            if ($user->can_view_paper($row)) {
                $out = array();
                foreach ($row->named_topic_map() as $t)
                    $out[] = [$row->paperId, $row->title, $t];
                if (empty($out))
                    $out[] = [$row->paperId, $row->title, "<none>"];
                arrayappend($texts[$row->paperId], $out);
            }
        return $user->conf->make_csvg("topics")
            ->select(["paper", "title", "topic"])
            ->add($ssel->reorder($texts));
    }
}

class GetCSV_ListAction extends ListAction {
    function run(Contact $user, $qreq, $ssel) {
        $search = new PaperSearch($user, $qreq);
        $search->restrict_match([$ssel, "is_selected"]);
        $pl = new PaperList($search, ["sort" => true, "report" => "pl", "display" => $qreq->display], $qreq);
        $pl->set_view("sel", false);
        list($header, $data) = $pl->text_csv($qreq->t);
        return $user->conf->make_csvg("data", CsvGenerator::FLAG_ITEM_COMMENTS)
            ->select($header)->add($data);
    }
}
