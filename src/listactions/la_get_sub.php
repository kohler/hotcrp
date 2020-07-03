<?php
// listactions/la_get_sub.php -- HotCRP helper classes for list actions
// Copyright (c) 2006-2020 Eddie Kohler; see LICENSE.

class Get_ListAction extends ListAction {
    static function render(PaperList $pl, Qrequest $qreq) {
        $actions = array_values($pl->displayable_list_actions("get/"));
        foreach ($pl->user->user_option_list() as $o) {
            if ($pl->user->can_view_some_option($o)
                && $o->is_document()
                && $pl->has($o->field_key()))
                $actions[] = GetDocument_ListAction::list_action_json($o);
        }
        usort($actions, "Conf::xt_position_compare");
        $last_group = null;
        $sel_opt = [];
        foreach ($actions as $fj) {
            $as = strpos($fj->title, "/");
            if ($as === false) {
                if ($last_group) {
                    $sel_opt[] = ["optgroup", false];
                }
                $last_group = null;
                $sel_opt[] = ["value" => substr($fj->name, 4), "label" => $fj->title];
            } else {
                $group = substr($fj->title, 0, $as);
                if ($group !== $last_group) {
                    $sel_opt[] = ["optgroup", $group];
                    $last_group = $group;
                }
                $sel_opt[] = ["value" => substr($fj->name, 4), "label" => substr($fj->title, $as + 1)];
            }
        }
        if (!empty($sel_opt)) {
            // Note that `js-submit-paperlist` JS handler depends on this
            return Ht::select("getfn", $sel_opt, $qreq->getfn,
                              ["class" => "want-focus js-submit-action-info-get", "style" => "max-width:10em"])
                . "&nbsp; " . Ht::submit("fn", "Go", ["value" => "get", "data-default-submit-all" => 1, "class" => "uic js-submit-mark"]);
        } else {
            return null;
        }
    }
    function run(Contact $user, $qreq, $ssel) {
        if (($opts = $user->conf->options()->find_all($qreq->getfn))
            && count($opts) == 1
            && ($o = current($opts))
            && $user->can_view_some_option($o)) {
            $ga = GetDocument_ListAction::make_list_action($o);
            return $ga->run($user, $qreq, $ssel);
        } else {
            return self::ENOENT;
        }
    }
}

class GetCheckFormat_ListAction extends ListAction {
    function run(Contact $user, $qreq, $ssel) {
        $papers = [];
        foreach ($ssel->paper_set($user) as $prow) {
            if ($user->can_view_pdf($prow))
                $papers[$prow->paperId] = $prow;
        }
        $csvg = $user->conf->make_csvg("formatcheck")->select(["paper", "title", "pages", "format", "messages"]);
        $csvg->download_headers();
        $csvg->flush();
        $cf = new CheckFormat($user->conf, CheckFormat::RUN_PREFER_NO);
        foreach ($papers as $prow) {
            if ($prow->mimetype == "application/pdf") {
                $dtype = $prow->finalPaperStorageId ? DTYPE_FINAL : DTYPE_SUBMISSION;
                if (($doc = $cf->fetch_document($prow, $dtype))) {
                    $cf->check_document($prow, $doc);
                }
                if ($doc && !$cf->failed) {
                    $pages = $cf->pages;
                    $errf = $cf->problem_fields();
                    if (empty($errf)) {
                        $format = "ok";
                        $messages = "";
                    } else {
                        $format = join(" ", $errf);
                        $messages = join("\n", $cf->message_texts());
                    }
                } else {
                    $pages = "?";
                    $format = "error";
                    $messages = "Problem running format checker";
                }
            } else {
                $pages = "";
                $format = "notpdf";
                $messages = "";
            }
            echo $prow->paperId, ",", CsvGenerator::quote($prow->title), ",", $pages, ",", CsvGenerator::quote($format), ",", CsvGenerator::quote($messages), "\n";
            ob_flush();
            flush();
        }
        exit;
    }
}

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
    function run(Contact $user, $qreq, $ssel) {
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

/* NB this search action is actually unavailable via the UI */
class GetContacts_ListAction extends ListAction {
    function allow(Contact $user, Qrequest $qreq) {
        return $user->is_manager();
    }
    function run(Contact $user, $qreq, $ssel) {
        $contact_map = GetAuthors_ListAction::contact_map($user->conf, $ssel);
        $texts = [];
        foreach ($ssel->paper_set($user, ["allConflictType" => 1]) as $prow) {
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
            ->append($texts);
    }
}

class GetPcconflicts_ListAction extends ListAction {
    function allow(Contact $user, Qrequest $qreq) {
        return $user->is_manager();
    }
    function run(Contact $user, $qreq, $ssel) {
        $confset = $user->conf->conflict_types();
        $pcm = $user->conf->pc_members();
        $csvg = $user->conf->make_csvg("pcconflicts")
            ->select(["paper", "title", "first", "last", "email", "conflicttype"]);
        $old_overrides = $user->add_overrides(Contact::OVERRIDE_CONFLICT);
        foreach ($ssel->paper_set($user, ["allConflictType" => 1]) as $prow) {
            if ($user->can_view_conflicts($prow)) {
                $m = [];
                foreach ($prow->conflicts() as $cid => $cflt) {
                    if (($pc = $pcm[$cid] ?? null) && $cflt->is_conflicted()) {
                        $m[$pc->sort_position] = [$prow->paperId, $prow->title, $pc->firstName, $pc->lastName, $pc->email, $confset->unparse_text($cflt->conflictType)];
                    }
                }
                if ($m) {
                    ksort($m);
                    $csvg->append(array_values($m));
                }
            }
        }
        $user->set_overrides($old_overrides);
        return $csvg;
    }
}

class GetTopics_ListAction extends ListAction {
    function run(Contact $user, $qreq, $ssel) {
        $texts = [];
        foreach ($ssel->paper_set($user, ["topics" => 1]) as $row) {
            if ($user->can_view_paper($row)) {
                $n = count($texts);
                foreach ($row->topic_map() as $t) {
                    $texts[] = [$row->paperId, $row->title, $t];
                }
                if (count($texts) === $n) {
                    $texts[] = [$row->paperId, $row->title, "<none>"];
                }
            }
        }
        return $user->conf->make_csvg("topics")
            ->select(["paper", "title", "topic"])
            ->append($texts);
    }
}

class GetCSV_ListAction extends ListAction {
    function run(Contact $user, $qreq, $ssel) {
        $search = new PaperSearch($user, $qreq);
        $search->restrict_match([$ssel, "is_selected"]);
        $pl = new PaperList("pl", $search, ["sort" => true, "display" => $qreq->display], $qreq);
        $pl->set_view("sel", false);
        list($header, $data) = $pl->text_csv();
        return $user->conf->make_csvg("data", CsvGenerator::FLAG_ITEM_COMMENTS)
            ->select($header)->append($data);
    }
}
