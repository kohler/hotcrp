<?php
// paperlist.inc -- HotCRP helper class for producing paper lists
// HotCRP is Copyright (c) 2006-2007 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

require_once("baselist.inc");

class PaperList extends BaseList {

    const FIELD_SELECTOR = 100;
    const FIELD_SELECTOR_ON = 101;
    const FIELD_SELECTOR_CONFLICT = 102;
    
    const FIELD_ID = 1;
    const FIELD_REVIEWID = 2;
    const FIELD_PIDARRAY = 9;
    
    const FIELD_TITLE = 11;
    const FIELD_REVIEWTITLE = 12;
    
    const FIELD_STATUS = 20;
    const FIELD_STATUS_SHORT = 21;
    const FIELD_REVIEWER = 25;
    const FIELD_REVIEWER_TYPE = 26;
    const FIELD_REVIEWER_TYPE_ICON = 27;
    const FIELD_ASSIGN_REVIEW = 35;
    const FIELD_TOPIC_INTEREST = 36;
    const FIELD_TOPIC_NAMES = 37;
    const FIELD_ALL_REVIEWER_NAMES = 38;
    const FIELD_REVIEWER_PREFERENCE = 39;
    const FIELD_EDIT_REVIEWER_PREFERENCE = 40;
    const FIELD_REVIEWS_STATUS = 41;
    const FIELD_DESIRABILITY = 43;
    const FIELD_ALL_PREFERENCES = 44;
    const FIELD_AUTHOR_MATCH = 47;
    const FIELD_COLLABORATORS_MATCH = 48;
    
    const FIELD_OPT_AUTHORS = 70;
    const FIELD_TAGS = 71;
    const FIELD_OPT_ABSTRACT = 72;
    const FIELD_FOLD = 75;

    const HEADER_ALL = 2;
    const HEADER_TITLES = 1;
    const HEADER_NONE = 0;
    
    var $showHeader;
    var $sortField;
    var $sortInfo;
    var $sortAverage;
    var $reverseSort;
    var $sortable;
    var $count;
    var $needFinalize;
    var $needSubmitReview;
    var $anySelector;
    var $listNumber;
    var $contact;
    var $scoresOk;
    var $scoreMax;
    var $search;
    var $authorMatch;
    var $collaboratorsMatch;
    var $extraText;
    var $unfolded;
    var $foldedAbstract;
    var $foldedTags;
    var $paperLink;
    var $paperLinkArgs;
    var $showConflict;
    var $headerInfo;
    var $papersel;
    
    function PaperList($sortable = true, $list = false, $search = null, $extraText = null) {
	global $paperListFields;
	$this->showHeader = self::HEADER_ALL;

	$s = ($sortable ? defval($_REQUEST, "sort", "") : "");
	$x = (strlen($s) ? $s[strlen($s)-1] : "");
	$this->reverseSort = ($x == "R");
	if ($x == "R" || $x == "N")
	    $s = substr($s, 0, strlen($s) - 1);
	if (("x" . (int) $s) == ("x" . $s))
	    $this->sortField = (int) $s;
	else
	    $this->sortField = null;
	$this->sortable = $sortable;

	$this->paperLink = "";
	$this->paperLinkArgs = "";
	if ($list) {
	    $this->listNumber = oldestListNumber($search ? $search->listId(defval($_REQUEST, "sort", "")) : "");
	    $this->paperLinkArgs .= "&amp;ls=" . $this->listNumber;
	} else
	    $this->listNumber = 0;

	$this->search = $search;
	$this->extraText = $extraText;
	$this->scoresOk = false;
	$this->showConflict = true;
	$this->papersel = null;
    }

    function selector($fieldId, &$queryOptions) {
	global $Conf, $reviewScoreNames;
	switch (self::_normalizeField($fieldId)) {
	case self::FIELD_TOPIC_INTEREST:
	    $rf = reviewForm();
	    if (!count($rf->topicName))
		return false;
	    $queryOptions['topicInterestScore'] = 1;
	    break;
	case self::FIELD_TOPIC_NAMES:
	    $queryOptions['topics'] = 1;
	    break;
	case self::FIELD_ALL_REVIEWER_NAMES:
	    $queryOptions['reviewList'] = 1;
	    break;
	case self::FIELD_REVIEWER_PREFERENCE:
	case self::FIELD_EDIT_REVIEWER_PREFERENCE:
	    $queryOptions['reviewerPreference'] = 1;
	    $Conf->footerStuff .= "<script type='text/javascript'>addRevprefAjax();</script>";
	    break;
	case self::FIELD_DESIRABILITY:
	    $queryOptions['desirability'] = 1;
	    break;
	case self::FIELD_ALL_PREFERENCES:
	    $queryOptions['allReviewerPreference'] = 1;
	    break;
	case self::FIELD_SCORE:
	    if (!$this->scoresOk)
		return false;
	    if (!isset($queryOptions['scores']))
		$queryOptions['scores'] = array();
	    $rf = reviewForm();
	    $scoreName = $reviewScoreNames[$fieldId - self::FIELD_SCORE];
	    $queryOptions['scores'][] = $scoreName;
	    $this->scoreMax[$scoreName] = $rf->maxNumericScore($scoreName);
	    break;
	case self::FIELD_FOLD:
	    $this->unfolded = false;
	    break;
	case self::FIELD_REVIEWER:
	    $queryOptions['allReviewScores'] = 1;
	    $queryOptions['reviewerName'] = 1;
	    break;
	case self::FIELD_REVIEWER_TYPE:
	case self::FIELD_REVIEWER_TYPE_ICON:
	    if ($this->search->reviewerName && !isset($queryOptions['allReviewScores'])) {
		// if reviewer name is unique, use that to decide icons to show
		// NB $this->search->reviewerName is sqlq'd already
		$result = $Conf->q("select contactId from ContactInfo where concat(firstName, ' ', lastName, ' ', email) like '%" . $this->search->reviewerName . "%'");
		if (edb_nrows($result) == 1) {
		    $row = edb_row($result);
		    $queryOptions['reviewerContact'] = $row[0];
		    $this->showConflict = false;
		}
	    }
	    break;
	case self::FIELD_TAGS:
	    if (!$this->contact->isPC)
		return false;
	    if ($this->foldedTags === false)
		$queryOptions['tags'] = 1;
	    break;
	}
	return true;
    }

    function _sortBase($a, $b) {
	return $a->paperId - $b->paperId;
    }
    
    function _sortPidarray($a, $b) {
	return $this->sortInfo[$a->paperId] - $this->sortInfo[$b->paperId];
    }
    
