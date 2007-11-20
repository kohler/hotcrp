<?php 
// papertable.inc -- HotCRP helper class for producing paper tables
// HotCRP is Copyright (c) 2006-2007 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

$authorFoldedRows = array("contactAuthor" => 1, "collaborators" => 1);
$textAreaRows = array("title" => 1, "abstract" => 1, "authorInformation" => 1,
		      "collaborators" => 1);

class PaperTable {

    const OPTIONAL = 2;
    const FINALCOPY = 4;
    const ENABLESUBMIT = 8;
    
    const STATUS_DATE = 16;
    const STATUS_CONFLICTINFO = 64;
    const STATUS_CONFLICTINFO_PC = 128;
    const STATUS_REVIEWERINFO = 256;

    var $editable;
    var $useRequest;
    var $allFolded;
    var $authorsFolded;
    var $foldState;
    var $highlight;
    var $matchPreg;
    var $watchCheckbox;
    
    function PaperTable($editable, $useRequest, $allFolded, $authorsFolded,
			$foldsession = null) {
	global $thisMatchPreg, $CurrentList;
	
	$this->editable = $editable;
	$this->useRequest = $useRequest;

	$this->foldState = 3;
	if ($foldsession && !defval($_SESSION, "fold${foldsession}a", 1))
	    $this->foldState &= ~1;
	if ($foldsession && !defval($_SESSION, "fold${foldsession}p", 1))
	    $this->foldState &= ~2;
	
	$this->allFolded = $allFolded;
	$this->authorsFolded = $authorsFolded;

	if (isset($thisMatchPreg))
	    $this->matchPreg = $thisMatchPreg;
	else if (isset($_SESSION["l"]) && isset($CurrentList)
		 && $CurrentList > 0
		 && isset($_SESSION["l"][$CurrentList]["matchPreg"]))
	    $this->matchPreg = $_SESSION["l"][$CurrentList]["matchPreg"];
	else
	    $this->matchPreg = null;
	$this->highlight = $this->matchPreg !== null;
	$this->watchCheckbox = 0;
    }

    function echoDivEnter() {
	if ($this->allFolded)
	    echo "<div id='foldpaper' class='", ($this->foldState & 2 ? "fold9c" : "fold9o"), "'>";
	if ($this->authorsFolded)
	    echo "<div id='foldauthors' class='", ($this->foldState & 1 ? "fold8c" : "fold8o"), "'>";
    }

    function echoDivExit() {
	if ($this->authorsFolded)
	    echo "</div>";
	if ($this->allFolded)
	    echo "</div>";
    }

    function tdCaption($what) {
	global $PaperError, $authorFoldedRows;
	$c = "<td class='caption";
	if (isset($PaperError[$what]))
	    $c .= " error";
	if ($this->allFolded)
	    $c .= " extension9";
	if ($this->authorsFolded && isset($authorFoldedRows[$what]))
	    $c .= " extension8";
	if ($what == "abstract" && $this->editable)
	    $c .= " textarea";
	return $c . "'>";
    }

    function tdEntry($what) {
	global $authorFoldedRows, $textAreaRows;
	$c = "<td class='entry";
	if ($this->allFolded)
	    $c .= " extension9";
	if ($this->authorsFolded && isset($authorFoldedRows[$what]))
	    $c .= " extension8";
	if (!$this->editable)
	    $c .= "' colspan='2";
	return $c . "'>";
    }

    function tdHint($what) {
	global $authorFoldedRows;
	$c = "<td class='hint";
	if ($this->allFolded)
	    $c .= " extension9";
	if ($this->authorsFolded && isset($authorFoldedRows[$what]))
	    $c .= " extension8";
	return $c . "'>";
    }

    function echoEntryData($fieldName, $prow, $nrows, $authorTable = false) {
	global $textAreaRows;
	
	if ($this->editable)
	    echo "<textarea class='textlite' name='$fieldName' rows='$nrows' cols='80' onchange='highlightUpdate()'>";
	
	if ($this->useRequest)
	    $text = $_REQUEST[$fieldName];
	else if ($prow)
	    $text = $prow->$fieldName;
	else
	    $text = "";

	if ($this->highlight && isset($textAreaRows[$fieldName]) && !$this->editable)
	    $text = preg_replace($this->matchPreg, "<span class='match'>\$1</span>", htmlspecialchars($text));
	else
	    $text = htmlspecialchars($text);
	
	if ($authorTable && !$this->editable)
	    echo authorTable($text);
	else
	    echo $text;
	
	if ($this->editable)
	    echo "</textarea>";
    }

    function echoTitle($prow) {
	if ($this->highlight)
	    echo preg_replace($this->matchPreg, "<span class='match'>\$1</span>", htmlspecialchars($prow->title));
	else
	    echo htmlspecialchars($prow->title);
    }
    
    function echoTitleRow($prow) {
	echo "<tr class='pt_title'>\n";
	echo "  ", $this->tdCaption("title"), "Title</td>\n";
	echo "  ", $this->tdEntry("title");
	$this->echoEntryData("title", $prow, 1);
	echo "</td>\n</tr>\n\n";
    }
    
