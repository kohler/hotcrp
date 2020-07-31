<?php
// intrinsicvalue.php -- HotCRP helper class for paper options
// Copyright (c) 2006-2020 Eddie Kohler; see LICENSE.

class Title_PaperOption extends PaperOption {
    function __construct($conf, $args) {
        parent::__construct($conf, $args);
    }
    function value_force(PaperValue $ov) {
        if ((string) $ov->prow->title !== "") {
            $ov->set_value_data([1], [$ov->prow->title]);
        }
    }
    function value_present(PaperValue $ov) {
        return $ov->value
            && (strlen($ov->data()) > 6
                || !preg_match('/\A(?:|N\/?A|TB[AD])\z/i', $ov->data()));
    }
    function value_unparse_json(PaperValue $ov, PaperStatus $ps) {
        return (string) $ov->data();
    }
    function value_save(PaperValue $ov, PaperStatus $ps) {
        $ps->mark_diff("title");
        $ps->save_paperf("title", $ov->data());
        return true;
    }
    function parse_web(PaperInfo $prow, Qrequest $qreq) {
        return $this->parse_json_string($prow, $qreq->title, PaperOption::PARSE_STRING_CONVERT | PaperOption::PARSE_STRING_SIMPLIFY);
    }
    function parse_json(PaperInfo $prow, $j) {
        return $this->parse_json_string($prow, $j, PaperOption::PARSE_STRING_SIMPLIFY);
    }
    function echo_web_edit(PaperTable $pt, $ov, $reqov) {
        $this->echo_web_edit_text($pt, $ov, $reqov, ["no_format_description" => true]);
    }
    function render(FieldRender $fr, PaperValue $ov) {
        $fr->value = $ov->prow->title ? : "[No title]";
        $fr->value_format = $ov->prow->title_format();
    }
}

class Abstract_PaperOption extends PaperOption {
    function __construct($conf, $args) {
        parent::__construct($conf, $args);
        $this->set_required(!$conf->opt("noAbstract"));
    }
    function value_force(PaperValue $ov) {
        if (($ab = $ov->prow->abstract_text()) !== "") {
            $ov->set_value_data([1], [$ab]);
        }
    }
    function value_present(PaperValue $ov) {
        return $ov->value
            && (strlen($ov->data()) > 6
                || !preg_match('/\A(?:|N\/?A|TB[AD])\z/i', $ov->data()));
    }
    function value_unparse_json(PaperValue $ov, PaperStatus $ps) {
        return (string) $ov->data();
    }
    function value_save(PaperValue $ov, PaperStatus $ps) {
        $ps->mark_diff("abstract");
        $ab = $ov->data();
        if ($ab === null || strlen($ab) < 16383) {
            $ps->save_paperf("abstract", $ab === "" ? null : $ab);
            $ps->update_paperf_overflow("abstract", null);
        } else {
            $ps->save_paperf("abstract", null);
            $ps->update_paperf_overflow("abstract", $ab);
        }
        return true;
    }
    function parse_web(PaperInfo $prow, Qrequest $qreq) {
        return $this->parse_json_string($prow, $qreq->abstract, PaperOption::PARSE_STRING_CONVERT | PaperOption::PARSE_STRING_TRIM);
    }
    function parse_json(PaperInfo $prow, $j) {
        return $this->parse_json_string($prow, $j, PaperOption::PARSE_STRING_TRIM);
    }
    function echo_web_edit(PaperTable $pt, $ov, $reqov) {
        if ((int) $this->conf->opt("noAbstract") !== 1) {
            $this->echo_web_edit_text($pt, $ov, $reqov);
        }
    }
    function render(FieldRender $fr, PaperValue $ov) {
        if ($fr->for_page()) {
            $fr->table->render_abstract($fr, $this);
        } else {
            $text = $ov->prow->abstract_text();
            if (trim($text) !== "") {
                $fr->value = $text;
                $fr->value_format = $ov->prow->abstract_format();
            } else if (!$this->conf->opt("noAbstract")
                       && $fr->verbose()) {
                $fr->set_text("[No abstract]");
            }
        }
    }
}

