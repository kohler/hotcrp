<?php
// paperlist.inc -- HotCRP helper class for producing paper lists
// HotCRP is Copyright (c) 2006-2012 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

require_once("baselist.inc");

class PaperList extends BaseList {

    const FIELD_SELECTOR = 1000;
    const FIELD_SELECTOR_ON = 1001;
    const FIELD_SELECTOR_CONFLICT = 1002;
    const FIELD_LAST_SELECTOR = 1002;

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
    const FIELD_OPT_TOPIC_NAMES = 73;
    const FIELD_COLLABORATORS = 74;
    const FIELD_OPT_ALL_REVIEWER_NAMES = 75;
    const FIELD_OPT_PC_CONFLICTS = 76;
    const FIELD_LEAD = 77;
    const FIELD_SHEPHERD = 78;

    const FIELD_FORMULA = 100;
    const FIELD_TAGREPORT = 101;

    const HEADER_ALL = 2;
    const HEADER_TITLES = 1;
    const HEADER_NONE = 0;

    var $showHeader;
    var $sortField;
    var $sortInfo;
    var $sortAverage;
    var $reverseSort;
    var $scoreSort;
    var $sortable;
    var $count;
    var $accepted;
    var $needFinalize;
    var $needFinalCopy;
    var $needSubmitReview;
    var $anySelector;
    var $anyFinalPaper;
    var $defaultFoldRows;
    var $foldRow;
    var $listNumber;
    var $contact;
    var $scoresOk;
    var $scoreMax;
    var $search;
    var $extraText;
    var $display;
    var $aufull;
    var $paperLink;
    var $paperLinkArgs;
    var $showConflict;
    var $headerInfo;
    var $footer;
    var $papersel;
    var $rf;
    var $formulas;
    var $view;
    var $reviewList;

    function __construct($search, $args = array()) {
	global $scoreSorts, $defaultScoreSort, $Conf;
	$this->showHeader = self::HEADER_ALL;

	$this->sortable = !!defval($args, "sort");
	$s = ($this->sortable ? defval($_REQUEST, "sort", "") : "");
	$this->reverseSort = false;
	$this->scoreSort = defval($_SESSION, "scoresort", $defaultScoreSort);
	for ($p = strlen($s) - 1; $p >= 0; --$p) {
	    $x = strtoupper($s[$p]);
	    if ($x == "R")
		$this->reverseSort = true;
	    else if ($x == "N")
		$this->reverseSort = false;
	    else if ($x == "M")
		$this->scoreSort = "C";
	    else if (isset($scoreSorts[$x]))
		$this->scoreSort = $x;
	    else
		break;
	}
	if (("x" . (int) $s) == ("x" . substr($s, 0, $p + 1)))
	    $this->sortField = (int) $s;
	else
	    $this->sortField = null;

	$this->paperLink = "";
	$this->paperLinkArgs = "";
	if (defval($args, "list")) {
	    $this->listNumber = allocateListNumber($search->listId($this->sortdef()));
	    $this->paperLinkArgs .= "&amp;ls=" . $this->listNumber;
	} else
	    $this->listNumber = 0;

	$this->search = $search;
	$this->extraText = defval($args, "extraText");
	$this->scoresOk = false;
	$this->showConflict = true;
	$this->papersel = null;
	$this->footer = true;
	if (is_string(defval($args, "display", null)))
	    $this->display = " " . $args["display"] . " ";
	else
	    $this->display = defval($_SESSION, defval($args, "foldtype", "pl") . "display", "");
	$this->rf = reviewForm();
	$this->formulas = array();
	$this->view = "vd";
	foreach (array("vmc", "vc", "vd") as $view)
	    if (strpos($this->display, " $view ") !== false) {
		$this->view = $view;
		break;
	    }
    }

    function _normalizeField($fieldId) {
	global $paperListNormalizer;
	if (isset($paperListNormalizer[$fieldId]))
	    return $paperListNormalizer[$fieldId][0];
	else
	    return $fieldId;
    }

    function _fieldInfo($fieldId) {
	global $paperListNormalizer;
	if (isset($paperListNormalizer[$fieldId]))
	    return $paperListNormalizer[$fieldId][1];
	else
	    return false;
    }

    function selector($fieldId, &$queryOptions, $folded) {
	global $Conf, $reviewScoreNames;
	switch (self::_normalizeField($fieldId)) {
	case self::FIELD_TOPIC_INTEREST:
	    if (!count($this->rf->topicName))
		return false;
	    $queryOptions["topicInterestScore"] = 1;
	    break;
	case self::FIELD_TOPIC_NAMES:
	    if (!count($this->rf->topicName))
		return false;
	    $queryOptions["topics"] = 1;
	    break;
	case self::FIELD_OPT_TOPIC_NAMES:
	    if (!count($this->rf->topicName))
		return false;
	    if (!$folded)
		$queryOptions["topics"] = 1;
	    break;
	case self::FIELD_ALL_REVIEWER_NAMES:
	case self::FIELD_OPT_ALL_REVIEWER_NAMES:
	    if (!$this->contact->canViewReviewerIdentity(true, null))
		return false;
	    if ($fieldId == self::FIELD_ALL_REVIEWER_NAMES || !$folded) {
		$queryOptions["reviewList"] = 1;
		if ($this->contact->privChair)
		    $queryOptions["allReviewerPreference"] = $queryOptions["topics"] = 1;
	    }
	    break;
	case self::FIELD_REVIEWER_PREFERENCE:
	case self::FIELD_EDIT_REVIEWER_PREFERENCE:
	    $queryOptions['reviewerPreference'] = 1;
	    $Conf->footerScript("addRevprefAjax()");
	    break;
	case self::FIELD_ASSIGN_REVIEW:
	    $Conf->footerScript("addAssrevAjax()");
	    break;
	case self::FIELD_SELECTOR_CONFLICT:
	    $Conf->footerScript("addConflictAjax()");
	    break;
	case self::FIELD_DESIRABILITY:
	    $queryOptions['desirability'] = 1;
	    break;
	case self::FIELD_ALL_PREFERENCES:
	    $queryOptions['allReviewerPreference'] = $queryOptions['topics']
		= $queryOptions['allConflictType'] = 1;
	    break;
	case self::FIELD_SCORE:
	    if (!$this->scoresOk)
		return false;
	    if (!$folded || $fieldId == $this->sortField) {
		$score = self::_fieldInfo($fieldId);
		$revView = $this->contact->viewReviewFieldsScore(null, true);
		if ($this->rf->authorView[$score] <= $revView)
		    return false;
		if (!isset($queryOptions["scores"]))
		    $queryOptions["scores"] = array();
		$queryOptions["scores"][$score] = true;
		$this->scoreMax[$score] = $this->rf->maxNumericScore($score);
	    }
	    break;
	case self::FIELD_FORMULA:
	    $revView = 0;
	    if ($this->contact->amReviewer()
		&& $this->search->limitName != "a")
		$revView = $this->contact->viewReviewFieldsScore(null, true);
	    if (!$this->scoresOk
		|| !($formula = self::_fieldInfo($fieldId))
		|| $formula->authorView <= $revView)
		return false;
	    require_once("paperexpr.inc");
	    $expr = PaperExpr::parse($formula->expression, true);
	    if (!$expr)
		return false;
	    PaperExpr::add_query_options($queryOptions, $expr, $this->contact);
	    $this->formulas[$fieldId] = PaperExpr::compile_function($expr, $this->contact);
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
	    if (!$folded) {
		require_once("tags.inc");
		$queryOptions["tags"] = 1;
	    }
	    break;
	case self::FIELD_TAGREPORT:
	    if (!$this->contact->privChair)
		return false;
	    if (!$folded) {
		require_once("tags.inc");
		$queryOptions["tags"] = 1;
	    }
	    break;
	case self::FIELD_LEAD:
	case self::FIELD_SHEPHERD:
	    if (!$this->contact->isPC)
		return false;
	    break;
	case self::FIELD_COLLABORATORS:
	    if (!$Conf->setting("sub_collab"))
		return false;
	    break;
	case self::FIELD_OPT_PC_CONFLICTS:
	    if (!$this->contact->privChair)
		return false;
	    if (!$folded)
		$queryOptions["allConflictType"] = 1;
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
	$a_dec = $this->contact->canViewDecision($a) ? $a->outcome : -10000;
	$b_dec = $this->contact->canViewDecision($b) ? $b->outcome : -10000;
	$x = $b_dec - $a_dec;
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
	$ar = $a->reviewType;
	$br = $b->reviewType;
	if ($this->contact->privChair && !$ar && $a->conflictType)
	    $ar = -$a->conflictType;
	if ($this->contact->privChair && !$br && $b->conflictType)
	    $br = -$b->conflictType;
	$x = $br - $ar;
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
	$x = 0;
	$ot = $this->search->orderTags;
	for ($i = 0; $x == 0 && $i < count($ot); ++$i) {
	    $n = "tagIndex" . ($i?$i:"");
	    if (($a->conflictType > 0 && $this->sortInfo) || $a->$n === null)
		$av = 2147483647;
	    else
		$av = $a->$n;
	    if (($b->conflictType > 0 && $this->sortInfo) || $b->$n === null)
		$bv = 2147483647;
	    else
		$bv = $b->$n;
	    $x = ($av < $bv ? -1 : ($av == $bv ? 0 : 1));
	    if ($ot[$i]->reverse)
		$x = -$x;
	}
	return $x ? $x : $a->paperId - $b->paperId;
    }

    function _sortThenmap($a, $b) {
	$thenmap = $this->search->thenmap;
	$x = $thenmap[$a->paperId] - $thenmap[$b->paperId];
	return $x ? $x : $this->sortInfo[$a->paperId] - $this->sortInfo[$b->paperId];
    }

    function _sort($rows, $pidarray) {
	global $Conf, $reviewScoreNames;
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
	    $scoreName = self::_fieldInfo($this->sortField);
	    $scoreMax = $this->scoreMax[$scoreName];
	    $scoreName .= "Scores";
	    $this->sortInfo = array();
	    $this->sortAverage = array();
	    foreach ($rows as $row)
		if ($this->contact->canViewReview($row, null))
		    $this->_setSortRow($row, $scoreName, $scoreMax, $this->scoreSort);
		else {
		    $this->sortInfo[$row->paperId] = self::LOTSAZEROS;
		    $this->sortAverage[$row->paperId] = 0;
		}
	    usort($rows, array($this, ($this->scoreSort == "M" || $this->scoreSort == "C" || $this->scoreSort == "Y" ? "_sortScore" : "_sortScoreNum")));
	    break;
	case self::FIELD_FORMULA:
	    $formulaf = $this->formulas[$this->sortField];
	    $this->sortInfo = array();
	    $this->sortAverage = array();
	    foreach ($rows as $row) {
		$this->sortInfo[$row->paperId] = $formulaf($row, $this->contact, "s");
		$this->sortAverage[$row->paperId] = 0;
	    }
	    usort($rows, array($this, "_sortScoreNum"));
	    break;
	}

	if ($this->search->thenmap) {
	    $this->sortInfo = array();
	    for ($i = 0; $i < count($rows); ++$i)
		$this->sortInfo[$rows[$i]->paperId] = $i;
	    usort($rows, array($this, "_sortThenmap"));
	}

	return $rows;
    }

