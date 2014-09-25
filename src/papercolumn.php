<?php
// papercolumn.php -- HotCRP helper classes for paper list content
// HotCRP is Copyright (c) 2006-2014 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

class PaperColumn extends Column {
    static private $by_name = array();
    static private $factories = array();

    public function __construct($name, $flags, $extra = array()) {
        parent::__construct($name, $flags, $extra);
    }

    public static function lookup_local($name) {
        return defval(self::$by_name, $name, null);
    }

    public static function lookup($name) {
        if (isset(self::$by_name[$name]))
            return self::$by_name[$name];
        foreach (self::$factories as $f)
            if (str_starts_with(strtolower($name), $f[0])
                && ($x = $f[1]->make_field($name)))
                return $x;
        return null;
    }

    public static function register($fdef) {
        assert(!isset(self::$by_name[$fdef->name]));
        self::$by_name[$fdef->name] = $fdef;
        for ($i = 1; $i < func_num_args(); ++$i)
            self::$by_name[func_get_arg($i)] = $fdef;
        return $fdef;
    }
    public static function register_factory($prefix, $f) {
        self::$factories[] = array(strtolower($prefix), $f);
    }
    public static function register_synonym($new_name, $old_name) {
        $fdef = self::$by_name[$old_name];
        assert($fdef && !isset(self::$by_name[$new_name]));
        self::$by_name[$new_name] = $fdef;
    }

    public function prepare($pl, &$queryOptions, $visible) {
        return true;
    }

    public function analyze($pl, &$rows) {
    }

    public function sort_prepare($pl, &$rows, $sorter) {
    }
    public function id_sorter($a, $b) {
        return $a->paperId - $b->paperId;
    }

    public function header($pl, $row, $ordinal) {
        return "&lt;" . htmlspecialchars($this->name) . "&gt;";
    }

    public function col() {
        return "<col />";
    }

    public function content_empty($pl, $row) {
        return false;
    }

    public function content($pl, $row) {
        return "";
    }
    public function text($pl, $row) {
        return "";
    }
}

class IdPaperColumn extends PaperColumn {
    public function __construct() {
        parent::__construct("id", Column::VIEW_COLUMN,
                            array("minimal" => true, "sorter" => "id_sorter"));
    }
    public function header($pl, $row, $ordinal) {
        return "ID";
    }
    public function col() {
        return "<col width='0*' />";
    }
    public function content($pl, $row) {
        $href = $pl->_paperLink($row);
        return "<a href=\"$href\" class=\"pnum\" tabindex=\"4\">#$row->paperId</a>";
    }
    public function text($pl, $row) {
        return $row->paperId;
    }
}

class SelectorPaperColumn extends PaperColumn {
    public $is_selector = true;
    public function __construct($name, $extra) {
        parent::__construct($name, Column::VIEW_COLUMN, $extra);
    }
    public function prepare($pl, &$queryOptions, $visible) {
        global $Conf;
        if ($this->name == "selconf" && !$pl->contact->privChair)
            return false;
        if ($this->name == "selconf" || $this->name == "selunlessconf")
            $queryOptions["reviewer"] = $pl->reviewer_cid();
        if ($this->name == "selconf")
            $Conf->footerScript("add_conflict_ajax()");
        return true;
    }
    public function header($pl, $row, $ordinal) {
        if ($this->name == "selconf")
            return "Conflict?";
        else
            return ($ordinal ? "&nbsp;" : "");
    }
    public function col() {
        return "<col width='0*' />";
    }
    private function checked($pl, $row) {
        return ($this->name == "selon"
                || ($this->name == "selconf" && $row->reviewerConflictType > 0))
            && (!$pl->papersel || defval($pl->papersel, $row->paperId, 1));
    }
    public function content($pl, $row) {
        if ($this->name == "selunlessconf" && $row->reviewerConflictType)
            return "";
        $pl->any->sel = true;
        $c = "";
        if ($this->checked($pl, $row)) {
            $c .= " checked='checked'";
            unset($row->folded);
        }
        if ($this->name == "selconf" && $row->reviewerConflictType >= CONFLICT_AUTHOR)
            $c .= " disabled='disabled'";
        if ($this->name != "selconf")
            $c .= " onclick='pselClick(event,this)'";
        $t = "<span class=\"pl_rownum fx6\">" . $pl->count . ". </span>" . "<input type='checkbox' class='cb' name='pap[]' value='$row->paperId' tabindex='3' id='psel$pl->count' $c/>";
        return $t;
    }
    public function text($pl, $row) {
        return $this->checked($pl, $row) ? "X" : "";
    }
}

class TitlePaperColumn extends PaperColumn {
    public function __construct() {
        parent::__construct("title", Column::VIEW_COLUMN,
                            array("minimal" => true, "sorter" => "title_sorter"));
    }
    public function title_sorter($a, $b) {
        return strcasecmp($a->title, $b->title);
    }
    public function header($pl, $row, $ordinal) {
        return "Title";
    }
    public function content($pl, $row) {
        $href = $pl->_paperLink($row);
        $x = Text::highlight($row->title, defval($pl->search->matchPreg, "title"));
        return "<a href=\"$href\" class=\"ptitle\" tabindex=\"5\">" . $x . "</a>" . $pl->_contentDownload($row);
    }
    public function text($pl, $row) {
        return $row->title;
    }
}

class StatusPaperColumn extends PaperColumn {
    private $is_long;
    public function __construct($name, $is_long, $extra = 0) {
        parent::__construct($name, Column::VIEW_COLUMN,
                            array("cssname" => "status", "sorter" => "status_sorter"));
        $this->is_long = $is_long;
    }
    public function sort_prepare($pl, &$rows, $sorter) {
        $force = $pl->search->limitName != "a" && $pl->contact->privChair;
        foreach ($rows as $row)
            $row->_status_sort_info = ($pl->contact->canViewDecision($row, $force) ? $row->outcome : -10000);
    }
    public function status_sorter($a, $b) {
        $x = $b->_status_sort_info - $a->_status_sort_info;
        $x = $x ? $x : ($a->timeWithdrawn > 0) - ($b->timeWithdrawn > 0);
        $x = $x ? $x : ($b->timeSubmitted > 0) - ($a->timeSubmitted > 0);
        return $x ? $x : ($b->paperStorageId > 1) - ($a->paperStorageId > 1);
    }
    public function header($pl, $row, $ordinal) {
        return "Status";
    }
    public function content($pl, $row) {
        if ($row->timeSubmitted <= 0 && $row->timeWithdrawn <= 0)
            $pl->any->need_submit = true;
        if ($row->outcome > 0 && $pl->contact->canViewDecision($row))
            $pl->any->accepted = true;
        if ($row->outcome > 0 && $row->timeFinalSubmitted <= 0
            && $pl->contact->canViewDecision($row))
            $pl->any->need_final = true;
        $status_info = $pl->contact->paper_status_info($row, $pl->search->limitName != "a" && $pl->contact->allow_administer($row));
        if (!$this->is_long && $status_info[0] == "pstat_sub")
            return "";
        return "<span class=\"pstat $status_info[0]\">" . htmlspecialchars($status_info[1]) . "</span>";
    }
    public function text($pl, $row) {
        $status_info = $pl->contact->paper_status_info($row, $pl->search->limitName != "a" && $pl->contact->allow_administer($row));
        return $status_info[1];
    }
}

