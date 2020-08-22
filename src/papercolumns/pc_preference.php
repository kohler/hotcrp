<?php
// pc_preference.php -- HotCRP helper classes for paper list content
// Copyright (c) 2006-2020 Eddie Kohler; see LICENSE.

class Preference_PaperColumn extends PaperColumn {
    private $editable;
    /** @var Contact */
    private $contact;
    /** @var Contact */
    private $viewer_contact;
    private $not_me;
    private $show_conflict;
    private $prefix;
    private $secondary_sort_topic_score = false;
    private $statistics;
    private $override_statistics;
    function __construct(Conf $conf, $cj) {
        parent::__construct($conf, $cj);
        if (isset($cj->user)) {
            $this->contact = $conf->pc_member_by_email($cj->user);
        }
        if ($cj->edit ?? false) {
            $this->mark_editable();
        }
        $this->statistics = new ScoreInfo;
    }
    function add_decoration($decor) {
        if ($decor === "topicsort") {
            $this->secondary_sort_topic_score = true;
            return $this->__add_decoration($decor);
        } else {
            return parent::add_decoration($decor);
        }
    }
    function mark_editable() {
        $this->editable = true;
        $this->className = "pl_editrevpref";
    }
    function prepare(PaperList $pl, $visible) {
        $this->viewer_contact = $pl->user;
        $reviewer = $pl->reviewer_user();
        $this->contact = $this->contact ?? $reviewer;
        $this->not_me = $this->contact->contactId !== $pl->user->contactId;
        if (!$pl->user->isPC
            || ($this->not_me && !$pl->user->can_view_preference(null))) {
            return false;
        }
        if ($visible) {
            $pl->qopts["topics"] = 1;
        }
        $this->prefix =  "";
        if ($this->as_row) {
            $this->prefix = $pl->user->reviewer_html_for($this->contact);
        }
        return true;
    }
    private function sortable_preference(PaperInfo $row) {
        if ($this->not_me
            && ($this->editable
                ? !$this->viewer_contact->allow_view_preference($row)
                : !$this->viewer_contact->can_view_preference($row))) {
            return [-PHP_INT_MAX, null];
        } else {
            $pv = $row->preference($this->contact);
            if ($pv[0] === 0 && $pv[1] === null) {
                if (!$this->contact->can_enter_preference($row)) {
                    $pv[0] = -PHP_INT_MAX;
                } else if ($row->has_conflict($this->contact)) {
                    $pv[0] = $this->editable ? -0.00001 : -PHP_INT_MAX;
                }
            }
            return $pv;
        }
    }
    function compare(PaperInfo $a, PaperInfo $b, PaperList $pl) {
        list($ap, $ae) = $this->sortable_preference($a);
        list($bp, $be) = $this->sortable_preference($b);
        if ($ap !== $bp) {
            return $ap < $bp ? 1 : -1;
        } else if ($ae !== $be) {
            if (($ae === null) !== ($be === null)) {
                return $ae === null ? 1 : -1;
            }
            return (int) $ae < (int) $be ? 1 : -1;
        }
        if ($this->secondary_sort_topic_score) {
            $at = $a->topic_interest_score($this->contact);
            $bt = $b->topic_interest_score($this->contact);
            if ($at != $bt) {
                return $at < $bt ? 1 : -1;
            }
        }
        return 0;
    }
    function analyze(PaperList $pl, $fields) {
        $pfcol = $rtcol = [];
        foreach ($fields as $fdef) {
            if ($fdef instanceof ReviewerType_PaperColumn) {
                $rtcol[] = $fdef;
            } else if ($fdef instanceof Preference_PaperColumn) {
                $pfcol[] = $fdef;
            }
        }
        $this->show_conflict = count($pfcol) !== 1
            || count($rtcol) !== 1
            || $rtcol[0]->contact()->contactId !== $this->contact->contactId;
    }
    function header(PaperList $pl, $is_text) {
        if ($this->contact === $pl->user || $this->as_row) {
            return "Preference";
        } else if ($is_text) {
            return $pl->user->reviewer_text_for($this->contact) . " preference";
        } else {
            return $pl->user->reviewer_html_for($this->contact) . "<br>preference";
        }
    }
    function content_empty(PaperList $pl, PaperInfo $row) {
        return $this->not_me && !$pl->user->allow_view_preference($row);
    }
    function content(PaperList $pl, PaperInfo $row) {
        $pv = $row->preference($this->contact);
        $pv_exists = $pv[0] !== 0 || $pv[1] !== null;
        $editable = $this->editable && $this->contact->can_enter_preference($row);
        $has_conflict = $row->has_conflict($this->contact);

        // compute HTML
        $t = "";
        if ($this->as_row) {
            if ($pv_exists) {
                $t = $this->prefix . unparse_preference_span($pv, true);
            }
        } else if ($editable) {
            $iname = "revpref" . $row->paperId;
            if ($this->not_me) {
                $iname .= "u" . $this->contact->contactId;
            }
            $t = '<input name="' . $iname . '" class="uikd uich revpref" value="'
                . ($pv_exists ? unparse_preference($pv) : "")
                . '" type="text" size="4" tabindex="2" placeholder="0" inputmode="numeric" />';
            if ($has_conflict && $this->show_conflict) {
                $t .= " " . review_type_icon(-1);
            }
        } else if (!$has_conflict || $pv_exists) {
            $t = str_replace("-", "−" /* U+2212 */, unparse_preference($pv));
        } else if ($this->show_conflict) {
            $t = review_type_icon(-1);
        }

        // account for statistics and maybe wrap HTML in conflict
        if ($this->not_me
            && !$editable
            && !$pl->user->can_view_preference($row)
            && $t !== "") {
            $tag = $this->as_row ? "div" : "span";
            $t = "<$tag class=\"fx5\">" . $t . "</$tag>";
            if (!$this->override_statistics) {
                $this->override_statistics = clone $this->statistics;
            }
            if ($pv_exists) {
                $this->override_statistics->add($pv[0]);
            }
        } else if ($pv_exists) {
            $this->statistics->add($pv[0]);
            if ($this->override_statistics) {
                $this->override_statistics->add($pv[0]);
            }
        }

        return $t;
    }
    function text(PaperList $pl, PaperInfo $row) {
        if (!$this->not_me || $this->viewer_contact->can_view_preference($row)) {
            return unparse_preference($row->preference($this->contact));
        } else {
            return "";
        }
    }
    function has_statistics() {
        return !$this->as_row && !$this->editable;
    }
    private function unparse_statistic($statistics, $stat) {
        $x = $statistics->statistic($stat);
        if ($x == 0
            && $stat !== ScoreInfo::COUNT
            && $statistics->statistic(ScoreInfo::COUNT) == 0) {
            return "";
        } else if (in_array($stat, [ScoreInfo::COUNT, ScoreInfo::SUM, ScoreInfo::MEDIAN])) {
            return $x;
        } else {
            return sprintf("%.2f", $x);
        }
    }
    function statistic_html(PaperList $pl, $stat) {
        $t = $this->unparse_statistic($this->statistics, $stat);
        if ($this->override_statistics) {
            $tt = $this->unparse_statistic($this->override_statistics, $stat);
            if ($t !== $tt) {
                $t = '<span class="fn5">' . $t . '</span><span class="fx5">' . $tt . '</span>';
            }
        }
        return $t;
    }

    static function expand($name, Contact $user, $xfj, $m) {
        if (!($fj = (array) $user->conf->basic_paper_column("pref", $user))) {
            return null;
        }
        $rs = [];
        foreach (ContactSearch::make_pc($m[1], $user)->users() as $u) {
            if ($u->roles & Contact::ROLE_PC) {
                $fj["name"] = "pref:" . $u->email;
                $fj["user"] = $u->email;
                $rs[] = (object) $fj;
            }
        }
        if (empty($rs)) {
            PaperColumn::column_error($user, "No PC member matches “" . htmlspecialchars($m[1]) . "”.");
        }
        return $rs;
    }
}
