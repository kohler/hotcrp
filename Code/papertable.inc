<?php 

$authorFoldedRows = array("contactAuthor" => 1, "collaborators" => 1);
$textAreaRows = array("title" => 1, "abstract" => 1, "authorInformation" => 1,
		      "collaborators" => 1);

class PaperTable {

    const NOCAPTION = 1;
    const OPTIONAL = 2;
    const FINALCOPY = 4;
    
    const STATUS_DATE = 8;
    const STATUS_DOWNLOAD = 16;
    const STATUS_CONFLICTINFO = 32;
    const STATUS_CONFLICTINFO_PC = 64;
    const STATUS_REVIEWERINFO = 128;

    var $editable;
    var $useRequest;
    var $allFolded;
    var $authorsFolded;
    var $foldState;
    var $highlight;
    var $matchPreg;
    
    function PaperTable($editable, $useRequest, $allFolded, $authorsFolded,
			$foldsession = null) {
	global $thisMatchPreg;
	
	$this->editable = $editable;
	$this->useRequest = $useRequest;

	$this->foldState = 3;
	if ($foldsession && !defval($_SESSION["fold${foldsession}a"], 1))
	    $this->foldState &= ~1;
	if ($foldsession && !defval($_SESSION["fold${foldsession}p"], 1))
	    $this->foldState &= ~2;
	
	$this->allFolded = $allFolded;
	$this->authorsFolded = $authorsFolded;

	$this->matchPreg = defval($thisMatchPreg, defval($_SESSION["matchPreg"], null));
	$this->highlight = ($this->matchPreg !== null && (isset($thisMatchPreg) || defval($_SESSION["whichList"]) == "list"));
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
	return $c . "'>";
    }