class ReviewStatusPaperColumn extends PaperColumn {
    private $auview;
    public function __construct() {
        global $Conf;
        parent::__construct("revstat", Column::VIEW_COLUMN,
                            array("sorter" => "review_status_sorter"));
        $this->auview = $Conf->timeAuthorViewReviews();
    }
    public function prepare($pl, &$queryOptions, $visible) {
        return $pl->contact->is_reviewer() || $this->auview
            || $pl->contact->privChair;
    }
    public function sort_prepare($pl, &$rows, $sorter) {
        foreach ($rows as $row)
            $row->_review_status_sort_info = !$this->content_empty($pl, $row);
    }
    public function review_status_sorter($a, $b) {
        $av = ($a->_review_status_sort_info ? $a->reviewCount : 2147483647);
        $bv = ($b->_review_status_sort_info ? $b->reviewCount : 2147483647);
        if ($av == $bv) {
            $av = ($a->_review_status_sort_info ? $a->startedReviewCount : 2147483647);
            $bv = ($b->_review_status_sort_info ? $b->startedReviewCount : 2147483647);
        }
        return ($av < $bv ? 1 : ($av == $bv ? 0 : -1));
    }
    public function header($pl, $row, $ordinal) {
        return "<span class='hastitle' title='\"1/2\" means 1 complete review out of 2 assigned reviews'>#&nbsp;Reviews</span>";
    }
    public function col() {
        return "<col width='0*' />";
    }
    public function content_empty($pl, $row) {
        return !($pl->contact->allow_administer($row)
                 || ($pl->contact->isPC && ($row->conflictType == 0 || $this->auview))
                 || $row->reviewType > 0
                 || ($row->conflictType >= CONFLICT_AUTHOR && $this->auview));
    }
    public function content($pl, $row) {
        if ($row->reviewCount != $row->startedReviewCount)
            return "<b>$row->reviewCount</b>/$row->startedReviewCount";
        else
            return "<b>$row->reviewCount</b>";
    }
    public function text($pl, $row) {
        if ($row->reviewCount != $row->startedReviewCount)
            return "$row->reviewCount/$row->startedReviewCount";
        else
            return $row->reviewCount;
    }
}

class AuthorsPaperColumn extends PaperColumn {
    private $aufull;
    public function __construct() {
        parent::__construct("authors", Column::VIEW_ROW | Column::FOLDABLE);
    }
    public function header($pl, $row, $ordinal) {
        return "Authors";
    }
    public function prepare($pl, &$queryOptions, $visible) {
        $this->aufull = !$pl->is_folded("aufull");
        return true;
    }
    private function full_authors($row) {
        $lastaff = "";
        $anyaff = false;
        $aus = $affaus = array();
        foreach ($row->authorTable as $au) {
            if ($au[3] != $lastaff && count($aus)) {
                $affaus[] = array($aus, $lastaff);
                $aus = array();
                $anyaff = $anyaff || ($au[3] != "");
            }
            $lastaff = $au[3];
            $aus[] = $au[0] || $au[1] ? trim("$au[0] $au[1]") : $au[2];
        }
        if (count($aus))
            $affaus[] = array($aus, $lastaff);
        foreach ($affaus as &$ax)
            if ($ax[1] === "" && $anyaff)
                $ax[1] = "unaffiliated";
        return $affaus;
    }
    public function content($pl, $row) {
        if (!$pl->contact->can_view_authors($row, true))
            return "";
        cleanAuthor($row);
        $aus = array();
        $highlight = defval($pl->search->matchPreg, "authorInformation", "");
        if ($this->aufull) {
            $affaus = $this->full_authors($row);
            foreach ($affaus as &$ax) {
                foreach ($ax[0] as &$axn)
                    $axn = Text::highlight($axn, $highlight);
                unset($axn);
                $aff = Text::highlight($ax[1], $highlight);
                $ax = commajoin($ax[0]) . ($aff ? " <span class='auaff'>($aff)</span>" : "");
            }
            return commajoin($affaus);
        } else if (!$highlight) {
            foreach ($row->authorTable as $au)
                $aus[] = Text::abbrevname_html($au);
            return join(", ", $aus);
        } else {
            foreach ($row->authorTable as $au) {
                $first = htmlspecialchars($au[0]);
                $x = Text::highlight(trim("$au[0] $au[1]"), $highlight, $nm);
                if ((!$nm || substr($x, 0, strlen($first)) == $first)
                    && ($initial = Text::initial($first)) != "")
                    $x = $initial . substr($x, strlen($first));
                $aus[] = $x;
            }
            return join(", ", $aus);
        }
    }
    public function text($pl, $row) {
        if (!$pl->contact->can_view_authors($row, true))
            return "";
        cleanAuthor($row);
        if ($this->aufull) {
            $affaus = $this->full_authors($row);
            foreach ($affaus as &$ax)
                $ax = commajoin($ax[0]) . ($ax[1] ? " ($ax[1])" : "");
            return commajoin($affaus);
        } else {
            $aus = array();
            foreach ($row->authorTable as $au)
                $aus[] = Text::abbrevname_text($au);
            return join(", ", $aus);
        }
    }
}

class CollabPaperColumn extends PaperColumn {
    public function __construct() {
        parent::__construct("collab", Column::VIEW_ROW | Column::FOLDABLE);
    }
    public function prepare($pl, &$queryOptions, $visible) {
        global $Conf;
        return !!$Conf->setting("sub_collab");
    }
    public function header($pl, $row, $ordinal) {
        return "Collaborators";
    }
    public function content_empty($pl, $row) {
        return ($row->collaborators == ""
                || strcasecmp($row->collaborators, "None") == 0
                || !$pl->contact->can_view_authors($row, true));
    }
    public function content($pl, $row) {
        $x = "";
        foreach (explode("\n", $row->collaborators) as $c)
            $x .= ($x === "" ? "" : ", ") . trim($c);
        return Text::highlight($x, defval($pl->search->matchPreg, "collaborators"));
    }
    public function text($pl, $row) {
        $x = "";
        foreach (explode("\n", $row->collaborators) as $c)
            $x .= ($x === "" ? "" : ", ") . trim($c);
        return $x;
    }
}

