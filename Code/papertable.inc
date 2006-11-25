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
    var $trClasses;
    var $autrClasses;
    var $foldsession;
    var $foldState;
    var $highlight;
    
    function PaperTable($editable, $useRequest, $allFolded, $authorsFolded,
			$foldsession = null) {
	$this->editable = $editable;
	$this->useRequest = $useRequest;

	$this->foldsession = $foldsession;
	$foldState = ($foldsession ? defval($_SESSION[$foldsession], 3) : 3);
	$this->foldState = $foldState;
	
	$this->allFolded = $allFolded;
	if ($allFolded)
	    $this->trClasses = ($foldState & 2 ? " fold1ed" : " unfold1ed");
	else
	    $this->trClasses = "";
	
	$this->authorsFolded = $authorsFolded;
	if ($authorsFolded)
	    $this->autrClasses = ($foldState & 1 ? " folded" : " unfolded");
	else
	    $this->autrClasses = "";
	$this->autrClasses .= $this->trClasses;

	$this->highlight = (defval($_SESSION["whichList"]) == "list" && isset($_SESSION["matchPreg"]));
    }

    function tdCaption($what) {
	global $PaperError, $authorFoldedRows;
	$c = "<td class='caption";
	if (isset($PaperError[$what]))
	    $c .= " error";
	if ($this->allFolded)
	    $c .= " extension1";
	if ($this->authorsFolded && isset($authorFoldedRows[$what]))
	    $c .= " extension";
	return $c . "'>";
    }

    function tdEntry($what) {
	global $authorFoldedRows, $textAreaRows;
	$c = "<td class='entry";
	if ($this->allFolded)
	    $c .= " extension1";
	if ($this->authorsFolded && isset($authorFoldedRows[$what]))
	    $c .= " extension";
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
	    $c .= " extension1";
	if ($this->authorsFolded && isset($authorFoldedRows[$what]))
	    $c .= " extension";
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

	if ($this->highlight && isset($textAreaRows[$fieldName]))
	    $text = preg_replace($_SESSION["matchPreg"], "<span class='match'>\$1</span>", htmlspecialchars($text));
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
	    echo preg_replace($_SESSION["matchPreg"], "<span class='match'>\$1</span>", htmlspecialchars($prow->title));
	else
	    echo htmlspecialchars($prow->title);
    }
    
    function echoStatusRow($prow, $flags) {
	global $Me;
	echo "<tr class='pt_status", $this->trClasses, "' id='foldst'>\n";
	echo "  <td class='caption'>";
	if ($this->allFolded) {
	    $fs = ($this->foldsession ? ";foldsession(['au','st'], '$this->foldsession')" : "");
	    echo "<a href=\"javascript:fold(['st','ti','pa','ab','ca','au','co','to'], 0, 1)$fs\" class='foldbutton unfolder1'>+</a>",
		"<a href=\"javascript:fold(['st','ti','pa','ab','ca','au','co','to'], 1, 1)$fs\" class='foldbutton folder1'>&minus;</a> ";
	}
	if (!($flags & self::NOCAPTION))
	    echo "Paper</td>\n";
	echo "  <td class='entry'>",
	    $Me->paperStatus($prow->paperId, $prow, ($flags & self::STATUS_DATE) != 0);
	if ($flags & self::STATUS_DOWNLOAD)
	    echo " ", paperDownload($prow);
	if (($flags & self::STATUS_CONFLICTINFO) && $prow->author > 0)
	    echo "<br/>\nYou are an <span class='author'>author</span> of this paper.";
	else if ((($flags & self::STATUS_CONFLICTINFO) && $prow->conflict > 0)
		 || (($flags & self::STATUS_CONFLICTINFO_PC) && $Me->isPC && $prow->conflict > 0))
	    echo "<br/>\nYou have a <span class='conflict'>conflict</span> with this paper.";
	if ($prow->reviewType != null && ($flags & self::STATUS_REVIEWERINFO)) {
	    if ($prow->reviewType == REVIEW_PRIMARY)
		echo "<br/>\nYou are a primary reviewer for this paper.";
	    else if ($prow->reviewType == REVIEW_SECONDARY)
		echo "<br/>\nYou are a secondary reviewer for this paper.";
	    else if ($prow->reviewType == REVIEW_REQUESTED)
		echo "<br/>\nYou were requested to review this paper.";
	    else
		echo "<br/>\nYou began a review for this paper.";
	}
	echo "</td>\n</tr>\n\n";
    }

    function echoTitleRow($prow) {
	echo "<tr class='pt_title$this->trClasses' id='foldti'>\n";
	echo "  ", $this->tdCaption("title"), "Title</td>\n";
	echo "  ", $this->tdEntry("title");
	$this->echoEntryData("title", $prow, 1);
	echo "</td>\n</tr>\n\n";
    }

    function echoDownloadRow($prow, $flags) {
	$final = (($flags & self::FINALCOPY) != 0 && $prow->outcome > 0);
	$canUpload = ($this->editable && (!$prow || $final || $prow->timeSubmitted <= 0));
	
	echo "<tr class='pt_paper$this->trClasses' id='foldpa'>\n";
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
	    echo ($prow && $prow->size > 0 ? "<br />\n  " : ""), "<input class='textlite' type='file' name='paperUpload' accept='application/pdf application/postscript' size='30' />";

	echo "</td>\n";
	if ($canUpload)
	    echo "  ", $this->tdHint("paper"), "Max size: ", ini_get("upload_max_filesize"), "B</td>\n";
	echo "</tr>\n\n";
    }

    function echoAbstractRow($prow) {
	echo "<tr class='pt_abstract$this->trClasses' id='foldab'>\n";
	echo "  ", $this->tdCaption("abstract"), "Abstract</td>\n";
	echo "  ", $this->tdEntry("abstract");
	$this->echoEntryData("abstract", $prow, 5);
	echo "</td>\n</tr>\n\n";
    }

    function echoAuthorInformation($prow) {
	global $Conf;
	echo "<tr class='pt_authorInformation$this->autrClasses' id='foldau'>\n";
	echo "  ", $this->tdCaption("authorInformation");
	if ($this->authorsFolded) {
	    $fs = ($this->foldsession ? ";foldsession(['au','st'], '$this->foldsession')" : "");
	    echo "<a href=\"javascript:fold(['au','ca','co'], 0)$fs\" class='foldbutton unfolder'>+</a>",
	    "<a href=\"javascript:fold(['au','ca','co'], 1)$fs\" class='foldbutton folder'>&minus;</a> ";
	}
	echo "Authors</td>\n";
	echo "  ", $this->tdEntry("authorInformation");
	if ($this->authorsFolded == 1)
	    echo"<span class='ellipsis'><i>Hidden for blind review</i></span><span class='extension'>";
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
	echo "<tr class='pt_contactAuthor$this->autrClasses' id='foldca'>\n";
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
		where paperId=$prow->paperId and author=1
		order by lastName, firstName, email", "while finding contact authors");
	echo "<tr class='pt_contactAuthor$this->autrClasses' id='foldca'>\n";
	echo "  ", $this->tdCaption("contactAuthor");
	echo (!MDB2::isError($result) && $result->numRows() == 1 ? "Contact author" : "Contact authors");
	echo "</td>\n  ", $this->tdEntry('contactAuthor');
	if (!MDB2::isError($result)) {
	    $aus = array();
	    while (($row = $result->fetchRow())) {
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
	echo "<tr class='pt_collaborators$this->autrClasses' id='foldco'>\n";
	echo "  ", $this->tdCaption("collaborators"), "Collaborators</td>\n";
	echo "  ", $this->tdEntry("collaborators");
	$this->echoEntryData("collaborators", $prow, 5, true);
	echo "</td>\n";
	if ($this->editable)
	    echo "  ", $this->tdHint("collaborators"), "List the authors' recent (~2 years) coauthors and collaborators, and any advisor or student relationships.  Be sure to include PC members when appropriate.  We use this information to avoid conflicts of interest when reviewers are assigned.  Use the same format as for authors, above.</td>\n";
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
	    echo "<tr class='pt_topics$this->trClasses' id='foldto'>\n";
	    echo "  ", $this->tdCaption("topics"), "Topics</td>\n";
	    echo "  ", $this->tdEntry("topics"), $topicTable, "</td>\n";
	    echo "</tr>\n\n";
	}
    }

    function echoPCConflicts($prow) {
	global $Conf;
	$q = "select firstName, lastName
		from ContactInfo
		join PCMember using (contactId)
		join PaperConflict using (contactId)
		where paperId=$prow->paperId group by ContactInfo.contactId";
	$result = $Conf->qe($q, "while finding conflicted PC members");
	if (!MDB2::isError($result)) {
	    while ($row = $result->fetchRow())
		$pcConflicts[] = "$row[0] $row[1]";
	    if (!isset($pcConflicts))
		$pcConflicts[] = "None";
	    echo "<tr class='pt_conflict'>\n  <td class='caption'>PC conflicts</td>\n  <td class='entry'>", authorTable($pcConflicts), "</td>\n</tr>\n\n";
	}
    }

    function echoTags($prow, $site = null) {
	global $ConfSiteBase;
	$t = defval($prow->paperTags, "");
	if ($site || $t) {
	    echo "<tr class='pt_tags'>\n  <td class='caption'>Tags</td>\n  <td class='entry'>";
	    if ($site) {
		echo "<div id='foldtags' class='folded'><form method='post' action=\"$site\" enctype='multipart/form-data'>";
		echo "<span class='ellipsis'>", ($t == "" ? "none" : htmlspecialchars($t)), "&nbsp;</span>";
		echo "<a href='javascript:fold(\"tags\", 0)'><img class='unfolder' src='${ConfSiteBase}images/next.png' alt='[Edit]' /></a>";
		echo "<span class='extension'><input class='textlite' type='text' size='40' name='tags' value=\"", htmlspecialchars($t), "\" /> <input class='button_small' type='submit' name='settags' value='Set tags' /> &nbsp; ";
		echo "<a href='javascript:fold(\"tags\", 1)'><img class='folder' src='${ConfSiteBase}images/prev.png' alt='[Hide]' /></a></span>";
		echo "</form></div>";
	    } else
		echo htmlspecialchars($t);
	    echo "</td>\n</tr>\n\n";
	}
    }

    function echoOutcomeSelector($prow) {
	echo "<tr class='pt_outcome'>
  <td class='caption'>Outcome</td>
  <td class='entry'><form method='get' action='review.php'><div class='inform'><input type='hidden' name='paperId' value='$prow->paperId' />";
	if (isset($_REQUEST['forceShow']))
	    echo "<input type='hidden' name='forceShow' value='", $_REQUEST['forceShow'], "' />";
	echo outcomeSelector($prow->outcome);
	echo "&nbsp;<input class='button_small' type='submit' name='setoutcome' value='Set outcome' /></div></form></td>\n</tr>\n";
    }

}
