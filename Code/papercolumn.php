<?php
// papercolumn.inc -- HotCRP helper classes for paper list content
// HotCRP is Copyright (c) 2006-2013 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

require_once("paperlist.inc");

class PaperColumn extends Column {
    static private $by_name = array();
    static private $factories = array();

    public function __construct($name, $view, $extra) {
        if ($extra === true)
            $extra = array("sorter" => "id_sorter");
        else if (is_int($extra))
            $extra = array("foldnum" => $extra);
        parent::__construct($name, $view, $extra);
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

    public function prepare($pl, &$queryOptions, $visible) {
        return true;
    }

    public function analyze($pl, &$rows) {
    }

    public function sort_prepare($pl, &$rows) {
    }
    public function id_sorter($a, $b) {
        return $a->paperId - $b->paperId;
    }

    public function header($pl, $row = null, $ordinal = 0) {
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
}

class IdPaperColumn extends PaperColumn {
    public function __construct() {
        parent::__construct("id", Column::VIEW_COLUMN,
                            array("minimal" => true, "sorter" => "id_sorter"));
    }
    public function header($pl, $row = null, $ordinal = 0) {
        return "ID";
    }
    public function col() {
        return "<col width='0*' />";
    }
    public function content($pl, $row) {
        $href = $pl->_paperLink($row);
        return "<a href='$href' tabindex='4'>#$row->paperId</a>";
    }
}

class SelectorPaperColumn extends PaperColumn {
    public $is_selector = true;
    public function __construct($name, $extra = 0) {
        parent::__construct($name, Column::VIEW_COLUMN, $extra);
    }
    public function prepare($pl, &$queryOptions, $visible) {
	global $Conf;
        if ($this->name == "selconf" && !$pl->contact->privChair)
            return false;
        if ($this->name == "selconf")
	    $Conf->footerScript("add_conflict_ajax()");
        return true;
    }
    public function header($pl, $row = null, $ordinal = 0) {
        if ($this->name == "selconf")
            return "Conflict?";
        else
	    return ($ordinal ? "&nbsp;" : "");
    }
    public function col() {
        return "<col width='0*' />";
    }
    public function content($pl, $row) {
        $pl->any->sel = true;
        $c = "";
        if (($this->name == "selon"
             || ($this->name == "selconf" && $row->conflictType > 0))
            && (!$pl->papersel || defval($pl->papersel, $row->paperId, 1))) {
            $c .= " checked='checked'";
            $pl->foldRow = false;
        }
        if ($this->name == "selconf" && $row->conflictType >= CONFLICT_AUTHOR)
            $c .= " disabled='disabled'";
        if ($this->name != "selconf")
            $c .= " onclick='pselClick(event,this)'";
        $t = "<span class=\"pl_rownum fx6\">" . $pl->count . ". </span>" . "<input type='checkbox' class='cb' name='pap[]' value='$row->paperId' tabindex='3' id='psel$pl->count' $c/>";
        return $t;
    }
}

class TitlePaperColumn extends PaperColumn {
    public function __construct($name) {
        parent::__construct($name, Column::VIEW_COLUMN,
                            array("minimal" => true, "sorter" => "title_sorter"));
    }
    public function title_sorter($a, $b) {
        return strcasecmp($a->title, $b->title);
    }
    public function header($pl, $row = null, $ordinal = 0) {
        return "Title";
    }
    public function content($pl, $row) {
        $href = $pl->_paperLink($row);
        $x = Text::highlight($row->title, defval($pl->search->matchPreg, "title"));
        return "<a href='$href' tabindex='5'>" . $x . "</a>" . $pl->_contentDownload($row);
    }
}

class StatusPaperColumn extends PaperColumn {
    private $is_long;
    public function __construct($name, $is_long, $extra = 0) {
        parent::__construct($name, Column::VIEW_COLUMN,
                            array("cssname" => "status", "sorter" => "status_sorter"));
        $this->is_long = $is_long;
    }
    public function sort_prepare($pl, &$rows) {
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
    public function header($pl, $row = null, $ordinal = 0) {
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
        $long = 0;
        if ($pl->search->limitName != "a" && $pl->contact->allowAdminister($row))
            $long = 2;
        if (!$this->is_long)
            $long = ($long == 2 ? -2 : -1);
        return $pl->contact->paperStatus($row->paperId, $row, $long);
    }
}

class ReviewStatusPaperColumn extends PaperColumn {
    private $auview;
    public function __construct($name) {
        global $Conf;
        parent::__construct($name, Column::VIEW_COLUMN,
                            array("sorter" => "review_status_sorter"));
        $this->auview = $Conf->timeAuthorViewReviews();
    }
    public function prepare($pl, &$queryOptions, $visible) {
        return $pl->contact->amReviewer() || $this->auview
            || $pl->contact->privChair;
    }
    public function sort_prepare($pl, &$rows) {
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
    public function header($pl, $row = null, $ordinal = 0) {
        return "<span class='hastitle' title='\"1/2\" means 1 complete review out of 2 assigned reviews'>#&nbsp;Reviews</span>";
    }
    public function col() {
        return "<col width='0*' />";
    }
    public function content_empty($pl, $row) {
        return !($pl->contact->allowAdminister($row)
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
}

class AuthorsPaperColumn extends PaperColumn {
    public function __construct($name, $extra) {
        parent::__construct($name, Column::VIEW_ROW, $extra);
    }
    public function header($pl, $row = null, $ordinal = 0) {
        return "Authors";
    }
    public function content($pl, $row) {
	if (!$pl->contact->canViewAuthors($row, true))
	    return "";
	cleanAuthor($row);
	$aus = array();
        $highlight = defval($pl->search->matchPreg, "authorInformation", "");
	if ($pl->aufull) {
	    $lastaff = "";
	    $anyaff = false;
	    $affaus = array();
	    foreach ($row->authorTable as $au) {
		if ($au[3] != $lastaff && count($aus)) {
		    $affaus[] = array($aus, $lastaff);
		    $aus = array();
		    $anyaff = $anyaff || ($au[3] != "");
		}
		$lastaff = $au[3];
		$n = $au[0] || $au[1] ? trim("$au[0] $au[1]") : $au[2];
		$aus[] = Text::highlight($n, $highlight);
	    }
	    if (count($aus))
		$affaus[] = array($aus, $lastaff);
	    foreach ($affaus as &$ax) {
		$aff = ($ax[1] == "" && $anyaff ? "unaffiliated" : $ax[1]);
                $aff = Text::highlight($aff, $highlight);
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
}

class CollabPaperColumn extends PaperColumn {
    public function __construct($name, $extra) {
        parent::__construct($name, Column::VIEW_ROW, $extra);
    }
    public function prepare($pl, &$queryOptions, $visible) {
        global $Conf;
        return !!$Conf->setting("sub_collab");
    }
    public function header($pl, $row = null, $ordinal = 0) {
        return "Collaborators";
    }
    public function content_empty($pl, $row) {
        return ($row->collaborators == ""
                || strcasecmp($row->collaborators, "None") == 0
                || !$pl->contact->canViewAuthors($row, true));
    }
    public function content($pl, $row) {
        $x = "";
        foreach (explode("\n", $row->collaborators) as $c)
            $x .= ($x === "" ? "" : ", ") . trim($c);
        return Text::highlight($x, defval($pl->search->matchPreg, "collaborators"));
    }
}

class AbstractPaperColumn extends PaperColumn {
    public function __construct($name, $extra) {
        parent::__construct($name, Column::VIEW_ROW, $extra);
    }
    public function header($pl, $row = null, $ordinal = 0) {
        return "Abstract";
    }
    public function content_empty($pl, $row) {
        return $row->abstract == "";
    }
    public function content($pl, $row) {
        return Text::highlight($row->abstract, defval($pl->search->matchPreg, "abstract"));
    }
}

class TopicListPaperColumn extends PaperColumn {
    public function __construct($name, $extra) {
        parent::__construct($name, Column::VIEW_ROW, $extra);
    }
    public function prepare($pl, &$queryOptions, $visible) {
        if (!count($pl->rf->topicName))
            return false;
        if ($visible)
            $queryOptions["topics"] = 1;
	return true;
    }
    public function header($pl, $row = null, $ordinal = 0) {
        return "Topics";
    }
    public function content_empty($pl, $row) {
        return !isset($row->topicIds) || $row->topicIds == "";
    }
    public function content($pl, $row) {
        return join(", ", $pl->rf->webTopicArray($row->topicIds, defval($row, "topicInterest")));
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
        if ($pl->search->reviewerContact
            && $pl->search->reviewerContact != $pl->contact->cid
            && count($rows)) {
            $by_pid = array();
            foreach ($rows as $row)
                $by_pid[$row->paperId] = $row;
            $result = $Conf->qe("select paperId, reviewType, reviewId, reviewModified, reviewSubmitted, reviewNeedsSubmit, reviewOrdinal, contactId reviewContactId, requestedBy, reviewToken, reviewRound, 0 conflictType from PaperReview where paperId in (" . join(",", array_keys($by_pid)) . ") and contactId=" . $pl->search->reviewerContact, "while examining reviews");
            while (($xrow = edb_orow($result)))
                if ($pl->contact->allowAdminister($xrow)
                    || $pl->contact->canViewReviewerIdentity($by_pid[$xrow->paperId], $xrow, true))
                    $by_pid[$xrow->paperId]->_xreviewer = $xrow;
            $this->xreviewer = new Contact;
            $this->xreviewer->lookupById($pl->search->reviewerContact);
        } else
            $this->xreviewer = false;
    }
    public function sort_prepare($pl, &$rows) {
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
    public function header($pl, $row = null, $ordinal = 0) {
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
            $xrow = (object) array("reviewType" => 0);
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
            $t = $Conf->cacheableImage("_.gif", "Conflict", "Conflict", "ass-1");
        else
            $t = $Conf->cacheableImage("_.gif", "", "", "ass0");
        return $t;
    }
}

class ReviewSubmittedPaperColumn extends PaperColumn {
    public function __construct($name) {
        parent::__construct($name, Column::VIEW_COLUMN, array("cssname" => "text"));
    }
    public function prepare($pl, &$queryOptions, $visible) {
        return !!$pl->contact->isPC;
    }
    public function header($pl, $row = null, $ordinal = 0) {
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
    public function __construct($name) {
        parent::__construct($name, Column::VIEW_COLUMN,
                            array("cssname" => "text",
                                  "sorter" => "review_delegation_sorter"));
    }
    public function prepare($pl, &$queryOptions, $visible) {
	if (!$pl->contact->isPC)
            return false;
        $queryOptions["allReviewScores"] = $queryOptions["reviewerName"] = 1;
	return true;
    }
    public function review_delegation_sorter($a, $b) {
	$x = strcasecmp($a->reviewLastName, $b->reviewLastName);
	$x = $x ? $x : strcasecmp($a->reviewFirstName, $b->reviewFirstName);
	return $x ? $x : strcasecmp($a->reviewEmail, $b->reviewEmail);
    }
    public function header($pl, $row = null, $ordinal = 0) {
        return "Reviewer";
    }
    public function content($pl, $row) {
        global $Conf;
        $t = Text::user_html($row->reviewFirstName, $row->reviewLastName, $row->reviewEmail) . "<br /><small>Last login: ";
        return $t . ($row->reviewLastLogin ? $Conf->printableTimeShort($row->reviewLastLogin) : "Never") . "</small>";
    }
}

class AssignReviewPaperColumn extends ReviewerTypePaperColumn {
    public function __construct($name) {
        parent::__construct($name);
    }
    public function prepare($pl, &$queryOptions, $visible) {
        global $Conf;
        if (!$pl->contact->privChair)
            return false;
        if ($visible > 0)
            $Conf->footerScript("add_assrev_ajax()");
        $queryOptions["reviewer"] = $pl->reviewer ? $pl->reviewer : $pl->contact->cid;
	return true;
    }
    public function analyze($pl, &$rows) {
        $this->xreviewer = false;
    }
    public function header($pl, $row = null, $ordinal = 0) {
        return "Assignment";
    }
    public function content_empty($pl, $row) {
        return !$pl->contact->allowAdminister($row);
    }
    public function content($pl, $row) {
        if ($row->reviewerConflictType >= CONFLICT_AUTHOR)
            return "<span class='author'>Author</span>";
        $rt = ($row->reviewerConflictType > 0 ? -1 : min(max($row->reviewerReviewType, 0), REVIEW_PRIMARY));
        return tagg_select("assrev$row->paperId",
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
    public function __construct($name) {
        parent::__construct($name, Column::VIEW_COLUMN,
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
    public function header($pl, $row = null, $ordinal = 0) {
        return "Desirability";
    }
    public function col() {
        return "<col width='0*' />";
    }
    public function content($pl, $row) {
        if (isset($row->desirability))
            return htmlspecialchars($row->desirability);
        else
            return "0";
    }
}

class TopicScorePaperColumn extends PaperColumn {
    public function __construct($name) {
        parent::__construct($name, Column::VIEW_COLUMN,
                            array("sorter" => "topic_score_sorter"));
    }
    public function prepare($pl, &$queryOptions, $visible) {
        if (!count($pl->rf->topicName) || !$pl->contact->isPC)
            return false;
        $queryOptions["reviewer"] = $pl->reviewer ? $pl->reviewer : $pl->contact->cid;
        $queryOptions["topicInterestScore"] = 1;
	return true;
    }
    public function topic_score_sorter($a, $b) {
	return $b->topicInterestScore - $a->topicInterestScore;
    }
    public function header($pl, $row = null, $ordinal = 0) {
        return "Topic<br/>score";
    }
    public function col() {
        return "<col width='0*' />";
    }
    public function content($pl, $row) {
        return htmlspecialchars($row->topicInterestScore + 0);
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
        $queryOptions["reviewer"] = $pl->reviewer ? $pl->reviewer : $pl->contact->cid;
        if ($this->editable && $visible > 0) {
            if ($pl->contact->privChair && $pl->reviewer)
                $arg = "&amp;reviewer=" . $pl->reviewer;
            $Conf->footerHtml(
                 tagg_form(hoturl_post("paper", "setrevpref=1$arg"),
                           array("id" => "prefform")) . "<div>"
                 . tagg_hidden("p") . tagg_hidden("revpref", "")
                 . "</div></form>", "prefform");
            $Conf->footerScript("add_revpref_ajax()");
        }
	return true;
    }
    public function preference_sorter($a, $b) {
	$x = $b->reviewerPreference - $a->reviewerPreference;
	return $x ? $x : $b->topicInterestScore - $a->topicInterestScore;
    }
    public function header($pl, $row = null, $ordinal = 0) {
        return "Preference";
    }
    public function col() {
        return "<col width='0*' />";
    }
    public function content($pl, $row) {
        $pref = (isset($row->reviewerPreference) ? htmlspecialchars($row->reviewerPreference) : "0");
        if (!$this->editable)
            return $pref;
        else if ($row->reviewerConflictType > 0)
            return "N/A";
        else
            return "<input class='textlite' type='text' size='4' name='revpref$row->paperId' id='revpref$row->paperId' value=\"$pref\" tabindex='2' />";
    }
}

class PreferenceListPaperColumn extends PaperColumn {
    public function __construct($name) {
        parent::__construct($name, Column::VIEW_ROW, 0);
    }
    public function prepare($pl, &$queryOptions, $visible) {
        if (!$pl->contact->privChair)
            return false;
        $queryOptions["allReviewerPreference"] = $queryOptions["topics"]
            = $queryOptions["allConflictType"] = 1;
	return true;
    }
    public function header($pl, $row = null, $ordinal = 0) {
        return "Preferences";
    }
    public function content_empty($pl, $row) {
        return !$pl->contact->allowAdminister($row);
    }
    public function content($pl, $row) {
        $prefs = PaperList::_rowPreferences($row);
        $text = "";
        foreach (pcMembers() as $pcid => $pc)
            if (($pref = defval($prefs, $pcid, null))) {
                $text .= ($text == "" ? "" : ", ")
                    . Text::name_html($pc) . preferenceSpan($pref);
            }
        return $text;
    }
}

class ReviewerListPaperColumn extends PaperColumn {
    public function __construct($name, $extra = 0) {
        parent::__construct($name, Column::VIEW_ROW, $extra);
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
    public function header($pl, $row = null, $ordinal = 0) {
        return "Reviewers";
    }
    public function content($pl, $row) {
        $prefs = PaperList::_rowPreferences($row);
        $n = "";
        // see also search.php > getaction == "reviewers"
        if (isset($pl->reviewList[$row->paperId])) {
            foreach ($pl->reviewList[$row->paperId] as $xrow)
                if ($xrow->lastName) {
                    $ranal = $pl->_reviewAnalysis($xrow);
                    $n .= ($n ? ", " : "");
                    $n .= Text::name_html($xrow);
                    if ($xrow->reviewType >= REVIEW_SECONDARY)
                        $n .= "&nbsp;" . PaperList::_reviewIcon($xrow, $ranal, false);
                    if (($pref = defval($prefs, $xrow->contactId, null)))
                        $n .= preferenceSpan($pref);
                }
            $n = $pl->maybeConflict($row, $n, $pl->contact->canViewReviewerIdentity($row, null, false));
        }
        return $n;
    }
}

class PCConflictListPaperColumn extends PaperColumn {
    public function __construct($name, $extra) {
        parent::__construct($name, Column::VIEW_ROW, $extra);
    }
    public function prepare($pl, &$queryOptions, $visible) {
        if (!$pl->contact->privChair)
            return false;
        if ($visible)
            $queryOptions["allConflictType"] = 1;
	return true;
    }
    public function header($pl, $row = null, $ordinal = 0) {
        return "PC conflicts";
    }
    public function content($pl, $row) {
        $x = "," . $row->allConflictType;
        $y = array();
        foreach (pcMembers() as $pc)
            if (strpos($x, "," . $pc->contactId . " ") !== false)
                $y[] = Text::name_html($pc);
        return join(", ", $y);
    }
}

class ConflictMatchPaperColumn extends PaperColumn {
    private $field;
    public function __construct($name, $field) {
        parent::__construct($name, Column::VIEW_ROW, 0);
        $this->field = $field;
    }
    public function prepare($pl, &$queryOptions, $visible) {
	return $pl->contact->privChair;
    }
    public function header($pl, $row = null, $ordinal = 0) {
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
	    $pl->foldRow = false;
	return $text;
    }
}

class TagListPaperColumn extends PaperColumn {
    public function __construct($name, $extra) {
        parent::__construct($name, Column::VIEW_ROW, $extra);
    }
    public function prepare($pl, &$queryOptions, $visible) {
        if (!$pl->contact->canViewTags(null))
            return false;
        if ($visible)
            $queryOptions["tags"] = 1;
        return true;
    }
    public function header($pl, $row = null, $ordinal = 0) {
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
    public function __construct($name, $tag, $is_value) {
        parent::__construct($name, Column::VIEW_COLUMN,
                            array("sorter" => "tag_sorter"));
        $this->dtag = $tag;
        $this->is_value = $is_value;
        $this->cssname = ($this->is_value ? "tagval" : "tag");
    }
    public function make_field($name) {
        $p = strpos($name, ":");
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
    public function sort_prepare($pl, &$rows) {
        global $Conf;
        $this->ctag_sorter = $sorter = "_tag_sort_info." . $this->ctag;
        $careful = !$pl->contact->privChair
            && $Conf->setting("tag_seeall") <= 0;
        foreach ($rows as $row)
            if ($careful && !$pl->contact->canViewTags($row, true))
                $row->$sorter = 2147483647;
            else if (($row->$sorter = $this->_tag_value($row)) === null)
                $row->$sorter = 2147483646 + !$this->editable;
    }
    public function tag_sorter($a, $b) {
        $sorter = $this->ctag_sorter;
        return $a->$sorter < $b->$sorter ? -1 :
            ($a->$sorter == $b->$sorter ? 0 : 1);
    }
    public function header($pl, $row = null, $ordinal = 0) {
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
}

class EditTagPaperColumn extends TagPaperColumn {
    public function __construct($name, $tag, $is_value) {
        parent::__construct($name, $tag, $is_value);
        $this->cssname = ($this->is_value ? "edittagval" : "edittag");
        $this->editable = true;
    }
    public function make_field($name) {
        $p = strpos($name, ":");
        return parent::register(new EditTagPaperColumn($name, substr($name, $p + 1), $this->is_value));
    }
    public function prepare($pl, &$queryOptions, $visible) {
        global $Conf;
        if (($p = parent::prepare($pl, $queryOptions, $visible))
            && $visible > 0) {
            $Conf->footerHtml(
                 tagg_form(hoturl_post("paper", "settags=1&amp;forceShow=1"),
                           array("id" => "edittagajaxform",
                                 "style" => "display:none")) . "<div>"
                 . tagg_hidden("p") . tagg_hidden("addtags")
                 . tagg_hidden("deltags") . "</div></form>",
                 "edittagajaxform");
            if (("edit" . $pl->sorter->type == $this->name
                 || $pl->sorter->type == $this->name)
                && !$pl->sorter->reverse
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
            return "<input type='text' class='textlite' size='4' name='tag:$this->dtag $row->paperId' value=\""
                . ($v !== null ? htmlspecialchars($v) : "") . "\" tabindex='6' />";
    }
}

class ScorePaperColumn extends PaperColumn {
    public $score;
    public $max_score;
    private $viewscore;
    private static $registered = array();
    public function __construct($score, $foldnum) {
        parent::__construct($score, Column::VIEW_COLUMN,
                            array("sorter" => "score_sorter"));
        $this->minimal = $this->sortable = true;
        $this->cssname = "score";
        $this->foldnum = $foldnum;
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
        global $reviewScoreNames;
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
        if ($visible) {
            $f = $pl->rf->field($this->score);
            $this->viewscore = $f->view_score;
            $auview = $pl->contact->viewReviewFieldsScore(null, true);
            if ($this->viewscore <= $auview)
                return false;
            if (!isset($queryOptions["scores"]))
                $queryOptions["scores"] = array();
            $queryOptions["scores"][$this->score] = true;
            $this->max_score = count($f->options);
        }
	return true;
    }
    public function sort_prepare($pl, &$rows) {
        $scoreName = $this->score . "Scores";
        foreach ($rows as $row)
            if ($pl->contact->canViewReview($row, $this->viewscore, null))
                $pl->score_analyze($row, $scoreName, $this->max_score,
                                   $pl->sorter->score);
            else
                $pl->score_reset($row);
        $this->_textual_sort =
            ($pl->sorter->score == "M" || $pl->sorter->score == "C"
             || $pl->sorter->score == "Y");
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
    public function header($pl, $row = null, $ordinal = 0) {
        return $pl->rf->field($this->score)->web_abbreviation();
    }
    public function col() {
        return "<col width='0*' />";
    }
    public function content_empty($pl, $row) {
        return !$pl->contact->canViewReview($row, $this->viewscore, true);
    }
    public function content($pl, $row) {
	global $Conf;
        $allowed = $pl->contact->canViewReview($row, $this->viewscore, false);
        $fname = $this->score . "Scores";
        if (($allowed || $pl->contact->allowAdminister($row))
	    && $row->$fname) {
            $t = $pl->rf->field($this->score)->unparse_graph($row->$fname, 1, defval($row, $this->score));
            if (!$allowed)
                $t = "<span class='fx20'>$t</span>";
            return $t;
        } else
            return "";
    }
}

class FormulaPaperColumn extends PaperColumn {
    private static $registered = array();
    public function __construct($name, $formula, $foldnum) {
        parent::__construct($name, Column::VIEW_COLUMN, array("minimal" => true, "sorter" => "formula_sorter", "foldnum" => $foldnum));
        $this->cssname = "formula";
        $this->formula = $formula;
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
        return null;
    }
    public function prepare($pl, &$queryOptions, $visible) {
        $revView = 0;
        if ($pl->contact->amReviewer()
            && $pl->search->limitName != "a")
            $revView = $pl->contact->viewReviewFieldsScore(null, true);
        if (!$pl->scoresOk
            || $this->formula->authorView <= $revView)
            return false;
        require_once("paperexpr.inc");
        if (!($expr = PaperExpr::parse($this->formula->expression, true)))
            return false;
        $this->formula_function = PaperExpr::compile_function($expr, $pl->contact);
        PaperExpr::add_query_options($queryOptions, $expr, $pl->contact);
	return true;
    }
    public function sort_prepare($pl, &$rows) {
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
    public function header($pl, $row = null, $ordinal = 0) {
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
        if ($row->conflictType > 0 && $pl->contact->allowAdminister($row))
            return "<span class='fn20'>$t</span><span class='fx20'>"
                . $formulaf($row, $pl->contact, "h", true) . "</span>";
        else
            return $t;
    }
}

class TagReportPaperColumn extends PaperColumn {
    private static $registered = array();
    public function __construct($tag, $foldnum) {
        parent::__construct("tagrep_" . preg_replace('/\W+/', '_', $tag), Column::VIEW_ROW, $foldnum);
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
    public function header($pl, $row = null, $ordinal = 0) {
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
    public function header($pl, $row = null, $ordinal = 0) {
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
    public function sort_prepare($pl, &$rows) {
        $sortInfo = array();
        foreach ($pl->search->simplePaperList() as $k => $v)
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
    public function sort_prepare($pl, &$rows) {
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
    public function __construct($name, $extra) {
        parent::__construct($name, Column::VIEW_ROW, $extra);
    }
    public function prepare($pl, &$queryOptions, $visible) {
        return $pl->contact->canViewReviewerIdentity(true, null, true);
    }
    public function header($pl, $row = null, $ordinal = 0) {
        return "Discussion lead";
    }
    public function content_empty($pl, $row) {
        return !$row->leadContactId
            || !$pl->contact->canViewDiscussionLead($row, true);
    }
    public function content($pl, $row) {
        $visible = $pl->contact->canViewDiscussionLead($row, null);
        return $pl->_contentPC($row, $row->leadContactId, $visible);
    }
}

class ShepherdPaperColumn extends PaperColumn {
    public function __construct($name, $extra) {
        parent::__construct($name, Column::VIEW_ROW, $extra);
    }
    public function prepare($pl, &$queryOptions, $visible) {
        global $Conf;
        return $pl->contact->isPC
            || ($Conf->setting("paperacc") && $Conf->timeAuthorViewDecision());
    }
    public function header($pl, $row = null, $ordinal = 0) {
        return "Shepherd";
    }
    public function content_empty($pl, $row) {
        return !$row->shepherdContactId
            || !$pl->contact->canViewDecision($row, true);
        // XXX external reviewer can view shepherd even if external reviewer
        // cannot view reviewer identities? WHO GIVES A SHIT
    }
    public function content($pl, $row) {
        $visible = $pl->contact->actPC($row) || $pl->contact->canViewDecision($row);
        return $pl->_contentPC($row, $row->shepherdContactId, $visible);
    }
}

function initialize_paper_columns() {
    global $paperListFormulas, $reviewScoreNames, $Conf;

    PaperColumn::register(new SelectorPaperColumn("sel", array("minimal" => true)));
    PaperColumn::register(new SelectorPaperColumn("selon", array("minimal" => true, "cssname" => "sel")));
    PaperColumn::register(new SelectorPaperColumn("selconf", array("cssname" => "confselector")));
    PaperColumn::register(new IdPaperColumn);
    PaperColumn::register(new TitlePaperColumn("title"));
    PaperColumn::register(new StatusPaperColumn("status", false));
    PaperColumn::register(new StatusPaperColumn("statusfull", true));
    PaperColumn::register(new ReviewerTypePaperColumn("revtype"));
    PaperColumn::register(new ReviewStatusPaperColumn("revstat"));
    PaperColumn::register(new ReviewSubmittedPaperColumn("revsubmitted"));
    PaperColumn::register(new ReviewDelegationPaperColumn("revdelegation"));
    PaperColumn::register(new AssignReviewPaperColumn("assrev"));
    PaperColumn::register(new TopicScorePaperColumn("topicscore"));
    PaperColumn::register(new TopicListPaperColumn("topics", 13));
    PaperColumn::register(new PreferencePaperColumn("revpref", false));
    PaperColumn::register(new PreferencePaperColumn("editrevpref", true));
    PaperColumn::register(new PreferenceListPaperColumn("allrevpref"));
    PaperColumn::register(new DesirabilityPaperColumn("desirability"));
    PaperColumn::register(new ReviewerListPaperColumn("reviewers", 10));
    PaperColumn::register(new AuthorsPaperColumn("authors", 3));
    PaperColumn::register(new CollabPaperColumn("collab", 15));
    PaperColumn::register(new TagListPaperColumn("tags", 4));
    PaperColumn::register(new AbstractPaperColumn("abstract", 5));
    PaperColumn::register(new LeadPaperColumn("lead", 12));
    PaperColumn::register(new ShepherdPaperColumn("shepherd", 11));
    PaperColumn::register(new PCConflictListPaperColumn("pcconf", 14));
    PaperColumn::register(new ConflictMatchPaperColumn("authorsmatch", "authorInformation"));
    PaperColumn::register(new ConflictMatchPaperColumn("collabmatch", "collaborators"));
    PaperColumn::register(new SearchSortPaperColumn);
    PaperColumn::register(new TagOrderSortPaperColumn);
    PaperColumn::register(new TimestampPaperColumn);
    PaperColumn::register_factory("tag:", new TagPaperColumn(null, null, false));
    PaperColumn::register_factory("tagval:", new TagPaperColumn(null, null, true));
    PaperColumn::register_factory("edittag:", new EditTagPaperColumn(null, null, false));
    PaperColumn::register_factory("edittagval:", new EditTagPaperColumn(null, null, true));

    $nextfield = 50; /* BaseList::FIELD_SCORE */
    $rf = reviewForm();
    foreach ($reviewScoreNames as $n) {
        $score = new ScorePaperColumn($n, $nextfield);
        $f = $rf->field($n);
        ScorePaperColumn::register_score($score, $f->display_order);
        ++$nextfield;
    }
    PaperColumn::register_factory("", $score);

    $nextfold = 21;
    $paperListFormulas = array();
    if ($Conf && $Conf->setting("formulas") && $Conf->sversion >= 32) {
        $result = $Conf->q("select * from Formula order by lower(name)");
        $formula = null;
        while (($row = edb_orow($result))) {
            $fid = $row->formulaId;
            $formula = new FormulaPaperColumn("formula$fid", $row, $nextfold);
            FormulaPaperColumn::register($formula);
            $paperListFormulas[$fid] = $row;
            ++$nextfold;
        }
        if ($formula)
            PaperColumn::register_factory("", $formula);
    }

    $tagger = new Tagger;
    if ($Conf && ($tagger->has_vote() || $tagger->has_rank())) {
        $vt = array();
        foreach ($tagger->defined_tags() as $v)
            if ($v->vote || $v->rank)
                $vt[] = $v->tag;
        foreach ($vt as $n) {
            TagReportPaperColumn::register(new TagReportPaperColumn($n, $nextfold));
            ++$nextfold;
        }
    }
}

initialize_paper_columns();
