<?php

class PaperList {

    const FIELD_ID = 1;
    const FIELD_ID_MANAGE = 2;
    const FIELD_ID_REVIEW = 3;
    const FIELD_TITLE = 11;
    const FIELD_TITLE_MANAGE = 12;
    const FIELD_TITLE_REVIEW = 13;
    const FIELD_STATUS = 27;
    const FIELD_DOWNLOAD = 28;
    const FIELD_REVIEWER_TYPE = 29;
    const FIELD_REVIEWER_STATUS = 30;
    const FIELD_SELECTOR = 31;
    const FIELD_REVIEW = 32;
    const FIELD_STATUS_SHORT = 33;
    const FIELD_REVIEWER_NAME = 34;
    const FIELD_ASSIGN_REVIEW = 35;
    const FIELD_TOPIC_INTEREST = 36;
    const FIELD_TOPIC_NAMES = 37;
    const FIELD_ALL_REVIEWER_NAMES = 38;
    const FIELD_REVIEWER_PREFERENCE = 39;
    const FIELD_EDIT_REVIEWER_PREFERENCE = 40;
    const FIELD_REVIEWS_STATUS = 41;
    const FIELD_MATCHES = 42;
    const FIELD_DESIRABILITY = 43;
    const FIELD_ALL_PREFERENCES = 44;
    const FIELD_REVIEWER_TYPE_ICON = 45;
    const FIELD_OPT_MERIT_ICON = 46;
    const FIELD_AUTHOR_MATCH = 47;
    const FIELD_COLLABORATORS_MATCH = 48;
    
    var $showHeader;
    var $sortField;
    var $reverseSort;
    var $sortable;
    var $count;
    var $needFinalize;
    var $needSubmitReview;
    var $anySelector;
    var $description;
    var $sessionVar;
    var $paperLink;
    var $contact;
    var $scoresOk;
    var $meritMax;
    var $search;
    var $authorMatch;
    var $collaboratorsMatch;
    var $extraText;
    
    function PaperList($sortable = true, $sessionVar = null, $search = null, $extraText = null) {
	$this->showHeader = true;

	$s = ($sortable ? defval($_REQUEST["sort"], "") : "");
	$x = (strlen($s) ? $s[strlen($s)-1] : "");
	$this->reverseSort = ($x == "R");
	if ($x == "R" || $x == "N")
	    $sortVal = substr($s, 0, -1);
	if ("x" . (int) $s == "x" . $s)
	    $this->sortField = (int) $s;
	else
	    $this->sortField = null;
	$this->sortable = $sortable;

	$this->sessionVar = $sessionVar;
	$this->paperLink = "";
	if ($this->sessionVar && $this->sessionVar != "list")
	    $this->paperLink = "&amp;whichList=" . $this->sessionVar;

	$this->search = $search;
	$this->extraText = $extraText;
    }

    function selector($fieldId, &$queryOptions) {
	switch ($fieldId) {
	case self::FIELD_REVIEWER_NAME:
	    $queryOptions['reviewerName'] = 1;
	    break;
	case self::FIELD_TOPIC_INTEREST:
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
	    break;
	case self::FIELD_DESIRABILITY:
	    $queryOptions['desirability'] = 1;
	    break;
	case self::FIELD_ALL_PREFERENCES:
	    $queryOptions['allReviewerPreference'] = 1;
	    break;
	case self::FIELD_OPT_MERIT_ICON:
	    if (!$this->scoresOk)
		return false;
	    $queryOptions['overallMeritScores'] = 1;
	    $rf = reviewForm();
	    $this->meritMax = $rf->maxNumericScore('overAllMerit');
	    break;
	}
	if ($this->sortField == $fieldId && ($order = $this->sort($fieldId)))
	    $queryOptions['order'] = $order;
	return true;
    }

