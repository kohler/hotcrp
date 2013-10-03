<?php
// paperlist.php -- HotCRP helper class for producing paper lists
// HotCRP is Copyright (c) 2006-2013 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

global $ConfSitePATH;
require_once("$ConfSitePATH/Code/baselist.inc");

class PaperList extends BaseList {

    // creator can set to change behavior
    public $papersel;
    public $display;

    // columns access
    public $sorter;
    public $contact;
    public $scoresOk;
    public $search;
    public $rf;
    public $tagger;
    public $reviewer;
    public $review_list;

    private $sortable;
    private $subsorter;
    var $listNumber;
    private $_paper_link_page;
    private $_paper_link_args;
    private $viewmap;
    private $atab;

    // collected during render and exported to caller
    public $count;
    public $ids;
    public $any;

    function __construct($search, $args = array()) {
	global $scoreSorts, $defaultScoreSort, $Conf;
	$this->search = $search;

	if (($this->sortable = !!defval($args, "sort"))
            && isset($_REQUEST["sort"]))
            $this->sorter = BaseList::parse_sorter($_REQUEST["sort"]);
        else
            $this->sorter = BaseList::parse_sorter("");
        $this->subsorter = null;

	$this->_paper_link_page = "";
        if (isset($_REQUEST["linkto"])
            && ($_REQUEST["linkto"] == "paper" || $_REQUEST["linkto"] == "review" || $_REQUEST["linkto"] == "assign"))
            $this->_paper_link_page = $_REQUEST["linkto"];
	$this->_paper_link_args = "";
	if (defval($args, "list")) {
	    $this->listNumber = allocateListNumber($search->listId($this->sortdef()));
	    $this->_paper_link_args .= "&amp;ls=" . $this->listNumber;
	} else
	    $this->listNumber = 0;

	$this->scoresOk = false;
	$this->papersel = null;
	if (is_string(defval($args, "display", null)))
	    $this->display = " " . $args["display"] . " ";
	else
	    $this->display = defval($_SESSION, defval($args, "foldtype", "pl") . "display", "");
        if (isset($args["reviewer"]))
            $this->reviewer = $args["reviewer"];
	$this->rf = reviewForm();
	$this->atab = defval($_REQUEST, "atab", "");
	unset($_REQUEST["atab"]);
    }

    function _sort($rows, $fdef) {
        global $sort_info;      /* ugh, PHP constraints */
        $sort_info = (object) array("f" => $fdef);

        $code = "global \$sort_info; \$x = 0;\n";
        if (($thenmap = $this->search->thenmap)) {
            foreach ($rows as $row)
                $row->_then_sort_info = $thenmap[$row->paperId];
            $code .= "\$x = \$x ? \$x : \$a->_then_sort_info - \$b->_then_sort_info;\n";
        }

        $fdef->sort_prepare($this, $rows);
        $code .= "\$x = \$x ? \$x : \$sort_info->f->" . $fdef->sorter . "(\$a, \$b);\n";

        if (($sub = $this->subsorter)) {
            $sub->field->sort_prepare($this, $rows);
            $sort_info->g = $sub->field;
            $code .= "\$x = \$x ? \$x : \$sort_info->g->" . $sub->field->sorter . "(\$a, \$b);\n";
        }

        $code .= "\$x = \$x ? \$x : \$a->paperId - \$b->paperId;\n";
        $code .= "return \$x < 0 ? -1 : (\$x == 0 ? 0 : 1);\n";

        usort($rows, create_function("\$a, \$b", $code));
        unset($sort_info);
	return $rows;
    }