class Collaborators_PaperOption extends PaperOption {
    function __construct(Conf $conf, $args) {
        parent::__construct($conf, $args);
        $this->set_exists_if(!!$this->conf->setting("sub_collab"));
    }
    function value_force(PaperValue $ov) {
        if (($collab = $ov->prow->collaborators()) !== "") {
            $ov->set_value_data([1], [$collab]);
        }
    }
    function value_unparse_json(PaperValue $ov, PaperStatus $ps) {
        return $ov->value ? $ov->data() : null;
    }
    function value_check(PaperValue $ov, Contact $user) {
        if (!$this->value_present($ov)
            && !$ov->prow->allow_absent()
            && ($ov->prow->outcome <= 0 || !$user->can_view_decision($ov->prow))) {
            $ov->warning($this->conf->_("Enter the authors’ external conflicts of interest. If none of the authors have external conflicts, enter “None”."));
        }
    }
    function value_save(PaperValue $ov, PaperStatus $ps) {
        $ps->mark_diff("collaborators");
        $collab = $ov->data();
        if ($collab === null || strlen($collab) < 8190) {
            $ps->save_paperf("collaborators", $collab === "" ? null : $collab);
            $ps->update_paperf_overflow("collaborators", null);
        } else {
            $ps->save_paperf("collaborators", null);
            $ps->update_paperf_overflow("collaborators", $collab);
        }
        return true;
    }
    function parse_web(PaperInfo $prow, Qrequest $qreq) {
        $ov = $this->parse_json_string($prow, $qreq->collaborators, PaperOption::PARSE_STRING_CONVERT | PaperOption::PARSE_STRING_TRIM);
        $this->normalize_value($ov);
        return $ov;
    }
    function parse_json(PaperInfo $prow, $j) {
        $ov = $this->parse_json_string($prow, $j, PaperOption::PARSE_STRING_TRIM);
        $this->normalize_value($ov);
        return $ov;
    }
    private function normalize_value(PaperValue $ov) {
        $s = $ov->value ? rtrim(cleannl($ov->data())) : "";
        $fix = (string) AuthorMatcher::fix_collaborators($s);
        if ($s !== $fix) {
            $ov->warning("This field was changed to follow our required format. Please check that the result is what you expect.");
            $ov->set_value_data([1], [$fix]);
        }
    }
    function echo_web_edit(PaperTable $pt, $ov, $reqov) {
        if ($pt->editable !== "f" || $pt->user->can_administer($pt->prow)) {
            $this->echo_web_edit_text($pt, $ov, $reqov, ["no_format_description" => true, "no_spellcheck" => true]);
        }
    }
    // XXX no render because paper strip
}

class Nonblind_PaperOption extends PaperOption {
    function __construct(Conf $conf, $args) {
        parent::__construct($conf, $args);
        $this->set_exists_if($this->conf->submission_blindness() == Conf::BLIND_OPTIONAL);
    }
    function value_force(PaperValue $ov) {
        if (!$ov->prow->blind) {
            $ov->set_value_data([1], [null]);
        }
    }
    function value_unparse_json(PaperValue $ov, PaperStatus $ps) {
        return !!$ov->value;
    }
    function value_save(PaperValue $ov, PaperStatus $ps) {
        $ps->mark_diff("nonblind");
        $ps->save_paperf("blind", $ov->value ? 0 : 1);
        return true;
    }
    function parse_web(PaperInfo $prow, Qrequest $qreq) {
        return PaperValue::make($prow, $this, $qreq->blind ? null : 1);
    }
    function parse_json(PaperInfo $prow, $j) {
        if (is_bool($j) || $j === null) {
            return PaperValue::make($prow, $this, $j ? 1 : null);
        } else {
            return PaperValue::make_estop($prow, $this, "Option should be “true” or “false”.");
        }
    }
    function echo_web_edit(PaperTable $pt, $ov, $reqov) {
        $pt->echo_editable_anonymity($this, $ov, $reqov);
    }
}

