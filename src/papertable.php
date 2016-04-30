<?php
// papertable.php -- HotCRP helper class for producing paper tables
// HotCRP is Copyright (c) 2006-2016 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

class PaperTable {
    const ENABLESUBMIT = 8;

    public $prow;
    private $all_rrows = null;
    public $viewable_rrows = null;
    var $crows = null;
    private $mycrows;
    private $can_view_reviews = false;
    var $rrow = null;
    var $editrrow = null;
    var $mode;
    private $allreviewslink;

    var $editable;
    private $qreq;
    private $useRequest;
    private $npapstrip = 0;
    private $npapstrip_tag_entry;
    private $allFolded;
    private $matchPreg;
    private $watchCheckbox = WATCH_COMMENT;
    private $entryMatches;
    private $canUploadFinal;
    private $admin;
    private $quit = false;

    static private $textAreaRows = array("title" => 1, "abstract" => 5, "authorInformation" => 5, "collaborators" => 5);

    function __construct($prow, $qreq, $mode = null) {
        global $Conf, $Me;

        $this->prow = $prow;
        $this->admin = $Me->allow_administer($prow);
        $this->qreq = $qreq;

        if ($this->prow == null) {
            $this->mode = "edit";
            return;
        }

        $ms = array();
        if ($Me->can_view_review($prow, null, null)
            || $prow->review_submitted($Me))
            $this->can_view_reviews = $ms["p"] = true;
        else if ($prow->timeWithdrawn > 0 && !$Conf->timeUpdatePaper($prow))
            $ms["p"] = true;
        if ($Me->can_review($prow, null))
            $ms["re"] = true;
        if ($Me->can_view_paper($prow) && $Me->allow_administer($prow))
            $ms["p"] = true;
        if ($prow->has_author($Me)
            && ($Conf->timeFinalizePaper($prow) || $prow->timeSubmitted <= 0))
            $ms["edit"] = true;
        if ($Me->can_view_paper($prow))
            $ms["p"] = true;
        if ($prow->has_author($Me)
            || $Me->allow_administer($prow))
            $ms["edit"] = true;
        if ($prow->myReviewType >= REVIEW_SECONDARY
            || $Me->allow_administer($prow))
            $ms["assign"] = true;
        if (!$mode)
            $mode = $this->qreq->m ? : $this->qreq->mode;
        if ($mode === "pe")
            $mode = "edit";
        else if ($mode === "view" || $mode === "r")
            $mode = "p";
        if ($mode && isset($ms[$mode]))
            $this->mode = $mode;
        else
            $this->mode = key($ms);
        if (isset($ms["re"]) && isset($this->qreq->reviewId))
            $this->mode = "re";

        $this->matchPreg = array();
        $matcher = array();
        if (($l = SessionList::active()) && isset($l->matchPreg) && $l->matchPreg)
            $matcher = self::_combine_match_preg($matcher, $l->matchPreg);
        if (($mpreg = $Conf->session("temp_matchPreg"))) {
            $matcher = self::_combine_match_preg($matcher, $mpreg);
            $Conf->save_session("temp_matchPreg", null);
        }
        foreach ($matcher as $k => $v)
            if (is_string($v) && $v !== "") {
                if ($v[0] !== "{")
                    $v = "{(" . $v . ")}i";
                $this->matchPreg[$k] = $v;
            } else if (is_object($v))
                $this->matchPreg[$k] = $v;
        if (count($this->matchPreg) == 0)
            $this->matchPreg = null;
    }

    private static function _combine_match_preg($m1, $m) {
        if (!is_array($m))
            $m = array("abstract" => $m, "title" => $m,
                       "authorInformation" => $m, "collaborators" => $m);
        foreach ($m as $k => $v)
            if (!isset($m1[$k]) || !$m1[$k])
                $m1[$k] = $v;
        return $m1;
    }

    function initialize($editable, $useRequest) {
        $this->editable = $editable;
        $this->useRequest = $useRequest;
        $this->allFolded = $this->mode === "re" || $this->mode === "assign"
            || ($this->mode !== "edit"
                && (count($this->all_rrows) || count($this->crows)));
    }

    function can_view_reviews() {
        return $this->can_view_reviews;
    }

    static public function do_header($paperTable, $id, $action_mode) {
        global $Conf, $Me;
        $prow = $paperTable ? $paperTable->prow : null;
        $format = 0;

        $t = '<div id="header_page" class="header_page_submission';
        if ($prow && $paperTable && ($list = SessionList::active()))
            $t .= ' has_hotcrp_list" data-hotcrp-list="' . $list->listno;
        $t .= '"><div id="header_page_submission_inner"><h1 class="paptitle';

        if (!$paperTable && !$prow) {
            if (($pid = req("paperId")) && ctype_digit($pid))
                $title = "#$pid";
            else
                $title = "Submission";
            $t .= '">' . $title;
        } else if (!$prow) {
            $title = "New submission";
            $t .= '">' . $title;
        } else {
            $title = "#" . $prow->paperId;
            $viewable_tags = $prow->viewable_tags($Me);
            if ($viewable_tags || $Me->can_view_tags($prow)) {
                $t .= ' has_hotcrp_tag_classes';
                if (($color = TagInfo::color_classes($viewable_tags)))
                    $t .= ' ' . $color;
            }
            $t .= '"><a class="q" href="' . hoturl("paper", array("p" => $prow->paperId, "ls" => null))
                . '"><span class="taghl"><span class="pnum">' . $title . '</span>'
                . ' &nbsp; ';

            $highlight_text = null;
            $title_matches = 0;
            if ($paperTable && $paperTable->matchPreg
                && ($highlight = get($paperTable->matchPreg, "title")))
                $highlight_text = Text::highlight($prow->title, $highlight, $title_matches);

            if (!$title_matches && ($format = $prow->title_format()))
                $t .= '<span class="ptitle need-format" data-format="' . $format . '">';
            else
                $t .= '<span class="ptitle">';
            if ($highlight_text)
                $t .= $highlight_text;
            else
                $t .= htmlspecialchars($prow->title);

            $t .= '</span></span></a>';
            if ($viewable_tags && TagInfo::has_badges()) {
                $tagger = new Tagger;
                $t .= $tagger->unparse_badges_html($viewable_tags);
            }
        }

        $t .= '</h1></div></div>';
        if ($paperTable && $prow)
            $t .= $paperTable->_paptabBeginKnown();

        $Conf->header($title, $id, actionBar($action_mode, $prow), $t);
        if ($format)
            $Conf->echoScript("render_text.on_page()");
    }

    private function abstract_foldable($abstract) {
        return strlen($abstract) > 190;
    }

    private function echoDivEnter() {
        global $Conf, $Me;

        $folds = ["a" => true, "p" => $this->allFolded, "b" => $this->allFolded, "t" => $this->allFolded];
        foreach (["a", "p", "b", "t"] as $k)
            if (!$Conf->session("foldpaper$k", 1))
                $folds[$k] = false;

        // if highlighting, automatically unfold abstract/authors
        if ($this->prow && $folds["b"]) {
            $abstract = $this->entryData("abstract");
            if ($this->entryMatches || !$this->abstract_foldable($abstract))
                $folds["b"] = false;
        }
        if ($this->matchPreg && $this->prow && $folds["a"]) {
            $this->entryData("authorInformation");
            if ($this->entryMatches)
                $folds["a"] = $folds["p"] = false;
        }

        // collect folders
        $folders = array();
        if ($this->prow) {
            $ever_viewable = $Me->can_view_authors($this->prow, true);
            $viewable = $ever_viewable && $Me->can_view_authors($this->prow, false);
            if ($ever_viewable && !$viewable)
                $folders[] = $folds["a"] ? "fold8c" : "fold8o";
            if ($ever_viewable && $this->allFolded)
                $folders[] = $folds["p"] ? "fold9c" : "fold9o";
        }
        $folders[] = $folds["b"] ? "fold6c" : "fold6o";
        $folders[] = $folds["t"] ? "fold5c" : "fold5o";

        // echo div
        echo '<div id="foldpaper" class="', join(" ", $folders), '">';
    }

    private function echoDivExit() {
        echo "</div>";
    }

    private function editable_papt($what, $name, $extra = array()) {
        global $Error;
        if (($id = get($extra, "id")))
            $c = '<div class="papeg papg_' . $id . '"><div id="' . $id . '" ';
        else
            $c = '<div class="papeg"><div ';
        $c .= 'class="papet';
        if (isset($Error[$what]))
            $c .= " error";
        return $c . '"><span class="papfn">' . $name
            . '</span><hr class="c" /></div>';
    }

    private function papt($what, $name, $extra = array()) {
        global $Error, $Conf;
        $type = defval($extra, "type", "pav");
        $fold = defval($extra, "fold", false);
        $editfolder = defval($extra, "editfolder", false);
        if ($fold || $editfolder) {
            $foldnum = defval($extra, "foldnum", 0);
            if (isset($extra["foldsession"]))
                $foldnumarg = ",{n:" . (+$foldnum) . ",s:'" . $extra["foldsession"] . "'}";
            else
                $foldnumarg = $foldnum ? ",$foldnum" : "";
        }

        if (get($extra, "type") === "ps")
            list($divclass, $hdrclass) = array("pst", "psfn");
        else
            list($divclass, $hdrclass) = array("pavt", "pavfn");

        $c = "<div class=\"$divclass";
        if (isset($Error[$what]))
            $c .= " error";
        if (($fold || $editfolder) && !get($extra, "float"))
            $c .= " childfold\" onclick=\"return foldup(this,event$foldnumarg)";
        $c .= "\"><span class=\"$hdrclass\">";
        if (!$fold) {
            $n = (is_array($name) ? $name[0] : $name);
            if ($editfolder)
                $c .= "<a class=\"q fn\" "
                    . "href=\"" . selfHref(array("atab" => $what))
                    . "\" onclick=\"return foldup(this,event$foldnumarg)\">"
                    . $n . "</a><span class=\"fx\">" . $n . "</span>";
            else
                $c .= $n;
        } else {
            $c .= '<a class="q" href="#" onclick="return foldup(this,event'
                . $foldnumarg . ')"';
            if (($title = defval($extra, "foldtitle")))
                $c .= ' title="' . $title . '"';
            $c .= '>' . expander(null, $foldnum);
            if (!is_array($name))
                $name = array($name, $name);
            if ($name[0] !== $name[1])
                $c .= '<span class="fn' . $foldnum . '">' . $name[1] . '</span><span class="fx' . $foldnum . '">' . $name[0] . '</span>';
            else
                $c .= $name[0];
            $c .= '</a>';
        }
        $c .= "</span>";
        if ($editfolder) {
            $c .= "<span class=\"pstedit fn\">"
                . "<a class=\"xx hottooltip\" href=\"" . selfHref(array("atab" => $what))
                . "\" onclick=\"return foldup(this,event$foldnumarg)\" data-hottooltip=\"Edit\">"
                . "<span class=\"psteditimg\">"
                . Ht::img("edit.png", "[Edit]", "bmabs")
                . "</span>&nbsp;<u class=\"x\">Edit</u></a></span>";
        }
        if (isset($extra["float"]))
            $c .= $extra["float"];
        $c .= "<hr class=\"c\" /></div>";
        return $c;
    }

    private function editable_textarea($fieldName) {
        if ($this->useRequest && isset($this->qreq[$fieldName]))
            $text = $this->qreq[$fieldName];
        else
            $text = $this->prow ? $this->prow->$fieldName : "";
        return Ht::textarea($fieldName, $text, ["class" => "papertext", "rows" => self::$textAreaRows[$fieldName], "cols" => 60, "onchange" => "hiliter(this)", "spellcheck" => $fieldName === "abstract" ? "true" : null]);
    }

    private function entryData($fieldName, $table_type = false) {
        $this->entryMatches = 0;
        $text = $this->prow ? $this->prow->$fieldName : "";
        if ($this->matchPreg && isset(self::$textAreaRows[$fieldName])
            && isset($this->matchPreg[$fieldName]))
            $text = Text::highlight($text, $this->matchPreg[$fieldName], $this->entryMatches);
        else
            $text = htmlspecialchars($text);
        return $table_type === "col" ? nl2br($text) : $text;
    }

    private function echo_editable_title() {
        echo $this->editable_papt("title", "Title"),
            '<div class="papev">', $this->editable_textarea("title"), "</div></div>\n\n";
    }