    function sortdef($always = false) {
	if ($this->sorter->type
	    && ($always || defval($_REQUEST, "sort", "") != "")
	    && ($this->sorter->type != "id" || $this->sorter->reverse)) {
            $x = ($this->sorter->reverse ? "r" : "");
            if (($fdef = PaperColumn::lookup($this->sorter->type))
                && isset($fdef->score))
                $x .= $this->sorter->score;
            return ($fdef ? $fdef->name : $this->sorter->type)
                . ($x ? ",$x" : "");
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


    function _contentDownload($row) {
	global $Conf;
	if ($row->finalPaperStorageId != 0) {
	    $finalsuffix = "f";
	    $finaltitle = "Final version";
	    $row->documentType = DTYPE_FINAL;
	} else {
	    $finalsuffix = "";
	    $finaltitle = null;
	    $row->documentType = DTYPE_SUBMISSION;
	}
	if ($row->size == 0 || !$this->contact->canDownloadPaper($row))
	    return "";
	if ($row->documentType == DTYPE_FINAL)
	    $this->any->final = true;
	$this->any->paper = true;
        $t = "&nbsp;<a href=\"" . $Conf->makeDownloadPath($row) . "\">";
	if ($row->mimetype == "application/pdf")
	    return $t . $Conf->cacheableImage("pdf$finalsuffix.png", "[PDF]", $finaltitle) . "</a>";
	else if ($row->mimetype == "application/postscript")
	    return $t . $Conf->cacheableImage("postscript$finalsuffix.png", "[PS]", $finaltitle) . "</a>";
	else
	    return $t . $Conf->cacheableImage("generic$finalsuffix.png", "[Download]", $finaltitle) . "</a>";
    }

    function _paperLink($row) {
	global $Conf;
        $pt = $this->_paper_link_page ? $this->_paper_link_page : "paper";
	$pl = "p=" . $row->paperId;
	$doreview = isset($row->reviewId) && isset($row->reviewFirstName);
	if ($doreview) {
	    $rord = unparseReviewOrdinal($row);
	    if ($pt != "paper" || $row->reviewSubmitted <= 0) {
		$pl .= "&amp;r=" . $rord;
		if ($row->reviewSubmitted > 0)
		    $pl .= "&amp;m=r";
	    }
	}
	$pl .= $this->_paper_link_args;
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

    static function wrapChairConflict($text) {
	return "<span class='fn5'><em>Hidden for conflict</em> &nbsp;<span class='barsep'>|</span>&nbsp; <a href=\"javascript:void fold('pl',0,'force')\">Override conflicts</a></span><span class='fx5'>$text</span>";
    }

    public function maybeConflict($row, $text, $visible) {
	if ($visible)
	    return $text;
	else if ($this->contact->allowAdminister($row))
	    return self::wrapChairConflict($text);
	else
	    return "";
    }

    public function _contentPC($row, $contactId, $visible) {
	$pcm = pcMembers();
	if (isset($pcm[$contactId]))
	    return $this->maybeConflict($row, Text::name_html($pcm[$contactId]), $visible);
	else
	    return "";
    }

    static function _rowPreferences($row) {
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

    function _reviewAnalysis($row) {
	global $Conf, $reviewTypeName;
	$ranal = (object) array("completion" => "", "delegated" => false,
				"needsSubmit" => false,
                                "round" => "", "type_name" => "",
				"link1" => "", "link2" => "");
	if ($row->reviewId) {
            if (isset($reviewTypeName[$row->reviewType]))
                $ranal->type_name = $reviewTypeName[$row->reviewType];
            else
                $ranal->type_name = "Review"; /* won't happen; just in case */
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
		$link = hoturl("review", "r=" . unparseReviewOrdinal($row) . $this->_paper_link_args);
	    else
		$link = hoturl("paper", "p=" . $row->paperId . $this->_paper_link_args . "#review" . unparseReviewOrdinal($row));
	    $ranal->link1 = "<a href=\"$link\">";
	    $ranal->link2 = "</a>";
	    if ($row->reviewRound) {
		$t = defval($Conf->settings["rounds"], $row->reviewRound);
		$ranal->round = $t ? htmlspecialchars($t) : "?$row->reviewRound?";
	    }
	}
	return $ranal;
    }

    static function _reviewIcon($row, $ranal, $includeLink) {
	global $Conf;
	$alt = $ranal->type_name . " review";
	if ($ranal->needsSubmit)
	    $alt .= " (" . strtolower($ranal->completion) . ")";
	$t = $Conf->cacheableImage("_.gif", $alt, $alt, "ass" . $row->reviewType . ($ranal->needsSubmit ? "n" : ""));
	if ($includeLink)
	    $t = $ranal->link1 . $t . $ranal->link2;
	if ($ranal->round)
	    $t .= "&nbsp;<span class='revround' title='Review round'>" . $ranal->round . "</span>";
	return $t;
    }

    private function _footer($ncol, $listname, $trclass, $extra) {
	global $Conf;
	if ($this->count == 0)
	    return "";

	$barsep = "<td>&nbsp;<span class='barsep'>&nbsp;|&nbsp;</span>&nbsp;</td>";
	$nlll = 1;
	$revpref = ($listname == "editReviewPreference");
	$whichlll = 1;
	$want_plactions_dofold = false;

	// Download
	if ($this->atab == "download")
	    $whichlll = $nlll;
	$t = "<td class='lll$nlll nowrap'><a href=\"" . selfHref(array("atab" => "download")) . "#plact\" onclick='return crpfocus(\"plact\",$nlll)'>Download</a></td><td class='lld$nlll nowrap'><b>:</b> &nbsp;";
	$sel_opt = array();
	if ($revpref) {
	    $sel_opt["revpref"] = "Preference file";
	    $sel_opt["revprefx"] = "Preference file with abstracts";
	    $sel_opt["abstract"] = "Abstracts";
	} else {
	    if ($this->any->final) {
		$sel_opt["final"] = "Final papers";
		foreach (PaperOption::get() as $id => $o)
		    if ($o->type == PaperOption::T_FINALPDF)
			$sel_opt["opt-" . $o->optionAbbrev] = htmlspecialchars($o->optionName) . " papers";
		    else if ($o->type == PaperOption::T_FINALSLIDES)
			$sel_opt["opt-" . $o->optionAbbrev] = htmlspecialchars(pluralx($o->optionName, 2));
		$sel_opt["paper"] = "Submitted papers";
	    } else if ($this->any->paper)
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
	$sel_opt["topics"] = "Topics";
	if ($this->contact->privChair) {
	    $sel_opt["checkformat"] = "Format check";
	    $sel_opt["pcconf"] = "PC conflicts";
            $sel_opt["allrevpref"] = "PC review preferences";
	}
	if (!$revpref && ($this->contact->privChair || ($this->contact->isPC && $Conf->timePCViewAllReviews())))
	    $sel_opt["scores"] = "Scores";
	if ($Conf->setting("paperlead") > 0) {
	    $sel_opt["lead"] = "Discussion leads";
	    $sel_opt["shepherd"] = "Shepherds";
	}
        if ($this->contact->privChair)
            $sel_opt["acmcms"] = "ACM CMS report";
	$t .= Ht::select("getaction", $sel_opt, defval($_REQUEST, "getaction"),
			  array("id" => "plact${nlll}_d", "tabindex" => 6))
	    . "&nbsp; <input type='submit' class='b' name='getgo' value='Go' tabindex='6' onclick='return (papersel_check_safe=true)' /></td>";
	$nlll++;

	// Upload preferences (review preferences only)
	if ($revpref) {
	    if (isset($_REQUEST["upload"]) || $this->atab == "uploadpref")
		$whichlll = $nlll;
	    $t .= $barsep;
	    $t .= "<td class='lll$nlll nowrap'><a href=\"" . selfHref(array("atab" => "uploadpref")) . "#plact\" onclick='return crpfocus(\"plact\",$nlll)'>Upload</a></td><td class='lld$nlll nowrap'><b>&nbsp;preference file:</b> &nbsp;";
	    $t .= "<input id='plact${nlll}_d' type='file' name='uploadedFile' accept='text/plain' size='20' tabindex='6' onfocus='autosub(\"upload\",this)' />&nbsp; <input type='submit' class='b' name='upload' value='Go' tabindex='6' /></td>";
	    $nlll++;
	}

	// Set preferences (review preferences only)
	if ($revpref) {
	    if (isset($_REQUEST["setpaprevpref"]) || $this->atab == "setpref")
		$whichlll = $nlll;
	    $t .= $barsep;
	    $t .= "<td class='lll$nlll nowrap'><a href=\"" . selfHref(array("atab" => "setpref")) . "#plact\" onclick='return crpfocus(\"plact\",$nlll)'>Set preferences</a></td><td class='lld$nlll nowrap'><b>:</b> &nbsp;";
	    $t .= "<input id='plact${nlll}_d' class='textlite' type='text' name='paprevpref' value='' size='4' tabindex='6' onfocus='autosub(\"setpaprevpref\",this)' /> &nbsp;<input type='submit' class='b' name='setpaprevpref' value='Go' tabindex='6' /></td>";
	    $nlll++;
	}

	// Tags (search+PC only)
	if ($this->contact->isPC && !$revpref) {
	    if (isset($_REQUEST["tagact"]) || $this->atab == "tags")
		$whichlll = $nlll;
	    $t .= $barsep;
	    $t .= "<td class='lll$nlll nowrap'><a href=\"" . selfHref(array("atab" => "tags")) . "#plact\" onclick='return crpfocus(\"plact\",$nlll)'>Tag</a></td><td class='lld$nlll nowrap'><table id='foldplacttags' class='foldc fold99c'><tr><td><b>:</b><a class='help' href='" . hoturl("help", "t=tags") . "' target='_blank' title='Learn more'>?</a> &nbsp;";
	    $tagopt = array("a" => "Add", "d" => "Remove", "s" => "Define", "xxxa" => null, "ao" => "Add to order", "aos" => "Add to gapless order", "so" => "Define order", "sos" => "Define gapless order", "sor" => "Define random order");
	    $tagextra = array("class" => "b", "id" => "placttagtype");
	    if ($this->contact->privChair) {
		$tagopt["xxxb"] = null;
		$tagopt["da"] = "Clear twiddle";
		$tagopt["cr"] = "Calculate rank";
		$tagextra["onchange"] = "plactions_dofold()";
		$want_plactions_dofold = true;
	    }
	    $t .= Ht::select("tagtype", $tagopt, defval($_REQUEST, "tagtype"),
			      $tagextra) . " &nbsp;";
	    if ($this->contact->privChair) {
		$t .= "<span class='fx99'><a href='#' onclick=\"return fold('placttags')\">"
		    . $Conf->cacheableImage("_.gif", "More...", null, "expander")
		    . "</a>&nbsp;</span></td><td>";
	    }
	    $t .= "tag<span class='fn99'>(s)</span> &nbsp;<input id='plact${nlll}_d' class='textlite' type='text' name='tag' value=\"" . htmlspecialchars(defval($_REQUEST, "tag", "")) . "\"' size='15' onfocus='autosub(\"tagact\",this)' /> &nbsp;<input type='submit' class='b' name='tagact' value='Go' />";
	    if ($this->contact->privChair) {
		$t .= "<div class='fx'><div style='margin:2px 0'>"
		    . Ht::checkbox("tagcr_gapless", 1, defval($_REQUEST, "tagcr_gapless"), array("style" => "margin-left:0"))
		    . "&nbsp;" . Ht::label("Gapless order") . "</div>"
		    . "<div style='margin:2px 0'>Using: &nbsp;"
		    . Ht::select("tagcr_method", PaperRank::methods(), defval($_REQUEST, "tagcr_method"))
		    . "</div>"
		    . "<div style='margin:2px 0'>Source tag: &nbsp;~<input class='textlite' type='text' name='tagcr_source' value=\"" . htmlspecialchars(defval($_REQUEST, "tagcr_source", "")) . "\" size='15' /></div>"
		    . "</div>";
	    }
	    $t .= "</td></tr></table></td>";
	    $nlll++;
	}

	// Assignments (search+admin only)
	if ($this->contact->privChair && !$revpref) {
	    if (isset($_REQUEST["setassign"]) || $this->atab == "assign")
		$whichlll = $nlll;
	    $t .= $barsep;
	    $t .= "<td class='lll$nlll'><a href=\"" . selfHref(array("atab" => "assign")) . "#plact\" onclick='return crpfocus(\"plact\",$nlll)'>Assign</a></td><td id='foldass' class='lld$nlll foldo'><b>:</b> &nbsp;";
	    $want_plactions_dofold = true;
	    $t .= Ht::select("marktype",
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
		$sel_opt[htmlspecialchars($row->email)] = Text::name_html($row);
	    $t .= Ht::select("markpc", $sel_opt, defval($_REQUEST, "markpc"),
			      array("id" => "markpc"))
		. "</span> &nbsp;<input type='submit' class='b' name='setassign' value='Go' />";
	    $t .= "</td>";
	    $nlll++;
	}

	// Decide, Mail (search+admin only)
	if ($this->contact->privChair && !$revpref) {
	    if ($this->atab == "decide")
		$whichlll = $nlll;
	    $t .= $barsep;
	    $t .= "<td class='lll$nlll'><a href=\"" . selfHref(array("atab" => "decide")) . "#plact\" onclick='return crpfocus(\"plact\",$nlll)'>Decide</a></td><td class='lld$nlll'><b>:</b> Set to &nbsp;";
	    $t .= decisionSelector(defval($_REQUEST, "decision", 0), "plact${nlll}_d") . " &nbsp;<input type='submit' class='b' name='setdecision' value='Go' /></td>";
	    $nlll++;

	    if (isset($_REQUEST["sendmail"]) || $this->atab == "mail")
		$whichlll = $nlll;
	    $t .= $barsep
		. "<td class='lll$nlll'><a href=\"" . selfHref(array("atab" => "mail")) . "#plact\" onclick='return crpfocus(\"plact\",$nlll)'>Mail</a></td><td class='lld$nlll'><b>:</b> &nbsp;"
		. Ht::select("recipients", array("au" => "Contact authors", "rev" => "Reviewers"), defval($_REQUEST, "recipients"), array("id" => "plact${nlll}_d"))
		. " &nbsp;<input type='submit' class='b' name='sendmail' value='Go' /></td>";
	    $nlll++;
	}

	if ($want_plactions_dofold)
	    $Conf->footerScript("plactions_dofold()");

	// Linelinks container
	$foot = " <tfoot>\n"
	    . "  <tr class='pl_footgap $trclass'><td colspan='$ncol'><div class='pl_footgap'></div></td></tr>\n"
	    . "  <tr class='pl_footrow'>\n";
	if ($this->viewmap->columns)
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
	    . $t . "</tr></td></table>" . $extra . "</td></tr>\n"
	    . " </tfoot>\n";
    }

    function _canonicalize_fields($fields) {
	$minimal = $this->viewmap->compactcolumns;
	$newFields = array();
	foreach ($fields as $fid) {
	    $nf = array();
	    if ($fid == "scores") {
		if ($this->scoresOk) {
                    $nf = ScorePaperColumn::lookup_all();
                    $this->scoresOk = "present";
                }
	    } else if ($fid == "formulas") {
		if ($this->scoresOk)
                    $nf = FormulaPaperColumn::lookup_all();
	    } else if ($fid == "tagreports") {
                $nf = TagReportPaperColumn::lookup_all();
	    } else if (($f = PaperColumn::lookup($fid)))
		$nf[] = $f;
	    foreach ($nf as $f)
		if (!$minimal || $f->minimal || $this->viewmap[$f->name])
		    $newFields[] = $f;
	}
	return $newFields;
    }

    static function _listDescription($listname) {
	switch ($listname) {
	  case "reviewAssignment":
	    return "Review assignments";
	  case "conflict":
	    return "Potential conflicts";
	  case "editReviewPreference":
	    return "Review preferences";
	  case "reviewers":
	  case "reviewersSel":
	    return "Proposed assignments";
	  default:
	    return null;
	}
    }

    private function _default_linkto($page) {
        if (!$this->_paper_link_page)
            $this->_paper_link_page = $page;
    }

    private function _listFields($listname) {
	switch ($listname) {
	case "a":
            $fields = "id title statusfull revstat scores";
            break;
	case "authorHome":
	    $fields = "id title statusfull";
	    break;
	case "s":
	case "acc":
	    $fields = "sel id title revtype revstat status authors abstract tags tagreports topics collab reviewers pcconf lead shepherd scores formulas";
	    break;
	case "all":
	case "act":
            $fields = "sel id title statusfull revtype authors abstract tags tagreports topics collab reviewers pcconf lead shepherd scores formulas";
	    break;
	case "reviewerHome":
	    $this->_default_linkto("review");
	    $fields = "id title revtype status";
	    break;
	case "r":
	case "lead":
	    $this->_default_linkto("review");
            $fields = "sel id title revtype revstat status authors abstract tags tagreports topics collab reviewers pcconf lead shepherd scores formulas";
	    break;
	case "rout":
	    $this->_default_linkto("review");
            $fields = "sel id title revtype revstat status authors abstract tags tagreports topics collab reviewers pcconf lead shepherd scores formulas";
	    break;
	case "req":
	    $this->_default_linkto("review");
            $fields = "sel id title revtype revstat status authors abstract tags tagreports topics collab reviewers pcconf lead shepherd scores formulas";
	    break;
	case "reqrevs":
	    $this->_default_linkto("review");
            $fields = "id title revdelegation revsubmitted revstat status authors abstract tags tagreports topics collab reviewers pcconf lead shepherd scores formulas";
	    break;
        case "reviewAssignment":
            $this->_default_linkto("assign");
            $fields = "id title revpref topicscore desirability assrev authors tags topics reviewers allrevpref authorsmatch collabmatch scores formulas";
	    break;
        case "conflict":
	    $this->_default_linkto("assign");
            $fields = "selconf id title authors abstract tags authorsmatch collabmatch foldall";
	    break;
        case "editReviewPreference":
            $this->_default_linkto("paper");
            $fields = "sel id title topicscore revtype editrevpref authors abstract topics";
	    break;
        case "reviewers":
            $this->_default_linkto("assign");
            $fields = "id title status reviewers autoassignment";
	    break;
        case "reviewersSel":
            $this->_default_linkto("assign");
            $fields = "selon id title status reviewers";
	    break;
	  default:
	    return null;
	}
	return $this->_canonicalize_fields(explode(" ", $fields));
    }

    private function _addAjaxLoadForm($pap, $extra = "") {
	global $Conf;
	$t = "<div><form id='plloadform' method='post' action='" . hoturl_post("search", "ajax=1" . $this->_paper_link_args) . "' accept-charset='UTF-8'>"
	    . "<div class='inform'>";
	$s = $this->search;
	if ($s->q)
	    $t .= Ht::hidden("q", $s->q);
	if ($s->qt)
	    $t .= Ht::hidden("qt", $s->qt);
        $aufull = !$this->is_folded("aufull");
	$t .= Ht::hidden("t", $s->limitName)
	    . Ht::hidden("pap", join(" ", $pap))
	    . Ht::hidden("get", "", array("id" => "plloadform_get"))
	    . Ht::hidden("aufull", $aufull ? "1" : "", array("id" => "plloadform_aufull"))
	    . "</div></form></div>";
	$Conf->footerHtml($t);
    }

    private function _prepareQuery($me, $queryOptions) {
	global $Conf;

	// prepare review query (see also search > getaction == "reviewers")
	$this->review_list = array();
	if (isset($queryOptions["reviewList"])) {
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
		$this->review_list[$row->paperId][] = $row;
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
        $pq = $Conf->paperQuery($me, $queryOptions);

	// make query
	return $Conf->qe($pq, "while selecting papers");
    }

    public function is_folded($field) {
        $fname = $field;
	if (is_object($field) || ($field = PaperColumn::lookup($field)))
	    $fname = $field->foldable ? $field->name : null;
	return $fname
            && !defval($_REQUEST, "show$fname")
            && ($this->viewmap->$fname === false
                || ($this->viewmap->$fname === null
                    && strpos($this->display, " $fname ") === false));
    }

    private function _row_text($rstate, $row, $fieldDef) {
	global $Conf;

	$rstate->ids[] = $row->paperId;
	$trclass = "k" . $rstate->colorindex;
	if (defval($row, "paperTags") && $this->contact->canViewTags($row, true)
	    && ($m = $this->tagger->color_classes($row->paperTags)))
	    $trclass .= " " . $m;
	$rstate->colorindex = 1 - $rstate->colorindex;
	$rstate->last_trclass = $trclass;

	// main columns
	$t = "";
	foreach ($fieldDef as $fdef) {
	    if ($fdef->view != Column::VIEW_COLUMN)
		continue;
	    $td = "    <td class=\"pl_$fdef->cssname";
	    if ($fdef->foldable)
		$td .= " fx$fdef->foldable";
	    if ($fdef->content_empty($this, $row)) {
		$t .= $td . "\"></td>\n";
		continue;
	    }
	    if ($fdef->foldable && !isset($rstate->foldinfo[$fdef->name]))
		$rstate->foldinfo[$fdef->name] = $this->is_folded($fdef);
	    if ($fdef->foldable && $rstate->foldinfo[$fdef->name]) {
		$t .= $td . "\" id=\"$fdef->name.$row->paperId\"></td>\n";
                $this->any[$fdef->name] = true;
	    } else {
		$c = $fdef->content($this, $row);
		$t .= $td . "\">" . $c . "</td>\n";
		if ($c !== "")
                    $this->any[$fdef->name] = true;
	    }
	}

	// extension columns
	$tt = "";
	foreach ($fieldDef as $fdef) {
	    if ($fdef->view != Column::VIEW_ROW
                || $fdef->content_empty($this, $row))
		continue;
	    if ($fdef->foldable && !isset($rstate->foldinfo[$fdef->name]))
		$rstate->foldinfo[$fdef->name] = $this->is_folded($fdef);
	    if ($fdef->foldable && $fdef->name != "authors"
                && $rstate->foldinfo[$fdef->name]) {
		$tt .= "<div id=\"$fdef->name.$row->paperId\"></div>";
                $this->any[$fdef->name] = true;
	    } else if (($c = $fdef->content($this, $row)) !== "") {
                if (!$fdef->foldable)
                    $tc = "";
                else if ($fdef->name != "authors")
		    $tc = " fx" . $fdef->foldable;
		else if ($this->contact->canViewAuthors($row, false)) {
		    $tc = " fx1";
                    $this->any->openau = true;
		} else {
		    $tc = " fx1 fx2";
                    $this->any->anonau = true;
		}
		$tt .= "<div id=\"$fdef->name.$row->paperId\" class=\"pl_"
                    . $fdef->cssname . $tc . "\"><h6>"
                    . $fdef->header($this, $row, -1)
		    . ":</h6> " . $c . "</div>";
		$this->any[$fdef->name] = true;
	    }
	}

	if (isset($row->folded) && $row->folded) {
	    $trclass .= " fx3";
	    $rstate->foldinfo["wholerow"] = true;
	}

	$t = "  <tr class=\"pl $trclass\" hotcrpid=\"$row->paperId\" hotcrptitlehint=\"" . htmlspecialchars(titleWords($row->title, 60)) . "\">\n" . $t . "  </tr>\n";

	if ($tt !== "") {
	    $t .= "  <tr class=\"plx $trclass\" hotcrpid=\"$row->paperId\">";
	    if ($rstate->skipcallout > 0)
		$t .= "<td colspan=\"$rstate->skipcallout\"></td>";
	    $t .= "<td colspan=\"" . ($rstate->ncol - $rstate->skipcallout) . "\">$tt</td></tr>\n";
	}

	return $t;
    }

    private function _row_check_heading($rstate, $srows, $row, $lastheading, &$body) {
	$headingmap = $this->search->headingmap;
	$heading = defval($headingmap, $row->paperId, "");
	if ($this->count == 1)
	    $rstate->headingstart = array(0);
	if ($heading != $lastheading) {
	    if ($this->count != 1)
		$rstate->headingstart[] = count($body);
	    if ($heading == "")
		$body[] = "  <tr class=\"pl plheading_blank plheading_middle\"><td class=\"plheading_blank plheading_middle\" colspan=\"$rstate->ncol\"></td></tr>\n";
	    else {
		for ($i = $this->count; $i < count($srows) && defval($headingmap, $srows[$i]->paperId, "") == $heading; ++$i)
		    /* do nothing */;
		$middle = ($this->count == 1 ? "" : " plheading_middle");
		$pheading = preg_replace('/(?:\A|\s)(?:-\s*|NOT\s*|)VIEW:\s*\S+/', "", $heading);
		$body[] = "  <tr class=\"pl plheading$middle\"><td class=\"plheading$middle\" colspan=\"$rstate->ncol\">" . htmlspecialchars(trim($pheading)) . " <span class=\"plheading_count\">(" . plural($i - $this->count + 1, "paper") . ")</span></td></tr>\n";
	    }
	    $rstate->colorindex = 0;
	}
	return $heading;
    }

    private function _analyze_folds($rstate, $fieldDef) {
	global $Conf;
	$classes = $jsmap = $jsloadmap = $jsnotitlemap = array();
	foreach ($fieldDef as $fdef)
	    if ($fdef->foldable && $fdef->name !== "authors"
		&& isset($rstate->foldinfo[$fdef->name])) {
		$closed = $rstate->foldinfo[$fdef->name];
		$classes[] = "fold$fdef->foldable" . ($closed ? "c" : "o");
		$jsmap[] = "\"$fdef->name\":$fdef->foldable";
		if ($closed && $this->any[$fdef->name])
		    $jsloadmap[] = "\"$fdef->name\":true";
		if ($fdef->view == Column::VIEW_COLUMN)
		    $jsnotitlemap[] = "\"$fdef->name\":true";
	    }
	// authorship requires special handling
	if ($this->any->openau || $this->any->anonau) {
	    $classes[] = "fold1" . ($this->is_folded("au") ? "c" : "o");
	    $jsmap[] = "\"au\":1,\"aufull\":4";
	    $jsloadmap[] = "\"aufull\":true";
	}
	if ($this->any->anonau) {
	    $classes[] = "fold2" . ($this->is_folded("anonau") ? "c" : "o");
	    $jsmap[] = "\"anonau\":2";
	}
	// total folding, row number folding
	if (isset($rstate->foldinfo["wholerow"]))
	    $classes[] = "fold3c";
	if ($this->any->sel) {
	    $jsmap[] = "\"rownum\":6";
	    $classes[] = "fold6" . ($this->is_folded("rownum") ? "c" : "o");
	}
	if ($this->contact->privChair) {
	    $jsmap[] = "\"force\":5";
	    $classes[] = "fold5" . (defval($_REQUEST, "forceShow") ? "o" : "c");
	}
	if (count($jsmap))
	    $Conf->footerScript("foldmap.pl={" . join(",", $jsmap) . "};");
	if (count($jsloadmap)) {
	    $Conf->footerScript("plinfo.needload={" . join(",", $jsloadmap) . "};");
	    $this->_addAjaxLoadForm($rstate->ids);
	}
	if (count($jsnotitlemap))
	    $Conf->footerScript("plinfo.notitle={" . join(",", $jsnotitlemap) . "};");
	return $classes;
    }

    private function _make_title_header_extra($rstate, $fieldDef, $show_links) {
	global $Conf;
	$titleextra = "";
	if (isset($rstate->foldinfo["wholerow"]))
	    $titleextra .= "<span class='sep'></span><a class='fn3' href=\"javascript:void fold('pl',0,3)\">Show all papers</a>";
	if (($this->any->openau || $this->any->anonau) && $show_links) {
	    $titleextra .= "<span class='sep'></span>";
	    if ($Conf->subBlindNever())
		$titleextra .= "<a class='fn1' href=\"javascript:void fold('pl',0,'au')\">Show authors</a><a class='fx1' href=\"javascript:void fold('pl',1,'au')\">Hide authors</a>";
	    else if ($this->contact->privChair && $this->any->anonau && !$this->any->openau)
		$titleextra .= "<a class='fn1 fn2' href=\"javascript:fold('pl',0,'au');void fold('pl',0,'anonau')\">Show authors</a><a class='fx1 fx2' href=\"javascript:fold('pl',1,'au');void fold('pl',1,'anonau')\">Hide authors</a>";
	    else if ($this->contact->privChair && $this->any->anonau)
		$titleextra .= "<a class='fn1' href=\"javascript:fold('pl',0,'au');void fold('pl',1,'anonau')\">Show non-anonymous authors</a><a class='fx1 fn2' href=\"javascript:void fold('pl',0,'anonau')\">Show all authors</a><a class='fx1 fx2' href=\"javascript:fold('pl',1,'au');void fold('pl',1,'anonau')\">Hide authors</a>";
	    else
		$titleextra .= "<a class='fn1' href=\"javascript:void fold('pl',0,'au')\">Show non-anonymous authors</a><a class='fx1' href=\"javascript:void fold('pl',1,'au')\">Hide authors</a>";
	}
	if ($this->any->tags && $show_links)
            foreach ($fieldDef as $fdef)
                if ($fdef->name == "tags" && $fdef->foldable) {
                    $titleextra .= "<span class='sep'></span>";
                    $titleextra .= "<a class='fn$fdef->foldable' href='javascript:void plinfo(\"tags\",0)'>Show tags</a><a class='fx$fdef->foldable' href='javascript:void plinfo(\"tags\",1)'>Hide tags</a><span id='tagsloadformresult'></span>";
                }
	return $titleextra ? "<span class='pl_titleextra'>$titleextra</span>" : "";
    }

    private function _column_split($rstate, $colhead, &$body) {
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
		else
		    $x = str_replace(" plheading_middle\"", "\"", $x);
		$nbody[] = $x;
	    }
	    $nbody[] = "  </tbody>\n</table></div></td>\n";
	}
	$nbody[] = "</tr>";

	$body = $nbody;
	$rstate->last_trclass = "k0 pl_splitcol";
	return true;
    }

    private function _set_viewmap() {
	$this->viewmap = new Qobject($this->search->viewmap);
	if ($this->viewmap->cc || $this->viewmap->compactcolumn
            || $this->viewmap->ccol || $this->viewmap->compactcolumns)
            $this->viewmap->compactcolumns = $this->viewmap->columns = true;
	if ($this->viewmap->column || $this->viewmap->col)
	    $this->viewmap->columns = true;
    }

    public function text($listname, $me, $options = array()) {
	global $Conf, $ConfSiteBase;

	$this->contact = $me;
	$this->count = 0;
	$this->any = new Qobject;
        $this->tagger = new Tagger($me);
	$url = $this->search->url();

	// check role type
	$this->scoresOk = $me->privChair || $me->amReviewer()
	    || $Conf->timeAuthorViewReviews();

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

	// collect view data
        $this->_set_viewmap();

	// get paper list
        $field_list = $this->_listFields($listname);
	if (!$field_list) {
	    $Conf->errorMsg("There is no paper list query named '" . htmlspecialchars($listname) . "'.");
	    return null;
	}
	if (defval($_REQUEST, "selectall") > 0
            && $field_list[0]->name == "sel")
	    $field_list[0] = PaperColumn::lookup("selon");

        // add requested fields
        $specials = array_flip(array("cc", "compactcolumn", "compactcolumns", "column", "col", "columns", "sort"));
        $new_names = array();
        foreach ($this->viewmap as $k => $v)
            if (!isset($specials[$k])) {
                $f = null;
                if ($v === "edit")
                    $f = PaperColumn::lookup("edit$k");
                if (!$f)
                    $f = PaperColumn::lookup($k);
                if ($f && $f->name != $k)
                    $new_names[$f->name] = $v;
                foreach ($field_list as $ff)
                    if ($f && $ff->name == $f->name)
                        $f = null;
                if ($f && $v)
                    $field_list[] = $f;
            }
        foreach ($new_names as $k => $v)
            $this->viewmap[$k] = $v;

        // check sort
        $special_sort = $sort_field = null;
        if (!$special_sort && count($this->search->orderTags)
            && ($special_sort = PaperColumn::lookup("tagordersort"))
            && !$special_sort->prepare($this, $queryOptions, -1))
            $special_sort = null;
        if (!$special_sort && $this->search->simplePaperList() !== null)
            $special_sort = PaperColumn::lookup("searchsort");
        if ($this->viewmap->sort
            && ($new_sorter = BaseList::parse_sorter($this->viewmap->sort))
            && ($new_sort_field = PaperColumn::lookup($new_sorter->type))
            && $new_sort_field->prepare($this, $queryOptions, -1)) {
            if ($this->sorter->empty) {
                $this->sorter = $new_sorter;
                $sort_field = $new_sort_field;
            } else {
                $this->subsorter = $new_sorter;
                $this->subsorter->field = $new_sort_field;
            }
        }
        if ($this->sorter->type
            && ($sort_field = PaperColumn::lookup($this->sorter->type))
            && !$sort_field->prepare($this, $queryOptions, -1))
            $sort_field = null;
        if (!$sort_field)
            $sort_field = $special_sort;
        if (!$sort_field)
            $sort_field = PaperColumn::lookup("id");
        $this->sorter->type = $sort_field->name;

	// get field array
	$fieldDef = array();
	$ncol = 0;
        // folds: au:1, anonau:2, fullrow:3, aufull:4, force:5, rownum:6, [fields]
        $next_fold = 7;
	foreach ($field_list as $f)
	    if ($this->viewmap[$f->name] !== false
                && $f->prepare($this, $queryOptions,
                               $this->is_folded($f) ? 0 : 1)) {
                if ($f->view != Column::VIEW_NONE)
                    $fieldDef[] = $f;
                if ($f->view != Column::VIEW_NONE && $f->foldable) {
                    $f->foldable = $next_fold;
                    ++$next_fold;
                }
		if ($f->view == Column::VIEW_COLUMN)
		    $ncol++;
	    }

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

        // analyze rows (usually noop)
        foreach ($fieldDef as $fdef)
            $fdef->analyze($this, $rows);

	// sort rows
	$srows = $this->_sort($rows, $sort_field);
	if ($this->sorter->reverse)
	    $srows = array_reverse($srows);
	if (isset($queryOptions["allReviewScores"]))
	    $this->_sortReviewOrdinal($srows);

	// count non-callout columns
	$skipcallout = 0;
	foreach ($fieldDef as $fdef)
	    if ($fdef->name != "id" && !isset($fdef->is_selector))
		break;
	    else
		++$skipcallout;

	// create render state
	$rstate = (object) array();
	$rstate->ids = array();
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
		$lastheading = $this->_row_check_heading($rstate, $srows, $row, $lastheading, $body);
	    $body[] = $this->_row_text($rstate, $row, $fieldDef);
	}

	// columns
	$colhead = "";
	foreach ($fieldDef as $fdef) {
	    if ($fdef->view == Column::VIEW_COLUMN)
		$colhead .= $fdef->col();
	}

	// header cells
	if (!defval($options, "noheader")) {
	    $colhead .= " <thead>\n  <tr class=\"pl_headrow\">\n";
	    $ord = 0;
	    $titleextra = $this->_make_title_header_extra($rstate, $fieldDef,
                                                          defval($options, "header_links"));

	    if ($this->sortable && $url) {
		$sortUrl = htmlspecialchars($url) . (strpos($url, "?") ? "&amp;" : "?") . "sort=";
		$q = "<a class='pl_sort' title='Change sort' href=\"" . $sortUrl;
	    } else
		$sortUrl = false;

	    foreach ($fieldDef as $fdef) {
		if ($fdef->view != Column::VIEW_COLUMN)
		    continue;
		if (!$this->any[$fdef->name]) {
		    $colhead .= "    <th class=\"pl_$fdef->cssname\"></th>\n";
		    continue;
		}
		$colhead .= "    <th class=\"pl_$fdef->cssname";
		if ($fdef->foldable)
		    $colhead .= " fx" . $fdef->foldable;
		$colhead .= "\">";
		$ftext = $fdef->header($this, null, $ord++);

                $has_special_sort = isset($fdef->is_selector) && $sortUrl && $special_sort;
                if ($has_special_sort && $special_sort->name == "tagordersort")
                    $ftext = "<span class='hastitle' title='Sort by tag order'>#</span>";
                else if ($has_special_sort && $special_sort->name == "searchsort")
                    $ftext = "<span class='hastitle' title='Sort by search term order'>#</span>";
		if ((($fdef->name == $sort_field->name
                      || $fdef->name == "edit" . $sort_field->name)
                     && $sortUrl)
                    || ($has_special_sort
                        && $special_sort->name == $sort_field->name))
		    $colhead .= "<a class='pl_sort_def" . ($this->sorter->reverse ? "_rev" : "") . "' title='Reverse sort' href=\"" . $sortUrl . urlencode($sort_field->name . "," . ($this->sorter->reverse ? "n" : "r")) . "\">" . $ftext . "</a>";
		else if ($fdef->sorter && $sortUrl)
		    $colhead .= $q . urlencode($fdef->name) . "\">" . $ftext . "</a>";
		else if ($has_special_sort)
		    $colhead .= $q . urlencode($special_sort->name) . "\">" . $ftext . "</a>";
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
	if (defval($options, "class"))
	    $enter .= " " . $options["class"];
	if (count($foldclasses))
	    $enter .= " " . join(" ", $foldclasses) . "\" id=\"foldpl";
	$enter .= "\">\n";
	$exit = "</table>";

	// maybe make columns, maybe not
	if ($this->viewmap->columns && count($rstate->ids)
	    && $this->_column_split($rstate, $colhead, $body)) {
	    $enter = "<div class='pl_splitcol_ctr_ctr'><div class='pl_splitcol_ctr'>" . $enter;
	    $exit = $exit . "</div></div>";
	    $ncol = $rstate->split_ncol;
	} else
	    $enter .= $colhead;

	if (($fieldDef[0]->name == "sel" || $fieldDef[0]->name == "selon")
            && !defval($options, "nofooter"))
	    $enter .= $this->_footer($ncol, $listname, $rstate->last_trclass,
                                     defval($options, "footer_extra", ""));

	$x = $enter . " <tbody>\n" . join("", $body) . " </tbody>\n" . $exit;

	// session variable to remember the list
	if ($this->listNumber) {
	    $idx = $this->search->decorateSessionList($rstate->ids, self::_listDescription($listname), $this->sortdef());
	    if (isset($_REQUEST["sort"]))
		$url .= (strpos($url, "?") ? "&" : "?") . "sort=" . urlencode($_REQUEST["sort"]);
	    if ($ConfSiteBase
		&& substr($url, 0, strlen($ConfSiteBase)) == $ConfSiteBase)
		$url = substr($url, strlen($ConfSiteBase));
	    $idx["url"] = $url;
	    $_SESSION["l"][$this->listNumber] = $idx;
	}

	$this->ids = $rstate->ids;
	return $x;
    }

    function ajaxColumn($fieldId, $me) {
	global $Conf;

	$this->contact = $me;
	$this->count = 0;
	$this->any = new Qobject;
        $this->tagger = new Tagger($me);
	$url = $this->search->url();

	// check role type
	$this->scoresOk = $me->privChair || $me->amReviewer()
	    || $Conf->timeAuthorViewReviews();

	// initialize query
	$queryOptions = array("joins" => array());
	if ($this->search->complexSearch($queryOptions)) {
	    if (!($table = $this->search->matchTable()))
		return null;
	    $queryOptions["joins"][] = "join $table on (Paper.paperId=$table.paperId)";
	}

        // collect view data
        $this->_set_viewmap();

	// get field array
	$ncol = 0;
	$this->unfolded = true;
	if (!($fdef = PaperColumn::lookup($fieldId))
	    || !$fdef->prepare($this, $queryOptions, 1))
	    return null;
	$unfolded = $this->unfolded;

	// make query
	$result = $this->_prepareQuery($me, $queryOptions);
	if (!$result)
	    return null;

	// collect row data
        $rows = array();
        while (($row = edb_orow($result)))
            $rows[] = $row;

        // analyze rows (usually noop)
        $fdef->analyze($this, $rows);

        // output field data
	$data = array();
	$name = $fdef->name;
	if (($x = $fdef->header($this, null, 0)))
	    $data["$name.title"] = $x;
	foreach ($rows as $row) {
	    if ($fdef->content_empty($this, $row))
		$c = "";
	    else
		$c = $fdef->content($this, $row);
	    $data["$name.$row->paperId"] = $c;
	}

	return $data;
    }

}
