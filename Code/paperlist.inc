<?php
// paperlist.inc -- HotCRP helper class for producing paper lists
// HotCRP is Copyright (c) 2006-2008 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

require_once("baselist.inc");

class PaperList extends BaseList {

    const FIELD_SELECTOR = 100;
    const FIELD_SELECTOR_ON = 101;
    const FIELD_SELECTOR_CONFLICT = 102;
    
    const FIELD_ID = 1;
    const FIELD_TAGINDEX = 8;
    const FIELD_PIDARRAY = 9;
    
    const FIELD_TITLE = 11;
    
    const FIELD_STATUS = 20;
    const FIELD_STATUS_SHORT = 21;
    const FIELD_REVIEWER = 25;
    const FIELD_REVIEWER_TYPE = 26;
    const FIELD_REVIEWER_TYPE_ICON = 27;
    const FIELD_REVIEWER_STATUS = 28;
    const FIELD_REVIEWER_MONITOR = 29;
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
    const FIELD_CONFLICT_CONFIRM = 49;

    const FIELD_OPT_AUTHORS = 70;
    const FIELD_TAGS = 71;
    const FIELD_OPT_ABSTRACT = 72;
    const FIELD_OPT_ALL_REVIEWER_NAMES = 73;
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
    var $specialSortable;
    var $count;
    var $needFinalize;
    var $needSubmitReview;
    var $anySelector;
    var $anyFinalPaper;
    var $listNumber;
    var $contact;
    var $scoresOk;
    var $scoreMax;
    var $search;
    var $authorMatch;
    var $collaboratorsMatch;
    var $extraText;
    var $foldtype;
    var $unfolded;
    var $foldedAbstract;
    var $foldedTags;
    var $foldedReviewers;
    var $paperLink;
    var $paperLinkArgs;
    var $showConflict;
    var $headerInfo;
    var $extraFooter;
    var $papersel;
    var $rf;
    
    function PaperList($sortable = true, $list = false, $search = null, $extraText = null) {
	global $paperListFields, $scoreSorts;
	$this->showHeader = self::HEADER_ALL;

	$s = ($sortable ? defval($_REQUEST, "sort", "") : "");
	$this->reverseSort = false;
	while (strlen($s)) {
	    $x = strtoupper($s[strlen($s) - 1]);
	    if ($x == "R")
		$this->reverseSort = true;
	    else if ($x == "N")
		$this->reverseSort = false;
	    else if (isset($scoreSorts[$x]))
		$_SESSION["scoresort"] = $x;
	    else
		break;
	    $s = substr($s, 0, strlen($s) - 1);
	}
	if (("x" . (int) $s) == ("x" . $s))
	    $this->sortField = (int) $s;
	else
	    $this->sortField = null;
	$this->sortable = $sortable;
	$this->specialSortable = null;

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
	$this->extraFooter = "";
	$this->foldtype = "foldpl";
	$this->rf = reviewForm();
    }