    function _sortTitle($a, $b) {
	$x = strcasecmp($a->title, $b->title);
	return $x ? $x : $a->paperId - $b->paperId;
    }
    
    function _sortStatus($a, $b) {
	$x = $b->outcome - $a->outcome;
	$x = $x ? $x : ($a->timeWithdrawn > 0) - ($b->timeWithdrawn > 0);
	$x = $x ? $x : ($b->timeSubmitted > 0) - ($a->timeSubmitted > 0);
	$x = $x ? $x : ($b->paperStorageId > 1) - ($a->paperStorageId > 1);
	return $x ? $x : $a->paperId - $b->paperId;
    }
    
    function _sortReviewer($a, $b) {
	$x = strcasecmp($a->reviewLastName, $b->reviewLastName);
	$x = $x ? $x : strcasecmp($a->reviewFirstName, $b->reviewFirstName);
	$x = $x ? $x : strcasecmp($a->reviewEmail, $b->reviewEmail);
	return $x ? $x : $a->paperId - $b->paperId;
    }

    function _sortReviewType($a, $b) {
	$x = $b->reviewType - $a->reviewType;
	return $x ? $x : $a->paperId - $b->paperId;
    }

    function _sortReviewsStatus($a, $b) {
	$x = $a->reviewCount - $b->reviewCount;
	$x = $x ? $x : $a->startedReviewCount - $b->startedReviewCount;
	return $x ? $x : $a->paperId - $b->paperId;
    }

    function _sortTopicInterest($a, $b) {
	$x = $b->topicInterestScore - $a->topicInterestScore;
	return $x ? $x : $a->paperId - $b->paperId;
    }
    
    function _sortReviewerPreference($a, $b) {
	$x = $b->reviewerPreference - $a->reviewerPreference;
	return $x ? $x : $a->paperId - $b->paperId;
    }
    
    function _sortDesirability($a, $b) {
	$x = $b->desirability - $a->desirability;
	return $x ? $x : $a->paperId - $b->paperId;
    }

    function _sort($rows, $pidarray) {
	global $Conf, $reviewScoreNames;
	switch (self::_normalizeField($this->sortField)) {
	case self::FIELD_ID:
	case self::FIELD_REVIEWID:
	    ksort($rows);
	    break;
	case self::FIELD_PIDARRAY:
	    $this->sortInfo = array_flip($pidarray);
	    usort($rows, array($this, "_sortPidarray"));
	    break;
	case self::FIELD_TITLE:
	case self::FIELD_REVIEWTITLE:
	    usort($rows, array("PaperList", "_sortTitle"));
	    break;
	case self::FIELD_STATUS:
	case self::FIELD_STATUS_SHORT:
	    usort($rows, array("PaperList", "_sortStatus"));
	    break;
	case self::FIELD_REVIEWER:
	    usort($rows, array("PaperList", "_sortReviewer"));
	    break;
	case self::FIELD_REVIEWER_TYPE:
	case self::FIELD_REVIEWER_TYPE_ICON:
	case self::FIELD_ASSIGN_REVIEW:
	    usort($rows, array("PaperList", "_sortReviewType"));
	    break;
	case self::FIELD_REVIEWS_STATUS:
	    usort($rows, array("PaperList", "_sortReviewsStatus"));
	    break;
	case self::FIELD_TOPIC_INTEREST:
	    usort($rows, array("PaperList", "_sortTopicInterest"));
	    break;
	case self::FIELD_REVIEWER_PREFERENCE:
	case self::FIELD_EDIT_REVIEWER_PREFERENCE:
	    usort($rows, array("PaperList", "_sortReviewerPreference"));
	    break;
	case self::FIELD_DESIRABILITY:
	    usort($rows, array("PaperList", "_sortDesirability"));
	    break;
	case self::FIELD_SCORE:
	    $scoreName = $reviewScoreNames[$this->sortField - self::FIELD_SCORE];
	    $scoreMax = $this->scoreMax[$scoreName];
	    $scoreName .= "Scores";
	    $this->sortInfo = array();
	    $this->sortAverage = array();
	    $scoresort = defval($_SESSION, "scoresort", 0);
	    foreach ($rows as $row)
		if (!$this->contact->privChair && !$this->contact->canViewReview($row, null, $Conf))
		    $this->sortInfo[$row->paperId] = self::LOTSAZEROS;
		else
		    $this->_setSortRow($row, $scoreName, $scoreMax, $scoresort);
	    usort($rows, array($this, ($scoresort ? "_sortScoreNum" : "_sortScore")));
	    break;
	}
	return $rows;
    }

    function _sortReviewOrdinal(&$rows) {
	for ($i = 0; $i < count($rows); $i++) {
	    for ($j = $i + 1; $j < count($rows) && $rows[$i]->paperId == $rows[$j]->paperId; $j++)
		/* do nothing */;
	    // insertion sort
	    for ($k = $i + 1; $k < $j; $k++) {
		$v = $rows[$k];
		for ($l = $k - 1; $l >= $i; $l--) {
		    $w = $rows[$l];
		    if ($v->reviewOrdinal && $w->reviewOrdinal)
			$cmp = $v->reviewOrdinal - $w->reviewOrdinal;
		    else if ($v->reviewOrdinal || $w->reviewOrdinal)
			$cmp = $v->reviewOrdinal ? -1 : 1;
		    else
			$cmp = $v->reviewId - $w->reviewId;
		    if ($cmp >= 0)
			break;
		    $rows[$l + 1] = $rows[$l];
		}
		$rows[$l + 1] = $v;
	    }
	}
    }
    