class Topics_PaperOption extends PaperOption {
    function __construct(Conf $conf, $args) {
        parent::__construct($conf, $args);
        $this->set_exists_if(!!$this->conf->setting("has_topics"));
    }
    function value_force(PaperValue $ov) {
        $vs = $ov->prow->topic_list();
        $ov->set_value_data($vs, array_fill(0, count($vs), null));
    }
    function value_unparse_json(PaperValue $ov, PaperStatus $ps) {
        $vs = $ov->value_list();
        if (!empty($vs) && !$ps->export_ids()) {
            $tmap = $ps->conf->topic_set();
            $vs = array_map(function ($t) use ($tmap) { return $tmap[$t]; }, $vs);
        }
        return $vs;
    }
    function value_store(PaperValue $ov, PaperStatus $ps) {
        $vs = $ov->value_list();
        $bad_topics = $ov->anno("bad_topics");
        $new_topics = $ov->anno("new_topics");
        '@phan-var ?list<string> $new_topics';
        if ($ps->add_topics() && !empty($new_topics)) {
            // add new topics to topic list
            $lctopics = [];
            foreach ($new_topics as $tk) {
                if (!in_array(strtolower($tk), $lctopics)) {
                    $lctopics[] = strtolower($tk);
                    $result = $ps->conf->qe("insert into TopicArea set topicName=?", $tk);
                    $vs[] = $result->insert_id;
                }
            }
            if (!$this->conf->has_topics()) {
                $this->conf->save_setting("has_topics", 1);
            }
            $this->conf->invalidate_topics();
            $bad_topics = array_diff($bad_topics, $new_topics);
        }
        $this->conf->topic_set()->sort($vs);
        $ov->set_value_data($vs, array_fill(0, count($vs), null));
        if (!empty($bad_topics)) {
            $ov->warning($ps->_("Unknown topics ignored (%2\$s).", count($bad_topics), htmlspecialchars(join("; ", $bad_topics))));
        }
    }
    function value_save(PaperValue $ov, PaperStatus $ps) {
        $ps->mark_diff("topics");
        $ps->_topic_ins = $ov->value_list();
        return true;
    }
    function parse_web(PaperInfo $prow, Qrequest $qreq) {
        $vs = [];
        foreach ($prow->conf->topic_set() as $tid => $tname) {
            if (+$qreq["top$tid"] > 0) {
                $vs[] = $tid;
            }
        }
        return PaperValue::make_multi($prow, $this, $vs, array_fill(0, count($vs), null));
    }
    function parse_json(PaperInfo $prow, $j) {
        $bad = false;
        if (is_object($j) || is_associative_array($j)) {
            $j = array_keys(array_filter((array) $j, function ($x) use (&$bad) {
                if ($x !== null && $x !== false && $x !== true) {
                    $bad = true;
                }
                return $x === true;
            }));
        } else if ($j === false) {
            $j = [];
        }
        if (!is_array($j) || $bad) {
            return PaperValue::make_estop($prow, $this, "Validation error.");
        }

        $topicset = $prow->conf->topic_set();
        $vs = $bad_topics = $new_topics = [];
        foreach ($j as $tk) {
            if (is_int($tk)) {
                if (isset($topicset[$tk])) {
                    $vs[] = $tk;
                } else {
                    $bad_topics[] = $tk;
                }
            } else if (!is_string($tk)) {
                return PaperValue::make_estop($prow, $this, "Validation error.");
            } else if (($tk = trim($tk)) !== "") {
                $tid = array_search($tk, $topicset->as_array(), true);
                if ($tid !== false) {
                    $vs[] = $tid;
                } else if (!ctype_digit($tk)) {
                    $tids = [];
                    foreach ($topicset as $xtid => $tname) {
                        if (strcasecmp($tk, $tname) == 0)
                            $tids[] = $xtid;
                    }
                    if (count($tids) === 1) {
                        $vs[] = $tids[0];
                    } else {
                        $bad_topics[] = $tk;
                        if (empty($tids)) {
                            $new_topics[] = $tk;
                        }
                    }
                }
            }
        }

        $ov = PaperValue::make_multi($prow, $this, $vs, array_fill(0, count($vs), null));
        $ov->anno["bad_topics"] = $bad_topics;
        $ov->anno["new_topics"] = $new_topics;
        return $ov;
    }
    function echo_web_edit(PaperTable $pt, $ov, $reqov) {
        $pt->echo_editable_topics($this, $reqov);
    }
    function render(FieldRender $fr, PaperValue $ov) {
        $fr->table->render_topics($fr, $this);
    }
}