    static function pdfStamps($data) {
        global $Conf;

        $t = array();
        $tm = defval($data, "timestamp", defval($data, "timeSubmitted", 0));
        if ($tm > 0)
            $t[] = "<span class='nowrap hottooltip' data-hottooltip='Time of most recent update'>" . Ht::img("_.gif", "Updated", array("class" => "timestamp12")) . " " . $Conf->printableTimestamp($tm) . "</span>";
        $sha1 = defval($data, "sha1");
        if ($sha1)
            $t[] = "<span class='nowrap hottooltip' data-hottooltip='SHA-1 checksum'>" . Ht::img("_.gif", "SHA-1", array("class" => "checksum12")) . " " . bin2hex($sha1) . "</span>";
        if (count($t) > 0)
            return "<span class='hint'>" . join(" <span class='barsep'>·</span> ", $t) . "</span>";
        else
            return "";
    }

    private function paptabDownload() {
        global $Conf, $Me;
        assert(!$this->editable);
        $prow = $this->prow;
        $out = array();

        // download
        if ($Me->can_view_pdf($prow)) {
            $pdfs = array();

            $dprefix = "";
            $dtype = $prow->finalPaperStorageId > 1 ? DTYPE_FINAL : DTYPE_SUBMISSION;
            if (($data = $prow->document($dtype))) {
                if (($stamps = self::pdfStamps($data)))
                    $stamps = "<span class='sep'></span>" . $stamps;
                $dname = $dtype == DTYPE_FINAL ? "Final version" : "Submission";
                $pdfs[] = $dprefix . documentDownload($data, "dlimg", '<span class="pavfn">' . $dname . '</span>') . $stamps;
            }

            foreach ($prow ? $prow->options() : [] as $id => $ov)
                if ($ov->option->display() === PaperOption::DISP_SUBMISSION
                    && $ov->option->has_document()
                    && $Me->can_view_paper_option($prow, $ov->option)) {
                    foreach ($ov->documents($prow) as $d) {
                        $name = '<span class="pavfn">' . htmlspecialchars($ov->option->name) . '</span>';
                        if ($ov->option->type === "attachments")
                            $name .= "/" . htmlspecialchars($d->unique_filename);
                        $pdfs[] = documentDownload($d, empty($pdfs) ? "dlimg" : "dlimgsp", $name);
                    }
                }

            if ($prow->finalPaperStorageId > 1
                && $prow->paperStorageId > 1) {
                $doc = (object) array("paperId" => $prow->paperId,
                                      "mimetype" => null,
                                      "documentType" => DTYPE_SUBMISSION);
                $pdfs[] = "<small><a class='u' href=\""
                    . HotCRPDocument::url($doc)
                    . "\">Submission version</a></small>";
            }

            foreach ($pdfs as $p)
                $out[] = '<p class="xd">' . $p . '</p>';
        }

        // conflicts
        if ($Me->isPC && !$prow->has_conflict($Me)
            && $Conf->timeUpdatePaper($prow) && $this->mode !== "assign"
            && $this->mode !== "contact")
            $out[] = Ht::xmsg("warning", 'The authors still have <a href="' . hoturl("deadlines") . '">time</a> to make changes.');

        echo join("", $out);
    }

    private function echo_editable_complete($storageId) {
        global $Conf, $Opt;
        $prow = $this->prow;
        if ($this->useRequest)
            $checked = !!$this->qreq->submitpaper;
        else if ($Conf->setting("sub_freeze"))
            $checked = $prow && $prow->timeSubmitted > 0;
        else
            $checked = !$prow || $storageId <= 1 || $prow->timeSubmitted > 0;
        echo "<div id='foldisready' class='",
            (($prow && $storageId > 1) || get($Opt, "noPapers") ? "foldo" : "foldc"),
            "'><table class='fx'><tr><td class='nowrap'>",
            Ht::checkbox_h("submitpaper", 1, $checked, array("id" => "paperisready")), "&nbsp;";
        if ($Conf->setting('sub_freeze'))
            echo "</td><td>", Ht::label("<strong>The submission is complete.</strong>"),
                "</td></tr><tr><td></td><td><small>You must complete your submission before the deadline or it will not be reviewed. Completed submissions are frozen and cannot be changed further.</small>";
        else
            echo Ht::label("The submission is ready for review.");
        echo "</td></tr></table></div>\n";
        $Conf->footerScript("jQuery(function(){var x=\$\$(\"paperUpload\");if(x&&x.value)fold(\"isready\",0)})");
    }

    private function echo_editable_document(PaperOption $docx, $storageId, $flags) {
        global $Conf, $Me, $Opt;

        $prow = $this->prow;
        $docclass = new HotCRPDocument($docx->id, $docx);
        $documentType = $docx->id;
        $optionType = $docx->type;
        $main_submission = ($documentType == DTYPE_SUBMISSION || $documentType == DTYPE_FINAL);
        $banal = $Conf->setting("sub_banal")
            && ($optionType === null || $optionType === "pdf")
            && $main_submission;

        $filetypes = array();
        $accepts = array();
        if ($main_submission
            && (get($Opt, "noPapers") === 1 || get($Opt, "noPapers") === true)
            && $documentType == DTYPE_SUBMISSION)
            return;

        $accepts = $docclass->mimetypes();
        if (count($accepts))
            echo $this->editable_papt($docx->abbr, htmlspecialchars($docx->name) . ' <span class="papfnh">(' . htmlspecialchars(Mimetype::description($accepts)) . ", max " . ini_get("upload_max_filesize") . "B)</span>");
        if ($docx->description)
            echo '<div class="paphint">', $docx->description, "</div>";
        echo '<div class="papev">';
        if ($optionType)
            echo Ht::hidden("has_opt$docx->id", 1);

        // current version, if any
        $doc = null;
        $inputid = ($optionType ? "opt" . $documentType : "paperUpload");
        if ($prow && $Me->can_view_pdf($prow) && $storageId > 1
            && (($doc = $prow->document($documentType, $storageId)))) {
            echo "<table id='current_$inputid'><tr>",
                "<td class='nowrap'>", documentDownload($doc), "</td>";
            if ($doc->mimetype === "application/pdf" && $banal)
                echo "<td><span class='sep'></span></td><td><a href='#' onclick='return docheckformat($documentType)'>Check format</a></td>";
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
                $uploader .= ";form.submitpaper.disabled=false";
            $uploader .= "' />";
            if ($doc && $optionType)
                $uploader .= " <span class='barsep'>·</span> "
                    . "<a id='remover_$inputid' href='#remover_$inputid' onclick='return doremovedocument(this)'>Delete</a>";
            if ($doc)
                $uploader .= "</span>";
            if ($doc && $optionType)
                $uploader .= "<span class='fn'><em>Marked for deletion</em></span>";
            if ($doc)
                $uploader .= "</div>";
        }

        if ($prow && $storageId > 1 && $banal
            && defval($prow, "mimetype", "application/pdf") === "application/pdf") {
            echo "<div id='foldcheckformat$documentType' class='foldc'><div id='checkformatform${documentType}result' class='fx'><div class='xmsg xinfo'>Checking format, please wait (this can take a while)...</div></div></div>";
            $Conf->footerHtml(Ht::form_div(hoturl_post("paper", "p=$prow->paperId&amp;dt=$documentType"), array("id" => "checkformatform$documentType", "class" => "fold7c", "onsubmit" => "return Miniajax.submit('checkformatform$documentType')"))
                              . Ht::hidden("checkformat", 1)
                              . "</div></form>");
        }

        if ($documentType == DTYPE_FINAL)
            echo Ht::hidden("submitpaper", 1);

        echo $uploader, "</div>";
    }

    private function echo_editable_submission() {
        $flags = 0;
        if (!$this->prow || $this->prow->size == 0)
            $flags |= PaperTable::ENABLESUBMIT;
        if ($this->canUploadFinal)
            $this->echo_editable_document(PaperOption::find_document(DTYPE_FINAL), $this->prow ? $this->prow->finalPaperStorageId : 0, $flags);
        else
            $this->echo_editable_document(PaperOption::find_document(DTYPE_SUBMISSION), $this->prow ? $this->prow->paperStorageId : 0, $flags);
        echo "</div>\n\n";
    }

    private function echo_editable_abstract() {
        $title = "Abstract";
        if (opt("noAbstract") === 2)
            $title .= ' <span class="papfnh">(optional)</span>';
        echo $this->editable_papt("abstract", $title),
            '<div class="papev abstract">';
        if (($f = Conf::format_info($this->prow ? $this->prow->paperFormat : null))
            && ($t = get($f, "description")))
            echo $t;
        echo $this->editable_textarea("abstract"),
            "</div></div>\n\n";
    }

    private function paptabAbstract() {
        global $Conf, $Opt;
        $text = $this->entryData("abstract");
        if (trim($text) === "" && get($Opt, "noAbstract"))
            return;
        $extra = [];
        if ($this->allFolded && $this->abstract_foldable($text))
            $extra = ["fold" => "paper", "foldnum" => 6,
                      "foldsession" => "foldpaperb",
                      "foldtitle" => "Toggle full abstract"];
        echo '<div class="paptab"><div class="paptab_abstract"><div class="pg">',
            $this->papt("abstract", "Abstract", $extra),
            '<div class="pavb abstract"><div class="paptext format0';
        if ($this->prow && !$this->entryMatches
            && ($format = $this->prow->format_of($text))) {
            echo ' need-format" data-format="', $format;
            $Conf->footerScript('$(render_text.on_page)', 'render_on_page');
        }
        echo '">', $text, "</div>";
        if ($extra)
            echo '<div class="fn6 textdiv-shade"></div>',
                '<div class="fn6 textdiv-expander"><a class="x" href="#" onclick="return foldup(this,event,{n:6,s:\'foldpaperb\'})">[more]</a></div>';
        echo "</div></div></div></div>\n";
        if ($extra)
            $Conf->echoScript("render_text.on_page()");
    }

    private static function editable_authors_tr($n, $name, $email, $aff) {
        return '<tr><td class="rxcaption">' . $n . '.</td><td class="lentry">'
            . Ht::entry("auname$n", $name, array("size" => "35", "onchange" => "author_change(this)", "placeholder" => "Name", "style" => "margin:2px 0.5em 2px 0")) . ' '
            . Ht::entry("auemail$n", $email, array("size" => "30", "onchange" => "author_change(this)", "placeholder" => "Email", "style" => "margin:2px 0.5em 2px 0")) . ' '
            . Ht::entry("auaff$n", $aff, array("size" => "32", "onchange" => "author_change(this)", "placeholder" => "Affiliation", "style" => "margin:2px 0.5em 2px 0")) . "</td>"
            . '<td class="nw"><a href="#" class="qx row_up" onclick="return author_change(this,-1)" tabindex="-1">&#x25b2;</a><a href="#" class="qx row_down" onclick="return author_change(this,1)" tabindex="-1">&#x25bc;</a><a href="#" class="qx row_kill" onclick="return author_change(this,Infinity)" tabindex="-1">x</a></td></tr>';
    }

    private function echo_editable_authors() {
        global $Conf;

        echo $this->editable_papt("authorInformation", "Authors"),
            "<div class='paphint'>List the authors one per line, including email addresses and affiliations.";
        if ($Conf->submission_blindness() == Conf::BLIND_ALWAYS)
            echo " Submission is blind, so reviewers will not be able to see author information.";
        echo " Any author with an account on this site can edit the submission.</div>",
            '<div class="papev"><table id="auedittable" class="auedittable">',
            '<tbody data-last-row-blank="true" data-min-rows="5" data-row-template="',
            htmlspecialchars(self::editable_authors_tr('$', "", "", "")), '">';

        $blankAu = array("", "", "", "");
        if ($this->useRequest) {
            for ($n = 1; $this->qreq["auname$n"] || $this->qreq["auemail$n"] || $this->qreq["auaff$n"]; ++$n)
                echo self::editable_authors_tr($n, (string) $this->qreq["auname$n"], (string) $this->qreq["auemail$n"], (string) $this->qreq["auaff$n"]);
        } else {
            $aulist = $this->prow ? $this->prow->author_list() : array();
            for ($n = 1; $n <= count($aulist); ++$n) {
                $au = $aulist[$n - 1];
                if ($au->firstName && $au->lastName && !preg_match('@^\s*(v[oa]n\s+|d[eu]\s+)?\S+(\s+jr.?|\s+sr.?|\s+i+)?\s*$@i', $au->lastName))
                    $auname = $au->lastName . ", " . $au->firstName;
                else
                    $auname = $au->name();
                echo self::editable_authors_tr($n, $auname, $au->email, $au->affiliation);
            }
        }
        do {
            echo self::editable_authors_tr($n, "", "", "");
        } while (++$n <= 5);
        echo "</tbody></table></div></div>\n\n";
    }

