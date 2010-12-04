<?php
// papertable.inc -- HotCRP helper class for producing paper tables
// HotCRP is Copyright (c) 2006-2010 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

global $textAreaRows;
$textAreaRows = array("title" => 1, "abstract" => 12, "authorInformation" => 5,
		      "collaborators" => 6);

class PaperTable {

    const ENABLESUBMIT = 8;

    const STATUS_DATE = 16;
    const STATUS_CONFLICTINFO = 64;
    const STATUS_CONFLICTINFO_PC = 128;
    const STATUS_REVIEWERINFO = 256;

    const PAPERLINKS_TYPE = 2;

    var $prow;
    var $rrows;
    var $crows;
    var $mycrows;
    var $rrow;
    var $editrrow;
    var $mode;
    var $allreviewslink;

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
	if ($Me->canViewReview($prow, null))
	    $ms["r"] = true;
	if ($Me->canReview($prow, null))
	    $ms["re"] = true;
	if ($prow->conflictType >= CONFLICT_AUTHOR
	    && ($Conf->timeFinalizePaper($prow) || $prow->timeSubmitted <= 0))
	    $ms["pe"] = true;
	if ($Me->canViewPaper($prow))
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
	if ($this->mode == "p" && isset($ms["r"]))
	    $this->mode = "r";
    }

    function initialize($editable, $useRequest) {
	global $thisMatchPreg, $CurrentList;

	$this->editable = $editable;
	$this->useRequest = $useRequest;

	$this->foldState = 1023;
	$pfold = defval($_REQUEST, "pfold");
	foreach (array("a" => 8, "p" => 9, "b" => 6, "t" => 5) as $k => $v) {
	    if ($k == "a")
		$svar = ($this->mode == "assign" ? "foldassigna" : null);
	    else
		$svar = "foldpaper$k";
	    if ($pfold && substr($pfold, 0, 1) == $v) {
		$sval = (substr($pfold, 1, 1) == "o" ? 0 : 1);
		if ($svar)
		    $_SESSION[$svar] = $sval;
		if (!$sval)
		    $this->foldState &= ~(1 << $v);
	    } else if ($svar && !defval($_SESSION, $svar, 1))
		$this->foldState &= ~(1 << $v);
	}

	$this->allFolded = ($this->mode == "re" || $this->mode == "assign"
			    || ($this->mode != "pe" && (count($this->rrows) || count($this->crows))));

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
	$this->watchCheckbox = WATCH_COMMENT;
	$this->initial = true;
    }

    function echoDivEnter($prow = null) {
	// if highlighting, automatically unfold abstract/authors
	if ($this->highlight && $prow && $this->allFolded
	    && ($this->foldState & 64)) {
	    $data = $this->entryData("abstract", $prow);
	    if ($this->entryMatches)
		$this->foldState &= ~64;
	}
	if ($this->highlight && $prow && ($this->foldState & 256)) {
	    cleanAuthor($prow);
	    $data = $this->entryData("authorInformation", $prow);
	    if ($this->entryMatches)
		$this->foldState &= ~(256 | 512);
	}

	echo "<div id='foldpaper' class='",
	    ($this->foldState & 256 ? "fold8c" : "fold8o"),
	    ($this->foldState & 512 ? " fold9c" : " fold9o"),
	    ($this->foldState & 64 ? " fold6c" : " fold6o"),
	    ($this->foldState & 32 ? " fold5c" : " fold5o"), "'>";
    }

    function echoDivExit() {
	echo "</div>";
    }

    function papt($what, $name, $extra = array()) {
	global $Error, $Conf, $ConfSiteSuffix;
	$type = defval($extra, "type", "pap");
	$c = "<div class='${type}t";
	if (isset($Error[$what]))
	    $c .= " error";
	if (isset($extra["extraclass"]))
	    $c .= " " . $extra["extraclass"];
	$c .= "'><span class='${type}fn'>";
	$editfolder = defval($extra, "editfolder", false);
	if (!($fold = defval($extra, "fold", false))) {
	    $n = (is_array($name) ? $name[0] : $name);
	    if ($editfolder)
		$c .= "<a class='q fn' title='Edit' "
		    . "href=\"" . selfHref(array("atab" => $what))
		    . "\" onclick='return fold(\"$editfolder\", 0)'>"
		    . $n . "</a><span class='fx'>" . $n . "</span>";
	    else
		$c .= $n;
	} else {
	    $foldnum = defval($extra, "foldnum", 0);
	    if (!is_array($name))
		$name = array($name, $name);
	    $c .= "<a class='q fn$foldnum' "
		. "href=\"" . selfHref(array("pfold" => $foldnum . "o"))
		. "\" onclick='return fold(\"$fold\", 0, $foldnum)' "
		. "title=\"" . defval($extra, "showtitle", "Show")
		. "\">+&nbsp;" . $name[1] . "</a>"
		. "<a class='q fx$foldnum' "
		. "href=\"" . selfHref(array("pfold" => $foldnum . "c"))
		. "\" onclick='return fold(\"$fold\", 1, $foldnum)' "
		. "title=\"" . defval($extra, "hidetitle", "Hide")
		. "\">&minus;&nbsp;" . $name[0] . "</a>";
	    if (($foldsession = defval($extra, "foldsession", false)))
		$c .= foldsessionpixel($fold . $foldnum, $foldsession);
	}
	$c .= "</span>";
	if ($editfolder) {
	    $x = defval($extra, "oneditunfold", "");
	    $a = "<a class='fn' href=\"" . selfHref(array("atab" => $what))
		. "\" onclick='" . $x . ($x ? ";" : "")
		. "return fold(\"$editfolder\", 0)' title='Edit'>";
	    $c .= "<span class='hint'>" . $a
		. $Conf->cacheableImage("edit.png", "[Edit]", null, "b")
		. "</a>&nbsp;" . $a . "Edit</a></span>";
	}
	$c .= "<div class='clear'></div></div>";
	return $c;
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
	    $text = highlightMatch($this->matchPreg[$fieldName], htmlspecialchars($text), $this->entryMatches);
	else
	    $text = htmlspecialchars($text);

	if ($authorTable == "col" && !$this->editable)
	    $text = nl2br($text);

	if ($this->editable)
	    $text = "<textarea class='textlite papertext' name='$fieldName' rows='" . $textAreaRows[$fieldName] . "' cols='60' onchange='hiliter(this)'>" . $text . "</textarea>";
	return $text;
    }

    function echoTitle($prow) {
	if ($this->highlight && isset($this->matchPreg["title"]))
	    echo highlightMatch($this->matchPreg["title"], htmlspecialchars($prow->title));
	else
	    echo htmlspecialchars($prow->title);
    }

    function paptabTitle($prow) {
	echo $this->papt("title", "Title"),
	    "<div class='papv'>",
	    $this->entryData("title", $prow),
	    "</div>\n\n";
    }

    function pdfStamps($data) {
	global $Conf, $Opt;

	$t = array();
	$tm = defval($data, "timestamp", defval($data, "timeSubmitted", 0));
	if ($tm > 0)
	    $t[] = "<span class='nowrap' title='Time of most recent update'>" . $Conf->cacheableImage("_.gif", "Updated", "Time of most recent update", "timestamp12") . " " . $Conf->printableTime($tm) . "</span>";
	$sha1 = defval($data, "sha1");
	if ($sha1 && !defval($Opt, "disableSHA1"))
	    $t[] = "<span class='nowrap' title='SHA-1 checksum'>" . $Conf->cacheableImage("_.gif", "SHA-1", "SHA-1 checksum", "checksum12") . " " . bin2hex($sha1) . "</span>";
	if (count($t) > 0)
	    return "<span class='hint'>" . join(" &nbsp;<span class='barsep'>|</span>&nbsp; ", $t) . "</span>";
	else
	    return "";
    }

    function paptabDownload($prow) {
	global $Conf, $Me, $ConfSiteSuffix;
	assert(!$this->editable);
	$final = ($prow->outcome > 0 && $Conf->collectFinalPapers());
	$out = array();

	// status and download
	if ($prow && $Me->canDownloadPaper($prow)) {
	    $t = "<td class='nowrap'>" . $Me->paperStatus($prow->paperId, $prow, 0) . "</td>";
	    if ($prow->outcome > 0 && $prow->finalPaperStorageId > 0
		&& $Conf->collectFinalPapers()) {
		$data = paperDocumentData($prow, -1);
		$t .= "<td class='nowrap'>Final copy: &nbsp;"
		    . documentDownload($data)
		    . "<br /><small><a class='black' href=\""
		    . $Conf->makeDownloadPath($prow->paperId, null, 0)
		    . "\">Submission version</a></small></td>";
	    } else {
		$data = paperDocumentData($prow, 0);
		if ($data && $data->paperStorageId > 1)
		    $t .= "<td class='nowrap'>" . documentDownload($data) . "</td>";
	    }
	    if ($data && ($stamps = $this->pdfStamps($data)))
		$t .= "<td><span class='sep'></span></td><td>$stamps</td>";
	    $out[] = "<table><tr>$t</tr></table>";
	}

	// conflicts
	$flags = self::STATUS_CONFLICTINFO;
	if (($flags & self::STATUS_CONFLICTINFO) && $prow->conflictType >= CONFLICT_AUTHOR)
	    $out[] = "You are an <span class='author'>author</span> of this paper.";
	else if ((($flags & self::STATUS_CONFLICTINFO) && $prow->conflictType > 0)
		 || (($flags & self::STATUS_CONFLICTINFO_PC) && $Me->isPC && $prow->conflictType > 0))
	    $out[] = "You have a <span class='conflict'>conflict</span> with this paper.";
	if ($prow->reviewType != null && ($flags & self::STATUS_REVIEWERINFO)) {
	    if ($prow->reviewType == REVIEW_PRIMARY)
		$out[] = "You are a primary reviewer for this paper.";
	    else if ($prow->reviewType == REVIEW_SECONDARY)
		$out[] = "You are a secondary reviewer for this paper.";
	    else if ($prow->reviewType == REVIEW_EXTERNAL)
		$out[] = "You were requested to review this paper.";
	    else
		$out[] = "You began a review for this paper.";
	}
	if ($Me->isPC && $prow->conflictType <= 0
	    && $Conf->timeUpdatePaper($prow) && $this->mode != "assign"
	    && $this->mode != "contact")
	    $out[] = "<div class='xwarning'>The authors still have <a href='deadlines$ConfSiteSuffix'>time</a> to make changes.</div>";

	echo join("<div class='g'></div>\n", $out);
    }

    function echoUploadRow($prow, $flags) {
	global $Conf, $ConfSiteSuffix, $Me, $Opt;
	$final = ($prow && $prow->outcome > 0
		  && ($Me->canSubmitFinalPaper($prow, $whyNot, true)
		      || defval($whyNot, "deadline") == "final_done"));
	$maxsize = " <span class='papfnh'>(max size "
	    . ini_get("upload_max_filesize") . "B)</span>";

	if (defval($Opt, "noPapers"))
	    $t = ($final ? null : "Status");
	else if (defval($Opt, "disablePS"))
	    $t = "PDF file" . $maxsize;
	else if (defval($Opt, "disablePDF"))
	    $t = "PostScript file" . $maxsize;
	else
	    $t = "PDF/PostScript file" . $maxsize;
	if ($t !== null && $final)
	    $t = "Final copy $t";
	if ($t !== null)
	    echo $this->papt("paper", $t);
	echo "<div class='papv'>";

	// current version, if any
	$d = "";
	$doc = null;
	if ($prow && $Me->canDownloadPaper($prow)
	    && ($final ? $prow->finalPaperStorageId > 0 : $prow->paperStorageId > 1)) {
	    $doc = paperDocumentData($prow, $final ? -1 : 0);
	    if ($doc) {
		$d .= "<td class='nowrap'>" . documentDownload($doc) . "</td>";
		if ($doc->mimetype == "application/pdf"
		    && $Conf->setting("sub_banal"))
		    $d .= "<td><span class='sep'></span></td><td><input class='b' type='submit' name='checkformat' value='Check format' onclick='return docheckformat()' /></td>";
		if (($stamps = $this->pdfStamps($doc)))
		    $d .= "<td><span class='sep'></span></td><td>$stamps</td>";
	    }
	}
	if ($d)
	    echo "<table><tr>", $d, "</tr></table>\n";

	// uploader
	$uploader = "";
	if (!defval($Opt, "noPapers")) {
	    $uploader = ($doc ? "<div class='g'></div>\nUpload replacement:" : "Upload:")
		. "&nbsp; <input id='paperUpload' class='textlite' type='file' name='paperUpload' accept='"
		. (defval($Opt, "disablePDF") ? "" : "application/pdf ")
		. (defval($Opt, "disablePS") ? "" : "application/postscript ")
		. "' size='30' onchange='hiliter(this)";
	    if (!$final)
		$uploader .= ";fold(\"isready\",0)";
	    if ($flags & self::ENABLESUBMIT)
		$uploader .= ";form.submit.disabled=false";
	    $uploader .= "' />";
	}

	if ($prow
	    && ($final ? $prow->finalPaperStorageId : $prow->paperStorageId) > 0
	    && defval($prow, "mimetype", "application/pdf") == "application/pdf"
	    && $Conf->setting("sub_banal")) {
	    echo "<div id='foldcheckformat' class='foldc'><div id='checkformatformresult' class='fx'><div class='xinfo'>Checking format, please wait (this can take a while)...</div></div></div>";
	    $Conf->footerHtml("<form id='checkformatform' class='fold7o' method='post' action='paper$ConfSiteSuffix?p=$prow->paperId");
	    if ($final)
		$Conf->footerHtml("&amp;final=1");
	    $Conf->footerHtml("' enctype='multipart/form-data' accept-charset='UTF-8' onsubmit='return Miniajax.submit(\"checkformatform\")'><input type='hidden' name='checkformat' value='1' /></form>");
	}

	if (!$final && (!$doc || $Conf->setting("sub_freeze"))) {
	    echo $uploader;
	    $uploader = "";
	}

	if (!$final) {
	    if ($this->useRequest)
		$checked = defval($_REQUEST, "submit");
	    else if ($Conf->setting('sub_freeze'))
		$checked = $prow && $prow->timeSubmitted > 0;
	    else
		$checked = !$prow || $prow->paperStorageId <= 1 || $prow->timeSubmitted > 0;
	    $s = ($doc ? " style='margin-top: 0.5ex'" : "");
	    echo "<div id='foldisready' class='",
		(($prow && $prow->paperStorageId > 1) || defval($Opt, "noPapers") ? "foldo" : "foldc"),
		"'$s><table class='fx'><tr><td class='nowrap'>",
		tagg_checkbox_h("submit", 1, $checked, array("id" => "paperisready")), "&nbsp;";
	    if ($Conf->setting('sub_freeze'))
		echo "</td><td>", tagg_label("<strong>This is the final submission.</strong>"),
		    "</td></tr><tr><td></td><td><small>You must submit a final version before the deadline or your paper will not be reviewed.  Once you submit a final version you will not be able to make further changes.</small>";
	    else
		echo tagg_label("The paper is ready for review.");
	    echo "</td></tr></table></div>\n";
	    $Conf->footerScript("hotcrp_onload.push(function(){var x=\$\$(\"paperUpload\");if(x&&x.value)fold(\"isready\",0)})");
	} else
	    echo "<input type='hidden' name='submit' value='1' />";

	echo $uploader;

	echo "</div>\n\n";
    }

    function paptabAbstract($prow) {
	$data = $this->entryData("abstract", $prow);
	if ($this->allFolded && strlen($data) > 190) {
	    $shortdata = preg_replace("/\\S+(<[^>]+)?\\Z/", "", substr($data, 0, 180));
	    if ($shortdata != "") { /* "" might happen if really long word */
		echo $this->papt("abstract", "Abstract",
				 array("fold" => "paper", "foldnum" => 6,
				       "foldsession" => "foldpaperb",
				       "showtitle" => "Show full abstract",
				       "hidetitle" => "Abbreviate abstract")),
		    "<div class='papv abstract'>",
		    "<span class='fn6'>", $shortdata,
		    " <a class='fn6' href='javascript:void fold(\"paper\", 0, 6)'>[more]</a>",
		    "</span><span class='fx6'>", $data,
		    "</span></div>\n\n";
		return;
	    }
	}
	echo $this->papt("abstract", "Abstract"),
	    "<div class='papv abstract'>", $data, "</div>\n\n";
    }

    function echoAuthorInformation($prow) {
	global $Conf;
	if ($prow)
	    cleanAuthor($prow);

	echo $this->papt("authorInformation", "Authors <span class='papfnh'>(<a href='javascript:void authorfold(\"auedit\",1,1)'>More</a> | <a href='javascript:void authorfold(\"auedit\",1,-1)'>Fewer</a>)</span>"),
	    "<div class='paphint'>List the paper&rsquo;s authors one per line, including their email addresses and affiliations.";
	if ($Conf->blindSubmission() == BLIND_ALWAYS)
	    echo " Submission is blind, so reviewers will not be able to see author information.";
	echo "  Any author with an account on this site can edit the paper.</div>",
	    "<div class='papv'><table id='auedittable' class='auedittable'><tr><th></th><th>Name</th><th>Email</th><th>Affiliation</th></tr>\n";

	$blankAu = array("", "", "", "");
	if ($this->useRequest && isset($_REQUEST["authorTable"]))
	    $authorTable = $_REQUEST["authorTable"];
	else
	    $authorTable = ($prow ? $prow->authorTable : array());
	for ($n = 1; $n <= 25; $n++) {
	    $au = ($n <= count($authorTable) ? $authorTable[$n - 1] : $blankAu);
	    if ($au[0] && $au[1] && !preg_match('@^\s*(v[oa]n\s+|d[eu]\s+)?\S+(\s+jr.?|\s+sr.?|\s+i+)?\s*$@i', $au[1]))
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
	echo "</table><input id='aueditcount' type='hidden' name='aueditcount' value='25' /></div>\n\n";
	$Conf->echoScript("authorfold(\"auedit\",0," . max(count($authorTable) + 1, 5) . ")");
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
		$n = abbreviateNameHtml($au[0], $au[1]);
		if ($highpreg !== false) {
		    $n = highlightMatch($highpreg, $n, $nm);
		    $this->entryMatches += $nm;
		}
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
		    $n = highlightMatch($highpreg, htmlspecialchars($n), $nm);
		    $this->entryMatches += $nm;
		} else
		    $n = htmlspecialchars($n);
		if ($au[2] != "" && preg_match("/ &lt;.*&gt;\\Z/", $n, $m))
		    $n = substr($n, 0, strlen($n) - strlen($m[0]) + 5)
			. "<a href=\"mailto:" . htmlspecialchars($au[2]) . "\">"
			. substr($m[0], 5, strlen($m[0]) - 9)
			. "</a>&gt;";
		if ($au[2] != "" && $viewAs !== null && $viewAs->email != $au[2]
		    && $viewAs->privChair && defval($au, 4))
		    $n .= " <a href=\"" . selfHref(array("viewContact" => $au[2])) . "\">" . $Conf->cacheableImage("viewas.png", "[Act as]", "Act as " . contactNameHtml($au)) . "</a>";
		$names[] = $n;
	    }
	    return join("<br />\n", $names);
	}
    }

    function paptabAuthors($prow) {
	global $Conf, $Me, $forceShow;

	$viewable = $Me->canViewAuthors($prow, $forceShow);
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
		where paperId=$prow->paperId and conflictType>=" . CONFLICT_AUTHOR . "
		order by lastName, firstName, email", "while finding contacts");
	$contacts = array();
	while (($row = edb_row($result))) {
	    $match = -1;
	    for ($i = 0; $match < 0 && $i < count($prow->authorTable); ++$i)
		if ($prow->authorTable[$i][2] == $row[2])
		    $match = $i;
	    if (($row[0] != "" || $row[1] != "") && $match < 0) {
		$contact_n = $row[0] . " " . $row[1];
		$contact_preg = str_replace("\\.", "\\S*", "{\\b" . preg_quote($row[0]) . "\\b.*\\b" . preg_quote($row[1]) . "\\b}i");
		for ($i = 0; $match < 0 && $i < count($prow->authorTable); ++$i) {
		    $f = $prow->authorTable[$i][0];
		    $l = $prow->authorTable[$i][1];
		    if (($f != "" || $l != "") && $prow->authorTable[$i][2] == "") {
			$author_n = $f . " " . $l;
			$author_preg = str_replace("\\.", "\\S*", "{\\b" . preg_quote($f) . "\\b.*\\b" . preg_quote($l) . "\\b}i");
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
	    echo "<div class='fn8'>",
		$this->papt("authorInformation", "Authors"),
		"<div class='papv'><a class='q fn8' ",
		"href='javascript:void fold(\"paper\", 0, 8)' title='Show authors'>",
		"+&nbsp;<i>Hidden for blind review</i></a>";
	    if ($this->mode == "assign")
		echo foldsessionpixel("paper8", "foldassigna");
	    echo "</div></div>\n",
		"<div class='fx8'>";
	    $inauthors = "<a class='q fx8' "
		. "href='javascript:void fold(\"paper\", 1, 8)' "
		. "title='Hide authors'>[blind]</a> ";
	    $afterauthors = "</div>\n";
	} else {
	    $inauthors = $afterauthors = "";
	    if ($Conf->blindSubmission() == BLIND_OPTIONAL && $prow->blind)
		$inauthors = "[blind] ";
	}

	// "author" or "authors"?
	$auname = pluralx(count($prow->authorTable), "Author");

	// actually print
	if ($this->allFolded) {
	    echo $this->papt("authorInformation", $auname,
			     array("fold" => "paper", "foldnum" => 9,
				   "foldsession" => "foldpaperp",
				   "showtitle" => "Show full authors",
				   "hidetitle" => "Show abbreviated authors")),
		"<div class='papv'>", $inauthors,
		"<span class='fn9'>",
		$this->authorData($prow->authorTable, "last"),
		" <a class='fn9' href='javascript:void fold(\"paper\", 0, 9)'>[details]</a>",
		"</span><span class='fx9'>",
		$this->authorData($prow->authorTable, "col", $Me),
		"</span>";

	} else {
	    echo $this->papt("authorInformation", $auname),
		"<div class='papv'>", $inauthors,
		$this->authorData($prow->authorTable, "col", $Me);
	}

	echo "</div>\n";

	if (count($contacts) > 0) {
	    echo "<div class='fx9'>",
		$this->papt("authorInformation", pluralx(count($contacts), "Contact author")),
		"<div class='papv'>",
		$this->authorData($contacts, "col", $Me),
		"</div></div>\n\n";
	}

	echo $afterauthors, "\n";
    }

    function paptabTopicsOptions($prow, $showAllOptions) {
	global $Conf, $Me, $forceShow;
	$topicdata = topicTable($prow, -1);
	$optionhtml = array();
	$ndocuments = 0;
	if (($options = paperOptions())) {
	    preg_match_all('/(\d+)#(\d+)/', defval($prow, "optionIds", ""), $m);
	    $optsel = array();
	    $optdata = null;
	    for ($i = 0; $i < count($m[1]); ++$i)
		$optsel[$m[1][$i]] = $m[2][$i];
	    foreach ($options as $o) {
		$ov = defval($optsel, $o->optionId, 0);
		if (($ov || $o->type)
		    && ($showAllOptions
			|| $Me->canViewPaperOption($prow, $o))) {
		    $on = htmlspecialchars($o->optionName);
		    $ox = "";
		    if ($o->type == OPTIONTYPE_CHECKBOX)
			$ox = $on;
		    else if ($o->type == OPTIONTYPE_SELECTOR) {
			$om = explode("\n", $o->optionValues);
			if (defval($om, $ov, "") != "")
			    $ox = "$on: <span class=\"optvalue\">" . htmlspecialchars($om[$ov]) . "</span>";
		    } else if ($o->type == OPTIONTYPE_NUMERIC && $ov != "")
			$ox = "$on: <span class=\"optvalue\">" . htmlspecialchars($ov) . "</span>";
		    else if ($o->type == OPTIONTYPE_TEXT && $ov != "") {
			if ($optdata == null) {
			    $optdata = array();
			    $result = $Conf->qe("select optionId, data from PaperOption where paperId=$prow->paperId", "while selecting paper options");
			    while (($row = edb_row($result)))
				$optdata[$row[0]] = $row[1];
			}
			if ($optdata[$o->optionId] != "")
			    $ox = "$on: <span class=\"optvalue\">" . htmlspecialchars($optdata[$o->optionId]) . "</span>";
		    } else if ($o->type == OPTIONTYPE_PDF && $ov) {
			$optionhtml[] = "<a href=\"" . $Conf->makeDownloadPath($prow->paperId, "application/pdf", $o->optionId) . "\">" . $Conf->cacheableImage("pdf.png", "[PDF]", null, "sdlimg") . "&nbsp;" . $on . "</a>";
			++$ndocuments;
		    }
		    if ($ox != "")
			$optionhtml[] = $ox . "<br />\n";
		}
	    }
	}

	if ($topicdata != "" || count($optionhtml)) {
	    $infotypes = array();
	    if ($ndocuments > 0)
		$infotypes[] = "Documents";
	    if (count($optionhtml) != $ndocuments)
		$infotypes[] = "Options";
	    $options_name = commajoin($infotypes);
	    if ($topicdata != "")
		array_unshift($infotypes, "Topics");
	    $tanda = commajoin($infotypes);

	    if ($this->allFolded) {
		$extra = array("fold" => "paper", "foldnum" => 5,
			       "foldsession" => "foldpapert",
			       "showtitle" => "Show " . strtolower($tanda),
			       "hidetitle" => "Hide " . strtolower($tanda));
		$before = "<div class='papv fn5'></div>";
		$eclass = " fx5";
	    } else {
		$extra = null;
		$before = $eclass = "";
	    }

	    if ($topicdata != "") {
		echo $this->papt("topics", array("Topics", $tanda), $extra),
		    $before,
		    "<div class='papv$eclass'>", $topicdata, "</div>\n\n";
		if ($extra)
		    $extra = array("extraclass" => "fx5");
		$before = "";
		$tanda = $options_name;
	    }

	    if (count($optionhtml)) {
		echo $this->papt("options", array($options_name, $tanda), $extra),
		    $before,
		    "<div class='papv$eclass'>", join("", $optionhtml), "</div>\n\n";
	    }
	}
    }

    function echoNewContactAuthor($pulldown) {
	global $Me, $Conf;
	echo $this->papt("contactAuthor", "Contact"),
	    "<div class='paphint'>You will be able to add more contacts after you register the paper.</div>",
	    "<div class='papv'>";
	if ($pulldown)
	    contactPulldown("contact", "contact", $Conf, $Me);
	else
	    echo contactHtml($Me);
	echo "</div>\n\n";
    }

    function echoContactAuthor($prow, $editMode = null) {
	global $Conf, $ConfSiteSuffix, $Me;
	$result = $Conf->qe("select firstName, lastName, email, contactId
		from ContactInfo
		join PaperConflict using (contactId)
		where paperId=$prow->paperId and conflictType=" . CONFLICT_CONTACTAUTHOR . "
		order by lastName, firstName, email", "while finding contacts");
	echo $this->papt("contactAuthor", pluralx(edb_nrows($result), "Contact")),
	    "<div class='papv'>";
	if ($result) {
	    $aus = array();
	    while (($row = edb_row($result)))
		$aus[] = $row;
	    echo authorTable($aus, $Me);
	}
	if ($editMode || ($editMode === false && ($this->editable || $Me->privChair)))
	    echo "(<a href='contactauthors$ConfSiteSuffix?p=$prow->paperId'>Edit&nbsp;contacts</a>)";
	echo "</div>\n\n";
    }

    function echoAnonymity($prow) {
	global $Conf, $Opt;
	$blind = ($this->useRequest ? isset($_REQUEST['blind']) : (!$prow || $prow->blind));
	assert(!!$this->editable);
	echo $this->papt("blind", tagg_checkbox_h("blind", 1, $blind)
			 . "&nbsp;" . tagg_label("Anonymous submission")),
	    "<div class='paphint'>", htmlspecialchars($Opt["shortName"]), " allows either anonymous or named submission.  Check this box to submit the paper anonymously (reviewers won&rsquo;t be shown the author list).  Make sure you also remove your name from the paper itself!</div>\n",
	    "<div class='papv'></div>\n\n";
    }

    function echoCollaborators($prow) {
	global $Conf, $ConfSiteSuffix;
	if (!$Conf->setting("sub_collab"))
	    return;
	$sub_pcconf = $Conf->setting("sub_pcconf");
	assert(!!$this->editable);

	echo $this->papt("collaborators", ($sub_pcconf ? "Other conflicts" : "Potential conflicts")),
	    "<div class='paphint'>";
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
	echo "  List one conflict per line.  For example: &ldquo;<tt>Jelena Markovic (EPFL)</tt>&rdquo; or, for a whole institution, &ldquo;<tt>EPFL</tt>&rdquo;.</div>",
	    "<div class='papv'>",
	    $this->entryData("collaborators", $prow),
	    "</div>\n\n";
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
	    "<div class='psv'><div class='fx'>", $data,
	    "</div></div></div>\n\n";
    }

    function echoTopics($prow) {
	global $Conf;
	assert(!!$this->editable);
	$topicMode = (int) $this->useRequest;
	if (($topicTable = topicTable($prow, $topicMode))) {
	    echo $this->papt("topics", "Topics"),
		"<div class='paphint'>Select any topics that apply to your paper.</div>",
		"<div class='papv'>", $topicTable, "</div>\n\n";
	}
    }

    function echoOptions($prow, $showAll) {
	global $Conf;
	$prow_optionIds = isset($prow->optionIds) ? $prow->optionIds : "";
	if (!($opt = paperOptions()))
	    return;
	assert(!!$this->editable);
	preg_match_all('/(\d+)#(\d+)/', $prow_optionIds, $m);
	$optsel = array();
	for ($i = 0; $i < count($m[1]); ++$i)
	    $optsel[$m[1][$i]] = $m[2][$i];
	$optdata = null;
	foreach ($opt as $o) {
	    $optid = "opt$o->optionId";
	    if ($this->useRequest)
		$myval = defval($_REQUEST, $optid);
	    else if ($o->type != OPTIONTYPE_TEXT)
		$myval = defval($optsel, $o->optionId);
	    else {
		if (!$optdata && $prow) {
		    $optdata = array();
		    $result = $Conf->qe("select optionId, data from PaperOption where paperId=$prow->paperId", "while reading options");
		    while (($row = edb_row($result)))
			$optdata[$row[0]] = $row[1];
		}
		$myval = defval($optdata, $o->optionId);
	    }

	    if ($o->type == OPTIONTYPE_CHECKBOX) {
		echo $this->papt($optid, tagg_checkbox_h($optid, 1, $myval)
				 . "&nbsp;" . tagg_label(htmlspecialchars($o->optionName)));
		if ($o->description)
		    echo "<div class='paphint'>", $o->description, "</div>";
		echo "<div class='papv'></div>\n\n";
	    } else if ($o->type < OPTIONTYPE_PDF) {
		echo $this->papt($optid, htmlspecialchars($o->optionName));
		if ($o->description)
		    echo "<div class='paphint'>", $o->description, "</div>";
		if ($o->type == OPTIONTYPE_SELECTOR)
		    echo "<div class='papv'>", tagg_select("opt$o->optionId", explode("\n", $o->optionValues), $myval, array("onchange" => "hiliter(this)")), "</div>\n\n";
		else if ($o->type == OPTIONTYPE_NUMERIC)
		    echo "<div class='papv'><input type='text' class='textlite' name='$optid' value=\"", htmlspecialchars($myval), "\" size='8' onchange='hiliter(this)' /></div>\n\n";
		else
		    echo "<div class='papv'><input type='text' class='textlite papertext' name='$optid' value=\"", htmlspecialchars($myval), "\" size='40' onchange='hiliter(this)' /></div>\n\n";
	    } else {
		echo $this->papt($optid, htmlspecialchars($o->optionName)
				 . " <span class='papfnh'>(PDF, max size "
				 . ini_get("upload_max_filesize")
				 . "B)</span>");
		if ($o->description)
		    echo "<div class='paphint'>", $o->description, "</div>";
		echo "<div class='papv'>";
		$doc = null;
		if (defval($optsel, $o->optionId)) {
		    $doc = paperDocumentData($prow, $o->optionId, $optsel[$o->optionId]);
		    if ($doc) {
			$d = "<td class='nowrap'>" . documentDownload($doc) . "</td>";
			if (($stamps = $this->pdfStamps($doc)))
			    $d .= "<td><span class='sep'></span></td><td>$stamps</td>";
			echo "<table id='current_$optid'><tr>", $d,
			    "</tr></table>\n<div class='g'></div>\n",
			    "<div id='replacement_$optid' class='foldo'>",
			    "<span class='fx'>";
		    }
		}
		echo ($doc ? "Upload replacement:" : "Upload:"),
		    "&nbsp; <input class='textlite' type='file' name='$optid' accept='application/pdf' size='30' onchange='hiliter(this)' />";
		if ($doc) {
		    echo " &nbsp;<span class='barsep'>|</span>&nbsp; ",
			"<button type='button' class='b' onclick='doremovedocument(this, \"$optid\")' />Remove option</button>",
			"</span><span class='fn'><em>This option has been marked for removal.</em></span>",
			"<input id='remove_$optid' type='hidden' name='remove_$optid' value='' />",
			"</div>";
		}
		echo "</div>\n\n";
	    }
	}
    }

    function echoPCConflicts($prow) {
	global $Conf, $authorConflictTypes;

	assert(!!$this->editable);
	if (!$Conf->setting("sub_pcconf"))
	    return;
	$pcm = pcMembers();
	if (!count($pcm))
	    return;

	$maxauthormark = ($Conf->sversion >= 22 ? CONFLICT_MAXAUTHORMARK : CONFLICT_AUTHORMARK);
	$selectors = ($maxauthormark == CONFLICT_MAXAUTHORMARK && $Conf->setting("sub_pcconfsel"));

	$conflict = array();
	if ($this->useRequest) {
	    foreach ($pcm as $id => $row)
		if (isset($_REQUEST["pcc$id"])
		    && ($ct = cvtint($_REQUEST["pcc$id"])) > 0)
		    $conflict[$id] = max(min($ct, $maxauthormark), CONFLICT_AUTHORMARK);
	}
	if ($prow) {
	    $result = $Conf->qe("select contactId, conflictType from PaperConflict where paperId=$prow->paperId", "while finding conflicted PC members");
	    while (($row = edb_row($result)))
		if ($row[1] < CONFLICT_AUTHORMARK || $row[1] > $maxauthormark
		    || !$this->useRequest)
		    $conflict[$row[0]] = $row[1];
	}

	$pcconfs = array();
	if ($selectors) {
	    foreach ($pcm as $id => $p) {
		$c = "<tr><td class='pctbname0 pctbl'>". contactHtml($p->firstName, $p->lastName) . "</td><td class='pctbconfsel'>";
		if (isset($conflict[$id]) && $conflict[$id] > 0
		    && ($conflict[$id] < CONFLICT_AUTHORMARK
			|| $conflict[$id] > $maxauthormark)) {
		    if ($conflict[$id] >= CONFLICT_AUTHOR)
			$c .= "<strong>Author</strong>";
		    else
			$c .= "<strong>Conflict</strong>";
		} else
		    $c .= tagg_select("pcc$id", $authorConflictTypes,
				      defval($conflict, $id, 0),
				      array("onchange" => "hiliter(this)",
					    "class" => "pctbconfselector"));
		$c .= "</td></tr>\n";
		$pcconfs[] = $c;
	    }
	    $tclass = " style='padding-left:0'><table class='pctb'";
	    $topen = "<td class='pctbcolleft'><table>";
	    $tswitch = "</table></td><td class='pctbcolmid'><table>";
	    $tclose = "</table>";
	} else {
	    foreach ($pcm as $id => $p) {
		$checked = defval($conflict, $id) > 0;
		$disabled = $checked && ($conflict[$id] < CONFLICT_AUTHORMARK || $conflict[$id] > $maxauthormark);
		$cbox = tagg_checkbox_h("pcc$id", CONFLICT_AUTHORMARK, $checked, array($disabled => $disabled));
		$aff = ($p->affiliation === "" ? "" : "<div class='pcconfaff'>" . htmlspecialchars($p->affiliation) . "</div>");
		$label = tagg_label(contactHtml($p->firstName, $p->lastName) . $aff);
		if ($aff !== "")
		    $pcconfs[] = "<table><tr><td>$cbox&nbsp;</td><td>$label</td></table>\n";
		else
		    $pcconfs[] = "$cbox&nbsp;$label<br />\n";
	    }
	    $tclass = "><table";
	    $topen = "<td class='rpad'>";
	    $tclose = "";
	    $tswitch = "</td><td class='rpad'>";
	}

	echo $this->papt("pcconf", "PC conflicts"),
	    "<div class='paphint'>Select the PC members who have conflicts of interest with this paper.  ", $Conf->conflictDefinitionText(), "</div>\n",
	    "<div class='papv'", $tclass, "><tr>", $topen;
	$n = ($selectors
	      ? intval((count($pcconfs) + 1) / 2)
	      : intval((count($pcconfs) + 2) / 3));
	for ($i = 0; $i < count($pcconfs); $i++) {
	    if (($i % $n) == 0 && $i)
		echo $tswitch;
	    echo $pcconfs[$i];
	}
	echo $tclose, "</td></tr></table></div>\n\n";
    }

    function papstripPCConflicts($prow) {
	global $Conf;
	assert(!$this->editable);
	$pcm = pcMembersAndAdmins();
	if (!count($pcm) || !$prow)
	    return;
	$conflict = array();
	$result = $Conf->qe("select contactId, conflictType from PaperConflict where paperId=$prow->paperId", "while finding conflicted PC members");
	while (($row = edb_row($result)))
	    $conflict[$row[0]] = $row[1];
	$pcconfs = array();
	foreach ($pcm as $id => $p)
	    if (defval($conflict, $id))
		$pcconfs[] = contactHtml($p->firstName, $p->lastName);
	if ($prow->pcPaper)
	    $pcconfs[] = "<b>PC paper</b>";
	if (!count($pcconfs))
	    $pcconfs[] = "None";

	echo $this->papt("pcconflict", "PC conflicts", array("type" => "ps")),
	    "<div class='psv'>", authorTable($pcconfs), "</div>\n";
    }

    function _papstripLeadShepherd($prow, $type, $name, $showedit, $wholefold) {
	global $Conf, $ConfSiteSuffix, $Me, $Error, $linkExtra;
	$editable = $Me->actChair($prow);

	$field = $type . "ContactId";
	if ($prow->$field == 0 && !$editable)
	    return;
	$value = $prow->$field;
	$pc = pcMembers();

	if ($editable) {
	    echo "<form id='${type}form' class='fold7o' method='post' action='review$ConfSiteSuffix?p=", $prow->paperId, "&amp;post=1$linkExtra' enctype='multipart/form-data' accept-charset='UTF-8' onsubmit='return Miniajax.submit(\"${type}form\")'>",
		"<div id='fold$type' class='", ($showedit ? "foldo" : "foldc"), ($wholefold ? " fold2c" : " fold2o"), " inform'><div class='fx2'>",
		"<input type='hidden' name='set$type' value='1' />";
	    $Conf->footerScript("Miniajax.onload(\"${type}form\")");
	}

	echo $this->papt($type, $name, array("type" => "ps", "editfolder" => ($editable && !$showedit ? $type : 0))),
	    "<div class='psv'>";

	if ($editable) {
	    $opt = array("0" => "None");
	    foreach ($pc as $row)
		$opt[htmlspecialchars($row->email)] = contactHtml($row->firstName, $row->lastName);

	    echo "<div class='fx'>",
		tagg_select($type, $opt,
			    ($value && isset($pc[$value]) ? htmlspecialchars($pc[$value]->email) : "0"),
			    array("onchange" => "Miniajax.submit(\"${type}form\")",
				  "id" => "fold${type}_d")),
		" <input class='b fx7' type='submit' value='Save' />",
		" <span id='${type}formresult'></span>",
		"</div><div class='fn'>";
	}

	if (!$value)
	    echo "None";
	else if (!isset($pc[$value]))
	    echo "Unknown!";
	else
	    echo contactHtml($pc[$value]->firstName, $pc[$value]->lastName);

	echo "</div>";
	if ($editable)
	    echo "</div></div></div></form>";
	echo "\n";
    }

    function papstripLead($prow, $showedit) {
	$this->_papstripLeadShepherd($prow, "lead", "Discussion lead", $showedit || defval($_REQUEST, "atab") == "lead", false);
    }

    function papstripShepherd($prow, $showedit, $fold) {
	$this->_papstripLeadShepherd($prow, "shepherd", "Shepherd", $showedit || defval($_REQUEST, "atab") == "shepherd", $fold);
    }

    function papstripTags($prow, $site = null) {
	global $Conf, $ConfSiteSuffix, $Me, $Error, $forceShow, $linkExtra;
	if ($site || defval($prow, "paperTags", "") !== "") {
	    // Note that tags MUST NOT contain HTML special characters.
	    require_once("tags.inc");
	    $tx = tagsToText($prow, true, $Me);
	    $editable = $site && $Me->canSetTags($prow, $forceShow);
	    $unfolded = $editable && (isset($Error["tags"]) || defval($_REQUEST, "atab") == "tags");

	    if ($editable)
		echo "<form method='post' action=\"$site\" enctype='multipart/form-data' accept-charset='UTF-8'><div id='foldtags' class='", ($unfolded ? "foldo" : "foldc"), " inform'>";

	    $colors = new TagColorizer($Me);
	    if (($color = $colors->match($prow->paperTags)))
		echo "<div class='", $color, "tag psshaper'>";

	    echo $this->papt("tags", "Tags", array("type" => "ps", "editfolder" => ($editable ? "tags" : 0), "oneditunfold" => "Miniajax.submit(\"tagreportform\")")),
		"<div class='psv'>";
	    if ($editable) {
		// tag report form
		$Conf->footerHtml("<form id='tagreportform' class='fold7o' method='post' action='paper$ConfSiteSuffix?p=$prow->paperId$linkExtra' enctype='multipart/form-data' accept-charset='UTF-8' onsubmit='return Miniajax.submit(\"tagreportform\")'><div class='aahc'><input type='hidden' name='tagreport' value='1' /></div></form>");

		$t = tagsToText($prow, false, $Me, true);
		echo "<div class='fn'>", ($tx == "" ? "None" : $tx),
		    "</div><div class='fx'><div id='tagreportformresult'>";
		if ($unfolded) {
		    require_once("paperactions.inc");
		    echo PaperActions::tagReport($prow, true);
		}
		echo "</div>";
		if (isset($Error["tags"]))
		    echo "<div class='xmerror'>", $Error["tags"], "</div>";
		echo "<textarea id='foldtags_d' cols='20' rows='2' name='tags' onkeypress='return crpSubmitKeyFilter(this, event)'>", $t, "</textarea><br />",
		    "<input type='hidden' name='settingtags' value='1' />",
		    "<input class='bsm' type='submit' value='Save' /> &nbsp; ",
		    "<input class='bsm' type='submit' name='cancelsettags' value='Cancel' onclick='return fold(\"tags\", 1)' /><br />",
		    "<span class='hint'><a href='help$ConfSiteSuffix?t=tags'>Learn more</a> &nbsp;<span class='barsep'>|</span>&nbsp; <strong>Tip:</strong> Twiddle tags like &ldquo;~tag&rdquo; are visible only to you.</span>",
		    "</div>";
	    } else
		echo ($tx == "" ? "None" : $tx);
	    echo "</div>";

	    if ($color)
		echo "</div>";
	    if ($editable)
		echo "</div></form>";
	    echo "\n";
	}
    }

    function papstripOutcomeSelector($prow, $showedit) {
	global $Conf, $ConfSiteSuffix, $rf, $Error, $linkExtra;
	if (!$rf)
	    $rf = reviewForm();
	echo "<div id='folddecision' class='",
	    ($showedit || defval($_REQUEST, "atab") == "decision" ? "foldo" : "foldc"), "'>",
	    $this->papt("decision", "Decision", array("type" => "ps", "editfolder" => ($showedit ? 0 : "decision"))),
	    "<div class='psv'><form id='decisionform' class='fx fold7o' method='post' action='review$ConfSiteSuffix?p=", $prow->paperId, "&amp;post=1$linkExtra' enctype='multipart/form-data' accept-charset='UTF-8' onsubmit='return dosubmitdecision()'>",
	    "<div class='inform'>",
	    "<input type='hidden' name='setdecision' value='1' />";
	if (isset($_REQUEST["forceShow"]))
	    echo "<input type='hidden' name='forceShow' value='", $_REQUEST['forceShow'], "' />";
	echo decisionSelector($prow->outcome, null, " onchange='dosubmitdecision()' id='folddecision_d'"),
	    " <input class='b fx7' type='submit' value='Save' />",
	    " <span id='decisionformresult'></span>",
	    "</div></form><div class='fn'>",
	    $rf->options["outcome"][$prow->outcome],
	    "</div></div></div>\n";
	$Conf->footerScript("Miniajax.onload(\"decisionform\")");
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
	    " <input class='b fx7' type='submit' value='Save' />",
	    " <span id='revprefformresult'></span>",
	    "</div></form></div>\n";
	$Conf->footerScript("Miniajax.onload(\"revprefform\")");
    }

    function papstripRank($prow) {
	global $Conf, $ConfSiteSuffix, $Me, $linkExtra;
	if (!($tag = $Conf->settingText("tag_rank")))
	    return;

	// load rank
	$tagsearch = " " . $Me->contactId . "~" . $tag;
	if (($pos = strpos(" " . $prow->paperTags, $tagsearch)) !== false) {
	    $pos += strlen($tagsearch) - 1;
	    if ($pos == strlen($prow->paperTags)
		|| $prow->paperTags[$pos] != "#")
		$rp = "0";
	    else if (($xpos = strpos($prow->paperTags, " ", $pos + 1)))
		$rp = substr($prow->paperTags, $pos + 1, $xpos - $pos - 1);
	    else
		$rp = substr($prow->paperTags, $pos + 1);
	} else
	    $rp = "";

	// rank context form
	$Conf->footerHtml("<form id='rankctxform' class='fold7o' method='post' action='paper$ConfSiteSuffix?p=$prow->paperId' enctype='multipart/form-data' accept-charset='UTF-8' onsubmit='return Miniajax.submit(\"rankctxform\")'><div class='aahc'><input type='hidden' name='rankctx' value='1' /></div></form>");

	echo "<div id='foldrank' class='foldc fold2c'>",
	    $this->papt("rank", "Your rank", array("type" => "ps", "editfolder" => "rank")),
	    "<div class='psv'><form id='rankform' class='fx fold7o' method='post' action='review$ConfSiteSuffix?p=", $prow->paperId, "&amp;post=1$linkExtra' enctype='multipart/form-data' accept-charset='UTF-8' onsubmit='return Miniajax.submit(\"rankform\")'>",
	    "<div class='inform'>",
	    "<input type='hidden' name='setrank' value='1' />";
	if (isset($_REQUEST["forceShow"]))
	    echo "<input type='hidden' name='forceShow' value='", $_REQUEST['forceShow'], "' />";
	echo "<input id='foldrank_d' class='textlite' type='text' size='4' name='rank' value=\"$rp\" onchange='Miniajax.submit(\"rankform\")' tabindex='1' />",
	    " <input class='b fx7' type='submit' value='Save' />",
	    " <span id='rankformresult'></span>",
	    " <div class='hint'><strong>Tip:</strong> Use <a href='offline$ConfSiteSuffix'>offline reviewing</a> to rank many papers at once.</div>",
	    "</div></form>",
	    "<div class='fn'>",
	    ($rp === "" ? "None" : $rp);
	if ($rp != "")
	    echo "<span class='fn2'> &nbsp; <a href='javascript:void 0' onclick='fold(\"rank\", 0, 2);void Miniajax.submit(\"rankctxform\")'>(context)</a>";
	echo "</div>",
	    "<div id='rankctxformresult' class='fx2'>Loading...</div>",
	    "</div></div>\n";
	$Conf->footerScript("Miniajax.onload(\"rankform\")");
    }

    function papstripWatch($prow) {
	global $Conf, $ConfSiteSuffix, $Me, $linkExtra;
	if (!($this->watchCheckbox
	      && $Conf->sversion >= 6
	      && $prow->timeSubmitted > 0
	      && ($prow->conflictType >= CONFLICT_AUTHOR
		  || $prow->conflictType <= 0
		  || ($Me->privChair && defval($_REQUEST, "forceShow")))))
	    return;
	// watch note
	$result = $Conf->q("select ContactInfo.contactId, reviewType, commentId, conflictType, watch from ContactInfo left join PaperReview on (PaperReview.paperId=$prow->paperId and PaperReview.contactId=ContactInfo.contactId) left join PaperComment on (PaperComment.paperId=$prow->paperId and PaperComment.contactId=ContactInfo.contactId) left join PaperConflict on (PaperConflict.paperId=$prow->paperId and PaperConflict.contactId=ContactInfo.contactId) left join PaperWatch on (PaperWatch.paperId=$prow->paperId and PaperWatch.contactId=ContactInfo.contactId) where ContactInfo.contactId=$Me->contactId");
	$row = edb_row($result);

	echo "<form id='watchform' class='fold7o' action=\"comment$ConfSiteSuffix?p=$prow->paperId&amp;post=1$linkExtra\" method='post' enctype='multipart/form-data' accept-charset='UTF-8' onsubmit='return Miniajax.submit(\"watchform\")'>",
	    "<div class='inform'>",
	    "<input type='hidden' name='setwatch' value='1' />";

	if ($row[4] && ($row[4] & ($Me->defaultWatch >> 1)))
	    $watchValue = $row[4];
	else if ($row[1] || $row[2] || $row[3] >= CONFLICT_AUTHOR)
	    $watchValue = $Me->defaultWatch;
	else
	    $watchValue = 0;

	echo $this->papt("watch",
			 tagg_checkbox("watch", $this->watchCheckbox,
				       $watchValue & $this->watchCheckbox,
				       array("onchange" => "Miniajax.submit('watchform')"))
			 . "&nbsp;" . tagg_label("Comment notification"),
			 array("type" => "ps")),
	    "<div class='pshint'>If selected, you will receive email when updated comments are available for this paper. <span id='watchformresult'></span></div>",
	    "<div class='papv'>",
	    "<input class='b fx7' type='submit' value='Save' />",
	    "</div></div></form>\n\n";

	$Conf->footerScript("Miniajax.onload(\"watchform\")");
    }


    // Functions for editing

    function deadlineSettingIs($dname) {
	global $Conf;
	$deadline = $Conf->printableTimeSetting($dname, "span");
	if ($deadline == "N/A")
	    return "";
	else if (time() < $Conf->setting($dname))
	    return "  The deadline is $deadline.";
	else
	    return "  The deadline was $deadline.";
    }

    function editMessage() {
	global $Conf, $ConfSiteSuffix, $Me;
	$prow = $this->prow;
	$m = "";

	$override = ($Me->privChair ? "  As an administrator, you can override this deadline using the &ldquo;Override deadlines&rdquo; checkbox." : "");
	if (!$prow) {
	    $timeStart = $Conf->timeStartPaper();
	    $startDeadline = $this->deadlineSettingIs("sub_reg");
	    if (!$timeStart) {
		if ($Conf->setting("sub_open") <= 0)
		    $msg = "You can&rsquo;t register new papers because the conference site has not been opened for submissions.$override";
		else
		    $msg = "You can&rsquo;t register new papers since the <a href='deadlines$ConfSiteSuffix'>deadline</a> has passed.$startDeadline$override";
		if (!$Me->privChair)
		    errorMsgExit($msg);
		$m .= "<div class='xinfo'>" . $msg . "</div>";
	    } else {
		$m .= "<div class='xinfo'>" . "Enter information about your paper.  ";
		if ($startDeadline && !$Conf->setting("sub_freeze"))
		    $m .= "You can make changes until the deadline, but thereafter ";
		else
		    $m .= "You don&rsquo;t have to upload the paper itself right away, but ";
		$m .= "incomplete submissions will not be considered.$startDeadline" . "</div>";
	    }
	} else if ($prow->conflictType >= CONFLICT_AUTHOR
		   && ($Conf->timeUpdatePaper($prow)
		       || $prow->timeSubmitted <= 0)) {
	    $timeUpdate = $Conf->timeUpdatePaper($prow);
	    $updateDeadline = $this->deadlineSettingIs("sub_update");
	    $timeSubmit = $Conf->timeFinalizePaper($prow);
	    $submitDeadline = $this->deadlineSettingIs("sub_sub");
	    if ($prow->timeWithdrawn > 0) {
		if ($timeUpdate)
		    $m .= "<div class='xinfo'>This paper has been withdrawn, but you can still revive it.$updateDeadline</div>";
		// otherwise no message
	    } else if ($timeUpdate) {
		if ($prow->timeSubmitted <= 0) {
		    $m .= "<div class='xwarning'>";
		    if ($Conf->setting('sub_freeze'))
			$m .= "A final version of this paper must be submitted before it can be reviewed.";
		    else if ($prow->paperStorageId <= 1)
			$m .= "The paper is not ready for review and will not be considered as is, but you can still make changes.";
		    else
			$m .= "The paper is not ready for review and will not be considered as is, but you can still mark it ready for review and make other changes if appropriate.";
		    $m .= $updateDeadline . "</div>";
		} else if ($this->mode == "pe")
		    $m .= "<div class='xconfirm'>This paper is ready and will be considered for review.  You can still make changes if necessary.$updateDeadline</div>";
	    } else if ($timeSubmit)
		$m .= "<div class='xwarning'>You cannot make any changes as the <a href='deadlines$ConfSiteSuffix'>deadline</a> has passed, but the current version can still be submitted.  Only submitted papers will be reviewed.$submitDeadline$override</div>";
	    else if ($Conf->settingsBetween("", "sub_grace", "sub_sub"))
		$m .= "<div class='xwarning'>The site is not open for submission updates at the moment.$override</div>";
	    else
		$m .= "<div class='xwarning'>The <a href='deadlines$ConfSiteSuffix'>deadline</a> for submitting this paper has passed.  The paper will not be reviewed.$submitDeadline$override</div>";
	} else if ($prow->conflictType >= CONFLICT_AUTHOR && $prow->outcome > 0
		   && $Conf->timeSubmitFinalPaper()) {
	    $updateDeadline = $this->deadlineSettingIs("final_soft");
	    $m .= "<div class='xinfo'>" . "Congratulations!  This paper was accepted.  Submit a final copy for the paper here.$updateDeadline  You may also withdraw the paper (in extraordinary circumstances) or edit paper contacts, allowing others to view reviews and make changes." . "</div>";
	} else if ($prow->conflictType >= CONFLICT_AUTHOR) {
	    $override2 = ($Me->privChair ? "  However, as an administrator, you can update the paper anyway by selecting \"Override deadlines\"." : "");
	    if ($this->mode == "pe")
		$m .= "<div class='xinfo'>" . "This paper is under review and can no longer be changed, although you may still withdraw it from the conference.$override2" . "</div>";
	} else
	    $m .= "<div class='xinfo'>" . "You aren&rsquo;t a contact for this paper, but as an administrator you can still make changes." . "</div>";

	return $m;
    }

    function echoActions() {
	global $Conf, $ConfSiteSuffix, $Me, $linkExtra;
	$prow = $this->prow;

	echo "<div class='aa'><table class='pt_buttons'>\n";
	$buttons = array();
	if (!$prow)
	    $buttons[] = "<input class='bb' type='submit' name='update' value='Save paper' />";
	else if ($prow->timeWithdrawn > 0
		 && ($Conf->timeFinalizePaper($prow) || $Me->privChair))
	    $buttons[] = "<input class='b' type='submit' name='revive' value='Revive paper' />";
	else if ($prow->timeWithdrawn > 0)
	    $buttons[] = "The paper has been withdrawn, and the <a href='deadlines$ConfSiteSuffix'>deadline</a> for reviving it has passed.";
	else {
	    if ($prow->outcome > 0 && $Conf->collectFinalPapers()
		&& ($Conf->timeSubmitFinalPaper() || $Me->privChair)
		&& $this->mode == "pe")
		$buttons[] = array("<input class='bb' type='submit' name='submitfinal' value='Submit final copy' />", "");
	    if ($Conf->timeUpdatePaper($prow) && $this->mode == "pe")
		$buttons[] = array("<input class='bb' type='submit' name='update' value='Save changes' />", "");
	    else if ($Me->privChair && $this->mode == "pe") {
		$class = ($prow->outcome > 0 && $Conf->collectFinalPapers() ? "b" : "bb");
		$buttons[] = array("<input class='$class' type='submit' name='update' value='Save changes' />", "(admin only)");
	    }
	    if ($prow->timeSubmitted <= 0)
		$buttons[] = "<input class='b' type='submit' name='withdraw' value='Withdraw paper' />";
	    else {
		$buttons[] = "<button type='button' class='b' onclick=\"popup(this,'w',0,true)\">Withdraw paper</button>";
		$admins = "";
		if ((!$Me->privChair || $prow->conflictType >= CONFLICT_AUTHOR)
		    && !$Conf->timeFinalizePaper($prow))
		    $admins = "Only administrators can undo this step.";
		$Conf->footerHtml("<div id='popup_w' class='popupc'>
  <p>Are you sure you want to withdraw this paper from consideration and/or
  publication?  $admins</p>
  <form method='post' action=\"paper$ConfSiteSuffix?p=" . $prow->paperId . "&amp;post=1&amp;m=pe$linkExtra\" enctype='multipart/form-data' accept-charset='UTF-8'>
    <div class='popup_actions'>
      <input class='popup_populate' type='hidden' name='doemail' value='1' />
      <input class='popup_populate' type='hidden' name='emailNote' value='' />
      <button type='button' class='b' onclick=\"popup(null, 'w', 1)\">Cancel</button>
      &nbsp;<input class='bb' type='submit' name='withdraw' value='Withdraw paper' />
    </div>
  </form></div>");
	    }
	    if (!$this->editable)
		$buttons[] = "<button type='button' class='b' onclick='document.location=\"contactauthors$ConfSiteSuffix?p=" . $prow->paperId . "$linkExtra\"'>Edit contacts</button>";
	}
	if ($Me->privChair && $prow) {
	    $buttons[] = array("<button type='button' class='b' onclick=\"popup(this,'d',0,true)\">Delete paper</button>", "(admin only)");
	    $Conf->footerHtml("<div id='popup_d' class='popupc'>
  <p>Be careful: This will permanently delete all information about this
  paper from the database and <strong>cannot be undone</strong>.</p>
  <form method='post' action=\"paper$ConfSiteSuffix?p=" . $prow->paperId . "&amp;post=1&amp;m=pe$linkExtra\" enctype='multipart/form-data' accept-charset='UTF-8'>
    <div class='popup_actions'>
      <input class='popup_populate' type='hidden' name='doemail' value='1' />
      <input class='popup_populate' type='hidden' name='emailNote' value='' />
      <button type='button' class='b' onclick=\"popup(null, 'd', 1)\">Cancel</button>
      &nbsp;<input class='bb' type='submit' name='delete' value='Delete paper' />
    </div>
  </form></div>");
	}
	echo "    <tr>\n";
	foreach ($buttons as $b) {
	    $x = (is_array($b) ? $b[0] : $b);
	    echo "      <td class='ptb_button'>", $x, "</td>\n";
	}
	echo "    </tr>\n    <tr>\n";
	foreach ($buttons as $b) {
	    $x = (is_array($b) ? $b[1] : "");
	    echo "      <td class='ptb_explain'>", $x, "</td>\n";
	}
	echo "    </tr>\n  </table></div>\n";
	if ($Me->privChair) {
	    echo "  <div class='g'></div>\n  <table>\n",
		"    <tr><td>",
		tagg_checkbox("doemail", 1, true), "&nbsp;",
		tagg_label("Email authors, including:"), "&nbsp; ",
		"<input type='text' class='textlite' name='emailNote' size='30' value='Optional explanation' onfocus=\"tempText(this, 'Optional explanation', 1)\" onblur=\"tempText(this, 'Optional explanation', 0)\" /></td></tr>\n",
		"    <tr><td>", tagg_checkbox("override"), "&nbsp;",
		tagg_label("Override deadlines"), "</td></tr>\n",
		"  </table>\n";
	}
    }


    // Functions for overall paper table viewing

    function _papstrip() {
	global $Conf, $ConfSiteSuffix, $Me, $forceShow, $linkExtra;
	$prow = $this->prow;
	if ($Me->canViewTags($prow, $forceShow))
	    $this->papstripTags($prow, "review$ConfSiteSuffix?p=$prow->paperId$linkExtra");
	if ($Me->canSetRank($prow))
	    $this->papstripRank($prow);
	$this->papstripWatch($prow);
	if (($Me->privChair || ($Me->isPC && $Me->canViewAuthors($prow, false)))
	    && !$this->editable)
	    $this->papstripPCConflicts($prow);
	if (($Me->actChair($prow) || $Me->canViewAuthors($prow, false))
	    && !$this->editable)
	    $this->papstripCollaborators($prow);

	$foldDecisions = false;
	$foldShepherd = $Me->canSetOutcome($prow) && $prow->outcome <= 0
	    && $prow->shepherdContactId == 0 && $this->mode != "assign";
	if ($Me->canSetOutcome($prow))
	    $this->papstripOutcomeSelector($prow, $foldDecisions);
	if ($Me->actPC($prow))
	    $this->papstripLead($prow, $foldDecisions || $this->mode == "assign");
	if ($Me->actPC($prow) || $Me->canViewDecision($prow))
	    $this->papstripShepherd($prow, $foldDecisions || $this->mode == "assign", $foldShepherd);

	if ($Me->actPC($prow) && $Conf->timePCReviewPreferences())
	    $this->papstripReviewPreference($prow);
    }

    function paptabBegin($contentopener = "") {
	global $Conf, $ConfSiteSuffix, $Me, $forceShow, $linkExtra;
	$prow = $this->prow;
	$paperId = ($prow ? $prow->paperId : "new");

	// what actions are supported?
	$canEdit = ($prow && $Me->canEditPaper($prow));
	$canReview = ($prow && $Me->canReview($prow, null));
	$canAssign = ($prow && $Me->actChair($prow));
	$canHome = ($prow && ($canEdit || $canAssign || $this->mode == "contact"));

	// a little extra space after messages
	if ($prow && (self::PAPERLINKS_TYPE & 2)
	    && ($canEdit || $canReview || $canAssign || $canHome)
	    && $Conf->msgcount > 0)
	    echo "<div class='msgpg'></div>\n";

	echo "<table class='pbox'>";

	// paper tabs
	if ($prow && (self::PAPERLINKS_TYPE & 2)
	    && ($canEdit || $canReview || $canAssign || $canHome)) {
	    echo "<tr>\n    <td colspan='3' class='pboxt'>",
		"<div class='psmodec'><div class='psmode'>";

	    // home link
	    $a = "<a href='paper$ConfSiteSuffix?p=$prow->paperId";
	    if ($this->mode == "pe" || $this->mode == "re")
		$a .= "&amp;m=p";
	    $a .= "$linkExtra'>";
	    $highlight = ($this->mode != "assign" && $this->mode != "pe"
			  && $this->mode != "contact" && $this->mode != "re");
	    echo "<div class='", ($highlight ? "papmodex" : "papmode"),
		"'>", $a, $Conf->cacheableImage("view18.png", "[Main]", null, "b"),
		"</a>&nbsp;", $a, "Main</a></div>\n";

	    if ($canEdit) {
		$a = "<a href='paper$ConfSiteSuffix?p=$prow->paperId&amp;m=pe$linkExtra'>";
		echo "<div class='", ($this->mode == "pe" ? "papmodex" : "papmode"),
		    "'>", $a, $Conf->cacheableImage("edit18.png", "[Edit]", null, "b"),
		    "</a>&nbsp;", $a, "Edit</a></div>\n";
	    }

	    if ($canReview) {
		$a = "<a href='review$ConfSiteSuffix?p=$prow->paperId&amp;m=re$linkExtra'>";
		echo "<div class='", ($this->mode == "re" && (!$this->editrrow || $this->editrrow->contactId == $Me->contactId) ? "papmodex" : "papmode"),
		    "'>", $a, $Conf->cacheableImage("review18.png", "[Review]", null, "b"),
		    "</a>&nbsp;", $a, "Review</a></div>\n";
	    }

	    if ($canAssign) {
		$a = "<a href='assign$ConfSiteSuffix?p=$prow->paperId$linkExtra'>";
		echo "<div class='", ($this->mode == "assign" ? "papmodex" : "papmode"),
		    "'>", $a, $Conf->cacheableImage("assign18.png", "[Assign]", null, "b"),
		    "</a>&nbsp;", $a, "Assign</a></div>\n";
	    }

	    echo "<div class='clear'></div></div></div></td>\n</tr>\n";
	}

	// paper number
	$pboxclass = ($prow ? "pbox" : "pboxn");
	echo "<tr>\n    <td class='${pboxclass}i'>";
	if ($prow) {
	    $pa = "<a href='paper$ConfSiteSuffix?p=$paperId$linkExtra' class='q'>";
	    echo "<div class='papnum'><h2>", $pa, "#", $this->prow->paperId, "</a></h2></div>";

	    // paper strip
	    echo "    <div class='papstripc'><div class='papstrip'>\n";
	    $this->_papstrip();
	    echo "</div></div>";
	}
	echo "</td>\n";

	// main paper table
	echo "    <td class='${pboxclass}t'><table class='papc'>
	<tr><td class='papculs'></td><td></td><td class='papcur'></td></tr>
	<tr><td></td><td>";

	if (self::PAPERLINKS_TYPE & 1) {
	    $t = "";
	    if ($prow && ($this->mode == "assign" || $this->mode == "pe"
			  || $this->mode == "contact")) {
		$a = "<a href='paper$ConfSiteSuffix?p=$prow->paperId";
		if ($this->mode == "pe")
		    $a .= "&amp;m=p";
		$a .= "$linkExtra'>";
		if ($t != "")
		    $t .= " &nbsp;<span class='barsep'>|</span>&nbsp; ";
		$t .= $a . $Conf->cacheableImage("view24.png", "[View]", null, "b elimg")
		    . "</a>&nbsp;" . $a . "View</a>";
	    }
	    if ($prow && $this->mode != "assign" && $this->mode != "pe"
		&& $Me->canEditPaper($prow)) {
		$a = "<a href='paper$ConfSiteSuffix?p=$prow->paperId&amp;m=pe$linkExtra'>";
		if ($t != "")
		    $t .= " &nbsp;<span class='barsep'>|</span>&nbsp; ";
		$t .= $a . $Conf->cacheableImage("edit24.png", "[Edit]", null, "b elimg")
		    . "</a>&nbsp;" . $a . "Edit</a>";
	    }
	    if ($prow && $this->mode != "assign" && $this->mode != "contact"
		&& $Me->actChair($prow)) {
		$a = "<a href='assign$ConfSiteSuffix?p=$prow->paperId$linkExtra'>";
		if ($t != "")
		    $t .= " &nbsp;<span class='barsep'>|</span>&nbsp; ";
		$t .= $a . $Conf->cacheableImage("assign24.png", "[Assign]", null, "b elimg")
		    . "</a>&nbsp;" . $a . "Assign</a>";
	    }
	    if ($t != "")
		echo "<div class='floatright'>", $t, "</div>";
	}

	echo "<h2>";
	if ($prow) {
	    echo $pa;
	    $this->echoTitle($prow);
	    echo "</a>";
	} else
	    echo "New paper";
	echo "</h2></td><td></td></tr>
    </table></td>
    <td class='${pboxclass}j'></td>
</tr><tr>
    <td class='${pboxclass}l'></td>
    <td class='${pboxclass}r'>", $contentopener, "<table class='papc'>
	<tr><td class='papcl'>",
	    $Conf->cacheableImage("_.gif", "", null, "_"),
	    "</td><td class='papct'><div class='inpapct'>";

	$form = "<form method='post' action=\"paper$ConfSiteSuffix?p="
	    . ($prow ? $prow->paperId : "new")
	    . "&amp;post=1&amp;m=pe$linkExtra\"";
	if ($prow && $prow->paperStorageId > 1 && $prow->timeSubmitted > 0
	    && !$Conf->setting('sub_freeze'))
	    $form .= " onsubmit='return docheckpaperstillready()'";
	$form .= " enctype='multipart/form-data' accept-charset='UTF-8'>";

	$this->echoDivEnter($prow);
	if ($this->editable) {
	    $spacer = "<div class='g'></div>\n\n";
	    echo $form, "<div class='aahc'>";

	    if (($m = $this->editMessage()))
		echo $m, $spacer;

	    $this->paptabTitle($prow);
	    $this->echoUploadRow($prow,
		(!$prow || $prow->size == 0 ? PaperTable::ENABLESUBMIT : 0));

	    // Authorship
	    echo $spacer;
	    $this->echoAuthorInformation($prow);
	    if (!$prow)
		$this->echoNewContactAuthor($Me->privChair);
	    else
		$this->echoContactAuthor($prow, $this->mode == "pe" || $prow->conflictType >= CONFLICT_AUTHOR);
	    if ($Conf->blindSubmission() == BLIND_OPTIONAL
		&& $this->editable !== "f")
		$this->echoAnonymity($prow);

	    echo $spacer;
	    $this->paptabAbstract($prow);

	    // Topics and options
	    echo $spacer;
	    $this->echoTopics($prow);
	    $this->echoOptions($prow, $Me->privChair);

	    // Potential conflicts
	    if ($this->editable !== "f" || $Me->privChair) {
		$this->echoPCConflicts($prow);
		$this->echoCollaborators($prow);
	    }

	    // Submit button
	    echo $spacer;
	    $this->echoActions();

	    echo "</div></form>";

	} else {
	    echo "<table class='paptab'><tr><td class='papbe' colspan='3'>";
	    if ($this->mode == "pe" && ($m = $this->editMessage()))
		echo $m, "<div class='g'></div>\n";
	    $this->paptabDownload($prow);
	    echo "</td></tr>
	<tr><td class='paple'>";
	    $this->paptabAbstract($prow);
	    echo "</td><td class='papce'></td><td class='papre'>";
	    $this->paptabAuthors($prow);
	    $this->paptabTopicsOptions($prow, $Me->actChair($prow));
	    echo "</td></tr></table>";
	}
	$this->echoDivExit();

	echo "</div></td><td class='papcr'>",
	    $Conf->cacheableImage("_.gif", "", null, "_"),
	    "</td></tr>\n";

	if (!$this->editable && $this->mode == "pe") {
	    $this->_paptabSepBegin();
	    echo $form;
	    $this->echoActions();
	    echo "</form>";
	    $this->_paptabSepEnd();
	}
    }

    function _paptabSepBegin() {
	echo "	<tr><td colspan='3' class='papsep'></td></tr>
	<tr><td></td><td class='papcc'>";
    }

    function _paptabSepEnd() {
	echo "</td><td></td></tr>\n";
    }

    function _paptabSepContaining($t) {
	if ($t !== "") {
	    $this->_paptabSepBegin();
	    echo $t;
	    $this->_paptabSepEnd();
	}
    }

    function _paptabReviewLinks($rtable, $editrrow, $ifempty) {
	require_once("reviewtable.inc");
	$t = "";
	if ($rtable)
	    $t .= reviewTable($this->prow, $this->rrows, $this->mycrows,
			      $editrrow, $this->mode);
	$t .= reviewLinks($this->prow, $this->rrows, $this->mycrows,
			  $editrrow, $this->mode, $this->allreviewslink);
	if (($empty = ($t == "")))
	    $t = $ifempty;
	$this->_paptabSepContaining($t);
	echo tagg_cbox("pap", true), "</td></tr></table>\n";
	return $empty;
    }

    function _privilegeMessage() {
	global $Conf;
	$a = "<a href=\"" . selfHref(array("forceShow" => 0)) . "\">";
	return $a . $Conf->cacheableImage("override24.png", "[Override]", null, "dlimg")
	    . "</a>&nbsp;You have used administrator privileges to view and edit "
	    . "reviews for this paper. (" . $a . "Unprivileged view</a>)";
    }

    function paptabEndWithReviews() {
	global $Conf, $ConfSiteSuffix, $Me, $rf, $forceShow, $linkExtra;
	if (!$rf)
	    $rf = reviewForm();
	$prow = $this->prow;

	if ($forceShow && !$Me->canViewReview($prow, null, $fakeWhyNotView, true))
	    $this->_paptabSepContaining("<div class='inpapcc'>" . $this->_privilegeMessage() . "</div>");

	$empty = $this->_paptabReviewLinks(true, null, "<div class='hint'>There are no reviews or comments for you to view.</div>");
	if ($empty)
	    return;

	// text format link
	if ($Me->canViewReview($prow, null)
	    && count($this->rrows) + count($this->crows) > 0) {
	    $a = "<a href='review$ConfSiteSuffix?p=$prow->paperId&amp;m=r&amp;text=1$linkExtra'>";
	    echo "<div class='relative'>",
		"<table class='pbox'><tr><td class='pboxl'></td>",
		"<td class='pboxr'>", $a,
		$Conf->cacheableImage("txt24.png", "[Text]", null, "dlimg"),
		"</a>&nbsp;", $a, "Reviews and comments in plain text</a>",
		"</td></tr></table></div>\n";
	}

	$opt = array("edit" => false);
	foreach ($this->rrows as $rr)
	    if ($rr->reviewSubmitted)
		$rf->show($prow, $this->rrows, $rr, $opt);
	foreach ($this->rrows as $rr)
	    if (!$rr->reviewSubmitted && $rr->reviewModified > 0
		&& $Me->canViewReview($prow, $rr))
		$rf->show($prow, $this->rrows, $rr, $opt);
    }

    function paptabComments() {
	global $Conf, $ConfSiteSuffix, $Me, $forceShow, $linkExtra,
	    $useRequest;
	$prow = $this->prow;

	// show comments as well
	if ((count($this->mycrows) || $Me->canComment($prow, null)
	     || $Conf->timeAuthorRespond()) && !$this->allreviewslink) {
	    require_once("Code/commentview.inc");
	    $cv = new CommentView;
	    $editablecid = defval($_REQUEST, "commentId", "xxx");
	    if (isset($_REQUEST["noedit"]))
		$editablecid = "xxx";
	    $editableresponse = $Conf->timeAuthorRespond() && $prow->conflictType >= CONFLICT_AUTHOR;

	    foreach ($this->mycrows as $cr) {
		$editMode = ($editablecid == $cr->commentId
			     || ($editableresponse && $cr->forAuthors > 1));
		$cv->show($prow, $cr, $editMode && $useRequest, $editMode);
	    }

	    // comment editing
	    if ($Me->canComment($prow, null))
		$cv->show($prow, null, $editablecid == "new" && $useRequest,
			  true, $editablecid != "new");
	    if (!$cv->nresponse && $Conf->timeAuthorRespond()
		&& ($prow->conflictType >= CONFLICT_AUTHOR
		    || ($Me->actChair($prow) && $editablecid == "response")))
		$cv->showResponse($prow, null, false, true);

	    $cv->endTable();
	    unset($_SESSION["comment_msgs"]);
	}
    }

    function paptabEndWithReviewMessage() {
	global $Conf, $ConfSiteSuffix, $Me, $linkExtra;

	if (!$Me->canViewReview($this->prow, null, $whyNot)
	    && $this->rrows)
	    $this->_paptabSepContaining("You can&rsquo;t see the reviews for this paper.  " . whyNotText($whyNot, "review"));

	if ($this->mode != "pe")
	    $this->_paptabReviewLinks(false, null, "");
	else
	    echo tagg_cbox("pap", true), "</td></tr></table>\n";
    }

    function paptabEndWithEditableReview() {
	global $Conf, $ConfSiteSuffix, $Me, $rf, $forceShow, $linkExtra;
	if (!$rf)
	    $rf = reviewForm();
	$prow = $this->prow;
	$actPC = $Me->actPC($prow);
	$actChair = $Me->actChair($prow);

	// review messages
	$viewall = $Me->canViewReview($prow, null, $whyNot, true);
	$msgs = array();
	if (!$this->rrow && $this->prow->reviewType <= 0)
	    $msgs[] = "You haven&rsquo;t been assigned to review this paper, but you can review it anyway.";
	if ($forceShow && !$viewall) {
	    $msgs[] = $this->_privilegeMessage();
	} else if (!$viewall && isset($whyNot["reviewNotComplete"])
		   && ($Me->isPC || $Conf->setting("extrev_view"))) {
	    $nother = 0;
	    foreach ($this->rrows as $rr)
		if (!$Me->ownReview($rr) && $rr->reviewSubmitted)
		    $nother++;
	    if ($nother > 0)
		$msgs[] = "You'll be able to see " . plural($nother, "other review") . " once you complete your own.";
	}
	if (count($msgs) > 0)
	    $this->_paptabSepContaining(join("<br />\n", $msgs));

	// links
	$this->_paptabReviewLinks(true, $this->editrrow, "");

	// review form, possibly with deadline warning
	$opt = array("edit" => $this->mode == "re");

	if ($this->editrrow && ($Me->ownReview($this->editrrow) || $actChair)
	    && !$Conf->timeReviewPaper($actPC, true, true)) {
	    if ($Me->actChair($prow))
		$override = "  As an administrator, you can override this deadline using the &ldquo;Override deadlines&rdquo; checkbox.";
	    else {
		$override = "";
		if ($this->editrrow->reviewSubmitted)
		    $opt["edit"] = false;
	    }
	    if (!$Conf->timeReviewPaper($actPC, true, true, true))
		$opt["editmessage"] = "The <a href='deadlines$ConfSiteSuffix'>deadline</a> for changing reviews has passed, so the review can no longer be changed.$override";
	    else
		$opt["editmessage"] = "The site is not open for reviewing, so the review cannot be changed.$override";
	} else if (!$Me->canReview($prow, $this->editrrow))
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
	    $_REQUEST["ls"] = allocateListNumber($search->listId());
	    $_SESSION["l"][$_REQUEST["ls"]] = $search->sessionList();
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
	if ($Me->isPC || $Conf->setting("tag_rank"))
	    $sel["tags"] = true;

	if (!($prow = $Conf->paperRow($sel, $Me, $whyNot)))
	    return null;
	$rrow = null;
	if (isset($sel["reviewId"]))
	    $rrow = $Conf->reviewRow($sel);
	if (!$Me->canViewPaper($prow, $whyNot)
	    || (!isset($_REQUEST["paperId"])
		&& !$Me->canViewReview($prow, $rrow)
		&& !$Me->privChair)) {
	    // Don't allow querier to probe review/comment<->paper mapping
	    if (!isset($_REQUEST["paperId"]))
		$whyNot = array("invalidId" => "paper");
	    return null;
	}
	cleanAuthor($prow);
	return $prow;
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
	global $Conf, $Me;
	if ($this->prow) {
	    $this->crows = $Conf->commentRows
		("select PaperComment.*, firstName, lastName, email
		from PaperComment join ContactInfo using (contactId)
		where paperId=" . $this->prow->paperId . " order by commentId");
	    $this->mycrows = array();
	    foreach ($this->crows as $crow)
		if ($Me->canViewComment($this->prow, $crow))
		    $this->mycrows[] = $crow;
	} else
	    $this->crows = $this->mycrows = array();
    }

    function fixReviewMode() {
	global $Conf, $Me;
	$prow = $this->prow;
	if ($this->mode == "re" && $this->rrow
	    && !$Me->canReview($prow, $this->rrow, $whyNot, false)
	    && ($this->rrow->contactId != $Me->contactId
		|| $this->rrow->reviewSubmitted))
	    $this->mode = "r";
	if ($this->mode == "r" && $this->rrow
	    && !$Me->canViewReview($prow, $this->rrow, $whyNot))
	    $this->rrow = $this->editrrow = null;
	if ($this->mode == "r" && !$this->rrow && !$this->editrrow
	    && !$Me->canViewReview($prow, $this->rrow, $whyNot)
	    && $Me->canReview($prow, $this->rrow, $whyNot, false))  {
	    $this->mode = "re";
	    foreach ($this->rrows as $rr)
		if ($rr->contactId == $Me->contactId
		    || (!$this->editrrow && $Me->ownReview($rr)))
		    $this->editrrow = $rr;
	}
	if ($this->mode == "r" && $prow && !count($this->rrows)
	    && !count($this->mycrows)
	    && $prow->conflictType >= CONFLICT_AUTHOR
	    && !$Me->actChair($prow, true)
	    && ($Conf->timeFinalizePaper($prow) || $prow->timeSubmitted <= 0))
	    $this->mode = "pe";
    }

}