    function sortdef($always = false) {
	if ($this->sortField
	    && ($always || defval($_REQUEST, "sort", "") != "")
	    && ($this->sortField != 1 || $this->reverseSort)) {
	    $t = $this->sortField . ($this->reverseSort ? "R" : "");
	    if (self::_normalizeField($this->sortField) == self::FIELD_SCORE)
		$t .= $this->scoreSort;
	    return $t;
	} else
	    return "";
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

    function header($fieldId, $ordinal = 0, $row = null) {
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
	    return ($ordinal ? "&nbsp;" : "");
	case self::FIELD_SELECTOR_CONFLICT:
	    return "Conflict?";
	case self::FIELD_ASSIGN_REVIEW:
	    return "Assignment";
	case self::FIELD_TOPIC_INTEREST:
	    return "Topic<br/>score";
	case self::FIELD_TOPIC_NAMES:
	case self::FIELD_OPT_TOPIC_NAMES:
	    return "Topics";
	case self::FIELD_ALL_REVIEWER_NAMES:
	    if ($row === null || !isset($this->reviewList[$row->paperId]))
		return "Reviewers";
	    else
		return "Reviewers (" . count($this->reviewList[$row->paperId]) . ")";
	case self::FIELD_OPT_ALL_REVIEWER_NAMES:
	    return "Reviewers";
	case self::FIELD_OPT_PC_CONFLICTS:
	    return "PC conflicts";
	case self::FIELD_REVIEWER_PREFERENCE:
	case self::FIELD_EDIT_REVIEWER_PREFERENCE:
	    return "Preference";
	case self::FIELD_DESIRABILITY:
	    return "Desirability";
	case self::FIELD_ALL_PREFERENCES:
	    return "Preferences";
	case self::FIELD_SCORE: {
	    $scoreName = self::_fieldInfo($fieldId);
	    return $this->rf->webFieldAbbrev($scoreName);
	}
	case self::FIELD_FORMULA: {
	    $formula = self::_fieldInfo($fieldId);
	    $x = ($formula->heading == "" ? $formula->name : $formula->heading);
	    if ($formula->headingTitle && $formula->headingTitle != $x)
		return "<span class=\"hastitle\" title=\"" . htmlspecialchars($formula->headingTitle) . "\">" . htmlspecialchars($x) . "</span>";
	    else
		return htmlspecialchars($x);
	}
	case self::FIELD_AUTHOR_MATCH:
	    return "<strong>Potential conflict in authors</strong>";
	case self::FIELD_COLLABORATORS_MATCH:
	    return "<strong>Potential conflict in collaborators</strong>";
	case self::FIELD_OPT_AUTHORS:
	    return "Authors";
	case self::FIELD_TAGS:
	    return "Tags";
	case self::FIELD_TAGREPORT:
	    return "“" . self::_fieldInfo($fieldId) . "” tag report";
	case self::FIELD_OPT_ABSTRACT:
	    return "Abstract";
	case self::FIELD_LEAD:
	    return "Discussion lead";
	case self::FIELD_SHEPHERD:
	    return "Shepherd";
	case self::FIELD_COLLABORATORS:
	    return "Collaborators";
	case self::FIELD_CONFLICT_CONFIRM:
	    return "";
	default:
	    return "&lt;$fieldId&gt;?";
	}
    }

    function col($fieldId) {
	global $reviewScoreNames;
	switch (self::_normalizeField($fieldId)) {
	case self::FIELD_ID:
	case self::FIELD_REVIEWS_STATUS:
	case self::FIELD_SELECTOR:
	case self::FIELD_SELECTOR_ON:
	case self::FIELD_SELECTOR_CONFLICT:
	case self::FIELD_TOPIC_INTEREST:
	case self::FIELD_REVIEWER_TYPE_ICON:
	case self::FIELD_REVIEWER_PREFERENCE:
	case self::FIELD_DESIRABILITY:
	case self::FIELD_SCORE:
	case self::FIELD_FORMULA:
	    return "<col width='0*' />";
	default:
	    return "<col />";
	}
    }

    function _contentDownload($row) {
	global $Conf;
	if ($row->finalPaperStorageId != 0) {
	    $this->anyFinalPaper = true;
	    $finalsuffix = "f";
	    $finaltitle = "Final version";
	    $documentType = DOCUMENT_FINAL;
	} else {
	    $finalsuffix = "";
	    $finaltitle = null;
	    $documentType = DOCUMENT_SUBMISSION;
	}
	if ($row->size == 0 || !$this->contact->canDownloadPaper($row))
	    return "";
	else if ($row->mimetype == "application/pdf")
	    return "&nbsp;<a href=\"" . $Conf->makeDownloadPath($row->paperId, $row->mimetype, $documentType) . "\">" . $Conf->cacheableImage("pdf$finalsuffix.png", "[PDF]", $finaltitle) . "</a>";
	else if ($row->mimetype == "application/postscript")
	    return "&nbsp;<a href=\"" . $Conf->makeDownloadPath($row->paperId, $row->mimetype, $documentType) . "\">" . $Conf->cacheableImage("postscript$finalsuffix.png", "[PS]", $finaltitle) . "</a>";
	else
	    return "&nbsp;<small><a href=\"" . $Conf->makeDownloadPath($row->paperId, $row->mimetype, $documentType) . "\">[Download]</a></small>";
    }

    function _conflictMatches($input, $preg) {
	preg_match_all("{\n(.*(?:$preg).*)\n}i", "\n$input\n", $matches);
	$amp_preg = str_replace("&", "&amp;", $preg);
	$text = "";
	foreach ($matches[0] as $line) {
	    if ($line != "")
		$line = htmlspecialchars(trim($line));
	    if ($line != "") {
		$text .= ($text ? "; " : "");
		$text .= highlightMatch($amp_preg, $line);
	    }
	}
	if ($text != "")
	    $this->foldRow = false;
	return $text;
    }

    function _paperLink($row) {
	global $Conf;
	if (!($pt = $this->paperLink))
	    $pt = "paper";
	$pl = "p=" . $row->paperId;
	$doreview = isset($row->reviewId) && ($this->search->reviewerContact || isset($row->reviewFirstName));
	if ($doreview) {
	    $rord = unparseReviewOrdinal($row);
	    if ($pt != "paper" || $row->reviewSubmitted <= 0) {
		$pl .= "&amp;r=" . $rord;
		if ($row->reviewSubmitted > 0)
		    $pl .= "&amp;m=r";
	    }
	}
	$pl .= $this->paperLinkArgs;
	if ($doreview && $row->reviewSubmitted > 0)
	    $pl .= "#review" . $rord;
	return hoturl($pt, $pl);
    }

    // content downloaders
    function sessionMatchPreg($name) {
	if (isset($_REQUEST["ls"])
	    && ctype_digit($_REQUEST["ls"])
	    && isset($_SESSION["l"][$_REQUEST["ls"]])
	    && isset($_SESSION["l"][$_REQUEST["ls"]]["matchPreg"]))
	    return defval($_SESSION["l"][$_REQUEST["ls"]]["matchPreg"],
			  $name, "");
	else
	    return "";
    }

