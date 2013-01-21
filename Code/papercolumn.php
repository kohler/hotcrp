<?php
// papercolumn.inc -- HotCRP helper classes for paper list content
// HotCRP is Copyright (c) 2006-2013 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

require_once("paperlist.inc");

class PaperColumn extends Column {
    public $id;
    public $group_id;

    public function __construct($name, $id, $view, $extra) {
        if ($extra === true)
            $extra = array("sortable" => true);
        else if (is_int($extra))
            $extra = array("foldnum" => $extra);
        parent::__construct($name, $view, $extra);
        $this->id = $this->group_id = $id;

        PaperListField::$by_id[$id] = PaperListField::$by_name[$name] = $this;
	if ($this->foldnum > 3  /* "authors" == 1 or 2, requires special handling */) {
            global $paperListFolds;
	    $paperListFolds[$this->foldname] = $this;
        }
    }

    public function prepare($pl, &$queryOptions, $folded) {
        return true;
    }

    public function sort($pl, &$rows) {
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

class GenericPaperColumn extends PaperColumn {
    public function __construct($name, $id, $view, $extra = 0) {
        parent::__construct($name, $id, $view, $extra);
    }

    public function prepare($pl, &$queryOptions, $folded) {
	global $Conf;
	switch ($this->group_id) {
	case PaperList::FIELD_TOPIC_INTEREST:
	    if (!count($pl->rf->topicName))
		return false;
	    $queryOptions["topicInterestScore"] = 1;
	    break;
	case PaperList::FIELD_OPT_TOPIC_NAMES:
	    if (!count($pl->rf->topicName))
		return false;
	    if (!$folded)
		$queryOptions["topics"] = 1;
	    break;
	case PaperList::FIELD_OPT_ALL_REVIEWER_NAMES:
	    if (!$pl->contact->canViewReviewerIdentity(true, null))
		return false;
	    if (!$folded) {
		$queryOptions["reviewList"] = 1;
		if ($pl->contact->privChair)
		    $queryOptions["allReviewerPreference"] = $queryOptions["topics"] = 1;
	    }
	    break;
	case PaperList::FIELD_REVIEWER_PREFERENCE:
	case PaperList::FIELD_EDIT_REVIEWER_PREFERENCE:
	    $queryOptions['reviewerPreference'] = 1;
	    $Conf->footerScript("addRevprefAjax()");
	    break;
	case PaperList::FIELD_ASSIGN_REVIEW:
	    $Conf->footerScript("addAssrevAjax()");
	    break;
	case PaperList::FIELD_SELECTOR_CONFLICT:
	    $Conf->footerScript("addConflictAjax()");
	    break;
	case PaperList::FIELD_DESIRABILITY:
	    $queryOptions['desirability'] = 1;
	    break;
	case PaperList::FIELD_ALL_PREFERENCES:
	    $queryOptions['allReviewerPreference'] = $queryOptions['topics']
		= $queryOptions['allConflictType'] = 1;
	    break;
	case PaperList::FIELD_REVIEWER_MONITOR:
	    $queryOptions["allReviewScores"] = 1;
	    $queryOptions["reviewerName"] = 1;
	    break;
	case PaperList::FIELD_REVIEWER_TYPE:
	case PaperList::FIELD_REVIEWER_TYPE_ICON:
	    // if search names a specific reviewer, link there
	    if ($pl->search->reviewerContact
		&& !isset($queryOptions['allReviewScores'])) {
		$queryOptions['reviewerContact'] = $pl->search->reviewerContact;
		if ($pl->search->reviewerContact != $pl->contact->contactId)
		    $pl->showConflict = false;
	    }
	    break;
	case PaperList::FIELD_TAGS:
	    if (!$pl->contact->isPC)
		return false;
	    if (!$folded)
		$queryOptions["tags"] = 1;
	    break;
	case PaperList::FIELD_LEAD:
	case PaperList::FIELD_SHEPHERD:
	    if (!$pl->contact->isPC)
		return false;
	    break;
	case PaperList::FIELD_COLLABORATORS:
	    if (!$Conf->setting("sub_collab"))
		return false;
	    break;
	case PaperList::FIELD_OPT_PC_CONFLICTS:
	    if (!$pl->contact->privChair)
		return false;
	    if (!$folded)
		$queryOptions["allConflictType"] = 1;
	    break;
	}
	return true;
    }

    private static function _sortTitle($a, $b) {
	$x = strcasecmp($a->title, $b->title);
	return $x ? $x : $a->paperId - $b->paperId;
    }

    private static function _sortStatus($a, $b) {
	$x = $b->_sort_info - $a->_sort_info;
	$x = $x ? $x : ($a->timeWithdrawn > 0) - ($b->timeWithdrawn > 0);
	$x = $x ? $x : ($b->timeSubmitted > 0) - ($a->timeSubmitted > 0);
	$x = $x ? $x : ($b->paperStorageId > 1) - ($a->paperStorageId > 1);
	return $x ? $x : $a->paperId - $b->paperId;
    }

    private static function _sortReviewer($a, $b) {
	$x = strcasecmp($a->reviewLastName, $b->reviewLastName);
	$x = $x ? $x : strcasecmp($a->reviewFirstName, $b->reviewFirstName);
	$x = $x ? $x : strcasecmp($a->reviewEmail, $b->reviewEmail);
	return $x ? $x : $a->paperId - $b->paperId;
    }

    private static function _sortReviewType($a, $b) {
	$x = $b->_sort_info - $a->_sort_info;
	return $x ? $x : $a->paperId - $b->paperId;
    }

    private static function _sortReviewsStatus($a, $b) {
	$av = ($a->_sort_info ? $a->reviewCount : 2147483647);
	$bv = ($b->_sort_info ? $b->reviewCount : 2147483647);
	if ($av == $bv) {
	    $av = ($a->_sort_info ? $a->startedReviewCount : 2147483647);
	    $bv = ($b->_sort_info ? $b->startedReviewCount : 2147483647);
	    if ($av == $bv) {
		$av = $a->paperId;
		$bv = $b->paperId;
	    }
	}
	return ($av < $bv ? -1 : ($av == $bv ? 0 : 1));
    }

    private static function _sortTopicInterest($a, $b) {
	$x = $b->topicInterestScore - $a->topicInterestScore;
	return $x ? $x : $a->paperId - $b->paperId;
    }

    private static function _sortReviewerPreference($a, $b) {
	$x = $b->reviewerPreference - $a->reviewerPreference;
	$x = $x ? $x : defval($b, "topicInterestScore", 0) - defval($a, "topicInterestScore", 0);
	return $x ? $x : $a->paperId - $b->paperId;
    }

    private static function _sortDesirability($a, $b) {
	$x = $b->desirability - $a->desirability;
	return $x ? $x : $a->paperId - $b->paperId;
    }

    public function sort($pl, &$rows) {
        global $Conf;
	switch ($this->group_id) {
	case PaperList::FIELD_ID:
	    ksort($rows);
	    break;
	case PaperList::FIELD_TITLE:
	    usort($rows, array($this, "_sortTitle"));
	    break;
	case PaperList::FIELD_STATUS:
	case PaperList::FIELD_STATUS_SHORT:
            foreach ($rows as $row)
                $row->_sort_info = ($pl->contact->canViewDecision($row) ? $row->outcome : -10000);
	    usort($rows, array($this, "_sortStatus"));
	    break;
	case PaperList::FIELD_REVIEWER_MONITOR:
	    usort($rows, array($this, "_sortReviewer"));
	    break;
	case PaperList::FIELD_REVIEWER_TYPE:
	case PaperList::FIELD_REVIEWER_TYPE_ICON:
	case PaperList::FIELD_ASSIGN_REVIEW:
            foreach ($rows as $row) {
                $row->_sort_info = $row->reviewType;
                if ($pl->contact->privChair && !$row->reviewType
                    && $row->conflictType)
                    $row->_sort_info = -$row->conflictType;
            }
	    usort($rows, array($this, "_sortReviewType"));
	    break;
	case PaperList::FIELD_REVIEWS_STATUS:
            $auview = $Conf->timeAuthorViewReviews();
            foreach ($rows as $row)
                $row->_sort_info = ($row->conflictType == 0
                        || ($row->conflictType >= CONFLICT_AUTHOR && $auview)
                        || $pl->contact->privChair);
	    usort($rows, array($this, "_sortReviewsStatus"));
	    break;
	case PaperList::FIELD_TOPIC_INTEREST:
	    usort($rows, array($this, "_sortTopicInterest"));
	    break;
	case PaperList::FIELD_REVIEWER_PREFERENCE:
	case PaperList::FIELD_EDIT_REVIEWER_PREFERENCE:
	    usort($rows, array($this, "_sortReviewerPreference"));
	    break;
	case PaperList::FIELD_DESIRABILITY:
	    usort($rows, array($this, "_sortDesirability"));
	    break;
	}
    }

    public function header($pl, $row = null, $ordinal = 0) {
	switch ($this->group_id) {
	case PaperList::FIELD_ID:
	    return "ID";
	case PaperList::FIELD_TITLE:
	    return "Title";
	case PaperList::FIELD_STATUS:
	case PaperList::FIELD_STATUS_SHORT:
	    return "Status";
	case PaperList::FIELD_REVIEWER_MONITOR:
	    return "Reviewer";
	case PaperList::FIELD_REVIEWER_TYPE:
	case PaperList::FIELD_REVIEWER_TYPE_ICON:
	    return "<span class='hastitle' title='Reviewer type'>Review</span>";
	case PaperList::FIELD_REVIEWER_STATUS:
	    return "Review status";
	case PaperList::FIELD_REVIEWS_STATUS:
	    return "<span class='hastitle' title='\"1/2\" means 1 complete review out of 2 assigned reviews'>#&nbsp;Reviews</span>";
	case PaperList::FIELD_SELECTOR:
	case PaperList::FIELD_SELECTOR_ON:
	    return ($ordinal ? "&nbsp;" : "");
	case PaperList::FIELD_SELECTOR_CONFLICT:
	    return "Conflict?";
	case PaperList::FIELD_ASSIGN_REVIEW:
	    return "Assignment";
	case PaperList::FIELD_TOPIC_INTEREST:
	    return "Topic<br/>score";
	case PaperList::FIELD_OPT_TOPIC_NAMES:
	    return "Topics";
	case PaperList::FIELD_OPT_ALL_REVIEWER_NAMES:
	    return "Reviewers";
	case PaperList::FIELD_OPT_PC_CONFLICTS:
	    return "PC conflicts";
	case PaperList::FIELD_REVIEWER_PREFERENCE:
	case PaperList::FIELD_EDIT_REVIEWER_PREFERENCE:
	    return "Preference";
	case PaperList::FIELD_DESIRABILITY:
	    return "Desirability";
	case PaperList::FIELD_ALL_PREFERENCES:
	    return "Preferences";
	case PaperList::FIELD_TAGS:
	    return "Tags";
	case PaperList::FIELD_OPT_ABSTRACT:
	    return "Abstract";
	case PaperList::FIELD_LEAD:
	    return "Discussion lead";
	case PaperList::FIELD_SHEPHERD:
	    return "Shepherd";
	case PaperList::FIELD_COLLABORATORS:
	    return "Collaborators";
	default:
	    return "&lt;" . htmlspecialchars($this->name) . "&gt;?";
	}
    }

    public function col() {
	switch ($this->group_id) {
	case PaperList::FIELD_ID:
	case PaperList::FIELD_REVIEWS_STATUS:
	case PaperList::FIELD_SELECTOR:
	case PaperList::FIELD_SELECTOR_ON:
	case PaperList::FIELD_SELECTOR_CONFLICT:
	case PaperList::FIELD_TOPIC_INTEREST:
	case PaperList::FIELD_REVIEWER_TYPE_ICON:
	case PaperList::FIELD_REVIEWER_PREFERENCE:
	case PaperList::FIELD_DESIRABILITY:
	    return "<col width='0*' />";
	default:
	    return "<col />";
	}
    }

    public function content_empty($pl, $row) {
	global $Conf;
	switch ($this->group_id) {
	case PaperList::FIELD_REVIEWER_TYPE:
	    return !$row->reviewType && $row->conflictType <= 0;
	case PaperList::FIELD_REVIEWER_STATUS:
	    return !$row->reviewId;
	case PaperList::FIELD_OPT_TOPIC_NAMES:
	    return isset($row->topicIds) && $row->topicIds == "";
	case PaperList::FIELD_OPT_ABSTRACT:
	    return $row->abstract == "";
	case PaperList::FIELD_TAGS:
	    return !$pl->contact->canViewTags($row);
	case PaperList::FIELD_LEAD:
	    return (!$pl->contact->actPC($row, true) || !$row->leadContactId);
	case PaperList::FIELD_SHEPHERD:
	    return (!$pl->contact->canViewDecision($row, true)
		    || !$row->shepherdContactId);
	case PaperList::FIELD_COLLABORATORS:
	    return ($row->collaborators == ""
		    || strcasecmp($row->collaborators, "None") == 0
		    || (!$pl->contact->privChair
			&& !$pl->contact->canViewAuthors($row, true)));
	default:
	    return false;
	}
    }

    public function content($pl, $row) {
	global $Conf;
	switch ($this->group_id) {
	case PaperList::FIELD_ID:
	    $href = $pl->_paperLink($row);
	    return "<a href='$href' tabindex='4'>#$row->paperId</a>";
	case PaperList::FIELD_TITLE:
	    $href = $pl->_paperLink($row);
            $x = Text::highlight($row->title, defval($pl->search->matchPreg, "title"));
	    return "<a href='$href' tabindex='5'>" . $x . "</a>" . $pl->_contentDownload($row);
	case PaperList::FIELD_STATUS:
	case PaperList::FIELD_STATUS_SHORT:
	    if ($row->timeSubmitted <= 0 && $row->timeWithdrawn <= 0)
		$pl->any->need_submit = true;
	    if ($row->outcome > 0 && $pl->contact->canViewDecision($row))
		$pl->any->accepted = true;
	    if ($row->outcome > 0 && $row->timeFinalSubmitted <= 0
		&& $pl->contact->canViewDecision($row))
		$pl->any->need_final = true;
	    $long = 0;
	    if ($pl->search->limitName != "a" && $pl->contact->privChair)
		$long = 2;
	    if ($this->id == PaperList::FIELD_STATUS_SHORT)
		$long = ($long == 2 ? -2 : -1);
	    return $pl->contact->paperStatus($row->paperId, $row, $long);
	case PaperList::FIELD_REVIEWER_MONITOR:
	    $t = Text::user_html($row->reviewFirstName, $row->reviewLastName, $row->reviewEmail) . "<br /><small>Last login: ";
	    return $t . ($row->reviewLastLogin ? $Conf->printableTimeShort($row->reviewLastLogin) : "Never") . "</small>";
	case PaperList::FIELD_REVIEWER_TYPE:
	    if ($row->reviewType == REVIEW_PRIMARY)
		$t = "Primary";
	    else if ($row->reviewType == REVIEW_SECONDARY)
		$t = "Secondary";
	    else if ($row->reviewType == REVIEW_EXTERNAL)
		$t = "External";
	    else if ($row->reviewType)
		$t = "Review";
	    else if ($row->conflictType >= CONFLICT_AUTHOR)
		return "<span class='rtype rtype_con'>Author</span>";
	    else if ($row->conflictType > 0)
		return "<span class='rtype rtype_con'>Conflict</span>";
	    else
		return "";
	    $ranal = $pl->_reviewAnalysis($row);
	    if ($ranal->needsSubmit)
		$pl->any->need_review = true;
	    $t = "$ranal->link1<span class='rtype'>$t</span>$ranal->link2";
	    if ($ranal->completion)
		$t .= "&nbsp;<span class='rstat'>($ranal->completion)</span>";
	    return $t;
	case PaperList::FIELD_REVIEWER_STATUS:
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
	case PaperList::FIELD_REVIEWER_TYPE_ICON:
	    $a1 = $a2 = "";
	    if ($row->conflictType > 0 && $pl->showConflict)
		return $Conf->cacheableImage("_.gif", "Conflict", "Conflict", "ass-1");
	    else if ($row->reviewType === null)
		return $Conf->cacheableImage("_.gif", "", "", "ass0");
	    else {
		$ranal = $pl->_reviewAnalysis($row);
		if ($ranal->needsSubmit)
		    $pl->any->need_review = true;
		$t = PaperList::_reviewIcon($row, $ranal, true);
		if ($ranal->round)
		    return "<div class='pl_revtype_round'>" . $t . "</div>";
		else
		    return $t;
	    }
	case PaperList::FIELD_REVIEWS_STATUS:
	    // see also _sortReviewsStatus
	    if ($row->conflictType > 0 && !$pl->contact->privChair
		&& ($row->conflictType < CONFLICT_AUTHOR
		    || !$Conf->timeAuthorViewReviews()))
		return "";
	    else if ($row->reviewCount != $row->startedReviewCount)
		return "<b>$row->reviewCount</b>/$row->startedReviewCount";
	    else
		return "<b>$row->reviewCount</b>";
	case PaperList::FIELD_SELECTOR:
	case PaperList::FIELD_SELECTOR_ON:
	case PaperList::FIELD_SELECTOR_CONFLICT:
	    $pl->any->sel = true;
	    $c = "";
	    if (($this->id == PaperList::FIELD_SELECTOR_ON
		 || ($this->id == PaperList::FIELD_SELECTOR_CONFLICT && $row->conflictType > 0))
		&& (!$pl->papersel || defval($pl->papersel, $row->paperId, 1))) {
		$c .= " checked='checked'";
		$pl->foldRow = false;
	    }
	    if ($this->id == PaperList::FIELD_SELECTOR_CONFLICT && $row->conflictType >= CONFLICT_AUTHOR)
		$c .= " disabled='disabled'";
	    if ($this->id != PaperList::FIELD_SELECTOR_CONFLICT)
		$c .= " onclick='pselClick(event,this)'";
	    $t = "<span class=\"pl_rownum fx6\">" . $pl->count . ". </span>" . "<input type='checkbox' class='cb' name='pap[]' value='$row->paperId' tabindex='3' id='psel$pl->count' $c/>";
            if ($this->id == PaperList::FIELD_SELECTOR_CONFLICT)
                $t .= "<span id='assrev" . $row->paperId . "ok' class='ajaxcheck'></span>";
            return $t;
	case PaperList::FIELD_ASSIGN_REVIEW:
	    if ($row->conflictType >= CONFLICT_AUTHOR)
		return "<span class='author'>Author</span>";
	    $rt = ($row->conflictType > 0 ? -1 : min(max($row->reviewType, 0), REVIEW_PRIMARY));
	    return tagg_select("assrev$row->paperId",
			       array(0 => "None", REVIEW_PRIMARY => "Primary",
				     REVIEW_SECONDARY => "Secondary",
				     REVIEW_PC => "Optional",
				     -1 => "Conflict"), $rt,
			       array("tabindex" => 3, "onchange" => "hiliter(this)"))
		. "<span id='assrev" . $row->paperId . "ok'></span>";
	case PaperList::FIELD_TOPIC_INTEREST:
	    return htmlspecialchars($row->topicInterestScore + 0);
	case PaperList::FIELD_OPT_TOPIC_NAMES:
	    return join(", ", $pl->rf->webTopicArray($row->topicIds, defval($row, "topicInterest")));
	case PaperList::FIELD_OPT_ALL_REVIEWER_NAMES:
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
		$n = $pl->maybeConflict($n, $pl->contact->canViewReviewerIdentity($row, null, true));
	    }
	    return $n;
	case PaperList::FIELD_OPT_PC_CONFLICTS:
	    $x = "," . $row->allConflictType;
	    $y = array();
	    foreach (pcMembers() as $pc)
		if (strpos($x, "," . $pc->contactId . " ") !== false)
		    $y[] = Text::name_html($pc);
	    return join(", ", $y);
	case PaperList::FIELD_REVIEWER_PREFERENCE:
	    return (isset($row->reviewerPreference) ? htmlspecialchars($row->reviewerPreference) : "0");
	case PaperList::FIELD_EDIT_REVIEWER_PREFERENCE:
	    if ($row->conflictType > 0)
		return "N/A";
	    $x = (isset($row->reviewerPreference) ? htmlspecialchars($row->reviewerPreference) : "0");
	    return "<input class='textlite' type='text' size='4' name='revpref$row->paperId' id='revpref$row->paperId' value=\"$x\" tabindex='2' /> <img id='revpref" . $row->paperId . "ok' src='" . hoturl_image("images/_.gif") . "' alt='' class='ajaxcheck' />";
	case PaperList::FIELD_DESIRABILITY:
	    return (isset($row->desirability) ? htmlspecialchars($row->desirability) : "0");
	case PaperList::FIELD_ALL_PREFERENCES:
	    $prefs = PaperList::_rowPreferences($row);
	    $text = "";
	    foreach (pcMembers() as $pcid => $pc)
		if (($pref = defval($prefs, $pcid, null))) {
		    $text .= ($text == "" ? "" : ", ")
			. Text::name_html($pc) . preferenceSpan($pref);
		}
	    return $text;
	case PaperList::FIELD_OPT_ABSTRACT:
	    if ($row->abstract == "")
		return "";
            return Text::highlight($row->abstract, defval($pl->search->matchPreg, "abstract"));
	case PaperList::FIELD_TAGS:
	    if (!$pl->contact->canViewTags($row))
		return "";
	    if (($t = $row->paperTags) !== "")
		$t = $pl->tagger->unparse_link_viewable($row->paperTags,
                                                          $pl->search->orderTags,
                                                          $row->conflictType <= 0);
	    return $t;
	case PaperList::FIELD_LEAD:
	    if (!$row->leadContactId)
		return "";
	    $visible = $pl->contact->actPC($row);
	    return $pl->_contentPC($row->leadContactId, $visible);
	case PaperList::FIELD_SHEPHERD:
	    if (!$row->shepherdContactId)
		return "";
	    $visible = $pl->contact->actPC($row) || $pl->contact->canViewDecision($row);
	    return $pl->_contentPC($row->shepherdContactId, $visible);
	case PaperList::FIELD_COLLABORATORS:
	    if ($row->collaborators == ""
		|| strcasecmp($row->collaborators, "None") == 0
		|| (!$pl->contact->privChair
		    && !$pl->contact->canViewAuthors($row, true)))
		return "";
	    $x = "";
	    foreach (explode("\n", $row->collaborators) as $c)
		$x .= ($x === "" ? "" : ", ") . trim($c);
            return Text::highlight($x, defval($pl->search->matchPreg, "collaborators"));
	default:
	    return "";
	}
    }
}

class AuthorsPaperColumn extends PaperColumn {
    public function __construct($name, $id, $view, $extra) {
        parent::__construct($name, $id, $view, $extra);
    }
    public function header($pl, $row = null, $ordinal = 0) {
        return "Authors";
    }
    public function content($pl, $row) {
	if (!$pl->contact->privChair
            && !$pl->contact->canViewAuthors($row, true))
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

class ConflictMatchPaperColumn extends PaperColumn {
    private $field;
    public function __construct($name, $id, $view, $extra = 0) {
        parent::__construct($name, $id, $view, $extra);
        $this->field = ($this->id == PaperList::FIELD_AUTHOR_MATCH ? "authorInformation" : "collaborators");
    }
    public function header($pl, $row = null, $ordinal = 0) {
	if ($this->id == PaperList::FIELD_AUTHOR_MATCH)
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

class ScorePaperColumn extends PaperColumn {
    public $score;
    public function __construct($name, $id, $foldnum) {
        parent::__construct($name, $id, Column::VIEW_COLUMN, array());
        $this->minimal = $this->sortable = true;
        $this->cssname = "score";
        $this->foldnum = $foldnum;
        $this->foldname = $this->name;
        $this->score = $name;
    }
    public function prepare($pl, &$queryOptions, $folded) {
        if (!$pl->scoresOk)
            return false;
        if (!$folded || $this->sorting) {
            $revView = $pl->contact->viewReviewFieldsScore(null, true);
            if ($pl->rf->authorView[$this->score] <= $revView)
                return false;
            if (!isset($queryOptions["scores"]))
                $queryOptions["scores"] = array();
            $queryOptions["scores"][$this->score] = true;
            $this->max = $pl->rf->maxNumericScore($this->score);
        }
	return true;
    }
    public function sort($pl, &$rows) {
        $scoreName = $this->score . "Scores";
        foreach ($rows as $row)
            if ($pl->contact->canViewReview($row, null))
                $pl->score_analyze($row, $scoreName, $this->max,
                                   $pl->scoreSort);
            else
                $pl->score_reset($row);
        $pl->score_sort($rows, $pl->scoreSort);
    }
    public function header($pl, $row = null, $ordinal = 0) {
        return $pl->rf->webFieldAbbrev($this->score);
    }
    public function col() {
        return "<col width='0*' />";
    }
    public function content_empty($pl, $row) {
        return !$pl->contact->canViewReview($row, null, $fakeWhyNotView, true)
            && !$pl->contact->privChair;
    }
    public function content($pl, $row) {
	global $Conf;
        $allowed = $pl->contact->canViewReview($row, null, $fakeWhyNotView, true);
        $fname = $this->score . "Scores";
        if (($allowed || $pl->contact->privChair) && $row->$fname) {
            $t = $Conf->textValuesGraph($row->$fname, $this->max, 1, defval($row, $this->score), $pl->rf->reviewFields[$this->score]);
            if (!$allowed)
                $t = "<span class='fx20'>$t</span>";
            return $t;
        } else
            return "";
    }
}

class FormulaPaperColumn extends PaperColumn {
    public function __construct($name, $id, $formula, $foldnum) {
        parent::__construct($name, $id, Column::VIEW_COLUMN, array("minimal" => true, "sortable" => true, "foldnum" => $foldnum));
        $this->cssname = "formula";
        $this->formula = $formula;
    }
    public function prepare($pl, &$queryOptions, $folded) {
	global $Conf;
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
    public function sort($pl, &$rows) {
        $formulaf = $this->formula_function;
        foreach ($rows as $row) {
            $row->_sort_info = $formulaf($row, $pl->contact, "s");
            $row->_sort_average = 0;
        }
        usort($rows, array($pl, "score_numeric_compar"));
        break;
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
        if ($row->conflictType > 0 && $pl->contact->privChair)
            return "<span class='fn20'>$t</span><span class='fx20'>"
                . $formulaf($row, $pl->contact, "h", true) . "</span>";
        else
            return $t;
    }
}

class TagReportPaperColumn extends PaperColumn {
    public function __construct($tag, $id, $foldnum) {
        parent::__construct("tagrep_" . preg_replace('/\W+/', '_', $tag), $id, Column::VIEW_ROW, $foldnum);
        $this->cssname = "tagrep";
        $this->tag = $tag;
    }
    public function prepare($pl, &$queryOptions, $folded) {
        if (!$pl->contact->privChair)
            return false;
        if (!$folded)
            $queryOptions["tags"] = 1;
        return true;
    }
    public function header($pl, $row = null, $ordinal = 0) {
        return "“" . $this->tag . "” tag report";
    }
    public function content_empty($pl, $row) {
        return !$pl->contact->canViewTags($row);
    }
    public function content($pl, $row) {
        if (!$pl->contact->canViewTags($row))
            return "";
        if (($t = " " . $row->paperTags) === " ")
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