    function tdEntry($what) {
	global $authorFoldedRows, $textAreaRows;
	$c = "<td class='entry";
	if ($this->allFolded)
	    $c .= " extension9";
	if ($this->authorsFolded && isset($authorFoldedRows[$what]))
	    $c .= " extension8";
	if ($this->editable && isset($textAreaRows[$what]))
	    $c .= " textarea";
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
	    echo authorTable($text, false);
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
    
    function echoStatusRow($prow, $flags) {
	global $Me;
	echo "<tr class='pt_status'>\n";
	echo "  <td class='caption'>";
	if ($this->allFolded)
	    echo "<a href=\"javascript:fold('paper', 0, 9)\" class='foldbutton unfolder9'>+</a>",
		"<a href=\"javascript:fold('paper', 1, 9)\" class='foldbutton folder9'>&minus;</a> ";
	if (!($flags & self::NOCAPTION))
	    echo "Paper</td>\n";
	echo "  <td class='entry'>",
	    $Me->paperStatus($prow->paperId, $prow, ($flags & self::STATUS_DATE) != 0);
	if ($flags & self::STATUS_DOWNLOAD)
	    echo " ", paperDownload($prow);
	if (($flags & self::STATUS_CONFLICTINFO) && $prow->conflictType == CONFLICT_AUTHOR)
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

    function echoTitleRow($prow) {
	echo "<tr class='pt_title'>\n";
	echo "  ", $this->tdCaption("title"), "Title</td>\n";
	echo "  ", $this->tdEntry("title");
	$this->echoEntryData("title", $prow, 1);
	echo "</td>\n</tr>\n\n";
    }

    function echoDownloadRow($prow, $flags) {
	$final = (($flags & self::FINALCOPY) != 0 && $prow->outcome > 0);
	$canUpload = $this->editable;
	//$canUpload = ($this->editable && (!$prow || $final || $prow->timeSubmitted <= 0));
	
	echo "<tr class='pt_paper'>\n";
	echo "  ", $this->tdCaption('paper');
	if (!($flags & self::NOCAPTION))
	    echo "Paper", ($flags & self::OPTIONAL ? " (optional)" : "");
	echo "</td>\n";
	echo "  ", $this->tdEntry("paper");

	if ($final && $prow->finalPaperStorageId > 0)
	    echo "Final copy: ", paperDownload($prow, true), " &nbsp;|&nbsp; Submission: ", paperDownload($prow);
	else if ($prow && $prow->size > 0)
	    echo ($canUpload && $final ? "Submission: " : ""), paperDownload($prow);	
	if ($canUpload)
	    echo ($prow && $prow->size > 0 ? "<br />\n  " : ""),
		($final ? "Final copy upload:" : "Upload:"), "&nbsp; ",
		"<input class='textlite' type='file' name='paperUpload' accept='application/pdf application/postscript' size='30' />";

	echo "</td>\n";
	if ($canUpload)
	    echo "  ", $this->tdHint("paper"), "Max size: ", ini_get("upload_max_filesize"), "B</td>\n";
	echo "</tr>\n\n";
    }

    function echoAbstractRow($prow) {
	echo "<tr class='pt_abstract'>\n";
	echo "  ", $this->tdCaption("abstract"), "Abstract</td>\n";
	echo "  ", $this->tdEntry("abstract");
	$this->echoEntryData("abstract", $prow, 5);
	echo "</td>\n</tr>\n\n";
    }

    function echoAuthorInformation($prow) {
	global $Conf;
	echo "<tr class='pt_authorInformation'>\n";
	echo "  ", $this->tdCaption("authorInformation");
	if ($this->authorsFolded)
	    echo "<a href=\"javascript:fold('authors', 0, 8)\" class='foldbutton unfolder8'>+</a>",
	    "<a href=\"javascript:fold('authors', 1, 8)\" class='foldbutton folder8'>&minus;</a> ";
	echo "Authors</td>\n";
	echo "  ", $this->tdEntry("authorInformation");
	if ($this->authorsFolded == 1)
	    echo"<span class='ellipsis8'><i>Hidden for blind review</i></span><span class='extension8'>";
	$this->echoEntryData("authorInformation", $prow, 5, true);
	if ($this->authorsFolded == 1)
	    echo "</span>";
	echo "</td>\n";
	if ($this->editable) {
	    echo "  ", $this->tdHint("authorInformation"), "List the paper's authors one per line, including any affiliations.  Example: <pre class='entryexample'>Bob Roberts (UCLA)
Ludwig van Beethoven (Colorado)
Zhang, Ping Yen (INRIA)</pre>";
	    if ($Conf->blindSubmission() == 2)
		echo " Submission is blind, so author information will not be shown to the PC or to external reviewers.";
	    echo "</td>\n";
	}
	echo "</tr>\n\n";
    }

    function echoNewContactAuthor($pulldown) {
	global $Me, $Conf;
	echo "<tr class='pt_contactAuthor'>\n";
	echo "  ", $this->tdCaption('contactAuthor'), "Contact author</td>\n";
	echo "  ", $this->tdEntry('contactAuthor');
	if ($pulldown)
	    contactPulldown("contact", "contact", $Conf, $Me);
	else
	    echo contactHtml($Me);
	echo "</td>\n";
	echo "  ", $this->tdHint('contactAuthor'), "You will be able to add more contact authors after you submit the paper.</td>\n";
    }

    function echoContactAuthor($prow, $editMode = null) {
	global $Conf, $ConfSiteBase;
	$result = $Conf->qe("select firstName, lastName, email, contactId
		from ContactInfo
		join PaperConflict using (contactId)
		where paperId=$prow->paperId and conflictType=" . CONFLICT_AUTHOR . "
		order by lastName, firstName, email", "while finding contact authors");
	echo "<tr class='pt_contactAuthor'>\n";
	echo "  ", $this->tdCaption("contactAuthor");
	echo (edb_nrows($result) == 1 ? "Contact author" : "Contact authors");
	echo "</td>\n  ", $this->tdEntry('contactAuthor');
	if ($result) {
	    $aus = array();
	    while (($row = edb_row($result))) {
		$au = contactText($row[0], $row[1], $row[2]);
		$aus[] = $au;
	    }
	    echo authorTable($aus);
	}
	if ($editMode || ($editMode === null && $this->editable))
	    echo "<a href='${ConfSiteBase}contactauthors.php?paperId=$prow->paperId'>Edit&nbsp;contact&nbsp;authors</a>";
	echo "</td>\n</tr>\n\n";
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
	$this->echoEntryData("collaborators", $prow, 6, true);
	echo "</td>\n";
	if ($this->editable) {
	    echo "  ", $this->tdHint("collaborators"), "List people and
	institutions with which the authors have conflicts of interest.
	This usually includes recent coauthors and collaborators, and
	any advisor/student relationships.  No need to list people at the
	authors' own institutions.";
	    if (!$Conf->setting("sub_pcconf"))
		echo "  Be sure to include conflicted <a href='${ConfSiteBase}pc.php'>PC members</a>.";
	    echo "  We use this information when assigning PC and external
	reviewers.  Use the same format as for authors,	above.</td>\n";
	}
	echo "</tr>\n\n";
    }

    function echoTopics($prow) {
	global $Conf;
	$topicMode = (int) $this->useRequest;
	if (!$this->editable)
	    //    || ($prow && (($prow->timeSubmitted > 0
	    //		   || $prow->timeWithdrawn > 0))))
	    $topicMode = -1;
	if ($topicTable = topicTable($prow, $topicMode, $Conf)) { 
	    echo "<tr class='pt_topics'>\n";
	    echo "  ", $this->tdCaption("topics"), "Topics</td>\n";
	    echo "  ", $this->tdEntry("topics"), $topicTable, "</td>\n";
	    echo "</tr>\n\n";
	}
    }

    function echoOptions($prow, $showAll) {
	global $Conf;
	if ((!$this->editable && defval($prow->optionIds, "") == "")
	    || !($opt = paperOptions()))
	    return;
	$optsel = array_flip(explode(",", defval($prow->optionIds, "")));
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
		    && ($o->pcView || $showAll || $prow->conflictType == CONFLICT_AUTHOR)) {
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
    
    function echoPCConflicts($prow) {
	global $Conf;
	if ($this->editable && !$Conf->setting("sub_pcconf"))
	    return;
	$pcm = pcMembers();
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
		if (defval($conflict[$id]) > 0) {
		    $c .= " checked='checked'";
		    if ($conflict[$id] != CONFLICT_AUTHORMARK)
			$c .= " disabled='disabled'";
		}
		$pcconfs[] = $c . " onchange='highlightUpdate()' /> " . contactHtml($p->firstName, $p->lastName);
	    }
	    echo "<tr class='pt_conflict_ass'><td class='caption'>PC conflicts</td>\n  <td class='entry'><table class='rpad'><tr><td>";
	    $n = intval((count($pcconfs) + 2) / 3);
	    for ($i = 0; $i < count($pcconfs); $i++) {
		if (($i % $n) == 0 && $i)
		    echo "</td><td class='colmid'>";
		echo $pcconfs[$i], "<br />";
	    }
	    echo "</td></tr></table></td>\n  <td class='hint'>Select the PC members who have conflicts of interest with this paper.</td></tr>\n\n";
	    
	} else {
	    foreach ($pcm as $id => $p)
		if (defval($conflict[$id]))
		    $pcconfs[] = contactHtml($p->firstName, $p->lastName);
	    if ($prow->pcPaper)
		$pcconfs[] = "<b>PC paper</b>";
	    if (!isset($pcconfs))
		$pcconfs[] = "None";
	    echo "<tr class='pt_conflict'><td class='caption'>PC conflicts</td><td class='entry'>", authorTable($pcconfs, false), "</td></tr>\n\n";
	}
    }

    function echoLead($prow) {
	if ($prow->leadContactId) {
	    $pc = pcMembers();
	    echo "<tr class='pt_conflict'><td class='caption'>Discussion lead</td><td class='entry'>";
	    if (($row = defval($pc[$prow->leadContactId], null)))
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
	    if (($row = defval($pc[$prow->shepherdContactId], null)))
		echo contactHtml($row->firstName, $row->lastName);
	    else
		echo "(Unknown contact ID!)";
	    echo "</td></tr>\n";
	}
    }

    function echoTags($prow, $site = null) {
	global $ConfSiteBase;
	$t = defval($prow->paperTags, "");
	if ($site || $t) {
	    $t = str_replace('#0', '', $t);
	    echo "<tr class='pt_tags'>\n  <td class='caption'>Tags</td>\n  <td class='entry'>";
	    if ($site) {
		echo "<div id='foldtags' class='foldc'><form method='post' action=\"$site\" enctype='multipart/form-data'>";
		echo "<span class='ellipsis'>", ($t == "" ? "none" : htmlspecialchars($t)), "&nbsp;</span>";
		echo "<a href='javascript:fold(\"tags\", 0)'><img class='unfolder' src='${ConfSiteBase}images/next.png' alt='[Edit]' /></a>";
		echo "<span class='extension'><input class='textlite' type='text' size='40' name='tags' value=\"", htmlspecialchars($t), "\" /> &nbsp;<input class='button_small' type='submit' name='settags' value='Set tags' /> &nbsp; ";
		echo "<a href='javascript:fold(\"tags\", 1)'><img class='folder' src='${ConfSiteBase}images/prev.png' alt='[Hide]' /></a></span>";
		echo "</form></div>";
	    } else
		echo htmlspecialchars($t);
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