    function echoPaperRow($prow, $flags) {
	global $Conf, $Me, $ConfSiteBase;
	$final = ($prow->outcome > 0 && $Conf->setting("final_open") > 0);
	$canUpload = $this->editable;

	echo "<tr class='pt_status'>\n";
	echo "  <td class='caption'>";
	if ($this->allFolded)
	    echo "<a href=\"javascript:fold('paper', 0, 9)\" class='foldbutton unfolder9'>+</a>",
		"<a href=\"javascript:fold('paper', 1, 9)\" class='foldbutton folder9'>&ndash;</a> ";
	echo ($canUpload ? "Status" : "Paper"), "</td>\n";
	echo "  <td class='entry'>";

	// status
	echo $Me->paperStatus($prow->paperId, $prow, 0);
	$timestamp = "";
	if (($flags & self::STATUS_DATE) && $prow->timestamp)
	    $timestamp = "<span class='sep'></span> (last updated " . $Conf->printableTime($prow->timestamp) . ")";
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
	    echo "<span class='sep'></span> <form id='watchform' class='fold7o' action=\"", htmlspecialchars(selfHref(array("post" => 1))), "\" method='post' enctype='multipart/form-data' onsubmit='return Miniajax.submit(\"watchform\")'><div class='inform'>",
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
	    echo " onchange='Miniajax.submit(\"watchform\")' />&nbsp;Mail me on comment updates&nbsp; ",
		"<input class='button_small extension7' type='submit' value='Save' /> ",
		"<span id='watchformresult' style='padding-left:1em'></span></div></form>";
	    $Conf->footerStuff .= "<script type='text/javascript'>Miniajax.onload(\"watchform\");</script>";
	}