    function selector($fieldId, &$queryOptions) {
	global $Conf, $reviewScoreNames;
	switch (self::_normalizeField($fieldId)) {
	case self::FIELD_TOPIC_INTEREST:
	    if (!count($this->rf->topicName))
		return false;
	    $queryOptions['topicInterestScore'] = 1;
	    break;
	case self::FIELD_TOPIC_NAMES:
	    $queryOptions['topics'] = 1;
	    break;
	case self::FIELD_ALL_REVIEWER_NAMES:
	    $queryOptions['reviewList'] = 1;
	    break;
	case self::FIELD_OPT_ALL_REVIEWER_NAMES:
	    if (!$this->contact->privChair)
		return false;
	    if ($this->foldedReviewers === false)
		$queryOptions["reviewList"] = 1;
	    break;
	case self::FIELD_REVIEWER_PREFERENCE:
	case self::FIELD_EDIT_REVIEWER_PREFERENCE:
	    $queryOptions['reviewerPreference'] = 1;
	    $Conf->footerStuff .= "<script type='text/javascript'>addRevprefAjax();</script>";
	    break;
	case self::FIELD_ASSIGN_REVIEW:
	    $Conf->footerStuff .= "<script type='text/javascript'>addAssrevAjax();</script>";
	    break;
	case self::FIELD_SELECTOR_CONFLICT:
	    $Conf->footerStuff .= "<script type='text/javascript'>addConflictAjax();</script>";
	    break;
	case self::FIELD_DESIRABILITY:
	    $queryOptions['desirability'] = 1;
	    break;
	case self::FIELD_ALL_PREFERENCES:
	    $queryOptions['allReviewerPreference'] = $queryOptions['topics']
		= $queryOptions['allConflictType'] = 1;
	    $ord = 0;
	    $pcm = pcMembers();
	    foreach ($pcm as $pc) {
		$pc->prefOrdinal = sprintf("-0.%04d", $ord++);
		$pc->topicInterest = array();
	    }
	    $result = $Conf->qe("select contactId, topicId, interest from TopicInterest", "while finding topic interests");
	    while (($row = edb_row($result)))
		$pcm[$row[0]]->topicInterest[$row[1]] = $row[2];
	    break;
	case self::FIELD_SCORE:
	    if (!$this->scoresOk)
		return false;
	    $score = $reviewScoreNames[$fieldId - self::FIELD_SCORE];
	    $revViewScore = $this->contact->viewReviewFieldsScore(null, true, $Conf);
	    if ($this->rf->authorView[$score] <= $revViewScore)
		return false;
	    if (!isset($queryOptions['scores']))
		$queryOptions['scores'] = array();
	    $queryOptions['scores'][] = $score;
	    $this->scoreMax[$score] = $this->rf->maxNumericScore($score);
	    break;
	case self::FIELD_FOLD:
	    $this->unfolded = false;
	    break;
	case self::FIELD_REVIEWER:
	case self::FIELD_REVIEWER_MONITOR:
	    $queryOptions['allReviewScores'] = 1;
	    $queryOptions['reviewerName'] = 1;
	    break;
	case self::FIELD_REVIEWER_TYPE:
	case self::FIELD_REVIEWER_TYPE_ICON:
	    // if search names a specific reviewer, link there
	    if ($this->search->reviewerContact
		&& !isset($queryOptions['allReviewScores'])) {
		$queryOptions['reviewerContact'] = $this->search->reviewerContact;
		if ($this->search->reviewerContact != $this->contact->contactId)
		    $this->showConflict = false;
	    }
	    break;
	case self::FIELD_TAGS:
	    if (!$this->contact->isPC)
		return false;
	    if ($this->foldedTags === false) {
		require_once("tags.inc");
		$queryOptions['tags'] = 1;
	    }
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
	global $Conf;
	$aok = ($a->conflictType == 0 || ($a->conflictType >= CONFLICT_AUTHOR && $Conf->timeAuthorViewReviews()) || $this->contact->privChair);
	$bok = ($b->conflictType == 0 || ($b->conflictType >= CONFLICT_AUTHOR && $Conf->timeAuthorViewReviews()) || $this->contact->privChair);
	$av = ($aok ? $a->reviewCount : 2147483647);
	$bv = ($bok ? $b->reviewCount : 2147483647);
	if ($av == $bv) {
	    $av = ($aok ? $a->startedReviewCount : 2147483647);
	    $bv = ($bok ? $b->startedReviewCount : 2147483647);
	    if ($av == $bv) {
		$av = $a->paperId;
		$bv = $b->paperId;
	    }
	}
	return ($av < $bv ? -1 : ($av == $bv ? 0 : 1));
    }

    function _sortTopicInterest($a, $b) {
	$x = $b->topicInterestScore - $a->topicInterestScore;
	return $x ? $x : $a->paperId - $b->paperId;
    }
    
    function _sortReviewerPreference($a, $b) {
	$x = $b->reviewerPreference - $a->reviewerPreference;
	$x = $x ? $x : defval($b, "topicInterestScore", 0) - defval($a, "topicInterestScore", 0);
	return $x ? $x : $a->paperId - $b->paperId;
    }
    
    function _sortDesirability($a, $b) {
	$x = $b->desirability - $a->desirability;
	return $x ? $x : $a->paperId - $b->paperId;
    }

    function _sortTagIndex($a, $b) {
	if (($a->conflictType > 0 && $this->sortInfo) || $a->tagIndex === null)
	    $av = 2147483647;
	else
	    $av = $a->tagIndex;
	if (($b->conflictType > 0 && $this->sortInfo) || $b->tagIndex === null)
	    $bv = 2147483647;
	else
	    $bv = $b->tagIndex;
	$x = ($av < $bv ? -1 : ($av == $bv ? 0 : 1));
	return $x ? $x : $a->paperId - $b->paperId;
    }

    function _sort($rows, $pidarray) {
	global $Conf, $reviewScoreNames, $defaultScoreSort;
	switch (self::_normalizeField($this->sortField)) {
	case self::FIELD_ID:
	    ksort($rows);
	    break;
	case self::FIELD_PIDARRAY:
	    $this->sortInfo = array_flip($pidarray);
	    usort($rows, array($this, "_sortPidarray"));
	    break;
	case self::FIELD_TAGINDEX:
	    // $this->sortInfo == careful about conflicts?
	    $this->sortInfo = !$this->contact->privChair && $Conf->setting("tag_seeall") <= 0;
	    usort($rows, array($this, "_sortTagIndex"));
	    break;
	case self::FIELD_TITLE:
	    usort($rows, array("PaperList", "_sortTitle"));
	    break;
	case self::FIELD_STATUS:
	case self::FIELD_STATUS_SHORT:
	    usort($rows, array("PaperList", "_sortStatus"));
	    break;
	case self::FIELD_REVIEWER:
	case self::FIELD_REVIEWER_MONITOR:
	    usort($rows, array("PaperList", "_sortReviewer"));
	    break;
	case self::FIELD_REVIEWER_TYPE:
	case self::FIELD_REVIEWER_TYPE_ICON:
	case self::FIELD_ASSIGN_REVIEW:
	    usort($rows, array("PaperList", "_sortReviewType"));
	    break;
	case self::FIELD_REVIEWS_STATUS:
	    usort($rows, array($this, "_sortReviewsStatus"));
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
	    $scoresort = defval($_SESSION, "scoresort", $defaultScoreSort);
	    foreach ($rows as $row)
		if (!$this->contact->privChair && !$this->contact->canViewReview($row, null, $Conf))
		    $this->sortInfo[$row->paperId] = self::LOTSAZEROS;
		else
		    $this->_setSortRow($row, $scoreName, $scoreMax, $scoresort);
	    usort($rows, array($this, ($scoresort == "M" || $scoresort == "Y" ? "_sortScore" : "_sortScoreNum")));
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
	    return "ID";
	case self::FIELD_TITLE:
	    return "Title";
	case self::FIELD_STATUS:
	case self::FIELD_STATUS_SHORT:
	    return "Status";
	case self::FIELD_REVIEWER:
	case self::FIELD_REVIEWER_MONITOR:
	    return "Reviewer";
	case self::FIELD_REVIEWER_TYPE:
	case self::FIELD_REVIEWER_TYPE_ICON:
	    return "<span class='hastitle' title='Reviewer type'>Review</span>";
	case self::FIELD_REVIEWER_STATUS:
	    return "Review status";
	case self::FIELD_REVIEWS_STATUS:
	    return "<span class='hastitle' title='\"1/2\" means 1 complete review out of 2 assigned reviews'>#&nbsp;Reviews</span>";
	case self::FIELD_SELECTOR:
	case self::FIELD_SELECTOR_ON:
	    if ($this->specialSortable == self::FIELD_TAGINDEX)
		return "<span class='hastitle' title='Sort by tag order'>#</span>";
	    else if ($this->specialSortable == self::FIELD_PIDARRAY)
		return "<span class='hastitle' title='Sort by search term order'>#</span>";
	    else
		return ($ordinal ? "&nbsp;" : "");
	case self::FIELD_SELECTOR_CONFLICT:
	    return "Conflict?";
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
	case self::FIELD_OPT_ALL_REVIEWER_NAMES:
	    return "Reviewers";
	case self::FIELD_REVIEWER_PREFERENCE:
	case self::FIELD_EDIT_REVIEWER_PREFERENCE:
	    return "Preference";
	case self::FIELD_DESIRABILITY:
	    return "Desirability";
	case self::FIELD_ALL_PREFERENCES:
	    return "Preferences";
	case self::FIELD_SCORE: {
	    $scoreName = $reviewScoreNames[$fieldId - self::FIELD_SCORE];
	    return $this->rf->webFieldAbbrev($scoreName);
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
	case self::FIELD_CONFLICT_CONFIRM:
	    return "";
	default:
	    return "&lt;$fieldId&gt;?";
	}
    }

    function _contentDownload($row) {
	global $Conf;
	$finalsuffix = "";
	$finaltitle = null;
	if (($final = $row->finalPaperStorageId != 0)) {
	    $this->anyFinalPaper = true;
	    $finalsuffix = "f";
	    $finaltitle = "Final copy";
	}
	$finalsuffix = ($final ? "f" : "");
	if ($row->size == 0 || !$this->contact->canDownloadPaper($row, $Conf))
	    return "";
	else if ($row->mimetype == "application/pdf")
	    return "&nbsp;<a href=\"" . $Conf->makeDownloadPath($row->paperId, $row->mimetype, $final) . "\">" . $Conf->cacheableImage("pdf$finalsuffix.png", "[PDF]", $finaltitle) . "</a>";
	else if ($row->mimetype == "application/postscript")
	    return "&nbsp;<a href=\"" . $Conf->makeDownloadPath($row->paperId, $row->mimetype, $final) . "\">" . $Conf->cacheableImage("postscript$finalsuffix.png", "[PS]", $finaltitle) . "</a>";
	else
	    return "&nbsp;<small><a href=\"" . $Conf->makeDownloadPath($row->paperId, $row->mimetype, $final) . "\">[Download]</a></small>";
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
	global $Conf, $ConfSiteSuffix;
	if (!($pl = $this->paperLink))
	    $pl = "paper";
	$pl .= $ConfSiteSuffix . "?p=" . $row->paperId;
	$doreview = isset($row->reviewId) && ($this->search->reviewerContact || isset($row->reviewFirstName));
	if ($doreview) {
	    $rord = unparseReviewOrdinal($row);
	    $pl .= "&amp;r=" . $rord;
	    if ($row->reviewSubmitted > 0)
		$pl .= "&amp;m=r";
	}
	$pl .= $this->paperLinkArgs;
	if ($doreview && $row->reviewSubmitted > 0)
	    $pl .= "#review" . $rord;
	return $pl;
    }
    
    function content($fieldId, $row, $reviewList) {
	global $ConfSiteSuffix, $Conf, $reviewTypeName, $reviewScoreNames;
	switch (self::_normalizeField($fieldId)) {
	case self::FIELD_ID:
	    $pl = $this->_paperLink($row);
	    return "<a href='$pl' tabindex='4'>#$row->paperId</a>";
	case self::FIELD_TITLE:
	    $pl = $this->_paperLink($row);
	    return "<a href='$pl' tabindex='5'>" . htmlspecialchars($row->title) . "</a>" . $this->_contentDownload($row);
	case self::FIELD_STATUS:
	case self::FIELD_STATUS_SHORT:
	    if ($row->timeSubmitted <= 0 && $row->timeWithdrawn <= 0)
		$this->needFinalize++;
	    $long = 0;
	    if ($this->search->limitName != "a" && $this->contact->privChair)
		$long = 2;
	    if ($fieldId == self::FIELD_STATUS_SHORT)
		$long = ($long == 2 ? -2 : -1);
	    return $this->contact->paperStatus($row->paperId, $row, $long);
	case self::FIELD_REVIEWER:
	    return contactHtml($row->reviewFirstName, $row->reviewLastName, $row->reviewEmail);
	case self::FIELD_REVIEWER_MONITOR:
	    $t = contactHtml($row->reviewFirstName, $row->reviewLastName, $row->reviewEmail) . "<br /><small>Last login: ";
	    return $t . ($row->reviewLastLogin ? $Conf->printableTimeShort($row->reviewLastLogin) : "Never") . "</small>";
	case self::FIELD_REVIEWER_TYPE:
	    if ($row->reviewType > 0 && (!isset($row->reviewSubmitted) || !$row->reviewSubmitted))
		$this->needSubmitReview++;
	    if ($row->reviewId) {
		$a1 = "<a href=\"review$ConfSiteSuffix?r=" . unparseReviewOrdinal($row) . $this->paperLinkArgs . "\">";
		$a2 = "</a>&nbsp;";
		if ($row->reviewSubmitted)
		    $a2 .= "<span class='rstat'>(Complete)</span>";
		else if ($row->reviewType == REVIEW_SECONDARY && $row->reviewNeedsSubmit <= 0)
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
	case self::FIELD_REVIEWER_STATUS:
	    if ($row->reviewType > 0 && (!isset($row->reviewSubmitted) || !$row->reviewSubmitted))
		$this->needSubmitReview++;
	    if (!$row->reviewId)
		return "";
	    else {
		if ($row->reviewSubmitted)
		    return "<a href=\"review$ConfSiteSuffix?r=" . unparseReviewOrdinal($row) . $this->paperLinkArgs . "\">Complete</a>";
		else if ($row->reviewType == REVIEW_SECONDARY && $row->reviewNeedsSubmit <= 0)
		    return "Delegated";
		else if ($row->reviewModified == 0)
		    return "<strong class='overdue'>Not&nbsp;started</strong>";
		else
		    return "<strong class='overdue'>In&nbsp;progress</strong>";
	    }
	case self::FIELD_REVIEWER_TYPE_ICON:
	    $needSubmit = ($row->reviewType > 0 && (!isset($row->reviewSubmitted) || !$row->reviewSubmitted));
	    if ($needSubmit)
		$this->needSubmitReview++;
	    $a1 = $a2 = $a3 = "";
	    if ($row->reviewId) {
		$a1 = "<a href=\"review$ConfSiteSuffix?r=" . unparseReviewOrdinal($row) . $this->paperLinkArgs . "\">";
		$a2 = "</a>";
	    }
	    if ($row->conflictType > 0 && $this->showConflict) {
		$which = "-1.png";
		$alt = "Conflict";
	    } else if ($row->reviewType === null) {
		$which = "0.gif";
		$alt = "";
	    } else {
		$which = $row->reviewType;
		$alt = $reviewTypeName[$row->reviewType] . " review";
		if ($needSubmit) {
		    $which .= "n";
		    $alt .= " (not complete)";
		}
		$which .= ".gif";
		if (!$row->reviewRound)
		    /* nada */;
		else if (($t = defval($Conf->settings["rounds"], $row->reviewRound)))
		    $a3 = htmlspecialchars($t);
		else
		    $a3 = "?$row->reviewRound";
		if ($a3)
		    $a3 = "&nbsp;<span class='revround' title='Review round'>" . htmlspecialchars($a3) . "</span>";
	    }
	    return $a1 . $Conf->cacheableImage("ass$which", $alt, $alt) . $a2 . $a3;
	case self::FIELD_REVIEWS_STATUS:
	    // see also _sortReviewsStatus
	    if ($row->conflictType > 0 && !$this->contact->privChair
		&& ($row->conflictType < CONFLICT_AUTHOR
		    || !$Conf->timeAuthorViewReviews()))
		return "";
	    else if ($row->reviewCount != $row->startedReviewCount)
		return "<b>$row->reviewCount</b>/$row->startedReviewCount";
	    else
		return "<b>$row->reviewCount</b>";
	case self::FIELD_SELECTOR:
	case self::FIELD_SELECTOR_ON:
	case self::FIELD_SELECTOR_CONFLICT:
	    $this->anySelector = true;
	    $c = "";
	    if (($fieldId == self::FIELD_SELECTOR_ON
		 || ($fieldId == self::FIELD_SELECTOR_CONFLICT && $row->conflictType > 0))
		&& (!$this->papersel || defval($this->papersel, $row->paperId, 1))) {
		$c .= " checked='checked'";
		$this->unfolded = true;
	    }
	    if ($fieldId == self::FIELD_SELECTOR_CONFLICT && $row->conflictType >= CONFLICT_AUTHOR)
		$c .= " disabled='disabled'";
	    if ($fieldId != self::FIELD_SELECTOR_CONFLICT)
		$c .= " onclick='pselClick(event, this, $this->count)'";
	    return "<span class='pl_rownum extension6'>" . $this->count . ". </span>" . "<input type='checkbox' name='pap[]' value='$row->paperId' tabindex='3' id='psel$this->count' $c/>";
	case self::FIELD_CONFLICT_CONFIRM:
	    return "<span id='assrev" . $row->paperId . "ok'></span>";
	case self::FIELD_ASSIGN_REVIEW:
	    if ($row->conflictType >= CONFLICT_AUTHOR)
		return "<span class='author'>Author</span>";
	    $rt = ($row->conflictType > 0 ? -1 : min(max($row->reviewType, 0), REVIEW_PRIMARY));
	    return tagg_select("assrev$row->paperId",
			       array(0 => "None", REVIEW_PRIMARY => "Primary",
				     REVIEW_SECONDARY => "Secondary",
				     -1 => "Conflict"), $rt,
			       array("tabindex" => 3, "onchange" => "hiliter(this)"))
		. "<span id='assrev" . $row->paperId . "ok'></span>";
	case self::FIELD_TOPIC_INTEREST:
	    return htmlspecialchars($row->topicInterestScore + 0);
	case self::FIELD_TOPIC_NAMES:
	    return join(", ", $this->rf->webTopicArray($row->topicIds, defval($row, "topicInterest")));
	case self::FIELD_OPT_ALL_REVIEWER_NAMES:
	    if ($this->foldedReviewers !== false) {
		$this->foldedReviewers[] = $row->paperId;
		return "<span id='reviewers$row->paperId'><em>Loading</em></span>";
	    }
	    /* fallthrough */
	case self::FIELD_ALL_REVIEWER_NAMES:
	    $n = "";
	    // see also search.php > getaction == "reviewers"
	    if (isset($reviewList[$row->paperId]))
		foreach ($reviewList[$row->paperId] as $xrow)
		    if ($xrow->lastName) {
			$n .= ($n ? ", " : "");
			$n .= contactHtml($xrow->firstName, $xrow->lastName);
			if ($xrow->reviewType == REVIEW_PRIMARY)
			    $n .= "&nbsp;" . $Conf->cacheableImage("ass" . REVIEW_PRIMARY . ".gif", "Primary");
			else if ($xrow->reviewType == REVIEW_SECONDARY)
			    $n .= "&nbsp;" . $Conf->cacheableImage("ass" . REVIEW_SECONDARY . ".gif", "Secondary");
		    }
	    return $n;
	case self::FIELD_REVIEWER_PREFERENCE:
	    return (isset($row->reviewerPreference) ? htmlspecialchars($row->reviewerPreference) : "0");
	case self::FIELD_EDIT_REVIEWER_PREFERENCE:
	    if ($row->conflictType > 0)
		return "N/A";
	    $x = (isset($row->reviewerPreference) ? htmlspecialchars($row->reviewerPreference) : "0");
	    return "<input class='textlite' type='text' size='4' name='revpref$row->paperId' value=\"$x\" tabindex='2' /><span id='revpref" . $row->paperId . "ok'></span>";
	case self::FIELD_DESIRABILITY:
	    return (isset($row->desirability) ? htmlspecialchars($row->desirability) : "0");
	case self::FIELD_ALL_PREFERENCES:
	    $x = array();
	    foreach (explode(',', $row->allReviewerPreference) as $rp) {
		$what = explode(' ', $rp);
		if (count($what) == 2 && $what[1])
		    $x[$what[0]] = $what[1];
	    }
	    foreach (explode(',', $row->allConflictType) as $rp) {
		$what = explode(' ', $rp);
		if (count($what) == 2 && $what[1])
		    $x[$what[0]] = "X";
	    }
	    $text = "";
	    $topicids = split(" ", $row->topicIds);
	    foreach (pcMembers() as $pc) {
		$pref = defval($x, $pc->contactId, 0);
		if ($pref === "X")
		    continue;
		$tis = 0;
		foreach ($topicids as $t)
		    if (($i = defval($pc->topicInterest, $t, 1)) == 2)
			$tis += 2;
		    else
			$tis += $i - 1;
		if ($tis || $pref)
		    $text .= ($text == "" ? "" : ", ")
			. contactHtml($pc->firstName, $pc->lastName)
			. preferenceSpan($pref, $tis);
	    }
	    return $text;
	case self::FIELD_SCORE:
	    if ($this->contact->privChair || $this->contact->canViewReview($row, null, $Conf)) {
		$scoreName = $reviewScoreNames[$fieldId - self::FIELD_SCORE];
		$itemName = "${scoreName}Scores";
		if ($row->$itemName)
		    return $Conf->textValuesGraph($row->$itemName, $this->scoreMax[$scoreName], 1, defval($row, $scoreName), $this->rf->reviewFields[$scoreName]);
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
		$aus[] = abbreviateName($au[0], $au[1]);
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
		if (($t = $row->paperTags) !== "")
		    if (($t = tagsToText($row, true, $this->contact, false,
					 $this->search->orderTag)) !== "")
			$t = "<span id='tags$row->paperId'>" . $t . "</span>";
		return $t;
	    } else {
		$this->foldedTags[] = $row->paperId;
		return "<span id='tags$row->paperId'><em>Loading</em></span>";
	    }
	default:
	    return "";
	}
    }

    function footer($ncol, $listname) {
	global $Conf, $ConfSiteSuffix;
	if ($this->count == 0)
	    return "";

	$barsep = " <span class='barsep'>&nbsp;|&nbsp;</span> ";
	$nlll = 1;
	$revpref = ($listname == "editReviewPreference");
	$whichlll = 1;
	
	// Download
	$t = "<span class='lll$nlll'><a href='javascript:void crpfocus(\"plact\",$nlll)'>Download</a></span><span class='lld$nlll'><b>:</b> &nbsp;";
	$sel_opt = array();
	if ($revpref) {
	    $sel_opt["revpref"] = "Preference file";
	    $sel_opt["revprefx"] = "Preference file with abstracts";
	    $sel_opt["abstracts"] = "Abstracts";
	} else {
	    if ($this->contact->isPC && $this->anyFinalPaper) {
		$sel_opt["final"] = "Final papers";
		$sel_opt["paper"] = "Submitted papers";
	    } else
		$sel_opt["paper"] = "Papers";
	    $sel_opt["abstracts"] = "Abstracts";
	    $sel_opt["revform"] = "Review forms";
	    $sel_opt["revformz"] = "Review forms (zip)";
	    if ($this->contact->isPC || $this->contact->isReviewer) {
		$sel_opt["rev"] = "All reviews";
		$sel_opt["revz"] = "All reviews (zip)";
	    }
	}
	if ($this->contact->privChair || ($this->contact->isReviewer && $Conf->blindSubmission() < BLIND_ALWAYS))
	    $sel_opt["authors"] = "Authors";
	if ($this->contact->privChair) {
	    $sel_opt["contact"] = "Contact emails";
	    $sel_opt["pcconflicts"] = "PC conflicts";
	    $sel_opt["checkformat"] = "Format check";
	}
	if ($this->contact->isPC)
	    $sel_opt["topics"] = "Topics";
	if (!$revpref && ($this->contact->privChair || ($this->contact->isPC && $Conf->timePCViewAllReviews())))
	    $sel_opt["scores"] = "Scores";
	if ($Conf->setting("paperlead") > 0) {
	    $sel_opt["lead"] = "Discussion leads";
	    $sel_opt["shepherd"] = "Shepherds";
	}
	$t .= tagg_select("getaction", $sel_opt, key($sel_opt),
			  array("id" => "plact${nlll}_d", "tabindex" => 6,
				"class" => "bsm"))
	    . "&nbsp; <input type='submit' class='bsm' name='getgo' value='Go' tabindex='6' /></span>";
	$nlll++;

	// Upload preferences (review preferences only)
	if ($revpref) {
	    if (isset($_REQUEST["upload"]))
		$whichlll = $nlll;
	    $t .= $barsep;
	    $t .= "<span class='lll$nlll'><a href='javascript:void crpfocus(\"plact\",$nlll)'>Upload</a></span><span class='lld$nlll'><b> preference file:</b> &nbsp;";
	    $t .= "<input id='plact${nlll}_d' type='file' name='uploadedFile' accept='text/plain' size='20' tabindex='6' onfocus='defact(\"upload\")' />&nbsp; <input type='submit' class='bsm' name='upload' value='Go' tabindex='6' /></span>";
	    $nlll++;
	}

	// Set preferences (review preferences only)
	if ($revpref) {
	    if (isset($_REQUEST["setpaprevpref"]))
		$whichlll = $nlll;
	    $t .= $barsep;
	    $t .= "<span class='lll$nlll'><a href='javascript:void crpfocus(\"plact\",$nlll)'>Set preferences</a></span><span class='lld$nlll'><b>:</b> &nbsp;";
	    $t .= "<input id='plact${nlll}_d' class='textlite' type='text' name='paprevpref' value='' size='4' tabindex='6' onfocus='defact(\"setpaprevpref\")' /> &nbsp;<input type='submit' class='bsm' name='setpaprevpref' value='Go' tabindex='6' /></span>";
	    $nlll++;
	}
	
	// Tags (search+PC only)
	if ($this->contact->isPC && !$revpref) {
	    if (isset($_REQUEST["tagact"]))
		$whichlll = $nlll;
	    $t .= $barsep;
	    $t .= "<span class='lll$nlll'><a href='javascript:void crpfocus(\"plact\",$nlll)'>Tag</a></span><span class='lld$nlll'><b>:</b><a class='help' href='help$ConfSiteSuffix?t=tags' title='Learn more'>?</a> &nbsp;";
	    $tagopt = array("a" => "Add", "d" => "Remove", "s" => "Define", "xxxa" => null, "ao" => "Add ordered", "aos" => "Add sequential", "so" => "Define ordered", "sos" => "Define sequential");
	    if ($this->contact->privChair) {
		$tagopt["xxxb"] = null;
		$tagopt["da"] = "Clear twiddle";
		$tagopt["cr"] = "Calculate rank";
	    }
	    $t .= tagg_select("tagtype", $tagopt, null, array("class" => "bsm"))
		. " &nbsp;tag(s) &nbsp;<input id='plact${nlll}_d' class='textlite' type='text' name='tag' value='' size='15' onfocus='defact(\"tagact\")' /> &nbsp;<input type='submit' class='bsm' name='tagact' value='Go' /></span>";
	    $nlll++;
	}

	// Assignments (search+admin only)
	if ($this->contact->privChair && !$revpref) {
	    if (isset($_REQUEST["setassign"]))
		$whichlll = $nlll;
	    $t .= $barsep;
	    $t .= "<span class='lll$nlll'><a href='javascript:void crpfocus(\"plact\",$nlll)'>Assign</a></span><span id='foldass' class='lld$nlll foldc'><b>:</b> &nbsp;";
	    $t .= tagg_select("marktype",
			      array("xauto" => "Automatic assignments",
				    "zzz1" => null,
				    "conflict" => "Mark conflict",
				    "unconflict" => "Remove conflict",
				    "zzz3" => null,
				    "assign" . REVIEW_PRIMARY => "Assign primary review",
				    "assign" . REVIEW_SECONDARY => "Assign secondary review",
				    "assign0" => "Unassign reviews"),
			      null, array("id" => "plact${nlll}_d",
					  "onchange" => "fold(\"ass\", (this.value[0] == \"x\"))",
					  "class" => "bsm"))
		. "<span class='extension'> &nbsp;for &nbsp;";
	    // <option value='-2' disabled='disabled'></option>
	    // <option value='xpcpaper'>Mark as PC-authored</option>
	    // <option value='xunpcpaper'>Mark as not PC-authored</option>
	    $pc = pcMembers();
	    $sel_opt = array();
	    foreach ($pc as $id => $row)
		$sel_opt[htmlspecialchars($row->email)] = contactHtml($row->firstName, $row->lastName);
	    $t .= tagg_select("markpc", $sel_opt, null, array("id" => "markpc",
							      "class" => "bsm"))
		. "</span> &nbsp;<input type='submit' class='bsm' name='setassign' value='Go' />";
	    $t .= "</span>";
	    $nlll++;
	}

	// Decide, Mail (search+admin only)
	if ($this->contact->privChair && !$revpref) {
	    if (isset($_REQUEST["sendmail"]))
		$whichlll = $nlll;
	    $t .= $barsep;
	    $t .= "<span class='lll$nlll'><a href='javascript:void crpfocus(\"plact\",$nlll)'>Decide</a></span><span class='lld$nlll'><b>:</b> Set to &nbsp;";
	    $t .= decisionSelector(0, "plact${nlll}_d", " class='bsm'") . " &nbsp;<input type='submit' class='bsm' name='setdecision' value='Go' /></span>";
	    $nlll++;

	    $t .= $barsep
		. "<span class='lll$nlll'><a href='javascript:void crpfocus(\"plact\",$nlll)'>Mail</a></span><span class='lld$nlll'><b>:</b> &nbsp;"
		. tagg_select("recipients", array("au" => "Contact authors", "rev" => "Reviewers"), null, array("id" => "plact${nlll}_d", "class" => "bsm"))
		. " &nbsp;<input type='submit' class='bsm' name='sendmail' value='Go' /></span>";
	    $nlll++;
	}

	// Linelinks container
	return "  <tr class='pl_footgap k" . (($this->count - 1) % 2) . "'><td class='pl_blank' colspan='$ncol'></td></tr>\n"
	    . "  <tr class='pl_footrow'>\n    <td class='pl_selector' style='vertical-align: top; padding-right: 4px;'>" . $Conf->cacheableImage("bendulft.png", "^^", null, "placthook") . "</td>\n"
	    . "    <td colspan='" . ($ncol - 1) . "'>"
	    . "<div id='plact' class='linelinks$whichlll'><b>Select papers</b> (or <a href='javascript:void papersel(true)'>select all " . $this->count . "</a>), then "
	    . $t . "</div>" . $this->extraFooter . "</td></tr>\n";
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
	    return "Your submissions";
	  case "s":
	    return "Submitted papers";
	  case "acc":
	    return "Accepted papers";
	  case "all":
	    return "All papers";
	  case "act":
	    return "Active papers";
	  case "reviewerHome":
	  case "r":
	    return "Your reviews";
	  case "rout":
	    return "Your incomplete reviews";
	  case "req":
	  case "reqrevs":
	    return "Your review requests";
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
	    return $this->addScores(array(100, 1, 11, 27, 41, 21, 70, 72, 71, 73));
	  case "all":
	  case "act":
	    return array(100, 1, 11, 20, 27, 70, 72, 71, 73);
	  case "reviewerHome":
	    $this->paperLink = "review";
	    return array(1, 11, 27, 21);
	  case "r":
	  case "lead":
	    $this->paperLink = "review";
	    return $this->addScores(array(100, 1, 11, 27, 41, 21, 70, 72, 71, 73));
	  case "rout":
	    $this->paperLink = "review";
	    return $this->addScores(array(100, 1, 11, 27, 41, 21, 70, 72, 71, 73));
	  case "req":
	    $this->paperLink = "review";
	    return $this->addScores(array(100, 1, 11, 27, 41, 21, 70, 72, 71, 73));
	  case "reqrevs":
	    $this->paperLink = "review";
	    return array(1, 11, self::FIELD_REVIEWER_MONITOR, self::FIELD_REVIEWER_STATUS, 41, 21, 70, 72, 71, 73);
	  case "reviewAssignment":
	    $this->paperLink = "assign";
	    return array(1, 11, 39, 36, 43, 35, 70, 37, 38, 44, 47, 48);
	  case "conflict":
	    $this->paperLink = "assign";
	    return array(102, 1, 11, self::FIELD_CONFLICT_CONFIRM, self::FIELD_OPT_AUTHORS, self::FIELD_OPT_ABSTRACT, self::FIELD_AUTHOR_MATCH, self::FIELD_COLLABORATORS_MATCH, self::FIELD_FOLD);
	  case "editReviewPreference":
	    $this->paperLink = "paper";
	    return array(100, 1, 11, 36, 27, 40, 70, 72, 37);
	  case "reviewers":
	    $this->paperLink = "assign";
	    return array(1, 11, 21, 38);
	  case "reviewersSel":
	    $this->paperLink = "assign";
	    return array(101, 1, 11, 21, 38);
	  case "revs":
	    $this->paperLink = "review";
	    return array(1, 11, 25, 27);
	  default:
	    return null;
	}
    }

    function text($listname, $me) {
	global $Conf, $ConfSiteSuffix, $paperListFields;

	$this->contact = $me;
	$this->count = 0;
	$this->needFinalize = 0;
	$this->needSubmitReview = 0;
	$this->anySelector = false;
	$this->anyFinalPaper = false;
	$url = $this->search->url();

	// check role type
	$this->scoresOk = $me->privChair || ($me->isPC && $Conf->timePCViewAllReviews());

	// check what is folded
	if (defval($_SESSION, $this->foldtype . "abstract", 1))
	    $this->foldedAbstract = array();
	else
	    $this->foldedAbstract = false;
	if (!defval($_REQUEST, "showtags")
	    && defval($_SESSION, $this->foldtype . "tags", 1))
	    $this->foldedTags = array();
	else
	    $this->foldedTags = false;
	if (!defval($_REQUEST, "showreviewers")
	    && defval($_SESSION, $this->foldtype . "reviewers", 1))
	    $this->foldedReviewers = array();
	else
	    $this->foldedReviewers = false;

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
	$this->specialSortable = false;
	if ($this->search->orderTag) {
	    $queryOptions["tagIndex"] = $this->search->orderTag;
	    if ($this->search->orderReverse)
		$this->reverseSort = !$this->reverseSort;
	    $this->specialSortable = self::FIELD_TAGINDEX;
	} else if (($pidarray = $this->search->simplePaperList()) !== null)
	    $this->specialSortable = self::FIELD_PIDARRAY;
	if ($this->sortField && !in_array($this->sortField, $baseFieldId))
	    $this->sortField = null;
	if (!$this->sortField && $this->specialSortable)
	    $this->sortField = $this->specialSortable;
	
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

	// prepare review query (see also search > getaction == "reviewers")
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
	    return "No matching papers";
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
	$foldinfo = array();
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
		    $ftype = $fdef[1];
		    if ($ftype == 3)
			$ftype = (paperBlind($row) ? 2 : 1);
		    $foldinfo[$ftype] = true;
		    $t .= " extension" . $ftype;
		    if ($ftype >= 4)
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
	if (isset($foldinfo[1]))
	    $x .= (defval($_SESSION, $this->foldtype . "au", 1) ? " fold1c" : " fold1o");
	if (isset($foldinfo[2]))
	    $x .= (defval($_SESSION, $this->foldtype . "anonau", 1) ? " fold2c" : " fold2o");
	if (!$unfolded)
	    $x .= " fold3c";
	if (isset($foldinfo[4]))
	    $x .= ($this->foldedTags !== false ? " fold4c" : " fold4o");
	if (isset($foldinfo[4]) && $this->foldedTags !== false && count($this->foldedTags)) {
	    $Conf->footerStuff .= "<form id='tagsloadform' method='post' action='search$ConfSiteSuffix?get=tags&amp;ajax=1' accept-charset='UTF-8'><div class='inform'><input type='hidden' name='pap' value='" . join(" ", $this->foldedTags) . "' />";
	    if ($this->search->orderTag)
		$Conf->footerStuff .= "<input type='hidden' name='highlight' value=\"" . htmlspecialchars($this->search->orderTag) . "\" />";
	    $Conf->footerStuff .= "</div></form><script type='text/javascript'>plinfo_ajax_load.tags = true;</script>";
	}
	if (isset($foldinfo[5]))
	    $x .= ($this->foldedAbstract !== false ? " fold5c" : " fold5o");
	if (isset($foldinfo[5]) && $this->foldedAbstract !== false && count($this->foldedAbstract))
	    $Conf->footerStuff .= "<form id='abstractloadform' method='post' action='search$ConfSiteSuffix?get=abstracts&amp;ajax=1' accept-charset='UTF-8'><div class='inform'><input type='hidden' name='pap' value='" . join(" ", $this->foldedAbstract) . "' /></div></form><script type='text/javascript'>plinfo_ajax_load.abstract = true;</script>";
	if (isset($foldinfo[10]))
	    $x .= ($this->foldedReviewers !== false ? " fold10c" : " fold10o");
	if (isset($foldinfo[10]) && $this->foldedReviewers !== false && count($this->foldedReviewers))
	    $Conf->footerStuff .= "<form id='reviewersloadform' method='post' action='search$ConfSiteSuffix?get=reviewers&amp;ajax=1' accept-charset='UTF-8'><div class='inform'><input type='hidden' name='pap' value='" . join(" ", $this->foldedReviewers) . "' /></div></form><script type='text/javascript'>plinfo_ajax_load.reviewers = true;</script>";
	if ($this->anySelector)
	    $x .= (defval($_SESSION, $this->foldtype . "rownum", 1) ? " fold6c" : " fold6o");
	if (count($foldinfo) || !$unfolded || $this->anySelector)
	    $x .= "\" id=\"foldpl";
	$x .= "\">\n";

	$t .= "<img id='foldplactsession' alt='' src='sessionvar$ConfSiteSuffix?var=foldplact&amp;val=" . defval($_SESSION, "foldplact", 1) . "&amp;cache=1' width='1' height='1' />";

	if ($this->showHeader) {
	    $x .= "  <tr class=\"pl_headrow\">\n";
	    $ord = 0;

	    $titleextra = "";
	    if (!$unfolded)
		$titleextra .= "<span class='sep'></span><a class='unfolder3' href='javascript:fold(\"pl\",0,3)'>Show all papers</a>";
	    if ((isset($foldinfo[1]) || isset($foldinfo[2]))
		&& $this->showHeader == self::HEADER_ALL) {
		$titleextra .= "<span class='sep'></span>";
		if ($Conf->blindSubmission() == BLIND_NEVER)
		    $titleextra .= "<a class='unfolder1' href='javascript:fold(\"pl\",0,1)'>Show authors</a><a class='folder1' href='javascript:fold(\"pl\",1,1)'>Hide authors</a>";
		else if ($this->contact->privChair && isset($foldinfo[2]) && !$foldinfo[1])
		    $titleextra .= "<a class='unfolder2' href='javascript:fold(\"pl\",0,2)'>Show authors</a><a class='folder2' href='javascript:fold(\"pl\",1,2)'>Hide authors</a>";
		else if ($this->contact->privChair && isset($foldinfo[2]))
		    $titleextra .= "<a class='unfolder1' href='javascript:fold(\"pl\",0,1)'>Show non-anonymous authors</a><a class='extension1 unfolder2' href='javascript:fold(\"pl\",0,2)'>Show all authors</a><a class='folder2' href='javascript:fold(\"pl\",1,1);fold(\"pl\",1,2)'>Hide authors</a>";
		else
		    $titleextra .= "<a class='unfolder1' href='javascript:fold(\"pl\",0,1)'>Show non-anonymous authors</a><a class='folder1' href='javascript:fold(\"pl\",1,1)'>Hide authors</a>";
	    }
	    if (isset($foldinfo[4]) && $this->showHeader == self::HEADER_ALL) {
		$titleextra .= "<span class='sep'></span>";
		$titleextra .= "<a class='unfolder4' href='javascript:foldplinfo(0,4,\"tags\")'>Show tags</a><a class='folder4' href='javascript:foldplinfo(1,4,\"tags\")'>Hide tags</a><span id='tagsloadformresult'></span>";
		$titleextra .= foldsessionpixel("pl4", $this->foldtype . "tags");
	    }
	    
	    if ($this->sortable && $url) {
		$sortUrl = htmlspecialchars($url) . (strpos($url, "?") ? "&amp;" : "?") . "sort=";
		$q = "<a class='pl_sort' title='Change sort' href=\"" . $sortUrl;
		foreach ($fieldDef as $fieldId => $fdef) {
		    if ($fdef[1] != 1)
			continue;
		    else if (!isset($anyData[$fieldId])) {
			$x .= "    <th class=\"pl_$fdef[0]\"></th>\n";
			continue;
		    }
		    $x .= "    <th class=\"pl_$fdef[0]\">";
		    $ftext = $this->header($fieldId, $ord++);
		    if ($this->sortField == null && $fieldId < 10)
			$this->sortField = $fieldId;
		    $sortableSelector = $fieldId >= self::FIELD_SELECTOR
			&& $this->specialSortable;
		    if ($fieldId == $this->sortField
			|| ($sortableSelector && $this->sortField == $this->specialSortable))
			$x .= "<a class='pl_sort_def" . ($this->reverseSort ? "_rev" : "") . "' title='Reverse sort' href=\"" . $sortUrl . $this->sortField . ($this->reverseSort ? "N" : "R") . "\">" . $ftext . "</a>";
		    else if ($fdef[2])
			$x .= $q . $fieldId . "\">" . $ftext . "</a>";
		    else if ($sortableSelector)
			$x .= $q . $this->specialSortable . "\">" . $ftext . "</a>";
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
	    $x .= $this->footer($ncol, $listname);
	$x .= "</table>";

	// session variable to remember the list
	if ($this->listNumber) {
	    $idx = $this->search->decorateSessionList($ids, $listname, defval($_REQUEST, "sort", ""));
	    if (isset($_REQUEST["sort"]))
		$url .= (strpos($url, "?") ? "&" : "?") . "sort=" . urlencode($_REQUEST["sort"]);
	    $idx["url"] = $url;
	    $_SESSION["l"][$this->listNumber] = $idx;
	}

	$this->headerInfo = array("authors" => (isset($foldinfo[2]) ? 2 : 0) | (isset($foldinfo[1]) ? 1 : 0), "tags" => defval($foldinfo, 4, false), "abstracts" => defval($foldinfo, 5, false), "pap" => $ids);

	return $x;
    }

}


global $paperListFields;
$paperListFields = array( // array(CSS_FIELD_NAME, COLUMN_TYPE, SORTABLE)
	PaperList::FIELD_SELECTOR => array('selector', 1, 0),
	PaperList::FIELD_SELECTOR_ON => array('selector', 1, 0),
	PaperList::FIELD_SELECTOR_CONFLICT => array('selector', 1, 0),
	PaperList::FIELD_ID => array('id', 1, 1),
	PaperList::FIELD_TITLE => array('title', 1, 1),
	PaperList::FIELD_STATUS => array('status', 1, 1),
	PaperList::FIELD_REVIEWER => array('reviewer', 1, 1),
	PaperList::FIELD_REVIEWER_TYPE => array('reviewer', 1, 1),
	PaperList::FIELD_REVIEWER_TYPE_ICON => array('revtypei', 1, 1),
	PaperList::FIELD_REVIEWER_STATUS => array('reviewer', 1, 0),
	PaperList::FIELD_REVIEWER_MONITOR => array('reviewer', 1, 1),
	PaperList::FIELD_STATUS_SHORT => array('status', 1, 1),
	PaperList::FIELD_ASSIGN_REVIEW => array('assrev', 1, 1),
	PaperList::FIELD_TOPIC_INTEREST => array('topicMatch', 1, 1),
	PaperList::FIELD_TOPIC_NAMES => array('topicNames', 2, 0),
	PaperList::FIELD_ALL_REVIEWER_NAMES => array('reviewers', 2, 0),
	PaperList::FIELD_REVIEWER_PREFERENCE => array('revpref', 1, 1),
	PaperList::FIELD_EDIT_REVIEWER_PREFERENCE => array('editrevpref', 1, 1),
	PaperList::FIELD_CONFLICT_CONFIRM => array('conflictconfirm', 1, 0),
	PaperList::FIELD_REVIEWS_STATUS => array('revstat', 1, 1),
	PaperList::FIELD_DESIRABILITY => array('desirability', 1, 1),
	PaperList::FIELD_ALL_PREFERENCES => array('allPreferences', 2, 0),
	PaperList::FIELD_SCORE => array('score', 1, 1),
	PaperList::FIELD_AUTHOR_MATCH => array('authorsMatch', 2, 0),
	PaperList::FIELD_COLLABORATORS_MATCH => array('collaboratorsMatch', 2, 0),
	PaperList::FIELD_OPT_AUTHORS => array('authors', 3, 0),
	PaperList::FIELD_TAGS => array('tags', 4, 0),
	PaperList::FIELD_OPT_ABSTRACT => array('abstract', 5, 0),
	PaperList::FIELD_OPT_ALL_REVIEWER_NAMES => array("reviewers", 10, 0),
	PaperList::FIELD_FOLD => array('fold', 0, 0)
	);
