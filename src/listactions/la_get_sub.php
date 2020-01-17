<?php
// listactions/la_get_sub.php -- HotCRP helper classes for list actions
// Copyright (c) 2006-2019 Eddie Kohler; see LICENSE.

class Get_ListAction extends ListAction {
    static function render(PaperList $pl) {
        $actions = array_values($pl->displayable_list_actions("get/"));
        foreach ($pl->user->user_option_list() as $o) {
            if ($pl->user->can_view_some_option($o)
                && $o->is_document()
                && $pl->has($o->field_key()))
                $actions[] = GetDocument_ListAction::make_list_action($o);
        }
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
                . "&nbsp; " . Ht::submit("fn", "Go", ["value" => "get", "data-default-submit-all" => 1, "class" => "uix js-submit-mark"]);
        } else {
            return null;
        }
    }
    function run(Contact $user, $qreq, $ssel) {
        if (($opts = $user->conf->paper_opts->find_all($qreq->getfn))
            && count($opts) == 1
            && ($o = current($opts))
            && $user->can_view_some_option($o)) {
            $ga = new GetDocument_ListAction($o->id);
            return $ga->run($user, $qreq, $ssel);
        } else {
            return self::ENOENT;
        }
    }
}

class GetCheckFormat_ListAction extends ListAction {
    function run(Contact $user, $qreq, $ssel) {
        $papers = [];
        foreach ($user->paper_set($ssel) as $prow) {
            if ($user->can_view_pdf($prow))
                $papers[$prow->paperId] = $prow;
        }
        $csvg = $user->conf->make_csvg("formatcheck")->select(["paper", "title", "pages", "format"]);
        $csvg->download_headers();
        echo $csvg->headerline;
        $cf = new CheckFormat($user->conf, CheckFormat::RUN_PREFER_NO);
        foreach ($papers as $prow) {
            $pages = "?";
            if ($prow->mimetype == "application/pdf") {
                $dtype = $prow->finalPaperStorageId ? DTYPE_FINAL : DTYPE_SUBMISSION;
                if (($doc = $cf->fetch_document($prow, $dtype))) {
                    $cf->check_document($prow, $doc);
                }
                if ($doc && !$cf->failed) {
                    $errf = $cf->problem_fields();
                    $format = empty($errf) ? "ok" : join(",", $errf);
                    $pages = $cf->pages;
                } else {
                    $format = "error";
                }
            } else {
                $format = "notpdf";
            }
            echo $prow->paperId, ",", CsvGenerator::quote($prow->title), ",", $pages, ",", CsvGenerator::quote($format), "\n";
            ob_flush();
            flush();
        }
        exit;
    }
}

class GetAbstract_ListAction extends ListAction {
    const WIDTH = 96;
    private static function render_abstract($fr, $prow, $user, $o) {
        $fr->value = $prow->abstract;
        $fr->value_format = $prow->format_of($prow->abstract);
    }
    private static function render_authors($fr, $prow, $user, $o) {
        if ($user->can_view_authors($prow)
            && ($alist = $prow->author_list())) {
            $fr->title = $o->title(count($alist));
            $fr->set_text("");
            foreach ($alist as $i => $au) {
                $marker = ($i || count($alist) > 1 ? ($i + 1) . ". " : "");
                $fr->value .= prefix_word_wrap($marker, $au->name_email_aff_text(), strlen($marker), self::WIDTH);
            }
        }
    }
    private static function render_topics($fr, $prow, $user, $o) {
        if (($tlist = $prow->topic_map())) {
            $fr->title = $o->title(count($tlist));
            $fr->set_text("");
            foreach ($tlist as $t)
                $fr->value .= prefix_word_wrap("* ", $t, 2, self::WIDTH);
        }
    }
    static function render(PaperInfo $prow, Contact $user) {
        $n = prefix_word_wrap("", "Submission #{$prow->paperId}: {$prow->title}", 0, self::WIDTH);
        $text = $n . str_repeat("=", min(self::WIDTH, strlen($n) - 1)) . "\n\n";

        $fr = new FieldRender(FieldRender::CTEXT);
        foreach ($user->conf->paper_opts->field_list($prow) as $o) {
            if (($o->id <= 0 || $user->allow_view_option($prow, $o))
                && $o->display_position() !== false) {
                $fr->clear();
                if ($o->id === -1004) {
                    self::render_abstract($fr, $prow, $user, $o);
                } else if ($o->id === -1001) {
                    self::render_authors($fr, $prow, $user, $o);
                } else if ($o->id === -1005) {
                    self::render_topics($fr, $prow, $user, $o);
                } else if ($o->id > 0
                           && ($ov = $prow->option($o))) {
                    $o->render($fr, $ov);
                }
                if (!$fr->is_empty()) {
                    if ($fr->title === null) {
                        $fr->title = $o->title();
                    }
                    $title = prefix_word_wrap("", $fr->title, 0, self::WIDTH);
                    $text .= $title
                        . str_repeat("-", min(self::WIDTH, strlen($title) - 1))
                        . "\n" . rtrim($fr->value) . "\n\n";
                }
            }
        }

        return $text . "\n";
    }
    function run(Contact $user, $qreq, $ssel) {
        $texts = array();
        foreach ($user->paper_set($ssel, ["topics" => 1]) as $prow) {
            if (($whyNot = $user->perm_view_paper($prow))) {
                Conf::msg_error(whyNotText($whyNot));
            } else {
                $texts[] = $this->render($prow, $user);
                $rfSuffix = (count($texts) == 1 ? $prow->paperId : "s");
            }
        }
        if (!empty($texts)) {
            downloadText(join("", $texts), "abstract$rfSuffix");
        }
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
        return $user->conf->make_csvg("authors")->select($header)->add($texts);
    }
}