class AbstractPaperColumn extends PaperColumn {
    public function __construct() {
        parent::__construct("abstract", Column::VIEW_ROW | Column::FOLDABLE);
    }
    public function header($pl, $row, $ordinal) {
        return "Abstract";
    }
    public function content_empty($pl, $row) {
        return $row->abstract == "";
    }
    public function content($pl, $row) {
        return Text::highlight($row->abstract, defval($pl->search->matchPreg, "abstract"));
    }
    public function text($pl, $row) {
        return $row->abstract;
    }
}

class TopicListPaperColumn extends PaperColumn {
    public function __construct() {
        parent::__construct("topics", Column::VIEW_ROW | Column::FOLDABLE);
    }
    public function prepare($pl, &$queryOptions, $visible) {
        global $Conf;
        if (!$Conf->has_topics())
            return false;
        if ($visible)
            $queryOptions["topics"] = 1;
        return true;
    }
    public function header($pl, $row, $ordinal) {
        return "Topics";
    }
    public function content_empty($pl, $row) {
        return !isset($row->topicIds) || $row->topicIds == "";
    }
    public function content($pl, $row) {
        return join(", ", PaperInfo::unparse_topics($row->topicIds, defval($row, "topicInterest")));
    }
}

class ReviewerTypePaperColumn extends PaperColumn {
    protected $xreviewer;
    public function __construct($name) {
        parent::__construct($name, Column::VIEW_COLUMN,
                            array("sorter" => "reviewer_type_sorter"));
    }
    public function analyze($pl, &$rows) {
        global $Conf;
        // PaperSearch is responsible for access control checking use of
        // `reviewerContact`, but we are careful anyway.
        if ($pl->search->reviewer_cid()
            && $pl->search->reviewer_cid() != $pl->contact->contactId
            && count($rows)) {
            $by_pid = array();
            foreach ($rows as $row)
                $by_pid[$row->paperId] = $row;
            $result = $Conf->qe("select Paper.paperId, reviewType, reviewId, reviewModified, reviewSubmitted, reviewNeedsSubmit, reviewOrdinal, reviewBlind, PaperReview.contactId reviewContactId, requestedBy, reviewToken, reviewRound, conflictType from Paper left join PaperReview on (PaperReview.paperId=Paper.paperId and PaperReview.contactId=" . $pl->search->reviewer_cid() . ") left join PaperConflict on (PaperConflict.paperId=Paper.paperId and PaperConflict.contactId=" . $pl->search->reviewer_cid() . ") where Paper.paperId in (" . join(",", array_keys($by_pid)) . ") and (PaperReview.contactId is not null or PaperConflict.contactId is not null)");
            while (($xrow = edb_orow($result))) {
                $prow = $by_pid[$xrow->paperId];
                if ($pl->contact->allow_administer($prow)
                    || $pl->contact->canViewReviewerIdentity($prow, $xrow, true)
                    || ($pl->contact->privChair
                        && $xrow->conflictType > 0
                        && !$xrow->reviewType))
                    $prow->_xreviewer = $xrow;
            }
            $this->xreviewer = $pl->search->reviewer();
        } else
            $this->xreviewer = false;
    }
    public function sort_prepare($pl, &$rows, $sorter) {
        if (!$this->xreviewer) {
            foreach ($rows as $row) {
                $row->_reviewer_type_sort_info = $row->reviewType;
                if (!$row->_reviewer_type_sort_info && $row->conflictType)
                    $row->_reviewer_type_sort_info = -$row->conflictType;
            }
        } else {
            foreach ($rows as $row)
                $row->_reviewer_type_sort_info = isset($row->_xreviewer) ? $row->_xreviewer->reviewType : 0;
        }
    }
    public function reviewer_type_sorter($a, $b) {
        return $b->_reviewer_type_sort_info - $a->_reviewer_type_sort_info;
    }
    public function header($pl, $row, $ordinal) {
        if ($this->xreviewer)
            return "<span class='hastitle' title='Reviewer type'>"
                . Text::name_html($this->xreviewer) . "<br />Review</span>";
        else
            return "<span class='hastitle' title='Reviewer type'>Review</span>";
    }
    public function col() {
        return "<col width='0*' />";
    }
    public function content($pl, $row) {
        global $Conf;
        if ($this->xreviewer && !isset($row->_xreviewer))
            $xrow = (object) array("reviewType" => 0, "conflictType" => 0);
        else if ($this->xreviewer)
            $xrow = $row->_xreviewer;
        else
            $xrow = $row;
        if ($xrow->reviewType) {
            $ranal = $pl->_reviewAnalysis($xrow);
            if ($ranal->needsSubmit)
                $pl->any->need_review = true;
            $t = PaperList::_reviewIcon($xrow, $ranal, true);
            if ($ranal->round)
                $t = "<div class='pl_revtype_round'>" . $t . "</div>";
        } else if ($xrow->conflictType > 0)
            $t = review_type_icon(-1);
        else
            $t = review_type_icon(0);
        return $t;
    }
}

class ReviewSubmittedPaperColumn extends PaperColumn {
    public function __construct() {
        parent::__construct("revsubmitted", Column::VIEW_COLUMN, array("cssname" => "text"));
    }
    public function prepare($pl, &$queryOptions, $visible) {
        return !!$pl->contact->isPC;
    }
    public function header($pl, $row, $ordinal) {
        return "Review status";
    }
    public function content_empty($pl, $row) {
        return !$row->reviewId;
    }
    public function content($pl, $row) {
        if (!$row->reviewId)
            return "";
        $ranal = $pl->_reviewAnalysis($row);
        if ($ranal->needsSubmit)
            $pl->any->need_review = true;
        $t = $ranal->completion;
        if ($ranal->needsSubmit && !$ranal->delegated)
            $t = "<strong class='overdue'>$t</strong>";
        if (!$ranal->needsSubmit)
            $t = $ranal->link1 . $t . $ranal->link2;
        return $t;
    }
}

