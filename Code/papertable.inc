<?php 
// papertable.inc -- HotCRP helper class for producing paper tables
// HotCRP is Copyright (c) 2006-2007 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

$textAreaRows = array("title" => 1, "abstract" => 12, "authorInformation" => 5,
		      "collaborators" => 6);

class PaperTable {

    const OPTIONAL = 2;
    const FINALCOPY = 4;
    const ENABLESUBMIT = 8;
    
    const STATUS_DATE = 16;
    const STATUS_CONFLICTINFO = 64;
    const STATUS_CONFLICTINFO_PC = 128;
    const STATUS_REVIEWERINFO = 256;

    var $prow;
    var $rrows;
    var $crows;
    var $rrow;
    var $editrrow;
    var $mode;

    var $editable;
    var $useRequest;
    var $allFolded;
    var $foldState;
    var $highlight;
    var $matchPreg;
    var $watchCheckbox;
    var $initial;
    var $entryMatches;

    function PaperTable($prow) {
	global $Conf, $Me;

	$this->prow = $prow;
	$this->rrows = null;
	$this->crows = null;
	$this->rrow = null;
	$this->editrrow = null;

	if ($this->prow == null) {
	    $this->mode = "pe";
	    return;
	}

	$ms = array();
	if (isset($_REQUEST["reviewId"]))
	    $ms["re"] = true;
	if ($Me->canViewReview($prow, null, $Conf))
	    $ms["r"] = true;
	if ($Me->canReview($prow, null, $Conf))
	    $ms["re"] = true;
	if ($prow->conflictType >= CONFLICT_AUTHOR
	    && $Conf->timeFinalizePaper($prow))
	    $ms["pe"] = true;
	if ($Me->canViewPaper($prow, $Conf))
	    $ms["p"] = true;
	if ($prow->conflictType >= CONFLICT_AUTHOR
	    || $Me->actChair($prow, true))
	    $ms["pe"] = true;
	if ($prow->myReviewType >= REVIEW_SECONDARY
	    || $Me->actChair($prow, true))
	    $ms["assign"] = true;
	if (isset($_REQUEST["mode"]) && isset($ms[$_REQUEST["mode"]]))
	    $this->mode = $_REQUEST["mode"];
	else if (isset($_REQUEST["m"]) && isset($ms[$_REQUEST["m"]]))
	    $this->mode = $_REQUEST["m"];
	else
	    $this->mode = key($ms);
    }

    function initialize($editable, $useRequest, $allFolded,
			$foldsession = null) {
	global $thisMatchPreg, $CurrentList;
	
	$this->editable = $editable;
	$this->useRequest = $useRequest;

	$this->foldState = 15;
	if ($foldsession && !defval($_SESSION, "fold${foldsession}a", 1))
	    $this->foldState &= ~1;
	if ($foldsession && !defval($_SESSION, "foldpaperp", 1))
	    $this->foldState &= ~2;
	if ($foldsession && !defval($_SESSION, "foldpaperb", 1))
	    $this->foldState &= ~4;
	if ($foldsession && !defval($_SESSION, "foldpapert", 1))
	    $this->foldState &= ~8;
	
	$this->allFolded = $allFolded;

	$this->matchPreg = array();
	$matcher = null;
	if (isset($thisMatchPreg) && $thisMatchPreg !== ""
	    && (!is_array($thisMatchPreg) || count($thisMatchPreg) > 0))
	    $matcher = $thisMatchPreg;
	else if (isset($_SESSION["l"]) && isset($CurrentList)
		 && $CurrentList > 0
		 && defval($_SESSION["l"][$CurrentList], "matchPreg", "") !== "")
	    $matcher = $_SESSION["l"][$CurrentList]["matchPreg"];
	if ($matcher !== null) {
	    if (!is_array($matcher))
		$matcher = array("abstract" => $matcher, "title" => $matcher,
				 "authorInformation" => $matcher,
				 "collaborators" => $matcher);
	    foreach ($matcher as $k => $v)
		if (is_string($v) && $v != "") {
		    if ($v[0] != "{")
			$v = "{(" . $v . ")}i";
		    $this->matchPreg[$k] = $v;
		}
	}
	if (count($this->matchPreg) == 0)
	    $this->matchPreg = null;

	$this->highlight = $this->matchPreg !== null;
	$this->watchCheckbox = 0;
	$this->initial = true;
    }

    function echoDivEnter($prow = null) {
	// if highlighting, automatically unfold abstract/authors
	if ($this->highlight && $prow && $this->allFolded
	    && ($this->foldState & 4)) {
	    $data = $this->entryData("abstract", $prow);
	    if ($this->entryMatches)
		$this->foldState &= ~4;
	}
	if ($this->highlight && $prow && ($this->foldState & 1)) {
	    cleanAuthor($prow);
	    $data = $this->entryData("authorInformation", $prow);
	    if ($this->entryMatches)
		$this->foldState &= ~(1 | 2);
	}
	
	echo "<div id='foldpaper' class='",
	    ($this->foldState & 1 ? "fold8c" : "fold8o"),
	    ($this->foldState & 2 ? " fold9c" : " fold9o"),
	    ($this->foldState & 4 ? " fold6c" : " fold6o"),
	    ($this->foldState & 8 ? " fold5c" : " fold5o"), "'>";
    }

    function echoDivExit() {
	echo "</div>";
    }

    function tdCaption($what) {
	global $Error;
	$c = "<td class='caption";
	if (isset($Error[$what]))
	    $c .= " error";
	if ($this->allFolded)
	    $c .= " extension9";
	if ($what == "abstract" && $this->editable)
	    $c .= " textarea";
	if ($this->initial)
	    $c .= " initial";
	return $c . "'>";
    }

    function papt($what, $name, $extra = array()) {
	global $Error, $Conf, $ConfSiteSuffix;
	$type = defval($extra, "type", "pap");
	$c = "<div class='${type}t";
	if (isset($Error[$what]))
	    $c .= " error";
	$c .= "'><span class='${type}fn'>";
	if (!($fold = defval($extra, "fold", false)))
	    $c .= (is_array($name) ? $name[0] : $name);
	else {
	    $foldnum = defval($extra, "foldnum", 0);
	    if (!is_array($name))
		$name = array($name, $name);
	    $c .= "<a class='q unfolder$foldnum' "
		. "href='javascript:fold(\"$fold\", 0, $foldnum)' "
		. "title=\"" . defval($extra, "showtitle", "Show")
		. "\">+&nbsp;" . $name[1] . "</a>"
		. "<a class='q folder$foldnum' "
		. "href='javascript:fold(\"$fold\", 1, $foldnum)' "
		. "title=\"" . defval($extra, "hidetitle", "Hide")
		. "\">&minus;&nbsp;" . $name[0] . "</a>";
	    if (($foldsession = defval($extra, "foldsession", false)))
		$c .= foldsessionpixel($fold . $foldnum, $foldsession);
	}
	$c .= "</span>";
	if (($editfolder = defval($extra, "editfolder", false))) {
	    $a = "<a class='unfolder' href='javascript:fold(\"$editfolder\", 0)' title='Edit'>";
	    $c .= "<span class='hint'>" . $a
		. $Conf->cacheableImage("newreview.png", "[Edit]", null, "b")
		. "</a>&nbsp;" . $a . "Edit</a></span>";
	}
	$c .= "<div class='clear'></div></div>";
	return $c;
    }

    function tdEntry($what) {
	global $textAreaRows;
	$c = "<td class='entry";
	if ($this->allFolded)
	    $c .= " extension9";
	if (!$this->editable)
	    $c .= "' colspan='2";
	if ($this->initial) {
	    $c .= " initial";
	    $this->initial = false;
	}
	return $c . "'>";
    }

    function tdHint($what) {
	$c = "<td class='hint";
	if ($this->allFolded)
	    $c .= " extension9";
	return $c . "'>";
    }

    function entryData($fieldName, $prow, $authorTable = false) {
	global $textAreaRows;
	$this->entryMatches = 0;
	
	if ($this->useRequest)
	    $text = $_REQUEST[$fieldName];
	else if ($prow)
	    $text = $prow->$fieldName;
	else
	    $text = "";

	if ($this->highlight && isset($textAreaRows[$fieldName])
	    && !$this->editable && isset($this->matchPreg[$fieldName]))
	    $text = preg_replace($this->matchPreg[$fieldName], "<span class='match'>\$1</span>", htmlspecialchars($text), -1, $this->entryMatches);
	else
	    $text = htmlspecialchars($text);
	
	if ($authorTable == "col" && !$this->editable)
	    $text = nl2br($text);
	else if ($authorTable == "tab" && !$this->editable)
	    $text = authorTable($text);

	if ($this->editable)
	    $text = "<textarea class='textlite' name='$fieldName' rows='" . $textAreaRows[$fieldName] . "' cols='80' onchange='hiliter(this)'>" . $text . "</textarea>";

	return $text;
    }

    function echoTitle($prow) {
	if ($this->highlight && isset($this->matchPreg["title"]))
	    echo preg_replace($this->matchPreg["title"], "<span class='match'>\$1</span>", htmlspecialchars($prow->title));
	else
	    echo htmlspecialchars($prow->title);
    }

