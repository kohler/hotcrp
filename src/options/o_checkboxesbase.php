<?php
// o_checkboxesbase.php -- HotCRP helper class for checkboxes & topics options
// Copyright (c) 2006-2023 Eddie Kohler; see LICENSE.

abstract class CheckboxesBase_PaperOption extends PaperOption {
    /** @var int */
    protected $min_count = 0;
    /** @var int */
    protected $max_count = 0;
    /** @var bool */
    protected $compact = false;

    function __construct(Conf $conf, $args) {
        parent::__construct($conf, $args);
        if (!isset($args->min) && $this->required > 0) {
            $this->min_count = 1;
        } else {
            $this->min_count = $args->min ?? 0;
        }
        $this->max_count = $args->max ?? 0;
        if ($this->min_count > 0 && $this->required === 0) {
            $this->set_required(self::REQ_SUBMIT);
        }
    }


    /** @return TopicSet */
    abstract function topic_set();

    /** @param ?Contact $user
     * @return array<int,int> */
    function interests($user) {
        return [];
    }


    function field_fmt_context() {
        return [new FmtArg("min", $this->min_count), new FmtArg("max", $this->max_count)];
    }


    function value_check(PaperValue $ov, Contact $user) {
        if (!$this->test_exists($ov->prow)) {
            return;
        }
        $n = $ov->value_count();
        if ($ov->has_anno("new_values")) {
            $n += count($ov->anno("new_values"));
        }
        if ($this->min_count > 0
            && !$ov->prow->allow_absent()
            && $n < $this->min_count) {
            $ov->error($this->conf->_("<0>At least {min} selections required", new FmtArg("min", $this->min_count), new FmtArg("max", $this->max_count), new FmtArg("type", $this->type)));
        }
        if ($this->max_count > 0
            && $n > $this->max_count) {
            $ov->error($this->conf->_("<0>At most {max} selections allowed", new FmtArg("min", $this->min_count), new FmtArg("max", $this->max_count), new FmtArg("type", $this->type)));
        }
    }

    function value_export_json(PaperValue $ov, PaperExport $pex) {
        $vs = $ov->value_list();
        if (!empty($vs) && !$pex->use_ids) {
            $tmap = $this->topic_set();
            $vs = array_map(function ($t) use ($tmap) { return $tmap[$t]; }, $vs);
        }
        return $vs;
    }

    function value_store(PaperValue $ov, PaperStatus $ps) {
        if ($ov->has_anno("new_values") && count($ov->anno("new_values")) > 0) {
            $this->value_store_new_values($ov, $ps);
        }

        $vs = $ov->value_list();
        $this->topic_set()->sort($vs); // to reduce unnecessary diffs
        $ov->set_value_data($vs, array_fill(0, count($vs), null));

        $badvs = $ov->anno("bad_values") ?? [];
        if (!empty($badvs)) {
            $ov->warning($ps->_("<0>Options {:list} not found", $badvs, new FmtArg("type", $this->type)));
        }
    }

    function value_store_new_values(PaperValue $ov, PaperStatus $ps) {
    }

    function parse_qreq(PaperInfo $prow, Qrequest $qreq) {
        $vs = [];
        foreach ($this->topic_set() as $tid => $tname) {
            $v = $qreq["{$this->formid}:{$tid}"] ?? "";
            if ($v !== "" && $v !== "0") {
                $vs[] = $tid;
            }
        }
        $v = $qreq[$this->formid] ?? "";
        if ($v !== "" && $v !== "0" && ctype_digit($v)) {
            $tid = intval($v);
            if ($this->topic_set()->name($tid) !== null
                && !in_array($tid, $vs)) {
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
        } else if (is_string($j)) {
            $j = [$j];
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

    /** @param FieldChangeSet $fcs */
    function strip_unchanged_qreq(PaperInfo $prow, Qrequest $qreq, $fcs) {
        if ($this->max_count !== 1) {
            $basev = $prow->base_option($this);
            foreach ($this->topic_set() as $tid => $tname) {
                if ($fcs->test("{$this->formid}:{$tid}") === FieldChangeSet::UNCHANGED) {
                    $qreq["{$this->formid}:{$tid}"] = in_array($tid, $basev->value_list()) ? "1" : "";
                }
            }
        }
    }

    private function render_checkbox($tid, $checked, $arg) {
        if ($this->max_count === 1) {
            return Ht::radio($this->formid, $tid, $checked, $arg);
        } else {
            return Ht::checkbox("{$this->formid}:{$tid}", 1, $checked, $arg);
        }
    }

    function print_web_edit(PaperTable $pt, $ov, $reqov) {
        $pt->print_editable_option_papt($this, null, [
            "id" => $this->readable_formid(),
            "for" => false,
        ]);
        $topicset = $this->topic_set();
        echo '<fieldset class="papev fieldset-covert" name="', $this->formid,
            '"><ul class="ctable',
            $this->compact ? ' compact' : '',
            count($topicset) < 7 ? ' column-count-1' : '',
            '">';
        foreach ($topicset->group_list() as $tg) {
            $arg = ["class" => "uic js-range-click topic-entry", "id" => false,
                    "data-range-type" => $this->formid];
            if (($isgroup = $tg->nontrivial())) {
                echo '<li class="ctelt cteltg"><div class="ctelti">';
                if ($tg->improper()) {
                    $arg["data-default-checked"] = in_array($tg->tid, $ov->value_list());
                    $checked = in_array($tg->tid, $reqov->value_list());
                    echo '<label class="checki cteltx"><span class="checkc">',
                        $this->render_checkbox($tg->tid, $checked, $arg),
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
                    $this->render_checkbox($tid, $checked, $arg),
                    '</span>', $tname, '</label>',
                    ($isgroup ? '' : '</li>');
            }
            if ($isgroup) {
                echo '</div></div></li>';
            }
        }
        if ($topicset->count() === 0) {
            echo '<li class="ctelt celtg"><div class="ctelti"><em>(No options defined)</em></div></li>';
        }
        echo "</ul></fieldset></div>\n\n";
    }

    function render(FieldRender $fr, PaperValue $ov) {
        $vs = $ov->value_list();
        if (!empty($vs)) {
            $interests = $this->interests($fr->user);
            $keyword = $fr->user && $fr->user->isPC ? $this->search_keyword() : null;
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
                if ($keyword !== null) {
                    $x = Ht::link($x, $this->conf->hoturl("search", ["q" => "{$keyword}:" . SearchWord::quote($tname)]), ["class" => "q"]);
                }
                $ts[] = "{$t}\">{$x}</li>";
                $lenclass = TopicSet::max_topici_lenclass($lenclass, $tname);
            }
            $fr->title = $this->title(new FmtArg("count", count($ts)));
            $fr->set_html("<ul class=\"topict topict-{$lenclass}\">" . join("", $ts) . '</ul>');
            $fr->value_long = true;
        }
    }

    function export_setting() {
        $sfs = parent::export_setting();
        $sfs->min = $this->min_count;
        $sfs->max = $this->max_count;
        return $sfs;
    }

    function parse_search(SearchWord $sword, PaperSearch $srch) {
        return $this->parse_topic_set_search($sword, $srch, $this->topic_set(), true);
    }

    function present_script_expression() {
        return ["type" => "checkboxes", "formid" => $this->formid];
    }

    function match_script_expression($values) {
        return ["type" => "checkboxes", "formid" => $this->formid, "values" => $values];
    }
}