    function authorInfo($row, $contact, $full, $highlight) {
	global $Conf;
	if (!$contact->privChair && $Conf->paperBlind($row, $contact))
	    return "";
	cleanAuthor($row);
	$aus = array();
	if ($full) {
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
		if ($highlight !== "")
		    $n = highlightMatch($highlight, $n);
		$aus[] = $n;
	    }
	    if (count($aus))
		$affaus[] = array($aus, $lastaff);
	    foreach ($affaus as &$ax) {
		$aff = htmlspecialchars($ax[1]);
		if ($aff == "" && $anyaff)
		    $aff = "unaffiliated";
		if ($highlight !== "")
		    $aff = highlightMatch($highlight, $aff);
		$ax = commajoin($ax[0]) . ($aff ? " ($aff)" : "");
	    }
	    return commajoin($affaus);
	} else if ($highlight === "") {
	    foreach ($row->authorTable as $au)
		$aus[] = abbreviateNameHtml($au[0], $au[1], $au[2]);
	    return join(", ", $aus);
	} else {
	    foreach ($row->authorTable as $au) {
		$first = htmlspecialchars($au[0]);
		$x = highlightMatch($highlight, trim($first . " " . htmlspecialchars($au[1])), $nm);
		if ((!$nm || substr($x, 0, strlen($first)) == $first)
		    && strlen($first) && ctype_upper($first[0]))
		    $x = $first[0] . ". " . substr($x, strlen($first) + 1);
		$aus[] = $x;
	    }
	    return join(", ", $aus);
	}
    }

    static function wrapChairConflict($text) {
	return "<span class='fn20'><em>Hidden for conflict</em> &nbsp;<span class='barsep'>|</span>&nbsp; <a href=\"javascript:void fold('pl',0,'force')\">Override conflicts</a></span><span class='fx20'>$text</span>";
    }

    function maybeConflict($text, $visible) {
	if ($visible)
	    return $text;
	else if ($this->contact->privChair)
	    return self::wrapChairConflict($text);
	else
	    return "";
    }

    function _contentPC($contactId, $visible) {
	$pcm = pcMembers();
	if (isset($pcm[$contactId]))
	    return self::maybeConflict(contactNameHtml($pcm[$contactId]), $visible);
	else
	    return "";
    }

    function _rowPreferences($row) {
	// reviewer preferences
	$prefs = array();
	if (isset($row->allReviewerPreference))
	    foreach (explode(',', $row->allReviewerPreference) as $rp) {
		$what = explode(' ', $rp);
		if (count($what) == 2 && $what[1])
		    $prefs[$what[0]] = $what[1];
	    }
	// if conflict, reviewer preference is set to "X"
	if (isset($row->allConflictType))
	    foreach (explode(',', $row->allConflictType) as $rp) {
		$what = explode(' ', $rp);
		if (count($what) == 2 && $what[1])
		    $prefs[$what[0]] = false;
	    }
	// topic interest scores (except for conflicts)
	if (isset($row->topicIds) && $row->topicIds != "") {
	    $topicids = explode(" ", $row->topicIds);
	    foreach (pcMembers() as $pcid => $pc) {
		$pref = defval($prefs, $pcid, 0);
		if ($pref !== false) {
		    $tscore = 0;
		    foreach ($topicids as $t) {
			$i = defval($pc->topicInterest, $t, 1);
			$tscore += ($i == 2 ? 2 : $i - 1);
		    }
		    if ($tscore)
			$prefs[$pcid] = array($pref, $tscore);
		}
	    }
	}
	return $prefs;
    }

    function contentEmpty($fieldId, $row) {
	global $Conf;
	switch (self::_normalizeField($fieldId)) {
	case self::FIELD_REVIEWER_TYPE:
	    return !$row->reviewType && $row->conflictType <= 0;
	case self::FIELD_REVIEWER_STATUS:
	    return !$row->reviewId;
	case self::FIELD_TOPIC_NAMES:
	case self::FIELD_OPT_TOPIC_NAMES:
	    return isset($row->topicIds) && $row->topicIds == "";
	case self::FIELD_SCORE:
	    return !$this->contact->canViewReview($row, null, $fakeWhyNotView, true) && !$this->contact->privChair;
	case self::FIELD_AUTHOR_MATCH:
	    return defval($this->search->matchPreg, "authorInformation", "") == "";
	case self::FIELD_COLLABORATORS_MATCH:
	    return defval($this->search->matchPreg, "collaborators", "") == "";
	case self::FIELD_OPT_ABSTRACT:
	    return $row->abstract == "";
	case self::FIELD_TAGS:
	case self::FIELD_TAGREPORT:
	    return !$this->contact->canViewTags($row);
	case self::FIELD_LEAD:
	    return (!$this->contact->actPC($row, true) || !$row->leadContactId);
	case self::FIELD_SHEPHERD:
	    return (!$this->contact->canViewDecision($row, true)
		    || !$row->shepherdContactId);
	case self::FIELD_COLLABORATORS:
	    return ($row->collaborators == ""
		    || strcasecmp($row->collaborators, "None") == 0
		    || (!$this->contact->privChair
			&& $Conf->paperBlind($row, $this->contact)));
	default:
	    return false;
	}
    }

    function _reviewAnalysis($row) {
	global $Conf;
	$ranal = (object) array("completion" => "", "delegated" => false,
				"needsSubmit" => false, "round" => "",
				"link1" => "", "link2" => "");
	if ($row->reviewId) {
	    $ranal->needsSubmit = !isset($row->reviewSubmitted) || !$row->reviewSubmitted;
	    if (!$ranal->needsSubmit)
		$ranal->completion = "Complete";
	    else if ($row->reviewType == REVIEW_SECONDARY
		     && $row->reviewNeedsSubmit <= 0) {
		$ranal->completion = "Delegated";
		$ranal->delegated = true;
	    } else if ($row->reviewModified == 0)
		$ranal->completion = "Not&nbsp;started";
	    else
		$ranal->completion = "In&nbsp;progress";
	    if ($ranal->needsSubmit)
		$link = hoturl("review", "r=" . unparseReviewOrdinal($row) . $this->paperLinkArgs);
	    else
		$link = hoturl("paper", "p=" . $row->paperId . $this->paperLinkArgs . "#review" . unparseReviewOrdinal($row));
	    $ranal->link1 = "<a href=\"$link\">";
	    $ranal->link2 = "</a>";
	    if ($row->reviewRound) {
		$t = defval($Conf->settings["rounds"], $row->reviewRound);
		$ranal->round = $t ? htmlspecialchars($t) : "?$row->reviewRound?";
	    }
	}
	return $ranal;
    }

    function _reviewIcon($row, $ranal, $includeLink) {
	global $reviewTypeName, $Conf;
	$alt = $reviewTypeName[$row->reviewType] . " review";
	if ($ranal->needsSubmit)
	    $alt .= " (" . strtolower($ranal->completion) . ")";
	$t = $Conf->cacheableImage("_.gif", $alt, $alt, "ass" . $row->reviewType . ($ranal->needsSubmit ? "n" : ""));
	if ($includeLink)
	    $t = $ranal->link1 . $t . $ranal->link2;
	if ($ranal->round)
	    $t .= "&nbsp;<span class='revround' title='Review round'>" . $ranal->round . "</span>";
	return $t;
    }

    function content($fieldId, $row) {
	global $Conf, $reviewScoreNames;
	switch (self::_normalizeField($fieldId)) {
	case self::FIELD_ID:
	    $pl = $this->_paperLink($row);
	    return "<a href='$pl' tabindex='4'>#$row->paperId</a>";
	case self::FIELD_TITLE:
	    $pl = $this->_paperLink($row);
	    $x = htmlspecialchars($row->title);
	    if (isset($this->search->matchPreg["title"]))
		$x = highlightMatch($this->search->matchPreg["title"], $x);
	    return "<a href='$pl' tabindex='5'>" . $x . "</a>" . $this->_contentDownload($row);
	case self::FIELD_STATUS:
	case self::FIELD_STATUS_SHORT:
	    if ($row->timeSubmitted <= 0 && $row->timeWithdrawn <= 0)
		$this->needFinalize++;
	    if ($row->outcome > 0 && $this->contact->canViewDecision($row))
		$this->accepted++;
	    if ($row->outcome > 0 && $row->timeFinalSubmitted <= 0
		&& $this->contact->canViewDecision($row))
		$this->needFinalCopy++;
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
	    $ranal = $this->_reviewAnalysis($row);
	    if ($ranal->needsSubmit)
		$this->needSubmitReview++;
	    $t = "$ranal->link1<span class='rtype'>$t</span>$ranal->link2";
	    if ($ranal->completion)
		$t .= "&nbsp;<span class='rstat'>($ranal->completion)</span>";
	    return $t;
	case self::FIELD_REVIEWER_STATUS:
	    if (!$row->reviewId)
		return "";
	    $ranal = $this->_reviewAnalysis($row);
	    if ($ranal->needsSubmit)
		$this->needSubmitReview++;
	    $t = $ranal->completion;
	    if ($ranal->needsSubmit && !$ranal->delegated)
		$t = "<strong class='overdue'>$t</strong>";
	    if (!$ranal->needsSubmit)
		$t = $ranal->link1 . $t . $ranal->link2;
	    return $t;
	case self::FIELD_REVIEWER_TYPE_ICON:
	    $a1 = $a2 = "";
	    if ($row->conflictType > 0 && $this->showConflict)
		return $Conf->cacheableImage("_.gif", "Conflict", "Conflict", "ass-1");
	    else if ($row->reviewType === null)
		return $Conf->cacheableImage("_.gif", "", "", "ass0");
	    else {
		$ranal = $this->_reviewAnalysis($row);
		if ($ranal->needsSubmit)
		    $this->needSubmitReview++;
		$t = self::_reviewIcon($row, $ranal, true);
		if ($ranal->round)
		    return "<div class='pl_revtypeiround'>" . $t . "</div>";
		else
		    return $t;
	    }
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
		$this->foldRow = false;
	    }
	    if ($fieldId == self::FIELD_SELECTOR_CONFLICT && $row->conflictType >= CONFLICT_AUTHOR)
		$c .= " disabled='disabled'";
	    if ($fieldId != self::FIELD_SELECTOR_CONFLICT)
		$c .= " onclick='pselClick(event,this)'";
	    return "<span class=\"pl_rownum fx6\">" . $this->count . ". </span>" . "<input type='checkbox' class='cb' name='pap[]' value='$row->paperId' tabindex='3' id='psel$this->count' $c/>";
	case self::FIELD_CONFLICT_CONFIRM:
	    return "<span id='assrev" . $row->paperId . "ok'></span>";
	case self::FIELD_ASSIGN_REVIEW:
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
	case self::FIELD_TOPIC_INTEREST:
	    return htmlspecialchars($row->topicInterestScore + 0);
	case self::FIELD_TOPIC_NAMES:
	case self::FIELD_OPT_TOPIC_NAMES:
	    return join(", ", $this->rf->webTopicArray($row->topicIds, defval($row, "topicInterest")));
	case self::FIELD_ALL_REVIEWER_NAMES:
	case self::FIELD_OPT_ALL_REVIEWER_NAMES:
	    $prefs = self::_rowPreferences($row);
	    $n = "";
	    // see also search.php > getaction == "reviewers"
	    if (isset($this->reviewList[$row->paperId])) {
		foreach ($this->reviewList[$row->paperId] as $xrow)
		    if ($xrow->lastName) {
			$ranal = $this->_reviewAnalysis($xrow);
			$n .= ($n ? ", " : "");
			$n .= contactHtml($xrow->firstName, $xrow->lastName);
			if ($xrow->reviewType >= REVIEW_SECONDARY)
			    $n .= "&nbsp;" . self::_reviewIcon($xrow, $ranal, false);
			if (($pref = defval($prefs, $xrow->contactId, null)))
			    $n .= preferenceSpan($pref);
		    }
		$n = self::maybeConflict($n, $this->contact->canViewReviewerIdentity($row, null, true));
	    }
	    return $n;
	case self::FIELD_OPT_PC_CONFLICTS:
	    $x = "," . $row->allConflictType;
	    $y = array();
	    foreach (pcMembers() as $pc)
		if (strpos($x, "," . $pc->contactId . " ") !== false)
		    $y[] = contactHtml($pc->firstName, $pc->lastName);
	    return join(", ", $y);
	case self::FIELD_REVIEWER_PREFERENCE:
	    return (isset($row->reviewerPreference) ? htmlspecialchars($row->reviewerPreference) : "0");
	case self::FIELD_EDIT_REVIEWER_PREFERENCE:
	    if ($row->conflictType > 0)
		return "N/A";
	    $x = (isset($row->reviewerPreference) ? htmlspecialchars($row->reviewerPreference) : "0");
	    return "<input class='textlite' type='text' size='4' name='revpref$row->paperId' id='revpref$row->paperId' value=\"$x\" tabindex='2' /> <img id='revpref" . $row->paperId . "ok' src='" . hoturlx("images/_.gif") . "' alt='' class='ajaxcheck' />";
	case self::FIELD_DESIRABILITY:
	    return (isset($row->desirability) ? htmlspecialchars($row->desirability) : "0");
	case self::FIELD_ALL_PREFERENCES:
	    $prefs = self::_rowPreferences($row);
	    $text = "";
	    foreach (pcMembers() as $pcid => $pc)
		if (($pref = defval($prefs, $pcid, null))) {
		    $text .= ($text == "" ? "" : ", ")
			. contactHtml($pc->firstName, $pc->lastName)
			. preferenceSpan($pref);
		}
	    return $text;
	case self::FIELD_SCORE:
	    $scoreName = self::_fieldInfo($fieldId);
	    $allowed = $this->contact->canViewReview($row, null, $fakeWhyNotView, true);
	    $fname = "${scoreName}Scores";
	    if (($allowed || $this->contact->privChair) && $row->$fname) {
		$t = $Conf->textValuesGraph($row->$fname, $this->scoreMax[$scoreName], 1, defval($row, $scoreName), $this->rf->reviewFields[$scoreName]);
		if (!$allowed)
		    $t = "<span class='fx20'>$t</span>";
		return $t;
	    } else
		return "";
	case self::FIELD_FORMULA:
	    $formulaf = $this->formulas[$fieldId];
	    $t = $formulaf($row, $this->contact, "h");
	    if ($row->conflictType > 0 && $this->contact->privChair)
		return "<span class='fn20'>$t</span><span class='fx20'>"
		    . $formulaf($row, $this->contact, "h", true) . "</span>";
	    else
		return $t;
	case self::FIELD_AUTHOR_MATCH:
	    $m = defval($this->search->matchPreg, "authorInformation", "");
	    if ($m == "")
		return "";
	    return $this->_conflictMatches($row->authorInformation, $m);
	case self::FIELD_COLLABORATORS_MATCH:
	    $m = defval($this->search->matchPreg, "collaborators", "");
	    if ($m == "")
		return "";
	    return $this->_conflictMatches($row->collaborators, $m);
	case self::FIELD_OPT_AUTHORS:
	    $highlight = defval($this->search->matchPreg, "authorInformation", "");
	    return self::authorInfo($row, $this->contact, $this->aufull,
				    $highlight);
	case self::FIELD_OPT_ABSTRACT:
	    if ($row->abstract == "")
		return "";
	    $x = htmlspecialchars($row->abstract);
	    if (isset($this->search->matchPreg["abstract"]))
		$x = highlightMatch($this->search->matchPreg["abstract"], $x);
	    return $x;
	case self::FIELD_TAGS:
	    if (!$this->contact->canViewTags($row))
		return "";
	    if (($t = $row->paperTags) !== "")
		$t = tagsToText($row, true, $this->contact, false,
				$this->search->orderTags);
	    return $t;
	case self::FIELD_TAGREPORT:
	    if (!$this->contact->canViewTags($row))
		return "";
	    $tag = self::_fieldInfo($fieldId);
	    if (($t = $row->paperTags) === "")
		return $t;
	    $a = array();
	    foreach (pcMembers() as $pcm) {
		$mytag = " " . $pcm->contactId . "~" . $tag . "#";
		if (($p = strpos($t, $mytag)) !== false)
		    $a[] = contactNameHtml($pcm) . " (#" . ((int) substr($t, $p + strlen($mytag))) . ")";
	    }
	    return join(", ", $a);
	case self::FIELD_LEAD:
	    if (!$row->leadContactId)
		return "";
	    $visible = $this->contact->actPC($row);
	    return $this->_contentPC($row->leadContactId, $visible);
	case self::FIELD_SHEPHERD:
	    if (!$row->shepherdContactId)
		return "";
	    $visible = $this->contact->actPC($row) || $this->contact->canViewDecision($row);
	    return $this->_contentPC($row->shepherdContactId, $visible);
	case self::FIELD_COLLABORATORS:
	    if ($row->collaborators == ""
		|| strcasecmp($row->collaborators, "None") == 0
		|| (!$this->contact->privChair
		    && $Conf->paperBlind($row, $this->contact)))
		return "";
	    $x = "";
	    foreach (explode("\n", $row->collaborators) as $c)
		$x .= ($x === "" ? "" : ", ") . htmlspecialchars(trim($c));
	    if (isset($this->search->matchPreg["collaborators"]))
		$x = highlightMatch($this->search->matchPreg["collaborators"], $x);
	    return $x;
	default:
	    return "";
	}
    }

    function footer($ncol, $listname, $trclass) {
	global $Conf;
	if ($this->count == 0 || !$this->footer)
	    return "";

	$barsep = "<td>&nbsp;<span class='barsep'>&nbsp;|&nbsp;</span>&nbsp;</td>";
	$nlll = 1;
	$revpref = ($listname == "editReviewPreference");
	$whichlll = 1;
	$want_plactions_dofold = false;

	// Download
	if (defval($_REQUEST, "atab") == "download")
	    $whichlll = $nlll;
	$t = "<td class='lll$nlll nowrap'><a href=\"" . selfHref(array("atab" => "download")) . "#plact\" onclick='return crpfocus(\"plact\",$nlll)'>Download</a></td><td class='lld$nlll nowrap'><b>:</b> &nbsp;";
	$sel_opt = array();
	if ($revpref) {
	    $sel_opt["revpref"] = "Preference file";
	    $sel_opt["revprefx"] = "Preference file with abstracts";
	    $sel_opt["abstract"] = "Abstracts";
	} else {
	    if ($this->anyFinalPaper) {
		$sel_opt["final"] = "Final papers";
		foreach (paperOptions() as $id => $o)
		    if ($o->type == OPTIONTYPE_FINALPDF)
			$sel_opt["opt-" . $o->optionAbbrev] = htmlspecialchars($o->optionName) . " papers";
		    else if ($o->type == OPTIONTYPE_FINALSLIDES)
			$sel_opt["opt-" . $o->optionAbbrev] = htmlspecialchars(pluralx($o->optionName, 2));
		$sel_opt["paper"] = "Submitted papers";
	    } else
		$sel_opt["paper"] = "Papers";
	    $sel_opt["abstract"] = "Abstracts";
	    $sel_opt["revform"] = "Review forms";
	    $sel_opt["revformz"] = "Review forms (zip)";
	    if ($this->contact->isPC || $this->contact->isReviewer) {
		$sel_opt["rev"] = "All reviews";
		$sel_opt["revz"] = "All reviews (zip)";
	    }
	}
	if ($this->contact->privChair)
	    $sel_opt["authors"] = "Authors &amp; contacts";
	else if ($this->contact->isReviewer && !$Conf->subBlindAlways())
	    $sel_opt["authors"] = "Authors";
	if ($this->contact->privChair) {
	    $sel_opt["pcconf"] = "PC conflicts";
	    $sel_opt["checkformat"] = "Format check";
	}
	$sel_opt["topics"] = "Topics";
	if (!$revpref && ($this->contact->privChair || ($this->contact->isPC && $Conf->timePCViewAllReviews())))
	    $sel_opt["scores"] = "Scores";
	if ($Conf->setting("paperlead") > 0) {
	    $sel_opt["lead"] = "Discussion leads";
	    $sel_opt["shepherd"] = "Shepherds";
	}
	$t .= tagg_select("getaction", $sel_opt, defval($_REQUEST, "getaction"),
			  array("id" => "plact${nlll}_d", "tabindex" => 6))
	    . "&nbsp; <input type='submit' class='b' name='getgo' value='Go' tabindex='6' onclick='return (papersel_check_safe=true)' /></td>";
	$nlll++;

	// Upload preferences (review preferences only)
	if ($revpref) {
	    if (isset($_REQUEST["upload"])
		|| defval($_REQUEST, "atab") == "uploadpref")
		$whichlll = $nlll;
	    $t .= $barsep;
	    $t .= "<td class='lll$nlll nowrap'><a href=\"" . selfHref(array("atab" => "uploadpref")) . "#plact\" onclick='return crpfocus(\"plact\",$nlll)'>Upload</a></td><td class='lld$nlll nowrap'><b>&nbsp;preference file:</b> &nbsp;";
	    $t .= "<input id='plact${nlll}_d' type='file' name='uploadedFile' accept='text/plain' size='20' tabindex='6' onfocus='autosub(\"upload\",this)' />&nbsp; <input type='submit' class='b' name='upload' value='Go' tabindex='6' /></td>";
	    $nlll++;
	}

	// Set preferences (review preferences only)
	if ($revpref) {
	    if (isset($_REQUEST["setpaprevpref"])
		|| defval($_REQUEST, "atab") == "setpref")
		$whichlll = $nlll;
	    $t .= $barsep;
	    $t .= "<td class='lll$nlll nowrap'><a href=\"" . selfHref(array("atab" => "setpref")) . "#plact\" onclick='return crpfocus(\"plact\",$nlll)'>Set preferences</a></td><td class='lld$nlll nowrap'><b>:</b> &nbsp;";
	    $t .= "<input id='plact${nlll}_d' class='textlite' type='text' name='paprevpref' value='' size='4' tabindex='6' onfocus='autosub(\"setpaprevpref\",this)' /> &nbsp;<input type='submit' class='b' name='setpaprevpref' value='Go' tabindex='6' /></td>";
	    $nlll++;
	}

	// Tags (search+PC only)
	if ($this->contact->isPC && !$revpref) {
	    if (isset($_REQUEST["tagact"])
		|| defval($_REQUEST, "atab") == "tags")
		$whichlll = $nlll;
	    $t .= $barsep;
	    $t .= "<td class='lll$nlll nowrap'><a href=\"" . selfHref(array("atab" => "tags")) . "#plact\" onclick='return crpfocus(\"plact\",$nlll)'>Tag</a></td><td id='foldplacttags' class='lld$nlll nowrap foldc fold99c'><table><tr><td><b>:</b><a class='help' href='" . hoturl("help", "t=tags") . "' target='_blank' title='Learn more'>?</a> &nbsp;";
	    $tagopt = array("a" => "Add", "d" => "Remove", "s" => "Define", "xxxa" => null, "ao" => "Add to order", "aos" => "Add to gapless order", "so" => "Define order", "sos" => "Define gapless order", "sor" => "Define random order");
	    $tagextra = array("class" => "b", "id" => "placttagtype");
	    if ($this->contact->privChair) {
		$tagopt["xxxb"] = null;
		$tagopt["da"] = "Clear twiddle";
		$tagopt["cr"] = "Calculate rank";
		$tagextra["onchange"] = "plactions_dofold()";
		$want_plactions_dofold = true;
	    }
	    $t .= tagg_select("tagtype", $tagopt, defval($_REQUEST, "tagtype"),
			      $tagextra) . " &nbsp;";
	    if ($this->contact->privChair) {
		$t .= "<span class='fx99'><a href='#' onclick=\"return fold('placttags')\">"
		    . $Conf->cacheableImage("_.gif", "More...", null, "expander")
		    . "</a>&nbsp;</span></td><td>";
	    }
	    $t .= "tag<span class='fn99'>(s)</span> &nbsp;<input id='plact${nlll}_d' class='textlite' type='text' name='tag' value=\"" . htmlspecialchars(defval($_REQUEST, "tag", "")) . "\"' size='15' onfocus='autosub(\"tagact\",this)' /> &nbsp;<input type='submit' class='b' name='tagact' value='Go' />";
	    if ($this->contact->privChair) {
		$t .= "<div class='fx'><div style='margin:2px 0'>"
		    . tagg_checkbox("tagcr_gapless", 1, defval($_REQUEST, "tagcr_gapless"), array("style" => "margin-left:0"))
		    . "&nbsp;" . tagg_label("Gapless order") . "</div>"
		    . "<div style='margin:2px 0'>Using: &nbsp;"
		    . tagg_select("tagcr_method", array("schulze" => "Schulze method", "irv" => "Instant-runoff voting", "range" => "Range voting", "civs" => "CIVS Ranked Pairs"), defval($_REQUEST, "tagcr_method"))
		    . "</div>"
		    . "<div style='margin:2px 0'>Source tag: &nbsp;~<input class='textlite' type='text' name='tagcr_source' value=\"" . htmlspecialchars(defval($_REQUEST, "tagcr_source", "")) . "\" size='15' /></div>"
		    . "</div>";
	    }
	    $t .= "</td></tr></table></td>";
	    $nlll++;
	}

	// Assignments (search+admin only)
	if ($this->contact->privChair && !$revpref) {
	    if (isset($_REQUEST["setassign"])
		|| defval($_REQUEST, "atab") == "assign")
		$whichlll = $nlll;
	    $t .= $barsep;
	    $t .= "<td class='lll$nlll'><a href=\"" . selfHref(array("atab" => "assign")) . "#plact\" onclick='return crpfocus(\"plact\",$nlll)'>Assign</a></td><td id='foldass' class='lld$nlll foldo'><b>:</b> &nbsp;";
	    $want_plactions_dofold = true;
	    $t .= tagg_select("marktype",
			      array("xauto" => "Automatic assignments",
				    "zzz1" => null,
				    "conflict" => "Mark conflict",
				    "unconflict" => "Remove conflict",
				    "zzz3" => null,
				    "assign" . REVIEW_PRIMARY => "Assign primary review",
				    "assign" . REVIEW_SECONDARY => "Assign secondary review",
				    "assign" . REVIEW_PC => "Assign optional review",
				    "assign0" => "Unassign reviews"),
			      defval($_REQUEST, "marktype"),
			      array("id" => "plact${nlll}_d",
				    "onchange" => "plactions_dofold()"))
		. "<span class='fx'> &nbsp;for &nbsp;";
	    // <option value='-2' disabled='disabled'></option>
	    // <option value='xpcpaper'>Mark as PC-authored</option>
	    // <option value='xunpcpaper'>Mark as not PC-authored</option>
	    $pc = pcMembers();
	    $sel_opt = array();
	    foreach ($pc as $id => $row)
		$sel_opt[htmlspecialchars($row->email)] = htmlspecialchars(contactNameText($row));
	    $t .= tagg_select("markpc", $sel_opt, defval($_REQUEST, "markpc"),
			      array("id" => "markpc"))
		. "</span> &nbsp;<input type='submit' class='b' name='setassign' value='Go' />";
	    $t .= "</td>";
	    $nlll++;
	}

	// Decide, Mail (search+admin only)
	if ($this->contact->privChair && !$revpref) {
	    if (defval($_REQUEST, "atab") == "decide")
		$whichlll = $nlll;
	    $t .= $barsep;
	    $t .= "<td class='lll$nlll'><a href=\"" . selfHref(array("atab" => "decide")) . "#plact\" onclick='return crpfocus(\"plact\",$nlll)'>Decide</a></td><td class='lld$nlll'><b>:</b> Set to &nbsp;";
	    $t .= decisionSelector(0, "plact${nlll}_d") . " &nbsp;<input type='submit' class='b' name='setdecision' value='Go' /></td>";
	    $nlll++;

	    if (isset($_REQUEST["sendmail"])
		|| defval($_REQUEST, "atab") == "mail")
		$whichlll = $nlll;
	    $t .= $barsep
		. "<td class='lll$nlll'><a href=\"" . selfHref(array("atab" => "mail")) . "#plact\" onclick='return crpfocus(\"plact\",$nlll)'>Mail</a></td><td class='lld$nlll'><b>:</b> &nbsp;"
		. tagg_select("recipients", array("au" => "Contact authors", "rev" => "Reviewers"), defval($_REQUEST, "recipients"), array("id" => "plact${nlll}_d"))
		. " &nbsp;<input type='submit' class='b' name='sendmail' value='Go' /></td>";
	    $nlll++;
	}

	if ($want_plactions_dofold)
	    $Conf->footerScript("plactions_dofold()");

	// Linelinks container
	$extraFooter = ($this->footer && $this->footer !== true ? $this->footer : "");
	$foot = " <tfoot>\n"
	    . "  <tr class='pl_footgap $trclass'><td colspan='$ncol'><div class='pl_footgap'></div></td></tr>\n"
	    . "  <tr class='pl_footrow'>\n";
	if ($this->view == "vmc" || $this->view == "vc")
	    $foot .= "    <td class='pl_footer' colspan='$ncol'>";
	else
	    $foot .= "    <td class='pl_footselector'>"
		. $Conf->cacheableImage("_.gif", "^^", null, "placthook")
		. "</td>\n    <td class='pl_footer' colspan='" . ($ncol - 1) . "'>";
	return $foot . "<table id='plact' class='linelinks$whichlll'><tr><td><a name='plact'><b>Select papers</b></a> (or <a href=\""
	    . selfHref(array("selectall" => 1))
	    . "#plact\" onclick='return papersel(true)'>select all " . $this->count . "</a>), then&nbsp;"
	    . "<img id='foldplactsession' alt='' src='" . hoturl("sessionvar", "var=foldplact&amp;val=" . defval($_SESSION, "foldplact", 1) . "&amp;cache=1") . "' width='1' height='1' />"
	    . "</td>"
	    . $t . "</tr></td></table>" . $extraFooter . "</td></tr>\n"
	    . " </tfoot>\n";
    }

    function _canonicalize_fields($fields) {
	global $reviewScoreNames, $paperListFormulas, $paperListNormalizer, $paperListFields;
	$minimal = $this->view == "vmc";
	$newFields = array();
	foreach ($fields as $fid) {
	    $nf = array();
	    if ($fid == self::FIELD_SCORE) {
		if (!$this->scoresOk)
		    continue;
		foreach ($paperListNormalizer as $newfid => $info)
		    if ($info[0] == self::FIELD_SCORE
			&& ($p = array_search($info[1], $this->rf->fieldOrder)) !== false)
			$nf[$newfid] = $p;
		asort($nf);
		$nf = array_keys($nf);
		$this->scoreMax = array();
	    } else if ($fid == self::FIELD_FORMULA) {
		if (!$this->scoresOk)
		    continue;
		foreach ($paperListFormulas as $formula)
		    array_push($nf, $formula->fieldId);
	    } else if ($fid == self::FIELD_TAGREPORT) {
		foreach ($paperListNormalizer as $newfid => $info)
		    if ($info[0] == self::FIELD_TAGREPORT)
			$nf[] = $newfid;
	    } else
		$nf[] = $fid;
	    foreach ($nf as $newfid)
		if (!$minimal || $paperListFields[$newfid]->minimal)
		    $newFields[] = $newfid;
	}
	return $newFields;
    }

    static function listDescription($listname) {
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
	    return "Review preferences";
	  case "reviewers":
	  case "reviewersSel":
	    return "Proposed assignments";
	  case "revs":
	    return "Reviews";
	  default:
	    return null;
	}
    }

    function _listFields($listname) {
	switch ($listname) {
	case "a":
	case "authorHome":
	    $fields = array(1, 11, 20);
	    break;
	case "s":
	case "acc":
	    $fields = array(self::FIELD_SELECTOR, 1, 11, 27, 41, 21, self::FIELD_OPT_AUTHORS, self::FIELD_OPT_ABSTRACT, self::FIELD_TAGS, self::FIELD_TAGREPORT, self::FIELD_OPT_TOPIC_NAMES, self::FIELD_COLLABORATORS, 75, 76, 77, 78, self::FIELD_SCORE, self::FIELD_FORMULA);
	    break;
	case "all":
	case "act":
	    $fields = array(self::FIELD_SELECTOR, 1, 11, 20, 27, self::FIELD_OPT_AUTHORS, self::FIELD_OPT_ABSTRACT, self::FIELD_TAGS, self::FIELD_TAGREPORT, self::FIELD_OPT_TOPIC_NAMES, self::FIELD_COLLABORATORS, 75, 76, 77, 78);
	    break;
	case "reviewerHome":
	    $this->paperLink = "review";
	    $fields = array(1, 11, 27, 21);
	    break;
	case "r":
	case "lead":
	    $this->paperLink = "review";
	    $fields = array(self::FIELD_SELECTOR, 1, 11, 27, 41, 21, self::FIELD_OPT_AUTHORS, self::FIELD_OPT_ABSTRACT, self::FIELD_TAGS, self::FIELD_TAGREPORT, self::FIELD_OPT_TOPIC_NAMES, self::FIELD_COLLABORATORS, 75, 76, 77, 78, self::FIELD_SCORE, self::FIELD_FORMULA);
	    break;
	case "rout":
	    $this->paperLink = "review";
	    $fields = array(self::FIELD_SELECTOR, 1, 11, 27, 41, 21, self::FIELD_OPT_AUTHORS, self::FIELD_OPT_ABSTRACT, self::FIELD_TAGS, self::FIELD_TAGREPORT, self::FIELD_OPT_TOPIC_NAMES, self::FIELD_COLLABORATORS, 75, 76, 77, 78, self::FIELD_SCORE, self::FIELD_FORMULA);
	    break;
	case "req":
	    $this->paperLink = "review";
	    $fields = array(self::FIELD_SELECTOR, 1, 11, 27, 41, 21, self::FIELD_OPT_AUTHORS, self::FIELD_OPT_ABSTRACT, self::FIELD_TAGS, self::FIELD_TAGREPORT, self::FIELD_OPT_TOPIC_NAMES, self::FIELD_COLLABORATORS, 75, 76, 77, 78, self::FIELD_SCORE, self::FIELD_FORMULA);
	    break;
	case "reqrevs":
	    $this->paperLink = "review";
	    $fields = array(1, 11, self::FIELD_REVIEWER_MONITOR, self::FIELD_REVIEWER_STATUS, 41, 21, self::FIELD_OPT_AUTHORS, self::FIELD_OPT_ABSTRACT, self::FIELD_TAGS, self::FIELD_TAGREPORT, self::FIELD_OPT_TOPIC_NAMES, self::FIELD_COLLABORATORS, 75, 76, 77, 78);
	    break;
	  case "reviewAssignment":
	    $this->paperLink = "assign";
	    $fields = array(1, 11, 39, 36, 43, 35, self::FIELD_OPT_AUTHORS, self::FIELD_TOPIC_NAMES, self::FIELD_ALL_REVIEWER_NAMES, self::FIELD_ALL_PREFERENCES, self::FIELD_AUTHOR_MATCH, self::FIELD_COLLABORATORS_MATCH);
	    break;
	  case "conflict":
	    $this->paperLink = "assign";
	    $this->defaultFoldRow = true;
	    $fields = array(self::FIELD_SELECTOR_CONFLICT, 1, 11, self::FIELD_CONFLICT_CONFIRM, self::FIELD_OPT_AUTHORS, self::FIELD_OPT_ABSTRACT, self::FIELD_AUTHOR_MATCH, self::FIELD_COLLABORATORS_MATCH);
	    break;
	  case "editReviewPreference":
	    $this->paperLink = "paper";
	    $fields = array(self::FIELD_SELECTOR, 1, 11, 36, 27, 40, self::FIELD_OPT_AUTHORS, self::FIELD_OPT_ABSTRACT, self::FIELD_OPT_TOPIC_NAMES);
	    break;
	  case "reviewers":
	    $this->paperLink = "assign";
	    $fields = array(1, 11, 21, self::FIELD_ALL_REVIEWER_NAMES);
	    break;
	  case "reviewersSel":
	    $this->paperLink = "assign";
	    $fields = array(self::FIELD_SELECTOR_ON, 1, 11, 21, self::FIELD_ALL_REVIEWER_NAMES);
	    break;
	  case "revs":
	    $this->paperLink = "review";
	    $fields = array(1, 11, 25, 27);
	    break;
	  default:
	    return null;
	}
	return $this->_canonicalize_fields($fields);
    }

    function _addAjaxLoadForm($pap, $extra = "") {
	global $Conf;
	$t = "<div><form id='plloadform' method='post' action='" . hoturl("search", "ajax=1" . $this->paperLinkArgs) . "' accept-charset='UTF-8'>"
	    . "<div class='inform'>";
	$s = $this->search;
	if ($s->q)
	    $t .= "<input type='hidden' name='q' value=\"" . htmlspecialchars($s->q) . "\" />";
	if ($s->qt)
	    $t .= "<input type='hidden' name='qt' value=\"" . htmlspecialchars($s->qt) . "\" />";
	$t .= "<input type='hidden' name='t' value=\"" . htmlspecialchars($s->limitName) . "\" />"
	    . "<input type='hidden' name='pap' value='" . join(" ", $pap) . "' />"
	    . "<input id='plloadform_get' type='hidden' name='get' value='' />"
	    . "<input id='plloadform_aufull' type='hidden' name='aufull' value='" . ($this->aufull ? "1" : "") . "' />"
	    . "</div></form></div>";
	$Conf->footerHtml($t);
    }

    function _prepareQuery($me, $queryOptions) {
	global $Conf;

	// prepare review query (see also search > getaction == "reviewers")
	$this->reviewList = array();
	if (isset($queryOptions['reviewList'])) {
	    $result = $Conf->qe("select Paper.paperId, reviewId, reviewType,
		reviewSubmitted, reviewModified, reviewNeedsSubmit, reviewRound,
		reviewOrdinal,
		PaperReview.contactId, lastName, firstName, email
		from Paper
		join PaperReview using (paperId)
		join ContactInfo on (PaperReview.contactId=ContactInfo.contactId)
		where " . ($this->search->limitName != 'a' ? "timeSubmitted>0" : "paperId=-1") . "
		order by lastName, firstName, email", "while fetching reviews");
	    while (($row = edb_orow($result)))
		$this->reviewList[$row->paperId][] = $row;
	}

	// prepare PC topic interests
	if (isset($queryOptions["allReviewerPreference"])) {
	    $ord = 0;
	    $pcm = pcMembers();
	    foreach ($pcm as $pc) {
		$pc->prefOrdinal = sprintf("-0.%04d", $ord++);
		$pc->topicInterest = array();
	    }
	    $result = $Conf->qe("select contactId, topicId, interest from TopicInterest", "while finding topic interests");
	    while (($row = edb_row($result)))
		$pcm[$row[0]]->topicInterest[$row[1]] = $row[2];
	}

	if (isset($queryOptions["scores"]))
	    $queryOptions["scores"] = array_keys($queryOptions["scores"]);

	// prepare query text
	if ($me->contactId == $this->search->contactId)
	    $pq = $Conf->paperQuery($me, $queryOptions);
	else
	    $pq = $Conf->paperQuery($this->search->contactId, $queryOptions);

	// make query
	return $Conf->qe($pq, "while selecting papers");
    }

    function _field_is_folded($field) {
	if (is_object($field))
	    $field = $field->foldname;
	return ($field
		&& !defval($_REQUEST, "show$field")
		&& strpos($this->display, " $field ") === false);
    }

    function _row_text($rstate, $row, $fieldDef) {
	global $Conf;

	$rstate->ids[] = $row->paperId;
	$trclass = "k" . $rstate->colorindex;
	if (defval($row, "paperTags") && $this->contact->canViewTags($row)
	    && ($m = $rstate->colorizer->match_all($row->paperTags)))
	    $trclass .= " " . $m;
	$rstate->colorindex = 1 - $rstate->colorindex;
	$rstate->last_trclass = $trclass;

	$this->foldRow = $this->defaultFoldRow;

	// main columns
	$t = "";
	foreach ($fieldDef as $fieldId => $fdef) {
	    if ($fdef->type != 1)
		continue;
	    $td = "    <td class=\"pl_$fdef->cssname";
	    if ($fdef->foldnum)
		$td .= " fx$fdef->foldnum";
	    if ($this->contentEmpty($fieldId, $row)) {
		$t .= $td . "\"></td>\n";
		continue;
	    }
	    if ($fdef->foldname && !isset($rstate->foldinfo[$fdef->foldname]))
		$rstate->foldinfo[$fdef->foldname] = $this->_field_is_folded($fdef);
	    if ($fdef->foldname && $rstate->foldinfo[$fdef->foldname]) {
		$t .= $td . "\" id=\"$fdef->foldname.$row->paperId\"></td>\n";
		$rstate->nonempty_fields[$fieldId] = true;
	    } else {
		$c = $this->content($fieldId, $row);
		$t .= $td . "\">" . $c . "</td>\n";
		if ($c !== "")
		    $rstate->nonempty_fields[$fieldId] = true;
	    }
	}

	// extension columns
	$tt = "";
	foreach ($fieldDef as $fieldId => $fdef) {
	    if ($fdef->type != 2 || $this->contentEmpty($fieldId, $row))
		continue;
	    if ($fdef->foldname	&& !isset($rstate->foldinfo[$fdef->foldname]))
		$rstate->foldinfo[$fdef->foldname] = $this->_field_is_folded($fdef);
	    if ($fdef->foldname && $fdef->foldname != "authors"
		&& $rstate->foldinfo[$fdef->foldname]) {
		$tt .= "<div id=\"$fdef->foldname.$row->paperId\"></div>";
		$rstate->nonempty_fields[$fieldId] = true;
	    } else if (($c = $this->content($fieldId, $row)) !== "") {
		$tt .= "<div id=\"$fdef->foldname.$row->paperId\" class=\"fx";
		if ($fdef->foldname != "authors")
		    $tt .= $fdef->foldnum;
		else if (!$Conf->paperBlind($row, $this->contact)) {
		    $tt .= "1";
		    $rstate->nonempty_fields["au"] = true;
		} else {
		    $tt .= "1 fx2";
		    $rstate->nonempty_fields["anonau"] = true;
		}
		$tt .= "\"><h6>" . $this->header($fieldId, -1, $row)
		    . ":</h6> " . $c . "</div>";
		$rstate->nonempty_fields[$fieldId] = true;
	    }
	}

	if ($this->extraText && isset($this->extraText[$row->paperId]))
	    $tt .= "<div id=\"plextra$row->paperId\" class=\"plextra\">" . $this->extraText[$row->paperId] . "</div>";

	if ($this->foldRow) {
	    $trclass .= " fx3";
	    $rstate->foldinfo["wholerow"] = true;
	}

	$t = "  <tr class=\"pl $trclass\">\n" . $t . "  </tr>\n";

	if ($tt !== "") {
	    $t .= "  <tr class=\"plx $trclass\">";
	    if ($rstate->skipcallout > 0)
		$t .= "<td colspan=\"$rstate->skipcallout\"></td>";
	    $t .= "<td colspan=\"" . ($rstate->ncol - $rstate->skipcallout) . "\">$tt</td></tr>\n";
	}

	return $t;
    }

    function _row_check_heading($rstate, $row, $lastheading, &$body) {
	$heading = defval($this->search->headingmap, $row->paperId, "");
	if ($this->count == 1)
	    $rstate->headingstart = array(0);
	if ($heading != $lastheading) {
	    if ($this->count != 1)
		$rstate->headingstart[] = count($body);
	    if ($heading == "")
		$body[] = "  <tr class=\"pl plheading_blank plheading_middle\"><td class=\"plheading_blank plheading_middle\" colspan=\"$rstate->ncol\"></td></tr>\n";
	    else {
		$middle = ($this->count == 1 ? "" : " plheading_middle");
		$body[] = "  <tr class=\"pl plheading$middle\"><td class=\"plheading$middle\" colspan=\"$rstate->ncol\">" . htmlspecialchars($heading) . "</td></tr>\n";
	    }
	    $rstate->colorindex = 0;
	}
	return $heading;
    }

    function _analyze_folds($rstate, $fieldDef) {
	global $Conf;
	$classes = $jsmap = $jsloadmap = $jsnotitlemap = array();
	foreach ($fieldDef as $fieldId => $fdef)
	    if ($fdef->foldname && $fdef->foldname !== "authors"
		&& isset($rstate->foldinfo[$fdef->foldname])) {
		$closed = $rstate->foldinfo[$fdef->foldname];
		$classes[] = "fold$fdef->foldnum" . ($closed ? "c" : "o");
		$jsmap[] = "\"$fdef->foldname\":$fdef->foldnum";
		if ($closed && isset($rstate->nonempty_fields[$fieldId]))
		    $jsloadmap[] = "\"$fdef->foldname\":true";
		if ($fdef->type == 1)
		    $jsnotitlemap[] = "\"$fdef->foldname\":true";
	    }
	// authorship requires special handling
	if (isset($rstate->nonempty_fields["au"]) || isset($rstate->nonempty_fields["anonau"])) {
	    $classes[] = "fold1" . ($this->_field_is_folded("au") ? "c" : "o");
	    $jsmap[] = "\"au\":1,\"aufull\":16";
	    $jsloadmap[] = "\"aufull\":true";
	}
	if (isset($rstate->nonempty_fields["anonau"])) {
	    $classes[] = "fold2" . ($this->_field_is_folded("anonau") ? "c" : "o");
	    $jsmap[] = "\"anonau\":2";
	}
	// total folding, row number folding
	if (isset($rstate->foldinfo["wholerow"]))
	    $classes[] = "fold3c";
	if ($this->anySelector) {
	    $jsmap[] = "\"rownum\":6";
	    $classes[] = "fold6" . ($this->_field_is_folded("rownum") ? "c" : "o");
	}
	if ($this->contact->privChair) {
	    $jsmap[] = "\"force\":20";
	    $classes[] = "fold20" . (defval($_REQUEST, "forceShow") ? "o" : "c");
	}
	if (count($jsmap))
	    $Conf->footerScript("foldmap.pl={" . join(",", $jsmap) . "};");
	if (count($jsloadmap)) {
	    $Conf->footerScript("plinfo_needload={" . join(",", $jsloadmap) . "};");
	    $this->_addAjaxLoadForm($rstate->ids);
	}
	if (count($jsnotitlemap))
	    $Conf->footerScript("plinfo_notitle={" . join(",", $jsnotitlemap) . "};");
	return $classes;
    }

    function _make_title_header_extra($rstate) {
	global $Conf;
	$titleextra = "";
	if (isset($rstate->foldinfo["wholerow"]))
	    $titleextra .= "<span class='sep'></span><a class='fn3' href=\"javascript:void fold('pl',0,3)\">Show all papers</a>";
	if ((isset($rstate->nonempty_fields["au"]) || isset($rstate->nonempty_fields["anonau"]))
	    && $this->showHeader == self::HEADER_ALL) {
	    $titleextra .= "<span class='sep'></span>";
	    if ($Conf->subBlindNever())
		$titleextra .= "<a class='fn1' href=\"javascript:void fold('pl',0,'au')\">Show authors</a><a class='fx1' href=\"javascript:void fold('pl',1,'au')\">Hide authors</a>";
	    else if ($this->contact->privChair && isset($rstate->nonempty_fields["anonau"]) && !isset($rstate->nonempty_fields["au"]))
		$titleextra .= "<a class='fn1 fn2' href=\"javascript:fold('pl',0,'au');void fold('pl',0,'anonau')\">Show authors</a><a class='fx1 fx2' href=\"javascript:fold('pl',1,'au');void fold('pl',1,'anonau')\">Hide authors</a>";
	    else if ($this->contact->privChair && isset($rstate->nonempty_fields["anonau"]))
		$titleextra .= "<a class='fn1' href=\"javascript:fold('pl',0,'au');void fold('pl',1,'anonau')\">Show non-anonymous authors</a><a class='fx1 fn2' href=\"javascript:void fold('pl',0,'anonau')\">Show all authors</a><a class='fx1 fx2' href=\"javascript:fold('pl',1,'au');void fold('pl',1,'anonau')\">Hide authors</a>";
	    else
		$titleextra .= "<a class='fn1' href=\"javascript:void fold('pl',0,'au')\">Show non-anonymous authors</a><a class='fx1' href=\"javascript:void fold('pl',1,'au')\">Hide authors</a>";
	}
	if (isset($rstate->nonempty_fields[self::FIELD_TAGS])
	    && $this->showHeader == self::HEADER_ALL) {
	    $titleextra .= "<span class='sep'></span>";
	    $titleextra .= "<a class='fn4' href='javascript:void foldplinfo(0,\"tags\")'>Show tags</a><a class='fx4' href='javascript:void foldplinfo(1,\"tags\")'>Hide tags</a><span id='tagsloadformresult'></span>";
	}
	return $titleextra ? "<span class='pl_titleextra'>$titleextra</span>" : "";
    }

    function _column_split($rstate, $colhead, &$body) {
	if (!isset($rstate->headingstart) || count($rstate->headingstart) <= 1)
	    return false;
	$rstate->headingstart[] = count($body);
	$rstate->split_ncol = count($rstate->headingstart) - 1;

	$rownum_marker = "<span class=\"pl_rownum fx6\">";
	$rownum_len = strlen($rownum_marker);
	$nbody = array("<tr>");
	for ($i = 1; $i < count($rstate->headingstart); ++$i) {
	    $nbody[] = "<td class='pl_splitcol top' width='" . (100 / $rstate->split_ncol) . "%'><div class='pl_splitcol'><table width='100%'>";
	    $nbody[] = $colhead . "  <tbody>\n";
	    $number = 1;
	    for ($j = $rstate->headingstart[$i - 1]; $j < $rstate->headingstart[$i]; ++$j) {
		$x = $body[$j];
		if (($pos = strpos($x, $rownum_marker)) !== false) {
		    $pos += strlen($rownum_marker);
		    $x = substr($x, 0, $pos) . preg_replace('/\A\d+/', $number, substr($x, $pos));
		    ++$number;
		} else if (strpos($x, "<tr class=\"pl plheading_blank") !== false)
		    $x = "";
		$nbody[] = $x;
	    }
	    $nbody[] = "  </tbody>\n</table></div></td>\n";
	}
	$nbody[] = "</tr>";

	$body = $nbody;
	$rstate->last_trclass = "k0 pl_splitcol";
	return true;
    }

    function text($listname, $me, $extra_class = "") {
	global $Conf, $ConfSiteBase, $paperListFields, $paperListFolds,
	    $reviewScoreNames;
	require_once("tags.inc"); // for TagColorizer

	$this->contact = $me;
	$this->count = 0;
	$this->accepted = 0;
	$this->needFinalize = 0;
	$this->needFinalCopy = 0;
	$this->needSubmitReview = 0;
	$this->anySelector = false;
	$this->anyFinalPaper = false;
	$this->defaultFoldRow = false;
	$url = $this->search->url();

	// check role type
	$this->scoresOk = $me->privChair || $me->amReviewer()
	    || $Conf->timeAuthorViewReviews();

	// get paper list
	if (!($baseFieldId = $this->_listFields($listname))) {
	    $Conf->errorMsg("There is no paper list query named '" . htmlspecialchars($listname) . "'.");
	    return null;
	}
	if (defval($_REQUEST, "selectall") > 0
	    && $baseFieldId[0] == self::FIELD_SELECTOR)
	    $baseFieldId[0] = self::FIELD_SELECTOR_ON;

	// check paper link destination
	if (isset($_REQUEST["linkto"]) && ($_REQUEST["linkto"] == "paper" || $_REQUEST["linkto"] == "review" || $_REQUEST["linkto"] == "assign"))
	    $this->paperLink = $_REQUEST["linkto"];

	// initialize query
	$queryOptions = array("joins" => array());
	// need tags for row coloring
	if ($this->contact->canViewTags(null))
	    $queryOptions["tags"] = 1;
	if ($this->search->complexSearch($queryOptions)) {
	    if (!($table = $this->search->matchTable()))
		return null;
	    $queryOptions["joins"][] = "join $table on (Paper.paperId=$table.paperId)";
	}

	// paper list query?
	$pidarray = null;
	$specialSortable = false;
	if (count($this->search->orderTags)) {
	    $queryOptions["tagIndex"] = array();
	    foreach ($this->search->orderTags as $x)
		$queryOptions["tagIndex"][] = $x->tag;
	    $specialSortable = self::FIELD_TAGINDEX;
	} else if (($pidarray = $this->search->simplePaperList()) !== null)
	    $specialSortable = self::FIELD_PIDARRAY;
	if ($this->sortField && !in_array($this->sortField, $baseFieldId))
	    $this->sortField = null;
	if (!$this->sortField && $specialSortable)
	    $this->sortField = $specialSortable;

	// get field array
	$fieldDef = array();
	$ncol = 0;
	foreach ($baseFieldId as $fid)
	    if (($fdef = defval($paperListFields, $fid))
		&& $this->selector($fid, $queryOptions, $this->_field_is_folded($fdef))) {
		$fieldDef[$fid] = $fdef;
		if ($fdef->type == 1)
		    $ncol++;
	    }
	$this->aufull = !$this->_field_is_folded("aufull");

	// make query
	$result = $this->_prepareQuery($me, $queryOptions);
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

	// count non-callout columns
	$skipcallout = 0;
	foreach ($fieldDef as $fieldId => $fdef)
	    if ($fieldId > self::FIELD_ID && $fieldId < self::FIELD_SELECTOR)
		break;
	    else
		++$skipcallout;

	// create render state
	$rstate = (object) array();
	$rstate->colorizer = new TagColorizer($this->contact);
	$rstate->ids = array();
	$rstate->nonempty_fields = array();
	$rstate->foldinfo = array();
	$rstate->colorindex = 0;
	$rstate->skipcallout = $skipcallout;
	$rstate->ncol = $ncol;
	$rstate->last_trclass = "";

	// collect row data
	$body = array();
	$lastheading = ($this->search->headingmap === null ? false : "");
	foreach ($srows as $row) {
	    ++$this->count;
	    if ($lastheading !== false)
		$lastheading = $this->_row_check_heading($rstate, $row, $lastheading, $body);
	    $body[] = $this->_row_text($rstate, $row, $fieldDef);
	}

	// columns
	$colhead = "";
	foreach ($fieldDef as $fieldId => $fdef) {
	    if ($fdef->type == 1)
		$colhead .= $this->col($fieldId);
	}

	// header cells
	if ($this->showHeader) {
	    $colhead .= " <thead>\n  <tr class=\"pl_headrow\">\n";
	    $ord = 0;
	    $titleextra = $this->_make_title_header_extra($rstate);

	    if ($this->sortable && $url) {
		$sortUrl = htmlspecialchars($url) . (strpos($url, "?") ? "&amp;" : "?") . "sort=";
		$q = "<a class='pl_sort' title='Change sort' href=\"" . $sortUrl;
	    } else
		$sortUrl = false;

	    foreach ($fieldDef as $fieldId => $fdef) {
		if ($fdef->type != 1)
		    continue;
		if (!isset($rstate->nonempty_fields[$fieldId])) {
		    $colhead .= "    <th class=\"pl_$fdef->cssname\"></th>\n";
		    continue;
		}
		$colhead .= "    <th class=\"pl_$fdef->cssname";
		if ($fdef->foldnum)
		    $colhead .= " fx" . $fdef->foldnum;
		$colhead .= "\">";
		$ftext = $this->header($fieldId, $ord++);

		$sortableSelector = $fieldId >= self::FIELD_SELECTOR
		    && $fieldId <= self::FIELD_LAST_SELECTOR
		    && $sortUrl && $specialSortable;
		if ($sortableSelector && $specialSortable == self::FIELD_TAGINDEX)
		    $ftext = "<span class='hastitle' title='Sort by tag order'>#</span>";
		else if ($sortableSelector && $specialSortable == self::FIELD_PIDARRAY)
		    $ftext = "<span class='hastitle' title='Sort by search term order'>#</span>";

		if ($this->sortField == null && $sortUrl && $fieldId < 10)
		    $this->sortField = $fieldId;
		if ($fieldId == $this->sortField
		    || ($sortableSelector && $this->sortField == $specialSortable))
		    $colhead .= "<a class='pl_sort_def" . ($this->reverseSort ? "_rev" : "") . "' title='Reverse sort' href=\"" . $sortUrl . $this->sortField . ($this->reverseSort ? "N" : "R") . "\">" . $ftext . "</a>";
		else if ($fdef->sortable && $sortUrl)
		    $colhead .= $q . $fieldId . "\">" . $ftext . "</a>";
		else if ($sortableSelector)
		    $colhead .= $q . $specialSortable . "\">" . $ftext . "</a>";
		else
		    $colhead .= $ftext;
		if ($titleextra && $fdef->cssname == "title") {
		    $colhead .= $titleextra;
		    $titleextra = false;
		}
		$colhead .= "</th>\n";
	    }

	    $colhead .= "  </tr>\n"
		. "  <tr><td class='pl_headgap' colspan='$ncol'></td></tr>\n"
		. " </thead>\n";
	}

	// table skeleton including fold classes
	$foldclasses = $this->_analyze_folds($rstate, $fieldDef);
	$enter = "<table class=\"pltable plt_" . htmlspecialchars($listname);
	if ($extra_class != "")
	    $enter .= " " . $extra_class;
	if (count($foldclasses))
	    $enter .= " " . join(" ", $foldclasses) . "\" id=\"foldpl";
	$enter .= "\">\n";
	$exit = "</table>";

	// maybe make columns, maybe not
	if (($this->view == "vc" || $this->view == "vmc") && count($rstate->ids)
	    && $this->_column_split($rstate, $colhead, $body)) {
	    $enter = "<div class='pl_splitcol_ctr_ctr'><div class='pl_splitcol_ctr'>" . $enter;
	    $exit = $exit . "</div></div>";
	    $ncol = $rstate->split_ncol;
	} else
	    $enter .= $colhead;

	reset($fieldDef);
	if (key($fieldDef) == self::FIELD_SELECTOR
	    || key($fieldDef) == self::FIELD_SELECTOR_ON)
	    $enter .= $this->footer($ncol, $listname, $rstate->last_trclass);

	$x = $enter . " <tbody>\n" . join("", $body) . " </tbody>\n" . $exit;

	// session variable to remember the list
	if ($this->listNumber) {
	    $idx = $this->search->decorateSessionList($rstate->ids, $listname, $this->sortdef());
	    if (isset($_REQUEST["sort"]))
		$url .= (strpos($url, "?") ? "&" : "?") . "sort=" . urlencode($_REQUEST["sort"]);
	    if ($ConfSiteBase
		&& substr($url, 0, strlen($ConfSiteBase)) == $ConfSiteBase)
		$url = substr($url, strlen($ConfSiteBase));
	    $idx["url"] = $url;
	    $_SESSION["l"][$this->listNumber] = $idx;
	}

	$this->headerInfo = array(
		"authors" => (isset($rstate->nonempty_fields["anonau"]) ? 2 : 0) | (isset($rstate->nonempty_fields["au"]) ? 1 : 0),
		"tags" => defval($rstate->nonempty_fields, self::FIELD_TAGS, false),
		"abstract" => defval($rstate->nonempty_fields, self::FIELD_OPT_ABSTRACT, false),
		"shepherd" => defval($rstate->nonempty_fields, self::FIELD_SHEPHERD, false),
		"lead" => defval($rstate->nonempty_fields, self::FIELD_LEAD, false),
		"topics" => defval($rstate->nonempty_fields, self::FIELD_OPT_TOPIC_NAMES, false),
		"pcconf" => defval($rstate->nonempty_fields, self::FIELD_OPT_PC_CONFLICTS, false),
		"collab" => defval($rstate->nonempty_fields, self::FIELD_COLLABORATORS, false),
		"pap" => $rstate->ids
	);

	return $x;
    }

    function ajaxColumn($fieldId, $me) {
	global $Conf, $paperListFields;

	$this->contact = $me;
	$this->count = 0;
	$this->accepted = 0;
	$this->needFinalize = 0;
	$this->needFinalCopy = 0;
	$this->needSubmitReview = 0;
	$this->anySelector = false;
	$this->anyFinalPaper = false;
	$url = $this->search->url();

	// check role type
	$this->scoresOk = $me->privChair || $me->amReviewer()
	    || $Conf->timeAuthorViewReviews();

	// check what is folded
	$this->aufull = defval($_REQUEST, "showaufull") || strpos($this->display, " aufull ") !== false;

	// check paper link destination
	if (isset($_REQUEST["linkto"]) && ($_REQUEST["linkto"] == "paper" || $_REQUEST["linkto"] == "review" || $_REQUEST["linkto"] == "assign"))
	    $this->paperLink = $_REQUEST["linkto"];

	// initialize query
	$queryOptions = array("joins" => array());
	if ($this->search->complexSearch($queryOptions)) {
	    if (!($table = $this->search->matchTable()))
		return null;
	    $queryOptions["joins"][] = "join $table on (Paper.paperId=$table.paperId)";
	}

	// get field array
	$ncol = 0;
	$this->unfolded = true;
	if (!($fdef = defval($paperListFields, $fieldId))
	    || !$this->selector($fieldId, $queryOptions, false))
	    return null;
	$unfolded = $this->unfolded;

	// make query
	$result = $this->_prepareQuery($me, $queryOptions);
	if (!$result)
	    return null;

	// collect row data
	$data = array();
	$foldname = $fdef->foldname;
	if (($x = $this->header($fieldId)))
	    $data["$foldname.title"] = $x;
	while (($row = edb_orow($result))) {
	    if ($this->contentEmpty($fieldId, $row))
		$c = "";
	    else
		$c = $this->content($fieldId, $row);
	    $data["$foldname.$row->paperId"] = $c;
	}

	return $data;
    }

}