    function header($fieldId, $ordinal, $row = null, $reviewList = null) {
	global $reviewScoreNames;
	switch (self::_normalizeField($fieldId)) {
	case self::FIELD_ID:
	case self::FIELD_REVIEWID:
	    return "ID";
	case self::FIELD_TITLE:
	case self::FIELD_REVIEWTITLE:
	    return "Title";
	case self::FIELD_STATUS:
	case self::FIELD_STATUS_SHORT:
	    return "Status";
	case self::FIELD_REVIEWER:
	    return "Reviewer";
	case self::FIELD_REVIEWER_TYPE:
	case self::FIELD_REVIEWER_TYPE_ICON:
	    return "<acronym title='Reviewer type'>Type</acronym>";
	case self::FIELD_REVIEWS_STATUS:
	    return "<acronym title='\"1/2\" means 1 complete review out of 2 assigned reviews'>#&nbsp;Reviews</acronym>";
	case self::FIELD_SELECTOR:
	case self::FIELD_SELECTOR_ON:
	    return ($ordinal ? "&nbsp;" : "");
	case self::FIELD_SELECTOR_CONFLICT:
	    return "Conflict";
	case self::FIELD_ASSIGN_REVIEW:
	    return "Assignment";
	case self::FIELD_TOPIC_INTEREST:
	    return "Topic<br/>score";
	case self::FIELD_TOPIC_NAMES:
	    return "Topics";
	case self::FIELD_ALL_REVIEWER_NAMES:
	    if ($row === null || !isset($reviewList[$row->paperId]))
		return "Reviewers";
	    else
		return "Reviewers (" . count($reviewList[$row->paperId]) . ")";
	case self::FIELD_REVIEWER_PREFERENCE:
	case self::FIELD_EDIT_REVIEWER_PREFERENCE:
	    return "Preference";
	case self::FIELD_DESIRABILITY:
	    return "Desirability";
	case self::FIELD_ALL_PREFERENCES:
	    return "Preferences";
	case self::FIELD_SCORE: {
	    $rf = reviewForm();
	    $scoreName = $reviewScoreNames[$fieldId - self::FIELD_SCORE];
	    return $rf->webFieldAbbrev($scoreName);
	}
	case self::FIELD_AUTHOR_MATCH:
	    return "<span class='match'>Potential conflict in authors</span>";
	case self::FIELD_COLLABORATORS_MATCH:
	    return "<span class='match'>Potential conflict in collaborators</span>";
	case self::FIELD_OPT_AUTHORS:
	    return "Authors";
	case self::FIELD_TAGS:
	    return "Tags";
	case self::FIELD_OPT_ABSTRACT:
	    return "Abstract";
	default:
	    return "&lt;$fieldId&gt;?";
	}
    }

    function _contentDownload($row) {
	global $ConfSiteBase, $Conf;
	if ($row->size == 0 || !$this->contact->canDownloadPaper($row, $Conf))
	    return "";
	else if ($row->mimetype == "application/pdf")
	    return "&nbsp;<a href=\"" . $Conf->makeDownloadPath($row->paperId, $row->mimetype) . "\">" . $Conf->cacheableImage("pdf.png", "[PDF]") . "</a>";
	else if ($row->mimetype == "application/postscript")
	    return "&nbsp;<a href=\"" . $Conf->makeDownloadPath($row->paperId, $row->mimetype) . "\">" . $Conf->cacheableImage("postscript.png", "[PS]") . "</a>";
	else
	    return "&nbsp;<small><a href=\"" . $Conf->makeDownloadPath($row->paperId, $row->mimetype) . "\">[Download]</a></small>";
    }

    function _joinMatches($m) {
	$x = array();
	$out = "";
	foreach ($m[0] as $T) {
	    $t = strtolower($T);
	    if (!isset($x[$t])) {
		$x[$t] = 1;
		$out .= $T . " ";
	    }
	}
	if ($out) {
	    $this->unfolded = true;
	    return htmlspecialchars(substr($out, 0, strlen($out) - 1));
	} else
	    return "";
    }

    function _paperLink($row) {
	global $Conf;
	if (($pl = $this->paperLink))
	    return $pl;
	if (($row->reviewCount && $this->contact->canViewReview($row, null, $Conf))
	    || ($this->contact->isPC && $this->contact->canReview($row, null, $Conf)))
	    return "review";
	return "paper";
    }
    
