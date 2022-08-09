<?php
// o_topics.php -- HotCRP helper class for topics intrinsic
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class Topics_PaperOption extends PaperOption {
    /** @var int */
    private $min_count = 0;
    /** @var int */
    private $max_count = 0;
    function __construct(Conf $conf, $args) {
        parent::__construct($conf, $args);
        if ($conf->setting("has_topics")) {
            $min_count = $conf->setting("topic_min");
            if ($min_count === null && $this->required) {
                $min_count = 1;
            }
            $this->min_count = $min_count ?? 0;
            $this->max_count = $conf->setting("topic_max") ?? 0;
            $this->required = $min_count > 0;
        } else {
            $this->set_exists_condition(false);
        }
    }
    function jsonSerialize() {
        $j = parent::jsonSerialize();
        if ($this->min_count > 1) {
            $j->min = $this->min_count;
        }
        if ($this->max_count > 0) {
            $j->max = $this->max_count;
        }
        return $j;
    }
    /** @return TopicSet */
    function topic_set() {
        return $this->conf->topic_set();
    }
    function value_force(PaperValue $ov) {
        $vs = $ov->prow->topic_list();
        $ov->set_value_data($vs, array_fill(0, count($vs), null));
    }
    function value_check(PaperValue $ov, Contact $user) {
        if ($this->test_exists($ov->prow)) {
            if ($this->min_count > 0
                && !$ov->prow->allow_absent()
                && $ov->value_count() < $this->min_count) {
                $ov->error($this->conf->_("<0>You must select at least %d topics", $this->min_count));
            }
            if ($this->max_count > 0
                && $ov->value_count() > $this->max_count) {
                $ov->error($this->conf->_("<0>You may select at most %d topics", $this->max_count));
            }
        }
    }
    function value_unparse_json(PaperValue $ov, PaperStatus $ps) {
        $vs = $ov->value_list();
        if (!empty($vs) && !$ps->export_ids()) {
            $tmap = $this->topic_set();
            $vs = array_map(function ($t) use ($tmap) { return $tmap[$t]; }, $vs);
        }
        return $vs;
    }
    function value_store(PaperValue $ov, PaperStatus $ps) {
        $vs = $ov->value_list();
        $badvs = $ov->anno("bad_values");
        $newvs = $ov->anno("new_values");
        '@phan-var ?list<string> $newvs';
        if ($ps->add_topics() && !empty($newvs)) {
            // add new topics to topic list
            $lctopics = [];
            foreach ($newvs as $tk) {
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
            $badvs = array_diff($badvs, $newvs);
        }
        $this->topic_set()->sort($vs);
        $ov->set_value_data($vs, array_fill(0, count($vs), null));
        if (!empty($badvs)) {
            $ov->warning($ps->_("<0>Topics %#s not found", $badvs));
        }
    }
    function value_save(PaperValue $ov, PaperStatus $ps) {
        $ps->change_at($this);
        $ps->_topic_ins = $ov->value_list();
        return true;
    }
    function parse_qreq(PaperInfo $prow, Qrequest $qreq) {
        $vs = [];
        foreach ($this->topic_set() as $tid => $tname) {
            $v = $qreq["{$this->formid}:$tid"] ?? "";
            if ($v !== "" && $v !== "0") {
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
            return PaperValue::make_estop($prow, $this, "<0>Validation error");
        }

        $topicset = $this->topic_set();
        $vs = $badvs = $newvs = [];
        foreach ($j as $tk) {
            if (is_int($tk)) {
                if (isset($topicset[$tk])) {
                    $vs[] = $tk;
                } else {
                    $badvs[] = $tk;
                }
            } else if (!is_string($tk)) {
                return PaperValue::make_estop($prow, $this, "<0>Validation error");
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
                        $badvs[] = $tk;
                        if (empty($tids)) {
                            $newvs[] = $tk;
                        }
                    }
                }
            }
        }

        $ov = PaperValue::make_multi($prow, $this, $vs, array_fill(0, count($vs), null));
        $ov->set_anno("bad_values", $badvs);
        $ov->set_anno("new_values", $newvs);
        return $ov;
    }
    function print_web_edit(PaperTable $pt, $ov, $reqov) {
        $pt->print_editable_option_papt($this, null, [
            "id" => $this->readable_formid(),
            "context_args" => [$this->min_count, $this->max_count]
        ]);
        echo '<fieldset class="papev fieldset-covert" name="', $this->formid, '"><ul class="ctable">';
        $topicset = $this->topic_set();
        $readonly = !$this->test_editable($ov->prow);
        foreach ($topicset->group_list() as $tg) {
            $arg = ["class" => "uic js-range-click topic-entry", "id" => false,
                    "data-range-type" => $this->formid, "disabled" => $readonly];
            if (($isgroup = $tg->nontrivial())) {
                echo '<li class="ctelt cteltg"><div class="ctelti">';
                if ($tg->improper()) {
                    $arg["data-default-checked"] = in_array($tg->tid, $ov->value_list());
                    $checked = in_array($tg->tid, $reqov->value_list());
                    echo '<label class="checki cteltx"><span class="checkc">',
                        Ht::checkbox("{$this->formid}:{$tg->tid}", 1, $checked, $arg),
                        '</span>', $topicset->unparse_name_html($tg->tid), '</label>';
                } else {
                    echo '<div class="cteltx"><span class="topicg">',
                        htmlspecialchars($tg->name), '</span></div>';
                }
                echo '<div class="checki">';
            }
            foreach ($tg->proper_members() as $tid) {
                if ($isgroup) {
                    $tname = $topicset->unparse_subtopic_name_html($tid);
                } else {
                    $tname = $topicset->unparse_name_html($tid);
                }
                $arg["data-default-checked"] = in_array($tid, $ov->value_list());
                $checked = in_array($tid, $reqov->value_list());
                echo ($isgroup ? '<label class="checki cteltx">' : '<li class="ctelt"><label class="checki ctelti">'),
                    '<span class="checkc">',
                    Ht::checkbox("{$this->formid}:{$tid}", 1, $checked, $arg),
                    '</span>', $tname, '</label>',
                    ($isgroup ? '' : '</li>');
            }
            if ($isgroup) {
                echo '</div></div></li>';
            }
        }
        echo "</ul></fieldset></div>\n\n";
    }
    function render(FieldRender $fr, PaperValue $ov) {
        $vs = $ov->value_list();
        if (!empty($vs)) {
            $user = $fr->user;
            $interests = $user ? $user->topic_interest_map() : [];
            $lenclass = count($vs) < 4 ? "long" : "short";
            $topicset = $this->topic_set();
            $ts = [];
            foreach ($vs as $tid) {
                $t = '<li class="topicti';
                if ($interests) {
                    $t .= ' topic' . ($interests[$tid] ?? 0);
                }
                $tname = $topicset->name($tid);
                $x = $topicset->unparse_name_html($tid);
                if ($user && $user->isPC) {
                    $x = Ht::link($x, $this->conf->hoturl("search", ["q" => "topic:" . SearchWord::quote($tname)]), ["class" => "q"]);
                }
                $ts[] = $t . '">' . $x . '</li>';
                $lenclass = TopicSet::max_topici_lenclass($lenclass, $tname);
            }
            $fr->title = $this->title(count($ts));
            $fr->set_html('<ul class="topict topict-' . $lenclass . '">' . join("", $ts) . '</ul>');
            $fr->value_long = true;
        }
    }
}