    private function authorData($table, $type, $viewAs = null, $prefix = "") {
        global $Conf;
        if ($this->matchPreg && isset($this->matchPreg["authorInformation"]))
            $highpreg = $this->matchPreg["authorInformation"];
        else
            $highpreg = false;
        $this->entryMatches = 0;

        $names = array();
        if ($type === "last") {
            foreach ($table as $au) {
                $n = Text::abbrevname_text($au);
                $names[] = Text::highlight($n, $highpreg, $nm);
                $this->entryMatches += $nm;
            }
            return $prefix . join(", ", $names);

        } else {
            foreach ($table as $au) {
                $nm1 = $nm2 = $nm3 = 0;
                $n = $e = $t = "";
                $n = trim(Text::highlight("$au->firstName $au->lastName", $highpreg, $nm1));
                if ($au->email !== "") {
                    $e = Text::highlight($au->email, $highpreg, $nm2);
                    $e = '&lt;<a href="mailto:' . htmlspecialchars($au->email)
                        . '">' . $e . '</a>&gt;';
                }
                $t = ($n === "" ? $e : $n);
                if ($au->affiliation !== "")
                    $t .= ' <span class="auaff">(' . Text::highlight($au->affiliation, $highpreg, $nm3) . ')</span>';
                if ($n !== "" && $e !== "")
                    $t .= " " . $e;
                $this->entryMatches += $nm1 + $nm2 + $nm3;
                $t = trim($t);
                if ($au->email !== "" && $au->contactId
                    && $viewAs !== null && $viewAs->email !== $au->email && $viewAs->privChair)
                    $t .= " <a href=\"" . selfHref(array("actas" => $au->email)) . "\">" . Ht::img("viewas.png", "[Act as]", array("title" => "Act as " . Text::name_text($au))) . "</a>";
                $names[] = '<p class="odname">' . $prefix . $t . '</p>';
                $prefix = "";
            }
            return join("\n", $names);
        }
    }