    function content($fieldId, $row, $reviewList) {
	global $ConfSiteBase, $Conf, $reviewTypeName, $reviewScoreNames;
	switch (self::_normalizeField($fieldId)) {
	case self::FIELD_ID:
	    $pl = $this->_paperLink($row);
	    return "<a href='${ConfSiteBase}$pl.php?paperId=$row->paperId" . $this->paperLinkArgs . "' tabindex='4'>#$row->paperId</a>";
	case self::FIELD_REVIEWID:
	    return "<a href='${ConfSiteBase}review.php?reviewId=$row->reviewId" . $this->paperLinkArgs . "' tabindex='4'>#$row->paperId" . unparseReviewOrdinal($row->reviewOrdinal) . "</a>";
	case self::FIELD_TITLE:
	    $pl = $this->_paperLink($row);
	    return "<a href='${ConfSiteBase}$pl.php?paperId=$row->paperId" . $this->paperLinkArgs . "' tabindex='5'>" . htmlspecialchars($row->title) . "</a>" . $this->_contentDownload($row);
	case self::FIELD_REVIEWTITLE:
	    return "<a href='${ConfSiteBase}review.php?reviewId=$row->reviewId" . $this->paperLinkArgs . "' tabindex='5'>" . htmlspecialchars($row->title) . "</a>" . $this->_contentDownload($row);
	case self::FIELD_STATUS:
	case self::FIELD_STATUS_SHORT:
	    if ($row->timeSubmitted <= 0 && $row->timeWithdrawn <= 0)
		$this->needFinalize++;
	    return $this->contact->paperStatus($row->paperId, $row, $fieldId == self::FIELD_STATUS ? 0 : -1);
	case self::FIELD_REVIEWER:
	    return contactHtml($row->reviewFirstName, $row->reviewLastName, $row->reviewEmail);
	case self::FIELD_REVIEWER_TYPE:
	    if ($row->reviewType > 0 && (!isset($row->reviewSubmitted) || !$row->reviewSubmitted))
		$this->needSubmitReview++;
	    if ($row->reviewId) {
		$a1 = "<a href=\"${ConfSiteBase}review.php?reviewId=$row->reviewId" . $this->paperLinkArgs . "\">";
		$a2 = "</a>&nbsp;";
		if ($row->reviewSubmitted)
		    $a2 .= "<span class='rstat'>(Complete)</span>";
		else if ($row->reviewType == REVIEW_SECONDARY && $row->reviewNeedsSubmit == 0)
		    $a2 .= "<span class='rstat'>(Delegated)</span>";
		else if ($row->reviewModified == 0)
		    $a2 .= "<span class='rstat'>(Not&nbsp;started)</span>";
		else
		    $a2 .= "<span class='rstat'>(In&nbsp;progress)</span>";
	    } else
		$a1 = $a2 = "";
	    if ($row->reviewType == REVIEW_PRIMARY)
		return "$a1<span class='rtype rtype_pri'>Primary</span>$a2";
	    else if ($row->reviewType == REVIEW_SECONDARY)
		return "$a1<span class='rtype rtype_sec'>Secondary</span>$a2";
	    else if ($row->reviewType == REVIEW_EXTERNAL)
		return "$a1<span class='rtype rtype_req'>External</span>$a2";
	    else if ($row->reviewType)
		return "$a1<span class='rtype'>Review</span>$a2";
	    else if ($row->conflictType >= CONFLICT_AUTHOR)
		return "<span class='rtype rtype_con'>Author</span>";
	    else if ($row->conflictType > 0)
		return "<span class='rtype rtype_con'>Conflict</span>";
	    else
		return "";
	case self::FIELD_REVIEWER_TYPE_ICON:
	    $needSubmit = ($row->reviewType > 0 && (!isset($row->reviewSubmitted) || !$row->reviewSubmitted));
	    if ($needSubmit)
		$this->needSubmitReview++;
	    $a1 = $a2 = "";
	    if ($row->reviewId) {
		$a1 = "<a href=\"${ConfSiteBase}review.php?reviewId=$row->reviewId" . $this->paperLinkArgs . "\">";
		$a2 = "</a>";
	    }
	    if ($row->conflictType > 0 && $this->showConflict) {
		$which = -1;
		$alt = "Conflict";
	    } else if ($row->reviewType === null) {
		$which = 0;
		$alt = "";
	    } else {
		$which = $row->reviewType;
		$alt = $reviewTypeName[$row->reviewType];
		if ($needSubmit) {
		    $which .= "n";
		    $alt .= " (not complete)";
		}
	    }
	    return $a1 . $Conf->cacheableImage("ass$which.png", $alt, $alt) . $a2;
	case self::FIELD_REVIEWS_STATUS:
	    if ($row->reviewCount != $row->startedReviewCount)
		return "<b>$row->reviewCount</b>/$row->startedReviewCount";
	    else
		return "<b>$row->reviewCount</b>";
	case self::FIELD_SELECTOR:
	case self::FIELD_SELECTOR_ON:
	case self::FIELD_SELECTOR_CONFLICT:
	    $this->anySelector = 1;
	    $c = "";
	    if (($fieldId == self::FIELD_SELECTOR_ON
		 || ($fieldId == self::FIELD_SELECTOR_CONFLICT && $row->conflictType > 0))
		&& (!$this->papersel || in_array($row->paperId, $this->papersel))) {
		$c = " checked='checked'";
		$this->unfolded = true;
	    }
	    if ($fieldId == self::FIELD_SELECTOR_CONFLICT && $row->conflictType >= CONFLICT_AUTHOR)
		$c .= " disabled='disabled'";
	    return "<input type='checkbox' name='pap[]' value='$row->paperId' tabindex='3' id='psel$this->count' onclick='pselClick(event, this, $this->count)' $c/>";
	case self::FIELD_ASSIGN_REVIEW:
	    if ($row->conflictType >= CONFLICT_AUTHOR)
		return "<span class='author'>Author</span>";
	    $rt = ($row->conflictType > 0 ? -1 : min(max($row->reviewType, 0), REVIEW_PRIMARY));
	    $x = "<select name='assrev$row->paperId' tabindex='3' onchange='highlightUpdate()'>";
	    $x .= "<option value='0'" . ($rt == 0 ? " selected='selected'" : "") . ">None</option>";
	    $x .= "<option value='" . REVIEW_PRIMARY . "'" . ($rt == REVIEW_PRIMARY ? " selected='selected'" : "") . ">Primary</option>";
	    $x .= "<option value='" . REVIEW_SECONDARY . "'" . ($rt == REVIEW_SECONDARY ? " selected='selected'" : "") . ">Secondary</option>";
	    $x .= "<option value='-1'" . ($rt == -1 ? " selected='selected'" : "") . ">Conflict</option>";
	    $x .= "</select>";
	    return $x;
	case self::FIELD_TOPIC_INTEREST:
	    return htmlspecialchars($row->topicInterestScore + 0);
	case self::FIELD_TOPIC_NAMES:
	    $rf = reviewForm();
	    return join(", ", $rf->webTopicArray($row->topicIds, isset($row->topicInterest) ? $row->topicInterest : null));
	case self::FIELD_ALL_REVIEWER_NAMES:
	    $n = "";
	    if (isset($reviewList[$row->paperId]))
		foreach ($reviewList[$row->paperId] as $xrow)
		    if ($xrow->lastName) {
			$n .= ($n ? ", " : "");
			$n .= contactHtml($xrow->firstName, $xrow->lastName);
			if ($xrow->reviewType == REVIEW_PRIMARY)
			    $n .= "&nbsp;" . $Conf->cacheableImage("ass" . REVIEW_PRIMARY . ".png", "Primary");
			else if ($xrow->reviewType == REVIEW_SECONDARY)
			    $n .= "&nbsp;" . $Conf->cacheableImage("ass" . REVIEW_SECONDARY . ".png", "Secondary");
		    }
	    return $n;
	case self::FIELD_REVIEWER_PREFERENCE:
	    return (isset($row->reviewerPreference) ? htmlspecialchars($row->reviewerPreference) : "0");
	case self::FIELD_EDIT_REVIEWER_PREFERENCE:
	    if ($row->conflictType > 0)
		return "N/A";
	    $x = (isset($row->reviewerPreference) ? htmlspecialchars($row->reviewerPreference) : "0");
	    return "<input class='textlite' type='text' size='4' name='revpref$row->paperId' value=\"$x\" tabindex='3' />";
	case self::FIELD_DESIRABILITY:
	    return (isset($row->desirability) ? htmlspecialchars($row->desirability) : "0");
	case self::FIELD_ALL_PREFERENCES:
	    $x = "";
	    foreach (explode(';;;', $row->allReviewerPreference) as $rp)
		if (preg_match("/(.*):::(.*):::(.*)/", $rp, $what)
		    && $what[3] != 0) {
		    $x .= ($x ? ", " : "");
		    $x .= contactHtml($what[2], $what[1]);
		    $x .= " [" . htmlspecialchars($what[3]) . "]";
		}
	    return $x;
	case self::FIELD_SCORE:
	    if ($this->contact->privChair || $this->contact->canViewReview($row, null, $Conf)) {
		$scoreName = $reviewScoreNames[$fieldId - self::FIELD_SCORE];
		$itemName = "${scoreName}Scores";
		if ($row->$itemName)
		    return $Conf->textValuesGraph($row->$itemName, $this->scoreMax[$scoreName], 1);
	    }
	    return "";
	case self::FIELD_AUTHOR_MATCH:
	    if (!isset($this->authorMatch))
		return "";
	    preg_match_all("/(" . $this->authorMatch . ")/i", $row->authorInformation, $matches);
	    return $this->_joinMatches($matches);
	case self::FIELD_COLLABORATORS_MATCH:
	    if (!isset($this->collaboratorsMatch))
		return "";
	    preg_match_all("/(" . $this->collaboratorsMatch . ")/i", $row->collaborators, $matches);
	    return $this->_joinMatches($matches);
	case self::FIELD_OPT_AUTHORS:
	    if (!$this->contact->privChair && paperBlind($row))
		return "";
	    $aus = array();
	    cleanAuthor($row);
	    foreach ($row->authorTable as $au)
		$aus[] = $au[1];
	    return htmlspecialchars(join(", ", $aus));
	case self::FIELD_OPT_ABSTRACT:
	    if ($row->abstract == "")
		return "";
	    if ($this->foldedAbstract === false)
		return "<span id='abstract$row->paperId'>" . htmlspecialchars($row->abstract) . "</span>";
	    else {
		$this->foldedAbstract[] = $row->paperId;
		return "<span id='abstract$row->paperId'><em>Loading</em></span>";
	    }
	case self::FIELD_TAGS:
	    if (!$this->contact->canViewTags($row, $Conf))
		return "";
	    if ($this->foldedTags === false) {
		$t = str_replace("#0", "", $row->paperTags);
		$t = preg_replace('/([a-zA-Z!@*_:.][-a-zA-Z0-9!@*_:.\/]*)/',
				  "<a class='q' href='${ConfSiteBase}search.php?q=tag:\$1'>\$1</a>",
				  $t);
		if ($t == "")
		    return "";
		else
		    return "<span id='tags$row->paperId'>" . $t . "</span>";
	    } else {
		$this->foldedTags[] = $row->paperId;
		return "<span id='tags$row->paperId'><em>Loading</em></span>";
	    }
	default:
	    return "";
	}
    }