    function echoTitleRow($prow) {
	echo "<tr class='pt_title'>\n";
	echo "  ", $this->tdCaption("title"), "Title</td>\n";
	echo "  ", $this->tdEntry("title"), $this->entryData("title", $prow);
	echo "</td>\n</tr>\n\n";
    }
    
    function echoPaperRow($prow, $flags) {
	global $Conf, $Me, $ConfSiteSuffix;
	$final = ($prow->outcome > 0 && $Conf->collectFinalPapers());
	$canUpload = $this->editable;

	echo "<tr class='pt_status'>\n";
	echo "  <td class='caption";
	if ($this->initial)
	    echo " initial";
	echo "'>";
	if ($this->allFolded)
	    echo foldbutton("paper", "abstract and authors", 9), " ";
	echo ($canUpload ? "Status" : "Paper"), "</td>\n";
	echo "  <td class='entry";
	if ($this->initial) {
	    echo " initial";
	    $this->initial = false;
	}
	echo "'>";

	// status
	echo $Me->paperStatus($prow->paperId, $prow, 0);
	$timestamp = "";
	if (($flags & self::STATUS_DATE) && $prow->timestamp)
	    $timestamp = $Conf->cacheableImage("timestamp12.png", "Updated", "Time of most recent paper update") . "&nbsp;<span title='Time of most recent paper update'>" . $Conf->printableTime($prow->timestamp) . "</span>";
	if (($flags & self::STATUS_DATE) && defval($prow, 'sha1')) {
	    if ($timestamp)
		$timestamp .= " &nbsp;<span class='barsep'>|</span>&nbsp; ";
	    $timestamp .= $Conf->cacheableImage("checksum12.png", "SHA-1", "SHA-1 checksum") . "&nbsp;<span title='SHA-1 checksum'>" . bin2hex($prow->sha1) . "</span>";
	}
	if ($timestamp)
	    $timestamp = "<span class='sep'></span> <span class='hint'>$timestamp</span>";
	if ($Me->canDownloadPaper($prow, $Conf)) {
	    if ($final && $prow->finalPaperStorageId > 0) {
		$submissionMimetype = null;
		echo " Final copy: ", paperDownload($prow, true), $timestamp,
		    " &nbsp;|&nbsp; <a class='black' href=\"",
		    $Conf->makeDownloadPath($prow->paperId, $submissionMimetype, false),
		    "\">Submission</a>";
	    } else if ($prow->size > 0)
		echo ($canUpload && $final ? " Submission: " : " "), paperDownload($prow), $timestamp;
	} else
	    echo $timestamp;

	// watch note
	if ($this->watchCheckbox && $Conf->setting("allowPaperOption") >= 6
	    && $prow->timeSubmitted >= 0
	    && (($prow->conflictType >= CONFLICT_AUTHOR || $prow->conflictType <= 0)
		|| ($Me->privChair && isset($_REQUEST["forceShow"])))) {
	    $result = $Conf->q("select ContactInfo.contactId, reviewType, commentId, conflictType, watch from ContactInfo left join PaperReview on (PaperReview.paperId=$prow->paperId and PaperReview.contactId=ContactInfo.contactId) left join PaperComment on (PaperComment.paperId=$prow->paperId and PaperComment.contactId=ContactInfo.contactId) left join PaperConflict on (PaperConflict.paperId=$prow->paperId and PaperConflict.contactId=ContactInfo.contactId) left join PaperWatch on (PaperWatch.paperId=$prow->paperId and PaperWatch.contactId=ContactInfo.contactId) where ContactInfo.contactId=$Me->contactId");
	    $row = edb_row($result);
	    echo "<span class='sep'></span> <form id='watchform' class='fold7o' action=\"", htmlspecialchars(selfHref(array("post" => 1))), "\" method='post' enctype='multipart/form-data' accept-charset='UTF-8' onsubmit='return Miniajax.submit(\"watchform\")'><div class='inform'>",
		"<input type='hidden' name='setwatch' value='1' />",
		"<input type='checkbox' name='watch' value='", $this->watchCheckbox, "'";
	    if ($row[4] && ($row[4] & ($Me->defaultWatch >> 1)))
		$watchValue = $row[4];
	    else if ($row[1] || $row[2] || $row[3] >= CONFLICT_AUTHOR)
		$watchValue = $Me->defaultWatch;
	    else
		$watchValue = 0;
	    if ($watchValue & $this->watchCheckbox)
		echo " checked='checked'";
	    echo " onchange='Miniajax.submit(\"watchform\")' />&nbsp;Mail me when updated comments are available&nbsp; ",
		"<input class='bsm extension7' type='submit' value='Save' /> ",
		"<span id='watchformresult' style='padding-left:1em'></span></div></form>";
	    $Conf->footerStuff .= "<script type='text/javascript'>Miniajax.onload(\"watchform\");</script>";
	}

	// conflicts
	if (($flags & self::STATUS_CONFLICTINFO) && $prow->conflictType >= CONFLICT_AUTHOR)
	    echo "<br />\nYou are an <span class='author'>author</span> of this paper.";
	else if ((($flags & self::STATUS_CONFLICTINFO) && $prow->conflictType > 0)
		 || (($flags & self::STATUS_CONFLICTINFO_PC) && $Me->isPC && $prow->conflictType > 0))
	    echo "<br />\nYou have a <span class='conflict'>conflict</span> with this paper.";
	if ($prow->reviewType != null && ($flags & self::STATUS_REVIEWERINFO)) {
	    if ($prow->reviewType == REVIEW_PRIMARY)
		echo "<br/>\nYou are a primary reviewer for this paper.";
	    else if ($prow->reviewType == REVIEW_SECONDARY)
		echo "<br/>\nYou are a secondary reviewer for this paper.";
	    else if ($prow->reviewType == REVIEW_EXTERNAL)
		echo "<br/>\nYou were requested to review this paper.";
	    else
		echo "<br/>\nYou began a review for this paper.";
	}
	if ($Me->isPC && $prow->conflictType <= 0
	    && $Conf->timeUpdatePaper($prow))
	    echo "<div class='g'></div><div class='xwarning'>The authors still have <a href='deadlines$ConfSiteSuffix'>time</a> to make changes.</div>";
	echo "</td>\n</tr>\n\n";
    }
    
    function paptabDownload($prow) {
	global $Conf, $Me, $ConfSiteSuffix;
	$final = ($prow->outcome > 0 && $Conf->collectFinalPapers());

	// status
	echo $Me->paperStatus($prow->paperId, $prow, 0);
	$timestamp = "";
	if ($prow->timestamp)
	    $timestamp = $Conf->cacheableImage("timestamp12.png", "Updated", "Time of most recent paper update") . "&nbsp;<span title='Time of most recent paper update'>" . $Conf->printableTime($prow->timestamp) . "</span>";
	if (defval($prow, 'sha1')) {
	    if ($timestamp)
		$timestamp .= " &nbsp;<span class='barsep'>|</span>&nbsp; ";
	    $timestamp .= $Conf->cacheableImage("checksum12.png", "SHA-1", "SHA-1 checksum") . "&nbsp;<span title='SHA-1 checksum'>" . bin2hex($prow->sha1) . "</span>";
	}
	if ($timestamp)
	    $timestamp = "<span class='sep'></span> <span class='hint'>$timestamp</span>";
	if ($Me->canDownloadPaper($prow, $Conf)) {
	    if ($final && $prow->finalPaperStorageId > 0) {
		$submissionMimetype = null;
		echo " Final copy: ", paperDownload($prow, true), $timestamp,
		    " &nbsp;|&nbsp; <a class='black' href=\"",
		    $Conf->makeDownloadPath($prow->paperId, $submissionMimetype, false),
		    "\">Submission</a>";
	    } else if ($prow->size > 0)
		echo " ", paperDownload($prow), $timestamp;
	} else
	    echo $timestamp;

	// conflicts
	$flags = self::STATUS_CONFLICTINFO;
	if (($flags & self::STATUS_CONFLICTINFO) && $prow->conflictType >= CONFLICT_AUTHOR)
	    echo "<br />\nYou are an <span class='author'>author</span> of this paper.";
	else if ((($flags & self::STATUS_CONFLICTINFO) && $prow->conflictType > 0)
		 || (($flags & self::STATUS_CONFLICTINFO_PC) && $Me->isPC && $prow->conflictType > 0))
	    echo "<br />\nYou have a <span class='conflict'>conflict</span> with this paper.";
	if ($prow->reviewType != null && ($flags & self::STATUS_REVIEWERINFO)) {
	    if ($prow->reviewType == REVIEW_PRIMARY)
		echo "<br/>\nYou are a primary reviewer for this paper.";
	    else if ($prow->reviewType == REVIEW_SECONDARY)
		echo "<br/>\nYou are a secondary reviewer for this paper.";
	    else if ($prow->reviewType == REVIEW_EXTERNAL)
		echo "<br/>\nYou were requested to review this paper.";
	    else
		echo "<br/>\nYou began a review for this paper.";
	}
	if ($Me->isPC && $prow->conflictType <= 0
	    && $Conf->timeUpdatePaper($prow))
	    echo "<div class='g'></div><div class='xwarning'>The authors still have <a href='deadlines$ConfSiteSuffix'>time</a> to make changes.</div>";
	echo "</td>\n</tr>\n\n";
    }

