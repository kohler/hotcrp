<?php
// pc_preference.php -- HotCRP helper classes for paper list content
// Copyright (c) 2006-2024 Eddie Kohler; see LICENSE.

class Preference_PaperColumn extends PaperColumn {
    /** @var bool */
    private $editable = false;
    /** @var Contact */
    private $viewer;
    /** @var Contact */
    private $user;
    /** @var bool */
    private $not_me;
    /** @var string */
    private $prefix;
    /** @var bool */
    private $show_conflict;
    /** @var bool */
    private $all = false;
    /** @var bool */
    private $secondary_sort_topic_score = false;
    /** @var ScoreInfo */
    private $statistics;
    /** @var ?ScoreInfo */
    private $override_statistics;
    function __construct(Conf $conf, $cj) {
        parent::__construct($conf, $cj);
        $this->override = PaperColumn::OVERRIDE_IFEMPTY;
        if (isset($cj->user)) {
            $this->user = $conf->pc_member_by_email($cj->user);
        }
        $this->editable = $cj->edit ?? false;
    }
    function view_option_schema() {
        return ["topics", "topicscore/topics", "topic_score/topics", "edit", "all"];
    }
    function prepare(PaperList $pl, $visible) {
        $this->viewer = $pl->user;
        $this->user = $this->user ?? $pl->reviewer_user();
        $this->not_me = $this->user->contactId !== $this->viewer->contactId;
        if (!$this->viewer->isPC
            || ($this->not_me && !$this->viewer->can_view_preference(null))) {
            return false;
        }
        $this->editable = $this->view_option("edit") ?? $this->editable;
        if ($this->editable) {
            $this->override = PaperColumn::OVERRIDE_BOTH;
            $this->className = "pl_editrevpref";
        }
        $this->secondary_sort_topic_score = $this->view_option("topics") ?? false;
        $this->all = $this->view_option("all") ?? false;
        if ($visible || $this->secondary_sort_topic_score) {
            $pl->qopts["topics"] = 1;
        }
        $this->prefix =  "";
        if ($this->as_row) {
            $this->prefix = $this->viewer->reviewer_html_for($this->user) . " ";
        }
        return true;
    }
    /** @return PaperReviewPreference */
    private function sortable_preference(PaperInfo $row) {
        if ($this->not_me
            && ($this->editable
                ? !$this->viewer->allow_view_preference($row)
                : !$this->viewer->can_view_preference($row))) {
            return PaperReviewPreference::make_sentinel();
        }
        $pf = $row->preference($this->user);
        if (!$pf->exists()) {
            if ($this->not_me && !$this->user->can_view_paper($row)) {
                return PaperReviewPreference::make_sentinel();
            } else if ($row->has_conflict($this->user)) {
                return new PaperReviewPreference($this->editable ? -0.00001 : -PHP_INT_MAX, null);
            }
        }
        return $pf;
    }
    function compare(PaperInfo $a, PaperInfo $b, PaperList $pl) {
        $cmp = PaperReviewPreference::compare($this->sortable_preference($a), $this->sortable_preference($b));
        if ($cmp === 0 && $this->secondary_sort_topic_score) {
            $cmp = $a->topic_interest_score($this->user) <=> $b->topic_interest_score($this->user);
        }
        return $cmp;
    }
    function reset(PaperList $pl) {
        if ($this->show_conflict === null) {
            $pfcol = $rtuid = [];
            foreach ($pl->vcolumns() as $fdef) {
                if ($fdef instanceof ReviewerType_PaperColumn
                    || $fdef instanceof AssignReview_PaperColumn) {
                    $rtuid[] = $fdef->contact()->contactId;
                } else if ($fdef instanceof Preference_PaperColumn) {
                    $pfcol[] = $fdef;
                }
            }
            $this->show_conflict = count($pfcol) !== 1
                || count($rtuid) !== 1
                || $rtuid[0] !== $this->user->contactId;
        }
        $this->statistics = new ScoreInfo;
        $this->override_statistics = null;
    }
    function header(PaperList $pl, $is_text) {
        if ($this->user === $this->viewer || $this->as_row) {
            return "Preference";
        } else if ($is_text) {
            return $this->viewer->reviewer_text_for($this->user) . " preference";
        } else {
            return $this->viewer->reviewer_html_for($this->user) . "<br>preference";
        }
    }
    function content_empty(PaperList $pl, PaperInfo $row) {
        return $this->not_me && !$this->viewer->allow_view_preference($row);
    }
    function content(PaperList $pl, PaperInfo $row) {
        $pf = $row->preference($this->user);
        $pf_exists = $pf->exists();
        $conflicted = $row->has_conflict($this->user);
        $editable = $this->editable
            && (!$this->not_me || $this->user->can_view_paper($row))
            && ($this->all || $this->user->pc_track_assignable($row));

        // compute HTML
        $t = "";
        if ($this->as_row) {
            if ($pf_exists) {
                $t = $this->prefix . " " . $pf->unparse_span();
            }
        } else if ($editable) {
            $iname = "revpref" . $row->paperId;
            if ($this->not_me) {
                $iname .= "u" . $this->user->contactId;
            }
            $pft = $pf_exists ? $pf->unparse() : "";
            $t = "<input name=\"{$iname}\" class=\"uikd uich revpref\" value=\"{$pft}\" type=\"text\" size=\"4\" tabindex=\"2\" placeholder=\"0\">";
            if ($conflicted && $this->show_conflict) {
                $t .= " " . review_type_icon(-1);
            }
        } else if (!$conflicted || $pf_exists) {
            $t = str_replace("-", "−" /* U+2212 */, $pf->unparse());
        } else if ($this->show_conflict) {
            $t = review_type_icon(-1);
        }

        // account for statistics and maybe wrap HTML in conflict
        if ($this->not_me
            && !$editable
            && !$pl->user->can_view_preference($row)
            && $t !== "") {
            $tag = $this->as_row ? "div" : "span";
            $t = "<{$tag} class=\"fx5\">{$t}</{$tag}>";
            if (!$this->override_statistics) {
                $this->override_statistics = clone $this->statistics;
            }
            if ($pf_exists) {
                $this->override_statistics->add($pf->preference);
            }
        } else if ($pf_exists) {
            $this->statistics->add($pf->preference);
            if ($this->override_statistics) {
                $this->override_statistics->add($pf->preference);
            }
        }

        return $t;
    }
    function text(PaperList $pl, PaperInfo $row) {
        if (!$this->not_me || $this->viewer->can_view_preference($row)) {
            return $row->preference($this->user)->unparse();
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
            $t = $pl->wrap_conflict($t, $tt);
        }
        return $t;
    }

    static function expand($name, XtParams $xtp, $xfj, $m) {
        if (!($fj = (array) $xtp->conf->basic_paper_column("pref", $xtp->user))) {
            return null;
        }
        $rs = [];
        foreach (ContactSearch::make_pc($m[1], $xtp->user)->users() as $u) {
            if ($u->roles & Contact::ROLE_PC) {
                $fj["name"] = "pref:{$u->email}";
                $fj["user"] = $u->email;
                $rs[] = (object) $fj;
            }
        }
        if (empty($rs)) {
            PaperColumn::column_error($xtp, "<0>PC member ‘{$m[1]}’ not found");
        }
        return $rs;
    }

    static function completions(Contact $user, $xfj) {
        if ($user->isPC && $user->can_view_preference(null)) {
            return ["pref:{user}"];
        } else {
            return [];
        }
    }
}