    function footer($ncol) {
	global $Conf, $ConfSiteBase;
	if ($this->count == 0)
	    return "";
	
	$t = "  <tr class='pl_footgap k" . (($this->count - 1) % 2) . "'><td class='pl_blank' colspan='$ncol'></td></tr>\n";
	$t .= "  <tr class='pl_footrow'>\n    <td class='pl_selector' style='vertical-align: top'>" . $Conf->cacheableImage("bendulft.png", "^^", null, "placthook") . "</td>\n";
	$t .= "    <td id='plact' class='linelinks1' colspan='" . ($ncol - 1) . "'><b>Select papers</b> (or <a href='javascript:void papersel(true)'>select all " . $this->count . "</a>), then ";

	// Begin linelinks
	$t .= "<span class='lll1'><a href='javascript:void crpfocus(\"plact\",1)'>Download</a></span><span class='lld1'><b>:</b> &nbsp;";
	$t .= "<select id='plact1_d' name='getaction'>
    <option value='paper' selected='selected'>Papers</option>
    <option value='abstracts'>Abstracts</option>
    <option value='revform'>Review forms</option>\n";
	if ($this->contact->isPC && $Conf->timeSubmitFinalPaper())
	    $t .= "    <option value='final'>Final papers</option>\n";
	if ($this->contact->isPC || $this->contact->isReviewer)
	    $t .= "    <option value='rev'>All reviews</option>\n";
	if ($this->contact->privChair || ($this->contact->isReviewer && $Conf->blindSubmission() < 2))
	    $t .= "    <option value='authors'>Authors (text)</option>\n";
	if ($this->contact->privChair) {
	    $t .= "    <option value='contact'>Contact emails (text)</option>\n";
	    $t .= "    <option value='pcconflicts'>PC conflicts (text)</option>\n";
	}
	if ($this->contact->isPC)
	    $t .= "    <option value='topics'>Topics (text)</option>\n";
	if ($this->contact->privChair || ($this->contact->isPC && $Conf->timePCViewAllReviews()))
	    $t .= "    <option value='scores'>All scores (text)</option>\n";
	$t .= "</select>&nbsp; <input type='submit' class='button_small' name='getgo' value='Go' /></span>";

	$barsep = " <span class='barsep'>&nbsp;|&nbsp;</span> ";
	if ($this->contact->isPC) {
	    $t .= $barsep;
	    $t .= "<span class='lll2'><a href='javascript:void crpfocus(\"plact\",2)'>Tag</a></span><span class='lld2'><b>:</b> &nbsp;";
	    $t .= "<select name='tagtype'>
    <option value='a' selected='selected'>Add</option><option value='d'>Remove</option><option value='s'>Define</option><option value='so'>Define ordered</option><option value='ao'>Add ordered</option>
  </select> &nbsp;tag(s) &nbsp;<input id='plact2_d' class='textlite' type='text' name='tag' value='' size='15' /> &nbsp;<input type='submit' class='button_small' name='tagact' value='Go' /></span>";
	}
	
	if ($this->contact->privChair) {
	    $t .= $barsep;
	    $t .= "<span class='lll3'><a href='javascript:void crpfocus(\"plact\",3)'>Assign</a></span><span id='foldass' class='lld3 foldc'><b>:</b> &nbsp;";
	    $t .= "<select id='plact3_d' name='marktype' onchange='fold(\"ass\", (this.value[0] == \"x\"))'>
      <option value='xauto' selected='selected'>Automatic assignments</option>
      <option value='-1' disabled='disabled'></option>
      <option value='conflict'>Mark conflict</option>
      <option value='unconflict'>Remove conflict</option>
      <option value='-3' disabled='disabled'></option>
      <option value='assign" . REVIEW_PRIMARY . "'>Assign primary review</option>
      <option value='assign" . REVIEW_SECONDARY . "'>Assign secondary review</option>
      <option value='assign0'>Unassign reviews</option>
    </select><span class='extension'> &nbsp;for &nbsp;";
	    // <option value='-2' disabled='disabled'></option>
	    // <option value='xpcpaper'>Mark as PC-authored</option>
	    // <option value='xunpcpaper'>Mark as not PC-authored</option>
	    $t .= "<select name='markpc' id='markpc'>\n";
	    $pc = pcMembers();
	    foreach ($pc as $id => $row)
		$t .= "<option value=\"" . htmlspecialchars($row->email) . "\">" . contactHtml($row->firstName, $row->lastName) . "</option>\n";
	    $t .= "</select></span> &nbsp;<input type='submit' class='button_small' name='setassign' value='Go' />";
	    $t .= "</span>";
	}

	if ($this->contact->privChair) {
	    $t .= $barsep;
	    $t .= "<span class='lll4'><a href='javascript:void crpfocus(\"plact\",4)'>Decide</a></span><span class='lld4'><b>:</b> Set to &nbsp;";
	    $t .= outcomeSelector(0, "plact4_d") . " &nbsp;<input type='submit' class='button_small' name='setoutcome' value='Go' /></span>";

	    $t .= $barsep;
	    $t .= "<span class='lll5'><a href='javascript:void crpfocus(\"plact\",5)'>Mail</a></span><span class='lld5'><b>:</b> &nbsp;";
	    $t .= "<select id='plact5_d' name='recipients'><option value='au'>Contact authors</option><option value='rev'>Reviewers</option></select> &nbsp;<input type='submit' class='button_small' name='sendmail' value='Go' /></span>";
	}

	return $t . "</td></tr>\n";
    }