class ReviewDelegationPaperColumn extends PaperColumn {
    public function __construct() {
        parent::__construct("revdelegation", Column::VIEW_COLUMN,
                            array("cssname" => "text",
                                  "sorter" => "review_delegation_sorter"));
    }
    public function prepare($pl, &$queryOptions, $visible) {
        if (!$pl->contact->isPC)
            return false;
        $queryOptions["reviewerName"] = true;
        $queryOptions["allReviewScores"] = true;
        $queryOptions["reviewLimitSql"] = "PaperReview.requestedBy=" . $pl->reviewer_cid();
        return true;
    }
    public function review_delegation_sorter($a, $b) {
        $x = strcasecmp($a->reviewLastName, $b->reviewLastName);
        $x = $x ? $x : strcasecmp($a->reviewFirstName, $b->reviewFirstName);
        return $x ? $x : strcasecmp($a->reviewEmail, $b->reviewEmail);
    }
    public function header($pl, $row, $ordinal) {
        return "Reviewer";
    }
    public function content($pl, $row) {
        global $Conf;
        $t = Text::user_html($row->reviewFirstName, $row->reviewLastName, $row->reviewEmail) . "<br /><small>Last login: ";
        return $t . ($row->reviewLastLogin ? $Conf->printableTimeShort($row->reviewLastLogin) : "Never") . "</small>";
    }
}

class AssignReviewPaperColumn extends ReviewerTypePaperColumn {
    public function __construct() {
        parent::__construct("assrev");
    }
    public function prepare($pl, &$queryOptions, $visible) {
        global $Conf;
        if (!$pl->contact->privChair)
            return false;
        if ($visible > 0)
            $Conf->footerScript("add_assrev_ajax()");
        $queryOptions["reviewer"] = $pl->reviewer_cid();
        return true;
    }
    public function analyze($pl, &$rows) {
        $this->xreviewer = false;
    }
    public function header($pl, $row, $ordinal) {
        return "Assignment";
    }
    public function content_empty($pl, $row) {
        return !$pl->contact->allow_administer($row);
    }
    public function content($pl, $row) {
        if ($row->reviewerConflictType >= CONFLICT_AUTHOR)
            return "<span class='author'>Author</span>";
        $rt = ($row->reviewerConflictType > 0 ? -1 : min(max($row->reviewerReviewType, 0), REVIEW_PRIMARY));
        return Ht::select("assrev$row->paperId",
                           array(0 => "None",
                                 REVIEW_PRIMARY => "Primary",
                                 REVIEW_SECONDARY => "Secondary",
                                 REVIEW_PC => "Optional",
                                 -1 => "Conflict"), $rt,
                           array("tabindex" => 3,
                                 "onchange" => "hiliter(this)"));
    }
}

class DesirabilityPaperColumn extends PaperColumn {
    public function __construct() {
        parent::__construct("desirability", Column::VIEW_COLUMN,
                            array("sorter" => "desirability_sorter"));
    }
    public function prepare($pl, &$queryOptions, $visible) {
        if (!$pl->contact->privChair)
            return false;
        $queryOptions["desirability"] = 1;
        return true;
    }
    public function desirability_sorter($a, $b) {
        return $b->desirability - $a->desirability;
    }
    public function header($pl, $row, $ordinal) {
        return "Desirability";
    }
    public function col() {
        return "<col width='0*' />";
    }
    public function content($pl, $row) {
        return htmlspecialchars(@($row->desirability + 0));
    }
    public function text($pl, $row) {
        return @($row->desirability + 0);
    }
}

class TopicScorePaperColumn extends PaperColumn {
    public function __construct() {
        parent::__construct("topicscore", Column::VIEW_COLUMN,
                            array("sorter" => "topic_score_sorter"));
    }
    public function prepare($pl, &$queryOptions, $visible) {
        global $Conf;
        if (!$Conf->has_topics() || !$pl->contact->isPC)
            return false;
        $queryOptions["reviewer"] = $pl->reviewer_cid();
        $queryOptions["topicInterestScore"] = 1;
        return true;
    }
    public function topic_score_sorter($a, $b) {
        return $b->topicInterestScore - $a->topicInterestScore;
    }
    public function header($pl, $row, $ordinal) {
        return "Topic<br/>score";
    }
    public function col() {
        return "<col width='0*' />";
    }
    public function content($pl, $row) {
        return htmlspecialchars($row->topicInterestScore + 0);
    }
    public function text($pl, $row) {
        return $row->topicInterestScore + 0;
    }
}

class PreferencePaperColumn extends PaperColumn {
    private $editable;
    public function __construct($name, $editable) {
        parent::__construct($name, Column::VIEW_COLUMN,
                            array("sorter" => "preference_sorter"));
        $this->editable = $editable;
    }
    public function prepare($pl, &$queryOptions, $visible) {
        global $Conf;
        if (!$pl->contact->isPC)
            return false;
        $queryOptions["reviewerPreference"] = $queryOptions["topicInterestScore"] = 1;
        $queryOptions["reviewer"] = $pl->reviewer_cid();
        if ($this->editable && $visible > 0) {
            $arg = "ajax=1&amp;setrevpref=1";
            if ($pl->contact->privChair && $pl->reviewer)
                $arg .= "&amp;reviewer=" . $pl->reviewer;
            $Conf->footerScript("add_revpref_ajax('" . hoturl_post_raw("paper", $arg) . "')");
        }
        return true;
    }
    public function preference_sorter($a, $b) {
        $x = $b->reviewerPreference - $a->reviewerPreference;
        return $x ? $x : $b->topicInterestScore - $a->topicInterestScore;
    }
    public function header($pl, $row, $ordinal) {
        return "Preference";
    }
    public function col() {
        return "<col width='0*' />";
    }
    public function content($pl, $row) {
        $pref = unparse_preference($row);
        if ($pl->reviewer
            && $pl->reviewer != $pl->contact->contactId
            && !$pl->contact->allow_administer($row))
            return "N/A";
        else if (!$this->editable)
            return $pref;
        else if ($row->reviewerConflictType > 0)
            return "N/A";
        else
            return "<input type='text' size='4' name='revpref$row->paperId' id='revpref$row->paperId' value=\"$pref\" tabindex='2' />";
    }
    public function text($pl, $row) {
        return @($row->reviewerPreference + 0);
    }
}

class PreferenceListPaperColumn extends PaperColumn {
    private $topics;
    public function __construct($name, $topics) {
        $this->topics = $topics;
        parent::__construct($name, Column::VIEW_ROW | Column::FOLDABLE);
    }
    public function prepare($pl, &$qopts, $visible) {
        if (!$pl->contact->privChair)
            return false;
        $qopts["allReviewerPreference"] = $qopts["allConflictType"] = 1;
        if ($this->topics)
            $queryOptions["topics"] = 1;
        return true;
    }
    public function header($pl, $row, $ordinal) {
        return "Preferences";
    }
    public function content_empty($pl, $row) {
        return !$pl->contact->allow_administer($row);
    }
    public function content($pl, $row) {
        $prefs = PaperList::_rowPreferences($row);
        $ts = array();
        foreach (pcMembers() as $pcid => $pc)
            if (($pref = defval($prefs, $pcid, null))) {
                $pref = $this->topics ? $pref : array_slice($pref, 0, 2);
                if (($pspan = unparse_preference_span($pref)) !== "")
                    $ts[] = '<span class="nw">' . Text::name_html($pc) . $pspan . '</span>';
            }
        return join(", ", $ts);
    }
}