class PCConflicts_PaperOption extends PaperOption {
    function __construct(Conf $conf, $args) {
        parent::__construct($conf, $args);
        $this->set_exists_if(!!$this->conf->setting("sub_pcconf"));
    }
    /** @return array<int,int> */
    static private function paper_value_map(PaperInfo $prow) {
        return array_intersect_key($prow->conflict_types(), $prow->conf->pc_members());
    }
    /** @return array<int,?string> */
    static private function value_map(PaperValue $ov) {
        return array_combine($ov->value_list(), $ov->data_list());
    }
    function value_force(PaperValue $ov) {
        $vm = self::paper_value_map($ov->prow);
        /** @phan-suppress-next-line PhanTypeMismatchArgument */
        $ov->set_value_data(array_keys($vm), array_values($vm));
    }
    function value_unparse_json(PaperValue $ov, PaperStatus $ps) {
        $pcm = $this->conf->pc_members();
        $confset = $this->conf->conflict_types();
        $can_view_authors = $ps->user->allow_view_authors($ov->prow);
        $pcc = [];
        foreach (self::value_map($ov) as $k => $v) {
            if (($pc = $pcm[$k] ?? null) && Conflict::is_conflicted((int) $v)) {
                $ct = (int) $v;
                if (!$can_view_authors) {
                    // Sometimes users can see conflicts but not authors.
                    // Don't expose author-ness during that period.
                    $ct = Conflict::set_pinned(Conflict::nonauthor_part($ct), false);
                    $ct = $ct ? : Conflict::GENERAL;
                } else if ($ct & CONFLICT_CONTACTAUTHOR) {
                    $ct = ($ct | CONFLICT_AUTHOR) & ~CONFLICT_CONTACTAUTHOR;
                }
                $pcc[$pc->email] = $confset->unparse_json($ct);
            }
        }
        return (object) $pcc;
    }
    function value_check(PaperValue $ov, Contact $user) {
        if ($this->conf->setting("sub_pcconf")
            && ($ov->prow->outcome <= 0 || !$user->can_view_decision($ov->prow))) {
            $vm = self::value_map($ov);
            $pcs = [];
            foreach ($this->conf->full_pc_members() as $p) {
                if (($vm[$p->contactId] ?? 0) === 0 /* not MAXUNCONFLICTED */
                    && $ov->prow->potential_conflict($p)) {
                    $pcs[] = Ht::link($p->name_h(NAME_P), "#pcc{$p->contactId}", ["class" => "uu"]);
                }
            }
            if (!empty($pcs)) {
                $ov->warning($this->conf->_("You may have missed conflicts of interest with %s. Please verify that all conflicts are correctly marked.", commajoin($pcs, "and")) . $this->conf->_(" Hover over “possible conflict” labels for more information."));
            }
        }
    }
    function value_save(PaperValue $ov, PaperStatus $ps) {
        // do not mark diff (will be marked later)
        $pcm = $this->conf->pc_members();
        if ($ov->prow->paperId > 0
            ? $ps->user->can_administer($ov->prow)
            : $ps->user->privChair) {
            $mask = CONFLICT_AUTHOR - 1;
        } else {
            $mask = (CONFLICT_AUTHOR - 1) & ~1;
        }
        foreach (self::value_map($ov) as $k => $v) {
            $ps->update_conflict_value($pcm[$k]->email, $mask, ((int) $v) & $mask);
        }
        return true;
    }
    private function update_value_map(&$vm, $k, $v) {
        $vm[$k] = (($vm[$k] ?? 0) & ~(CONFLICT_AUTHOR - 1)) | $v;
    }
    function parse_web(PaperInfo $prow, Qrequest $qreq) {
        $vm = self::paper_value_map($prow);
        foreach ($prow->conf->pc_members() as $cid => $pc) {
            if (isset($qreq["has_pcc$cid"]) || isset($qreq["pcc$cid"])) {
                $ct = $qreq["pcc$cid"] ?? "0";
                if (ctype_digit($ct) && $ct >= 0 && $ct <= 127) {
                    $this->update_value_map($vm, $cid, (int) $ct);
                }
            }
        }
        /** @phan-suppress-next-line PhanTypeMismatchArgument */
        return PaperValue::make_multi($prow, $this, array_keys($vm), array_values($vm));
    }
    function parse_json(PaperInfo $prow, $j) {
        $ja = [];
        if (is_object($j) || is_associative_array($j)) {
            foreach ((array) $j as $k => $v) {
                $ja[strtolower($k)] = $v;
            }
        } else if (is_array($j)) {
            foreach ($j as $x) {
                if (is_string($x)) {
                    $ja[strtolower($x)] = true;
                } else {
                    return PaperValue::make_estop($prow, $this, "Validation error.");
                }
            }
        } else {
            return PaperValue::make_estop($prow, $this, "Validation error.");
        }

        $vm = self::paper_value_map($prow);
        foreach ($vm as $k => &$v) {
            $v &= ~(CONFLICT_AUTHOR - 1);
        }
        unset($v);

        $confset = $prow->conf->conflict_types();
        $pv = new PaperValue($prow, $this);
        foreach ($ja as $email => $v) {
            if (is_string($email)
                && (is_bool($v) || is_int($v) || is_string($v))) {
                $pc = $prow->conf->pc_member_by_email($email);
                if (!$pc) {
                    $pv->msg("“" . htmlspecialchars($email) . "” is not a PC member’s email.", MessageSet::WARNING);
                }
                $ct = $confset->parse_json($v);
                if ($ct === false) {
                    $pv->msg("“" . htmlspecialchars($v) . "” does not describe a conflict type.", MessageSet::WARNING);
                    $ct = Conflict::GENERAL;
                }
                if ($pc) {
                    $this->update_value_map($vm, $pc->contactId, $ct);
                }
            } else {
                return PaperValue::make_estop($prow, $this, "Validation error.");
            }
        }
        /** @phan-suppress-next-line PhanTypeMismatchArgument */
        $pv->set_value_data(array_keys($vm), array_values($vm));
        return $pv;
    }
    function echo_web_edit(PaperTable $pt, $ov, $reqov) {
        $pt->echo_editable_pc_conflicts($this, $ov, $reqov);
    }
    // XXX no render because paper strip
}

