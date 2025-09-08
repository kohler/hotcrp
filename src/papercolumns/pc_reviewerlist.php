<?php
// pc_reviewerlist.php -- HotCRP helper classes for paper list content
// Copyright (c) 2006-2023 Eddie Kohler; see LICENSE.

class ReviewerList_PaperColumn extends PaperColumn {
    /** @var bool */
    private $pref = false;
    /** @var bool */
    private $topics = false;
    /** @var ?ReviewSearchMatcher */
    private $rsm;
    /** @var ?list<?SearchTerm> */
    private $hlterms;
    function __construct(Conf $conf, $cj) {
        parent::__construct($conf, $cj);
        if (isset($cj->rematch)) {
            $this->rsm = new ReviewSearchMatcher;
            foreach ($cj->rematch as $m) {
                $this->rsm->apply_review_word($m, $conf);
            }
        }
    }
    function view_option_schema() {
        return ["pref", "prefs/pref", "preference/pref", "preferences/pref", "topics", "topic/topics", "topicscore/topics"];
    }
    function prepare(PaperList $pl, $visible) {
        if (!$pl->user->can_view_some_review_identity()) {
            return false;
        }
        $this->topics = ($this->view_option("topics") ?? false)
            && $pl->conf->has_topics();
        $this->pref = ($this->view_option("pref") ?? $this->topics ?? false)
            && $pl->user->allow_view_preference(null);
        $pl->qopts["reviewSignatures"] = true;
        if ($this->pref) {
            $pl->qopts["allReviewerPreference"] = true;
            if ($this->topics && $pl->conf->has_topics()) {
                $pl->qopts["topics"] = true;
            }
        }
        if ($pl->conf->review_blindness() === Conf::BLIND_OPTIONAL
            || $this->pref) {
            $this->override = PaperColumn::OVERRIDE_BOTH;
        } else {
            $this->override = PaperColumn::OVERRIDE_IFEMPTY;
        }
        $nhlt = 0;
        $hlt = [];
        foreach ($pl->search->group_slice_terms() as $gt) {
            if ($gt->about() === SearchTerm::ABOUT_REVIEW) {
                $hlt[] = $gt;
                ++$nhlt;
            } else {
                $hlt[] = null;
            }
        }
        if ($nhlt > 0) {
            $this->hlterms = $hlt;
        }
        return true;
    }
    function content_empty(PaperList $pl, PaperInfo $prow) {
        return !$pl->user->can_view_review_identity($prow, null);
    }
    function content(PaperList $pl, PaperInfo $prow) {
        // see also search.php > getaction == "reviewers"
        $x = [];
        $pref = $this->pref && $pl->user->can_view_preference($prow);
        foreach ($prow->reviews_as_display() as $xrow) {
            if ($pl->user->can_view_review_identity($prow, $xrow)
                && (!$this->rsm || $this->rsm->test_review($pl->user, $prow, $xrow))) {
                $ranal = $pl->make_review_analysis($xrow, $prow);
                $t = $pl->user->reviewer_html_for($xrow) . " " . $ranal->icon_html(false);
                if ($pref) {
                    $pf = $prow->preference($xrow->contactId);
                    $tv = $this->topics ? $prow->topic_interest_score($xrow->contactId) : null;
                    $t .= " " . $pf->unparse_span($tv);
                }
                if (($hlt = $this->hlterms[$prow->_search_group] ?? null)
                    && $hlt->test($prow, $xrow)) {
                    $t = "<span class=\"highlightmark taghh\">{$t}</span>";
                }
                $x[] = "<li>{$t}</li>";
            }
        }
        return $x ? "<ul class=\"comma\">" . join("", $x) . "</ul>" : "";
    }
    function text(PaperList $pl, PaperInfo $prow) {
        $x = [];
        $pref = $this->pref && $pl->user->can_view_preference($prow);
        foreach ($prow->reviews_as_display() as $xrow) {
            if ($pl->user->can_view_review_identity($prow, $xrow)
                && (!$this->rsm || $this->rsm->test_review($pl->user, $prow, $xrow))) {
                $t = $pl->user->reviewer_text_for($xrow);
                if ($pref) {
                    $pf = $prow->preference($xrow->contactId);
                    $t .= " P" . unparse_number_pm_text($pf->preference) . unparse_expertise($pf->expertise);
                    if ($this->topics
                        && $pf->preference === 0
                        && ($tv = $prow->topic_interest_score($xrow->contactId))) {
                        $t .= " T" . unparse_number_pm_text($tv);
                    }
                }
                $x[] = $t;
            }
        }
        return join("; ", $x);
    }
}