class ReviewerListPaperColumn extends PaperColumn {
    public function __construct() {
        parent::__construct("reviewers", Column::VIEW_ROW | Column::FOLDABLE);
    }
    public function prepare($pl, &$queryOptions, $visible) {
        if (!$pl->contact->canViewReviewerIdentity(true, null, null))
            return false;
        if ($visible) {
            $queryOptions["reviewList"] = 1;
            if ($pl->contact->privChair)
                $queryOptions["allReviewerPreference"] = $queryOptions["topics"] = 1;
        }
        return true;
    }
    public function header($pl, $row, $ordinal) {
        return "Reviewers";
    }
    public function content($pl, $row) {
        $prefs = PaperList::_rowPreferences($row);
        $n = "";
        // see also search.php > getaction == "reviewers"
        if (isset($pl->review_list[$row->paperId])) {
            foreach ($pl->review_list[$row->paperId] as $xrow)
                if ($xrow->lastName) {
                    $ranal = $pl->_reviewAnalysis($xrow);
                    $n .= ($n ? ", " : "");
                    $n .= Text::name_html($xrow);
                    if ($xrow->reviewType >= REVIEW_SECONDARY)
                        $n .= "&nbsp;" . PaperList::_reviewIcon($xrow, $ranal, false);
                    if (($pref = defval($prefs, $xrow->contactId, null)))
                        $n .= unparse_preference_span($pref);
                }
            $n = $pl->maybeConflict($row, $n, $pl->contact->canViewReviewerIdentity($row, null, false));
        }
        return $n;
    }
}

class PCConflictListPaperColumn extends PaperColumn {
    public function __construct() {
        parent::__construct("pcconf", Column::VIEW_ROW | Column::FOLDABLE);
    }
    public function prepare($pl, &$queryOptions, $visible) {
        if (!$pl->contact->privChair)
            return false;
        if ($visible)
            $queryOptions["allConflictType"] = 1;
        return true;
    }
    public function header($pl, $row, $ordinal) {
        return "PC conflicts";
    }
    public function content($pl, $row) {
        $conf = $row->conflicts();
        $y = array();
        foreach (pcMembers() as $id => $pc)
            if (@$conf[$id])
                $y[] = Text::name_html($pc);
        return join(", ", $y);
    }
}

class ConflictMatchPaperColumn extends PaperColumn {
    private $field;
    public function __construct($name, $field) {
        parent::__construct($name, Column::VIEW_ROW);
        $this->field = $field;
    }
    public function prepare($pl, &$queryOptions, $visible) {
        return $pl->contact->privChair;
    }
    public function header($pl, $row, $ordinal) {
        if ($this->field == "authorInformation")
            return "<strong>Potential conflict in authors</strong>";
        else
            return "<strong>Potential conflict in collaborators</strong>";
    }
    public function content_empty($pl, $row) {
        return defval($pl->search->matchPreg, $this->field, "") == "";
    }
    public function content($pl, $row) {
        $preg = defval($pl->search->matchPreg, $this->field, "");
        if ($preg == "")
            return "";
        $text = "";
        $field = $this->field;
        foreach (explode("\n", $row->$field) as $line)
            if (($line = trim($line)) != "") {
                $line = Text::highlight($line, $preg, $n);
                if ($n)
                    $text .= ($text ? "; " : "") . $line;
            }
        if ($text != "")
            unset($row->folded);
        return $text;
    }
}

class TagListPaperColumn extends PaperColumn {
    public function __construct() {
        parent::__construct("tags", Column::VIEW_ROW | Column::FOLDABLE);
    }
    public function prepare($pl, &$queryOptions, $visible) {
        if (!$pl->contact->canViewTags(null))
            return false;
        if ($visible)
            $queryOptions["tags"] = 1;
        return true;
    }
    public function header($pl, $row, $ordinal) {
        return "Tags";
    }
    public function content_empty($pl, $row) {
        return !$pl->contact->canViewTags($row, true);
    }
    public function content($pl, $row) {
        if (($t = $row->paperTags) !== "")
            $t = $pl->tagger->unparse_link_viewable($row->paperTags,
                                                    $pl->search->orderTags,
                                                    $row->conflictType <= 0);
        return $t;
    }
}

class TagPaperColumn extends PaperColumn {
    protected $is_value;
    protected $dtag;
    protected $ctag;
    protected $editable = false;
    static private $sortf_ctr = 0;
    public function __construct($name, $tag, $is_value) {
        parent::__construct($name, Column::VIEW_COLUMN, array("sorter" => "tag_sorter"));
        $this->dtag = $tag;
        $this->is_value = $is_value;
        $this->cssname = ($this->is_value ? "tagval" : "tag");
    }
    public function make_field($name) {
        $p = strpos($name, ":") ? : strpos($name, "#");
        return parent::register(new TagPaperColumn($name, substr($name, $p + 1), $this->is_value));
    }
    public function prepare($pl, &$queryOptions, $visible) {
        if (!$pl->contact->canViewTags(null))
            return false;
        $tagger = new Tagger($pl->contact);
        if (!($ctag = $tagger->check($this->dtag, Tagger::NOVALUE)))
            return false;
        $this->ctag = " $ctag#";
        $queryOptions["tags"] = 1;
        return true;
    }
    protected function _tag_value($row) {
        if (($p = strpos($row->paperTags, $this->ctag)) === false)
            return null;
        else
            return (int) substr($row->paperTags, $p + strlen($this->ctag));
    }
    public function sort_prepare($pl, &$rows, $sorter) {
        global $Conf;
        $sorter->sortf = $sortf = "_tag_sort_info." . self::$sortf_ctr;
        ++self::$sortf_ctr;
        $careful = !$pl->contact->privChair
            && $Conf->setting("tag_seeall") <= 0;
        $unviewable = $empty = $sorter->reverse ? -2147483647 : 2147483647;
        if ($this->editable)
            $empty = $sorter->reverse ? -2147483646 : 2147483646;
        foreach ($rows as $row)
            if ($careful && !$pl->contact->canViewTags($row, true))
                $row->$sortf = $unviewable;
            else if (($row->$sortf = $this->_tag_value($row)) === null)
                $row->$sortf = $empty;
    }
    public function tag_sorter($a, $b, $sorter) {
        $sortf = $sorter->sortf;
        return $a->$sortf < $b->$sortf ? -1 :
            ($a->$sortf == $b->$sortf ? 0 : 1);
    }
    public function header($pl, $row, $ordinal) {
        return "#$this->dtag";
    }
    public function content_empty($pl, $row) {
        return !$pl->contact->canViewTags($row, true);
    }
    public function content($pl, $row) {
        if (($v = $this->_tag_value($row)) === null)
            return "";
        else if ($v === 0 && !$this->is_value)
            return "&#x2713;";
        else
            return $v;
    }
    public function text($pl, $row) {
        if (($v = $this->_tag_value($row)) === null)
            return "";
        else if ($v === 0 && !$this->is_value)
            return "X";
        else
            return $v;
    }
}