    function sort($fieldId) {
	$up = ($this->reverseSort ? "desc" : "asc");
	$down = ($this->reverseSort ? "asc" : "desc");
	switch ($fieldId) {
	case self::FIELD_ID:
	case self::FIELD_ID_MANAGE:
	case self::FIELD_ID_REVIEW:
	    return "order by Paper.paperId $up";
	case self::FIELD_TITLE:
	case self::FIELD_TITLE_MANAGE:
	case self::FIELD_TITLE_REVIEW:
	    return "order by Paper.title $up";
	case self::FIELD_STATUS:
	case self::FIELD_STATUS_SHORT:
	    return "order by Paper.outcome $down, (Paper.timeWithdrawn > 0) $up, (Paper.timeSubmitted > 0) $down, (paperStorageId > 1) $down, Paper.paperId $up";
	case self::FIELD_REVIEWER_TYPE:
	case self::FIELD_REVIEWER_TYPE_ICON:
	    return "order by PaperReview.reviewType $down";
	case self::FIELD_REVIEWER_NAME:
	    return "order by ReviewerContactInfo.lastName $up, ReviewerContactInfo.firstName $up, ReviewerContactInfo.email $up";
	case self::FIELD_REVIEWS_STATUS:
	    return "order by reviewCount $up, startedReviewCount $up";
	case self::FIELD_ASSIGN_REVIEW:
	    return "order by PaperReview.reviewType $down, Paper.paperId $up";
	case self::FIELD_TOPIC_INTEREST:
	    return "order by topicInterestScore $down, Paper.paperId $up";
	case self::FIELD_REVIEWER_PREFERENCE:
	case self::FIELD_EDIT_REVIEWER_PREFERENCE:
	    return "order by reviewerPreference $down";
	case self::FIELD_DESIRABILITY:
	    return "order by desirability $down";
	case self::FIELD_OPT_MERIT_ICON:
	    return "order by overallMeritAvg $down";
	default:
	    return null;
	}
    }
    
    function header($fieldId, $ordinal, $row = null, $reviewList = null) {
	switch ($fieldId) {
	case self::FIELD_ID:
	case self::FIELD_ID_MANAGE:
	case self::FIELD_ID_REVIEW:
	    return "ID";
	case self::FIELD_TITLE:
	case self::FIELD_TITLE_MANAGE:
	case self::FIELD_TITLE_REVIEW:
	    return "Title";
	case self::FIELD_STATUS:
	case self::FIELD_STATUS_SHORT:
	    return "Status";
	case self::FIELD_DOWNLOAD:
	    return "";
	case self::FIELD_REVIEWER_TYPE:
	    return "Review";
	case self::FIELD_REVIEWER_TYPE_ICON:
	    return "<acronym title='Reviewer type'>Review</acronym>";
	case self::FIELD_REVIEWER_STATUS:
	    return "Reviewer status";
	case self::FIELD_REVIEWS_STATUS:
	    return "<acronym title='\"1/2\" means 1 complete review out of 2 assigned reviews'>#&nbsp;Reviews</acronym>";
	case self::FIELD_SELECTOR:
	    return ($ordinal ? "&nbsp;" : "");
	case self::FIELD_REVIEW:
	    return "&nbsp;";
	case self::FIELD_REVIEWER_NAME:
	    return "Reviewer name";
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
	case self::FIELD_OPT_MERIT_ICON: {
	    $rf = reviewForm();
	    return $rf->webFieldAbbrev("overAllMerit");
	}
	case self::FIELD_AUTHOR_MATCH:
	    return "Author matches";
	case self::FIELD_COLLABORATORS_MATCH:
	    return "Collaborator matches";
	default:
	    return "&lt;$fieldId&gt;?";
	}
    }

    function _contentDownload($row) {
	global $ConfSiteBase, $Conf;
	if ($row->size == 0)
	    return "";
	else if ($row->mimetype == "application/pdf")
	    return "&nbsp;<a href=\"" . $Conf->makeDownloadPath($row->paperId, $row->mimetype) . "\"><img src=\"${ConfSiteBase}images/pdf.png\" alt='[PDF]' /></a>";
	else if ($row->mimetype == "application/postscript")
	    return "&nbsp;<a href=\"" . $Conf->makeDownloadPath($row->paperId, $row->mimetype) . "\"><img src=\"${ConfSiteBase}images/postscript.png\" alt='[PS]' /></a>";
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
	return ($out ? htmlspecialchars(substr($out, 0, strlen($out) - 1)) : "");
    }
    