    function echoUploadRow($prow, $flags) {
	global $Conf, $ConfSiteSuffix, $Opt;
	$final = (($flags & self::FINALCOPY) != 0 && $prow->outcome > 0 && $Conf->collectFinalPapers());
	
	echo "<tr class='pt_paper'>\n  ", $this->tdCaption('paper');
	if (defval($Opt, 'disablePS') > 0)
	    echo "PDF file";
	else if (defval($Opt, 'disablePDF') > 0)
	    echo "PostScript file";
	else
	    echo "PDF/PostScript file";
	$maxSize = ini_get("upload_max_filesize") . "B";
	echo "<br /><span class='hint'>(max size $maxSize)</span></td>\n";
	echo "  ", $this->tdEntry("paper");

	echo ($final ? "Final copy:&nbsp; " : ""),
	    "<input class='textlite' type='file' name='paperUpload' accept='",
	    (defval($Opt, 'disablePDF') > 0 ? "" : "application/pdf "),
	    (defval($Opt, 'disablePS') > 0 ? "" : "application/postscript "),
	    "' size='30' onchange='hiliter(this)";
	if (!$final)
	    echo ";fold(\"isready\",0)";
	if ($flags & self::ENABLESUBMIT)
	    echo ";form.submit.disabled=false";
	echo "' />";
	
	if (!$final) {
	    if ($this->useRequest)
		$checked = defval($_REQUEST, "submit");
	    else if ($Conf->setting('sub_freeze'))
		$checked = $prow && $prow->timeSubmitted > 0;
	    else
		$checked = !$prow || $prow->paperStorageId <= 1 || $prow->timeSubmitted > 0;
	    echo "<div id='foldisready' class='",
		($prow && $prow->paperStorageId > 1 ? "foldo" : "foldc"),
		"'><table class='extension'><tr><td class='nowrap'><input type='checkbox' name='submit' value='1'",
		($checked ? " checked='checked'" : ""),
		" onchange='hiliter(this)' />&nbsp;";
	    if ($Conf->setting('sub_freeze'))
		echo "</td><td><strong>This is the final submission.</strong></td></tr><tr><td></td><td><small>You must submit a final version before the deadline or your paper will not be reviewed.  Once you submit a final version you will not be able to make further changes.</small>";
	    else
		echo "The paper is ready for review.";
	    echo "</td></tr></table></div>\n";
	} else
	    echo "<input type='hidden' name='submit' value='1' />";

	if ($prow
	    && ($final ? $prow->finalPaperStorageId : $prow->paperStorageId) > 0
	    && defval($prow, "mimetype", "application/pdf") == "application/pdf"
	    && $Conf->setting("sub_banal")) {
	    echo "<div id='foldcheckformat' class='foldc'><input class='b' type='submit' name='checkformat' value='Check format requirements' onclick='return docheckformat()' /><div id='checkformatformresult' class='extension'><div class='xinfo'>Checking format, please wait (this can take a while)...</div></div></div>";
	    $Conf->footerStuff .= "<form id='checkformatform' class='fold7o' method='post' action='paper$ConfSiteSuffix?p=$prow->paperId";
	    if ($final)
		$Conf->footerStuff .= "&amp;final=1";
	    $Conf->footerStuff .= "' enctype='multipart/form-data' accept-charset='UTF-8' onsubmit='return Miniajax.submit(\"checkformatform\")'><input type='hidden' name='checkformat' value='1' /></form>";
	}

	echo "</td>\n</tr>\n\n";
    }

    function echoAbstractRow($prow) {
	echo "<tr class='pt_abstract'>\n";
	echo "  ", $this->tdCaption("abstract"), "Abstract</td>\n";
	echo "  ", $this->tdEntry("abstract"),
	    $this->entryData("abstract", $prow);
	echo "</td>\n</tr>\n\n";
    }

    function paptabAbstract($prow) {
	$data = $this->entryData("abstract", $prow);
	if ($this->allFolded && strlen($data) > 190) {
	    $shortdata = preg_replace("/\\S+(<[^>]+)?\\Z/", "", substr($data, 0, 180));
	    if ($shortdata != "") { /* "" might happen if really long word */
		echo $this->papt("abstract", "Abstract",
				 array("fold" => "paper", "foldnum" => 6,
				       "showtitle" => "Show full abstract",
				       "hidetitle" => "Abbreviate abstract")),
		    "<div class='papv abstract'>",
		    "<span class='ellipsis6'>", $shortdata,
		    " <a class='unfolder6' href='javascript:fold(\"paper\", 0, 6)'>[more]</a>",
		    "</span><span class='extension6'>", $data,
		    "</span></div>\n\n";
		return;
	    }
	}
	echo $this->papt("abstract", "Abstract"),
	    "<div class='papv abstract'>", $data, "</div>\n\n";
    }

    function echoEditableAuthors($prow) {
	global $Conf;
	echo "<div class='hint'>List the paper's authors one per line, including their email addresses and affiliations.";
	if ($Conf->blindSubmission() == 2)
	    echo " Submission is blind, so reviewers will not be able to see author information.";
	echo "  Any author with an account on this site can edit the paper.</div>\n";
	echo "<table id='auedittable' class='auedittable'><tr><th></th><th>Name</th><th>Email</th><th>Affiliation</th></tr>\n";
	$blankAu = array("", "", "", "");
	if ($this->useRequest && isset($_REQUEST["authorTable"]))
	    $authorTable = $_REQUEST["authorTable"];
	else
	    $authorTable = ($prow ? $prow->authorTable : array());
	for ($n = 1; $n <= 25; $n++) {
	    $au = ($n <= count($authorTable) ? $authorTable[$n - 1] : $blankAu);
	    if ($au[0] && $au[1] && !preg_match('@^\s*(von\s+|van\s+)?\S+(\s+jr.?|\s+sr.?|\s+i+)?\s*$@i', $au[1]))
		$auname = $au[1] . ", " . $au[0];
	    else if ($au[0] && $au[1])
		$auname = $au[0] . " " . $au[1];
	    else
		$auname = $au[0] . $au[1];
	    echo "<tr id='auedit$n' class='auedito'><td class='rxcaption'>", $n, ".</td>",
		"<td class='lentry'><input class='textlite' type='text' name='auname$n' size='30' onchange='hiliter(this)' value=\"", htmlspecialchars($auname), "\" /></td>",
		"<td class='lentry'><input class='textlite' type='text' name='auemail$n' size='20' onchange='hiliter(this)' value=\"", htmlspecialchars($au[2]), "\" /></td>",
		"<td class='lentry'><input class='textlite' type='text' name='auaff$n' size='25' onchange='hiliter(this)' value=\"", htmlspecialchars($au[3]), "\" /></td></tr>\n";
	}
	echo "</table><input id='aueditcount' type='hidden' name='aueditcount' value='25' />";
	$Conf->echoScript("authorfold(\"auedit\",0," . max(count($authorTable) + 1, 5) . ")");
    }

    function echoAuthorInformation($prow) {
	global $Conf;
	if ($prow)
	    cleanAuthor($prow);
	echo "<tr class='pt_authorInformation'>\n";
	echo "  ", $this->tdCaption("authorInformation");
	echo "Authors";
	if ($this->editable)
	    echo "<br /><small><a href='javascript:authorfold(\"auedit\",1,1)'>More</a> | <a href='javascript:authorfold(\"auedit\",1,-1)'>Fewer</a></small>";
	echo "</td>\n  ", $this->tdEntry("authorInformation");
	if ($this->editable)
	    $this->echoEditableAuthors($prow);
	else
	    echo $this->entryData("authorInformation", $prow, "tab");
	echo "</td>\n</tr>\n\n";
    }
    
    function authorData($table, $type, $viewAs = null) {
	global $Conf;
	if ($this->highlight && isset($this->matchPreg["authorInformation"]))
	    $highpreg = $this->matchPreg["authorInformation"];
	else
	    $highpreg = false;
	$this->entryMatches = 0;

	$names = array();
	if ($type == "last") {
	    foreach ($table as $au) {
		$n = abbreviateName($au[0], $au[1]);
		if ($highpreg !== false) {
		    $n = preg_replace($highpreg, "<span class='match'>\$1</span>", htmlspecialchars($n), -1, $nm);
		    $this->entryMatches += $nm;
		} else
		    $n = htmlspecialchars($n);
		$names[] = $n;
	    }
	    return join(", ", $names);

	} else {
	    foreach ($table as $au) {
		$n = trim("$au[0] $au[1]");
		if ($au[3] != "")
		    $n .= " ($au[3])";
		if ($au[2] != "")
		    $n .= " <$au[2]>";
		if ($highpreg !== false) {
		    $n = preg_replace($highpreg, "<span class='match'>\$1</span>", htmlspecialchars($n), -1, $nm);
		    $this->entryMatches += $nm;
		} else
		    $n = htmlspecialchars($n);
		if ($au[2] != "" && preg_match("/ &lt;.*&gt;\\Z/", $n, $m))
		    $n = substr($n, 0, strlen($n) - strlen($m[0]) + 5)
			. "<a href=\"mailto:" . htmlspecialchars($au[2]) . "\">"
			. substr($m[0], 5, strlen($m[0]) - 9)
			. "</a>&gt;";
		if ($au[2] != "" && $viewAs !== null && $viewAs->email != $au[2]
		    && $viewAs->privChair)
		    $n .= " <a href=\"" . htmlspecialchars(selfHref(array("viewContact" => $au[2]))) . "\">" . $Conf->cacheableImage("viewas.png", "[Act as]", "Act as " . contactNameHtml($au)) . "</a>";
		$names[] = $n;
	    }
	    return join("<br />\n", $names);
	}
    }