class Contacts_PaperOption extends PaperOption {
    function __construct(Conf $conf, $args) {
        parent::__construct($conf, $args);
    }
    function value_force(PaperValue $ov) {
        // $ov->value_list: contact IDs
        // $ov->data_list: emails
        // $ov->anno("users"): list<Author>
        // $ov->anno("bad_users"): list<Author>
        // NB fake papers start out with this user as contact
        $ca = $va = [];
        foreach ($ov->prow->contacts(true) as $cflt) {
            if ($cflt->contactId > 0) {
                $ca[] = $cflt;
                $va[$cflt->contactId] = $cflt->email;
            }
        }
        $ov->set_value_data(array_keys($va), array_values($va));
        $ov->set_anno("users", array_values($ca));
    }
    function value_unparse_json(PaperValue $ov, PaperStatus $ps) {
        $ca = [];
        foreach ($ov->anno("users") ?? [] as $c) {
            if ($c->contactId >= 0)
                $ca[$c->contactId] = $c;
        }
        foreach ($ov->value_list() as $cid) {
            if (!isset($ca[$cid]))
                $ps->conf->request_cached_user_by_id($cid);
        }
        $j = [];
        foreach ($ov->value_list() as $cid) {
            if (($u = $ca[$cid] ?? $ps->conf->cached_user_by_id($cid)))
                $j[] = Author::unparse_json_of($u);
        }
        return $j;
    }
    function value_check(PaperValue $ov, Contact $user) {
        if ($ov->anno("modified")) {
            if (count($ov->value_list()) === 0
                && $ov->prow->paperId > 0
                && count($ov->prow->contacts()) > 0) {
                $ov->error($this->conf->_("Each submission must have at least one contact."));
            }
            if (!$user->allow_administer($ov->prow)
                && $ov->prow->conflict_type($user) >= CONFLICT_CONTACTAUTHOR
                && self::ca_index($ov->anno("users"), $user->email) === false) {
                $ov->error($this->conf->_("You can’t remove yourself from the submission’s contacts. (Ask another contact to remove you.)"));
            }
        }
    }
    function value_save(PaperValue $ov, PaperStatus $ps) {
        // do not mark diff (will be marked later)
        $ps->clear_conflict_values(CONFLICT_CONTACTAUTHOR);
        foreach ($ov->anno("users") as $c) {
            $ps->update_conflict_value($c->email, CONFLICT_CONTACTAUTHOR, CONFLICT_CONTACTAUTHOR);
            if ($c->contactId === 0) {
                $c->conflictType = CONFLICT_CONTACTAUTHOR;
                $ps->register_user($c);
            }
        }
        return true;
    }
    static function ca_index($ca, $email) {
        foreach ($ca as $i => $c) {
            if (strcasecmp($c->email, $email) === 0)
                return $i;
        }
        return false;
    }
    /** @param string $email
     * @return ?Author */
    function value_by_email(PaperValue $ov, $email) {
        $ca = $ov->anno("users") ?? [];
        $i = self::ca_index($ca, $email);
        return $i !== false ? $ca[$i] : null;
    }
    static private function apply_new_users(PaperValue $ov, $new_ca, &$ca) {
        $bad_ca = [];
        foreach ($new_ca as $c) {
            $c->contactId = 0;
            if (validate_email($c->email)) {
                $ca[] = $c;
            } else {
                if ($c->email === "" || strcasecmp($c->email, "Email") === 0) {
                    $ov->error("Email address required.");
                } else {
                    $ov->error("“" . htmlspecialchars($c->email) . "” is not a valid email address.");
                }
                if ($c->author_index) {
                    $ov->msg_at("contacts:{$c->author_index}", false, MessageSet::ERROR);
                }
                $bad_ca[] = $c;
            }
        }
        $ov->set_value_data(array_map(function ($c) { return $c->contactId; }, $ca),
                            array_map(function ($c) { return $c->email; }, $ca));
        $ov->set_anno("users", $ca);
        $ov->set_anno("bad_users", $bad_ca);
        $ov->set_anno("modified", true);
    }
    function parse_web(PaperInfo $prow, Qrequest $qreq) {
        $ov = PaperValue::make_force($prow, $this);
        $ca = $ov->anno("users");
        $bad_ca = $new_ca = [];
        for ($n = 1; isset($qreq["contacts:email_$n"]); ++$n) {
            $email = trim($qreq["contacts:email_$n"]);
            $name = simplify_whitespace((string) $qreq["contacts:name_$n"]);
            $affiliation = simplify_whitespace((string) $qreq["contacts:affiliation_$n"]);
            if (($i = self::ca_index($ca, $email)) !== false) {
                if (!$qreq["contacts:active_$n"]
                    && ($ca[$i]->conflictType & CONFLICT_AUTHOR) === 0) {
                    array_splice($ca, $i, 1);
                }
            } else if (($email !== "" || $name !== "") && $qreq["contacts:active_$n"]) {
                $new_ca[] = $c = Author::make_keyed(["email" => $email, "name" => $name, "affiliation" => $affiliation]);
                $c->author_index = $n;
            }
        }
        self::apply_new_users($ov, $new_ca, $ca);
        return $ov;
    }
    function parse_json(PaperInfo $prow, $j) {
        $ov = PaperValue::make_force($prow, $this);
        $ca = $old_ca = $ov->anno("users");
        $new_ca = [];
        if (is_object($j) || is_associative_array($j)) {
            foreach ((array) $j as $k => $v) {
                $i = self::ca_index($ca, $k);
                if ($v === false) {
                    if ($i !== false
                        && ($ca[$i]->conflictType & CONFLICT_AUTHOR) === 0) {
                        array_splice($ca, $i, 1);
                    }
                } else if ($v === true
                           || (is_object($v) && strcasecmp($v->email ?? $k, $k) === 0)) {
                    if ($i === false) {
                        $a = $v === true ? [] : (array) $v;
                        $a["email"] = $k;
                        $new_ca[] = Author::make_keyed($a);
                    }
                } else {
                    return PaperValue::make_estop($prow, $this, "Validation error.");
                }
            }
        } else if (is_array($j)) {
            $ca = array_values(array_filter($ca, function ($au) {
                return ($au->conflictType & CONFLICT_AUTHOR) !== 0;
            }));
            foreach ($j as $v) {
                if (is_string($v)) {
                    $email = $v;
                } else if (is_object($v) && isset($v->email)) {
                    $email = $v->email;
                } else {
                    return PaperValue::make_estop($prow, $this, "Validation error.");
                }
                if (self::ca_index($ca, $email) !== false) {
                    // double mention -- do nothing
                } else if (($i = self::ca_index($old_ca, $email)) !== false) {
                    $ca[] = $old_ca[$i];
                } else {
                    $a = is_string($v) ? [] : (array) $v;
                    $a["email"] = $email;
                    $new_ca[] = Author::make_keyed($a);
                }
            }
        } else {
            return PaperValue::make_estop($prow, $this, "Validation error.");
        }
        self::apply_new_users($ov, $new_ca, $ca);
        return $ov;
    }
    function echo_web_edit(PaperTable $pt, $ov, $reqov) {
        $pt->echo_editable_contact_author($this, $ov, $reqov);
    }
    // XXX no render because paper strip
}