class EditTagPaperColumn extends TagPaperColumn {
    public function __construct($name, $tag, $is_value) {
        parent::__construct($name, $tag, $is_value);
        $this->cssname = ($this->is_value ? "edittagval" : "edittag");
        $this->editable = true;
    }
    public function make_field($name) {
        $p = strpos($name, ":") ? : strpos($name, "#");
        return parent::register(new EditTagPaperColumn($name, substr($name, $p + 1), $this->is_value));
    }
    public function prepare($pl, &$queryOptions, $visible) {
        global $Conf;
        if (($p = parent::prepare($pl, $queryOptions, $visible))
            && $visible > 0) {
            $Conf->footerHtml(
                 Ht::form(hoturl_post("paper", "settags=1&amp;forceShow=1"),
                           array("id" => "edittagajaxform",
                                 "style" => "display:none")) . "<div>"
                 . Ht::hidden("p") . Ht::hidden("addtags")
                 . Ht::hidden("deltags") . "</div></form>",
                 "edittagajaxform");
            $sorter = @$pl->sorters[0];
            if (("edit" . $sorter->type == $this->name
                 || $sorter->type == $this->name)
                && !$sorter->reverse
                && !$pl->search->thenmap
                && $this->is_value)
                $Conf->footerScript("add_edittag_ajax('$this->dtag')");
            else
                $Conf->footerScript("add_edittag_ajax()");
        }
        return $p;
    }
    public function content($pl, $row) {
        $v = $this->_tag_value($row);
        if (!$this->is_value)
            return "<input type='checkbox' class='cb' name='tag:$this->dtag $row->paperId' value='x' tabindex='6'"
                . ($v !== null ? " checked='checked'" : "") . " />";
        else
            return "<input type='text' size='4' name='tag:$this->dtag $row->paperId' value=\""
                . ($v !== null ? htmlspecialchars($v) : "") . "\" tabindex='6' />";
    }
}

class ScorePaperColumn extends PaperColumn {
    public $score;
    public $max_score;
    private $form_field;
    private static $registered = array();
    public function __construct($score) {
        parent::__construct($score, Column::VIEW_COLUMN | Column::FOLDABLE,
                            array("sorter" => "score_sorter"));
        $this->minimal = true;
        $this->cssname = "score";
        $this->score = $score;
    }
    public static function lookup_all() {
        return self::$registered;
    }
    public static function register_score($fdef, $order) {
        PaperColumn::register($fdef);
        if ($order !== false) {
            self::$registered[$order] = $fdef;
            ksort(self::$registered);
        }
    }
    public function make_field($name) {
        $rf = reviewForm();
        if ((($f = $rf->field($name))
             || (($name = $rf->unabbreviateField($name))
                 && ($f = $rf->field($name))))
            && $f->has_options)
            return parent::lookup_local($name);
        return null;
    }
    public function prepare($pl, &$queryOptions, $visible) {
        if (!$pl->scoresOk)
            return false;
        $rf = reviewForm();
        $this->form_field = $rf->field($this->score);
        if ($visible) {
            $auview = $pl->contact->viewReviewFieldsScore(null, true);
            if ($this->form_field->view_score <= $auview)
                return false;
            if (!isset($queryOptions["scores"]))
                $queryOptions["scores"] = array();
            $queryOptions["scores"][$this->score] = true;
            $this->max_score = count($this->form_field->options);
        }
        return true;
    }
    public function sort_prepare($pl, &$rows, $sorter) {
        $scoreName = $this->score . "Scores";
        $view_score = $this->form_field->view_score;
        foreach ($rows as $row)
            if ($pl->contact->canViewReview($row, $view_score, null))
                $pl->score_analyze($row, $scoreName, $this->max_score,
                                   $pl->sorters[0]->score);
            else
                $pl->score_reset($row);
        $this->_textual_sort =
            ($pl->sorters[0]->score == "M" || $pl->sorters[0]->score == "C"
             || $pl->sorters[0]->score == "Y");
    }
    public function score_sorter($a, $b) {
        if ($this->_textual_sort)
            return strcmp($b->_sort_info, $a->_sort_info);
        else {
            $x = $b->_sort_info - $a->_sort_info;
            $x = $x ? $x : $b->_sort_average - $a->_sort_average;
            return $x < 0 ? -1 : ($x == 0 ? 0 : 1);
        }
    }
    public function header($pl, $row, $ordinal) {
        return $this->form_field->web_abbreviation();
    }
    public function col() {
        return "<col width='0*' />";
    }
    public function content_empty($pl, $row) {
        return !$pl->contact->canViewReview($row, $this->form_field->view_score, true);
    }
    public function content($pl, $row) {
        $allowed = $pl->contact->canViewReview($row, $this->form_field->view_score, false);
        $fname = $this->score . "Scores";
        if (($allowed || $pl->contact->allow_administer($row))
            && $row->$fname) {
            $t = $this->form_field->unparse_graph($row->$fname, 1, defval($row, $this->score));
            if (!$allowed)
                $t = "<span class='fx5'>$t</span>";
            return $t;
        } else
            return "";
    }
}