class PaperListField {
    var $id;
    var $cssname;
    var $foldnum;
    var $foldname;
    var $type;
    var $sortable;
    var $minimal;

    function __construct($id, $cssname, $type, $extra) {
	$this->id = $id;
	$this->cssname = $cssname;
	$this->foldnum = defval($extra, "foldnum", 0);
	$this->foldname = ($this->foldnum ? $cssname : null);
	$this->type = $type;
	$this->sortable = defval($extra, "sortable", false);
	$this->minimal = defval($extra, "minimal", false);
    }

    static function make($id, $cssname, $type, $extra = 0) {
	global $paperListFields, $paperListFolds;
	if ($extra === true)
	    $extra = array("sortable" => true);
	else if (is_int($extra))
	    $extra = array("foldnum" => $extra);
	$f = new PaperListField($id, $cssname, $type, $extra);
	$paperListFields[$id] = $f;
	if ($f->foldnum > 3  /* "authors" == 1 or 2, requires special handling */)
	    $paperListFolds[$f->foldname] = $f;
	return $f;
    }

    static function _formula_compar($a, $b) {
	return strcasecmp($a->name, $b->name);
    }

    static function initialize() {
	global $paperListFields, $paperListFolds, $paperListFormulas,
	    $paperListNormalizer, $reviewScoreNames, $Conf;

	$paperListFolds = array("au" => (object) array("id" => PaperList::FIELD_OPT_AUTHORS, "foldnum" => 1),
				"anonau" => (object) array("id" => PaperList::FIELD_OPT_AUTHORS, "foldnum" => 2),
				"rownum" => (object) array("foldnum" => 6),
				"aufull" => (object) array("foldnum" => 16),
				"force" => (object) array("foldnum" => 20));

	$paperListFields = array();
	PaperListField::make(PaperList::FIELD_SELECTOR, "selector", 1, array("minimal" => true));
	PaperListField::make(PaperList::FIELD_SELECTOR_ON, "selector", 1, array("minimal" => true));
	PaperListField::make(PaperList::FIELD_SELECTOR_CONFLICT, "confselector", 1);
	PaperListField::make(PaperList::FIELD_ID, "id", 1, array("minimal" => true, "sortable" => true));
	PaperListField::make(PaperList::FIELD_TITLE, "title", 1, array("minimal" => true, "sortable" => true));
	PaperListField::make(PaperList::FIELD_STATUS, "status", 1, true);
	PaperListField::make(PaperList::FIELD_REVIEWER, "reviewer", 1, true);
	PaperListField::make(PaperList::FIELD_REVIEWER_TYPE, "reviewer", 1, true);
	PaperListField::make(PaperList::FIELD_REVIEWER_TYPE_ICON, "revtypei", 1, true);
	PaperListField::make(PaperList::FIELD_REVIEWER_STATUS, "reviewer", 1);
	PaperListField::make(PaperList::FIELD_REVIEWER_MONITOR, "reviewer", 1, true);
	PaperListField::make(PaperList::FIELD_STATUS_SHORT, "status", 1, true);
	PaperListField::make(PaperList::FIELD_ASSIGN_REVIEW, "assrev", 1, true);
	PaperListField::make(PaperList::FIELD_TOPIC_INTEREST, "topicMatch", 1, true);
	PaperListField::make(PaperList::FIELD_TOPIC_NAMES, "topics", 2);
	PaperListField::make(PaperList::FIELD_OPT_TOPIC_NAMES, "topics", 2, 13);
	PaperListField::make(PaperList::FIELD_ALL_REVIEWER_NAMES, "reviewers", 2);
	PaperListField::make(PaperList::FIELD_REVIEWER_PREFERENCE, "revpref", 1, true);
	PaperListField::make(PaperList::FIELD_EDIT_REVIEWER_PREFERENCE, "editrevpref", 1, true);
	PaperListField::make(PaperList::FIELD_CONFLICT_CONFIRM, "conflictconfirm", 1);
	PaperListField::make(PaperList::FIELD_REVIEWS_STATUS, "revstat", 1, true);
	PaperListField::make(PaperList::FIELD_DESIRABILITY, "desirability", 1, true);
	PaperListField::make(PaperList::FIELD_ALL_PREFERENCES, "allPreferences", 2);
	PaperListField::make(PaperList::FIELD_SCORE, "score", 1, array("minimal" => true, "sortable" => true));
	PaperListField::make(PaperList::FIELD_FORMULA, "formula", 1, array("minimal" => true, "sortable" => true));
	PaperListField::make(PaperList::FIELD_AUTHOR_MATCH, "authorsMatch", 2);
	PaperListField::make(PaperList::FIELD_COLLABORATORS_MATCH, "collaboratorsMatch", 2);
	PaperListField::make(PaperList::FIELD_OPT_AUTHORS, "authors", 2, 3);
	PaperListField::make(PaperList::FIELD_TAGS, "tags", 2, 4);
	PaperListField::make(PaperList::FIELD_OPT_ABSTRACT, "abstract", 2, 5);
	PaperListField::make(PaperList::FIELD_OPT_ALL_REVIEWER_NAMES, "reviewers", 2, 10);
	PaperListField::make(PaperList::FIELD_LEAD, "lead", 2, 12);
	PaperListField::make(PaperList::FIELD_SHEPHERD, "shepherd", 2, 11);
	PaperListField::make(PaperList::FIELD_OPT_PC_CONFLICTS, "pcconf", 2, 14);
	PaperListField::make(PaperList::FIELD_COLLABORATORS, "collab", 2, 15);

	$paperListNormalizer = array();
	$nextfield = PaperList::FIELD_SCORE;
	foreach ($reviewScoreNames as $k => $n) {
	    $fdef = PaperListField::make($nextfield, $n, 1, array("minimal" => true, "sortable" => true, "foldnum" => $nextfield));
	    $fdef->cssname = "score";
	    $paperListNormalizer[$nextfield] = array(PaperList::FIELD_SCORE, $n);
	    ++$nextfield;
	}

	$nextfold = 21;
	$paperListFormulas = array();
	if ($Conf && $Conf->setting("formulas") && $Conf->sversion >= 32) {
	    $result = $Conf->q("select * from Formula");
	    while (($row = edb_orow($result))) {
		$fid = $row->formulaId;
		$fdef = PaperListField::make($nextfield, "formula$fid", 1, array("minimal" => true, "sortable" => true, "foldnum" => $nextfold));
		$fdef->cssname = "formula";
		$row->fieldId = $nextfield;
		$paperListFormulas[$fid] = $row;
		$paperListNormalizer[$nextfield] = array(PaperList::FIELD_FORMULA, $row);
		++$nextfold;
		++$nextfield;
	    }
	    uasort($paperListFormulas, array("PaperListField", "_formula_compar"));
	}

	if ($Conf && ($Conf->setting("tag_vote") || $Conf->setting("tag_rank"))) {
	    require_once("tags.inc");
	    $vt = voteTags(rankTags());
	    ksort($vt);
	    foreach ($vt as $n => $t) {
		$fdef = PaperListField::make($nextfield, "tagrep_" . preg_replace('/\W+/', '_', $n), 2, $nextfold);
		$fdef->cssname = "tagrep";
		$paperListNormalizer[$nextfield] = array(PaperList::FIELD_TAGREPORT, $n);
		++$nextfold;
		++$nextfield;
	    }
	}
    }

}

PaperListField::initialize();