    function paptabAuthors($prow) {
	global $Conf, $Me, $forceShow;

	$viewable = $Me->canViewAuthors($prow, $Conf, $forceShow);
	if (!$viewable && !$Me->actChair($prow, true)) {
	    echo $this->papt("authorInformation", "Authors"),
		"<div class='papv'><i>Hidden for blind review</i></div>\n\n";
	    return;
	}

	// clean author information
	cleanAuthor($prow);
	// find contact author information, combine with author table
	$result = $Conf->qe("select firstName, lastName, email, '' as affiliation, contactId
		from ContactInfo join PaperConflict using (contactId)
		where paperId=$prow->paperId and conflictType=" . CONFLICT_CONTACTAUTHOR . "
		order by lastName, firstName, email", "while finding contact authors");
	$contacts = array();
	while (($row = edb_row($result))) {
	    $match = -1;
	    for ($i = 0; $match < 0 && $i < count($prow->authorTable); ++$i)
		if ($prow->authorTable[$i][2] == $row[2])
		    $match = $i;
	    if (($row[0] != "" || $row[1] != "") && $match < 0) {
		$contact_n = $row[0] . " " . $row[1];
		$contact_preg = str_replace("\\.", "\\S*", "/\\b" . preg_quote($row[0]) . "\\b.*\\b" . preg_quote($row[1]) . "\\b/i");
		for ($i = 0; $match < 0 && $i < count($prow->authorTable); ++$i) {
		    $f = $prow->authorTable[$i][0];
		    $l = $prow->authorTable[$i][1];
		    if (($f != "" || $l != "") && $prow->authorTable[$i][2] == "") {
			$author_n = $f . " " . $l;
			$author_preg = str_replace("\\.", "\\S*", "/\\b" . preg_quote($f) . "\\b.*\\b" . preg_quote($l) . "\\b/i");
			if (preg_match($contact_preg, $author_n)
			    || preg_match($author_preg, $contact_n))
			    $match = $i;
		    }
		}
	    }
	    if ($match >= 0) {
		if ($prow->authorTable[$match][2] == "")
		    $prow->authorTable[$match][2] = $row[2];
		$prow->authorTable[$match][4] = 1;
	    } else
		$contacts[] = $row;
	}

	// anonymity folding
	if (!$viewable) {
	    echo "<div class='ellipsis8'>",
		$this->papt("authorInformation", "Authors"),
		"<div class='papv'><a class='q unfolder8' ",
		"href='javascript:fold(\"paper\", 0, 8)' title='Show authors'>",
		"+&nbsp;<i>Hidden for blind review</i></a></div></div>\n",
		"<div class='extension8'>";
	    $inauthors = "<a class='q folder8' "
		. "href='javascript:fold(\"paper\", 1, 8)' "
		. "title='Hide authors'>[blind]</a> ";
	    $afterauthors = "</div>\n";
	} else {
	    $inauthors = $afterauthors = "";
	    if ($Conf->blindSubmission() == 1 && $prow->blind)
		$inauthors = "[blind] ";
	}
	
	// "author" or "authors"?
	$auname = pluralx(count($prow->authorTable), "Author");

	// actually print
	if ($this->allFolded) {
	    echo $this->papt("authorInformation", $auname,
			     array("fold" => "paper", "foldnum" => 9,
				   "showtitle" => "Show full authors",
				   "hidetitle" => "Show abbreviated authors")),
		"<div class='papv'>", $inauthors,
		"<span class='ellipsis9'>",
		$this->authorData($prow->authorTable, "last"),
		" <a class='folder' href='javascript:fold(\"paper\", 0, 9)'>[details]</a>",
		"</span><span class='extension9'>",
		$this->authorData($prow->authorTable, "col", $Me);

	} else {
	    echo $this->papt("authorInformation", $auname),
		"<div class='papv'>", $inauthors,
		$this->authorData($prow->authorTable, "col", $Me);
	}

	echo "</div>\n";

	if (count($contacts) > 0) {
	    echo "<div class='extension9'>",
		$this->papt("authorInformation", pluralx(count($contacts), "Contact author")),
		"<div class='papv'>",
		$this->authorData($contacts, "col", $Me),
		"</div></div>\n\n";
	}

	echo $afterauthors, "\n";
    }

    function paptabTopicsOptions($prow, $showAllOptions) {
	global $Conf;
	$topicdata = topicTable($prow, -1, $Conf);
	$optiondata = "";
	if (($options = paperOptions())) {
	    preg_match_all('/(\d+)#(\d+)/', defval($prow, "optionIds", ""), $m);
	    $optsel = array();
	    for ($i = 0; $i < count($m[1]); ++$i)
		$optsel[$m[1][$i]] = $m[2][$i];
	    foreach ($options as $o) {
		$om = defval($o, "optionValues");
		$ov = defval($optsel, $o->optionId, 0);
		if (($ov || $om)
		    && ($o->pcView || $showAllOptions
			|| $prow->conflictType >= CONFLICT_AUTHOR)) {
		    if ($om) {
			$om = explode("\n", $om);
			if (defval($om, $ov, "") != "")
			    $optiondata .= htmlspecialchars("$o->optionName: $om[$ov]") . "<br />\n";
		    } else
			$optiondata .= htmlspecialchars($o->optionName) . "<br />\n";
		}
	    }
	}

	if ($topicdata != "" || $optiondata != "") {
	    if ($topicdata != "" && $optiondata != "")
		$tanda = "Topics and Options";
	    else if ($topicdata != "")
		$tanda = "Topics";
	    else
		$tanda = "Options";

	    if ($this->allFolded) {
		$extra = array("fold" => "paper", "foldnum" => 5,
			       "showtitle" => "Show " . strtolower($tanda),
			       "hidetitle" => "Hide " . strtolower($tanda));
		$before = "<div class='papv ellipsis5'></div><div class='extension5'>";
		$after = "</div>";
	    } else {
		$extra = null;
		$before = $after = "";
	    }

	    if ($topicdata != "") {
		echo $this->papt("topics", array("Topics", $tanda), $extra),
		    $before, "<div class='papv'>", $topicdata, "</div>\n\n";
		$extra = null;
		$before = "";
		$tanda = "Options";
	    }

	    if ($optiondata != "") {
		echo $this->papt("options", array("Options", $tanda), $extra),
		    $before, "<div class='papv'>", $optiondata, "</div>\n\n";
	    }

	    echo $after;
	}
    }

    function echoNewContactAuthor($pulldown) {
	global $Me, $Conf;
	echo "<tr class='pt_contactAuthor'>\n";
	echo "  ", $this->tdCaption('contactAuthor'), "Contact author</td>\n";
	echo "  ", $this->tdEntry('contactAuthor');
	echo "<div class='hint'>You will be able to add more contact authors after you register the paper.</div>\n";
	if ($pulldown)
	    contactPulldown("contact", "contact", $Conf, $Me);
	else
	    echo contactHtml($Me);
	echo "</td>\n</tr>\n\n";
    }

    function echoContactAuthor($prow, $editMode = null) {
	global $Conf, $ConfSiteSuffix, $Me;
	$result = $Conf->qe("select firstName, lastName, email, contactId
		from ContactInfo
		join PaperConflict using (contactId)
		where paperId=$prow->paperId and conflictType=" . CONFLICT_CONTACTAUTHOR . "
		order by lastName, firstName, email", "while finding contact authors");
	echo "<tr class='pt_contactAuthor'>\n";
	echo "  ", $this->tdCaption("contactAuthor");
	echo (edb_nrows($result) == 1 ? "Contact author" : "Contact authors");
	echo "</td>\n  ", $this->tdEntry('contactAuthor');
	if ($result) {
	    $aus = array();
	    while (($row = edb_row($result)))
		$aus[] = $row;
	    echo authorTable($aus, $Me);
	}
	if ($editMode || ($editMode === false && ($this->editable || $Me->privChair)))
	    echo "<a class='button_small' href='contactauthors$ConfSiteSuffix?p=$prow->paperId'>Edit&nbsp;contact&nbsp;authors</a>";
	echo "</td>\n</tr>\n\n";
    }

    function echoAnonymity($prow) {
	global $Conf;
	echo "<tr class='pt_blind'>\n";
	echo "  ", $this->tdCaption("blind"), "Anonymity</td>\n";
	echo "  ", $this->tdEntry("blind");
	$blind = ($this->useRequest ? isset($_REQUEST['blind']) : (!$prow || $prow->blind));
	if ($this->editable) {
	    echo "<div class='hint'>", htmlspecialchars($Conf->shortName), " allows either anonymous or named submission.  Check this box to submit the paper anonymously (reviewers won't be shown the author list).  Make sure you also remove your name from the paper itself!</div>\n";
	    echo "<input type='checkbox' name='blind' value='1'";
	    if ($blind)
		echo " checked='checked'";
	    echo " onchange='hiliter(this)' />&nbsp;Anonymous submission";
	} else
	    echo ($blind ? "Anonymous" : "Non-anonymous"), " submission";
	echo "</td>\n</tr>\n";
    }

    function echoCollaborators($prow) {
	global $Conf, $ConfSiteSuffix;
	if (!$Conf->setting("sub_collab"))
	    return;
	$sub_pcconf = $Conf->setting("sub_pcconf");
	echo "<tr class='pt_collaborators'>\n";
	echo "  ", $this->tdCaption("collaborators"),
	    ($sub_pcconf ? "Other conflicts" : "Potential conflicts"),
	    "</td>\n  ", $this->tdEntry("collaborators");
	if ($this->editable) {
	    echo "<div class='hint'>";
	    if ($Conf->setting("sub_pcconf"))
		echo "List <em>other</em> people and institutions with which
	the authors have conflicts of interest.  This will help us avoid
	conflicts when assigning external reviews.  No need to list people
	at the authors' own institutions.";
	    else
		echo "List people and institutions with which the authors have
	conflicts of interest.  ", $Conf->conflictDefinitionText(), "
	Be sure to include conflicted <a href='contacts$ConfSiteSuffix?t=pc'>PC members</a>.
	We use this information when assigning PC and external reviews.";
	    echo "  List one conflict per line.  For example: &ldquo;<tt>Jelena Markovic (EPFL)</tt>&rdquo; or, for a whole institution, &ldquo;<tt>EPFL</tt>&rdquo;.</div>\n";
	}
	echo $this->entryData("collaborators", $prow, "tab");
	echo "</td>\n</tr>\n\n";
    }

    function papstripCollaborators($prow) {
	global $Conf, $ConfSiteSuffix;
	if (!$Conf->setting("sub_collab") || !$prow->collaborators
	    || strcasecmp(trim($prow->collaborators), "None") == 0)
	    return;
	$name = $Conf->setting("sub_pcconf") ? "Other conflicts" : "Potential conflicts";
	$fold = defval($_SESSION, "foldpscollab", 1) ? 1 : 0;

	$data = $this->entryData("collaborators", $prow, "col");
	if ($this->entryMatches || !$this->allFolded)
	    $fold = 0;
	
	echo "<div id='foldpscollab' class='fold", ($fold ? "c" : "o"), "'>",
	    $this->papt("collaborators", $name,
			array("type" => "ps", "fold" => "pscollab",
			      "foldsession" => "foldpscollab",
			      "folded" => $fold)),
	    "<div class='psv'><div class='extension'>", $data,
	    "</div></div></div>\n\n";
    }

    function echoTopics($prow) {
	global $Conf;
	$topicMode = (int) $this->useRequest;
	if (!$this->editable)
	    $topicMode = -1;
	if ($topicTable = topicTable($prow, $topicMode, $Conf)) { 
	    echo "<tr class='pt_topics'>\n";
	    echo "  ", $this->tdCaption("topics"), "Topics</td>\n";
	    echo "  ", $this->tdEntry("topics");
	    if ($this->editable)
		echo "<div class='hint'>Select any topics that apply to your paper.</div>\n";
	    echo $topicTable, "</td>\n";
	    echo "</tr>\n\n";
	}
    }

    function echoOptions($prow, $showAll) {
	global $Conf;
	$prow_optionIds = isset($prow->optionIds) ? $prow->optionIds : "";
	if (!($opt = paperOptions()))
	    return;
	preg_match_all('/(\d+)#(\d+)/', $prow_optionIds, $m);
	$optsel = array();
	for ($i = 0; $i < count($m[1]); ++$i)
	    $optsel[$m[1][$i]] = $m[2][$i];
	if ($this->editable) {
	    foreach ($opt as $o) {
		echo "<tr class='pt_option'>\n",
		    "  ", $this->tdCaption("option"), htmlspecialchars($o->optionName), "</td>\n",
		    "  ", $this->tdEntry("option");
		if (!defval($o, "optionValues")) {
		    echo "<input type='checkbox' name='opt$o->optionId' value='1'",
			(defval($optsel, $o->optionId) ? " checked='checked'" : ""),
			" onchange='hiliter(this)' />&nbsp;", $o->description;
		} else {
		    if ($o->description)
			echo "<div class='hint'>", $o->description, "</div>";
		    echo tagg_select("opt$o->optionId", explode("\n", $o->optionValues), defval($optsel, $o->optionId, 0), array("onchange" => "hiliter(this)"));
		}
		echo "</td>\n</tr>\n\n";
	    }
	} else {
	    $sep = $t = "";
	    foreach ($opt as $o) {
		$om = defval($o, "optionValues");
		$ov = defval($optsel, $o->optionId, 0);
		if (($om || $ov)
		    && ($o->pcView || $showAll
			|| $prow->conflictType >= CONFLICT_AUTHOR)) {
		    if ($om) {
			$om = explode("\n", $om);
			if (defval($om, $ov, "") != "") {
			    $t .= $sep . htmlspecialchars("$o->optionName: $om[$ov]");
			    $sep = "<span class='sep'> </span>";
			}
		    } else {
			$t .= $sep . htmlspecialchars($o->optionName);
			$sep = "<span class='sep'> </span>";
		    }
		}
	    }
	    if ($t) {
		echo "<tr class='pt_option'>\n";
		echo "  ", $this->tdCaption("option"), "Options</td>\n";
		echo "  ", $this->tdEntry("option"), $t, "</td>\n";
		echo "</tr>\n\n";
	    }
	}
    }

    function echoPCConflicts($prow, $admins) {
	global $Conf;
	if ($this->editable && !$Conf->setting("sub_pcconf"))
	    return;
	$pcm = ($admins ? pcMembersAndAdmins() : pcMembers());
	$conflict = array();
	if (!count($pcm))
	    return;
	if ($this->useRequest) {
	    foreach ($pcm as $id => $row)
		if (isset($_REQUEST["pcc$id"]))
		    $conflict[$id] = CONFLICT_AUTHORMARK;
	}
	if ($prow) {
	    $result = $Conf->qe("select contactId, conflictType from PaperConflict where paperId=$prow->paperId", "while finding conflicted PC members");
	    while (($row = edb_row($result)))
		if ($row[1] != CONFLICT_AUTHORMARK || !$this->useRequest)
		    $conflict[$row[0]] = $row[1];
	}

	if ($this->editable) {
	    foreach ($pcm as $id => $p) {
		$c = "<input type='checkbox' name='pcc$id' value='1'";
		if (defval($conflict, $id) > 0) {
		    $c .= " checked='checked'";
		    if ($conflict[$id] != CONFLICT_AUTHORMARK)
			$c .= " disabled='disabled'";
		}
		$pcconfs[] = $c . " onchange='hiliter(this)' /> " . contactHtml($p->firstName, $p->lastName);
	    }
	    echo "<tr class='pt_conflict_ass'><td class='caption'>PC conflicts</td>\n  <td class='entry'>";
	    echo "<div class='hint'>Select the PC members who have conflicts of interest with this paper.  ", $Conf->conflictDefinitionText(), "</div>\n";
	    echo "<table class='rpad'><tr><td>";
	    $n = intval((count($pcconfs) + 2) / 3);
	    for ($i = 0; $i < count($pcconfs); $i++) {
		if (($i % $n) == 0 && $i)
		    echo "</td><td class='colmid'>";
		echo $pcconfs[$i], "<br />";
	    }
	    echo "</td></tr></table></td></tr>\n\n";
	    
	} else {
	    foreach ($pcm as $id => $p)
		if (defval($conflict, $id))
		    $pcconfs[] = contactHtml($p->firstName, $p->lastName);
	    if ($prow->pcPaper)
		$pcconfs[] = "<b>PC paper</b>";
	    if (!isset($pcconfs))
		$pcconfs[] = "None";
	    echo "<tr class='pt_conflict'><td class='caption'>PC conflicts</td><td class='entry'>", authorTable($pcconfs), "</td></tr>\n\n";
	}
    }

    function papstripPCConflicts($prow, $admins) {
	global $Conf;
	assert(!$this->editable);
	$pcm = ($admins ? pcMembersAndAdmins() : pcMembers());
	if (!count($pcm) || !$prow)
	    return;
	$conflict = array();
	$result = $Conf->qe("select contactId, conflictType from PaperConflict where paperId=$prow->paperId", "while finding conflicted PC members");
	while (($row = edb_row($result)))
	    $conflict[$row[0]] = $row[1];
	foreach ($pcm as $id => $p)
	    if (defval($conflict, $id))
		$pcconfs[] = contactHtml($p->firstName, $p->lastName);
	if ($prow->pcPaper)
	    $pcconfs[] = "<b>PC paper</b>";
	if (!isset($pcconfs))
	    $pcconfs[] = "None";

	echo $this->papt("pcconflict", "PC conflicts", array("type" => "ps")),
	    "<div class='psv'>", authorTable($pcconfs), "</div>\n";
    }

    function echoLead($prow) {
	if ($prow->leadContactId) {
	    $pc = pcMembers();
	    echo "<tr class='pt_conflict'><td class='caption'>Discussion lead</td><td class='entry'>";
	    if (($row = defval($pc, $prow->leadContactId, null)))
		echo contactHtml($row->firstName, $row->lastName);
	    else
		echo "(Unknown contact ID!)";
	    echo "</td></tr>\n";
	}
    }

    function _papstripLeadShepherd($prow, $type, $name, $defaultEdit) {
	global $Conf, $ConfSiteSuffix, $Me, $Error, $linkExtra;
	$editable = $Me->actChair($prow) && $defaultEdit >= 0;

	$field = $type . "ContactId";
	if ($prow->$field == 0 && !$editable)
	    return;
	$value = $prow->$field;
	$pc = pcMembers();

	if ($editable) {
	    echo "<form id='${type}form' class='fold7o' method='post' action='review$ConfSiteSuffix?p=", $prow->paperId, "&amp;post=1$linkExtra' enctype='multipart/form-data' accept-charset='UTF-8' onsubmit='return Miniajax.submit(\"${type}form\")'>",
		"<div id='fold$type' class='", ($defaultEdit ? "foldo" : "foldc"), " inform'>",
		"<input type='hidden' name='set$type' value='1' />";
	    $Conf->footerStuff .= "<script type='text/javascript'>Miniajax.onload(\"${type}form\");</script>";
	}
	
	echo $this->papt($type, $name, array("type" => "ps", "editfolder" => ($editable && !$defaultEdit ? $type : 0))),
	    "<div class='psv'>";

	if ($editable) {
	    $opt = array("0" => "None");
	    foreach ($pc as $row)
		$opt[htmlspecialchars($row->email)] = contactHtml($row->firstName, $row->lastName);

	    echo "<div class='extension'>",
		tagg_select($type, $opt,
			    ($value && isset($pc[$value]) ? htmlspecialchars($pc[$value]->email) : "0"),
			    array("onchange" => "Miniajax.submit(\"${type}form\")")),
		"&nbsp; <input class='b extension7' type='submit' value='Save' />",
		" <span id='${type}formresult'></span>",
		"</div><div class='ellipsis'>";
	}

	if (!$value)
	    echo "None";
	else if (!isset($pc[$value]))
	    echo "Unknown!";
	else
	    echo contactHtml($pc[$value]->firstName, $pc[$value]->lastName);

	echo "</div>";
	if ($editable)
	    echo "</div></div></form>";
	echo "\n\n";
    }

    function papstripLead($prow, $defaultEdit = false) {
	$this->_papstripLeadShepherd($prow, "lead", "Discussion lead", $defaultEdit);
    }

    function echoShepherd($prow) {
	if ($prow->shepherdContactId) {
	    $pc = pcMembers();
	    echo "<tr class='pt_conflict'><td class='caption'>Shepherd</td><td class='entry'>";
	    if (($row = defval($pc, $prow->shepherdContactId, null)))
		echo contactHtml($row->firstName, $row->lastName);
	    else
		echo "(Unknown contact ID!)";
	    echo "</td></tr>\n";
	}
    }

    function papstripShepherd($prow, $defaultEdit = false) {
	$this->_papstripLeadShepherd($prow, "shepherd", "Shepherd", $defaultEdit);
    }

    function echoTags($prow, $site = null) {
	global $Conf, $ConfSiteSuffix, $Me;
	$t = isset($prow->paperTags) ? $prow->paperTags : "";
	if ($site || $t) {
	    // Note that tags MUST NOT contain HTML special characters.
	    require_once("tags.inc");
	    $tx = tagsToText($t, true, $Me->contactId);
	    $t = tagsToText($t, false, $Me->contactId);
	    echo "<tr class='pt_tags'>\n  <td class='caption'>Tags</td>\n  <td class='entry'>";
	    if ($site && $Me->canSetTags($prow, $Conf)) {
		echo "<form method='post' action=\"$site\" enctype='multipart/form-data' accept-charset='UTF-8'><div id='foldtags' class='foldc inform'>";
		echo "<span class='ellipsis'>", ($t == "" ? "none" : $tx), "&nbsp;</span>";
		echo "<a href='javascript:fold(\"tags\", 0)' title='Edit tags'>", $Conf->cacheableImage("next.png", "[Edit]", null, "unfolder"), "</a>";
		echo "<span class='extension'><input class='textlite' type='text' size='40' name='tags' value=\"", $t, "\" /> &nbsp;<input class='bsm' type='submit' name='settags' value='Save' /> &nbsp; ";
		echo "<button type='button' class='bsm' onclick='javascript:fold(\"tags\", 1)'>Cancel</button><br /><span class='hint'><a href='help$ConfSiteSuffix?t=tags'>Learn more</a> &nbsp;<span class='barsep'>|</span>&nbsp; <strong>Tip:</strong> Twiddle tags like &ldquo;~tag&rdquo; are visible only to you.</span></span>";
		echo "</div></form>";
	    } else
		echo ($t == "" ? "none" : $tx);
	    echo "</td>\n</tr>\n\n";
	}
    }

    function papstripTags($prow, $site = null) {
	global $Conf, $ConfSiteSuffix, $Me, $Error;
	$t = isset($prow->paperTags) ? $prow->paperTags : "";
	if ($site || $t) {
	    // Note that tags MUST NOT contain HTML special characters.
	    require_once("tags.inc");
	    $tx = tagsToText($t, true, $Me->contactId);
	    $t = tagsToText($t, false, $Me->contactId);
	    $editable = $site && $Me->canSetTags($prow, $Conf);

	    if ($editable)
		echo "<form method='post' action=\"$site\" enctype='multipart/form-data' accept-charset='UTF-8'><div id='foldtags' class='foldc inform'>";

	    echo $this->papt("tags", "Tags", array("type" => "ps", "editfolder" => ($editable ? "tags" : 0))),
		"<div class='psv'>";
	    if ($editable) {
		echo "<div class='ellipsis'>", ($t == "" ? "none" : $tx),
		    "</div><div class='extension'>",
		    "<textarea cols='20' rows='2' name='tags'>", $t, "</textarea></br>",
		    "<input class='bsm' type='submit' name='settags' value='Save' /> &nbsp; ",
		    "<button type='button' class='bsm' onclick='javascript:fold(\"tags\", 1)'>Cancel</button><br />",
		    "<span class='hint'><a href='help$ConfSiteSuffix?t=tags'>Learn more</a> &nbsp;<span class='barsep'>|</span>&nbsp; <strong>Tip:</strong> Twiddle tags like &ldquo;~tag&rdquo; are visible only to you.</span>",
		    "</div>";
	    } else
		echo ($t == "" ? "none" : $tx);
	    echo "</div>";

	    if ($editable)
		echo "</div></form>";
	}
    }

    function echoOutcomeSelector($prow) {
	global $ConfSiteSuffix;
	echo "<tr class='pt_outcome'>
  <td class='caption'>Decision</td>
  <td class='entry'><form method='get' action='review$ConfSiteSuffix' accept-charset='UTF-8'><div class='inform'><input type='hidden' name='p' value='$prow->paperId' />";
	if (isset($_REQUEST['forceShow']))
	    echo "<input type='hidden' name='forceShow' value='", $_REQUEST['forceShow'], "' />";
	echo decisionSelector($prow->outcome, null, " onchange='highlightUpdate(\"setdecision\")'");
	echo "&nbsp; <input id='setdecision' class='b' type='submit' name='setdecision' value='Save' /></div></form></td>\n</tr>\n";
    }

    function papstripOutcomeSelector($prow) {
	global $Conf, $ConfSiteSuffix, $Error, $linkExtra;
	echo $this->papt("decision", "Decision", array("type" => "ps")),
	    "<div class='psv'><form id='decisionform' class='fold7o' method='post' action='review$ConfSiteSuffix?p=", $prow->paperId, "&amp;post=1$linkExtra' enctype='multipart/form-data' accept-charset='UTF-8' onsubmit='return Miniajax.submit(\"decisionform\")'>",
	    "<div class='inform'>",
	    "<input type='hidden' name='setdecision' value='1' />";
	if (isset($_REQUEST["forceShow"]))
	    echo "<input type='hidden' name='forceShow' value='", $_REQUEST['forceShow'], "' />";
	echo decisionSelector($prow->outcome, null, " onchange='Miniajax.submit(\"decisionform\")'"),
	    "&nbsp; <input class='b extension7' type='submit' value='Save' />",
	    " <span id='decisionformresult'></span>",
	    "</div></form></div>\n";
	$Conf->footerStuff .= "<script type='text/javascript'>Miniajax.onload(\"decisionform\");</script>";
    }

    function papstripReviewPreference($prow) {
	global $Conf, $ConfSiteSuffix, $linkExtra;
	echo $this->papt("revpref", "Review preference", array("type" => "ps")),
	    "<div class='psv'><form id='revprefform' class='fold7o' method='post' action='review$ConfSiteSuffix?p=", $prow->paperId, "&amp;post=1$linkExtra' enctype='multipart/form-data' accept-charset='UTF-8' onsubmit='return Miniajax.submit(\"revprefform\")'>",
	    "<div class='inform'>",
	    "<input type='hidden' name='setrevpref' value='1' />";
	if (isset($_REQUEST["forceShow"]))
	    echo "<input type='hidden' name='forceShow' value='", $_REQUEST['forceShow'], "' />";
	$rp = (isset($prow->reviewerPreference) ? htmlspecialchars($prow->reviewerPreference) : "0");
	$rp = ($rp == "0" ? "" : $rp);
	echo "<input id='revprefform_d' class='textlite' type='text' size='4' name='revpref' value=\"$rp\" onchange='Miniajax.submit(\"revprefform\")' tabindex='1' />",
	    "&nbsp; <input class='b extension7' type='submit' value='Save' />",
	    " <span id='revprefformresult'></span>",
	    "</div></form></div>\n";
	$Conf->footerStuff .= "<script type='text/javascript'>Miniajax.onload(\"revprefform\");</script>";
    }


    // Functions for overall paper table viewing

    function paptabBegin() {
	global $Conf, $ConfSiteSuffix, $Me, $forceShow, $linkExtra;
	$prow = $this->prow;

	echo "<table class='pbox'><tr>
    <td class='pboxi'><table class='papc'>
	<tr><td class='papcul'></td><td></td><td></td></tr>
	<tr><td></td><td><h2>#", $this->prow->paperId, "</h2></td><td></td></tr>
    </table>
    <div class='papstripc'><div class='papstrip'>";

	// Paper strip
	if ($Me->canViewTags($prow, $Conf, $forceShow))
	    $this->papstripTags($prow, "review$ConfSiteSuffix?p=$prow->paperId$linkExtra");
	if ($Me->actChair($prow) || $prow->conflictType >= CONFLICT_AUTHOR) {
	    $this->papstripPCConflicts($prow, true);
	    $this->papstripCollaborators($prow);
	}
	if ($Me->canSetOutcome($prow))
	    $this->papstripOutcomeSelector($prow);
	if ($Me->actPC($prow))
	    $this->papstripLead($prow);
	if ($Me->actPC($prow) || $Me->canViewDecision($prow, $Conf))
	    $this->papstripShepherd($prow);
	if ($Me->actPC($prow) && $Conf->timePCReviewPreferences())
	    $this->papstripReviewPreference($prow);

	echo "</div></div></td>\n";

	// main paper table
	echo "    <td class='pboxt'><table class='papc'>
	<tr><td class='papculs'></td><td></td><td></td></tr>
	<tr><td></td><td>";
	if ($Me->canEditPaper($prow, $Conf)) {
	    echo "<div class='floatright'>";
	    $a = "<a href='paper$ConfSiteSuffix?p=$prow->paperId&amp;m=pe$linkExtra'>";
	    echo $a, $Conf->cacheableImage("newreview24.png", "[Edit]", null, "b"),
		"</a>&nbsp;", $a, "Edit</a></div>";
	}
	echo "<h2>";
	$this->echoTitle($prow);
	echo "</h2></td><td class='papcur'></td></tr>
    </table></td>
</tr><tr>
    <td class='pboxl'></td>
    <td class='pboxr'><table class='papc'>
	<tr><td class='papculs'></td><td class='papcc'>";

	$this->echoDivEnter($prow);
	echo "<table class='paptab'><tr><td class='papbe' colspan='2'>";
	$this->paptabDownload($prow);
	// XXX PaperTable::STATUS_CONFLICTINFO_PC
	echo "</td></tr>
	<tr><td class='paple'>";
	$this->paptabAbstract($prow);
	echo "</td><td class='papre'>";
	// XXXX XXXX XXXX
	$this->paptabAuthors($prow);
	$this->paptabTopicsOptions($prow, $Me->actChair($prow));
	echo "</td></tr></table>";
	$this->echoDivExit();
	echo "</td><td></td></tr>\n";
    }

    function paptabEndWithReviews() {
	global $Conf, $ConfSiteSuffix, $Me, $rf, $forceShow, $linkExtra;
	require_once("reviewtable.inc");
	$prow = $this->prow;

	if ($forceShow && !$Me->canViewReview($prow, null, $Conf, $fakeWhyNotView, true)) {
	    echo "	<tr><td colspan='3' class='papsep'></td></tr>
	<tr><td></td><td class='papcc'>",
		"You have used administrator privileges to view and edit ",
		"reviews for this paper. (<a href=\"", htmlspecialchars(selfHref(array("forceShow" => 0))), "\">Unprivileged view</a>)",
		"</td><td></td></tr>\n";
	}
	
	echo "	<tr><td colspan='3' class='papsep'></td></tr>
	<tr><td></td><td class='papcc'>";

	$t = reviewTable($prow, $this->rrows, $this->crows, null, $this->mode)
	    . reviewLinks($prow, $this->rrows, $this->crows, null, $this->mode);
	if ($t == "") {
	    echo "<div class='hint'>There are no reviews or comments for you to view.</div></td><td></td>",
		tagg_cbox("pap", true), "</td></tr></table>";
	    return;
	}

	echo $t, "</td><td></td></tr>\n",
	    tagg_cbox("pap", true), "</td></tr></table>\n";

	// text format link
	if ($Me->canViewReview($prow, null, $Conf)
	    && count($this->rrows) + count($this->crows) > 0) {
	    $a = "<a href='review$ConfSiteSuffix?p=$prow->paperId&amp;mode=r&amp;text=1$linkExtra'>";
	    echo "<table class='pbox'><tr><td class='pboxl'></td>",
		"<td class='pboxr'>", $a,
		$Conf->cacheableImage("txt24.png", "[Text]", null, "dlimg"),
		"</a>&nbsp;", $a, "Reviews and comments in text format</a>",
		"</td></tr></table>\n";
	}

	$opt = array("edit" => false);
	foreach ($this->rrows as $rr)
	    if ($rr->reviewSubmitted)
		$rf->show($prow, $this->rrows, $rr, $opt);
	foreach ($this->rrows as $rr)
	    if (!$rr->reviewSubmitted && $rr->reviewModified > 0
		&& $Me->canViewReview($prow, $rr, $Conf))
		$rf->show($prow, $this->rrows, $rr, $opt);

	// show comments as well
	if (count($this->crows) || $Me->canComment($prow, null, $Conf)
	    || $Conf->timeAuthorRespond()) {
	    require_once("Code/commentview.inc");
	    $cv = new CommentView;
	    $editablecid = defval($_REQUEST, "commentId", "xxx");
	    $editableresponse = $Conf->timeAuthorRespond() && $prow->conflictType >= CONFLICT_AUTHOR;
	    foreach ($this->crows as $cr)
		$cv->show($prow, $cr, false, $cr->commentId == $editablecid || ($editableresponse && $cr->forAuthors > 1));

	    // comment editing
	    if ($Me->canComment($prow, null, $Conf))
		$cv->show($prow, null, false, true, $editablecid != "new");
	    if (!$cv->nresponse && $Conf->timeAuthorRespond()
		&& ($prow->conflictType >= CONFLICT_AUTHOR
		    || ($Me->actChair($prow) && $editablecid == "response")))
		$cv->showResponse($prow, null, false, true);
	}
    }

    function paptabEndWithReviewMessage() {
	global $Conf, $ConfSiteSuffix, $Me, $rf, $linkExtra;

	if (!$Me->canViewReview($this->prow, null, $Conf, $whyNot)) {
	    echo "	<tr><td colspan='3' class='papsep'></td></tr>
	<tr><td></td><td class='papcc'>",
		"You can't see the reviews for this paper.  ",
		whyNotText($whyNot, "review"), "</td><td></td></tr>\n";
	}
	echo tagg_cbox("pap", true), "</td></tr></table>";
    }

    function paptabEndWithEditableReview() {
	global $Conf, $ConfSiteSuffix, $Me, $rf, $forceShow, $linkExtra;
	require_once("reviewtable.inc");
	$prow = $this->prow;
	$actPC = $Me->actPC($prow);
	$actChair = $Me->actChair($prow);

	// review messages
	$viewall = $Me->canViewReview($prow, null, $Conf, $whyNot, true);
	$msgs = array();
	if (!$this->rrow && $this->prow->reviewType <= 0)
	    $msgs[] = "You haven't been assigned to review this paper, but you can review it anyway.";
	if ($forceShow && !$viewall) {
	    $msgs[] = "You have used administrator privileges to view and edit reviews for this paper. (<a href=\"" . htmlspecialchars(selfHref(array("forceShow" => 0))) . "\">Unprivileged view</a>)";
	} else if (!$viewall && isset($whyNot["reviewNotComplete"])) {
	    $nother = 0;
	    foreach ($this->rrows as $rr)
		if (!$Me->ownReview($rr) && $rr->reviewSubmitted)
		    $nother++;
	    if ($nother > 0)
		$msgs[] = "You'll be able to see " . plural($nother, "other review") . " once you complete your own.";
	}
	if (count($msgs) > 0)
	    echo "	<tr><td colspan='3' class='papsep'></td></tr>
	<tr><td></td><td class='papcc'>", join("<br />\n", $msgs), "</td><td></td></tr>\n";

	// links
	$t = reviewLinks($prow, $this->rrows, $this->crows, $this->editrrow, $this->mode);
	if ($t != "")
	    echo "	<tr><td colspan='3' class='papsep'></td></tr>
	<tr><td></td><td class='papcc'>", $t, "</td><td></td></tr>\n";

	echo tagg_cbox("pap", true), "</td></tr></table>\n";

	// review form, possibly with deadline warning
	$opt = array("edit" => $this->mode == "re");

	if ($this->editrrow && ($Me->ownReview($this->editrrow) || $actChair)
	    && !$Conf->timeReviewPaper($actPC, true, true)) {
	    if ($Me->actChair)
		$override = "  As an administrator, you can override this deadline using the &ldquo;Override deadlines&rdquo; checkbox.";
	    else {
		$override = "";
		$opt["edit"] = false;
	    }
	    if (!$Conf->timeReviewPaper($actPC, true, true, true))
		$opt["editmessage"] = "The <a href='deadlines$ConfSiteSuffix'>deadline</a> for changing reviews has passed, so the review can no longer be changed.$override";
	    else
		$opt["editmessage"] = "The site is not open for reviewing, so the review cannot be changed.$override";
	} else if (!$Me->canReview($prow, $this->editrrow, $Conf))
	    $opt["edit"] = false;

	$rf->show($prow, $this->rrows, $this->editrrow, $opt);
    }


    // Functions for loading papers

    function _maybeSearchPaperId() {
	global $Conf, $Me, $ConfSiteSuffix, $CurrentList;

	// if a number, don't search
	if (isset($_REQUEST["paperId"]) && $_REQUEST["paperId"] != "") {
	    if (ctype_digit($_REQUEST["paperId"])
		&& $_REQUEST["paperId"][0] != "0")
		return false;
	    if (preg_match('/^\s*#?([1-9]\d*)\s*$/s', $_REQUEST["paperId"], $m)) {
		$_REQUEST["paperId"] = $m[1];
		return false;
	    }
	}
	
	// if a complex request, or a form upload, don't search
	foreach ($_REQUEST as $k => $v)
	    if ($k != "p" && $k != "paperId" && $k != "m" && $k != "mode"
		&& $k != "forceShow" && $k != "go" && $k != "viewContact"
		&& $k != "chairMode" && $k != "ls" && $k != "t"
		&& !isset($_COOKIE[$k]))
		return false;

	// if no paper ID set, find one
	if (!isset($_REQUEST["paperId"])) {
	    $q = "select min(Paper.paperId) from Paper ";
	    if ($Me->isPC)
		$q .= "where timeSubmitted>0";
	    else if ($Me->isReviewer)
		$q .= "join PaperReview on (PaperReview.paperId=Paper.paperId and PaperReview.contactId=$Me->contactId)";
	    else
		$q .= "join ContactInfo on (ContactInfo.paperId=Paper.paperId and ContactInfo.contactId=$Me->contactId and ContactInfo.conflictType>=" . CONFLICT_AUTHOR . ")";
	    $result = $Conf->q($q);
	    if (($paperId = edb_row($result)))
		$_REQUEST["paperId"] = $paperId[0];
	    return false;
	}

	// if invalid contact, or it looks like a number, don't search
	if (!$Me->validated)
	    return false;

	// actually try to search
	require_once("search.inc");
	$search = new PaperSearch($Me, array("q" => $_REQUEST["paperId"], "t" => defval($_REQUEST, "t", 0)));
	$pl = $search->paperList();
	if (count($pl) == 1) {
	    global $thisMatchPreg;
	    if ($search->matchPreg)
		$thisMatchPreg = $search->matchPreg;
	    $_REQUEST["paperId"] = $pl[0];
	    $replaceList = oldestListNumber();
	    $_SESSION["l"][$replaceList] = $search->sessionList($search->limitName);
	    $_REQUEST["ls"] = $replaceList;
	    return true;
	} else {
	    $t = (defval($_REQUEST, "t", 0) ? "&t=" . urlencode($_REQUEST["t"]) : "");
	    $Conf->go("search$ConfSiteSuffix?q=" . urlencode($_REQUEST["paperId"]) . $t);
	    exit;
	}
    }

    function paperRow(&$whyNot) {
	global $Conf, $Me;

	if (!isset($_REQUEST["paperId"]) && isset($_REQUEST["p"]))
	    $_REQUEST["paperId"] = $_REQUEST["p"];
	if (!isset($_REQUEST["reviewId"]) && isset($_REQUEST["r"]))
	    $_REQUEST["reviewId"] = $_REQUEST["r"];
	if (!isset($_REQUEST["commentId"]) && isset($_REQUEST["c"]))
	    $_REQUEST["commentId"] = $_REQUEST["c"];

	if (isset($_REQUEST["paperId"]) && $_REQUEST["paperId"] == "new")
	    return null;
	if (!isset($_REQUEST["paperId"]) && isset($_REQUEST["reviewId"])
	    && preg_match('/^(\d+)[A-Z]+$/', $_REQUEST["reviewId"], $m))
	    $_REQUEST["paperId"] = $m[1];

	$sel = array();
	if (isset($_REQUEST["paperId"])
	    || (!isset($_REQUEST["reviewId"]) && !isset($_REQUEST["commentId"]))) {
	    self::_maybeSearchPaperId();
	    $sel["paperId"] = $_REQUEST["paperId"];
	} else if (isset($_REQUEST["reviewId"]))
	    $sel["reviewId"] = $_REQUEST["reviewId"];
	else if (isset($_REQUEST["commentId"]))
	    $sel["commentId"] = $_REQUEST["commentId"];

	$sel["topics"] = $sel["options"] = true;
	if (($Me->isPC && $Conf->timePCReviewPreferences()) || $Me->privChair)
	    $sel["reviewerPreference"] = true;
	if ($Me->isPC)
	    $sel["tags"] = true;

	if (!($prow = $Conf->paperRow($sel, $Me, $whyNot)))
	    return null;
	if (!$Me->canViewPaper($prow, $Conf, $whyNot)
	    || (!isset($_REQUEST["paperId"])
		&& !$Me->canViewReview($prow, null, $Conf))) {
	    // Don't allow querier to probe review/comment<->paper mapping
	    if (!isset($_REQUEST["paperId"]))
		$whyNot = array("invalidId" => "paper");
	    return null;
	}
	cleanAuthor($prow);
	return $prow;
    }

    function resolveMode() {
	global $Conf, $Me;
	$prow = $this->prow;
	if ($prow === null) {
	    $this->mode = "pe";
	    return;
	}
	$ms = array();
	if ($this->rrow && $Me->canReview($prow, $this->rrow, $Conf))
	    $ms["re"] = true;
	if ($Me->canViewReview($prow, null, $Conf))
	    $ms["r"] = true;
	if ($Me->canReview($prow, null, $Conf))
	    $ms["re"] = true;
	if ($prow->conflictType >= CONFLICT_AUTHOR
	    && $Conf->timeFinalizePaper($prow))
	    $ms["pe"] = true;
	if ($Me->canViewPaper($prow, $Conf))
	    $ms["p"] = true;
	if ($prow->conflictType >= CONFLICT_AUTHOR || $Me->actChair($prow, true))
	    $ms["pe"] = true;
	if ($prow->myReviewType >= REVIEW_SECONDARY || $Me->actChair($prow, true))
	    $ms["assign"] = true;
	if (isset($_REQUEST["mode"]) && isset($ms[$_REQUEST["mode"]]))
	    return $_REQUEST["mode"];
	if (isset($_REQUEST["m"]) && isset($ms[$_REQUEST["m"]]))
	    return $_REQUEST["m"];
	return key($ms);
    }

    function resolveReview() {
	global $Conf, $Me;

	$sel = array("paperId" => $this->prow->paperId, "array" => true);
	if ($Conf->setting("rev_ratings") != REV_RATINGS_NONE) {
	    $sel["ratings"] = true;
	    $sel["myRating"] = $Me->contactId;
	}
	$this->rrows = $Conf->reviewRow($sel, $whyNot);
	
	$rrid = strtoupper(defval($_REQUEST, "reviewId", ""));
	while ($rrid != "" && $rrid[0] == "0")
	    $rrid = substr($rrid, 1);

	$this->rrow = $myrrow = null;
	foreach ($this->rrows as $rr) {
	    if ($rrid != "") {
		if (strcmp($rr->reviewId, $rrid) == 0
		    || ($rr->reviewOrdinal && strcmp($rr->paperId . unparseReviewOrdinal($rr->reviewOrdinal), $rrid) == 0))
		    $this->rrow = $rr;
	    }
	    if ($rr->contactId == $Me->contactId
		|| (!$myrrow && $Me->ownReview($rr)))
		$myrrow = $rr;
	}

	// naming a nonexistent review? silently view all reviews
	if ($this->mode == "re" && !$this->rrow && isset($_REQUEST["reviewId"]))
	    $this->mode = "r";

	$this->editrrow = ($this->rrow ? $this->rrow : $myrrow);
    }

    function resolveComments() {
	global $Conf;
	$result = $Conf->qe("select PaperComment.*, firstName, lastName, email
		from PaperComment join ContactInfo using (contactId)
		where paperId=" . $this->prow->paperId . " order by commentId",
			    "while loading comments");
	$this->crows = array();
	while ($row = edb_orow($result))
	    $this->crows[] = $row;
    }

    function fixReviewMode() {
	global $Conf, $Me;
	if ($this->mode == "re" && $this->rrow
	    && !$Me->canReview($this->prow, $this->rrow, $Conf, $whyNot, false))
	    $this->mode = "r";
	if ($this->mode == "r" && $this->rrow
	    && !$Me->canViewReview($this->prow, $this->rrow, $Conf, $whyNot))
	    $this->rrow = $this->editrrow = null;
	if ($this->mode == "r" && !$this->rrow && !$this->editrrow
	    && !$Me->canViewReview($this->prow, $this->rrow, $Conf, $whyNot)
	    && $Me->canReview($this->prow, $this->rrow, $Conf, $whyNot, false))  {
	    $this->mode = "re";
	    foreach ($this->rrows as $rr)
		if ($rr->contactId == $Me->contactId
		    || (!$this->editrrow && $Me->ownReview($rr)))
		    $this->editrrow = $rr;
	}
    }

}
