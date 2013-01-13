<?php
// papertable.inc -- HotCRP helper class for producing paper tables
// HotCRP is Copyright (c) 2006-2012 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

global $textAreaRows;
$textAreaRows = array("title" => 1, "abstract" => 12, "authorInformation" => 5,
		      "collaborators" => 6);

class PaperTable {

    const ENABLESUBMIT = 8;

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
    var $npapstrip;
    var $allFolded;
    var $foldState;
    var $highlight;
    var $matchPreg;
    var $watchCheckbox;
    var $initial;
    var $entryMatches;
    var $canUploadFinal;

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
	global $CurrentList;

	$this->editable = $editable;
	$this->useRequest = $useRequest;
	$this->npapstrip = 0;

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
        if (isset($_SESSION["l"]) && isset($CurrentList)
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
		} else if (is_object($v))
                    $this->matchPreg[$k] = $v;
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
	global $Error, $Conf;
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
	    $c .= "<span class='pstedit fn'>"
		. "<a class='xx' href=\"" . selfHref(array("atab" => $what))
		. "\" onclick='" . $x . ($x ? ";" : "")
		. "return fold(\"$editfolder\",0)' title='Edit'>"
		. "<span style='display:inline-block;position:relative;width:15px'>"
		. $Conf->cacheableImage("edit.png", "[Edit]", null, "bmabs")
		. "</span>&nbsp;<u class='x'>Edit</u></a></span>";
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
	    $text = Text::highlight($text, $this->matchPreg[$fieldName], $this->entryMatches);
	else
	    $text = htmlspecialchars($text);

	if ($authorTable == "col" && !$this->editable)
	    $text = nl2br($text);
	else if ($authorTable == "p" && !$this->editable) {
	    $pars = preg_split("/\n([ \t\r\v\f]*\n)+/", $text);
	    $text = "";
	    for ($i = 0; $i < count($pars); ++$i) {
		$style = ($i == 0 ? "margin-top:0" : "");
		if ($i == count($pars) - 1)
		    $style .= ($style ? ";" : "") . "margin-bottom:0";
		$text .= "<p" . ($style ? " style='$style'" : "") . ">" . $pars[$i] . "</p>";
	    }
	}