class IntrinsicValue {
    static function assign_intrinsic(PaperValue $ov) {
        if ($ov->id === DTYPE_SUBMISSION) {
            $ov->set_value_data([(int) $ov->prow->paperStorageId], [null]);
        } else if ($ov->id === DTYPE_FINAL) {
            $ov->set_value_data([(int) $ov->prow->finalPaperStorageId], [null]);
        } else {
            $ov->set_value_data([], []);
        }
    }
    static function value_check($o, PaperValue $ov, Contact $user) {
        if ($o->id === DTYPE_SUBMISSION
            && !$o->conf->opt("noPapers")
            && !$o->value_present($ov)
            && !$ov->prow->allow_absent()) {
            $ov->warning($o->conf->_("Entry required to complete submission."));
        }
        if ($o->id === PaperOption::AUTHORSID) {
            $msg1 = $msg2 = false;
            foreach ($ov->prow->author_list() as $n => $au) {
                if (strpos($au->email, "@") === false
                    && strpos($au->affiliation, "@") !== false) {
                    $msg1 = true;
                    $ov->msg_at("author" . ($n + 1), false, MessageSet::WARNING);
                } else if ($au->firstName === "" && $au->lastName === ""
                           && $au->email === "" && $au->affiliation !== "") {
                    $msg2 = true;
                    $ov->msg_at("author" . ($n + 1), false, MessageSet::WARNING);
                }
            }
            $max_authors = $o->conf->opt("maxAuthors");
            if (!$ov->prow->author_list()
                && !$ov->prow->allow_absent()) {
                $ov->msg_at("author1", false, MessageSet::ERROR);
            }
            if ($max_authors > 0
                && count($ov->prow->author_list()) > $max_authors) {
                $ov->estop($o->conf->_("Each submission can have at most %d authors.", $max_authors));
            }
            if ($msg1) {
                $ov->warning("You may have entered an email address in the wrong place. The first author field is for email, the second for name, and the third for affiliation.");
            }
            if ($msg2) {
                $ov->warning("Please enter a name and optional email address for every author.");
            }
        }
    }
    static function echo_web_edit($o, PaperTable $pt, $ov, $reqov) {
        if ($o->id === PaperOption::AUTHORSID) {
            $pt->echo_editable_authors($o);
        }
    }
}
