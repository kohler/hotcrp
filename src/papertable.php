<?php
// papertable.php -- HotCRP helper class for producing paper tables
// HotCRP is Copyright (c) 2006-2013 Eddie Kohler and Regents of the UC
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
    private $admin;

    function __construct($prow) {
	global $Conf, $Me;

	$this->prow = $prow;
	$this->rrows = null;
	$this->crows = null;
	$this->rrow = null;
	$this->editrrow = null;
        $this->admin = $Me->allowAdminister($prow);

	if ($this->prow == null) {
	    $this->mode = "pe";
	    return;
	}

	$ms = array();
	if (isset($_REQUEST["reviewId"]))
	    $ms["re"] = true;
	if ($Me->canViewReview($prow, null, null))
	    $ms["r"] = true;
	if ($Me->canReview($prow, null))
	    $ms["re"] = true;
	if ($prow->has_author($Me)
	    && ($Conf->timeFinalizePaper($prow) || $prow->timeSubmitted <= 0))
	    $ms["pe"] = true;
	if ($Me->canViewPaper($prow))
	    $ms["p"] = true;
	if ($prow->has_author($Me)
	    || $Me->allowAdminister($prow))
	    $ms["pe"] = true;
	if ($prow->myReviewType >= REVIEW_SECONDARY
	    || $Me->allowAdminister($prow))
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

    private static function _combine_match_preg($m1, $m) {
        if (!is_array($m))
            $m = array("abstract" => $m, "title" => $m,
                       "authorInformation" => $m, "collaborators" => $m);
        foreach ($m as $k => $v)
            if (!@$m1[$k])
                $m1[$k] = $v;
        return $m1;
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
	$matcher = array();
        if ($CurrentList > 0 && @($l = SessionList::lookup($CurrentList))
            && @$l->matchPreg)
	    $matcher = self::_combine_match_preg($matcher, $l->matchPreg);
        if (@$_SESSION["temp_matchPreg"]) {
            $matcher = self::_combine_match_preg($matcher, $_SESSION["temp_matchPreg"]);
            unset($_SESSION["temp_matchPreg"]);
        }
        foreach ($matcher as $k => $v)
            if (is_string($v) && $v != "") {
                if ($v[0] != "{")
                    $v = "{(" . $v . ")}i";
                $this->matchPreg[$k] = $v;
            } else if (is_object($v))
                $this->matchPreg[$k] = $v;
	if (count($this->matchPreg) == 0)
	    $this->matchPreg = null;

	$this->highlight = $this->matchPreg !== null;
	$this->watchCheckbox = WATCH_COMMENT;
	$this->initial = true;
    }

    private function echoDivEnter() {
	// if highlighting, automatically unfold abstract/authors
	if ($this->highlight && $this->prow && $this->allFolded
	    && ($this->foldState & 64)) {
	    $data = $this->entryData("abstract");
	    if ($this->entryMatches)
		$this->foldState &= ~64;
	}
	if ($this->highlight && $this->prow && ($this->foldState & 256)) {
	    cleanAuthor($this->prow);
	    $data = $this->entryData("authorInformation");
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

    private function editable_papt($what, $name) {
	global $Error, $Warning;
	$c = "<div class='papt";
	if (isset($Error[$what]) || isset($Warning[$what]))
	    $c .= " error";
	return $c . "'><span class='papfn'>" . $name
            . "</span><div class='clear'></div></div>";
    }

    private function papt($what, $name, $extra = array()) {
	global $Error, $Conf;
	$type = defval($extra, "type", "pav");
        $fold = defval($extra, "fold", false);
        $editfolder = defval($extra, "editfolder", false);
        if ($fold || $editfolder) {
            $foldnum = defval($extra, "foldnum", 0);
            if (isset($extra["foldsession"]))
                $foldnumarg = "," . (+$foldnum) . ",'" . $extra["foldsession"] . "'";
            else
                $foldnumarg = $foldnum ? ",$foldnum" : "";
        }

	$c = "<div class=\"${type}t";
	if (isset($Error[$what]))
	    $c .= " error";
        if ($fold || $editfolder)
            $c .= " childfold\" onclick=\"return foldup(this,event$foldnumarg)";
	$c .= "\"><span class=\"${type}fn\">";
	if (!$fold) {
	    $n = (is_array($name) ? $name[0] : $name);
	    if ($editfolder)
		$c .= "<a class=\"q fn\" title=\"Edit\" "
		    . "href=\"" . selfHref(array("atab" => $what))
		    . "\" onclick=\"return foldup(this,event$foldnumarg)\">"
		    . $n . "</a><span class=\"fx\">" . $n . "</span>";
	    else
		$c .= $n;
	} else {
	    if (!is_array($name))
		$name = array($name, $name);
	    $c .= "<a class=\"q fn$foldnum\" "
		. "href=\"" . selfHref(array("pfold" => $foldnum . "o"))
		. "\" onclick=\"return foldup(this,event$foldnumarg)\" "
		. "title=\"" . defval($extra, "showtitle", "Show")
		. "\">" . expander(true) . $name[1] . "</a>"
		. "<a class=\"q fx$foldnum\" "
		. "href=\"" . selfHref(array("pfold" => $foldnum . "c"))
		. "\" onclick=\"return foldup(this,event$foldnumarg)\" "
		. "title=\"" . defval($extra, "hidetitle", "Hide")
		. "\">" . expander(false) . $name[0] . "</a>";
	}
	$c .= "</span>";
	if ($editfolder) {
	    $c .= "<span class=\"pstedit fn\">"
		. "<a class=\"xx\" href=\"" . selfHref(array("atab" => $what))
		. "\" onclick=\"return foldup(this,event$foldnumarg)\" title=\"Edit\">"
		. "<span style='display:inline-block;position:relative;width:15px'>"
		. $Conf->cacheableImage("edit.png", "[Edit]", null, "bmabs")
		. "</span>&nbsp;<u class=\"x\">Edit</u></a></span>";
	}
	$c .= "<div class=\"clear\"></div></div>";
	return $c;
    }

    private function entryData($fieldName, $authorTable = false) {
	global $textAreaRows;
	$this->entryMatches = 0;

	if ($this->useRequest)
	    $text = $_REQUEST[$fieldName];
	else if ($this->prow)
	    $text = $this->prow->$fieldName;
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

    private function echoTitle() {
	if ($this->highlight && isset($this->matchPreg["title"]))
	    echo Text::highlight($this->prow->title, $this->matchPreg["title"]);
	else
	    echo htmlspecialchars($this->prow->title);
    }

    private function editable_title() {
	echo $this->editable_papt("title", "Title"),
	    "<div class='papv'>", $this->entryData("title"), "</div>\n\n";
    }

    static function pdfStamps($data) {
	global $Conf, $Opt;

	$t = array();
	$tm = defval($data, "timestamp", defval($data, "timeSubmitted", 0));
	if ($tm > 0)
	    $t[] = "<span class='nowrap' title='Time of most recent update'>" . $Conf->cacheableImage("_.gif", "Updated", "Time of most recent update", "timestamp12") . " " . $Conf->printableTimestamp($tm) . "</span>";
	$sha1 = defval($data, "sha1");
	if ($sha1)
	    $t[] = "<span class='nowrap' title='SHA-1 checksum'>" . $Conf->cacheableImage("_.gif", "SHA-1", "SHA-1 checksum", "checksum12") . " " . bin2hex($sha1) . "</span>";
	if (count($t) > 0)
	    return "<span class='hint'>" . join(" &nbsp;<span class='barsep'>|</span>&nbsp; ", $t) . "</span>";
	else
	    return "";
    }

    private function paptabDownload() {
	global $Conf, $Me;
	assert(!$this->editable);
        $prow = $this->prow;
	$final = ($prow->outcome > 0 && $Conf->collectFinalPapers());
	$out = array();

	// status and download
	if ($Me->canDownloadPaper($prow)) {
	    $t = "<td class='nowrap pad'>" . $Me->paperStatus($prow->paperId, $prow, 0) . "</td>";
	    $pdfs = array();

	    $dprefix = "";
	    if ($final && $prow->finalPaperStorageId > 1) {
		$data = paperDocumentData($prow, DTYPE_FINAL);
		$dprefix = "Final version: &nbsp;";
	    } else
		$data = paperDocumentData($prow, DTYPE_SUBMISSION);
	    if ($data) {
		if (($stamps = self::pdfStamps($data)))
		    $stamps = "<span class='sep'></span>" . $stamps;
		$pdfs[] = $dprefix . documentDownload($data) . $stamps;
	    }

	    foreach (PaperOption::option_list() as $id => $o)
		if (@$o->near_submission
                    && $o->is_document()
		    && (!@$o->final || $final)
                    && $prow
                    && ($oa = $prow->option($id))
                    && $oa->value > 1
                    && ($d = paperDocumentData($prow, $id, $oa->value))) {
                    $pdfs[] = "<span class='papfn'>"
                        . htmlspecialchars($o->name)
                        . "</span>: &nbsp;"
                        . documentDownload($d, count($pdfs) ? "dlimgsp" : "dlimg");
		}

	    if ($final && $prow->finalPaperStorageId > 1
		&& $prow->paperStorageId > 1) {
                $doc = (object) array("paperId" => $prow->paperId,
                                      "mimetype" => null,
                                      "documentType" => DTYPE_SUBMISSION);
		$pdfs[] = "<small><a class='u' href=\""
		    . HotCRPDocument::url($doc)
		    . "\">Submission version</a></small>";
            }

	    $t .= "<td>";
	    foreach ($pdfs as $p)
		$t .= "<p class='od'>" . $p . "</p>";
	    $out[] = "<table><tr>$t</td></tr></table>";
	}

	// conflicts
	if ($prow->has_author($Me))
	    $out[] = "You are an <span class='author'>author</span> of this paper.";
	else if ($prow->has_conflict($Me))
	    $out[] = "You have a <span class='conflict'>conflict</span> with this paper.";
	if ($Me->isPC && !$prow->has_conflict($Me)
	    && $Conf->timeUpdatePaper($prow) && $this->mode != "assign"
	    && $this->mode != "contact")
	    $out[] = "<div class='xwarning'>The authors still have <a href='" . hoturl("deadlines") . "'>time</a> to make changes.</div>";
	if (count($out))
	    $out[] = "";

	echo join("<div class='g'></div>\n", $out);
    }

    private function editable_document($opt, $storageId, $flags) {
	global $Conf, $Me, $Opt;

        $prow = $this->prow;
        $docclass = new HotCRPDocument($opt->id, $opt);
	$documentType = $opt->id;
	$optionType = $opt->type;
        $main_submission = ($documentType == DTYPE_SUBMISSION || $documentType == DTYPE_FINAL);
	$noPapers = defval($Opt, "noPapers") && $main_submission;
	$banal = $Conf->setting("sub_banal")
	    && ($optionType == null || $optionType == "pdf")
            && $main_submission;

	$filetypes = array();
	$accepts = array();
	if ($noPapers) {
	    if ($documentType == DTYPE_SUBMISSION)
		echo $this->editable_papt($opt->abbr, "Status");
	} else {
            $accepts = $docclass->mimetypes();
	    if (count($accepts))
		echo $this->editable_papt($opt->abbr, htmlspecialchars($opt->name) . " <span class='papfnh'>(" . htmlspecialchars(Mimetype::description($accepts)) . ", max " . ini_get("upload_max_filesize") . "B)</span>");
	}
	if (@$opt->description)
	    echo "<div class='paphint'>", $opt->description, "</div>";
	echo "<div class='papv'>";

	// current version, if any
	$doc = null;
        $inputid = ($optionType ? "opt" . $documentType : "paperUpload");
	if ($prow && $Me->canDownloadPaper($prow) && $storageId > 1
	    && (($doc = paperDocumentData($prow, $documentType, $storageId)))) {
	    echo "<table id='current_$inputid'><tr>",
		"<td class='nowrap'>", documentDownload($doc), "</td>";
	    if ($doc->mimetype == "application/pdf" && $banal)
		echo "<td><span class='sep'></span></td><td><a href='javascript:docheckformat($documentType)'>Check format</a></td>";
	    if (($stamps = self::pdfStamps($doc)))
		echo "<td><span class='sep'></span></td><td>$stamps</td>";
	    echo "</tr></table>\n";
	}

	// uploader
	$uploader = "";
	if (count($accepts)) {
	    if ($doc)
		$uploader .= "<div class='g'></div><div id='removable_$inputid' class='foldo'><span class='fx'>Replace:&nbsp; ";
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
		    . "<a id='remover_$inputid' href='#remover_$inputid' onclick='return doremovedocument(this)'>Delete</a>";
	    $uploader .= "</span>";
	    if ($doc && $optionType)
		$uploader .= "<span class='fn'><em>Marked for deletion</em></span>";
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
		Ht::checkbox_h("submit", 1, $checked, array("id" => "paperisready")), "&nbsp;";
	    if ($Conf->setting('sub_freeze'))
		echo "</td><td>", Ht::label("<strong>This is the final submission.</strong>"),
		    "</td></tr><tr><td></td><td><small>You must submit a final version before the deadline or your paper will not be reviewed.  Once you submit a final version you will not be able to make further changes.</small>";
	    else
		echo Ht::label("The paper is ready for review.");
	    echo "</td></tr></table></div>\n";
	    $Conf->footerScript("hotcrp_onload.push(function(){var x=\$\$(\"paperUpload\");if(x&&x.value)fold(\"isready\",0)})");
	} else if ($documentType == DTYPE_FINAL)
	    echo "<input type='hidden' name='submit' value='1' />";

	echo $uploader;

	echo "</div>\n\n";
    }

    private function editable_submission($flags) {
	if ($this->canUploadFinal)
	    $this->editable_document(PaperOption::find_document(DTYPE_FINAL), $this->prow ? $this->prow->finalPaperStorageId : 0, $flags);
        else
	    $this->editable_document(PaperOption::find_document(DTYPE_SUBMISSION), $this->prow ? $this->prow->paperStorageId : 0, $flags);
    }

    private function editable_abstract() {
        echo "<div class='pg pgtop'>",
            $this->editable_papt("abstract", "Abstract"),
            "<div class='papv abstract'>",
            $this->entryData("abstract", "p"),
            "</div></div>\n\n";
    }

    private function paptabAbstract() {
	$data = $this->entryData("abstract", "p");
	if ($this->allFolded && strlen($data) > 190) {
	    $shortdata = trim(preg_replace(",</?p.*?>,", "\n", $data));
	    $shortdata = preg_replace("/\\S+(<[^>]+)?\\Z/", "", utf8_substr($shortdata, 0, 180));
	    if ($shortdata != "") { /* "" might happen if really long word */
                echo "<div class='pg pgtop'>",
                    $this->papt("abstract", "Abstract",
                                array("fold" => "paper", "foldnum" => 6,
                                      "foldsession" => "foldpaperb",
                                      "showtitle" => "Show full abstract",
                                      "hidetitle" => "Abbreviate abstract")),
                    "<div class='pavb abstract'>",
		    "<div class='fn6'>", $shortdata,
		    " <a class='fn6' href='javascript:void fold(\"paper\", 0, 6)'>[more]</a>",
		    "</div><div class='fx6'>", $data,
                    "</div></div></div>\n\n";
		return;
	    }
	}
        echo "<div class='pg pgtop'>",
            $this->papt("abstract", "Abstract"),
            "<div class='pavb abstract'>", $data, "</div></div>\n\n";
    }

    private function editable_authors() {
	global $Conf;
        cleanAuthor($this->prow);

	echo $this->editable_papt("authorInformation", "Authors <span class='papfnh'>(<a href='javascript:void authorfold(\"auedit\",1,1)'>More</a> | <a href='javascript:void authorfold(\"auedit\",1,-1)'>Fewer</a>)</span>"),
	    "<div class='paphint'>List the paper&rsquo;s authors one per line, including their email addresses and affiliations.";
	if ($Conf->subBlindAlways())
	    echo " Submission is blind, so reviewers will not be able to see author information.";
	echo "  Any author with an account on this site can edit the paper.</div>",
	    "<div class='papv'><table id='auedittable' class='auedittable'><tr><th></th><th>Name</th><th>Email</th><th>Affiliation</th></tr>\n";

	$blankAu = array("", "", "", "");
	if ($this->useRequest && isset($_REQUEST["authorTable"]))
	    $authorTable = $_REQUEST["authorTable"];
	else
	    $authorTable = ($this->prow ? $this->prow->authorTable : array());
	for ($n = 1; $n <= 25; $n++) {
	    $au = ($n <= count($authorTable) ? $authorTable[$n - 1] : $blankAu);
	    if ($au[0] && $au[1] && !preg_match('@^\s*(v[oa]n\s+|d[eu]\s+)?\S+(\s+jr.?|\s+sr.?|\s+i+)?\s*$@i', $au[1]))
		$auname = $au[1] . ", " . $au[0];
	    else if ($au[0] && $au[1])
		$auname = $au[0] . " " . $au[1];
	    else
		$auname = $au[0] . $au[1];
	    echo "<tr id='auedit$n' class='auedito'><td class='rxcaption'>", $n, ".</td>",
		"<td class='lentry'>", Ht::entry("auname$n", $auname, array("class" => "textlite", "size" => "35", "onchange" => "hiliter(this)")), "</td>",
		"<td class='lentry'>", Ht::entry("auemail$n", $au[2], array("class" => "textlite", "size" => "30", "onchange" => "hiliter(this)")), "</td>",
		"<td class='lentry'>", Ht::entry("auaff$n", $au[3], array("class" => "textlite", "size" => "32", "onchange" => "hiliter(this)")), "</td>",
                "</tr>\n";
	}
	echo "</table>", Ht::hidden("aueditcount", "25", array("id" => "aueditcount")), "</div>\n\n";
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
                if (is_object($au))
                    $au = array($au->firstName, $au->lastName, $au->email, $au->affiliation, 0);
                $nm1 = $nm2 = $nm3 = 0;
                $n = $e = $t = "";
		$n = trim(Text::highlight("$au[0] $au[1]", $highpreg, $nm1));
                if ($au[2] != "") {
                    $e = Text::highlight($au[2], $highpreg, $nm2);
                    $e = '&lt;<a href="mailto:' . htmlspecialchars($au[2])
                        . '">' . $e . '</a>&gt;';
                }
                $t = ($n == "" ? $e : $n);
                if ($au[3] != "")
                    $t .= ' <span class="auaff">(' . Text::highlight($au[3], $highpreg, $nm3) . ')</span>';
                if ($n != "" && $e != "")
                    $t .= " " . $e;
                $this->entryMatches += $nm1 + $nm2 + $nm3;
		$t = trim($t);
		if ($au[2] != "" && $viewAs !== null && $viewAs->email != $au[2]
		    && $viewAs->privChair && defval($au, 4))
		    $t .= " <a href=\"" . selfHref(array("actas" => $au[2])) . "\">" . $Conf->cacheableImage("viewas.png", "[Act as]", "Act as " . Text::name_html($au)) . "</a>";
		$names[] = '<p class="odname">' . $prefix . $t . '</p>';
		$prefix = "";
	    }
	    return join("\n", $names);
	}
    }

    private function _analyze_authors() {
        global $Conf;
	// clean author information
	cleanAuthor($this->prow);
        $autable = $this->prow->authorTable;

	// find contact author information, combine with author table
	$result = $Conf->qe("select firstName, lastName, email, '' as affiliation, contactId
		from ContactInfo join PaperConflict using (contactId)
		where paperId=" . $this->prow->paperId . " and conflictType>=" . CONFLICT_AUTHOR, "while finding contacts");
	$contacts = array();
	while (($row = edb_orow($result))) {
	    $match = -1;
	    for ($i = 0; $match < 0 && $i < count($autable); ++$i)
		if ($autable[$i][2] == $row->email)
		    $match = $i;
	    if (($row->firstName != "" || $row->lastName != "") && $match < 0) {
		$contact_n = $row->firstName . " " . $row->lastName;
		$contact_preg = str_replace("\\.", "\\S*", "{\\b" . preg_quote($row->firstName) . "\\b.*\\b" . preg_quote($row->lastName) . "\\b}i");
		for ($i = 0; $match < 0 && $i < count($autable); ++$i) {
		    $f = $autable[$i][0];
		    $l = $autable[$i][1];
		    if (($f != "" || $l != "") && $autable[$i][2] == "") {
			$author_n = $f . " " . $l;
			$author_preg = str_replace("\\.", "\\S*", "{\\b" . preg_quote($f) . "\\b.*\\b" . preg_quote($l) . "\\b}i");
			if (preg_match($contact_preg, $author_n)
			    || preg_match($author_preg, $contact_n))
			    $match = $i;
		    }
		}
	    }
	    if ($match >= 0) {
		if ($autable[$match][2] == "")
		    $this->prow->authorTable[$match][2] = $row->email;
		$this->prow->authorTable[$match][4] = $row->contactId;
	    } else {
                Contact::set_sorter($row);
		$contacts[] = $row;
            }
	}

        uasort($contacts, "Contact::compare");
        return array($this->prow->authorTable, $contacts);
    }

    private function paptabAuthors($skip_contacts) {
	global $Conf, $Me;

	$viewable = $Me->canViewAuthors($this->prow, false);
	if (!$viewable && !$Me->canViewAuthors($this->prow, true)) {
	    echo "<div class='pg pgtop'>",
                $this->papt("authorInformation", "Authors"),
                "<div class='pavb'><i>Hidden for blind review</i></div>",
                "</div>\n\n";
	    return;
	}

	// clean author information
        list($autable, $contacts) = $this->_analyze_authors();

	// anonymity folding
	if (!$viewable) {
            echo "<div class='pg pgtop fn8'>",
                $this->papt("authorInformation", "Authors"),
                "<div class='pavb'><a class='q fn8' ",
		"href='javascript:void fold(\"paper\", 0, 8)' title='Show authors'>",
		"+&nbsp;<i>Hidden for blind review</i></a>";
	    if ($this->mode == "assign")
		echo foldsessionpixel("paper8", "foldassigna");
	    echo "</div></div>\n",
                "<div class='pg pgtop fx8'>";
	    $inauthors1 = $inauthors2 = "<a class='q fx8' "
		. "href='javascript:void fold(\"paper\", 1, 8)' "
		. "title='Hide authors'>[blind]</a> ";
	} else {
            echo "<div class='pg pgtop'>";
	    $inauthors1 = $inauthors2 = $afterauthors = "";
	    if ($Conf->subBlindOptional() && $this->prow->blind)
		$inauthors1 = $inauthors2 = "[blind] ";
	}

	// "author" or "authors"?
	$auname = pluralx(count($autable), "Author");

	// actually print
	if ($this->allFolded) {
	    echo $this->papt("authorInformation", $auname,
			     array("fold" => "paper", "foldnum" => 9,
				   "foldsession" => "foldpaperp",
				   "showtitle" => "Show full authors",
				   "hidetitle" => "Show abbreviated authors")),
                "<div class='pavb'><span class='fn9'>",
		$this->authorData($autable, "last", null, $inauthors1),
		" <a class='fn9' href='javascript:void fold(\"paper\", 0, 9)'>[details]</a>",
		"</span><span class='fx9'>",
		$this->authorData($autable, "col", $Me, $inauthors2),
		"</span>";

	} else {
	    echo $this->papt("authorInformation", $auname),
                "<div class='pavb'>", $inauthors1,
		$this->authorData($autable, "col", $Me);
	}

        echo "</div></div>\n\n";

	if (count($contacts) > 0 && !$skip_contacts) {
            echo "<div class='pg fx9", ($viewable ? "" : " fx8"), "'>",
		$this->papt("authorInformation", pluralx(count($contacts), "Contact")),
                "<div class='pavb'>",
		$this->authorData($contacts, "col", $Me),
		"</div></div>\n\n";
	}
    }

    private function paptabTopicsOptions($showAllOptions) {
        global $Conf, $Me;
	$topicdata = topicTable($this->prow, -1);
        $xoptionhtml = array();
	$optionhtml = array();
	$ndocuments = 0;
        $nfolded = 0;

        foreach ($this->prow->options() as $oa) {
            $o = $oa->option;
            if ((@$o->near_submission && $o->is_document())
                || (!$showAllOptions && !$Me->canViewPaperOption($this->prow, $o)))
                continue;

            // create option display value
            $show_on = true;
            $on = htmlspecialchars($o->name);
            $ox = "";
            if ($o->type == "checkbox" && $oa->value)
                $ox = true;
            else if ($o->has_selector()
                     && @($otext = $o->selector[$oa->value]))
                $ox = htmlspecialchars($otext);
            else if ($o->type == "numeric"
                     && $oa->value != "" && $oa->value != "0")
                $ox = htmlspecialchars($oa->value);
            else if ($o->type == "text"
                     && $oa->data != "") {
                $ox = htmlspecialchars($oa->data);
                if (@($o->display_space > 1))
                    $ox = nl2br($ox);
            } else if ($o->type == "attachments") {
                $ox = array();
                foreach ($oa->values as $docid)
                    if (($doc = paperDocumentData($this->prow, $o->id, $docid))) {
                        unset($doc->size);
                        $ox[] = documentDownload($doc, "sdlimg", htmlspecialchars($doc->filename));
                    }
                $ox = join("<br />\n", $ox);
            } else if ($o->is_document() && $oa->value) {
                $show_on = false;
                if ($o->type == "pdf")
                    /* make fake document */
                    $doc = (object) array("paperId" => $this->prow->paperId, "mimetype" => "application/pdf", "documentType" => $o->id);
                else
                    $doc = paperDocumentData($this->prow, $o->id, $oa->value);
                if ($doc)
                    $ox = documentDownload($doc, "sdlimg", $on);
            }
            if ($ox == "")
                continue;

            // display it
            $folded = $showAllOptions && !$Me->canViewPaperOption($this->prow, $o, false);
            if (@$o->highlight || @$o->near_submission) {
                $x = "<div class='pgsm" . ($folded ? " fx8" : "") . "'>"
                    . "<div class='papt'><span class='papfn'>"
                    . ($show_on ? $on : $ox) . "</span>"
                    . "<div class='clear'></div></div>";
                if ($show_on && $ox !== true)
                    $x .= "<div class='pavb'>" . $ox . "</div>";
                $xoptionhtml[] = $x . "</div>\n";
            } else {
                if ($ox === true)
                    $x = $on . "<br />";
                else if ($show_on)
                    $x = $on . ": <span class='optvalue'>" . $ox . "</span><br />";
                else
                    $x = $ox . "<br />";
                if ($folded) {
                    $x = "<span class='fx8'>" . $x . "</span>";
                    ++$nfolded;
                }
                $optionhtml[] = $x . "\n";
                if ($o->is_document() || $o->type == "attachments")
                    ++$ndocuments;
            }
        }

        if (count($xoptionhtml))
            echo "<div class='pg'>", join("", $xoptionhtml), "</div>\n";

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
		$eclass = " fx5";
	    } else {
		$extra = null;
                $eclass = "";
	    }

	    if ($topicdata != "") {
                echo "<div class='pg'>",
                    $this->papt("topics", array("Topics", $tanda), $extra),
                    "<div class='pavb$eclass'>", $topicdata, "</div></div>\n\n";
                $extra = null;
		$tanda = $options_name;
	    }

	    if (count($optionhtml)) {
                echo "<div class='pg", ($extra ? "" : $eclass),
                    ($nfolded == count($optionhtml) ? " fx8" : ""), "'>",
                    $this->papt("options", array($options_name, $tanda), $extra),
                    "<div class='pavb$eclass'>", join("", $optionhtml), "</div></div>\n\n";
	    }
	}
    }

    private function editable_new_contact_author() {
	global $Me, $Conf, $Opt;
        if (!$Me->privChair)
            return;
	echo $this->editable_papt("contactAuthor", "Contact"),
	    "<div class='paphint'>You can add more contacts after you register the paper.</div>",
	    "<div class='papv'>";
        $name = $this->useRequest ? @trim($_REQUEST["newcontact_name"]) : "";
        $name = $name == "Name" ? "" : $name;
        $email = $this->useRequest ? @trim($_REQUEST["newcontact_email"]) : "";
        $email = $email == "Email" ? "" : $email;
        list($name, $email, $class) = $email
            ? array($name, $email, "textlite temptextoff")
            : array("Name", "Email", "textlite temptext");
        echo '<table><tr><td class="lcaption">Add</td>',
            '<td></td><td>',
            Ht::entry('newcontact_name', $name,
                      array("id" => "newcontact_name", "size" => 30,
                            "class" => $class, "onchange" => "hiliter(this)")),
            '&nbsp;&nbsp;',
            Ht::entry('newcontact_email', $email,
                      array("id" => "newcontact_email", "size" => 20,
                            "class" => $class, "onchange" => "hiliter(this)")),
            '</td></tr></table>';
        $Conf->footerScript("mktemptext('newcontact_name','Name');mktemptext('newcontact_email','Email')");
	echo "</div>\n\n";
    }

    private function editable_contact_author($always_unfold) {
	global $Conf, $Me;
        $paperId = $this->prow->paperId;
        list($autable, $contacts) = $this->_analyze_authors();

        $open = @$Error["contactAuthor"]
            || ($this->useRequest && @$_REQUEST["setcontacts"] == 2)
            || $always_unfold;
        echo '<div id="foldcontactauthors" class="',
            ($open ? "foldo" : "foldc"),
            '"><div class="papt childfold fn0" ',
            "onclick=\"\$\$('setcontacts').value=2;return foldup(this,event)\"",
            '><span class="papfn"><a class="q" href="#" ',
            "onclick=\"\$\$('setcontacts').value=2;return foldup(this,event)\"",
            ' title="Edit contacts">', expander(true), 'Contacts</a></span><div class="clear"></div></div>',
            '<div class="papt fx0',
            (@$Error["contactAuthor"] ? " error" : ""),
            '"><span class="papfn">',
            ($always_unfold ? "" : expander(false)),
            'Contacts</span><div class="clear"></div></div>';

        // Non-editable version
        echo '<div class="papv fn0">';
        foreach ($autable as $au)
            if (@$au[4]) {
                echo '<span class="autblentry_long">', Text::user_html($au);
                if ($Me->privChair && $au[4] != $Me->contactId)
                    echo '&nbsp;', viewas_link($au[2], $au);
                echo '</span><br />';
            }
        foreach ($contacts as $au) {
            echo '<span class="autblentry_long">', Text::user_html($au);
            if ($Me->privChair && $au->contactId != $Me->contactId)
                echo '&nbsp;', viewas_link($au);
            echo '</span><br />';
        }
        echo '</div>';

        // Editable version
        echo '<div class="paphint fx0">',
            'Contacts are HotCRP users who can edit paper information and view reviews. Paper authors with HotCRP accounts are always contacts, but you can add additional contacts who aren’t in the author list or create accounts for authors who haven’t yet logged in.',
            '</div>';
        echo '<div class="papv fx0">';
        echo '<table>';
        $title = "Authors";
        foreach ($autable as $au) {
            if (!@$au[4] && (!$au[2] || !validateEmail($au[2])))
                continue;
            $control = "contact_" . html_id_encode($au[2]);
            $checked = $this->useRequest ? !!@$_REQUEST[$control] : @$au[4];
            echo '<tr><td class="lcaption">', $title, '</td><td>';
            if (@$au[4])
                echo Ht::checkbox(null, null, true, array("disabled" => true)),
                    Ht::hidden($control, Text::name_text($au));
            else
                echo Ht::checkbox($control, Text::name_text($au), $checked, array("onclick" => "hiliter(this)"));
            echo '&nbsp;</td><td>', Ht::label(Text::user_html_nolink($au)),
                '</td></tr>';
            $title = "";
        }
        $title = "Non-authors";
        foreach ($contacts as $au) {
            $control = "contact_" . html_id_encode($au->email);
            $checked = $this->useRequest ? @$_REQUEST[$control] : true;
            echo '<tr><td class="lcaption">', $title, '</td>',
                '<td>', Ht::checkbox($control, Text::name_text($au), $checked, array("onclick" => "hiliter(this)")),
                '&nbsp;</td><td>', Ht::label(Text::user_html($au)), '</td>',
                '</tr>';
            $title = "";
        }
        $checked = $this->useRequest ? @$_REQUEST["newcontact"] : true;
        $name = $this->useRequest ? @trim($_REQUEST["newcontact_name"]) : "";
        $name = $name == "Name" ? "" : $name;
        $email = $this->useRequest ? @trim($_REQUEST["newcontact_email"]) : "";
        $email = $email == "Email" ? "" : $email;
        list($name, $email, $class) = $email
            ? array($name, $email, "textlite temptextoff")
            : array("Name", "Email", "textlite temptext");
        echo '<tr><td class="lcaption">Add</td>',
            '<td></td><td>',
            Ht::entry('newcontact_name', $name,
                      array("id" => "newcontact_name", "size" => 30,
                            "class" => $class, "onchange" => "hiliter(this)")),
            '&nbsp;&nbsp;',
            Ht::entry('newcontact_email', $email,
                      array("id" => "newcontact_email", "size" => 20,
                            "class" => $class, "onchange" => "hiliter(this)")),
            '</td></tr>';
        $Conf->footerScript("mktemptext('newcontact_name','Name');mktemptext('newcontact_email','Email')");
        echo '</table>', Ht::hidden("setcontacts", $open ? 2 : 1, array("id" => "setcontacts")), "</div></div>\n\n";
    }

    private function editable_anonymity() {
	global $Conf, $Opt;
	$blind = ($this->useRequest ? isset($_REQUEST['blind']) : (!$this->prow || $this->prow->blind));
	assert(!!$this->editable);
	echo $this->editable_papt("blind", Ht::checkbox_h("blind", 1, $blind)
                                  . "&nbsp;" . Ht::label("Anonymous submission")),
	    "<div class='paphint'>", htmlspecialchars($Opt["shortName"]), " allows either anonymous or named submission.  Check this box to submit the paper anonymously (reviewers won&rsquo;t be shown the author list).  Make sure you also remove your name from the paper itself!</div>\n",
	    "<div class='papv'></div>\n\n";
    }

    private function editable_collaborators() {
	global $Conf;
	if (!$Conf->setting("sub_collab"))
	    return;
	$sub_pcconf = $Conf->setting("sub_pcconf");
	assert(!!$this->editable);

	echo $this->editable_papt("collaborators", ($sub_pcconf ? "Other conflicts" : "Potential conflicts")),
	    "<div class='paphint'>";
	if ($Conf->setting("sub_pcconf"))
	    echo "List <em>other</em> people and institutions with which
	the authors have conflicts of interest.  This will help us avoid
	conflicts when assigning external reviews.  No need to list people
	at the authors’ own institutions.";
	else
	    echo "List people and institutions with which the authors have
	conflicts of interest.  ", Message::html("conflictdef"), "
	Be sure to include conflicted <a href='", hoturl("users", "t=pc"), "'>PC members</a>.
	We use this information when assigning PC and external reviews.";
	echo "  List one conflict per line.  For example: &ldquo;<tt>Jelena Markovic (EPFL)</tt>&rdquo; or, for a whole institution, &ldquo;<tt>EPFL</tt>&rdquo;.</div>",
	    "<div class='papv'>",
	    $this->entryData("collaborators"),
	    "</div>\n\n";
    }

    function _papstripBegin($foldid = null, $folded = null, $extra = null) {
	$x = "<div ";
	if ($foldid)
	    $x .= " id='fold$foldid'";
	$x .= " class='psc";
	if (!$this->npapstrip)
	    $x .= " psc1";
	if ($foldid)
	    $x .= " fold" . ($folded ? "c" : "o");
	if (is_string($extra))
	    $x .= " " . $extra;
        else if (is_array($extra))
            foreach ($extra as $k => $v)
                $x .= "' $k='$v";
	++$this->npapstrip;
	return $x . "'>";
    }

    private function papstripCollaborators() {
	global $Conf;
	if (!$Conf->setting("sub_collab") || !$this->prow->collaborators
	    || strcasecmp(trim($this->prow->collaborators), "None") == 0)
	    return;
	$name = $Conf->setting("sub_pcconf") ? "Other conflicts" : "Potential conflicts";
	$fold = defval($_SESSION, "foldpscollab", 1) ? 1 : 0;

	$data = $this->entryData("collaborators", "col");
	if ($this->entryMatches || !$this->allFolded)
	    $fold = 0;

	echo $this->_papstripBegin("pscollab", $fold),
	    $this->papt("collaborators", $name,
			array("type" => "ps", "fold" => "pscollab",
			      "foldsession" => "foldpscollab",
			      "folded" => $fold)),
	    "<div class='psv'><div class='fx'>", $data,
	    "</div></div></div>\n\n";
    }

    private function editable_topics() {
	global $Conf;
	assert(!!$this->editable);
	$topicMode = (int) $this->useRequest;
	if (($topicTable = topicTable($this->prow, $topicMode))) {
	    echo $this->editable_papt("topics", "Topics"),
		"<div class='paphint'>Select any topics that apply to your paper.</div>",
		"<div class='papv'>", $topicTable, "</div>\n\n";
	}
    }

    private function editable_attachments($o) {
        echo $this->editable_papt($o->id, htmlspecialchars($o->name)
                                  . " <span class='papfnh'>(max " . ini_get("upload_max_filesize") . "B per file)</span>");
        if ($o->description)
            echo "<div class='paphint'>", $o->description, "</div>";
        echo "<div class='papv'>";
        if (($prow = $this->prow) && ($optx = $prow->option($o->id))) {
            $docclass = new HotCRPDocument($o->id, $o);
            foreach ($optx->values as $docid)
                if (($doc = paperDocumentData($prow, $o->id, $docid))) {
                    $oname = "opt" . $o->id . "_" . $docid;
                    echo "<div id='removable_$oname' class='foldo'><table id='current_$oname'><tr>",
                        "<td class='nowrap'>", documentDownload($doc, "dlimg", htmlspecialchars($doc->filename)), "</td>",
                        "<td class='fx'><span class='sep'></span></td>",
                        "<td class='fx'><a id='remover_$oname' href='#remover_$oname' onclick='return doremovedocument(this)'>Delete</a></td>";
                    if (($stamps = self::pdfStamps($doc)))
                        echo "<td class='fx'><span class='sep'></span></td><td class='fx'>$stamps</td>";
                    echo "</tr></table></div>\n";
                }
        }
        echo "<div id='opt", $o->id, "_new'></div>",
            "<button type='button' onclick=\"addattachment($o->id)\">Attach file</button>",
            "</div>";
    }

    private function editable_options($display_types) {
	global $Conf, $Me;
        $prow = $this->prow;
	if (!($opt = PaperOption::option_list()))
	    return;
	assert(!!$this->editable);
	foreach ($opt as $o) {
	    if (!@($display_types[$o->display_type()])
		|| (@$o->final && !$this->canUploadFinal)
                || ($prow && !$Me->canViewPaperOption($prow, $o, true)))
		continue;

	    $optid = "opt$o->id";
            $optx = ($prow ? $prow->option($o->id) : null);
            if ($o->type == "attachments") {
                $this->editable_attachments($o);
                continue;
            }

	    if ($this->useRequest)
		$myval = defval($_REQUEST, $optid);
            else if (!$optx)
                $myval = null;
	    else if ($o->type == "text")
                $myval = $optx->data;
	    else
                $myval = $optx->value;

	    if ($o->type == "checkbox") {
		echo $this->editable_papt($optid, Ht::checkbox_h($optid, 1, $myval) . "&nbsp;" . Ht::label(htmlspecialchars($o->name)));
		if (@$o->description)
		    echo "<div class='paphint'>", $o->description, "</div>";
		echo "<div class='papv'></div>\n\n";
	    } else if (!$o->is_document()) {
		echo $this->editable_papt($optid, htmlspecialchars($o->name));
		if (@$o->description)
		    echo "<div class='paphint'>", $o->description, "</div>";
		if ($o->type == "selector")
		    echo "<div class='papv'>", Ht::select("opt$o->id", $o->selector, $myval, array("onchange" => "hiliter(this)")), "</div>\n\n";
		else if ($o->type == "radio") {
		    echo "<div class='papv'>";
		    $myval = isset($o->selector[$myval]) ? $myval : 0;
		    foreach ($o->selector as $val => $text) {
			echo Ht::radio("opt$o->id", $val, $val == $myval, array("onchange" => "hiliter(this)"));
			echo "&nbsp;", Ht::label(htmlspecialchars($text)), "<br />\n";
		    }
		    echo "</div>\n\n";
		} else if ($o->type == "numeric")
		    echo "<div class='papv'><input type='text' class='textlite' name='$optid' value=\"", htmlspecialchars($myval), "\" size='8' onchange='hiliter(this)' /></div>\n\n";
		else if ($o->type == "text" && @$o->display_space <= 1)
		    echo "<div class='papv'><input type='text' class='textlite papertext' name='$optid' value=\"", htmlspecialchars($myval), "\" size='40' onchange='hiliter(this)' /></div>\n\n";
                else if ($o->type == "text")
                    echo "<div class='papv'><textarea class='textlite papertext' name='$optid' rows='5' cols='60' onchange='hiliter(this)'>", htmlspecialchars($myval), "</textarea></div>\n\n";
	    } else
		$this->editable_document($o, $optx ? $optx->value : 0, 0);
	}
    }

    private function editable_pc_conflicts() {
	global $Conf, $Me;

	assert(!!$this->editable);
	if (!$Conf->setting("sub_pcconf"))
	    return;
	$pcm = pcMembers();
	if (!count($pcm))
	    return;

	$selectors = $Conf->setting("sub_pcconfsel");

	$conflict = array();
	if ($this->useRequest) {
	    foreach ($pcm as $id => $row)
		if (isset($_REQUEST["pcc$id"])
		    && ($ct = cvtint($_REQUEST["pcc$id"])) > 0)
		    $conflict[$id] = Conflict::force_author_mark($ct, $this->admin);
	}
	if ($this->prow) {
	    $result = $Conf->qe("select contactId, conflictType from PaperConflict where paperId=" . $this->prow->paperId, "while finding conflicted PC members");
	    while (($row = edb_row($result))) {
		$ct = new Conflict($row[1]);
		if (!$this->useRequest || (!$ct->is_author_mark() && !$this->admin))
		    $conflict[$row[0]] = $ct;
	    }
	}

	$pcconfs = array();
	$nonct = Conflict::make_nonconflict();
	if ($selectors) {
	    $ctypes = Conflict::$type_descriptions;
	    $extra = array("onchange" => "hiliter(this)",
			   "class" => "pctbconfselector");
	    if ($this->admin) {
		$ctypes["xsep"] = null;
		$ctypes[CONFLICT_CHAIRMARK] = "Confirmed conflict";
		$extra["optionstyles"] = array(CONFLICT_CHAIRMARK => "font-weight:bold");
	    }
	    foreach ($pcm as $id => $p) {
		$c = "<tr><td class='pctbname0 pctbl'>". Text::name_html($p) . "</td><td class='pctbconfsel'>";
		$ct = defval($conflict, $id, $nonct);
		if ($ct->is_author())
		    $c .= "<strong>Author</strong>";
		else if ($ct->is_conflict() && !$ct->is_author_mark()) {
                    if (!$this->admin)
                        $c .= "<strong>Conflict</strong>";
                    else
                        $c .= Ht::select("pcc$id", $ctypes, CONFLICT_CHAIRMARK, $extra);
		} else
		    $c .= Ht::select("pcc$id", $ctypes, $ct->value, $extra);
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
		$disabled = $checked
                    && ($ct->is_author()
                        || (!$ct->is_author_mark() && !$this->admin));
		$value = $checked ? $ct->value : CONFLICT_AUTHORMARK;
		$cbox = Ht::checkbox_h("pcc$id", $value, $checked, array("disabled" => $disabled));
		$aff = ($p->affiliation === "" ? "" : "<div class='pcconfaff'>" . htmlspecialchars($p->affiliation) . "</div>");
		$label = Ht::label(Text::name_html($p) . $aff);
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

	echo $this->editable_papt("pcconf", "PC conflicts"),
	    "<div class='paphint'>Select the PC members who have conflicts of interest with this paper.  ", Message::html("conflictdef"), "</div>\n",
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

    private function papstripPCConflicts() {
	global $Conf;
	assert(!$this->editable);
	$pcm = pcMembers();
	if (!count($pcm) || !$this->prow)
	    return;
	$conflict = array();
	$result = $Conf->qe("select contactId, conflictType from PaperConflict where paperId=" . $this->prow->paperId, "while finding conflicted PC members");
	while (($row = edb_row($result)))
	    $conflict[$row[0]] = $row[1];
	$pcconfs = array();
	foreach ($pcm as $id => $p)
	    if (defval($conflict, $id))
		$pcconfs[] = Text::name_html($p);
	if ($this->prow->pcPaper)
	    $pcconfs[] = "<b>PC paper</b>";
	if (!count($pcconfs))
	    $pcconfs[] = "None";

	echo $this->_papstripBegin(),
	    $this->papt("pcconflict", "PC conflicts", array("type" => "ps")),
	    "<div class='psv psconf'><p class='odname'>",
	    join("</p><p class='odname'>", $pcconfs),
	    "</p></div></div>\n";
    }

    private function _papstripLeadShepherd($type, $name, $showedit, $wholefold) {
	global $Conf, $Me, $Opt, $Error;
	$editable = ($type == "manager" ? $Me->privChair : $Me->canAdminister($this->prow));

	$field = $type . "ContactId";
	if ($this->prow->$field == 0 && !$editable)
	    return;
	$value = $this->prow->$field;
	$pc = pcMembers();

        echo "<div id='fold${type}' class='foldc fold2", ($wholefold ? "c" : "o"), "'>",
            $this->_papstripBegin(null, true, "fx2"),
            $this->papt($type, $name, array("type" => "ps", "fold" => $editable ? $type : false, "folded" => true)),
            "<div class='psv'><p class='fn odname'>";
        if ($value)
            echo isset($pc[$value]) ? Text::name_html($pc[$value]) : "Unknown!";
        else
            echo "None";
        echo "</p>";

        if ($editable) {
	    echo "<form id='${type}form' class='fx fold7o' method='post' action='", hoturl_post("review", "p=" . $this->prow->paperId), "' enctype='multipart/form-data' accept-charset='UTF-8' onsubmit='return dosubmitstripselector(\"${type}\")'>",
		"<div class='inform'>",
		"<input type='hidden' name='set$type' value='1' />";
	    $Conf->footerScript("Miniajax.onload(\"${type}form\")");

	    $sel = array("0" => "None");
            $textarg = array("lastFirst" => @$Opt["sortByLastName"]);
	    foreach ($pc as $row)
		$sel[htmlspecialchars($row->email)] =
                Text::name_html($row, $textarg);

	    echo Ht::select($type, $sel,
                             ($value && isset($pc[$value]) ? htmlspecialchars($pc[$value]->email) : "0"),
                             array("onchange" => "dosubmitstripselector('${type}')",
                                   "id" => "fold${type}_d")),
		" <input class='fx7' type='submit' value='Save' />",
		" <span id='${type}formresult'></span>",
		"</div></form>";
        }

        echo "</div></div></div>\n";
    }

    private function papstripLead($showedit) {
	$this->_papstripLeadShepherd("lead", "Discussion lead", $showedit || defval($_REQUEST, "atab") == "lead", false);
    }

    private function papstripShepherd($showedit, $fold) {
	$this->_papstripLeadShepherd("shepherd", "Shepherd", $showedit || defval($_REQUEST, "atab") == "shepherd", $fold);
    }

    private function papstripManager($showedit) {
	$this->_papstripLeadShepherd("manager", "Paper administrator", $showedit || defval($_REQUEST, "atab") == "manager", false);
    }

    private function papstripTags($site = null) {
	global $Conf, $Me, $Error;
	if ($site || defval($this->prow, "paperTags", "") !== "") {
	    // Note that tags MUST NOT contain HTML special characters.
	    $tagger = new Tagger;
	    $tx = $tagger->unparse_link_viewable($this->prow->paperTags, false, !$this->prow->has_conflict($Me));
	    $editable = $site && $Me->canSetTags($this->prow);
	    $unfolded = $editable && (isset($Error["tags"]) || defval($_REQUEST, "atab") == "tags");

	    echo $this->_papstripBegin("tags", !$unfolded,
                                       array("onunfold" => "Miniajax.submit(\"tagreportform\")"));
	    if (($color = $tagger->color_classes($this->prow->paperTags)))
		echo "<div class='pscopen ${color}'>";

	    if ($editable)
		echo "<form method='post' action='", hoturl_post($site, "p=" . $this->prow->paperId), "' enctype='multipart/form-data' accept-charset='UTF-8'><div class='inform'>";

	    echo $this->papt("tags", "Tags", array("type" => "ps", "editfolder" => ($editable ? "tags" : 0))),
		"<div class='psv' style='position:relative'>";
	    if ($editable) {
		// tag report form
		$Conf->footerHtml("<form id='tagreportform' class='fold7o' method='post' action='" . hoturl_post("paper", "p=" . $this->prow->paperId . "&amp;tagreport=1") . "' enctype='multipart/form-data' accept-charset='UTF-8' onsubmit='return Miniajax.submit(\"tagreportform\")'></form>");

		echo "<div class='fn'>", ($tx == "" ? "None" : $tx),
		    "</div><div id='papstriptagsedit' class='fx'><div id='tagreportformresult'>";
		if ($unfolded)
		    echo PaperActions::tagReport($this->prow, true);
		echo "</div>";
		if (isset($Error["tags"]))
		    echo "<div class='xmerror'>", $Error["tags"], "</div>";
		echo "<div style='position:relative'>",
                    "<div id='taghelp_p' class='taghelp_p'></div>",
                    "<textarea id='foldtags_d' cols='20' rows='4' name='tags' onkeypress='return crpSubmitKeyFilter(this, event)'>",
                    $tagger->unparse($tagger->editable($this->prow->paperTags)),
                    "</textarea></div>",
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

    function papstripOutcomeSelector() {
	global $Conf, $Error;
	echo $this->_papstripBegin("decision", defval($_REQUEST, "atab") != "decision"),
	    $this->papt("decision", "Decision", array("type" => "ps", "fold" => "decision")),
	    "<div class='psv'><form id='decisionform' class='fx fold7o' method='post' action='", hoturl_post("review", "p=" . $this->prow->paperId), "' enctype='multipart/form-data' accept-charset='UTF-8' onsubmit='return dosubmitstripselector(\"decision\")'>",
	    "<div class='inform'>",
	    "<input type='hidden' name='setdecision' value='1' />";
	if (isset($_REQUEST["forceShow"]))
	    echo "<input type='hidden' name='forceShow' value='", ($_REQUEST['forceShow'] ? 1 : 0), "' />";
        $outcomes = $Conf->outcome_map();
	echo decisionSelector($this->prow->outcome, null, " onchange='dosubmitstripselector(\"decision\")' id='folddecision_d'"),
	    " <input class='fx7' type='submit' value='Save' />",
	    " <span id='decisionformresult'></span>",
	    "</div></form><p class='fn odname'>",
	    $outcomes[$this->prow->outcome],
	    "</p></div></div>\n";
	$Conf->footerScript("Miniajax.onload(\"decisionform\")");
    }

    function papstripReviewPreference() {
	global $Conf, $CurrentList;
	echo $this->_papstripBegin(),
	    $this->papt("revpref", "Review preference", array("type" => "ps")),
	    "<div class='psv'><form id='revprefform' class='fold7o' method='post' action='", hoturl_post("review", "p=" . $this->prow->paperId), "' enctype='multipart/form-data' accept-charset='UTF-8' onsubmit='return Miniajax.submit(\"revprefform\")'>",
	    "<div class='aahc inform'>",
	    "<input type='hidden' name='setrevpref' value='1' />";
	if (isset($_REQUEST["forceShow"]))
	    echo "<input type='hidden' name='forceShow' value='", ($_REQUEST['forceShow'] ? 1 : 0), "' />";
	$rp = (isset($this->prow->reviewerPreference) ? htmlspecialchars($this->prow->reviewerPreference) : "0");
	$rp = ($rp == "0" ? "" : $rp);
	echo "<input id='revprefform_d' class='textlite' type='text' size='4' name='revpref' value=\"$rp\" onchange='Miniajax.submit(\"revprefform\")' tabindex='1' />",
	    " <input class='fx7' type='submit' value='Save' />",
	    " <span id='revprefformresult'></span>",
	    "</div></form></div></div>\n";
	$Conf->footerScript("Miniajax.onload(\"revprefform\");shortcut(\"revprefform_d\").add()");
	if ($CurrentList && ($l = SessionList::lookup($CurrentList))
            && @$l->revprefs && ($this->mode == "p" || $this->mode == "r"))
	    $Conf->footerScript("crpfocus('revprefform',null,3)");
    }

    function papstripRank() {
	global $Conf, $Me;
	if (!($tag = $Conf->setting_data("tag_rank")))
	    return;

	// load rank
	$tagsearch = " " . $Me->contactId . "~" . $tag . "#";
	if (($pos = strpos($this->prow->paperTags, $tagsearch)) !== false)
            $rp = (int) substr($this->prow->paperTags, $pos + strlen($tagsearch));
        else
	    $rp = "";

	// rank context form
	$Conf->footerHtml("<form id='rankctxform' class='fold7o' method='post' action='" . hoturl_post("paper", "p=" . $this->prow->paperId) . "' enctype='multipart/form-data' accept-charset='UTF-8' onsubmit='return Miniajax.submit(\"rankctxform\")'><div class='aahc'><input type='hidden' name='rankctx' value='1' /></div></form>");

	echo $this->_papstripBegin("rank", true, "fold2c"),
	    $this->papt("rank", "Your rank", array("type" => "ps", "editfolder" => "rank")),
	    "<div class='psv'><form id='rankform' class='fx fold7o' method='post' action='" . hoturl_post("review", "p=" . $this->prow->paperId) . "' enctype='multipart/form-data' accept-charset='UTF-8' onsubmit='return Miniajax.submit(\"rankform\")'>",
	    "<div class='inform'>",
	    "<input type='hidden' name='setrank' value='1' />";
	if (isset($_REQUEST["forceShow"]))
	    echo "<input type='hidden' name='forceShow' value='", ($_REQUEST['forceShow'] ? 1 : 0), "' />";
	echo "<input id='foldrank_d' class='textlite' type='text' size='4' name='rank' value=\"$rp\" onchange='Miniajax.submit(\"rankform\")' tabindex='1' />",
	    " <input class='fx7' type='submit' value='Save' />",
	    " <span id='rankformresult'></span>",
	    " <div class='hint'><strong>Tip:</strong> <a href='", hoturl("search", "q=" . urlencode("editsort:#~$tag")), "'>Search “editsort:#~${tag}”</a> to drag and drop your ranking, or <a href='", hoturl("offline"), "'>use offline reviewing</a> to rank many papers at once.</div>",
	    "</div></form>",
	    "<div class='fn'>",
	    ($rp === "" ? "None" : $rp);
	if ($rp != "")
	    echo " <span class='fn2'>&nbsp; <a href='javascript:void 0' onclick='fold(\"rank\", 0, 2);void Miniajax.submit(\"rankctxform\")'>(context)</a></span>";
        echo " &nbsp; <a href='", hoturl("search", "q=" . urlencode("editsort:#~$tag")), "'>(all)</a>";
	echo "</div>",
	    "<div id='rankctxformresult' class='fx2'>Loading...</div>",
	    "</div></div>\n";
	$Conf->footerScript("Miniajax.onload(\"rankform\")");
    }

    private function papstripWatch() {
	global $Conf, $Me;
        $prow = $this->prow;
        $conflictType = $prow->conflict_type($Me);
	if (!($this->watchCheckbox
	      && $prow->timeSubmitted > 0
	      && ($conflictType >= CONFLICT_AUTHOR
		  || $conflictType <= 0
		  || $Me->is_admin_force())
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
	    "<form id='watchform' class='fold7o' action=\"", hoturl_post("comment", "p=$prow->paperId"), "\" method='post' enctype='multipart/form-data' accept-charset='UTF-8' onsubmit='return Miniajax.submit(\"watchform\")'>",
	    "<div class='inform'>",
	    "<input type='hidden' name='setwatch' value='1' />";

	if ($row[4] && ($row[4] & ($this->watchCheckbox >> 1)))
	    $watchValue = $row[4];
	else if ($row[1] || $row[2] || $row[3] >= CONFLICT_AUTHOR)
	    $watchValue = $Me->defaultWatch;
	else
	    $watchValue = 0;

	echo $this->papt("watch",
			 Ht::checkbox("watch", $this->watchCheckbox,
				       $watchValue & $this->watchCheckbox,
				       array("onchange" => "Miniajax.submit('watchform')",
					     "style" => "padding-left:0;margin-left:0"))
			 . "&nbsp;" . Ht::label("Email notification"),
			 array("type" => "ps")),
	    "<div class='pshint'>Select to receive email on updates to reviews and comments. <span id='watchformresult'></span>",
	    "<input class='fx7' type='submit' value='Save' />",
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

	$override = ($this->admin ? "  As an administrator, you can override this deadline using the “Override deadlines” checkbox." : "");
	if (!$prow) {
	    $timeStart = $Conf->timeStartPaper();
	    $startDeadline = $this->deadlineSettingIs("sub_reg");
	    if (!$timeStart) {
		if ($Conf->setting("sub_open") <= 0)
		    $msg = "You can’t register new papers because the conference site has not been opened for submissions.$override";
		else
		    $msg = "You can’t register new papers since the <a href='" . hoturl("deadlines") . "'>deadline</a> has passed.$startDeadline$override";
		if (!$this->admin)
		    errorMsgExit($msg);
		$m .= "<div class='xinfo'>" . $msg . "</div>";
	    } else {
		$m .= "<div class='xinfo'>" . "Enter information about your paper.  ";
		if ($startDeadline && !$Conf->setting("sub_freeze"))
		    $m .= "You can make changes until the deadline, but thereafter ";
		else
		    $m .= "You don’t have to upload the paper itself right away, but ";
		$m .= "incomplete submissions will not be considered.$startDeadline" . "</div>";
	    }
	} else if ($prow->has_author($Me)
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
	} else if ($prow->has_author($Me) && $prow->outcome > 0
		   && $Conf->timeSubmitFinalPaper()) {
	    $updateDeadline = $this->deadlineSettingIs("final_soft");
	    $m .= "<div class='xinfo'>" . "Congratulations!  This paper was accepted.  Submit a final version for the paper here.$updateDeadline  You may also edit paper contacts, allowing others to view reviews and make changes." . "</div>";
	} else if ($prow->has_author($Me)) {
	    $override2 = ($this->admin ? " As an administrator, you can update the paper anyway by selecting “Override deadlines.”" : "");
	    if ($this->mode == "pe") {
		$m .= "<div class='xinfo'>This paper is under review and can’t be changed, but you can change its contacts";
                if ($Me->canWithdrawPaper($prow))
                    $m .= " or withdraw it from consideration";
                $m .= ".$override2</div>";
            }
	} else
	    $m .= "<div class='xinfo'>" . "You aren’t a contact for this paper, but as an administrator you can still make changes." . "</div>";

	return $m;
    }

    function _collectActionButtons() {
        global $Conf, $Me;
        $prow = $this->prow;

        // Absent paper can only be saved
        if (!$prow)
            return array("<input class='bb' type='submit' name='update' value='Save paper' />");

        // Withdrawn papers can be revived
        if ($prow->timeWithdrawn > 0) {
            $revivable = $Conf->timeFinalizePaper($prow);
            if ($revivable || $this->admin) {
                $b = "<input type='submit' name='revive' value='Revive paper' />";
                if (!$revivable)
                    $b = array($b, "(admin only)");
            } else
                $b = "The <a href='" . hoturl("deadlines") . "'>deadline</a> for reviving withdrawn papers has passed.";
            return array($b);
        }

        $buttons = array();

        if ($prow->outcome > 0 && $Conf->collectFinalPapers()
            && ($Conf->timeSubmitFinalPaper() || $this->admin)
            && $this->mode == "pe")
            $buttons[] = array(Ht::submit("submitfinal", "Save changes", array("class" => "bb")), "");
        else if ($Conf->timeUpdatePaper($prow) && $this->mode == "pe")
            $buttons[] = array(Ht::submit("update", "Save changes", array("class" => "bb")), "");
        else if ($this->admin && $this->mode == "pe") {
            $class = ($prow->outcome > 0 && $Conf->collectFinalPapers() ? "b" : "bb");
            $buttons[] = array(Ht::submit("update", "Save changes", array("class" => $class)), "(admin only)");
        } else if ($this->mode == "pe" && $prow->timeSubmitted > 0)
            $buttons[] = array(Ht::submit("updatecontacts", "Save contacts", array("class" => "b")), "");

        // withdraw button
        if (!$Me->canWithdrawPaper($prow, $whyNot, true))
            $b = null;
        else if ($prow->timeSubmitted <= 0)
            $b = Ht::submit("withdraw", "Withdraw paper");
        else {
            $b = Ht::button("Withdraw paper", array("onclick" => "popup(this,'w',0,true)"));
            $admins = "";
            if ((!$this->admin || $prow->has_author($Me))
                && !$Conf->timeFinalizePaper($prow))
                $admins = "Only administrators can undo this step.";
            $override = "";
            if (!$Me->canWithdrawPaper($prow))
                $override = "<div>" . Ht::checkbox("override", array("id" => "dialog_override")) . "&nbsp;"
                    . Ht::label("Override deadlines") . "</div>";
            $Conf->footerHtml("<div id='popup_w' class='popupc'>
  <p>Are you sure you want to withdraw this paper from consideration and/or
  publication?  $admins</p>
  <form method='post' action=\"" . hoturl_post("paper", "p=" . $prow->paperId . "&amp;m=pe") . "\" enctype='multipart/form-data' accept-charset='UTF-8'><div class='inform'>
    <textarea id='withdrawreason' class='temptext' name='reason' rows='3' cols='40' style='width:99%'>Optional explanation</textarea>$override
    <div class='popup_actions' style='margin-top:10px'>
      <input class='popup_populate' type='hidden' name='doemail' value='1' />
      <input class='popup_populate' type='hidden' name='emailNote' value='' />
      <button type='button' onclick=\"popup(null, 'w', 1)\">Cancel</button>
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

        return $buttons;
    }

    function echoActions() {
	global $Conf, $Me;
	$prow = $this->prow;

	$buttons = $this->_collectActionButtons();

	if ($this->admin && $prow) {
	    $buttons[] = array("<button type='button' onclick=\"popup(this,'d',0,true)\">Delete paper</button>", "(admin only)");
	    $Conf->footerHtml("<div id='popup_d' class='popupc'>
  <p>Be careful: This will permanently delete all information about this
  paper from the database and <strong>cannot be undone</strong>.</p>
  <form method='post' action=\"" . hoturl_post("paper", "p=" . $prow->paperId . "&amp;m=pe") . "\" enctype='multipart/form-data' accept-charset='UTF-8'>
    <div class='popup_actions'>
      <input class='popup_populate' type='hidden' name='doemail' value='1' />
      <input class='popup_populate' type='hidden' name='emailNote' value='' />
      <button type='button' onclick=\"popup(null, 'd', 1)\">Cancel</button>
      &nbsp;<input class='bb' type='submit' name='delete' value='Delete paper' />
    </div>
  </form></div>");
	}

	echo Ht::actions($buttons);
	if ($this->admin) {
	    $v = defval($_REQUEST, "emailNote", "");
	    echo "  <div class='g'></div>\n  <table>\n",
		"    <tr><td>",
		Ht::checkbox("doemail", 1, true), "&nbsp;",
		Ht::label("Email authors, including:"), "&nbsp; ",
		"<input id='emailNote' type='text' class='textlite temptext' name='emailNote' size='30' value=\"",
		htmlspecialchars($v == "" ? "Optional explanation" : $v),
		"\" /></td></tr>\n    <tr><td>",
		Ht::checkbox("override", array("onclick" => "copy_override_status(this)")), "&nbsp;";
            if ($Conf->timeUpdatePaper($prow))
                echo "<span class='dim'>", Ht::label("Override deadlines"), "</span>";
            else
                echo "<strong>", Ht::label("Override deadlines"), "</strong>";
            echo "</td></tr>\n  </table>\n";
	    $Conf->footerScript("mktemptext('emailNote','Optional explanation')");
	}
    }


    // Functions for overall paper table viewing

    function _papstrip() {
	global $Conf, $Me;
	$prow = $this->prow;
        if (($prow->managerContactId || ($Me->privChair && $this->mode == "assign"))
            && $Me->canViewPaperManager($prow))
            $this->papstripManager($Me->privChair);
	if ($Me->canViewTags($prow))
	    $this->papstripTags("review");
	if ($Me->canSetRank($prow))
	    $this->papstripRank();
	$this->papstripWatch();
        if (($this->admin
             || ($Me->isPC && $Me->canViewAuthors($prow))
             || $Me->actAuthorView($prow))
            && !$this->editable)
            $this->papstripPCConflicts();
        if ($Me->canViewAuthors($prow, true) && !$this->editable)
            $this->papstripCollaborators();

	$foldShepherd = $Me->canSetOutcome($prow) && $prow->outcome <= 0
	    && $prow->shepherdContactId == 0 && $this->mode != "assign";
	if ($Me->canSetOutcome($prow))
	    $this->papstripOutcomeSelector();
	if ($Me->actPC($prow))
	    $this->papstripLead($this->mode == "assign");
	if ($Me->actPC($prow) || $Me->canViewDecision($prow))
	    $this->papstripShepherd($this->mode == "assign", $foldShepherd);

	if ($Me->actPC($prow) && $Conf->timePCReviewPreferences())
	    $this->papstripReviewPreference();
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
	global $Conf, $Me;
	$prow = $this->prow;

	// what actions are supported?
	$canEdit = $Me->canEditPaper($prow);
	$canReview = $Me->canReview($prow, null);
	$canAssign = $Me->canAdminister($prow);
	$canHome = ($canEdit || $canAssign || $this->mode == "contact");

	echo "<div class='pban'>";

	// paper tabs
	if ($canEdit || $canReview || $canAssign || $canHome) {
	    echo "<div class='psmodec'><div class='psmode'>";

	    // home link
	    $highlight = ($this->mode != "assign" && $this->mode != "pe"
			  && $this->mode != "contact" && $this->mode != "re");
	    $a = ($this->mode == "pe" || $this->mode == "re" ? "&amp;m=p" : "");
	    $this->_paptabTabLink("Main", hoturl("paper", "p=$prow->paperId$a"), "view18.png", $highlight);

	    if ($canEdit)
		$this->_paptabTabLink("Edit", hoturl("paper", "p=$prow->paperId&amp;m=pe"), "edit18.png", $this->mode == "pe");

	    if ($canReview)
		$this->_paptabTabLink("Review", hoturl("review", "p=$prow->paperId&amp;m=re"), "review18.png", $this->mode == "re" && (!$this->editrrow || $this->editrrow->contactId == $Me->contactId));

	    if ($canAssign)
		$this->_paptabTabLink("Assign", hoturl("assign", "p=$prow->paperId"), "assign18.png", $this->mode == "assign");

	    echo "<div class='clear'></div></div></div>\n";
	}

	// paper number
	$pa = "<a href='" . hoturl("paper", "p=$prow->paperId") . "' class='q'>";
	echo "<table class='pban'><tr>
    <td class='pboxi'><div class='papnum'>",
	    "<h2>", $pa, "#", $prow->paperId, "</a></h2></div></td>\n";

	// paper title
	echo "    <td class='pboxt'><h2>", $pa;
	$this->echoTitle();
	echo "</a></h2></td>
    <td class='pboxj'></td>
</tr></table>\n";

	echo "</div>\n";
    }

    function paptabBegin() {
	global $Conf, $Me;
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
	echo "    <td class='${pboxclass}r'><table class='papcpap'>
	<tr><td class='papcl'>",
	    $Conf->cacheableImage("_.gif", "", null, "_"),
	    "</td><td class='papct'><div class='inpapct'>";

	$form = "<form method='post' action=\""
	    . hoturl_post("paper", "p="
			  . ($prow ? $prow->paperId : "new")
			  . "&amp;m=pe") . "\"";
	if ($prow && $prow->paperStorageId > 1 && $prow->timeSubmitted > 0
	    && !$Conf->setting('sub_freeze'))
	    $form .= " onsubmit='return docheckpaperstillready()'";
	$form .= " enctype='multipart/form-data' accept-charset='UTF-8'>";

	$this->echoDivEnter();
	if ($this->editable) {
	    $spacer = "<div class='g'></div>\n\n";
	    echo $form, "<div class='aahc'>";
	    $this->canUploadFinal = $prow && $prow->outcome > 0
		&& ($Me->canSubmitFinalPaper($prow, $whyNot, true)
		    || defval($whyNot, "deadline") == "final_done");

	    if (($m = $this->editMessage()))
		echo $m, $spacer;

	    $this->editable_title();
	    $this->editable_submission(!$prow || $prow->size == 0 ? PaperTable::ENABLESUBMIT : 0);
	    $this->editable_options(array("near_submission" => true));

	    // Authorship
	    echo $spacer;
	    $this->editable_authors();
	    if (!$prow)
		$this->editable_new_contact_author();
	    else
		$this->editable_contact_author(false);
	    if ($Conf->subBlindOptional() && $this->editable !== "f")
		$this->editable_anonymity();

	    echo $spacer;
	    $this->editable_abstract();

	    // Topics and options
	    echo $spacer;
	    $this->editable_topics();
	    $this->editable_options(array("normal" => true, "highlight" => true));

	    // Potential conflicts
	    if ($this->editable !== "f" || $this->admin) {
		$this->editable_pc_conflicts();
		$this->editable_collaborators();
	    }

	    // Submit button
	    echo $spacer;
	    $this->echoActions();

	    echo "</div></form>";

	} else {
	    if ($this->mode == "pe" && ($m = $this->editMessage()))
		echo $m, "<div class='g'></div>\n";
	    $this->paptabDownload();
	    echo "<table class='paptab'><tr><td class='paple'><div class='paple'>";
	    $this->paptabAbstract();
	    echo "</div></td><td class='papre'><div class='papre'>";
	    $this->paptabAuthors(!$this->editable && $this->mode == "pe"
                                 && $prow->timeSubmitted > 0);
	    $this->paptabTopicsOptions($Me->canAdminister($prow));
	    echo "</div></td></tr></table>";
	}
	$this->echoDivExit();

	echo "</div></td><td class='papcr'>",
	    $Conf->cacheableImage("_.gif", "", null, "_"),
	    "</td></tr>\n";

	if (!$this->editable && $this->mode == "pe") {
	    $this->_paptabSepBegin(true);
	    echo $form;
            if ($prow->timeSubmitted > 0)
                $this->editable_contact_author(true);
	    $this->echoActions();
	    echo "</form>";
	    $this->_paptabSepEnd();
	}

        $Conf->footerScript("shortcut().add()");
    }

    function _paptabSepBegin($nosep = false) {
        if (!$nosep)
            echo "	<tr><td colspan='3' class='papsep'></td></tr>\n";
        echo "	<tr><td></td><td class='papcc'>";
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
	require_once("reviewtable.php");
	$t = "";
	if ($rtable)
	    $t .= reviewTable($this->prow, $this->rrows, $this->mycrows,
			      $editrrow, $this->mode);
	$t .= reviewLinks($this->prow, $this->rrows, $this->mycrows,
			  $editrrow, $this->mode, $this->allreviewslink);
	if (($empty = ($t == "")))
	    $t = $ifempty;
	$this->_paptabSepContaining($t);
	echo Ht::cbox("pap", true), "</td></tr></table></div>\n";
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
	global $Conf, $Me;
	$prow = $this->prow;

	if ($Me->is_admin_force()
            && !$Me->canViewReview($prow, null, false))
	    $this->_paptabSepContaining("<div class='inpapcc'>" . $this->_privilegeMessage() . "</div>");

	$empty = $this->_paptabReviewLinks(true, null, "<div class='hint'>There are no reviews or comments for you to view.</div>");
	if ($empty)
	    return;

	// text format link
	$viewable = array();
	foreach ($this->rrows as $rr)
	    if ($rr->reviewModified > 0 && $Me->canViewReview($prow, $rr, null)) {
		$viewable[] = "reviews";
		break;
	    }
	foreach ($this->crows as $cr)
	    if ($Me->canViewComment($prow, $cr, null)) {
		$viewable[] = "comments";
		break;
	    }
	if (count($viewable))
	    echo "<div class='pboxc'>",
		"<table class='pbox'><tr><td class='pboxl'></td>",
		"<td class='pboxr'>",
		"<a href='", hoturl("review", "p=$prow->paperId&amp;m=r&amp;text=1"), "' class='xx'>",
		$Conf->cacheableImage("txt24.png", "[Text]", null, "dlimg"),
		"&nbsp;<u>", ucfirst(join(" and ", $viewable)),
		" in plain text</u></a></td></tr></table></div>\n";

	$opt = array("edit" => false);
        $rf = reviewForm();
	foreach ($this->rrows as $rr)
	    if ($rr->reviewSubmitted)
		$rf->show($prow, $this->rrows, $rr, $opt);
	foreach ($this->rrows as $rr)
	    if (!$rr->reviewSubmitted && $rr->reviewModified > 0
		&& $Me->canViewReview($prow, $rr, null))
		$rf->show($prow, $this->rrows, $rr, $opt);
    }

    function paptabComments() {
	global $Conf, $Me, $useRequest;
	$prow = $this->prow;

	// show comments as well
	if ((count($this->mycrows) || $Me->canComment($prow, null)
	     || $Conf->timeAuthorRespond()) && !$this->allreviewslink) {
	    require_once("src/commentview.php");
	    $cv = new CommentView;
	    $editablecid = defval($_REQUEST, "commentId", "xxx");
	    if (isset($_REQUEST["noedit"]))
		$editablecid = "xxx";
	    $editableresponse = $Conf->timeAuthorRespond() && $prow->has_author($Me);

	    foreach ($this->mycrows as $cr) {
		$editMode = $editablecid == $cr->commentId
                    || ($editableresponse
                        && ($cr->commentType & COMMENTTYPE_RESPONSE));
		$cv->show($prow, $cr, $editMode && $useRequest, $editMode);
	    }

	    // comment editing
	    if ($Me->canComment($prow, null))
		$cv->show($prow, null, $editablecid == "new" && $useRequest,
			  true, $editablecid != "new");
	    if (!$cv->nresponse && $Conf->timeAuthorRespond()
		&& ($prow->has_author($Me)
		    || ($Me->canAdminister($prow) && $editablecid == "response")))
		$cv->showResponse($prow, null, false, true);

	    $cv->table_end();
	    unset($_SESSION["comment_msgs"]);
	}
    }

    function paptabEndWithReviewMessage() {
	global $Conf, $Me;

	if (!$Me->canViewReview($this->prow, null, null, $whyNot)
	    && $this->rrows)
	    $this->_paptabSepContaining("You can’t see the reviews for this paper. " . whyNotText($whyNot, "review"));

	if ($this->mode != "pe")
	    $this->_paptabReviewLinks(false, null, "");
	else
	    echo Ht::cbox("pap", true), "</td></tr></table></div>\n";
    }

    function paptabEndWithEditableReview() {
	global $Conf, $Me;
	$prow = $this->prow;
	$actPC = $Me->actPC($prow);
	$actChair = $Me->canAdminister($prow);

	// review messages
	$viewall = $Me->canViewReview($prow, null, false, $whyNot);
	$msgs = array();
	if (!$this->rrow && $this->prow->reviewType <= 0)
	    $msgs[] = "You haven&rsquo;t been assigned to review this paper, but you can review it anyway.";
	if ($Me->is_admin_force() && !$viewall) {
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

	if ($this->editrrow
            && ($Me->ownReview($this->editrrow) || $actChair)
	    && !$Conf->time_review($actPC, true)) {
	    if ($actChair)
		$override = "  As an administrator, you can override this deadline using the “Override deadlines” checkbox.";
	    else {
		$override = "";
		if ($this->editrrow->reviewSubmitted)
		    $opt["edit"] = false;
	    }
	    if (!$Conf->time_review($actPC, true, true))
		$opt["editmessage"] = "The <a href='" . hoturl("deadlines") . "'>deadline</a> for changing reviews has passed, so the review can no longer be changed.$override";
	    else
		$opt["editmessage"] = "The site is not open for reviewing, so the review cannot be changed.$override";
	} else if (!$Me->canReview($prow, $this->editrrow))
	    $opt["edit"] = false;

        $rf = reviewForm();
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
		&& $k != "forceShow" && $k != "go" && $k != "actas"
		&& $k != "ls" && $k != "t"
		&& !isset($_COOKIE[$k]))
		return false;

	// if no paper ID set, find one
	if (!isset($_REQUEST["paperId"])) {
	    $q = "select min(Paper.paperId) from Paper ";
	    if ($Me->isPC)
		$q .= "where timeSubmitted>0";
	    else if ($Me->has_review())
		$q .= "join PaperReview on (PaperReview.paperId=Paper.paperId and PaperReview.contactId=$Me->contactId)";
	    else
		$q .= "join ContactInfo on (ContactInfo.paperId=Paper.paperId and ContactInfo.contactId=$Me->contactId and ContactInfo.conflictType>=" . CONFLICT_AUTHOR . ")";
	    $result = $Conf->q($q);
	    if (($paperId = edb_row($result)))
		$_REQUEST["paperId"] = $paperId[0];
	    return false;
	}

	// if invalid contact, don't search
	if ($Me->is_empty())
	    return false;

	// actually try to search
	if ($_REQUEST["paperId"] == "(All)")
	    $_REQUEST["paperId"] = "";
	$search = new PaperSearch($Me, array("q" => $_REQUEST["paperId"], "t" => defval($_REQUEST, "t", 0)));
	$pl = $search->paperList();
	if (count($pl) == 1) {
            $pl = $search->session_list_object();
	    $_REQUEST["paperId"] = $_REQUEST["p"] = $pl->ids[0];
            // check if the paper is in the current list. If not, make a new one
            if (!@$_REQUEST["ls"]
                || !($curpl = SessionList::lookup($_REQUEST["ls"]))
                || array_search($pl->ids[0], $curpl->ids) === false) {
                $_REQUEST["ls"] = SessionList::allocate($pl->listid);
                SessionList::change($_REQUEST["ls"], $pl);
            } else if (@$pl->matchPreg)
                $_SESSION["temp_matchPreg"] = $pl->matchPreg;
            // ensure URI makes sense ("paper/2" not "paper/searchterm")
            redirectSelf();
	    return true;
	} else {
	    $t = (defval($_REQUEST, "t", 0) ? "&t=" . urlencode($_REQUEST["t"]) : "");
	    go(hoturl("search", "q=" . urlencode($_REQUEST["paperId"]) . $t));
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
		&& !$Me->canViewReview($prow, $rrow, null)
		&& !$Me->privChair)) {
	    // Don't allow querier to probe review/comment<->paper mapping
	    if (!isset($_REQUEST["paperId"]))
		$whyNot = array("invalidId" => "paper");
	    return null;
	}
        if (!isset($_REQUEST["paperId"]))
            $_REQUEST["paperId"] = $prow->paperId;
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
	    $this->crows = $Conf->comment_rows
		("select PaperComment.*, firstName, lastName, email
		from PaperComment join ContactInfo using (contactId)
		where paperId=" . $this->prow->paperId . " order by commentId", $Me);
	    $this->mycrows = array();
	    foreach ($this->crows as $crow)
		if ($Me->canViewComment($this->prow, $crow, null))
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
	    && !$Me->canViewReview($prow, $this->rrow, null))
	    $this->rrow = $this->editrrow = null;
	if ($this->mode == "r" && !$this->rrow && !$this->editrrow
	    && !$Me->canViewReview($prow, $this->rrow, null)
	    && $Me->canReview($prow, $this->rrow, $whyNot, false))  {
	    $this->mode = "re";
	    foreach ($this->rrows as $rr)
		if ($rr->contactId == $Me->contactId
		    || (!$this->editrrow && $Me->ownReview($rr)))
		    $this->editrrow = $rr;
	}
	if ($this->mode == "r" && $prow && !count($this->rrows)
	    && !count($this->mycrows)
	    && $prow->has_author($Me)
	    && !$Me->allowAdminister($prow)
	    && ($Conf->timeFinalizePaper($prow) || $prow->timeSubmitted <= 0))
	    $this->mode = "pe";
    }

}