	// conflicts
	if (($flags & self::STATUS_CONFLICTINFO) && $prow->conflictType >= CONFLICT_AUTHOR)
	    echo "<br/>\nYou are an <span class='author'>author</span> of this paper.";
	else if ((($flags & self::STATUS_CONFLICTINFO) && $prow->conflictType > 0)
		 || (($flags & self::STATUS_CONFLICTINFO_PC) && $Me->isPC && $prow->conflictType > 0))
	    echo "<br/>\nYou have a <span class='conflict'>conflict</span> with this paper.";
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
	echo "</td>\n</tr>\n\n";
    }

    function echoEditRow($prow) {
	global $Conf, $Me, $ConfSiteBase;
	if (!$this->editable) {
	    echo "<tr class='pt_mode'>\n";
	    echo "  <td class='caption'></td>\n";
	    echo "  <td class='entry'><a href=\"${ConfSiteBase}paper.php?paperId=$prow->paperId&amp;mode=edit\" class='button'>Edit paper</a></td>\n";
	    echo "</tr>\n";
	}
    }

    function echoUploadRow($prow, $flags) {
	global $Conf, $Opt;
	$final = (($flags & self::FINALCOPY) != 0 && $prow->outcome > 0);
	
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
	    "' size='30' onchange='highlightUpdate()";
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
		"/>&nbsp;";
	    if ($Conf->setting('sub_freeze'))
		echo "<strong>This is the final submission.</strong></td></tr><tr><td></td><td><small>You must submit a final version before the deadline or your paper will not be reviewed.  Once you submit a final version you will not be able to make further changes.</small>";
	    else
		echo "The paper is ready for review.";
	    echo "</td></tr></table></div>\n";
	} else
	    echo "<input type='hidden' name='submit' value='1' />";

	echo "</td>\n</tr>\n\n";
    }

    function echoAbstractRow($prow) {
	echo "<tr class='pt_abstract'>\n";
	echo "  ", $this->tdCaption("abstract"), "Abstract</td>\n";
	echo "  ", $this->tdEntry("abstract");
	$this->echoEntryData("abstract", $prow, 12);
	echo "</td>\n</tr>\n\n";
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
		"<td class='lentry'><input class='textlite' type='text' name='auname$n' size='35' onchange='highlightUpdate()' value=\"", htmlspecialchars($auname), "\" /></td>",
		"<td class='lentry'><input class='textlite' type='text' name='auemail$n' size='25' onchange='highlightUpdate()' value=\"", htmlspecialchars($au[2]), "\" /></td>",
		"<td class='lentry'><input class='textlite' type='text' name='auaff$n' size='30' onchange='highlightUpdate()' value=\"", htmlspecialchars($au[3]), "\" /></td></tr>\n";
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
	if ($this->authorsFolded)
	    echo "<a href=\"javascript:fold('authors', 0, 8)\" class='foldbutton unfolder8'>+</a>",
	    "<a href=\"javascript:fold('authors', 1, 8)\" class='foldbutton folder8'>&ndash;</a> ";
	echo "Authors";
	if ($this->editable)
	    echo "<br /><small><a href='javascript:authorfold(\"auedit\",1,1)'>More</a> | <a href='javascript:authorfold(\"auedit\",1,-1)'>Fewer</a></small>";
	echo "</td>\n  ", $this->tdEntry("authorInformation");
	if ($this->editable)
	    $this->echoEditableAuthors($prow);
	else {
	    if ($this->authorsFolded == 1)
		echo"<span class='ellipsis8'><i>Hidden for blind review</i></span><span class='extension8'>";
	    $this->echoEntryData("authorInformation", $prow, 5, true);
	    if ($this->authorsFolded == 1)
		echo "</span>";
	}
	echo "</td>\n</tr>\n\n";
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
	global $Conf, $ConfSiteBase, $Me;
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
	    echo "<a class='button_small' href='${ConfSiteBase}contactauthors.php?paperId=$prow->paperId'>Edit&nbsp;contact&nbsp;authors</a>";
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
	    echo " />&nbsp;Anonymous submission";
	} else
	    echo ($blind ? "Anonymous" : "Non-anonymous"), " submission";
	echo "</td>\n</tr>\n";
    }

    function echoCollaborators($prow) {
	global $Conf, $ConfSiteBase;
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
	Be sure to include conflicted <a href='${ConfSiteBase}contacts.php?t=pc'>PC members</a>.
	We use this information when assigning PC and external reviews.";
	    echo "  List one conflict per line.  For example: &ldquo;<tt>Jelena Markovic (EPFL)</tt>&rdquo; or, for a whole institution, &ldquo;<tt>EPFL</tt>&rdquo;.</div>\n";
	}
	$this->echoEntryData("collaborators", $prow, 6, true);
	echo "</td>\n</tr>\n\n";
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
	if ((!$this->editable && $prow_optionIds == "")
	    || !($opt = paperOptions()))
	    return;
	$optsel = array_flip(explode(",", $prow_optionIds));
	if ($this->editable) {
	    foreach ($opt as $o) {
		echo "<tr class='pt_option'>\n";
		echo "  ", $this->tdCaption("option"), htmlspecialchars($o->optionName), "</td>\n";
		echo "  ", $this->tdEntry("option"), "<input type='checkbox' name='opt$o->optionId' value='1'", (isset($optsel[$o->optionId]) ? " checked='checked'" : ""), " />&nbsp;", $o->description, "</td>\n";
		echo "</tr>\n\n";
	    }
	} else {
	    $sep = $t = "";
	    foreach ($opt as $o)
		if (isset($optsel[$o->optionId])
		    && ($o->pcView || $showAll || $prow->conflictType >= CONFLICT_AUTHOR)) {
		    $t .= $sep . htmlspecialchars($o->optionName);
		    $sep = "<span class='sep'> </span>";
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
	if ($prow) {
	    $result = $Conf->qe("select contactId, conflictType from PaperConflict where paperId=$prow->paperId", "while finding conflicted PC members");
	    while (($row = edb_row($result)))
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
		$pcconfs[] = $c . " onchange='highlightUpdate()' /> " . contactHtml($p->firstName, $p->lastName);
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

    function echoTags($prow, $site = null) {
	global $Conf, $ConfSiteBase, $Me;
	$t = isset($prow->paperTags) ? $prow->paperTags : "";
	if ($site || $t) {
	    // Note that tags MUST NOT contain HTML special characters.
	    $t = str_replace('#0', '', $t);
	    if ($t == "")
		$tx = "";
	    else
		$tx = preg_replace('/([a-zA-Z!@*_:.][-a-zA-Z0-9!@*_:.\/]*)/',
				   "<a class='q' href='${ConfSiteBase}search.php?q=tag:\$1'>\$1</a>",
				   $t);
	    
	    echo "<tr class='pt_tags'>\n  <td class='caption'>Tags</td>\n  <td class='entry'>";
	    if ($site && $Me->canSetTags($prow, $Conf)) {
		echo "<form method='post' action=\"$site\" enctype='multipart/form-data'><div id='foldtags' class='foldc inform'>";
		echo "<span class='ellipsis'>", ($t == "" ? "none" : $tx), "&nbsp;</span>";
		echo "<a href='javascript:fold(\"tags\", 0)'>", $Conf->cacheableImage("next.png", "[Edit]", null, "unfolder"), "</a>";
		echo "<span class='extension'><input class='textlite' type='text' size='40' name='tags' value=\"", $t, "\" /> &nbsp;<input class='button_small' type='submit' name='settags' value='Set tags' /> &nbsp; ";
		echo "<a href='javascript:fold(\"tags\", 1)'>", $Conf->cacheableImage("prev.png", "[Hide]", null, "folder"), "</a></span>";
		echo "</div></form>";
	    } else
		echo $tx;
	    echo "</td>\n</tr>\n\n";
	}
    }

    function echoOutcomeSelector($prow) {
	echo "<tr class='pt_outcome'>
  <td class='caption'>Decision</td>
  <td class='entry'><form method='get' action='review.php'><div class='inform'><input type='hidden' name='paperId' value='$prow->paperId' />";
	if (isset($_REQUEST['forceShow']))
	    echo "<input type='hidden' name='forceShow' value='", $_REQUEST['forceShow'], "' />";
	echo outcomeSelector($prow->outcome);
	echo "&nbsp; <input class='button_small' type='submit' name='setoutcome' value='Set decision' /></div></form></td>\n</tr>\n";
    }

}