    function addScores($a) {
	if ($this->scoresOk) {
	    $scores = defval($_SESSION, "scores", 1);
	    for ($i = 0; $i < self::FIELD_NUMSCORES; $i++)
		if ($scores & (1 << $i))
		    array_push($a, self::FIELD_SCORE + $i);
	    $this->scoreMax = array();
	}
	return $a;
    }

    function listDescription($listname) {
	switch ($listname) {
	  case "a":
	  case "authorHome":
	    return "My papers";
	  case "s":
	    return "Submitted papers";
	  case "acc":
	    return "Accepted papers";
	  case "all":
	    return "All papers";
	  case "reviewerHome":
	  case "r":
	    return "My reviews";
	  case "rout":
	    return "My incomplete reviews";
	  case "req":
	    return "My review requests";
	  case "reviewAssignment":
	    return "Review assignments";
	  case "conflict":
	    return "Potential conflicts";
	  case "editReviewPreference":
	    return "Reviewer preferences";
	  case "reviewers":
	  case "reviewersSel":
	    return "Proposed assignments";
	  case "revs":
	    return "Reviews";
	  default:
	    return null;
	}
    }
    
    function listFields($listname) {
	switch ($listname) {
	  case "a":
	  case "authorHome":
	    return array(1, 11, 20);
	  case "s":
	  case "acc":
	    return $this->addScores(array(100, 1, 11, 27, 41, 21, 70, 72, 71));
	  case "all":
	    return array(100, 1, 11, 20, 27, 70, 72, 71);
	  case "reviewerHome":
	    $this->paperLink = "review";
	    return array(1, 11, 27, 21);
	  case "r":
	    $this->paperLink = "review";
	    return $this->addScores(array(100, 1, 11, 27, 41, 21, 70, 72, 71));
	  case "rout":
	    $this->paperLink = "review";
	    return $this->addScores(array(100, 1, 11, 27, 41, 21, 70, 72, 71));
	  case "req":
	    $this->paperLink = "review";
	    return $this->addScores(array(100, 1, 11, 27, 41, 21, 70, 72, 71));
	  case "reviewAssignment":
	    $this->paperLink = "assign";
	    return array(1, 11, 39, 36, 43, 35, 70, 37, 38, 44, 47, 48);
	  case "conflict":
	    $this->paperLink = "assign";
	    return array(102, 1, 11, self::FIELD_OPT_AUTHORS, self::FIELD_OPT_ABSTRACT, self::FIELD_AUTHOR_MATCH, self::FIELD_COLLABORATORS_MATCH, self::FIELD_FOLD);
	  case "editReviewPreference":
	    $this->paperLink = "paper";
	    return array(1, 11, 36, 27, 40, 70, 72, 37);
	  case "reviewers":
	    $this->paperLink = "assign";
	    return array(1, 11, 21, 38);
	  case "reviewersSel":
	    $this->paperLink = "assign";
	    return array(101, 1, 11, 21, 38);
	  case "revs":
	    $this->paperLink = "review";
	    return array(2, 12, 25, 27);
	  default:
	    return null;
	}
    }

