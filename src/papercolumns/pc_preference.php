<?php
// pc_preference.php -- HotCRP helper classes for paper list content
// Copyright (c) 2006-2020 Eddie Kohler; see LICENSE.

class Preference_PaperColumn extends PaperColumn {
    private $editable;
    private $contact;
    private $viewer_contact;
    private $not_me;
    private $show_conflict;
    private $prefix;
    private $secondary_sort_topic_score;
    private $statistics;
    private $override_statistics;
    function __construct(Conf $conf, $cj) {
        parent::__construct($conf, $cj);
        if (isset($cj->user)) {
            $this->contact = $conf->pc_member_by_email($cj->user);
        }
        if (get($cj, "edit")) {
            $this->mark_editable();
        }
        $this->statistics = new ScoreInfo;
    }
    function mark_editable() {
        $this->editable = true;
        $this->className = "pl_editrevpref";
    }
    function prepare(PaperList $pl, $visible) {
        $this->viewer_contact = $pl->user;
        $reviewer = $pl->reviewer_user();
        $this->contact = $this->contact ? : $reviewer;
        $this->not_me = $this->contact->contactId !== $pl->user->contactId;
        if (!$pl->user->isPC
            || ($this->not_me && !$pl->user->can_view_preference(null))) {
            return false;
        }
        if ($visible) {
            $pl->qopts["topics"] = 1;
        }
        $this->prefix =  "";
        if ($this->row) {
            $this->prefix = $pl->user->reviewer_html_for($this->contact);
        }
        $this->secondary_sort_topic_score = $pl->report_id() === "editpref";
        return true;
    }
    private function preference_values($row) {
        if ($this->not_me && !$this->viewer_contact->can_view_preference($row)) {
            return [null, null];
        } else {
            return $row->preference($this->contact);
        }
    }
    function compare(PaperInfo $a, PaperInfo $b, ListSorter $sorter) {
        list($ap, $ae) = $this->preference_values($a);
        if ($ap === 0 && $ae === null && $a->has_conflict($this->contact)) {
            $ap = false;
        }
        list($bp, $be) = $this->preference_values($b);
        if ($bp === 0 && $be === null && $b->has_conflict($this->contact)) {
            $bp = false;
        }
        if ($ap === false || $bp === false) {
            return $ap === $bp ? 0 : ($ap === false ? 1 : -1);
        } else if ($ap === null || $bp === null) {
            return $ap === $bp ? 0 : ($ap === null ? 1 : -1);
        } else if ($ap !== $bp) {
            return $ap < $bp ? 1 : -1;
        } else if ($ae !== $be) {
            if (($ae === null) !== ($be === null)) {
                return $ae === null ? 1 : -1;
            }
            return (float) $ae < (float) $be ? 1 : -1;
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
    function analyze(PaperList $pl, &$rows, $fields) {
        $pfcol = $rtcol = [];
        foreach ($fields as $fdef) {
            if ($fdef instanceof ReviewerType_PaperColumn
                && $fdef->is_visible) {
                $rtcol[] = $fdef;
            } else if ($fdef instanceof Preference_PaperColumn
                       && $fdef->is_visible) {
                $pfcol[] = $fdef;
            }
        }
        $this->show_conflict = count($pfcol) !== 1
            || count($rtcol) !== 1
            || $rtcol[0]->contact()->contactId !== $this->contact->contactId;
    }
    function header(PaperList $pl, $is_text) {
        if ($this->contact === $pl->user || $this->row) {
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
        if ($this->row) {
            if ($pv_exists) {
                $t = $this->prefix . unparse_preference_span($pv, true);
            }
        } else if ($editable && (!$has_conflict || $pv_exists)) {
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
        } else if ($has_conflict) {
            if ($this->show_conflict) {
                $t = review_type_icon(-1);
            }
        } else {
            $t = str_replace("-", "−" /* U+2212 */, unparse_preference($pv));
        }

        // account for statistics and maybe wrap HTML in conflict
        if ($this->not_me
            && !$editable
            && !$pl->user->can_view_preference($row)
            && $t !== "") {
            $tag = $this->row ? "div" : "span";
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
        return unparse_preference($this->preference_values($row));
    }
    function has_statistics() {
        return !$this->row && !$this->editable;
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
    function statistic($pl, $stat) {
        $t = $this->unparse_statistic($this->statistics, $stat);
        if ($this->override_statistics) {
            $tt = $this->unparse_statistic($this->override_statistics, $stat);
            if ($t !== $tt) {
                $t = '<span class="fn5">' . $t . '</span><span class="fx5">' . $tt . '</span>';
            }
        }
        return $t;
    }

    static function expand($name, $user, $xfj, $m) {
        if (!($fj = (array) $user->conf->basic_paper_column("pref", $user))) {
            return null;
        }
        $rs = [];
        foreach (ContactSearch::make_pc($m[1], $user)->ids as $cid) {
            $u = $user->conf->cached_user_by_id($cid);
            if ($u->roles & Contact::ROLE_PC) {
                $fj["name"] = "pref:" . $u->email;
                $fj["user"] = $u->email;
                $rs[] = (object) $fj;
            }
        }
        if (empty($rs)) {
            $user->conf->xt_factory_error("No PC member matches “" . htmlspecialchars($m[1]) . "”.");
        }
        return $rs;
    }
}