/* NB this search action is actually unavailable via the UI */
class GetContacts_ListAction extends ListAction {
    function allow(Contact $user) {
        return $user->is_manager();
    }
    function run(Contact $user, $qreq, $ssel) {
        $contact_map = GetAuthors_ListAction::contact_map($user->conf, $ssel);
        $texts = [];
        foreach ($user->paper_set($ssel, ["allConflictType" => 1]) as $prow) {
            if ($user->allow_administer($prow)) {
                foreach ($prow->contacts() as $cid => $c) {
                    $a = $contact_map[$cid];
                    $aa = $prow->author_by_email($a->email) ? : $a;
                    $texts[] = [$prow->paperId, $prow->title, $aa->firstName, $aa->lastName, $aa->email, $aa->affiliation];
                }
            }
        }
        return $user->conf->make_csvg("contacts")
            ->select(["paper", "title", "first", "last", "email", "affiliation"])
            ->add($texts);
    }
}

class GetPcconflicts_ListAction extends ListAction {
    function allow(Contact $user) {
        return $user->is_manager();
    }
    function run(Contact $user, $qreq, $ssel) {
        $confset = $user->conf->conflict_types();
        $pcm = $user->conf->pc_members();
        $texts = array();
        $old_overrides = $user->add_overrides(Contact::OVERRIDE_CONFLICT);
        foreach ($user->paper_set($ssel, ["allConflictType" => 1]) as $prow) {
            if ($user->can_view_conflicts($prow)) {
                $m = [];
                foreach ($prow->conflicts() as $cid => $c) {
                    if (isset($pcm[$cid])) {
                        $pc = $pcm[$cid];
                        $m[$pc->sort_position] = [$prow->paperId, $prow->title, $pc->firstName, $pc->lastName, $pc->email, $confset->unparse_text($c->conflictType)];
                    }
                }
                if ($m) {
                    ksort($m);
                    $texts[] = $m;
                }
            }
        }
        $user->set_overrides($old_overrides);
        return $user->conf->make_csvg("pcconflicts")
            ->select(["paper", "title", "first", "last", "email", "conflicttype"])
            ->add($texts);
    }
}

class GetTopics_ListAction extends ListAction {
    function run(Contact $user, $qreq, $ssel) {
        $texts = array();
        foreach ($user->paper_set($ssel, ["topics" => 1]) as $row) {
            if ($user->can_view_paper($row)) {
                $out = array();
                foreach ($row->topic_map() as $t) {
                    $out[] = [$row->paperId, $row->title, $t];
                }
                if (empty($out)) {
                    $out[] = [$row->paperId, $row->title, "<none>"];
                }
                $texts[] = $out;
            }
        }
        return $user->conf->make_csvg("topics")
            ->select(["paper", "title", "topic"])
            ->add($texts);
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