class FormulaPaperColumn extends PaperColumn {
    private static $registered = array();
    public static $list = array();
    public function __construct($name, $formula) {
        parent::__construct($name, Column::VIEW_COLUMN | Column::FOLDABLE,
                            array("minimal" => true, "sorter" => "formula_sorter"));
        $this->cssname = "formula";
        $this->formula = $formula;
        if ($formula && @$formula->formulaId)
            self::$list[$formula->formulaId] = $formula;
    }
    public static function lookup_all() {
        return self::$registered;
    }
    public static function register($fdef) {
        PaperColumn::register($fdef);
        self::$registered[] = $fdef;
    }
    public function make_field($name) {
        foreach (self::$registered as $col)
            if ($col->formula->name == $name)
                return $col;
        if (strpos($name, "(") !== false
            && ($fexpr = Formula::parse($name, true))) {
            $fdef = new FormulaPaperColumn("formulax" . (count(self::$registered) + 1),
                                           new FormulaInfo($name, $fexpr));
            self::register($fdef);
            return $fdef;
        }
        return null;
    }
    public function prepare($pl, &$queryOptions, $visible) {
        global $ConfSitePATH;
        $revView = 0;
        if ($pl->contact->is_reviewer()
            && $pl->search->limitName != "a")
            $revView = $pl->contact->viewReviewFieldsScore(null, true);
        if (!$pl->scoresOk
            || $this->formula->authorView <= $revView)
            return false;
        if (!($expr = Formula::parse($this->formula->expression, true)))
            return false;
        $this->formula_function = Formula::compile_function($expr, $pl->contact);
        Formula::add_query_options($queryOptions, $expr, $pl->contact);
        return true;
    }
    public function sort_prepare($pl, &$rows, $sorter) {
        $formulaf = $this->formula_function;
        $this->formula_sorter = $sorter = "_formula_sort_info." . $this->formula->name;
        foreach ($rows as $row)
            $row->$sorter = $formulaf($row, $pl->contact, "s");
    }
    public function formula_sorter($a, $b) {
        $sorter = $this->formula_sorter;
        return $a->$sorter < $b->$sorter ? -1
            : ($a->$sorter == $b->$sorter ? 0 : 1);
    }
    public function header($pl, $row, $ordinal) {
        if ($this->formula->heading == "")
            $x = $this->formula->name;
        else
            $x = $this->formula->heading;
        if ($this->formula->headingTitle
            && $this->formula->headingTitle != $x)
            return "<span class=\"hastitle\" title=\"" . htmlspecialchars($this->formula->headingTitle) . "\">" . htmlspecialchars($x) . "</span>";
        else
            return htmlspecialchars($x);
    }
    public function col() {
        return "<col width='0*' />";
    }
    public function content($pl, $row) {
        $formulaf = $this->formula_function;
        $t = $formulaf($row, $pl->contact, "h");
        if ($row->conflictType > 0 && $pl->contact->allow_administer($row))
            return "<span class='fn5'>$t</span><span class='fx5'>"
                . $formulaf($row, $pl->contact, "h", true) . "</span>";
        else
            return $t;
    }
}

class TagReportPaperColumn extends PaperColumn {
    private static $registered = array();
    public function __construct($tag) {
        parent::__construct("tagrep_" . preg_replace('/\W+/', '_', $tag),
                            Column::VIEW_ROW | Column::FOLDABLE);
        $this->cssname = "tagrep";
        $this->tag = $tag;
    }
    public static function lookup_all() {
        return self::$registered;
    }
    public static function register($fdef) {
        PaperColumn::register($fdef);
        self::$registered[] = $fdef;
    }
    public function prepare($pl, &$queryOptions, $visible) {
        if (!$pl->contact->privChair)
            return false;
        if ($visible)
            $queryOptions["tags"] = 1;
        return true;
    }
    public function header($pl, $row, $ordinal) {
        return "“" . $this->tag . "” tag report";
    }
    public function content_empty($pl, $row) {
        return !$pl->contact->canViewTags($row, true);
    }
    public function content($pl, $row) {
        if (($t = $row->paperTags) === "")
            return "";
        $a = array();
        foreach (pcMembers() as $pcm) {
            $mytag = " " . $pcm->contactId . "~" . $this->tag . "#";
            if (($p = strpos($t, $mytag)) !== false)
                $a[] = Text::name_html($pcm) . " (#" . ((int) substr($t, $p + strlen($mytag))) . ")";
        }
        return join(", ", $a);
    }
}

class TimestampPaperColumn extends PaperColumn {
    public function __construct() {
        parent::__construct("timestamp", Column::VIEW_COLUMN,
                            array("sorter" => "update_time_sorter"));
    }
    public function update_time_sorter($a, $b) {
        $at = max($a->timeFinalSubmitted, $a->timeSubmitted, 0);
        $bt = max($b->timeFinalSubmitted, $b->timeSubmitted, 0);
        return $at > $bt ? -1 : ($at == $bt ? 0 : 1);
    }
    public function header($pl, $row, $ordinal) {
        return "Timestamp";
    }
    public function content_empty($pl, $row) {
        return max($row->timeFinalSubmitted, $row->timeSubmitted) <= 0;
    }
    public function content($pl, $row) {
        global $Conf;
        $t = max($row->timeFinalSubmitted, $row->timeSubmitted, 0);
        if ($t > 0)
            return $Conf->printableTimestamp($t);
        else
            return "";
    }
}

class SearchSortPaperColumn extends PaperColumn {
    public function __construct() {
        parent::__construct("searchsort", Column::VIEW_NONE,
                            array("sorter" => "search_sort_sorter"));
    }
    public function sort_prepare($pl, &$rows, $sorter) {
        $sortInfo = array();
        foreach ($pl->search->numbered_papers() as $k => $v)
            if (!isset($sortInfo[$v]))
                $sortInfo[$v] = $k;
        foreach ($rows as $row)
            $row->_search_sort_info = $sortInfo[$row->paperId];
    }
    public function search_sort_sorter($a, $b) {
        return $a->_search_sort_info - $b->_search_sort_info;
    }
}

class TagOrderSortPaperColumn extends PaperColumn {
    public function __construct() {
        parent::__construct("tagordersort", Column::VIEW_NONE,
                            array("sorter" => "tag_order_sorter"));
    }
    public function prepare($pl, &$queryOptions, $visible) {
        if (!($pl->contact->isPC && count($pl->search->orderTags)))
            return false;
        $queryOptions["tagIndex"] = array();
        foreach ($pl->search->orderTags as $x)
            $queryOptions["tagIndex"][] = $x->tag;
        return true;
    }
    public function sort_prepare($pl, &$rows, $sorter) {
        global $Conf;
        $careful = !$pl->contact->privChair
            && $Conf->setting("tag_seeall") <= 0;
        $ot = $pl->search->orderTags;
        for ($i = 0; $i < count($ot); ++$i) {
            $n = "tagIndex" . ($i ? $i : "");
            $rev = $ot[$i]->reverse;
            foreach ($rows as $row) {
                if ($row->$n === null
                    || ($careful && !$pl->contact->canViewTags($row, true)))
                    $row->$n = 2147483647;
                if ($rev)
                    $row->$n = -$row->$n;
            }
        }
    }
    public function tag_order_sorter($a, $b) {
        $i = $x = 0;
        for ($i = $x = 0; $x == 0; ++$i) {
            $n = "tagIndex" . ($i ? $i : "");
            if (!isset($a->$n))
                break;
            $x = ($a->$n < $b->$n ? -1 : ($a->$n == $b->$n ? 0 : 1));
        }
        return $x;
    }
}