    function content($fieldId, $row, $plist, $reviewList) {
	global $ConfSiteBase, $Conf, $reviewTypeName;
	switch ($fieldId) {
	case self::FIELD_ID:
	    return "<a href='${ConfSiteBase}paper.php?paperId=$row->paperId" . $this->paperLink . "' tabindex='2'>#$row->paperId</a>";
	case self::FIELD_ID_MANAGE:
	    return "<a href='${ConfSiteBase}paper.php?paperId=$row->paperId" . $this->paperLink . "' tabindex='2'>#$row->paperId</a>";
	case self::FIELD_ID_REVIEW:
	    return "<a href='${ConfSiteBase}review.php?paperId=$row->paperId" . $this->paperLink . "' tabindex='2'>#$row->paperId</a>";
	case self::FIELD_TITLE:
	    return "<a href='${ConfSiteBase}paper.php?paperId=$row->paperId" . $this->paperLink . "' tabindex='3'>" . htmlspecialchars($row->title) . "</a>" . $this->_contentDownload($row);
	case self::FIELD_TITLE_MANAGE:
	    return "<a href='${ConfSiteBase}paper.php?paperId=$row->paperId" . $this->paperLink . "' tabindex='3'>" . htmlspecialchars($row->title) . "</a>" . $this->_contentDownload($row);
	case self::FIELD_TITLE_REVIEW:
	    return "<a href='${ConfSiteBase}review.php?paperId=$row->paperId" . $this->paperLink . "' tabindex='3'>" . htmlspecialchars($row->title) . "</a>" . $this->_contentDownload($row);
	case self::FIELD_STATUS:
	case self::FIELD_STATUS_SHORT:
	    if ($row->timeSubmitted <= 0 && $row->timeWithdrawn <= 0)
		$this->needFinalize++;
	    return $this->contact->paperStatus($row->paperId, $row, $fieldId == self::FIELD_STATUS ? 0 : -1);
	case self::FIELD_DOWNLOAD:
	    if ($row->size == 0)
		return "";
	    else if ($row->mimetype == "application/pdf")
		return "<a href=\"" . $Conf->makeDownloadPath($row->paperId, $row->mimetype) . "\"><img src=\"${ConfSiteBase}images/pdf.png\" alt='[PDF]' /></a>";
	    else if ($row->mimetype == "application/postscript")
		return "<a href=\"" . $Conf->makeDownloadPath($row->paperId, $row->mimetype) . "\"><img src=\"${ConfSiteBase}images/postscript.png\" alt='[PS]' /></a>";
	    else
		return "<a href=\"" . $Conf->makeDownloadPath($row->paperId, $row->mimetype) . "\">Paper</a>";
	case self::FIELD_REVIEWER_TYPE:
	    if ($row->reviewType > 0 && (!isset($row->reviewSubmitted) || !$row->reviewSubmitted))
		$this->needSubmitReview++;
	    if ($row->reviewId) {
		$a1 = "<a href=\"${ConfSiteBase}review.php?reviewId=$row->reviewId" . $this->paperLink . "\">";
		$a2 = "</a>&nbsp;";
		if ($row->reviewSubmitted > 0)
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
	    else if ($row->reviewType == REVIEW_REQUESTED)
		return "$a1<span class='rtype rtype_req'>Requested</span>$a2";
	    else if ($row->reviewType)
		return "$a1<span class='rtype'>Review</span>$a2";
	    else if ($row->conflictType == CONFLICT_AUTHOR)
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
		$a1 = "<a href=\"${ConfSiteBase}review.php?reviewId=$row->reviewId" . $this->paperLink . "\">";
		$a2 = "</a>";
	    }
	    if ($row->conflictType > 0) {
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
	    return "$a1<img src='${ConfSiteBase}images/ass$which.png' alt='$alt' title='$alt' />$a2";
	case self::FIELD_REVIEWER_STATUS:
	    return reviewStatus($row, 0);
	case self::FIELD_REVIEWS_STATUS:
	    if ($row->reviewCount != $row->startedReviewCount)
		return "<b>$row->reviewCount</b>/$row->startedReviewCount";
	    else
		return "<b>$row->reviewCount</b>";
	case self::FIELD_SELECTOR:
	    $this->anySelector = 1;
	    return "<input type='checkbox' name='pap[]' value='$row->paperId' tabindex='1' id='psel$this->count' onclick='pselClick(event, this, $this->count)' />";
	case self::FIELD_REVIEW:
	    return reviewButton($row->paperId, $row);
	case self::FIELD_REVIEWER_NAME:
	    return contactHtml($row->reviewFirstName, $row->reviewLastName, $row->reviewEmail);
	case self::FIELD_ASSIGN_REVIEW:
	    if ($row->conflictType == CONFLICT_AUTHOR)
		return "<span class='author'>Author</span>";
	    $rt = ($row->conflictType > 0 ? -1 : min(max($row->reviewType, 0), REVIEW_PRIMARY));
	    $x = "<select name='assrev$row->paperId' tabindex='1' onchange='highlightUpdate()'>";
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
	    return join(", ", $rf->webTopicArray($row->topicIds, defval($row->topicInterest)));
	case self::FIELD_ALL_REVIEWER_NAMES:
	    $n = "";
	    if (isset($reviewList[$row->paperId]))
		foreach ($reviewList[$row->paperId] as $xrow)
		    if ($xrow->lastName) {
			$n .= ($n ? ", " : "");
			$n .= contactHtml($xrow->firstName, $xrow->lastName);
			if ($xrow->reviewType == REVIEW_PRIMARY)
			    $n .= "&nbsp;<img src='${ConfSiteBase}images/ass" . REVIEW_PRIMARY . ".png' alt='Primary' />";
			else if ($xrow->reviewType == REVIEW_SECONDARY)
			    $n .= "&nbsp;<img src='${ConfSiteBase}images/ass" . REVIEW_SECONDARY . ".png' alt='Secondary' />";
		    }
	    return $n;
	case self::FIELD_REVIEWER_PREFERENCE:
	    return (isset($row->reviewerPreference) ? htmlspecialchars($row->reviewerPreference) : "0");
	case self::FIELD_EDIT_REVIEWER_PREFERENCE:
	    if ($row->conflictType > 0)
		return "N/A";
	    $x = (isset($row->reviewerPreference) ? htmlspecialchars($row->reviewerPreference) : "0");
	    return "<input class='textlite revpref' type='text' size='4' name='revpref$row->paperId' value=\"$x\" />";
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
	case self::FIELD_OPT_MERIT_ICON:
	    return $Conf->textValuesGraph($row->overallMeritScores, $this->meritMax, 1);
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
	default:
	    return "";
	}
    }

    function updatePaperLists() {
	global $Conf;
	$t = defval($Conf->settings["paperlist_update"]);
	if (!isset($_SESSION["paperLists"]) || $t <= 0
	    || $_SESSION["paperLists"]["date"] <= $t) {
	    $pl = array();
	    $result = $Conf->qe("select * from PaperList");
	    while (($row = edb_orow($result)))
		$pl[$row->paperListName] = $row;
	    $pl["date"] = $t;
	    $_SESSION["paperLists"] = $pl;
	}
    }

    function listContact($listname) {
	self::updatePaperLists();
	if (($plist = defval($_SESSION["paperLists"][$listname])))
	    return $plist->listContact;
	else
	    return '';
    }

    function listContactType($listname) {
	self::updatePaperLists();
	if (($plist = defval($_SESSION["paperLists"][$listname])))
	    return $plist->listContactType;
	else
	    return '';
    }

    function footer($ncol) {
	global $Conf, $ConfSiteBase;
	if ($this->count == 0)
	    return "";
	$t = "  <tr class='pl_footgap k" . (($this->count - 1) % 2) . "'><td class='pl_blank' colspan='$ncol'></td></tr>\n";
	$t .= "  <tr class='pl_footrow'>\n    <td class='pl_selector' style='vertical-align: top'><img src=\"${ConfSiteBase}images/bendulft.png\" style='position: relative; top: 11px' /></td>\n";
	$fclass = (defval($_SESSION["plactFold"], 1) ? "folded" : "unfolded");
	$t .= "    <td id='foldplact' class='$fclass' colspan='" . ($ncol - 1) . "'><table>
  <tr><td class='xcaption'><b>Select papers</b> (or <a href='javascript:void checkPapersel(true)'>select all " . $this->count . "</a>), then <b>Get:</b></td>
    <td><select name='getaction'>
    <option value='paper' selected='selected'>Papers</option>
    <option value='revform'>Review forms</option>\n";
	if ($this->contact->isPC && $Conf->timeSubmitFinalPaper())
	    $t .= "    <option value='final'>Final papers</option>\n";
	if ($this->contact->amAssistant())
	    $t .= "    <option value='rev'>All reviews</option>\n";
	else if ($this->contact->isPC && $Conf->timePCViewAllReviews())
	    $t .= "    <option value='rev'>All reviews (no conflicts)</option>\n";
	if ($this->contact->amAssistant() || ($this->contact->isReviewer && $Conf->blindSubmission() < 2))
	    $t .= "    <option value='authors'>Authors (text)</option>\n";
	if ($this->contact->amAssistant()) {
	    $t .= "    <option value='contact'>Contact emails (text)</option>\n";
	    $t .= "    <option value='pcconflicts'>PC conflicts (text)</option>\n";
	}
	if ($this->contact->isPC)
	    $t .= "    <option value='topics'>Topics (text)</option>\n";
	if ($this->contact->amAssistant() || ($this->contact->isPC && $Conf->timePCViewAllReviews()))
	    $t .= "    <option value='scores'>All scores (text)</option>\n";
	$t .= "</select>&nbsp; <input type='submit' class='button_small' name='get' value='Go' />";

	if ($this->contact->isPC) {
	    $t .= "<span class='sep'></span> <a class='unfolder' href='javascript:fold(\"plact\", 0);foldsession(\"plact\", \"plactFold\")'>More &raquo;</a>";
	    $t .= "<a class='folder' href='javascript:fold(\"plact\", 1);foldsession(\"plact\", \"plactFold\")'>Less &laquo;</a>";
	    $t .= "<img id='plactFold' alt='' src='${ConfSiteBase}sessionvar.php?var=plactFold&amp;val=" . defval($_SESSION["plactFold"], 1) . "&amp;cache=1' width='1' height='1' />";
	    $t .= "</td></tr>\n";

	    $t .= "  <tr class='extension'><td class='rcaption'>Tags:</td><td><select name='tagtype'>
    <option value='a' selected='selected'>Add</option><option value='d'>Remove</option><option value='s'>Define</option><option value='so'>Define ordered</option><option value='ao'>Add ordered</option>
  </select> &nbsp;tag(s) &nbsp;<input class='textlite' type='text' name='tag' value='' size='15' /> &nbsp;<input type='submit' class='button_small' name='tagact' value='Go' /></td></tr>\n";
	    
	    if ($this->contact->amAssistant())
		$t .= "  <tr class='extension'><td class='rcaption'>Decision:</td><td>Set decision to&nbsp; " . outcomeSelector() . " &nbsp;<input type='submit' class='button_small' name='setoutcome' value='Go' /></td></tr>\n";
	    
	    if ($this->contact->amAssistant()) {
		$t .= "  <tr class='extension'><td class='rcaption'>Conflicts &amp; assignments:</td>
    <td><select name='marktype' onchange='document.getElementById(\"markpc\").disabled = (this.value == \"pcpaper\" || this.value == \"unpcpaper\")'>
      <option value='conflict' selected='selected'>Mark conflict</option>
      <option value='unconflict'>Remove conflict</option>
      <option value='-1' disabled='disabled'></option>
      <option value='pcpaper'>Mark as PC-authored</option>
      <option value='unpcpaper'>Mark as not PC-authored</option>
      <option value='-1' disabled='disabled'></option>
      <option value='assign" . REVIEW_PRIMARY . "'>Assign primary review</option>
      <option value='assign" . REVIEW_SECONDARY . "'>Assign secondary review</option>
      <option value='assign0'>Unassign reviews</option>
    </select> &nbsp;for &nbsp;";
		$t .= "<select name='markpc' id='markpc'>\n";
		$pc = pcMembers();
		foreach ($pc as $id => $row)
		    $t .= "<option value=\"" . htmlspecialchars($row->email) . "\">" . contactHtml($row->firstName, $row->lastName) . "</option>\n";
		$t .= "</select> &nbsp;<input type='submit' class='button_small' name='setassign' value='Go' />";
		$t .= "</td></tr>\n";
	    }
	} else
	    $t .= "</td></tr>";
	
	return $t . "</table></td></tr>\n";
    }

    function text($listname, $me, $listDescription) {
	global $Conf, $ConfSiteBase;

	$this->contact = $me;
	$this->count = 0;
	$this->needFinalize = 0;
	$this->needSubmitReview = 0;
	$this->anySelector = 0;
	$url = $this->search->url();

	// get paper list id
	self::updatePaperLists();
	if (!($plist = defval($_SESSION["paperLists"][$listname]))) {
	    $Conf->errorMsg("There is no paper list query named '" . htmlspecialchars($listname) . "'.");
	    return NULL;
	}
	$this->description = $plist->description;

	// check role type
	$this->scoresOk = $me->amAssistant() || ($me->isPC && $Conf->timePCViewGrades());

	// initialize query
	$queryOptions = array();
	if ($this->search->complexSearch($queryOptions)) {
	    if (!$this->search->search())
		return NULL;
	    $queryOptions["joins"] = array("Matches on (Paper.paperId=Matches.paperId)");
	}

	// paper list query?
	$pidarray = null;
	if ($this->sortField === null) {
	    $pidarray = $this->search->simplePaperList();
	    if ($this->search->orderTag) {
		if (!isset($queryOptions['joins']))
		    $queryOptions['joins'] = array();
		$queryOptions['joins'][] = "PaperTag on (Paper.paperId=PaperTag.paperId and PaperTag.tag='" . sqlq($this->search->orderTag) . "')";
		$queryOptions['order'] = "order by PaperTag.tagIndex, Paper.paperId";
	    }
	}
	    
	// get field array
	$result = $Conf->qe(
		"select PaperFields.fieldId, fieldName, display
		from PaperListColumns join PaperFields using (fieldId)
		where PaperListColumns.paperListId=$plist->paperListId
		order by PaperListColumns.col", "while looking up paper list information");
	if (!$result)
	    return NULL;
	
	$fieldId = array();
	$ncol = 0;
	while ($row = edb_row($result)) {
	    if ($this->selector($row[0], $queryOptions) === false)
		continue;
	    $fieldId[] = array($row[0], htmlspecialchars($row[1]), $row[2]);
	    if ($row[2] == 1)
		$ncol++;
	}

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

	if (edb_nrows($result) == 0)
	    return "No papers";

	$ids = array();
	$anyData = array();
	$byPaperId = array();
	$body = '';
	while (($row = edb_orow($result))) {
	    $ids[] = $row->paperId;
	    $trclass = "k" . ($this->count % 2);
	    $this->count++;
	    
	    $t = "  <tr class=\"pl " . $trclass . "\">\n";
	    foreach ($fieldId as $f)
		if ($f[2] == 1) {
		    $c = $this->content($f[0], $row, $plist, $reviewList);
		    $t .= "    <td class=\"pl_$f[1]\">$c</td>\n";
		    if ($c != "")
			$anyData[$f[0]] = 1;
		}
	    $t .= "  </tr>\n";
	    
	    foreach ($fieldId as $f)
		if ($f[2] == 2 && ($d = $this->content($f[0], $row, $plist, $reviewList))) {
		    $t .= "  <tr class=\"pl_$f[1] pl_callout $trclass\">\n";
		    $t .= "<td class=\"pl_$f[1] pl_callout\" colspan='$ncol'>";
		    $t .= "<span class='pl_callouthdr'>" . $this->header($f[0], -1, $row, $reviewList) . ":</span>";
		    $t .= $d . "</td>\n  </tr>\n";
		}

	    if ($this->extraText && isset($this->extraText[$row->paperId]))
		$t .= "  <tr class=\"pl_extra pl_callout $trclass\">\n"
		    . "<td class=\"pl_extra pl_callout\" colspan='$ncol'>"
		    . $this->extraText[$row->paperId]
		    . "</td>\n  </tr>\n";

	    if ($pidarray === null)
		$body .= $t;
	    else
		$byPaperId[$row->paperId] = $t;
	}

	// collect paper IDs by order user specified
	if ($pidarray !== null)
	    foreach ($pidarray as $pid)
		if (isset($byPaperId[$pid]))
		    $body .= $byPaperId[$pid];
	
	$x = "<table class=\"pltable plt_" . htmlspecialchars($listname) . "\">\n";

	if ($this->showHeader) {
	    $x .= "  <tr class=\"pl_headrow\">\n";
	    $ord = 0;

	    if ($this->sortable && $url) {
		$sortUrl = $ConfSiteBase . $url . (strpos($url, "?") ? "&" : "?") . "sort=";
		$q = "<a class='pl_sort' href=\"" . $sortUrl;
		foreach ($fieldId as $f) {
		    if ($f[2] != 1)
			continue;
		    else if (!isset($anyData[$f[0]])) {
			$x .= "    <th class=\"pl_$f[1]\"></th>\n";
			continue;
		    }
		    $x .= "    <th class=\"pl_$f[1]\">";
		    $ftext = $this->header($f[0], $ord++);
		    if ($this->sortField == null && $f[0] < 10
			&& $pidarray === null && !$this->search->orderTag)
			$this->sortField = $f[0];
		    if ($f[0] == $this->sortField)
			$x .= "<a class='pl_sort_def" . ($this->reverseSort ? "_rev" : "") . "' href=\"" . $sortUrl . $f[0] . ($this->reverseSort ? "N" : "R") . "\">" . $ftext . "</a>";
		    else if ($this->sort($f[0]))
			$x .= $q . $f[0] . "\">" . $ftext . "</a>";
		    else
			$x .= $ftext;
		    $x .= "</th>\n";
		}
		
	    } else {
		foreach ($fieldId as $f)
		    if ($f[2] == 1 && isset($anyData[$f[0]]))
			$x .= "    <th class=\"pl_$f[1]\">"
			    . $this->header($f[0], $ord++) . "</th>\n";
		    else if ($f[2] == 1)
			$x .= "    <th class=\"pl_$f[1]\"></th>\n";
	    }
	    
	    $x .= "  </tr>\n";
	    $x .= "  <tr><td class='pl_headgap' colspan='$ncol'></td></tr>\n";
	}

	$x .= $body;
	if ($fieldId[0][0] == self::FIELD_SELECTOR)
	    $x .= $this->footer($ncol);
	$x .= "</table>";

	// session variable to remember the list
	if ($this->sessionVar) {
	    if (isset($_REQUEST["sort"]))
		$url .= (strpos($url, "?") ? "&" : "?") . "sort=" . urlencode($_REQUEST["sort"]);
	    $_SESSION[$this->sessionVar] = $ids;
	    $_SESSION[$this->sessionVar . "!"] = array($ConfSiteBase . $url, $listDescription);
	}
	
	return $x;
    }

}