	if ($this->editable)
	    $text = "<textarea class='textlite papertext' name='$fieldName' rows='" . $textAreaRows[$fieldName] . "' cols='60' onchange='hiliter(this)'>" . $text . "</textarea>";
	return $text;
    }

    function echoTitle($prow) {
	if ($this->highlight && isset($this->matchPreg["title"]))
	    echo Text::highlight($prow->title, $this->matchPreg["title"]);
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
	if ($sha1)
	    $t[] = "<span class='nowrap' title='SHA-1 checksum'>" . $Conf->cacheableImage("_.gif", "SHA-1", "SHA-1 checksum", "checksum12") . " " . bin2hex($sha1) . "</span>";
	if (count($t) > 0)
	    return "<span class='hint'>" . join(" &nbsp;<span class='barsep'>|</span>&nbsp; ", $t) . "</span>";
	else
	    return "";
    }

    function paptabDownload($prow) {
	global $Conf, $Me;
	assert(!$this->editable);
	$final = ($prow->outcome > 0 && $Conf->collectFinalPapers());
	$out = array();

	// status and download
	if ($prow && $Me->canDownloadPaper($prow)) {
	    $t = "<td class='nowrap pad'>" . $Me->paperStatus($prow->paperId, $prow, 0) . "</td>";
	    $pdfs = array();

	    $dprefix = "";
	    if ($final && $prow->finalPaperStorageId > 1) {
		$data = paperDocumentData($prow, DTYPE_FINAL);
		$dprefix = "Final version: &nbsp;";
	    } else
		$data = paperDocumentData($prow, DTYPE_SUBMISSION);
	    if ($data) {
		if (($stamps = $this->pdfStamps($data)))
		    $stamps = "<span class='sep'></span>" . $stamps;
		$pdfs[] = $dprefix . documentDownload($data) . $stamps;
	    }

	    foreach (paperOptions() as $id => $o)
		if ($o->displayType == 2 && $o->isDocument
		    && (!$o->isFinal || $final)) {
		    if (preg_match("/\\b$id#(\\d+)/", defval($prow, "optionIds", ""), $m)
			&& $m[1] > 1
			&& ($d = paperDocumentData($prow, $id, $m[1])))
			$pdfs[] = "<span class='papfn'>"
			    . htmlspecialchars($o->optionName)
			    . "</span>: &nbsp;"
			    . documentDownload($d, count($pdfs) ? "dlimgsp" : "dlimg");
		}

	    if ($final && $prow->finalPaperStorageId > 1
		&& $prow->paperStorageId > 1)
		$pdfs[] = "<small><a class='u' href=\""
		    . $Conf->makeDownloadPath($prow->paperId, null, 0)
		    . "\">Submission version</a></small>";

	    $t .= "<td>";
	    foreach ($pdfs as $p)
		$t .= "<p class='od'>" . $p . "</p>";
	    $out[] = "<table><tr>$t</td></tr></table>";
	}

	// conflicts
	if ($prow->conflictType >= CONFLICT_AUTHOR)
	    $out[] = "You are an <span class='author'>author</span> of this paper.";
	else if ($prow->conflictType > 0)
	    $out[] = "You have a <span class='conflict'>conflict</span> with this paper.";
	if ($Me->isPC && $prow->conflictType <= 0
	    && $Conf->timeUpdatePaper($prow) && $this->mode != "assign"
	    && $this->mode != "contact")
	    $out[] = "<div class='xwarning'>The authors still have <a href='" . hoturl("deadlines") . "'>time</a> to make changes.</div>";
	if (count($out))
	    $out[] = "";

	echo join("<div class='g'></div>\n", $out);
    }

    function echoDocumentUploadRow($prow, $opt, $storageId, $flags) {
	global $Conf, $Me, $Opt;

        $docclass = new HotCRPDocument($opt->optionId, $opt);
	$documentType = $opt->optionId;
	$optionType = $opt->type;
	$noPapers = defval($Opt, "noPapers") && ($documentType == DTYPE_SUBMISSION || $documentType == DTYPE_FINAL);
	$banal = $Conf->setting("sub_banal")
	    && ($optionType == null || $optionType == PaperOption::T_PDF
		|| $optionType == PaperOption::T_FINALPDF);

	$filetypes = array();
	$accepts = array();
	if ($noPapers) {
	    if ($documentType == DTYPE_SUBMISSION)
		echo $this->papt("paper", "Status");
	} else {
            $accepts = $docclass->mimetypes();
	    if (count($accepts))
		echo $this->papt("paper", htmlspecialchars($opt->optionName) . " <span class='papfnh'>(" . htmlspecialchars(Mimetype::description($accepts)) . ", max size " . ini_get("upload_max_filesize") . "B)</span>");
	}
	if (isset($opt->description) && $opt->description)
	    echo "<div class='paphint'>", $opt->description, "</div>";
	echo "<div class='papv'>";

	// current version, if any
	$doc = null;
	if ($prow && $Me->canDownloadPaper($prow) && $storageId > 1
	    && (($doc = paperDocumentData($prow, $documentType, $storageId)))) {
	    echo "<table id='current_$documentType'><tr>",
		"<td class='nowrap'>", documentDownload($doc), "</td>";
	    if ($doc->mimetype == "application/pdf" && $banal)
		echo "<td><span class='sep'></span></td><td><a href='javascript:docheckformat($documentType)'>Check format</a></td>";
	    if (($stamps = $this->pdfStamps($doc)))
		echo "<td><span class='sep'></span></td><td>$stamps</td>";
	    echo "</tr></table>\n";
	}

	// uploader
	$uploader = "";
	if (count($accepts)) {
	    $uploader = "";
	    if ($doc)
		$uploader .= "<div class='g'></div><div id='replacement_$documentType' class='foldo'><span class='fx'>Replace:&nbsp; ";
	    $inputid = ($optionType ? "opt" . $documentType : "paperUpload");
	    $uploader .= "<input id='$inputid' type='file' name='$inputid'";
	    if (count($accepts) == 1)
		$uploader .= " accept='" . $accepts[0]->mimetype . "'";
	    $uploader .= " size='30' onchange='hiliter(this)";
	    if ($documentType == DTYPE_SUBMISSION)
		$uploader .= ";fold(\"isready\",0)";
	    if ($flags & self::ENABLESUBMIT)
		$uploader .= ";form.submit.disabled=false";
	    $uploader .= "' />";
	    if ($doc && $optionType)
		$uploader .= " &nbsp;<span class='barsep'>|</span>&nbsp; "
		    . "<a id='remover_$documentType' href='javascript:doremovedocument(\"$documentType\")'>Delete</a>";
	    $uploader .= "</span>";
	    if ($doc && $optionType)
		$uploader .= "<span class='fn'><em>Option marked for deletion</em></span>"
		    . "<input id='remove_$documentType' type='hidden' name='remove_$documentType' value='' />";
	    if ($doc)
		$uploader .= "</div>";
	}

	if ($prow && $storageId > 1 && $banal
	    && defval($prow, "mimetype", "application/pdf") == "application/pdf") {
	    echo "<div id='foldcheckformat$documentType' class='foldc'><div id='checkformatform${documentType}result' class='fx'><div class='xinfo'>Checking format, please wait (this can take a while)...</div></div></div>";
	    $Conf->footerHtml("<form id='checkformatform$documentType' class='fold7o' method='post' action='" . hoturl_post("paper", "p=$prow->paperId&amp;dt=$documentType") . "' enctype='multipart/form-data' accept-charset='UTF-8' onsubmit='return Miniajax.submit(\"checkformatform$documentType\")'><input type='hidden' name='checkformat' value='1' /></form>");
	}

	if ($documentType == DTYPE_SUBMISSION
	    && (!$doc || $Conf->setting("sub_freeze"))) {
	    echo $uploader;
	    $uploader = "";
	}

	if ($documentType == DTYPE_SUBMISSION) {
	    if ($this->useRequest)
		$checked = defval($_REQUEST, "submit");
	    else if ($Conf->setting('sub_freeze'))
		$checked = $prow && $prow->timeSubmitted > 0;
	    else
		$checked = !$prow || $storageId <= 1 || $prow->timeSubmitted > 0;
	    $s = ($doc ? " style='margin-top: 0.5ex'" : "");
	    echo "<div id='foldisready' class='",
		(($prow && $storageId > 1) || $noPapers ? "foldo" : "foldc"),
		"'$s><table class='fx'><tr><td class='nowrap'>",
		tagg_checkbox_h("submit", 1, $checked, array("id" => "paperisready")), "&nbsp;";
	    if ($Conf->setting('sub_freeze'))
		echo "</td><td>", tagg_label("<strong>This is the final submission.</strong>"),
		    "</td></tr><tr><td></td><td><small>You must submit a final version before the deadline or your paper will not be reviewed.  Once you submit a final version you will not be able to make further changes.</small>";
	    else
		echo tagg_label("The paper is ready for review.");
	    echo "</td></tr></table></div>\n";
	    $Conf->footerScript("hotcrp_onload.push(function(){var x=\$\$(\"paperUpload\");if(x&&x.value)fold(\"isready\",0)})");
	} else if ($documentType == DTYPE_FINAL)
	    echo "<input type='hidden' name='submit' value='1' />";

	echo $uploader;

	echo "</div>\n\n";
    }

    function echoUploadRow($prow, $flags) {
	global $Me;
	if ($this->canUploadFinal) {
	    $this->echoDocumentUploadRow($prow, (object) array("optionId" => DTYPE_FINAL, "optionName" => "Final version", "type" => null), $prow->finalPaperStorageId, $flags);
	    foreach (paperOptions() as $id => $o)
		if ($o->type == PaperOption::T_FINALPDF) {
		    $match = preg_match("/\\b$id#(\\d+)/", defval($prow, "optionIds", ""), $m);
		    $this->echoDocumentUploadRow($prow, $o, $match ? $m[1] : 0, $flags);
		}
	} else
	    $this->echoDocumentUploadRow($prow, (object) array("optionId" => DTYPE_SUBMISSION, "optionName" => "Submission", "type" => null), $prow->paperStorageId, $flags);
    }

    function paptabAbstract($prow) {
	$data = $this->entryData("abstract", $prow, "p");
	if ($this->allFolded && strlen($data) > 190) {
	    $shortdata = trim(preg_replace(",</?p.*?>,", "\n", $data));
	    $shortdata = preg_replace("/\\S+(<[^>]+)?\\Z/", "", utf8_substr($shortdata, 0, 180));
	    if ($shortdata != "") { /* "" might happen if really long word */
		echo $this->papt("abstract", "Abstract",
				 array("fold" => "paper", "foldnum" => 6,
				       "foldsession" => "foldpaperb",
				       "showtitle" => "Show full abstract",
				       "hidetitle" => "Abbreviate abstract")),
		    "<div class='papv abstract'>",
		    "<div class='fn6'>", $shortdata,
		    " <a class='fn6' href='javascript:void fold(\"paper\", 0, 6)'>[more]</a>",
		    "</div><div class='fx6'>", $data,
		    "</div></div>\n\n";
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
	if ($Conf->subBlindAlways())
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

    function authorData($table, $type, $viewAs = null, $prefix = "") {
	global $Conf;
	if ($this->highlight && isset($this->matchPreg["authorInformation"]))
	    $highpreg = $this->matchPreg["authorInformation"];
	else
	    $highpreg = false;
	$this->entryMatches = 0;

	$names = array();
	if ($type == "last") {
	    foreach ($table as $au) {
		$n = Text::abbrevname_text($au);
                $names[] = Text::highlight($n, $highpreg, $nm);
                $this->entryMatches += $nm;
	    }
	    return $prefix . join(", ", $names);

	} else {
	    foreach ($table as $au) {
		$n = Text::highlight("$au[0] $au[1]", $highpreg, $nm);
                $this->entryMatches += $nm;
		if ($au[3] != "") {
		    $n .= " <span class='auaff'>(" . Text::highlight($au[3], $highpreg, $nm) . ")</span>";
                    $this->entryMatches += $nm;
                }
		if ($au[2] != "") {
		    $n .= " &lt;" . Text::highlight($au[2], $highpreg, $nm) . "&gt;";
                    $this->entryMatches += $nm;
                }
		$n = trim($n);
		if ($au[2] != "" && preg_match("/ &lt;.*&gt;\\Z/", $n, $m))
		    $n = substr($n, 0, strlen($n) - strlen($m[0]) + 5)
			. "<a href=\"mailto:" . htmlspecialchars($au[2]) . "\">"
			. substr($m[0], 5, strlen($m[0]) - 9)
			. "</a>&gt;";
		if ($au[2] != "" && $viewAs !== null && $viewAs->email != $au[2]
		    && $viewAs->privChair && defval($au, 4))
		    $n .= " <a href=\"" . selfHref(array("viewContact" => $au[2])) . "\">" . $Conf->cacheableImage("viewas.png", "[Act as]", "Act as " . Text::name_html($au)) . "</a>";
		$names[] = "<p class='odname'>" . $prefix . $n . "</p>";
		$prefix = "";
	    }
	    return join("\n", $names);
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
	    $inauthors1 = $inauthors2 = "<a class='q fx8' "
		. "href='javascript:void fold(\"paper\", 1, 8)' "
		. "title='Hide authors'>[blind]</a> ";
	    $afterauthors = "</div>\n";
	} else {
	    $inauthors1 = $inauthors2 = $afterauthors = "";
	    if ($Conf->subBlindOptional() && $prow->blind)
		$inauthors1 = $inauthors2 = "[blind] ";
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
		"<div class='papv'><span class='fn9'>",
		$this->authorData($prow->authorTable, "last", null, $inauthors1),
		" <a class='fn9' href='javascript:void fold(\"paper\", 0, 9)'>[details]</a>",
		"</span><span class='fx9'>",
		$this->authorData($prow->authorTable, "col", $Me, $inauthors2),
		"</span>";

	} else {
	    echo $this->papt("authorInformation", $auname),
		"<div class='papv'>", $inauthors1,
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

	$options = paperOptions();
	if (count($options)) {
	    preg_match_all('/(\d+)#(\d+)/', defval($prow, "optionIds", ""), $m);
	    $optsel = array();
	    $optdata = null;
	    for ($i = 0; $i < count($m[1]); ++$i)
		$optsel[$m[1][$i]] = $m[2][$i];

	    $oseparator = "";

	    foreach ($options as $o) {
		$ov = defval($optsel, $o->optionId, 0);
		if ((!$ov && !$o->type)
		    || ($o->displayType == 2 && $o->isDocument)
		    || (!$showAllOptions && !$Me->canViewPaperOption($prow, $o)))
		    continue;

		$show_on = true;
		$on = htmlspecialchars($o->optionName);
		$ox = "";
		if ($o->type == PaperOption::T_CHECKBOX)
		    $ox = true;
		else if (PaperOption::type_is_selectorlike($o->type)) {
		    $om = explode("\n", $o->optionValues);
		    if (defval($om, $ov, "") != "")
			$ox = htmlspecialchars($om[$ov]);
		} else if ($o->type == PaperOption::T_NUMERIC && $ov != "" && $ov != "0")
		    $ox = htmlspecialchars($ov);
		else if ($o->type == PaperOption::T_TEXT && $ov != "") {
		    if ($optdata == null) {
			$optdata = array();
			$result = $Conf->qe("select optionId, data from PaperOption where paperId=$prow->paperId", "while selecting paper options");
			while (($row = edb_row($result)))
			    $optdata[$row[0]] = $row[1];
		    }
		    if ($optdata[$o->optionId] != "")
			$ox = htmlspecialchars($optdata[$o->optionId]);
		} else if ($o->isDocument && $ov) {
		    $show_on = false;
		    if ($o->type == PaperOption::T_PDF)
			/* make fake document */
			$doc = (object) array("paperId" => $prow->paperId, "mimetype" => "application/pdf", "documentType" => $o->optionId);
		    else
			$doc = paperDocumentData($prow, $o->optionId, $ov);
		    if ($doc)
			$ox = documentDownload($doc, "sdlimg", $on);
		}

		if ($ox == "")
		    /* do nothing */;
		else if ($o->displayType) {
		    echo $oseparator, "<div class='papt'><span class='papfn'>",
			($show_on ? $on : $ox), "</span>";
		    if ($show_on && $ox !== true)
			echo ": ", $ox;
		    echo "<div class='clear'></div></div>";
		    $oseparator = "<div class='papvx'></div>\n";
		} else {
		    if ($ox === true)
			$optionhtml[] = $on . "<br />\n";
		    else if ($show_on)
			$optionhtml[] = $on . ": <span class='optvalue'>" . $ox . "</span><br />\n";
		    else
			$optionhtml[] = $ox . "<br />\n";
		    $ndocuments += $o->isDocument ? 1 : 0;
		}
	    }

	    if ($oseparator)
		echo "<div class='papv'></div>\n";
	}

	if ($topicdata != "" || count($optionhtml)) {
	    $infotypes = array();
	    if ($ndocuments > 0)
		$infotypes[] = "Attachments";
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
	    echo Text::user_html($Me);
	echo "</div>\n\n";
    }

    function echoContactAuthor($prow, $editMode = null) {
	global $Conf, $Me;
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
	    echo "<span class='barsep'>|</span>&nbsp; <a href='" . hoturl("contactauthors", "p=$prow->paperId") . "'>Edit&nbsp;contacts</a>";
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
	global $Conf;
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
	Be sure to include conflicted <a href='", hoturl("users", "t=pc"), "'>PC members</a>.
	We use this information when assigning PC and external reviews.";
	echo "  List one conflict per line.  For example: &ldquo;<tt>Jelena Markovic (EPFL)</tt>&rdquo; or, for a whole institution, &ldquo;<tt>EPFL</tt>&rdquo;.</div>",
	    "<div class='papv'>",
	    $this->entryData("collaborators", $prow),
	    "</div>\n\n";
    }

    function _papstripBegin($foldid = null, $folded = null, $otherclasses = null) {
	$x = "<div ";
	if ($foldid)
	    $x .= " id='fold$foldid'";
	$x .= " class='psc";
	if (!$this->npapstrip)
	    $x .= " psc1";
	if ($foldid)
	    $x .= " fold" . ($folded ? "c" : "o");
	if ($otherclasses)
	    $x .= " " . $otherclasses;
	++$this->npapstrip;
	return $x . "'>";
    }

    function papstripCollaborators($prow) {
	global $Conf;
	if (!$Conf->setting("sub_collab") || !$prow->collaborators
	    || strcasecmp(trim($prow->collaborators), "None") == 0)
	    return;
	$name = $Conf->setting("sub_pcconf") ? "Other conflicts" : "Potential conflicts";
	$fold = defval($_SESSION, "foldpscollab", 1) ? 1 : 0;

	$data = $this->entryData("collaborators", $prow, "col");
	if ($this->entryMatches || !$this->allFolded)
	    $fold = 0;

	echo $this->_papstripBegin("pscollab", true, $fold),
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

    function echoOptions($prow, $showAll, $displayTypeFlags) {
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
	    if (!($displayTypeFlags & (1 << $o->displayType))
		|| ($o->isFinal && !$this->canUploadFinal))
		continue;
	    $optid = "opt$o->optionId";
	    if ($this->useRequest)
		$myval = defval($_REQUEST, $optid);
	    else if ($o->type != PaperOption::T_TEXT)
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

	    if ($o->type == PaperOption::T_CHECKBOX) {
		echo $this->papt($optid, tagg_checkbox_h($optid, 1, $myval)
				 . "&nbsp;" . tagg_label(htmlspecialchars($o->optionName)));
		if ($o->description)
		    echo "<div class='paphint'>", $o->description, "</div>";
		echo "<div class='papv'></div>\n\n";
	    } else if (!$o->isDocument) {
		echo $this->papt($optid, htmlspecialchars($o->optionName));
		if ($o->description)
		    echo "<div class='paphint'>", $o->description, "</div>";
		if ($o->type == PaperOption::T_SELECTOR)
		    echo "<div class='papv'>", tagg_select("opt$o->optionId", explode("\n", $o->optionValues), $myval, array("onchange" => "hiliter(this)")), "</div>\n\n";
		else if ($o->type == PaperOption::T_RADIO) {
		    echo "<div class='papv'>";
		    $values = explode("\n", $o->optionValues);
		    $myval = isset($values[$myval]) ? $myval : 0;
		    foreach ($values as $val => $text) {
			echo tagg_radio("opt$o->optionId", $val, $val == $myval, array("onchange" => "hiliter(this)"));
			echo "&nbsp;", tagg_label(htmlspecialchars($text)), "<br />\n";
		    }
		    echo "</div>\n\n";
		} else if ($o->type == PaperOption::T_NUMERIC)
		    echo "<div class='papv'><input type='text' class='textlite' name='$optid' value=\"", htmlspecialchars($myval), "\" size='8' onchange='hiliter(this)' /></div>\n\n";
		else
		    echo "<div class='papv'><input type='text' class='textlite papertext' name='$optid' value=\"", htmlspecialchars($myval), "\" size='40' onchange='hiliter(this)' /></div>\n\n";
	    } else if ($o->type != PaperOption::T_FINALPDF)
		$this->echoDocumentUploadRow($prow, $o, defval($optsel, $o->optionId), 0);
	}
    }

    function echoPCConflicts($prow) {
	global $Conf, $Me, $authorConflictTypes;

	assert(!!$this->editable);
	if (!$Conf->setting("sub_pcconf"))
	    return;
	$pcm = pcMembers();
	if (!count($pcm))
	    return;

	$selectors = $Conf->setting("sub_pcconfsel");
	require_once("conflict.inc");

	$conflict = array();
	if ($this->useRequest) {
	    foreach ($pcm as $id => $row)
		if (isset($_REQUEST["pcc$id"])
		    && ($ct = cvtint($_REQUEST["pcc$id"])) > 0)
		    $conflict[$id] = Conflict::force_author_mark($ct, $Me->privChair);
	}
	if ($prow) {
	    $result = $Conf->qe("select contactId, conflictType from PaperConflict where paperId=$prow->paperId", "while finding conflicted PC members");
	    while (($row = edb_row($result))) {
		$ct = new Conflict($row[1]);
		if (!$this->useRequest || (!$ct->is_author_mark() && !$Me->privChair))
		    $conflict[$row[0]] = $ct;
	    }
	}

	$pcconfs = array();
	$nonct = Conflict::make_nonconflict();
	if ($selectors) {
	    $ctypes = $authorConflictTypes;
	    $extra = array("onchange" => "hiliter(this)",
			   "class" => "pctbconfselector");
	    if ($Me->privChair) {
		$ctypes["xsep"] = null;
		$ctypes[CONFLICT_CHAIRMARK] = "Confirmed conflict";
		$extra["optionstyles"] = array(CONFLICT_CHAIRMARK => "font-weight:bold");
	    }
	    foreach ($pcm as $id => $p) {
		$c = "<tr><td class='pctbname0 pctbl'>". Text::name_html($p) . "</td><td class='pctbconfsel'>";
		$ct = defval($conflict, $id, $nonct);
		if ($ct->is_author())
		    $c .= "<strong>Author</strong>";
		else if ($ct->is_conflict() && !$ct->is_author_mark() && !$Me->privChair)
		    $c .= "<strong>Conflict</strong>";
		else if ($ct->is_conflict() && !$ct->is_author_mark())
		    $c .= tagg_select("pcc$id", $ctypes, CONFLICT_CHAIRMARK, $extra);
		else
		    $c .= tagg_select("pcc$id", $ctypes, $ct->value, $extra);
		$c .= "</td></tr>\n";
		$pcconfs[] = $c;
	    }
	    $tclass = " style='padding-left:0'><table class='pctb'";
	    $topen = "<td class='pctbcolleft'><table>";
	    $tswitch = "</table></td><td class='pctbcolmid'><table>";
	    $tclose = "</table>";
	} else {
	    foreach ($pcm as $id => $p) {
		$ct = defval($conflict, $id, $nonct);
		$checked = $ct->is_conflict();
		$disabled = $checked && !$ct->is_author_mark() && !$Me->privChair;
		$value = $checked ? $ct->value : CONFLICT_AUTHORMARK;
		$cbox = tagg_checkbox_h("pcc$id", $value, $checked, array("disabled" => $disabled));
		$aff = ($p->affiliation === "" ? "" : "<div class='pcconfaff'>" . htmlspecialchars($p->affiliation) . "</div>");
		$label = tagg_label(Text::name_html($p) . $aff);
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
		$pcconfs[] = Text::name_html($p);
	if ($prow->pcPaper)
	    $pcconfs[] = "<b>PC paper</b>";
	if (!count($pcconfs))
	    $pcconfs[] = "None";

	echo $this->_papstripBegin(),
	    $this->papt("pcconflict", "PC conflicts", array("type" => "ps")),
	    "<div class='psv psconf'><p class='odname'>",
	    join("</p><p class='odname'>", $pcconfs),
	    "</p></div></div>\n";
    }

    function _papstripLeadShepherd($prow, $type, $name, $showedit, $wholefold) {
	global $Conf, $Me, $Error, $linkExtra;
	$editable = $Me->actChair($prow);

	$field = $type . "ContactId";
	if ($prow->$field == 0 && !$editable)
	    return;
	$value = $prow->$field;
	$pc = pcMembers();
	if ($editable)
	    echo "<div id='fold$type' class='fold", ($showedit ? "o" : "c"),
		" fold2", ($wholefold ? "c" : "o"), "'>";
	echo $this->_papstripBegin(null, null, "fx2");

	if ($editable) {
	    echo "<form id='${type}form' class='fold7o' method='post' action='", hoturl_post("review", "p=" . $prow->paperId . $linkExtra), "' enctype='multipart/form-data' accept-charset='UTF-8' onsubmit='return Miniajax.submit(\"${type}form\")'>",
		"<div class='inform'>",
		"<input type='hidden' name='set$type' value='1' />";
	    $Conf->footerScript("Miniajax.onload(\"${type}form\")");
	}

	echo $this->papt($type, $name, array("type" => "ps", "editfolder" => ($editable && !$showedit ? $type : 0))),
	    "<div class='psv'>";

	if ($editable) {
	    $opt = array("0" => "None");
	    foreach ($pc as $row)
		$opt[htmlspecialchars($row->email)] = Text::name_html($row);

	    echo "<div class='fx'>",
		tagg_select($type, $opt,
			    ($value && isset($pc[$value]) ? htmlspecialchars($pc[$value]->email) : "0"),
			    array("onchange" => "Miniajax.submit('${type}form')",
				  "id" => "fold${type}_d")),
		" <input class='b fx7' type='submit' value='Save' />",
		" <span id='${type}formresult'></span>",
		"</div><div class='fn'><p class='odname'>";
	}

	if (!$value)
	    echo "None";
	else if (!isset($pc[$value]))
	    echo "Unknown!";
	else
	    echo Text::name_html($pc[$value]);

	echo "</p></div>";
	if ($editable)
	    echo "</div></div></form></div>";
	echo "</div>\n";
    }

    function papstripLead($prow, $showedit) {
	$this->_papstripLeadShepherd($prow, "lead", "Discussion lead", $showedit || defval($_REQUEST, "atab") == "lead", false);
    }

    function papstripShepherd($prow, $showedit, $fold) {
	$this->_papstripLeadShepherd($prow, "shepherd", "Shepherd", $showedit || defval($_REQUEST, "atab") == "shepherd", $fold);
    }

    function papstripTags($prow, $site = null) {
	global $Conf, $Me, $Error, $forceShow, $linkExtra;
	if ($site || defval($prow, "paperTags", "") !== "") {
	    // Note that tags MUST NOT contain HTML special characters.
	    require_once("tags.inc");
	    $tx = tagsToText($prow, true, $Me);
	    $editable = $site && $Me->canSetTags($prow, $forceShow);
	    $unfolded = $editable && (isset($Error["tags"]) || defval($_REQUEST, "atab") == "tags");

	    echo $this->_papstripBegin("tags", !$unfolded);
	    $colors = new TagColorizer($Me);
	    if (($color = $colors->match_all($prow->paperTags)))
		echo "<div class='pscopen ${color}'>";

	    if ($editable)
		echo "<form method='post' action='", hoturl_post($site, "p=$prow->paperId$linkExtra"), "' enctype='multipart/form-data' accept-charset='UTF-8'><div class='inform'>";

	    echo $this->papt("tags", "Tags", array("type" => "ps", "editfolder" => ($editable ? "tags" : 0), "oneditunfold" => "Miniajax.submit(\"tagreportform\")")),
		"<div class='psv' style='position:relative'>";
	    if ($editable) {
		// tag report form
		$Conf->footerHtml("<form id='tagreportform' class='fold7o' method='post' action='" . hoturl_post("paper", "p=$prow->paperId&amp;tagreport=1$linkExtra") . "' enctype='multipart/form-data' accept-charset='UTF-8' onsubmit='return Miniajax.submit(\"tagreportform\")'></form>");

		$t = tagsToText($prow, false, $Me, true);
		echo "<div class='fn'>", ($tx == "" ? "None" : $tx),
		    "</div><div id='papstriptagsedit' class='fx'><div id='tagreportformresult'>";
		if ($unfolded)
		    echo PaperActions::tagReport($prow, true);
		echo "</div>";
		if (isset($Error["tags"]))
		    echo "<div class='xmerror'>", $Error["tags"], "</div>";
		echo "<div style='position:relative'>",
                    "<div id='taghelp_p' class='taghelp_p'></div>",
                    "<textarea id='foldtags_d' cols='20' rows='4' name='tags' onkeypress='return crpSubmitKeyFilter(this, event)'>", $t, "</textarea></div>",
		    "<div style='padding:1ex 0;text-align:right'>",
		    "<input type='hidden' name='settags' value='1' />",
		    "<input class='bsm' type='submit' name='cancelsettags' value='Cancel' onclick='return fold(\"tags\",1)' />",
		    " &nbsp;<input class='bsm' type='submit' value='Save' />",
                    "</div>",
		    "<span class='hint'><a href='", hoturl("help", "t=tags"), "'>Learn more</a> &nbsp;<span class='barsep'>|</span>&nbsp; <strong>Tip:</strong> Twiddle tags like &ldquo;~tag&rdquo; are visible only to you.</span>",
		    "</div>";
                $Conf->footerScript("taghelp(\"foldtags_d\",\"taghelp_p\",taghelp_tset)");
	    } else
		echo ($tx == "" ? "None" : $tx);
	    echo "</div>";

	    if ($editable)
		echo "</div></form>";
	    if ($color)
		echo "</div>";
	    echo "</div>\n";
	}
    }

    function papstripOutcomeSelector($prow, $showedit) {
	global $Conf, $rf, $Error, $linkExtra;
	if (!$rf)
	    $rf = reviewForm();
	echo $this->_papstripBegin("decision", !$showedit && defval($_REQUEST, "atab") != "decision"),
	    $this->papt("decision", "Decision", array("type" => "ps", "editfolder" => ($showedit ? 0 : "decision"))),
	    "<div class='psv'><form id='decisionform' class='fx fold7o' method='post' action='", hoturl_post("review", "p=" . $prow->paperId . $linkExtra), "' enctype='multipart/form-data' accept-charset='UTF-8' onsubmit='return dosubmitdecision()'>",
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
	global $Conf, $linkExtra, $CurrentList;
	echo $this->_papstripBegin(),
	    $this->papt("revpref", "Review preference", array("type" => "ps")),
	    "<div class='psv'><form id='revprefform' class='fold7o' method='post' action='", hoturl_post("review", "p=" . $prow->paperId . $linkExtra), "' enctype='multipart/form-data' accept-charset='UTF-8' onsubmit='return Miniajax.submit(\"revprefform\")'>",
	    "<div class='aahc inform'>",
	    "<input type='hidden' name='setrevpref' value='1' />";
	if (isset($_REQUEST["forceShow"]))
	    echo "<input type='hidden' name='forceShow' value='", $_REQUEST['forceShow'], "' />";
	$rp = (isset($prow->reviewerPreference) ? htmlspecialchars($prow->reviewerPreference) : "0");
	$rp = ($rp == "0" ? "" : $rp);
	echo "<input id='revprefform_d' class='textlite' type='text' size='4' name='revpref' value=\"$rp\" onchange='Miniajax.submit(\"revprefform\")' tabindex='1' />",
	    " <input class='b fx7' type='submit' value='Save' />",
	    " <span id='revprefformresult'></span>",
	    "</div></form></div></div>\n";
	$Conf->footerScript("Miniajax.onload(\"revprefform\");shortcut(\"revprefform_d\").add()");
	if (isset($CurrentList) && $CurrentList
	    && defval($_SESSION["l"][$CurrentList], "revprefs")
	    && ($this->mode == "p" || $this->mode == "r"))
	    $Conf->footerScript("crpfocus('revprefform',null,3)");
    }

    function papstripRank($prow) {
	global $Conf, $Me, $linkExtra;
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
	$Conf->footerHtml("<form id='rankctxform' class='fold7o' method='post' action='" . hoturl_post("paper", "p=$prow->paperId") . "' enctype='multipart/form-data' accept-charset='UTF-8' onsubmit='return Miniajax.submit(\"rankctxform\")'><div class='aahc'><input type='hidden' name='rankctx' value='1' /></div></form>");

	echo $this->_papstripBegin("rank", true, "fold2c"),
	    $this->papt("rank", "Your rank", array("type" => "ps", "editfolder" => "rank")),
	    "<div class='psv'><form id='rankform' class='fx fold7o' method='post' action='" . hoturl_post("review", "p=" . $prow->paperId . $linkExtra) . "' enctype='multipart/form-data' accept-charset='UTF-8' onsubmit='return Miniajax.submit(\"rankform\")'>",
	    "<div class='inform'>",
	    "<input type='hidden' name='setrank' value='1' />";
	if (isset($_REQUEST["forceShow"]))
	    echo "<input type='hidden' name='forceShow' value='", $_REQUEST['forceShow'], "' />";
	echo "<input id='foldrank_d' class='textlite' type='text' size='4' name='rank' value=\"$rp\" onchange='Miniajax.submit(\"rankform\")' tabindex='1' />",
	    " <input class='b fx7' type='submit' value='Save' />",
	    " <span id='rankformresult'></span>",
	    " <div class='hint'><strong>Tip:</strong> Use <a href='", hoturl("offline"), "'>offline reviewing</a> to rank many papers at once.</div>",
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
	global $Conf, $Me, $linkExtra;
	if (!($this->watchCheckbox
	      && $prow->timeSubmitted > 0
	      && ($prow->conflictType >= CONFLICT_AUTHOR
		  || $prow->conflictType <= 0
		  || ($Me->privChair && defval($_REQUEST, "forceShow")))
	      && $Me->contactId > 0))
	    return;
	// watch note
	$result = $Conf->q("select
	ContactInfo.contactId, reviewType, commentId, conflictType, watch
	from ContactInfo
	left join PaperReview on (PaperReview.paperId=$prow->paperId and PaperReview.contactId=ContactInfo.contactId)
	left join PaperComment on (PaperComment.paperId=$prow->paperId and PaperComment.contactId=ContactInfo.contactId)
	left join PaperConflict on (PaperConflict.paperId=$prow->paperId and PaperConflict.contactId=ContactInfo.contactId)
	left join PaperWatch on (PaperWatch.paperId=$prow->paperId and PaperWatch.contactId=ContactInfo.contactId)
	where ContactInfo.contactId=$Me->contactId");
	$row = edb_row($result);

	echo $this->_papstripBegin(),
	    "<form id='watchform' class='fold7o' action=\"", hoturl_post("comment", "p=$prow->paperId$linkExtra"), "\" method='post' enctype='multipart/form-data' accept-charset='UTF-8' onsubmit='return Miniajax.submit(\"watchform\")'>",
	    "<div class='inform'>",
	    "<input type='hidden' name='setwatch' value='1' />";

	if ($row[4] && ($row[4] & ($this->watchCheckbox >> 1)))
	    $watchValue = $row[4];
	else if ($row[1] || $row[2] || $row[3] >= CONFLICT_AUTHOR)
	    $watchValue = $Me->defaultWatch;
	else
	    $watchValue = 0;

	echo $this->papt("watch",
			 tagg_checkbox("watch", $this->watchCheckbox,
				       $watchValue & $this->watchCheckbox,
				       array("onchange" => "Miniajax.submit('watchform')",
					     "style" => "padding-left:0;margin-left:0"))
			 . "&nbsp;" . tagg_label("Comment notification"),
			 array("type" => "ps")),
	    "<div class='pshint'>If selected, you will receive email when updated comments are available for this paper. <span id='watchformresult'></span>",
	    "<input class='b fx7' type='submit' value='Save' />",
	    "</div></div></form></div>\n\n";

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
	global $Conf, $Me;
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
		    $msg = "You can&rsquo;t register new papers since the <a href='" . hoturl("deadlines") . "'>deadline</a> has passed.$startDeadline$override";
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
		$m .= "<div class='xwarning'>You cannot make any changes as the <a href='" . hoturl("deadlines") . "'>deadline</a> has passed, but the current version can still be submitted.  Only submitted papers will be reviewed.$submitDeadline$override</div>";
	    else if ($Conf->deadlinesBetween("", "sub_sub", "sub_grace"))
		$m .= "<div class='xwarning'>The site is not open for submission updates at the moment.$override</div>";
	    else
		$m .= "<div class='xwarning'>The <a href='" . hoturl("deadlines") . "'>deadline</a> for submitting this paper has passed.  The paper will not be reviewed.$submitDeadline$override</div>";
	} else if ($prow->conflictType >= CONFLICT_AUTHOR && $prow->outcome > 0
		   && $Conf->timeSubmitFinalPaper()) {
	    $updateDeadline = $this->deadlineSettingIs("final_soft");
	    $m .= "<div class='xinfo'>" . "Congratulations!  This paper was accepted.  Submit a final version for the paper here.$updateDeadline  You may also edit paper contacts, allowing others to view reviews and make changes." . "</div>";
	} else if ($prow->conflictType >= CONFLICT_AUTHOR) {
	    $override2 = ($Me->privChair ? " As an administrator, you can update the paper anyway by selecting \"Override deadlines\"." : "");
	    if ($this->mode == "pe") {
		$m .= "<div class='xinfo'>This paper is under review and can’t be changed";
                if ($Me->canWithdrawPaper($prow))
                    $m .= ", but you can withdraw it from consideration";
                $m .= ".$override2</div>";
            }
	} else
	    $m .= "<div class='xinfo'>" . "You aren’t a contact for this paper, but as an administrator you can still make changes." . "</div>";

	return $m;
    }

    function _collectActionButtons() {
        global $Conf, $Me, $linkExtra;
        $prow = $this->prow;

        // Absent paper can only be saved
        if (!$prow)
            return array("<input class='bb' type='submit' name='update' value='Save paper' />");

        // Withdrawn papers can be revived
        if ($prow->timeWithdrawn > 0) {
            $revivable = $Conf->timeFinalizePaper($prow);
            if ($revivable || $Me->privChair) {
                $b = "<input class='b' type='submit' name='revive' value='Revive paper' />";
                if (!$revivable)
                    $b = array($b, "(admin only)");
            } else
                $b = "The <a href='" . hoturl("deadlines") . "'>deadline</a> for reviving withdrawn papers has passed.";
            return array($b);
        }

        $buttons = array();

        if ($prow->outcome > 0 && $Conf->collectFinalPapers()
            && ($Conf->timeSubmitFinalPaper() || $Me->privChair)
            && $this->mode == "pe")
            $buttons[] = array("<input class='bb' type='submit' name='submitfinal' value='Submit final version' />", "");
        else if ($Conf->timeUpdatePaper($prow) && $this->mode == "pe")
            $buttons[] = array("<input class='bb' type='submit' name='update' value='Save changes' />", "");
        else if ($Me->privChair && $this->mode == "pe") {
            $class = ($prow->outcome > 0 && $Conf->collectFinalPapers() ? "b" : "bb");
            $buttons[] = array("<input class='$class' type='submit' name='update' value='Save changes' />", "(admin only)");
        }

        // withdraw button
        if (!$Me->canWithdrawPaper($prow, $whyNot, true))
            $b = null;
        else if ($prow->timeSubmitted <= 0)
            $b = "<input class='b' type='submit' name='withdraw' value='Withdraw paper' />";
        else {
            $b = "<button type='button' class='b' onclick=\"popup(this,'w',0,true)\">Withdraw paper</button>";
            $admins = "";
            if ((!$Me->privChair || $prow->conflictType >= CONFLICT_AUTHOR)
                && !$Conf->timeFinalizePaper($prow))
                $admins = "Only administrators can undo this step.";
            $override = "";
            if (!$Me->canWithdrawPaper($prow))
                $override = "<div>" . tagg_checkbox("override") . "&nbsp;" .
                    tagg_label("Override deadlines") . "</div>";
            $Conf->footerHtml("<div id='popup_w' class='popupc'>
  <p>Are you sure you want to withdraw this paper from consideration and/or
  publication?  $admins</p>
  <form method='post' action=\"" . hoturl_post("paper", "p=" . $prow->paperId . "&amp;m=pe$linkExtra") . "\" enctype='multipart/form-data' accept-charset='UTF-8'><div class='inform'>
    <textarea id='withdrawreason' class='temptext' name='reason' rows='3' cols='40' style='width:99%'>Optional explanation</textarea>$override
    <div class='popup_actions' style='margin-top:10px'>
      <input class='popup_populate' type='hidden' name='doemail' value='1' />
      <input class='popup_populate' type='hidden' name='emailNote' value='' />
      <button type='button' class='b' onclick=\"popup(null, 'w', 1)\">Cancel</button>
      &nbsp;<input class='bb' type='submit' name='withdraw' value='Withdraw paper' />
    </div>
  </div></form></div>");
            $Conf->footerScript("mktemptext('withdrawreason','Optional explanation')");
        }
        if ($b) {
            if (!$Me->canWithdrawPaper($prow))
                $b = array($b, "(admin only)");
            $buttons[] = $b;
        }

        // Edit contacts
        if (!$this->editable)
            $buttons[] = "<button type='button' class='b' onclick='window.location=\"" . hoturl("contactauthors", "p=" . $prow->paperId . $linkExtra) . "\"'>Edit contacts</button>";

        return $buttons;
    }

    function echoActions() {
	global $Conf, $Me, $linkExtra;
	$prow = $this->prow;

	$buttons = $this->_collectActionButtons();

	if ($Me->privChair && $prow) {
	    $buttons[] = array("<button type='button' class='b' onclick=\"popup(this,'d',0,true)\">Delete paper</button>", "(admin only)");
	    $Conf->footerHtml("<div id='popup_d' class='popupc'>
  <p>Be careful: This will permanently delete all information about this
  paper from the database and <strong>cannot be undone</strong>.</p>
  <form method='post' action=\"" . hoturl_post("paper", "p=" . $prow->paperId . "&amp;m=pe$linkExtra") . "\" enctype='multipart/form-data' accept-charset='UTF-8'>
    <div class='popup_actions'>
      <input class='popup_populate' type='hidden' name='doemail' value='1' />
      <input class='popup_populate' type='hidden' name='emailNote' value='' />
      <button type='button' class='b' onclick=\"popup(null, 'd', 1)\">Cancel</button>
      &nbsp;<input class='bb' type='submit' name='delete' value='Delete paper' />
    </div>
  </form></div>");
	}

	echo tagg_actions($buttons);
	if ($Me->privChair) {
	    $v = defval($_REQUEST, "emailNote", "");
	    echo "  <div class='g'></div>\n  <table>\n",
		"    <tr><td>",
		tagg_checkbox("doemail", 1, true), "&nbsp;",
		tagg_label("Email authors, including:"), "&nbsp; ",
		"<input id='emailNote' type='text' class='textlite temptext' name='emailNote' size='30' value='",
		htmlspecialchars($v == "" ? "Optional explanation" : $v),
		"' /></td></tr>\n    <tr><td>",
		tagg_checkbox("override"), "&nbsp;",
		tagg_label("Override deadlines"), "</td></tr>\n",
		"  </table>\n";
	    $Conf->footerScript("mktemptext('emailNote','Optional explanation')");
	}
    }


    // Functions for overall paper table viewing

    function _papstrip() {
	global $Conf, $Me, $forceShow, $linkExtra;
	$prow = $this->prow;
	if ($Me->canViewTags($prow, $forceShow))
	    $this->papstripTags($prow, "review");
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

    function _paptabTabLink($text, $link, $image, $highlight) {
	global $Conf;
	echo "<div class='", ($highlight ? "papmodex" : "papmode"),
	    "'><a href='", $link, "' class='", ($highlight ? "qx" : "xx"),
	    "'>", $Conf->cacheableImage($image, "[$text]", null, "b"),
	    "&nbsp;<u", ($highlight ? " class='x'" : ""), ">", $text,
	    "</u></a></div>\n";
    }

    function _paptabBeginKnown() {
	global $Conf, $Me, $forceShow, $linkExtra;
	$prow = $this->prow;

	// what actions are supported?
	$canEdit = $Me->canEditPaper($prow);
	$canReview = $Me->canReview($prow, null);
	$canAssign = $Me->actChair($prow);
	$canHome = ($canEdit || $canAssign || $this->mode == "contact");

	echo "<div class='pban'>";

	// paper tabs
	if ($canEdit || $canReview || $canAssign || $canHome) {
	    echo "<div class='psmodec'><div class='psmode'>";

	    // home link
	    $highlight = ($this->mode != "assign" && $this->mode != "pe"
			  && $this->mode != "contact" && $this->mode != "re");
	    $a = ($this->mode == "pe" || $this->mode == "re" ? "&amp;m=p" : "");
	    $this->_paptabTabLink("Main", hoturl("paper", "p=$prow->paperId$a$linkExtra"), "view18.png", $highlight);

	    if ($canEdit)
		$this->_paptabTabLink("Edit", hoturl("paper", "p=$prow->paperId&amp;m=pe$linkExtra"), "edit18.png", $this->mode == "pe");

	    if ($canReview)
		$this->_paptabTabLink("Review", hoturl("review", "p=$prow->paperId&amp;m=re$linkExtra"), "review18.png", $this->mode == "re" && (!$this->editrrow || $this->editrrow->contactId == $Me->contactId));

	    if ($canAssign)
		$this->_paptabTabLink("Assign", hoturl("assign", "p=$prow->paperId$linkExtra"), "assign18.png", $this->mode == "assign");

	    echo "<div class='clear'></div></div></div>\n";
	}

	// paper number
	$pa = "<a href='" . hoturl("paper", "p=$prow->paperId$linkExtra") . "' class='q'>";
	echo "<table class='pban'><tr>
    <td class='pboxi'><div class='papnum'>",
	    "<h2>", $pa, "#", $prow->paperId, "</a></h2></div></td>\n";

	// paper title
	echo "    <td class='pboxt'><h2>", $pa;
	$this->echoTitle($prow);
	echo "</a></h2></td>
    <td class='pboxj'></td>
</tr></table>\n";

	echo "</div>\n";
    }

    function paptabBegin($contentopener = "") {
	global $Conf, $Me, $forceShow, $linkExtra;
	$prow = $this->prow;
	$pboxclass = $prow ? "pbox" : "pboxn";

	if ($prow) {
	    $this->_paptabBeginKnown();
	    echo "<div class='pbox1'><table class='pbox'><tr>\n",
		"    <td class='pboxi'><div class='papstripc'><div class='papstrip'><div class='papstripi'>\n";
	    $this->_papstrip();
	    echo "</div></div></div></td>\n";
	} else {
	    echo "<div class='pbox1'><table class='pbox'><tr>\n",
		"    <td class='pboxni'></td>\n",
		"    <td class='pboxnt'><table class='papcbar'>\n",
		"	<tr><td class='papculs'></td><td></td><td class='papcur'></td></tr>\n",
		"	<tr><td></td><td><h2>New paper</h2></td><td></td></tr>\n",
		"    </table></td>\n",
		"    <td class='pboxnj'></td>\n",
		"</tr><tr>",
		"    <td class='pboxnl'></td>\n";
	}
	echo "    <td class='${pboxclass}r'>", $contentopener, "<table class='papcpap'>
	<tr><td class='papcl'>",
	    $Conf->cacheableImage("_.gif", "", null, "_"),
	    "</td><td class='papct'><div class='inpapct'>";

	$form = "<form method='post' action=\""
	    . hoturl_post("paper", "p="
			  . ($prow ? $prow->paperId : "new")
			  . "&amp;m=pe$linkExtra") . "\"";
	if ($prow && $prow->paperStorageId > 1 && $prow->timeSubmitted > 0
	    && !$Conf->setting('sub_freeze'))
	    $form .= " onsubmit='return docheckpaperstillready()'";
	$form .= " enctype='multipart/form-data' accept-charset='UTF-8'>";

	$this->echoDivEnter($prow);
	if ($this->editable) {
	    $spacer = "<div class='g'></div>\n\n";
	    echo $form, "<div class='aahc'>";
	    $this->canUploadFinal = $prow && $prow->outcome > 0
		&& ($Me->canSubmitFinalPaper($prow, $whyNot, true)
		    || defval($whyNot, "deadline") == "final_done");

	    if (($m = $this->editMessage()))
		echo $m, $spacer;

	    $this->paptabTitle($prow);
	    $this->echoUploadRow($prow,
		(!$prow || $prow->size == 0 ? PaperTable::ENABLESUBMIT : 0));
	    $this->echoOptions($prow, $Me->privChair, 4);

	    // Authorship
	    echo $spacer;
	    $this->echoAuthorInformation($prow);
	    if (!$prow)
		$this->echoNewContactAuthor($Me->privChair);
	    else
		$this->echoContactAuthor($prow, $this->mode == "pe" || $prow->conflictType >= CONFLICT_AUTHOR);
	    if ($Conf->subBlindOptional() && $this->editable !== "f")
		$this->echoAnonymity($prow);

	    echo $spacer;
	    $this->paptabAbstract($prow);

	    // Topics and options
	    echo $spacer;
	    $this->echoTopics($prow);
	    $this->echoOptions($prow, $Me->privChair, 3);

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
	    if ($this->mode == "pe" && ($m = $this->editMessage()))
		echo $m, "<div class='g'></div>\n";
	    $this->paptabDownload($prow);
	    echo "<table class='paptab'><tr><td class='paple'><div class='paple'>";
	    $this->paptabAbstract($prow);
	    echo "</div></td><td class='papre'><div class='papre'>";
	    $this->paptabAuthors($prow);
	    $this->paptabTopicsOptions($prow, $Me->actChair($prow));
	    echo "</div></td></tr></table>";
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

	if ($this->mode == "p" || $this->mode == "r" || $this->mode == "re")
	    $Conf->footerScript("shortcut().add()");
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
	echo tagg_cbox("pap", true), "</td></tr></table></div>\n";
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
	global $Conf, $Me, $rf, $forceShow, $linkExtra;
	if (!$rf)
	    $rf = reviewForm();
	$prow = $this->prow;

	if ($forceShow && !$Me->canViewReview($prow, null, $fakeWhyNotView, true))
	    $this->_paptabSepContaining("<div class='inpapcc'>" . $this->_privilegeMessage() . "</div>");

	$empty = $this->_paptabReviewLinks(true, null, "<div class='hint'>There are no reviews or comments for you to view.</div>");
	if ($empty)
	    return;

	// text format link
	$viewable = array();
	foreach ($this->rrows as $rr)
	    if ($rr->reviewModified > 0 && $Me->canViewReview($prow, $rr)) {
		$viewable[] = "reviews";
		break;
	    }
	foreach ($this->crows as $cr)
	    if ($Me->canViewComment($prow, $cr)) {
		$viewable[] = "comments";
		break;
	    }
	if (count($viewable))
	    echo "<div class='pboxc'>",
		"<table class='pbox'><tr><td class='pboxl'></td>",
		"<td class='pboxr'>",
		"<a href='", hoturl("review", "p=$prow->paperId&amp;m=r&amp;text=1$linkExtra"), "' class='xx'>",
		$Conf->cacheableImage("txt24.png", "[Text]", null, "dlimg"),
		"&nbsp;<u>", ucfirst(join(" and ", $viewable)),
		" in plain text</u></a></td></tr></table></div>\n";

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
	global $Conf, $Me, $forceShow, $linkExtra, $useRequest;
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

	    $cv->table_end();
	    unset($_SESSION["comment_msgs"]);
	}
    }

    function paptabEndWithReviewMessage() {
	global $Conf, $Me, $linkExtra;

	if (!$Me->canViewReview($this->prow, null, $whyNot)
	    && $this->rrows)
	    $this->_paptabSepContaining("You can&rsquo;t see the reviews for this paper.  " . whyNotText($whyNot, "review"));

	if ($this->mode != "pe")
	    $this->_paptabReviewLinks(false, null, "");
	else
	    echo tagg_cbox("pap", true), "</td></tr></table></div>\n";
    }

    function paptabEndWithEditableReview() {
	global $Conf, $Me, $rf, $forceShow, $linkExtra;
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
		$msgs[] = "You’ll be able to see " . plural($nother, "other review") . " once you complete your own.";
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
		$opt["editmessage"] = "The <a href='" . hoturl("deadlines") . "'>deadline</a> for changing reviews has passed, so the review can no longer be changed.$override";
	    else
		$opt["editmessage"] = "The site is not open for reviewing, so the review cannot be changed.$override";
	} else if (!$Me->canReview($prow, $this->editrrow))
	    $opt["edit"] = false;

	$rf->show($prow, $this->rrows, $this->editrrow, $opt);
    }


    // Functions for loading papers

    static function _maybeSearchPaperId() {
	global $Conf, $Me;

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
	if ($_REQUEST["paperId"] == "(All)")
	    $_REQUEST["paperId"] = "";
	$search = new PaperSearch($Me, array("q" => $_REQUEST["paperId"], "t" => defval($_REQUEST, "t", 0)));
	$pl = $search->paperList();
	if (count($pl) == 1) {
	    $_REQUEST["paperId"] = $pl[0];
	    $_REQUEST["ls"] = allocateListNumber($search->listId());
	    $_SESSION["l"][$_REQUEST["ls"]] = $search->sessionList();
	    return true;
	} else {
	    $t = (defval($_REQUEST, "t", 0) ? "&t=" . urlencode($_REQUEST["t"]) : "");
	    $Conf->go(hoturl("search", "q=" . urlencode($_REQUEST["paperId"]) . $t));
	    exit;
	}
    }

    static function cleanRequest() {
	if (!isset($_REQUEST["paperId"]) && isset($_REQUEST["p"]))
	    $_REQUEST["paperId"] = $_REQUEST["p"];
	if (!isset($_REQUEST["reviewId"]) && isset($_REQUEST["r"]))
	    $_REQUEST["reviewId"] = $_REQUEST["r"];
	if (!isset($_REQUEST["commentId"]) && isset($_REQUEST["c"]))
	    $_REQUEST["commentId"] = $_REQUEST["c"];
	if (!isset($_REQUEST["paperId"]) && isset($_SERVER["PATH_INFO"])
	    && preg_match(',\A/(?:new|\d+)\z,i', $_SERVER["PATH_INFO"]))
	    $_REQUEST["paperId"] = substr($_SERVER["PATH_INFO"], 1);
	else if (!isset($_REQUEST["reviewId"]) && isset($_SERVER["PATH_INFO"])
		 && preg_match(',\A/\d+[A-Z]+\z,i', $_SERVER["PATH_INFO"]))
	    $_REQUEST["reviewId"] = substr($_SERVER["PATH_INFO"], 1);
	if (!isset($_REQUEST["paperId"]) && isset($_REQUEST["reviewId"])
	    && preg_match('/^(\d+)[A-Z]+$/', $_REQUEST["reviewId"], $m))
	    $_REQUEST["paperId"] = $m[1];
    }

    static function paperRow(&$whyNot) {
	global $Conf, $Me;

	self::cleanRequest();
	if (isset($_REQUEST["paperId"]) && $_REQUEST["paperId"] == "new")
	    return null;

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