class LeadPaperColumn extends PaperColumn {
    public function __construct() {
        parent::__construct("lead", Column::VIEW_ROW | Column::FOLDABLE);
    }
    public function prepare($pl, &$queryOptions, $visible) {
        return $pl->contact->canViewReviewerIdentity(true, null, true);
    }
    public function header($pl, $row, $ordinal) {
        return "Discussion lead";
    }
    public function content_empty($pl, $row) {
        return !$row->leadContactId
            || !$pl->contact->can_view_lead($row, true);
    }
    public function content($pl, $row) {
        $visible = $pl->contact->can_view_lead($row, null);
        return $pl->_contentPC($row, $row->leadContactId, $visible);
    }
}

class ShepherdPaperColumn extends PaperColumn {
    public function __construct() {
        parent::__construct("shepherd", Column::VIEW_ROW | Column::FOLDABLE);
    }
    public function prepare($pl, &$queryOptions, $visible) {
        global $Conf;
        return $pl->contact->isPC
            || ($Conf->setting("paperacc") && $Conf->timeAuthorViewDecision());
    }
    public function header($pl, $row, $ordinal) {
        return "Shepherd";
    }
    public function content_empty($pl, $row) {
        return !$row->shepherdContactId
            || !$pl->contact->can_view_shepherd($row, true);
        // XXX external reviewer can view shepherd even if external reviewer
        // cannot view reviewer identities? WHO GIVES A SHIT
    }
    public function content($pl, $row) {
        $visible = $pl->contact->can_view_shepherd($row, null);
        return $pl->_contentPC($row, $row->shepherdContactId, $visible);
    }
}

class FoldAllPaperColumn extends PaperColumn {
    public function __construct() {
        parent::__construct("foldall", Column::VIEW_NONE);
    }
    public function prepare($pl, &$queryOptions, $visible) {
        $queryOptions["foldall"] = true;
        return true;
    }
}

function initialize_paper_columns() {
    global $reviewScoreNames, $Conf;

    PaperColumn::register(new SelectorPaperColumn("sel", array("minimal" => true)));
    PaperColumn::register(new SelectorPaperColumn("selon", array("minimal" => true, "cssname" => "sel")));
    PaperColumn::register(new SelectorPaperColumn("selconf", array("cssname" => "confselector")));
    PaperColumn::register(new SelectorPaperColumn("selunlessconf", array("minimal" => true, "cssname" => "sel")));
    PaperColumn::register(new IdPaperColumn);
    PaperColumn::register(new TitlePaperColumn);
    PaperColumn::register(new StatusPaperColumn("status", false));
    PaperColumn::register(new StatusPaperColumn("statusfull", true));
    PaperColumn::register(new ReviewerTypePaperColumn("revtype"));
    PaperColumn::register(new ReviewStatusPaperColumn);
    PaperColumn::register(new ReviewSubmittedPaperColumn);
    PaperColumn::register(new ReviewDelegationPaperColumn);
    PaperColumn::register(new AssignReviewPaperColumn);
    PaperColumn::register(new TopicScorePaperColumn);
    PaperColumn::register(new TopicListPaperColumn);
    PaperColumn::register(new PreferencePaperColumn("revpref", false));
    PaperColumn::register_synonym("pref", "revpref");
    PaperColumn::register(new PreferencePaperColumn("editrevpref", true));
    PaperColumn::register_synonym("editpref", "editrevpref");
    PaperColumn::register(new PreferenceListPaperColumn("allrevpref", false));
    PaperColumn::register_synonym("allpref", "allrevpref");
    PaperColumn::register(new PreferenceListPaperColumn("allrevtopicpref", true));
    PaperColumn::register_synonym("alltopicpref", "allrevtopicpref");
    PaperColumn::register(new DesirabilityPaperColumn);
    PaperColumn::register(new ReviewerListPaperColumn);
    PaperColumn::register(new AuthorsPaperColumn);
    PaperColumn::register(new CollabPaperColumn);
    PaperColumn::register(new TagListPaperColumn);
    PaperColumn::register(new AbstractPaperColumn);
    PaperColumn::register(new LeadPaperColumn);
    PaperColumn::register(new ShepherdPaperColumn);
    PaperColumn::register(new PCConflictListPaperColumn);
    PaperColumn::register(new ConflictMatchPaperColumn("authorsmatch", "authorInformation"));
    PaperColumn::register(new ConflictMatchPaperColumn("collabmatch", "collaborators"));
    PaperColumn::register(new SearchSortPaperColumn);
    PaperColumn::register(new TagOrderSortPaperColumn);
    PaperColumn::register(new TimestampPaperColumn);
    PaperColumn::register(new FoldAllPaperColumn);
    PaperColumn::register_factory("tag:", new TagPaperColumn(null, null, false));
    PaperColumn::register_factory("tagval:", new TagPaperColumn(null, null, true));
    PaperColumn::register_factory("edittag:", new EditTagPaperColumn(null, null, false));
    PaperColumn::register_factory("edittagval:", new EditTagPaperColumn(null, null, true));
    PaperColumn::register_factory("#", new TagPaperColumn(null, null, false));
    PaperColumn::register_factory("edit#", new EditTagPaperColumn(null, null, true));

    $rf = reviewForm();
    $score = null;
    foreach ($reviewScoreNames as $n) {
        $score = new ScorePaperColumn($n);
        $f = $rf->field($n);
        ScorePaperColumn::register_score($score, $f->display_order);
    }
    if ($score)
        PaperColumn::register_factory("", $score);

    if ($Conf && $Conf->setting("formulas")) {
        $result = $Conf->q("select * from Formula order by lower(name)");
        $formula = null;
        while (($row = edb_orow($result))) {
            $fid = $row->formulaId;
            $formula = new FormulaPaperColumn("formula$fid", $row);
            FormulaPaperColumn::register($formula);
        }
        if (!$formula)
            $formula = new FormulaPaperColumn("", null);
        PaperColumn::register_factory("", $formula);
    }

    $tagger = new Tagger;
    if ($Conf && ($tagger->has_vote() || $tagger->has_rank())) {
        $vt = array();
        foreach ($tagger->defined_tags() as $v)
            if ($v->vote || $v->rank)
                $vt[] = $v->tag;
        foreach ($vt as $n)
            TagReportPaperColumn::register(new TagReportPaperColumn($n));
    }
}

initialize_paper_columns();