    function text($listname, $me) {
	global $Conf, $ConfSiteBase, $paperListFields;

	$this->contact = $me;
	$this->count = 0;
	$this->needFinalize = 0;
	$this->needSubmitReview = 0;
	$this->anySelector = 0;
	$url = $this->search->url();

	// check role type
	$this->scoresOk = $me->privChair || ($me->isPC && $Conf->timePCViewAllReviews());

	// check what is folded
	if (defval($_SESSION, "foldplabstract", 1))
	    $this->foldedAbstract = array();
	else
	    $this->foldedAbstract = false;
	if (defval($_SESSION, "foldpltags", 1))
	    $this->foldedTags = array();
	else
	    $this->foldedTags = false;

	// get paper list
	if (!($baseFieldId = $this->listFields($listname))) {
	    $Conf->errorMsg("There is no paper list query named '" . htmlspecialchars($listname) . "'.");
	    return null;
	}

	// check paper link destination
	if (isset($_REQUEST["linkto"]) && ($_REQUEST["linkto"] == "paper" || $_REQUEST["linkto"] == "review" || $_REQUEST["linkto"] == "assign"))
	    $this->paperLink = $_REQUEST["linkto"];
	
	// initialize query
	$queryOptions = array("joins" => array());
	if ($this->search->complexSearch($queryOptions)) {
	    if (!$this->search->search())
		return NULL;
	    $queryOptions["joins"][] = "join Matches on (Paper.paperId=Matches.paperId)";
	}

	// paper list query?
	$pidarray = null;
	if ($this->sortField === null) {
	    $pidarray = $this->search->simplePaperList();
	    if ($pidarray !== null)
		$this->sortField = self::FIELD_PIDARRAY;
	    if ($this->search->orderTag) {
		$queryOptions['joins'][] = "left join PaperTag on (Paper.paperId=PaperTag.paperId and PaperTag.tag='" . sqlq($this->search->orderTag) . "')";
		$queryOptions['order'] = "order by PaperTag.tagIndex, Paper.paperId";
	    }
	} else if (!in_array($this->sortField, $baseFieldId))
	    $this->sortField = null;
	
	// get field array
	$fieldDef = array();
	$ncol = 0;
	$this->unfolded = true;
	foreach ($baseFieldId as $fid) {
	    if ($this->selector($fid, $queryOptions) === false)
		continue;
	    $normFid = self::_normalizeField($fid);
	    $fieldDef[$fid] = $paperListFields[$normFid];
	    if ($paperListFields[$normFid][1] == 1)
		$ncol++;
	}
	$unfolded = $this->unfolded;

	// prepare review query
	$reviewList = array();
	if (isset($queryOptions['reviewList'])) {
	    $result = $Conf->qe("select Paper.paperId, reviewId, reviewType,
		reviewSubmitted, reviewModified,
		PaperReview.contactId, lastName, firstName, email
		from Paper
		join PaperReview using (paperId)
		join ContactInfo on (PaperReview.contactId=ContactInfo.contactId)
		where " . ($this->search->limitName != 'a' ? "timeSubmitted>0" : "paperId=-1") . "
		order by lastName, firstName, email", "while fetching reviews");
	    while (($row = edb_orow($result)))
		$reviewList[$row->paperId][] = $row;
	}
	
	// prepare query text
	if ($me->contactId == $this->search->contactId)
	    $pq = $Conf->paperQuery($me, $queryOptions);
	else
	    $pq = $Conf->paperQuery($this->search->contactId, $queryOptions);

	// make query
	$result = $Conf->qe($pq, "while selecting papers");
	if (!$result)
	    return NULL;

	// fetch data
	if (edb_nrows($result) == 0)
	    return "No papers";
	$rows = array();
	while (($row = edb_orow($result)))
	    $rows[] = $row;

	// sort rows
	$srows = $this->_sort($rows, $pidarray);
	if ($this->reverseSort)
	    $srows = array_reverse($srows);
	if (isset($queryOptions["allReviewScores"]))
	    $this->_sortReviewOrdinal($srows);

	$ids = array();
	$anyData = array();
	$body = '';
	$authorinfo = $taginfo = $abstractinfo = false;
	foreach ($srows as $row) {
	    $ids[] = $row->paperId;
	    $trclass = "k" . ($this->count % 2);
	    $this->count++;
	    $this->unfolded = $unfolded;

	    $t = "  <tr class=\"pl $trclass\">\n";
	    foreach ($fieldDef as $fieldId => $fdef)
		if ($fdef[1] == 1) {
		    $c = $this->content($fieldId, $row, $reviewList);
		    $t .= "    <td class=\"pl_$fdef[0]\">$c</td>\n";
		    if ($c != "")
			$anyData[$fieldId] = 1;
		}
	    $t .= "  </tr>\n";
	    
	    foreach ($fieldDef as $fieldId => $fdef)
		if ($fdef[1] >= 2 && ($d = $this->content($fieldId, $row, $reviewList))) {
		    $t .= "  <tr class=\"pl_$fdef[0] pl_callout $trclass";
		    if ($fdef[1] == 3) {
			$authorinfo |= (paperBlind($row) ? 2 : 1);
			$t .= " extension" . (paperBlind($row) ? 2 : 1);
		    } else if ($fdef[1] == 4) {
			$taginfo = true;
			$t .= " extension4";
		    } else if ($fdef[1] == 5) {
			$abstractinfo = true;
			$t .= " extension5";
		    }
		    if ($fdef[1] == 4 || $fdef[1] == 5)
			$t .= "\" id=\"pl_$fdef[0]$row->paperId";
		    $t .= "\">\n";
		    $t .= "<td class=\"pl_$fdef[0] pl_callout\" colspan='$ncol'>";
		    $t .= "<span class='pl_callouthdr'>" . $this->header($fieldId, -1, $row, $reviewList) . ":</span>";
		    $t .= $d . "</td>\n  </tr>\n";
		}

	    if ($this->extraText && isset($this->extraText[$row->paperId]))
		$t .= "  <tr class=\"pl_extra $trclass\">\n"
		    . "<td class=\"pl_extra pl_callout\" colspan='$ncol'>"
		    . $this->extraText[$row->paperId]
		    . "</td>\n  </tr>\n";

	    if (!$this->unfolded)
		$t = str_replace("<tr class=\"", "<tr class=\"extension3 ", $t);

	    $body .= $t;
	}

	$x = "<table class=\"pltable plt_" . htmlspecialchars($listname);
	if ($authorinfo & 1)
	    $x .= (defval($_SESSION, "foldplau", 1) ? " fold1c" : " fold1o");
	if ($authorinfo & 2)
	    $x .= (defval($_SESSION, "foldplanonau", 1) ? " fold2c" : " fold2o");
	if (!$unfolded)
	    $x .= " fold3c";
	if ($taginfo)
	    $x .= ($this->foldedTags !== false ? " fold4c" : " fold4o");
	if ($taginfo && $this->foldedTags !== false && count($this->foldedTags))
	    $Conf->footerStuff .= "<form id='tagloadform' method='post' action='${ConfSiteBase}search.php?get=tags&amp;ajax=1'><div class='inform'><input type='hidden' name='pap' value='" . join(" ", $this->foldedTags) . "' /><input type='hidden' name='sitebase' value=\"" . htmlspecialchars($ConfSiteBase) . "\" /></div></form><script type='text/javascript'>ajaxTags = true;</script>";
	if ($abstractinfo)
	    $x .= ($this->foldedAbstract !== false ? " fold5c" : " fold5o");
	if ($abstractinfo && $this->foldedAbstract !== false && count($this->foldedAbstract))
	    $Conf->footerStuff .= "<form id='abstractloadform' method='post' action='${ConfSiteBase}search.php?get=abstracts&amp;ajax=1'><div class='inform'><input type='hidden' name='pap' value='" . join(" ", $this->foldedAbstract) . "' /></div></form><script type='text/javascript'>ajaxAbstracts = true;</script>";
	if ($authorinfo || !$unfolded || $taginfo || $abstractinfo)
	    $x .= "\" id=\"foldpl";
	$x .= "\">\n";

	$t .= "<img id='foldplactsession' alt='' src='${ConfSiteBase}sessionvar.php?var=foldplact&amp;val=" . defval($_SESSION, "foldplact", 1) . "&amp;cache=1' width='1' height='1' />";

	if ($this->showHeader) {
	    $x .= "  <tr class=\"pl_headrow\">\n";
	    $ord = 0;

	    $titleextra = "";
	    if (!$unfolded)
		$titleextra .= "<span class='sep'></span><a class='unfolder3' href='javascript:fold(\"pl\",0,3)'>Show all papers</a>";
	    if ($authorinfo && $this->showHeader == self::HEADER_ALL) {
		$titleextra .= "<span class='sep'></span>";
		if ($Conf->blindSubmission() == 0)
		    $titleextra .= "<a class='unfolder1' href='javascript:fold(\"pl\",0,1)'>Show authors</a><a class='folder1' href='javascript:fold(\"pl\",1,1)'>Hide authors</a>";
		else if ($this->contact->privChair && $authorinfo == 2)
		    $titleextra .= "<a class='unfolder2' href='javascript:fold(\"pl\",0,2)'>Show authors</a><a class='folder2' href='javascript:fold(\"pl\",1,2)'>Hide authors</a>";
		else if ($this->contact->privChair && $authorinfo > 1)
		    $titleextra .= "<a class='unfolder1' href='javascript:fold(\"pl\",0,1)'>Show non-anonymous authors</a><a class='extension1 unfolder2' href='javascript:fold(\"pl\",0,2)'>Show all authors</a><a class='folder2' href='javascript:fold(\"pl\",1,1);fold(\"pl\",1,2)'>Hide authors</a>";
		else
		    $titleextra .= "<a class='unfolder1' href='javascript:fold(\"pl\",0,1)'>Show non-anonymous authors</a><a class='folder1' href='javascript:fold(\"pl\",1,1)'>Hide authors</a>";
	    }
	    if ($taginfo && $this->showHeader == self::HEADER_ALL) {
		$titleextra .= "<span class='sep'></span>";
		$titleextra .= "<a class='unfolder4' href='javascript:foldtags(\"pl\",0,4)'>Show tags</a><a class='folder4' href='javascript:foldtags(\"pl\",1,4)'>Hide tags</a>";
		$titleextra .= "<img id='foldsession.pl4' alt='' src='${ConfSiteBase}sessionvar.php?var=foldpltags&amp;val=" . defval($_SESSION, "foldpltags", 1) . "&amp;cache=1' width='1' height='1' />";
	    }
	    
	    if ($this->sortable && $url) {
		$sortUrl = $ConfSiteBase . htmlspecialchars($url) . (strpos($url, "?") ? "&amp;" : "?") . "sort=";
		$q = "<a class='pl_sort' href=\"" . $sortUrl;
		foreach ($fieldDef as $fieldId => $fdef) {
		    if ($fdef[1] != 1)
			continue;
		    else if (!isset($anyData[$fieldId])) {
			$x .= "    <th class=\"pl_$fdef[0]\"></th>\n";
			continue;
		    }
		    $x .= "    <th class=\"pl_$fdef[0]\">";
		    $ftext = $this->header($fieldId, $ord++);
		    if ($this->sortField == null && $fieldId < 10
			&& $pidarray === null && !$this->search->orderTag)
			$this->sortField = $fieldId;
		    if ($fieldId == $this->sortField)
			$x .= "<a class='pl_sort_def" . ($this->reverseSort ? "_rev" : "") . "' href=\"" . $sortUrl . $fieldId . ($this->reverseSort ? "N" : "R") . "\">" . $ftext . "</a>";
		    else if ($fdef[2])
			$x .= $q . $fieldId . "\">" . $ftext . "</a>";
		    else
			$x .= $ftext;
		    if ($titleextra && $fdef[0] == "title") {
			$x .= $titleextra;
			$titleextra = false;
		    }
		    $x .= "</th>\n";
		}
		
	    } else {
		foreach ($fieldDef as $fieldId => $fdef)
		    if ($fdef[1] == 1 && isset($anyData[$fieldId]))
			$x .= "    <th class=\"pl_$fdef[0]\">"
			    . $this->header($fieldId, $ord++) . "</th>\n";
		    else if ($fdef[1] == 1)
			$x .= "    <th class=\"pl_$fdef[0]\"></th>\n";
	    }
	    
	    $x .= "  </tr>\n";
	    $x .= "  <tr><td class='pl_headgap' colspan='$ncol'></td></tr>\n";
	}

	$x .= $body;
	reset($fieldDef);
	if (key($fieldDef) == self::FIELD_SELECTOR)
	    $x .= $this->footer($ncol);
	$x .= "</table>";

	// session variable to remember the list
	if ($this->listNumber) {
	    if (isset($_REQUEST["sort"]))
		$url .= (strpos($url, "?") ? "&" : "?") . "sort=" . urlencode($_REQUEST["sort"]);
	    $ids["url"] = $url;
	    $ids["description"] = $this->search->description($listname);
	    $ids["timestamp"] = time();
	    if ($this->search->q || $this->search->qa || $this->search->qx)
		$ids["matchPreg"] = "/(" . $this->search->matchPreg . ")/i";
	    $ids["listid"] = $this->search->listId(isset($_REQUEST["sort"]) ? $_REQUEST["sort"] : "");
	    $_SESSION["l"][$this->listNumber] = $ids;
	}

	$this->headerInfo = array("authors" => $authorinfo, "tags" => $taginfo, "abstracts" => $abstractinfo);

	return $x;
    }

}


global $paperListFields;
$paperListFields = array(
	PaperList::FIELD_SELECTOR => array('selector', 1, 0),
	PaperList::FIELD_SELECTOR_ON => array('selector', 1, 0),
	PaperList::FIELD_SELECTOR_CONFLICT => array('selector', 1, 0),
	PaperList::FIELD_ID => array('id', 1, 1),
	PaperList::FIELD_REVIEWID => array('id', 1, 1),
	PaperList::FIELD_TITLE => array('title', 1, 1),
	PaperList::FIELD_REVIEWTITLE => array('title', 1, 1),
	PaperList::FIELD_STATUS => array('status', 1, 1),
	PaperList::FIELD_REVIEWER => array('reviewer', 1, 1),
	PaperList::FIELD_REVIEWER_TYPE => array('reviewer', 1, 1),
	PaperList::FIELD_REVIEWER_TYPE_ICON => array('reviewerTypeIcon', 1, 1),
	PaperList::FIELD_STATUS_SHORT => array('status', 1, 1),
	PaperList::FIELD_ASSIGN_REVIEW => array('reviewAssignment', 1, 1),
	PaperList::FIELD_TOPIC_INTEREST => array('topicMatch', 1, 1),
	PaperList::FIELD_TOPIC_NAMES => array('topicNames', 2, 0),
	PaperList::FIELD_ALL_REVIEWER_NAMES => array('reviewerNames', 2, 0),
	PaperList::FIELD_REVIEWER_PREFERENCE => array('reviewPreference', 1, 1),
	PaperList::FIELD_EDIT_REVIEWER_PREFERENCE => array('editReviewPreference', 1, 1),
	PaperList::FIELD_REVIEWS_STATUS => array('reviewsStatus', 1, 1),
	PaperList::FIELD_DESIRABILITY => array('desirability', 1, 1),
	PaperList::FIELD_ALL_PREFERENCES => array('allPreferences', 2, 0),
	PaperList::FIELD_SCORE => array('score', 1, 1),
	PaperList::FIELD_AUTHOR_MATCH => array('authorsMatch', 2, 0),
	PaperList::FIELD_COLLABORATORS_MATCH => array('collaboratorsMatch', 2, 0),
	PaperList::FIELD_OPT_AUTHORS => array('authors', 3, 0),
	PaperList::FIELD_TAGS => array('tags', 4, 0),
	PaperList::FIELD_OPT_ABSTRACT => array('abstract', 5, 0)
	);