    private function _analyze_authors() {
        global $Conf;
        // clean author information
        $aulist = $this->prow->author_list();

        // find contact author information, combine with author table
        $result = $Conf->qe("select firstName, lastName, email, '' as affiliation, contactId
                from ContactInfo join PaperConflict using (contactId)
                where paperId=" . $this->prow->paperId . " and conflictType>=" . CONFLICT_AUTHOR);
        $contacts = array();
        while (($row = edb_orow($result))) {
            $match = -1;
            for ($i = 0; $match < 0 && $i < count($aulist); ++$i)
                if (strcasecmp($aulist[$i]->email, $row->email) == 0)
                    $match = $i;
            if (($row->firstName !== "" || $row->lastName !== "") && $match < 0) {
                $contact_n = $row->firstName . " " . $row->lastName;
                $contact_preg = str_replace("\\.", "\\S*", "{\\b" . preg_quote($row->firstName) . "\\b.*\\b" . preg_quote($row->lastName) . "\\b}i");
                for ($i = 0; $match < 0 && $i < count($aulist); ++$i) {
                    $f = $aulist[$i]->firstName;
                    $l = $aulist[$i]->lastName;
                    if (($f !== "" || $l !== "") && $aulist[$i]->email === "") {
                        $author_n = $f . " " . $l;
                        $author_preg = str_replace("\\.", "\\S*", "{\\b" . preg_quote($f) . "\\b.*\\b" . preg_quote($l) . "\\b}i");
                        if (preg_match($contact_preg, $author_n)
                            || preg_match($author_preg, $contact_n))
                            $match = $i;
                    }
                }
            }
            if ($match >= 0) {
                if ($aulist[$match]->email === "")
                    $aulist[$match]->email = $row->email;
                $aulist[$match]->contactId = (int) $row->contactId;
            } else {
                Contact::set_sorter($row);
                $contacts[] = $row;
            }
        }

        uasort($contacts, "Contact::compare");
        return array($aulist, $contacts);
    }

    private function paptabAuthors($skip_contacts) {
        global $Conf, $Me, $Error;

        $viewable = $Me->can_view_authors($this->prow, false);
        if (!$viewable && !$Me->can_view_authors($this->prow, true)) {
            echo '<div class="pg">',
                $this->papt("authorInformation", "Authors"),
                '<div class="pavb"><i>Hidden for blind review</i></div>',
                "</div>\n\n";
            return;
        }

        // clean author information
        list($aulist, $contacts) = $this->_analyze_authors();

        // "author" or "authors"?
        $auname = pluralx(count($aulist), "Author");
        if (!$viewable)
            $auname = "$auname (deblinded)";

        // header with folding
        echo '<div class="pg">',
            '<div class="pavt childfold', (get($Error, "authorInformation") ? " error" : ""),
            '" onclick="return aufoldup(event)">',
            '<span class="pavfn">';
        if (!$viewable || $this->allFolded)
            echo '<a class="q" href="#" onclick="return aufoldup(event)" title="Toggle author display">';
        if (!$viewable)
            echo '<span class="fn8">Authors</span><span class="fx8">';
        if ($this->allFolded)
            echo expander(null, 9);
        else if (!$viewable)
            echo expander(false);
        echo $auname;
        if (!$viewable)
            echo '</span>';
        if (!$viewable || $this->allFolded)
            echo '</a>';
        echo '</span><hr class="c" /></div>';

        // contents
        $inauthors = "";
        if ($viewable && $Conf->submission_blindness() == Conf::BLIND_OPTIONAL && $this->prow->blind)
            $inauthors = "[blind] ";
        echo '<div class="pavb">';
        if (!$viewable)
            echo '<a class="q fn8" href="#" onclick="return aufoldup(event)" title="Toggle author display">',
                '+&nbsp;<i>Hidden for blind review</i>',
                '</a><div class="fx8">';
        if ($this->allFolded)
            echo '<div class="fn9">',
                $this->authorData($aulist, "last", null, $inauthors),
                ' <a href="#" onclick="return aufoldup(event)">[details]</a>',
                '</div><div class="fx9">';
        echo $this->authorData($aulist, "col", $Me, $inauthors);
        if ($this->allFolded)
            echo '</div>';
        if (!$viewable)
            echo '</div>';
        echo "</div></div>\n\n";

        // contacts
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
        $topicdata = $this->prow->unparse_topics_html(false, $Me);
        $xoptionhtml = array();
        $optionhtml = array();
        $ndocuments = 0;
        $nfolded = 0;

        foreach ($this->prow->options() as $oa) {
            $o = $oa->option;
            if (($o->display() === PaperOption::DISP_SUBMISSION
                 && $o->has_document()
                 && $Me->can_view_paper_option($this->prow, $o))
                || (!$showAllOptions
                    && !$Me->can_view_paper_option($this->prow, $o))
                || $o->display() < 0)
                continue;

            // create option display value
            $show_on = true;
            $on = htmlspecialchars($o->name);
            $ox = "";
            if ($o->type === "checkbox" && $oa->value)
                $ox = true;
            else if ($o->has_selector()
                     && ($otext = get($o->selector, $oa->value)))
                $ox = htmlspecialchars($otext);
            else if ($o->type === "numeric"
                     && $oa->value != "" && $oa->value != "0")
                $ox = htmlspecialchars($oa->value);
            else if ($o->type === "text"
                     && $oa->data != "") {
                $ox = htmlspecialchars($oa->data);
                if ($o->display_space > 1)
                    $ox = nl2br($ox);
                $ox = Ht::link_urls($ox);
            } else if ($o->type === "attachments") {
                $ox = array();
                foreach ($oa->documents($this->prow) as $doc)
                    $ox[] = documentDownload($doc, "sdlimg", htmlspecialchars($doc->unique_filename));
                $ox = join("<br />\n", $ox);
            } else if ($o->is_document() && $oa->value > 1) {
                $show_on = false;
                if ($o->type === "pdf")
                    /* make fake document */
                    $doc = (object) array("paperId" => $this->prow->paperId, "mimetype" => "application/pdf", "documentType" => $o->id);
                else
                    $doc = $this->prow->document($o->id, $oa->value);
                if ($doc)
                    $ox = documentDownload($doc, "sdlimg", $on);
            }
            if ($ox === "")
                continue;

            // display it
            $folded = $showAllOptions && !$Me->can_view_paper_option($this->prow, $o, false);
            if ($o->display() !== PaperOption::DISP_TOPICS) {
                $x = '<div class="pgsm' . ($folded ? " fx8" : "") . '">'
                    . '<div class="pavt"><span class="pavfn">'
                    . ($show_on ? $on : $ox) . "</span>"
                    . '<hr class="c" /></div>';
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
                if ($o->has_document())
                    ++$ndocuments;
            }
        }

        if (count($xoptionhtml))
            echo "<div class='pg'>", join("", $xoptionhtml), "</div>\n";

        if ($topicdata !== "" || count($optionhtml)) {
            $infotypes = array();
            if ($ndocuments > 0)
                $infotypes[] = "Attachments";
            if (count($optionhtml) != $ndocuments)
                $infotypes[] = "Options";
            $options_name = commajoin($infotypes);
            if ($topicdata !== "")
                array_unshift($infotypes, "Topics");
            $tanda = commajoin($infotypes);

            if ($this->allFolded) {
                $extra = array("fold" => "paper", "foldnum" => 5,
                               "foldsession" => "foldpapert",
                               "foldtitle" => "Toggle " . strtolower($tanda));
                $eclass = " fx5";
            } else {
                $extra = null;
                $eclass = "";
            }

            if ($topicdata !== "") {
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
        global $Me, $Conf;
        echo $this->editable_papt("contactAuthor", "Contact"),
            '<div class="paphint">You can add more contacts after you register the submission.</div>',
            '<div class="papev">';
        $name = $this->useRequest ? trim((string) $this->qreq->newcontact_name) : "";
        $email = $this->useRequest ? trim((string) $this->qreq->newcontact_email) : "";
        echo '<table><tr><td class="lcaption">Add</td>',
            '<td></td><td>',
            Ht::entry('newcontact_name', $name,
                      array("id" => "newcontact_name", "size" => 30,
                            "onchange" => "hiliter(this)", "placeholder" => "Name")),
            '&nbsp;&nbsp;',
            Ht::entry('newcontact_email', $email,
                      array("id" => "newcontact_email", "size" => 20,
                            "onchange" => "hiliter(this)", "placeholder" => "Email")),
            '</td></tr></table>';
        echo "</div></div>\n\n";
    }

    private function editable_contact_author($always_unfold = false) {
        global $Conf, $Me, $Error;
        $paperId = $this->prow->paperId;
        list($aulist, $contacts) = $this->_analyze_authors();

        $cerror = get($Error, "contactAuthor") || get($Error, "contacts");
        $open = $cerror || $always_unfold
            || ($this->useRequest && $this->qreq->setcontacts == 2);
        echo '<div id="foldcontactauthors" class="papeg ',
            ($open ? "foldo" : "foldc"),
            '"><div class="papet childfold fn0" ',
            "onclick=\"\$\$('setcontacts').value=2;return foldup(this,event)\"",
            '><span class="papfn"><a class="qq" href="#" ',
            "onclick=\"\$\$('setcontacts').value=2;return foldup(this,event)\"",
            ' title="Edit contacts">', expander(true), 'Contacts</a></span><hr class="c" /></div>',
            '<div class="papet fx0',
            ($cerror ? " error" : ""),
            '"><span class="papfn">Contacts</span><hr class="c" /></div>';

        // Non-editable version
        echo '<div class="papev fn0">';
        foreach ($aulist as $au)
            if ($au->contactId) {
                echo '<span class="autblentry_long">', Text::user_html($au);
                if ($Me->privChair && $au->contactId != $Me->contactId)
                    echo '&nbsp;', actas_link($au->email, $au);
                echo '</span><br />';
            }
        foreach ($contacts as $au) {
            echo '<span class="autblentry_long">', Text::user_html($au);
            if ($Me->privChair && $au->contactId != $Me->contactId)
                echo '&nbsp;', actas_link($au);
            echo '</span><br />';
        }
        echo '</div>';

        // Editable version
        echo '<div class="paphint fx0">',
            'Contacts are HotCRP users who can edit paper information and view reviews. Paper authors with HotCRP accounts are always contacts, but you can add additional contacts who aren’t in the author list or create accounts for authors who haven’t yet logged in.',
            '</div>';
        echo '<div class="papev fx0">';
        echo '<table>';
        $title = "Authors";
        foreach ($aulist as $au) {
            if (!$au->contactId && (!$au->email || !validate_email($au->email)))
                continue;
            $control = "contact_" . html_id_encode($au->email);
            $checked = $this->useRequest ? !!$this->qreq[$control] : $au->contactId;
            echo '<tr><td class="lcaption">', $title, '</td><td>';
            if ($au->contactId)
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
            $checked = $this->useRequest ? $this->qreq[$control] : true;
            echo '<tr><td class="lcaption">', $title, '</td>',
                '<td>', Ht::checkbox($control, Text::name_text($au), $checked, array("onclick" => "hiliter(this)")),
                '&nbsp;</td><td>', Ht::label(Text::user_html($au)), '</td>',
                '</tr>';
            $title = "";
        }
        $checked = $this->useRequest ? $this->qreq->newcontact : true;
        $name = $this->useRequest ? trim((string) $this->qreq->newcontact_name) : "";
        $email = $this->useRequest ? trim((string) $this->qreq->newcontact_email) : "";
        echo '<tr><td class="lcaption">Add</td>',
            '<td></td><td>',
            Ht::entry('newcontact_name', $name,
                      array("id" => "newcontact_name", "size" => 30,
                            "onchange" => "hiliter(this)", "placeholder" => "Name",
                            "class" => $cerror ? "error" : null)),
            '&nbsp;&nbsp;',
            Ht::entry('newcontact_email', $email,
                      array("id" => "newcontact_email", "size" => 20,
                            "onchange" => "hiliter(this)", "placeholder" => "Email",
                            "class" => $cerror ? "error" : null)),
            '</td></tr>';
        echo '</table>', Ht::hidden("setcontacts", $open ? 2 : 1, array("id" => "setcontacts")), "</div></div>\n\n";
    }

    private function echo_editable_anonymity() {
        global $Conf;
        $blind = ($this->useRequest ? !!$this->qreq->blind : (!$this->prow || $this->prow->blind));
        assert(!!$this->editable);
        echo $this->editable_papt("blind", Ht::checkbox_h("blind", 1, $blind)
                                  . "&nbsp;" . Ht::label("Anonymous submission")),
            '<div class="paphint">', htmlspecialchars(Conf::$gShortName), " allows either anonymous or named submission.  Check this box to submit anonymously (reviewers won’t be shown the author list).  Make sure you also remove your name from the paper itself!</div>\n",
            "</div>\n\n";
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
        conflicts of interest. ", $Conf->message_html("conflictdef"), "
        Be sure to include conflicted <a href='", hoturl("users", "t=pc"), "'>PC members</a>.
        We use this information when assigning PC and external reviews.";
        echo "  List one conflict per line.  For example: &ldquo;<samp>Jelena Markovic (EPFL)</samp>&rdquo; or, for a whole institution, &ldquo;<samp>EPFL</samp>&rdquo;.</div>",
            '<div class="papev">',
            $this->editable_textarea("collaborators"),
            "</div></div>\n\n";
    }

    private function _papstripBegin($foldid = null, $folded = null, $extra = null) {
        global $Conf, $Me;
        if (!$this->npapstrip) {
            echo '<div class="pspcard_container"><div class="pspcard">',
                '<div class="pspcard_body"><div class="pspcard_fold">',
                '<div style="float:right;margin-left:1em"><span class="psfn">More ', expander(true), '</span></div>';

            if ($this->prow && ($viewable = $this->prow->viewable_tags($Me))) {
                $tagger = new Tagger;
                $color = TagInfo::color_classes($viewable);
                echo '<div class="', trim("has_hotcrp_tag_classes pscopen $color"), '">',
                    '<span class="psfn">Tags:</span> ',
                    $tagger->unparse_and_link($viewable, $this->prow->all_tags_text(), false),
                    '</div>';
            }

            echo '<hr class="c" /></div><div class="pspcard_open">';
            $Conf->footerScript('$(".pspcard_fold").click(function(e){$(".pspcard_fold").hide();$(".pspcard_open").show();e.preventDefault();return false})');
        }
        echo '<div';
        if ($foldid)
            echo " id=\"fold$foldid\"";
        echo ' class="psc';
        if (!$this->npapstrip)
            echo " psc1";
        if ($foldid)
            echo " fold", ($folded ? "c" : "o");
        if (is_string($extra))
            echo " " . $extra;
        else if (is_array($extra))
            foreach ($extra as $k => $v)
                echo "\" $k=\"$v";
        echo '">';
        ++$this->npapstrip;
    }

    private function papstripCollaborators() {
        global $Conf;
        if (!$Conf->setting("sub_collab") || !$this->prow->collaborators
            || strcasecmp(trim($this->prow->collaborators), "None") == 0)
            return;
        $name = $Conf->setting("sub_pcconf") ? "Other conflicts" : "Potential conflicts";
        $fold = $Conf->session("foldpscollab", 1) ? 1 : 0;

        $data = $this->entryData("collaborators", "col");
        if ($this->entryMatches || !$this->allFolded)
            $fold = 0;

        $this->_papstripBegin("pscollab", $fold, ["data-fold-session" => "foldpscollab"]);
        echo $this->papt("collaborators", $name,
                         ["type" => "ps", "fold" => "pscollab", "folded" => $fold]),
            "<div class='psv'><div class='fx'>", $data,
            "</div></div></div>\n\n";
    }

    private function echo_editable_topics() {
        global $Conf;
        assert(!!$this->editable);
        $topicMode = (int) $this->useRequest;
        if (($topicTable = topicTable($this->prow, $topicMode))) {
            echo $this->editable_papt("topics", "Topics"),
                '<div class="paphint">Select any topics that apply to your paper.</div>',
                '<div class="papev">',
                Ht::hidden("has_topics", 1),
                $topicTable,
                "</div></div>\n\n";
        }
    }

    private function editable_attachments($o) {
        echo $this->editable_papt($o->id, htmlspecialchars($o->name)
                                  . " <span class='papfnh'>(max " . ini_get("upload_max_filesize") . "B per file)</span>");
        if ($o->description)
            echo "<div class='paphint'>", $o->description, "</div>";
        echo '<div class="papev">', Ht::hidden("has_opt$o->id", 1);
        if (($prow = $this->prow) && ($optx = $prow->option($o->id))) {
            $docclass = new HotCRPDocument($o->id, $o);
            foreach ($optx->documents($prow) as $doc) {
                $oname = "opt" . $o->id . "_" . $doc->paperStorageId;
                echo "<div id='removable_$oname' class='foldo'><table id='current_$oname'><tr>",
                    "<td class='nowrap'>", documentDownload($doc, "dlimg", htmlspecialchars($doc->unique_filename)), "</td>",
                    "<td class='fx'><span class='sep'></span></td>",
                    "<td class='fx'><a id='remover_$oname' href='#remover_$oname' onclick='return doremovedocument(this)'>Delete</a></td>";
                if (($stamps = self::pdfStamps($doc)))
                    echo "<td class='fx'><span class='sep'></span></td><td class='fx'>$stamps</td>";
                echo "</tr></table></div>\n";
            }
        }
        echo "<div id='opt", $o->id, "_new'></div>",
            Ht::js_button("Add attachment", "addattachment($o->id)"),
            "</div></div>\n\n";
    }

    public function echo_editable_option_papt(PaperOption $o, $label = null) {
        echo $this->editable_papt("opt$o->id", $label ? : htmlspecialchars($o->name),
                                  ["id" => "opt{$o->id}_div"]);
        if ($o->description)
            echo '<div class="paphint">', $o->description, "</div>";
        echo Ht::hidden("has_opt$o->id", 1);
    }

    public function echo_editable_option($o) {
        global $Conf, $Me;
        $prow = $this->prow;
        $optid = "opt$o->id";
        $ov = null;
        if ($prow)
            $ov = $prow->option($o->id);
        $ov = $ov ? : new PaperOptionValue($o->id, $o);
        if ($o->type === "attachments")
            $this->editable_attachments($o);
        else if ($o instanceof DocumentPaperOption) {
            $this->echo_editable_document($o, $ov->value ? : 0, 0);
            echo "</div>\n\n";
        } else
            $o->echo_editable_html($ov, $this->useRequest ? $this->qreq["opt$o->id"] : null, $this);
    }

    private function make_echo_editable_option($o) {
        return function () use ($o) {
            $this->echo_editable_option($o);
        };
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
        $show_colors = $Me->can_view_reviewer_tags($this->prow);

        $conflict = array();
        if ($this->useRequest) {
            foreach ($pcm as $id => $row)
                if (isset($this->qreq["pcc$id"])
                    && ($ct = cvtint($this->qreq["pcc$id"])) > 0)
                    $conflict[$id] = Conflict::force_author_mark($ct, $this->admin);
        }
        if ($this->prow) {
            $result = $Conf->qe("select contactId, conflictType from PaperConflict where paperId=" . $this->prow->paperId);
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
        }

        echo $this->editable_papt("pcconf", "PC conflicts"),
            "<div class='paphint'>Select the PC members who have conflicts of interest with this paper. ", $Conf->message_html("conflictdef"), "</div>\n",
            '<div class="papev">',
            Ht::hidden("has_pcconf", 1),
            '<div class="pc_ctable">';
        foreach ($pcm as $id => $p) {
            $label = Ht::label($Me->name_html_for($p), "pcc$id", array("class" => "taghl"));
            if ($p->affiliation)
                $label .= '<div class="pcconfaff">' . htmlspecialchars(UnicodeHelper::utf8_abbreviate($p->affiliation, 60)) . '</div>';
            $ct = defval($conflict, $id, $nonct);

            echo '<div class="ctelt"><div class="ctelti';
            if ($show_colors && ($classes = $p->viewable_color_classes($Me)))
                echo ' ', $classes;
            echo '">';

            if ($selectors) {
                echo '<div class="pctb_editconf_sconf">';
                $extra["id"] = "pcc$id";
                if ($ct->is_author())
                    echo "<strong>Author</strong>";
                else if ($ct->is_conflict() && !$ct->is_author_mark()) {
                    if (!$this->admin)
                        echo "<strong>Conflict</strong>";
                    else
                        echo Ht::select("pcc$id", $ctypes, CONFLICT_CHAIRMARK, $extra);
                } else
                    echo Ht::select("pcc$id", $ctypes, $ct->value, $extra);
                echo '</div>', $label;
            } else {
                $checked = $ct->is_conflict();
                $disabled = $checked && ($ct->is_author() || (!$ct->is_author_mark() && !$this->admin));
                echo '<table><tr><td>',
                    Ht::checkbox_h("pcc$id", $checked ? $ct->value : CONFLICT_AUTHORMARK,
                                   $checked, array("id" => "pcc$id", "disabled" => $disabled)),
                    '&nbsp;</td><td>', $label, '</td></tr></table>';
            }
            echo '<hr class="c" />', "</div></div>";
        }
        echo "</div>\n</div></div>\n\n";
    }

    private function papstripPCConflicts() {
        global $Conf, $Me;
        assert(!$this->editable);
        if (!$this->prow)
            return;

        $pcconf = array();
        $pcm = pcMembers();
        foreach ($this->prow->pc_conflicts() as $id => $x) {
            $p = $pcm[$id];
            $text = "<p class=\"odname\">" . $Me->name_html_for($p) . "</p>";
            if ($Me->isPC && ($classes = $p->viewable_color_classes($Me)))
                $text = "<div class=\"pscopen $classes taghl\">$text</div>";
            $pcconf[$p->sort_position] = $text;
        }
        ksort($pcconf);
        if (!count($pcconf))
            $pcconf[] = "<p class=\"odname\">None</p>";
        $this->_papstripBegin();
        echo $this->papt("pcconflict", "PC conflicts", array("type" => "ps")),
            "<div class='psv psconf'>", join("", $pcconf), "</div></div>\n";
    }

    private function _papstripLeadShepherd($type, $name, $showedit, $wholefold) {
        global $Conf, $Me;
        $editable = ($type === "manager" ? $Me->privChair : $Me->can_administer($this->prow));

        $field = $type . "ContactId";
        if ($this->prow->$field == 0 && !$editable)
            return;
        $value = $this->prow->$field;
        $pc = pcMembers();

        if ($wholefold === null)
            $this->_papstripBegin($type, true);
        else {
            echo '<div id="fold', $type, '" class="foldc">';
            $this->_papstripBegin(null, true);
        }
        echo $this->papt($type, $name, array("type" => "ps", "fold" => $editable ? $type : false, "folded" => true)),
            '<div class="psv">';
        $colors = "";
        $p = null;
        if ($value && isset($pc[$value]))
            $n = $Me->name_html_for($value);
        else
            $n = $value ? "Unknown!" : "";
        $text = '<p class="fn odname">' . $n . '</p>';
        if ($Me->can_view_reviewer_tags($this->prow)) {
            $classes = "";
            if ($p && $p->contactTags)
                $classes = $p->viewable_color_classes($Me);
            echo '<div class="pscopen taghl', rtrim(" $classes"), '">', $text, '</div>';
        } else
            echo $text;

        if ($editable) {
            $selopt = [0];
            foreach (pcMembers() as $p)
                if (!$this->prow
                    || $p->can_accept_review_assignment($this->prow)
                    || $p->contactId == $value)
                    $selopt[] = $p->contactId;
            $Conf->stash_hotcrp_pc($Me);
            echo '<form class="fx"><div>',
                Ht::select($type, [], 0, ["id" => "fold{$type}_d", "class" => "need-pcselector", "data-pcselector-options" => join(" ", $selopt), "data-pcselector-selected" => $value]),
                '</div></form>';
            $Conf->footerScript('make_pseditor("' . $type . '",{p:' . $this->prow->paperId . ',fn:"set' . $type . '"})');
        }

        if ($wholefold === null)
            echo "</div></div>\n";
        else
            echo "</div></div></div>\n";
    }

    private function papstripLead($showedit) {
        $this->_papstripLeadShepherd("lead", "Discussion lead", $showedit || $this->qreq->atab === "lead", null);
    }

    private function papstripShepherd($showedit, $fold) {
        $this->_papstripLeadShepherd("shepherd", "Shepherd", $showedit || $this->qreq->atab === "shepherd", $fold);
    }

    private function papstripManager($showedit) {
        $this->_papstripLeadShepherd("manager", "Paper administrator", $showedit || $this->qreq->atab === "manager", null);
    }

    private function papstripTags() {
        global $Conf, $Me, $Error;
        if (!$this->prow || !$Me->can_view_tags($this->prow))
            return;
        $tags = $this->prow->all_tags_text();
        $is_editable = $Me->can_change_some_tag($this->prow);
        if ($tags === "" && !$is_editable)
            return;

        // Note that tags MUST NOT contain HTML special characters.
        $tagger = new Tagger;
        $viewable = $this->prow->viewable_tags($Me);

        $tx = $tagger->unparse_and_link($viewable, $tags, false);
        $unfolded = $is_editable && (isset($Error["tags"]) || $this->qreq->atab === "tags");

        $this->_papstripBegin("tags", !$unfolded, ["data-onunfold" => "save_tags.load_report()"]);
        $color = TagInfo::color_classes($viewable);
        echo '<div class="', trim("has_hotcrp_tag_classes pscopen $color"), '">';

        if ($is_editable)
            echo Ht::form_div(hoturl("paper", "p=" . $this->prow->paperId), array("id" => "tagform", "onsubmit" => "return save_tags()"));

        echo $this->papt("tags", "Tags", array("type" => "ps", "editfolder" => ($is_editable ? "tags" : 0))),
            '<div class="psv">';
        if ($is_editable) {
            // tag report form
            $treport = PaperApi::tagreport($Me, $this->prow);

            // uneditable
            echo '<div class="fn taghl">';
            if ($treport->warnings)
                echo Ht::xmsg("warning", join("<br>", $treport->warnings));
            echo ($tx === "" ? "None" : $tx), '</div>';

            echo '<div id="papstriptagsedit" class="fx"><div id="tagreportformresult">';
            if ($treport->warnings)
                echo Ht::xmsg("warning", join("<br>", $treport->warnings));
            if ($treport->messages)
                echo Ht::xmsg("info", join("<br>", $treport->messages));
            echo "</div>";
            if (isset($Error["tags"]))
                echo Ht::xmsg("error", $Error["tags"]);
            $editable = $tags;
            if ($this->prow)
                $editable = $this->prow->editable_tags($Me);
            echo '<div style="position:relative">',
                '<textarea id="foldtags_d" cols="20" rows="4" name="tags" onkeypress="return crpSubmitKeyFilter(this, event)" style="width:99%">',
                $tagger->unparse($editable),
                "</textarea></div>",
                '<div style="padding:1ex 0;text-align:right">',
                Ht::submit("cancelsettags", "Cancel", array("class" => "bsm", "onclick" => "return fold('tags',1)")),
                " &nbsp;", Ht::submit("Save", array("class" => "bsm")),
                "</div>",
                "<span class='hint'><a href='", hoturl("help", "t=tags"), "'>Learn more</a> <span class='barsep'>·</span> <strong>Tip:</strong> Twiddle tags like &ldquo;~tag&rdquo; are visible only to you.</span>",
                "</div>";
            $Conf->footerScript("suggest(\"foldtags_d\",\"taghelp_p\",taghelp_tset)");
        } else
            echo '<div class="taghl">', ($tx === "" ? "None" : $tx), '</div>';
        echo "</div>";

        if ($is_editable)
            echo "</div></form>";
        echo "</div></div>\n";
    }

    function papstripOutcomeSelector() {
        global $Conf;
        $this->_papstripBegin("decision", $this->qreq->atab !== "decision");
        echo $this->papt("decision", "Decision", array("type" => "ps", "fold" => "decision")),
            '<div class="psv"><form class="fx"><div>';
        if (isset($this->qreq->forceShow))
            echo Ht::hidden("forceShow", $this->qreq->forceShow ? 1 : 0);
        echo decisionSelector($this->prow->outcome, null, " id='folddecision_d'"),
            '</div></form><p class="fn odname">',
            htmlspecialchars($Conf->decision_name($this->prow->outcome)),
            "</p></div></div>\n";
        $Conf->footerScript('make_pseditor("decision",{p:' . $this->prow->paperId . ',fn:"setdecision"})');
    }

    function papstripReviewPreference() {
        global $Conf;
        $this->_papstripBegin();
        echo $this->papt("revpref", "Review preference", array("type" => "ps")),
            "<div class='psv'>",
            Ht::form_div(hoturl_post("review", "p=" . $this->prow->paperId), array("id" => "revprefform", "class" => "fold7c", "onsubmit" => "return Miniajax.submit('revprefform')", "divclass" => "aahc")),
            Ht::hidden("setrevpref", 1);
        if (isset($this->qreq->forceShow))
            echo Ht::hidden("forceShow", $this->qreq->forceShow ? 1 : 0);
        $rp = unparse_preference($this->prow);
        $rp = ($rp == "0" ? "" : $rp);
        echo "<input id='revprefform_d' type='text' size='4' name='revpref' value=\"$rp\" onchange='Miniajax.submit(\"revprefform\")' tabindex='1' />",
            " ", Ht::submit("Save", array("class" => "fx7")),
            " <span id='revprefformresult'></span>",
            "</div></form></div></div>\n";
        $Conf->footerScript("Miniajax.onload(\"revprefform\");shortcut(\"revprefform_d\").add()");
        if (($l = SessionList::active()) && isset($l->revprefs) && $l->revprefs && $this->mode === "p")
            $Conf->footerScript("crpfocus('revprefform',null,3)");
    }

    private function papstrip_tag_entry($id, $folds) {
        if (!$this->npapstrip_tag_entry)
            $this->_papstripBegin(null, null, "psc_te");
        ++$this->npapstrip_tag_entry;
        echo '<div', ($id ? " id=\"fold{$id}\"" : ""),
            ' class="pste', ($folds ? " $folds" : ""), '">';
    }

    private function papstrip_tag_float($tag, $kind, $type) {
        if (($totval = $this->prow->tag_value($tag)) === false)
            $totval = "";
        $reverse = $type !== "rank";
        $class = "hotcrp_tag_hideempty floatright";
        $extradiv = "";
        if ($type === "vote" || $type === "approval") {
            $class .= " hottooltip";
            $extradiv = ' data-hottooltip-dir="h" data-hottooltip-content-promise="votereport(\'' . $tag . '\')"';
        }
        return '<div class="' . $class . '" style="display:' . ($totval ? "block" : "none")
            . '"' . $extradiv
            . '><a class="qq" href="' . hoturl("search", "q=" . urlencode("show:#$tag sort:" . ($reverse ? "-" : "") . "#$tag")) . '">'
            . '<span class="is-tag-index" data-tag-base="' . $tag . '">' . $totval . '</span> ' . $kind . '</a></div>';
    }

    private function papstrip_tag_entry_title($start, $tag, $value) {
        $title = $start . '<span class="fn hotcrp_tag_hideempty"';
        if ($value === "")
            $title .= ' style="display:none"';
        return $title . '>: <span class="is-tag-index" data-tag-base="' . $tag . '">' . $value . '</span></span>';
    }

    private function papstripRank($tag) {
        global $Conf, $Me;
        $id = "rank_" . html_id_encode($tag);
        if (($myval = $this->prow->tag_value($Me->contactId . "~$tag")) === false)
            $myval = "";
        $totmark = $this->papstrip_tag_float($tag, "overall", "rank");

        $this->papstrip_tag_entry($id, "foldc fold2c");
        echo Ht::form_div("", array("id" => "{$id}form", "data-tag-base" => "~$tag", "onsubmit" => "return false"));
        if (isset($this->qreq->forceShow))
            echo Ht::hidden("forceShow", $this->qreq->forceShow);
        echo $this->papt($id, $this->papstrip_tag_entry_title("#$tag rank", "~$tag", $myval),
                         array("type" => "ps", "fold" => $id, "float" => $totmark)),
            '<div class="psv"><div class="fx">',
            Ht::entry("tagindex", $myval,
                      array("id" => "fold{$id}_d", "size" => 4, "tabindex" => 1,
                            "onchange" => "save_tag_index(this)",
                            "class" => "is-tag-index",
                            "data-tag-base" => "~$tag")),
            ' <span class="barsep">·</span> ',
            '<a href="', hoturl("search", "q=" . urlencode("editsort:-#~$tag")), '">Edit all</a>',
            " <div class='hint' style='margin-top:4px'><strong>Tip:</strong> <a href='", hoturl("search", "q=" . urlencode("editsort:#~$tag")), "'>Search “editsort:#~{$tag}”</a> to drag and drop your ranking, or <a href='", hoturl("offline"), "'>use offline reviewing</a> to rank many papers at once.</div>",
            "</div></div></div></form></div>\n";
    }

    private function papstripVote($tag, $allotment) {
        global $Conf, $Me;
        $id = "vote_" . html_id_encode($tag);
        if (($myval = $this->prow->tag_value($Me->contactId . "~$tag")) === false)
            $myval = "";
        $totmark = $this->papstrip_tag_float($tag, "total", "vote");

        $this->papstrip_tag_entry($id, "foldc fold2c");
        echo Ht::form_div("", array("id" => "{$id}form", "data-tag-base" => "~$tag", "onsubmit" => "return false"));
        if (isset($this->qreq->forceShow))
            echo Ht::hidden("forceShow", $this->qreq->forceShow);
        echo $this->papt($id, $this->papstrip_tag_entry_title("#$tag votes", "~$tag", $myval),
                         array("type" => "ps", "fold" => $id, "float" => $totmark)),
            '<div class="psv"><div class="fx">',
            Ht::entry("tagindex", $myval,
                      array("id" => "fold{$id}_d", "size" => 4, "tabindex" => 1,
                            "onchange" => "save_tag_index(this)",
                            "class" => "is-tag-index",
                            "data-tag-base" => "~$tag")),
            " &nbsp;of $allotment",
            ' <span class="barsep">·</span> ',
            '<a href="', hoturl("search", "q=" . urlencode("editsort:#~$tag")), '">Edit all</a>',
            "</div></div></div></form></div>\n";
    }

    private function papstripApproval($tag) {
        global $Conf, $Me;
        $id = "approval_" . html_id_encode($tag);
        if (($myval = $this->prow->tag_value($Me->contactId . "~$tag")) === false)
            $myval = "";
        $totmark = $this->papstrip_tag_float($tag, "total", "approval");

        $this->papstrip_tag_entry(null, null);
        echo Ht::form_div("", array("id" => "{$id}form", "data-tag-base" => "~$tag", "onsubmit" => "return false"));
        if (isset($this->qreq->forceShow))
            echo Ht::hidden("forceShow", $this->qreq->forceShow);
        echo $this->papt($id,
                         Ht::checkbox("tagindex", "0", $myval !== "",
                                      array("id" => "fold" . $id . "_d", "tabindex" => 1,
                                            "onchange" => "save_tag_index(this)",
                                            "class" => "is-tag-index",
                                            "data-tag-base" => "~$tag",
                                            "style" => "padding-left:0;margin-left:0;margin-top:0"))
                         . "&nbsp;" . Ht::label("#$tag vote"),
                         array("type" => "ps", "float" => $totmark)),
            "</div></form></div>\n\n";
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

        $this->_papstripBegin();
        echo Ht::form_div(hoturl_post("paper", "p=$prow->paperId&amp;setfollow=1"), array("id" => "watchform", "class" => "fold7c", "onsubmit" => "Miniajax.submit('watchform')"));

        if ($row[4] && ($row[4] & ($this->watchCheckbox >> 1)))
            $watchValue = $row[4];
        else if ($row[1] || $row[2] || $row[3] >= CONFLICT_AUTHOR
                 || $prow->managerContactId == $Me->contactId)
            $watchValue = $Me->defaultWatch;
        else
            $watchValue = 0;

        echo $this->papt("watch",
                         Ht::checkbox("follow", $this->watchCheckbox,
                                       $watchValue & $this->watchCheckbox,
                                       array("onchange" => "Miniajax.submit('watchform')",
                                             "style" => "padding-left:0;margin-left:0"))
                         . "&nbsp;" . Ht::label("Email notification"),
                         array("type" => "ps")),
            "<div class='pshint'>Select to receive email on updates to reviews and comments. <span id='watchformresult'></span>",
            Ht::submit("Save", array("class" => "fx7")),
            "</div></div></form></div>\n\n";

        $Conf->footerScript("Miniajax.onload(\"watchform\")");
    }


    // Functions for editing

    function deadlineSettingIs($dname) {
        global $Conf;
        $deadline = $Conf->printableTimeSetting($dname, "span");
        if ($deadline === "N/A")
            return "";
        else if (time() < $Conf->setting($dname))
            return "  The deadline is $deadline.";
        else
            return "  The deadline was $deadline.";
    }

    private function _override_message() {
        if ($this->admin)
            return " As an administrator, you can override this deadline.";
        else
            return "";
    }

    private function _edit_message_new_paper() {
        global $Conf;
        $startDeadline = $this->deadlineSettingIs("sub_reg");
        $msg = "";
        if (!$Conf->timeStartPaper()) {
            if ($Conf->setting("sub_open") <= 0)
                $msg = "You can’t register new papers because the conference site has not been opened for submissions." . $this->_override_message();
            else
                $msg = 'You can’t register new papers since the <a href="' . hoturl("deadlines") . '">deadline</a> has passed.' . $startDeadline . $this->_override_message();
            if (!$this->admin) {
                $this->quit = true;
                return '<div class="merror">' . $msg . '</div>';
            }
            $msg = Ht::xmsg("info", $msg);
        }
        if ($startDeadline && !$Conf->setting("sub_freeze"))
            $t = "You can make changes until the deadline, but thereafter";
        else
            $t = "You don’t have to upload the paper right away, but";
        $msg .= Ht::xmsg("info", "Enter information about your paper. $t incomplete submissions will not be considered.$startDeadline");
        if (($v = $Conf->message_html("submit")))
            $msg .= Ht::xmsg("info", $v);
        return $msg;
    }

    private function editMessage() {
        global $Conf, $Me, $Opt;
        if (!($prow = $this->prow))
            return $this->_edit_message_new_paper();

        $m = "";
        $has_author = $prow->has_author($Me);
        if ($has_author && $prow->outcome < 0 && $Conf->timeAuthorViewDecision())
            $m .= Ht::xmsg("warning", "This paper was not accepted.");
        else if ($has_author && $prow->timeWithdrawn > 0) {
            if ($Me->can_revive_paper($prow))
                $m .= Ht::xmsg("warning", "This paper has been withdrawn, but you can still revive it." . $this->deadlineSettingIs("sub_update"));
        } else if ($has_author && $prow->timeSubmitted <= 0) {
            if ($Me->can_update_paper($prow)) {
                if ($Conf->setting("sub_freeze"))
                    $t = "A final version of this paper must be submitted before it can be reviewed.";
                else if ($prow->paperStorageId <= 1 && !get($Opt, "noPapers"))
                    $t = "The submission is not ready for review and will not be considered as is, but you can still make changes.";
                else
                    $t = "The submission is not ready for review and will not be considered as is, but you can still mark it ready for review and make other changes if appropriate.";
                $m .= Ht::xmsg("warning", $t . $this->deadlineSettingIs("sub_update"));
            } else if ($Me->can_finalize_paper($prow))
                $m .= Ht::xmsg("warning", 'Unless the paper is submitted, it will not be reviewed. You cannot make any changes as the <a href="' . hoturl("deadlines") . '">deadline</a> has passed, but the current version can be still be submitted.' . $this->deadlineSettingIs("sub_sub") . $this->_override_message());
            else if ($Conf->deadlinesBetween("", "sub_sub", "sub_grace"))
                $m .= Ht::xmsg("warning", 'The site is not open for submission updates at the moment.' . $this->_override_message());
            else
                $m .= Ht::xmsg("warning", 'The <a href="' . hoturl("deadlines") . '">deadline</a> for submitting this paper has passed. The paper will not be reviewed.' . $this->deadlineSettingIs("sub_sub") . $this->_override_message());
        } else if ($has_author && $Me->can_update_paper($prow)) {
            if ($this->mode === "edit")
                $m .= Ht::xmsg("confirm", 'This submission is ready and will be considered for review. You can still make changes if necessary.' . $this->deadlineSettingIs("sub_update"));
        } else if ($has_author
                   && $prow->outcome > 0
                   && $Conf->timeSubmitFinalPaper()
                   && ($t = $Conf->message_html("finalsubmit", array("deadline" => $this->deadlineSettingIs("final_soft")))))
            $m .= Ht::xmsg("info", $t);
        else if ($has_author) {
            $override2 = ($this->admin ? " As an administrator, you can update the paper anyway." : "");
            if ($this->mode === "edit") {
                $t = "";
                if ($Me->can_withdraw_paper($prow))
                    $t = " or withdraw it from consideration";
                $m .= Ht::xmsg("info", "This paper is under review and can’t be changed, but you can change its contacts$t.$override2");
            }
        } else if ($prow->outcome > 0 && !$Conf->timeAuthorViewDecision()
                   && $Conf->collectFinalPapers())
            $m .= Ht::xmsg("info", "This paper was accepted, but authors can’t view paper decisions yet. Once decisions are visible, the system will allow accepted authors to upload final versions.");
        else
            $m .= Ht::xmsg("info", "You aren’t a contact for this paper, but as an administrator you can still make changes.");
        if ($Me->can_update_paper($prow, true) && ($v = $Conf->message_html("submit")))
            $m .= Ht::xmsg("info", $v);
        return $m;
    }

    function _collectActionButtons() {
        global $Conf, $Me;
        $prow = $this->prow;
        $pid = $prow ? $prow->paperId : "new";

        // Withdrawn papers can be revived
        if ($prow && $prow->timeWithdrawn > 0) {
            $revivable = $Conf->timeFinalizePaper($prow);
            if ($revivable)
                $b = Ht::submit("revive", "Revive paper");
            else {
                $b = "The <a href='" . hoturl("deadlines") . "'>deadline</a> for reviving withdrawn papers has passed.";
                if ($this->admin)
                    $b = array(Ht::js_button("Revive paper", "override_deadlines(this)", array("data-override-text" => $b, "data-override-submit" => "revive")), "(admin only)");
            }
            return array($b);
        }

        $buttons = array();

        if ($this->mode === "edit") {
            // check whether we can save
            if ($this->canUploadFinal) {
                $updater = "submitfinal";
                $whyNot = $Me->perm_submit_final_paper($prow, false);
            } else if ($prow) {
                $updater = "update";
                $whyNot = $Me->perm_update_paper($prow, false);
            } else {
                $updater = "update";
                $whyNot = $Me->perm_start_paper(false);
            }
            // pay attention only to the deadline
            if ($whyNot && (get($whyNot, "deadline") || get($whyNot, "rejected")))
                $whyNot = array("deadline" => get($whyNot, "deadline"), "rejected" => get($whyNot, "rejected"));
            else
                $whyNot = null;
            // produce button
            if (!$whyNot)
                $buttons[] = array(Ht::submit($updater, "Save changes", array("class" => "bb")), "");
            else if ($this->admin)
                $buttons[] = array(Ht::js_button("Save changes", "override_deadlines(this)", array("data-override-text" => whyNotText($whyNot, $prow ? "update" : "register"), "data-override-submit" => $updater)), "(admin only)");
            else if ($prow && $prow->timeSubmitted > 0)
                $buttons[] = array(Ht::submit("updatecontacts", "Save contacts", array("class" => "b")), "");
            else if ($Conf->timeFinalizePaper($prow))
                $buttons[] = array(Ht::submit("update", "Save changes", array("class" => "bb")));
        }

        // withdraw button
        if (!$prow || !$Me->can_withdraw_paper($prow, true))
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
            if (!$Me->can_withdraw_paper($prow))
                $override = "<div>" . Ht::checkbox("override", array("id" => "dialog_override")) . "&nbsp;"
                    . Ht::label("Override deadlines") . "</div>";
            $Conf->footerHtml("<div class='popupbg' style='display:none'><div id='popup_w' class='popupc'>
  <p>Are you sure you want to withdraw this paper from consideration and/or
  publication?  $admins</p>\n"
    . Ht::form_div(hoturl_post("paper", "p=" . $prow->paperId . "&amp;m=edit"))
    . Ht::textarea("reason", null,
                   array("id" => "withdrawreason", "rows" => 3, "cols" => 40,
                         "style" => "width:99%", "placeholder" => "Optional explanation", "spellcheck" => "true"))
    . $override
    . "<div class='popup_actions' style='margin-top:10px'>\n"
    . Ht::hidden("doemail", 1, array("class" => "popup_populate"))
    . Ht::hidden("emailNote", "", array("class" => "popup_populate"))
    . Ht::js_button("Cancel", "popup(null,'w',1)")
    . Ht::submit("withdraw", "Withdraw paper", array("class" => "bb"))
    . "</div></div></form></div></div>", "popup_w");
        }
        if ($b) {
            if (!$Me->can_withdraw_paper($prow))
                $b = array($b, "(admin only)");
            $buttons[] = $b;
        }

        return $buttons;
    }

    function echoActions($top) {
        global $Conf, $Me;
        $prow = $this->prow;

        $buttons = $this->_collectActionButtons();

        if ($this->admin && $prow) {
            $buttons[] = array(Ht::js_button("Delete paper", "popup(this,'delp',0,true)"), "(admin only)");
            Ht::popup("delp",
                "<p>Be careful: This will permanently delete all information about this paper from the database and <strong>cannot be undone</strong>.</p>",
                Ht::form(hoturl_post("paper", "p=" . $prow->paperId . "&amp;m=edit")),
                Ht::hidden("doemail", 1, array("class" => "popup_populate"))
                    . Ht::hidden("emailNote", "", array("class" => "popup_populate"))
                    . Ht::js_button("Cancel", "popup(null,'delp',1)")
                    . Ht::submit("delete", "Delete paper", array("class" => "bb")));
        }

        echo Ht::actions($buttons, array("class" => "aab"));
        if ($this->admin && !$top) {
            $v = (string) $this->qreq->emailNote;
            echo "  <div class='g'></div>\n  <table>\n",
                "    <tr><td>",
                Ht::checkbox("doemail", 1, true), "&nbsp;",
                Ht::label("Email authors, including:"), "&nbsp; ",
                Ht::entry("emailNote", $v,
                          array("id" => "emailNote", "size" => 30, "placeholder" => "Optional explanation")),
                "</td></tr>\n  </table>\n";
        }
    }


    // Functions for overall paper table viewing

    function _papstrip() {
        global $Conf, $Me;
        $prow = $this->prow;
        if (($prow->managerContactId || ($Me->privChair && $this->mode === "assign"))
            && $Me->can_view_paper_manager($prow))
            $this->papstripManager($Me->privChair);
        $this->papstripTags();
        $this->npapstrip_tag_entry = 0;
        foreach (TagInfo::defined_tags() as $ltag => $dt)
            if ($Me->can_change_tag($prow, "~$ltag", null, 0)) {
                if ($dt->approval)
                    $this->papstripApproval($dt->tag);
                else if ($dt->vote)
                    $this->papstripVote($dt->tag, $dt->vote);
                else if ($dt->rank)
                    $this->papstripRank($dt->tag);
            }
        if ($this->npapstrip_tag_entry)
            echo "</div>";
        $this->papstripWatch();
        if ($Me->can_view_conflicts($prow) && !$this->editable)
            $this->papstripPCConflicts();
        if ($Me->can_view_authors($prow, true) && !$this->editable)
            $this->papstripCollaborators();

        $foldShepherd = $Me->can_set_decision($prow) && $prow->outcome <= 0
            && $prow->shepherdContactId == 0 && $this->mode !== "assign";
        if ($Me->can_set_decision($prow))
            $this->papstripOutcomeSelector();
        if ($Me->can_view_lead($prow))
            $this->papstripLead($this->mode === "assign");
        if ($Me->can_view_shepherd($prow))
            $this->papstripShepherd($this->mode === "assign", $foldShepherd);

        if ($Me->can_accept_review_assignment($prow)
            && $Conf->timePCReviewPreferences()
            && ($Me->roles & (Contact::ROLE_PC | Contact::ROLE_CHAIR)))
            $this->papstripReviewPreference();
        $Conf->echoScript("$(\".need-pcselector\").each(populate_pcselector)");
    }

    function _paptabTabLink($text, $link, $image, $highlight) {
        global $Conf;
        return '<div class="' . ($highlight ? "papmodex" : "papmode")
            . '"><a href="' . $link . '" class="xx">'
            . Ht::img($image, "[$text]", "b")
            . "&nbsp;<u" . ($highlight ? ' class="x"' : "") . ">" . $text
            . "</u></a></div>\n";
    }

    private function _paptabBeginKnown() {
        global $Conf, $Me;
        $prow = $this->prow;

        // what actions are supported?
        $canEdit = $Me->can_edit_paper($prow);
        $canReview = $Me->can_review($prow, null);
        $canAssign = $Me->can_administer($prow);
        $canHome = ($canEdit || $canAssign || $this->mode === "contact");

        $t = "";

        // paper tabs
        if ($canEdit || $canReview || $canAssign || $canHome) {
            $t .= '<div class="submission_modes">';

            // home link
            $highlight = ($this->mode !== "assign" && $this->mode !== "edit"
                          && $this->mode !== "contact" && $this->mode !== "re");
            $a = ""; // ($this->mode === "edit" || $this->mode === "re" ? "&amp;m=p" : "");
            $t .= $this->_paptabTabLink("Main", hoturl("paper", "p=$prow->paperId$a"), "view18.png", $highlight);

            if ($canEdit)
                $t .= $this->_paptabTabLink("Edit", hoturl("paper", "p=$prow->paperId&amp;m=edit"), "edit18.png", $this->mode === "edit");

            if ($canReview)
                $t .= $this->_paptabTabLink("Review", hoturl("review", "p=$prow->paperId&amp;m=re"), "review18.png", $this->mode === "re" && (!$this->editrrow || $this->editrrow->contactId == $Me->contactId));

            if ($canAssign)
                $t .= $this->_paptabTabLink("Assign", hoturl("assign", "p=$prow->paperId"), "assign18.png", $this->mode === "assign");

            $t .= "<hr class=\"c\" /></div>";
        }

        return $t;
    }

    static private function _echo_clickthrough($ctype) {
        global $Conf, $Now;
        $data = $Conf->message_html("clickthrough_$ctype");
        echo Ht::form(hoturl_post("profile"), array("onsubmit" => "return handle_clickthrough(this)")), "<div class='aahc'>", $data;
        $buttons = array(Ht::submit("clickthrough_accept", "Accept", array("class" => "bb")));
        echo "<div class='g'></div>",
            Ht::hidden("clickthrough", $ctype),
            Ht::hidden("clickthrough_sha1", sha1($data)),
            Ht::hidden("clickthrough_time", $Now),
            Ht::actions($buttons), "</div></form>";
    }

    static public function echo_review_clickthrough() {
        echo '<div class="revcard clickthrough"><div class="revcard_head"><h3>Reviewing terms</h3></div><div class="revcard_body">You must agree to these terms before you can save reviews.<hr />';
        self::_echo_clickthrough("review");
        echo "</form></div></div>";
    }

    private function _echo_editable_body($form) {
        global $Conf, $Me, $Opt;
        $prow = $this->prow;

        echo $form, "<div class='aahc'>";
        $this->canUploadFinal = $prow && $prow->outcome > 0
            && (!($whyNot = $Me->perm_submit_final_paper($prow, true))
                || get($whyNot, "deadline") === "final_done");

        if (($m = $this->editMessage()))
            echo $m, '<div class="g"></div>';
        if ($this->quit) {
            echo "</div></form>";
            return;
        }

        $this->echoActions(true);
        echo '<div>';

        $callbacks = [
            [0, 0, [$this, "echo_editable_title"]],
            [10000, 1, [$this, "echo_editable_submission"]],
            [20000, 2, [$this, "echo_editable_authors"]]
        ];
        if ($this->prow)
            $callbacks[] = [20200, count($callbacks), [$this, "editable_contact_author"]];
        else if ($Me->privChair)
            $callbacks[] = [20200, count($callbacks), [$this, "editable_new_contact_author"]];
        if ($Conf->submission_blindness() == Conf::BLIND_OPTIONAL
            && $this->editable !== "f")
            $callbacks[] = [20100, count($callbacks), [$this, "echo_editable_anonymity"]];
        if (($x = opt("noAbstract")) !== 1 && $x !== true)
            $callbacks[] = [30000, count($callbacks), [$this, "echo_editable_abstract"]];
        $callbacks[] = [40000, count($callbacks), [$this, "echo_editable_topics"]];
        if ($this->editable !== "f" || $this->admin) {
            $callbacks[] = [60000, count($callbacks), [$this, "editable_pc_conflicts"]];
            $callbacks[] = [61000, count($callbacks), [$this, "editable_collaborators"]];
        }
        foreach (PaperOption::option_list() as $opt)
            if ((!$opt->final || $this->canUploadFinal)
                && (!$this->prow || $Me->can_view_paper_option($this->prow, $opt, true)))
                $callbacks[] = [$opt->form_priority(), count($callbacks), $this->make_echo_editable_option($opt)];
        usort($callbacks, function ($a, $b) {
            return $a[0] - $b[0] ? : $a[1] - $b[1];
        });
        foreach ($callbacks as $f)
            call_user_func($f[2]);

        // Submit button
        echo "</div>";
        $this->echo_editable_complete($this->prow ? $this->prow->paperStorageId : 0);
        $this->echoActions(false);

        echo "</div></form>";
        Ht::stash_script("jQuery('textarea.papertext').autogrow()");
    }

    function paptabBegin() {
        global $Conf, $Me;
        $prow = $this->prow;

        if ($prow)
            $this->_papstrip();
        if ($this->npapstrip) {
            echo "</div></div></div></div>\n",
                '<div class="papcard"><div class="papcard_body">';
        } else
            echo '<div class="pedcard"><div class="pedcard_body">';

        $form_js = array("id" => "paperedit");
        if ($prow && $prow->paperStorageId > 1 && $prow->timeSubmitted > 0
            && !$Conf->setting('sub_freeze'))
            $form_js["onsubmit"] = "return docheckpaperstillready()";
        $form = Ht::form(hoturl_post("paper", "p=" . ($prow ? $prow->paperId : "new") . "&amp;m=edit"), $form_js);

        $this->echoDivEnter();
        if ($this->editable) {
            if (!$Me->can_clickthrough("submit")) {
                echo '<div class="clickthrough"><h3>Submission terms</h3>You must agree to these terms before you can submit a paper.<hr />';
                self::_echo_clickthrough("submit");
                echo '</div><div id="clickthrough_show" style="display:none">';
                $this->_echo_editable_body($form);
                echo '</div>';
            } else
                $this->_echo_editable_body($form);
        } else {
            if ($this->mode === "edit" && ($m = $this->editMessage()))
                echo $m, "<div class='g'></div>\n";
            $this->paptabDownload();
            $this->paptabAbstract();
            echo '<div class="paptab"><div class="paptab_authors">';
            $this->paptabAuthors(!$this->editable && $this->mode === "edit"
                                 && $prow->timeSubmitted > 0);
            $this->paptabTopicsOptions($Me->can_administer($prow));
            echo '</div></div><hr class="c" />';
        }
        $this->echoDivExit();

        if (!$this->editable && $this->mode === "edit") {
            echo $form;
            if ($prow->timeSubmitted > 0)
                $this->editable_contact_author(true);
            $this->echoActions(false);
            echo "</form>";
        } else if (!$this->editable && $Me->act_author_view($prow) && !$Me->contactId) {
            echo '<hr class="papcard_sep" />',
                "To edit this paper, <a href=\"", hoturl("index"), "\">sign in using your email and password</a>.";
        }

        $Conf->footerScript("shortcut().add()");
    }

    private function _paptabSepContaining($t) {
        if ($t !== "")
            echo '<hr class="papcard_sep" />', $t;
    }

    function _paptabReviewLinks($rtable, $editrrow, $ifempty) {
        global $Me;
        require_once("reviewtable.php");
        $status_info = $Me->paper_status_info($this->prow);
        $out = "<span class=\"pstat $status_info[0]\">"
            . htmlspecialchars($status_info[1]) . "</span>";

        if ($this->prow->has_author($Me))
            $out .= ' <span class="barsep">·</span> You are an <span class="author">author</span> of this paper.';
        else if ($this->prow->has_conflict($Me))
            $out .= ' <span class="barsep">·</span> You have a <span class="conflit">conflict</span> with this paper.';
        $this->_paptabSepContaining('<p class="xd">' . $out . '</p>');

        $t = "";
        if ($rtable)
            $t .= reviewTable($this->prow, $this->all_rrows, $this->mycrows,
                              $editrrow, $this->mode);
        $t .= reviewLinks($this->prow, $this->all_rrows, $this->mycrows,
                          $editrrow, $this->mode, $this->allreviewslink);
        if (($empty = ($t === "")))
            $t = $ifempty;
        echo $t, "</div></div>\n";
        return $empty;
    }

    function _privilegeMessage() {
        global $Conf;
        $a = "<a href=\"" . selfHref(array("forceShow" => 0)) . "\">";
        return $a . Ht::img("override24.png", "[Override]", "dlimg")
            . "</a>&nbsp;You have used administrator privileges to view and edit "
            . "reviews for this paper. (" . $a . "Unprivileged view</a>)";
    }

    public static function sort_rc_json($a, $b) {
        // drafts come last
        if (isset($a->draft) != isset($b->draft)
            && (isset($a->draft) ? !$a->displayed_at : !$b->displayed_at))
            return isset($a->draft) ? 1 : -1;
        // order by displayed_at
        if ($a->displayed_at != $b->displayed_at)
            return $a->displayed_at < $b->displayed_at ? -1 : 1;
        // reviews before comments
        if (isset($a->rid) != isset($b->rid))
            return isset($a->rid) ? -1 : 1;
        if (isset($a->cid))
            // order by commentId (which generally agrees with ordinal)
            return $a->cid < $b->cid ? -1 : 1;
        else {
            // order by ordinal
            if (isset($a->ordinal) && isset($b->ordinal)) {
                $al = strlen($a->ordinal);
                $bl = strlen($b->ordinal);
                if ($al != $bl)
                    return $al < $bl ? -1 : 1;
                else
                    return strcmp($a->ordinal, $b->ordinal);
            }
            // order by reviewId
            return $a->rid < $b->rid ? -1 : 1;
        }
    }

    private function include_comments() {
        global $Conf, $Me;
        return !$this->allreviewslink
            && (count($this->mycrows)
                || $Me->can_comment($this->prow, null)
                || $Conf->time_author_respond());
    }

    function paptabEndWithReviewsAndComments() {
        global $Conf, $Me;
        $prow = $this->prow;

        if ($Me->is_admin_force()
            && !$Me->can_view_review($prow, null, false))
            $this->_paptabSepContaining($this->_privilegeMessage());
        else if ($Me->contactId == $prow->managerContactId && !$Me->privChair
                 && $Me->contactId > 0)
            $this->_paptabSepContaining("You are this paper’s administrator.");

        $empty = $this->_paptabReviewLinks(true, null, "<div class='hint'>There are no reviews or comments for you to view.</div>");
        if ($empty)
            return;

        // text format link
        $viewable = array();
        foreach ($this->viewable_rrows as $rr)
            if ($rr->reviewModified > 0) {
                $viewable[] = "reviews";
                break;
            }
        foreach ($this->crows as $cr)
            if ($Me->can_view_comment($prow, $cr, null)) {
                $viewable[] = "comments";
                break;
            }
        if (count($viewable))
            echo '<div class="notecard"><div class="notecard_body">',
                "<a href='", hoturl("review", "p=$prow->paperId&amp;m=r&amp;text=1"), "' class='xx'>",
                Ht::img("txt24.png", "[Text]", "dlimg"),
                "&nbsp;<u>", ucfirst(join(" and ", $viewable)),
                " in plain text</u></a></div></div>\n";

        $rf = ReviewForm::get();
        $rf->set_can_view_ratings($prow, $this->all_rrows, $Me);

        $rcjs = [];
        foreach ($this->viewable_rrows as $rr)
            if ($rr->reviewSubmitted || $rr->reviewModified > 0)
                $rcjs[] = $rf->unparse_review_json($prow, $rr, $Me, true);
        if ($this->include_comments())
            foreach ($this->mycrows as $cr)
                $rcjs[] = $cr->unparse_json($Me, true);
        $this->render_rcjs($rcjs);
    }

    private function has_response($respround) {
        foreach ($this->mycrows as $cr)
            if (($cr->commentType & COMMENTTYPE_RESPONSE)
                && $cr->commentRound == $respround)
                return true;
        return false;
    }

    private function render_rcjs($rcjs) {
        global $Conf, $Me;
        usort($rcjs, "PaperTable::sort_rc_json");

        $s = "";
        $ncmt = 0;
        foreach ($rcjs as $rcj) {
            unset($rcj->displayed_at);
            if (isset($rcj->rid))
                $s .= "review_form.add_review(" . json_encode($rcj) . ");\n";
            else {
                ++$ncmt;
                $s .= "papercomment.add(" . json_encode($rcj) . ");\n";
            }
        }

        if ($this->include_comments()) {
            if ($Me->can_comment($this->prow, null)) {
                ++$ncmt;
                $s .= "papercomment.add({is_new:true,editable:true});\n";
            }
            if ($this->prow->has_author($Me))
                foreach ($Conf->time_author_respond() as $i => $rname) {
                    if (!$this->has_response($i)) {
                        ++$ncmt;
                        $s .= "papercomment.add({is_new:true,editable:true,response:" . json_encode($rname) . "},true);\n";
                    }
                }
        }

        if ($ncmt)
            CommentInfo::echo_script($this->prow);
        $Conf->echoScript($s);
    }

    function paptabComments() {
        global $Conf, $Me;
        if ($this->include_comments()) {
            $rcjs = [];
            foreach ($this->mycrows as $cr)
                $rcjs[] = $cr->unparse_json($Me, true);
            $this->render_rcjs($rcjs);
        }
    }

    function paptabEndWithReviewMessage() {
        global $Conf, $Me;
        if ($this->editable) {
            echo "</div></div>\n";
            return;
        }

        $m = array();
        if ($this->all_rrows
            && ($whyNot = $Me->perm_view_review($this->prow, null, null)))
            $m[] = "You can’t see the reviews for this paper. " . whyNotText($whyNot, "review");
        if ($this->prow && $this->prow->reviewType && !$Conf->time_review_open()) {
            if ($this->rrow)
                $m[] = "You can’t edit your review because the site is not open for reviewing.";
            else
                $m[] = "You can’t begin your assigned review because the site is not open for reviewing.";
        }
        if (count($m))
            $this->_paptabSepContaining(join("<br />", $m));

        $this->_paptabReviewLinks(false, null, "");
    }

    function paptabEndWithEditableReview() {
        global $Conf, $Me;
        $prow = $this->prow;
        $act_pc = $Me->act_pc($prow);
        $actChair = $Me->can_administer($prow);

        // review messages
        $whyNot = $Me->perm_view_review($prow, null, false);
        $msgs = array();
        if (!$this->rrow && $this->prow->reviewType <= 0)
            $msgs[] = "You haven’t been assigned to review this paper, but you can review it anyway.";
        if ($whyNot && $Me->is_admin_force()) {
            $msgs[] = $this->_privilegeMessage();
        } else if ($whyNot && isset($whyNot["reviewNotComplete"])
                   && ($Me->isPC || $Conf->setting("extrev_view"))) {
            $nother = 0;
            foreach ($this->all_rrows as $rr)
                if (!$Me->is_my_review($rr) && $rr->reviewSubmitted)
                    $nother++;
            if ($nother > 0)
                $msgs[] = "You’ll be able to see " . plural($nother, "other review") . " once you complete your own.";
        }
        if (count($msgs) > 0)
            $this->_paptabSepContaining(join("<br />\n", $msgs));

        // links
        $this->_paptabReviewLinks(true, $this->editrrow, "");

        // review form, possibly with deadline warning
        $opt = array("edit" => $this->mode === "re");

        if ($this->editrrow
            && ($Me->is_my_review($this->editrrow) || $actChair)
            && !$Conf->time_review($this->editrrow, $act_pc, true)) {
            if ($actChair)
                $override = " As an administrator, you can override this deadline.";
            else {
                $override = "";
                if ($this->editrrow->reviewSubmitted)
                    $opt["edit"] = false;
            }
            if ($Conf->time_review_open())
                $opt["editmessage"] = "The <a href='" . hoturl("deadlines") . "'>review deadline</a> has passed, so the review can no longer be changed.$override";
            else
                $opt["editmessage"] = "The site is not open for reviewing, so the review cannot be changed.$override";
        } else if (!$Me->can_review($prow, $this->editrrow))
            $opt["edit"] = false;

        // maybe clickthrough
        if ($opt["edit"] && !$Me->can_clickthrough("review"))
            self::echo_review_clickthrough();

        $rf = ReviewForm::get();
        $rf->set_can_view_ratings($prow, $this->all_rrows, $Me);
        $rf->show($prow, $this->all_rrows, $this->editrrow, $opt);
        Ht::stash_script("jQuery('textarea.reviewtext').autogrow()",
                         "reviewtext_autogrow");
    }


    // Functions for loading papers

    static function _maybeSearchPaperId() {
        global $Conf, $Me, $Now;

        // if a number, don't search
        if (isset($_REQUEST["paperId"]) && $_REQUEST["paperId"] != "") {
            if (ctype_digit($_REQUEST["paperId"])
                && $_REQUEST["paperId"][0] != "0")
                return false;
            if (preg_match('/^\s*#?([1-9]\d*)\s*$/s', $_REQUEST["paperId"], $m)) {
                $_REQUEST["paperId"] = $_GET["paperId"] = $_POST["paperId"] = $m[1];
                return false;
            }
        }

        // if a complex request, or a form upload, don't search
        foreach ($_REQUEST as $k => $v)
            if ($k !== "p" && $k !== "paperId" && $k !== "m" && $k !== "mode"
                && $k !== "forceShow" && $k !== "go" && $k !== "actas"
                && $k !== "ls" && $k !== "t"
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
                $_REQUEST["paperId"] = $_GET["paperId"] = $_POST["paperId"] = $paperId[0];
            return false;
        }

        // if invalid contact, don't search
        if ($Me->is_empty())
            return false;

        // actually try to search
        if ($_REQUEST["paperId"] === "(All)")
            $_REQUEST["paperId"] = $_GET["paperId"] = $_POST["paperId"] = "";
        $search = new PaperSearch($Me, array("q" => $_REQUEST["paperId"], "t" => defval($_REQUEST, "t", 0)));
        $pl = $search->paperList();
        if (count($pl) == 1) {
            $pl = $search->session_list_object();
            $_REQUEST["paperId"] = $_GET["paperId"] = $_POST["paperId"] =
                $_REQUEST["p"] = $_GET["p"] = $_POST["p"] = $pl->ids[0];
            // check if the paper is in the current list
            if (false && ($curpl = SessionList::requested())
                && isset($curpl->listno) && $curpl->listno
                && str_starts_with($curpl->listid, "p")
                && !preg_match(',\Ap/[^/]*//,', $curpl->listid)
                && array_search($pl->ids[0], $curpl->ids) !== false) {
                // preserve current list
                if (isset($pl->matchPreg) && $pl->matchPreg)
                    $Conf->save_session("temp_matchPreg", $pl->matchPreg);
                $pl = $curpl;
            } else {
                // make new list
                $pl->listno = SessionList::allocate($pl->listid);
                SessionList::change($pl->listno, $pl);
            }
            unset($_REQUEST["ls"], $_GET["ls"], $_POST["ls"]);
            SessionList::set_requested($pl->listno);
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
            $_REQUEST["paperId"] = $_GET["paperId"] = $_POST["paperId"] = $_REQUEST["p"];
        if (!isset($_REQUEST["reviewId"]) && isset($_REQUEST["r"]))
            $_REQUEST["reviewId"] = $_GET["reviewId"] = $_POST["reviewId"] = $_REQUEST["r"];
        if (!isset($_REQUEST["commentId"]) && isset($_REQUEST["c"]))
            $_REQUEST["commentId"] = $_GET["commentId"] = $_POST["commentId"] = $_REQUEST["c"];
        if (!isset($_REQUEST["paperId"])
            && preg_match(',\A/(?:new|\d+)\z,i', Navigation::path()))
            $_REQUEST["paperId"] = $_GET["paperId"] = $_POST["paperId"] = substr(Navigation::path(), 1);
        else if (!isset($_REQUEST["reviewId"])
                 && preg_match(',\A/\d+[A-Z]+\z,i', Navigation::path()))
            $_REQUEST["reviewId"] = $_GET["reviewId"] = $_POST["reviewId"] = substr(Navigation::path(), 1);
        if (!isset($_REQUEST["paperId"]) && isset($_REQUEST["reviewId"])
            && preg_match('/^(\d+)[A-Z]+$/', $_REQUEST["reviewId"], $m))
            $_REQUEST["paperId"] = $_GET["paperId"] = $_POST["paperId"] = $m[1];
    }

    static function paperRow(&$whyNot) {
        global $Conf, $Me;

        self::cleanRequest();
        if (isset($_REQUEST["paperId"]) && $_REQUEST["paperId"] === "new")
            return null;

        $sel = array();
        if (isset($_REQUEST["paperId"])
            || (!isset($_REQUEST["reviewId"]) && !isset($_REQUEST["commentId"]))) {
            self::_maybeSearchPaperId();
            $sel["paperId"] = defval($_REQUEST, "paperId", 1);
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
        if (($whyNot = $Me->perm_view_paper($prow))
            || (!isset($_REQUEST["paperId"])
                && !$Me->can_view_review($prow, $rrow, null)
                && !$Me->privChair)) {
            // Don't allow querier to probe review/comment<->paper mapping
            if (!isset($_REQUEST["paperId"]))
                $whyNot = array("invalidId" => "paper");
            return null;
        }
        if (!isset($_REQUEST["paperId"]))
            $_REQUEST["paperId"] = $_GET["paperId"] = $_POST["paperId"] = $prow->paperId;
        return $prow;
    }

    function resolveReview($want_review) {
        global $Conf, $Me;

        $sel = array("paperId" => $this->prow->paperId, "array" => true);
        if ($Conf->setting("rev_ratings") != REV_RATINGS_NONE) {
            $sel["ratings"] = true;
            $sel["myRating"] = $Me->contactId;
        }
        $this->all_rrows = $Conf->reviewRow($sel, $whyNot);

        $this->viewable_rrows = array();
        $round_mask = 0;
        $min_view_score = VIEWSCORE_MAX;
        foreach ($this->all_rrows as $rrow)
            if ($Me->can_view_review($this->prow, $rrow, null)) {
                $this->viewable_rrows[] = $rrow;
                if ($rrow->reviewRound !== null)
                    $round_mask |= 1 << (int) $rrow->reviewRound;
                $min_view_score = min($min_view_score, $Me->view_score_bound($this->prow, $rrow));
            }
        $rf = ReviewForm::get();
        $Conf->footerScript("review_form.set_form(" . json_encode($rf->unparse_json($round_mask, $min_view_score)) . ")");
        if ($Me->can_view_review_ratings())
            $Conf->footerScript("review_form.set_ratings(" . json_encode($rf->unparse_ratings_json()) . ")");

        $rrid = strtoupper(defval($_REQUEST, "reviewId", ""));
        while ($rrid !== "" && $rrid[0] === "0")
            $rrid = substr($rrid, 1);

        $this->rrow = $myrrow = null;
        foreach ($this->viewable_rrows as $rrow) {
            if ($rrid !== ""
                && (strcmp($rrow->reviewId, $rrid) == 0
                    || ($rrow->reviewOrdinal
                        && strcmp($rrow->paperId . unparseReviewOrdinal($rrow->reviewOrdinal), $rrid) == 0)))
                $this->rrow = $rrow;
            if ($rrow->contactId == $Me->contactId
                || (!$myrrow && $Me->is_my_review($rrow)))
                $myrrow = $rrow;
        }

        $this->editrrow = $this->rrow ? : $myrrow;

        if ($want_review && $Me->can_review($this->prow, $this->editrrow, false))
            $this->mode = "re";
    }

    function resolveComments() {
        global $Conf, $Me;
        $this->crows = $this->mycrows = array();
        if ($this->prow) {
            $this->crows = $this->prow->all_comments();
            foreach ($this->crows as $cid => $crow)
                if ($Me->can_view_comment($this->prow, $crow, null))
                    $this->mycrows[$cid] = $crow;
        }
    }

    function fixReviewMode() {
        global $Conf, $Me;
        $prow = $this->prow;
        if ($this->mode === "re" && $this->rrow
            && !$Me->can_review($prow, $this->rrow, false)
            && ($this->rrow->contactId != $Me->contactId
                || $this->rrow->reviewSubmitted))
            $this->mode = "p";
        if ($this->mode === "p" && $this->rrow
            && !$Me->can_view_review($prow, $this->rrow, null))
            $this->rrow = $this->editrrow = null;
        if ($this->mode === "p" && !$this->rrow && !$this->editrrow
            && !$Me->can_view_review($prow, $this->rrow, null)
            && $Me->can_review($prow, $this->rrow, false))  {
            $this->mode = "re";
            foreach ($this->all_rrows as $rr)
                if ($rr->contactId == $Me->contactId
                    || (!$this->editrrow && $Me->is_my_review($rr)))
                    $this->editrrow = $rr;
        }
        if ($this->mode === "p" && $prow && !count($this->viewable_rrows)
            && !count($this->mycrows)
            && $prow->has_author($Me)
            && !$Me->allow_administer($prow)
            && ($Conf->timeFinalizePaper($prow) || $prow->timeSubmitted <= 0))
            $this->mode = "edit";
    }
}
