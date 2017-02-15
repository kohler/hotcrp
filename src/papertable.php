<?php
// papertable.php -- HotCRP helper class for producing paper tables
// HotCRP is Copyright (c) 2006-2017 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

class PaperTable {
    const ENABLESUBMIT = 8;

    public $conf;
    public $prow;
    private $all_rrows = null;
    public $viewable_rrows = null;
    var $crows = null;
    private $mycrows;
    private $can_view_reviews = false;
    var $rrow = null;
    var $editrrow = null;
    var $mode;
    private $prefer_approvable = false;
    private $allreviewslink;
    private $edit_status = null;

    public $editable;
    public $edit_fields;
    public $edit_fields_position;

    private $qreq;
    private $useRequest;
    private $npapstrip = 0;
    private $npapstrip_tag_entry;
    private $allFolded;
    private $matchPreg;
    private $watchCheckbox = WATCHTYPE_COMMENT;
    private $entryMatches;
    private $canUploadFinal;
    private $admin;
    private $cf = null;
    private $quit = false;

    static private $textAreaRows = array("title" => 1, "abstract" => 5, "authorInformation" => 5, "collaborators" => 5);

    function __construct($prow, $qreq, $mode = null) {
        global $Conf, $Me;

        $this->conf = $Conf;
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
        else if ($prow->timeWithdrawn > 0 && !$this->conf->timeUpdatePaper($prow))
            $ms["p"] = true;
        if ($Me->can_review($prow, null))
            $ms["re"] = true;
        if ($Me->can_view_paper($prow) && $Me->allow_administer($prow))
            $ms["p"] = true;
        if ($prow->has_author($Me)
            && ($this->conf->timeFinalizePaper($prow) || $prow->timeSubmitted <= 0))
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
        else if ($mode === "rea") {
            $mode = "re";
            $this->prefer_approvable = true;
        }
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
        if (($mpreg = $this->conf->session("temp_matchPreg"))) {
            $matcher = self::_combine_match_preg($matcher, $mpreg);
            $this->conf->save_session("temp_matchPreg", null);
        }
        foreach ($matcher as $k => $v)
            if (is_string($v) && $v !== "") {
                if ($v[0] !== "{")
                    $v = "{(" . $v . ")}i";
                $this->matchPreg[$k] = $v;
            } else if (is_object($v))
                $this->matchPreg[$k] = $v;
        if (empty($this->matchPreg))
            $this->matchPreg = null;
    }

    private static function _combine_match_preg($m1, $m) {
        if (is_object($m))
            $m = get_object_vars($m);
        if (!is_array($m))
            $m = ["abstract" => $m, "title" => $m,
                  "authorInformation" => $m, "collaborators" => $m];
        foreach ($m as $k => $v)
            if (!isset($m1[$k]) || !$m1[$k])
                $m1[$k] = $v;
        return $m1;
    }

    function initialize($editable, $useRequest) {
        global $Me;
        $this->editable = $editable;
        $this->useRequest = $useRequest;
        $this->allFolded = $this->mode === "re" || $this->mode === "assign"
            || ($this->mode !== "edit"
                && (count($this->all_rrows) || count($this->crows)));
    }

    function set_edit_status(PaperStatus $status) {
        $this->edit_status = $status;
    }

    function can_view_reviews() {
        return $this->can_view_reviews;
    }

    static public function do_header($paperTable, $id, $action_mode) {
        global $Conf, $Me;
        $prow = $paperTable ? $paperTable->prow : null;
        $format = 0;

        $t = '<div id="header_page" class="header_page_submission"><div id="header_page_submission_inner"><h1 class="paptitle';

        if (!$paperTable && !$prow) {
            if (($pid = req("paperId")) && ctype_digit($pid))
                $title = "#$pid";
            else
                $title = $Conf->_c("paper_title", "Submission");
            $t .= '">' . $title;
        } else if (!$prow) {
            $title = $Conf->_c("paper_title", "New submission");
            $t .= '">' . $title;
        } else {
            $title = "#" . $prow->paperId;
            $viewable_tags = $prow->viewable_tags($Me);
            if ($viewable_tags || $Me->can_view_tags($prow)) {
                $t .= ' has-tag-classes';
                if (($color = $prow->conf->tags()->color_classes($viewable_tags)))
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
            if ($viewable_tags && $Conf->tags()->has_decoration) {
                $tagger = new Tagger;
                $t .= $tagger->unparse_decoration_html($viewable_tags);
            }
        }

        $t .= '</h1></div></div>';
        if ($paperTable && $prow)
            $t .= $paperTable->_paptabBeginKnown();

        $Conf->header($title, $id, actionBar($action_mode, $prow), $t);
        if ($format)
            echo Ht::unstash_script("render_text.on_page()");
    }

    private function abstract_foldable($abstract) {
        return strlen($abstract) > 190;
    }

    private function echoDivEnter() {
        global $Me;

        $folds = ["a" => true, "p" => $this->allFolded, "b" => $this->allFolded, "t" => $this->allFolded];
        foreach (["a", "p", "b", "t"] as $k)
            if (!$this->conf->session("foldpaper$k", 1))
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
        $folders = array("clearfix");
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

    public function has_problem_at($f) {
        if ($f === "authorInformation")
            $f = "authors";
        return $this->edit_status && $this->edit_status->has_problem_at($f);
    }

    public function error_class($f) {
        return $this->has_problem_at($f) ? " error" : "";
    }

    private function editable_papt($what, $name, $extra = array()) {
        $id = get($extra, "id");
        return '<div class="papeg' . ($id ? " papg_$id" : "")
            . '"><div class="papet' . $this->error_class($what)
            . ($id ? "\" id=\"$id" : "")
            . '"><span class="papfn">' . $name . '</span></div>';
    }

    public function messages_for($field) {
        if ($this->edit_status && ($ms = $this->edit_status->messages_at($field, true))) {
            $status = array_reduce($ms, function ($c, $m) { return max($c, $m[2]); }, 0);
            $statusmap = ["xinfo", "warning", "error"];
            return Ht::xmsg($statusmap[$status], '<div class="multimessage"><div class="mmm">' . join("</div>\n<div class=\"mmm\">", array_map(function ($m) { return $m[1]; }, $ms)) . '</div></div>');
        } else
            return "";
    }

    private function papt($what, $name, $extra = array()) {
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

        $c = "<div class=\"$divclass" . $this->error_class($what);
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
                . "<a class=\"xx need-tooltip\" href=\"" . selfHref(array("atab" => $what))
                . "\" onclick=\"return foldup(this,event$foldnumarg)\" data-tooltip=\"Edit\">"
                . "<span class=\"psteditimg\">"
                . Ht::img("edit48.png", "[Edit]", "editimg")
                . "</span>&nbsp;<u class=\"x\">Edit</u></a></span>";
        }
        if (isset($extra["float"]))
            $c .= $extra["float"];
        $c .= "</div>";
        return $c;
    }

    private function editable_textarea($fieldName) {
        if ($this->useRequest && isset($this->qreq[$fieldName]))
            $text = $this->qreq[$fieldName];
        else
            $text = $this->prow ? $this->prow->$fieldName : "";
        return Ht::textarea($fieldName, $text, ["class" => "papertext" . $this->error_class($fieldName), "rows" => self::$textAreaRows[$fieldName], "cols" => 60, "spellcheck" => $fieldName === "abstract" ? "true" : null]);
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

    private function field_name($name) {
        return $this->conf->_c("paper_edit_field", $name);
    }

    private function field_hint($name, $itext = "") {
        $t = $this->conf->_ci("paper_edit_description", $name, $itext);
        if ($t !== "")
            return '<div class="paphint">' . $t . '</div>';
        return "";
    }

    private function echo_editable_title() {
        echo $this->editable_papt("title", $this->field_name("Title")),
            $this->messages_for("title"),
            $this->field_hint("Title"),
            '<div class="papev">', $this->editable_textarea("title"), "</div></div>\n\n";
    }

    static public function pdf_stamps_html($data, $options = null) {
        global $Conf;
        $tooltip = !$options || !get($options, "notooltip");

        $t = array();
        $tm = defval($data, "timestamp", defval($data, "timeSubmitted", 0));
        if ($tm > 0)
            $t[] = ($tooltip ? '<span class="nb need-tooltip" data-tooltip="Time of most recent update">' : '<span class="nb">')
                . '<svg width="12" height="12" viewBox="0 0 96 96" style="vertical-align:-2px"><path style="fill:#333" d="M48 6a42 42 0 1 1 0 84 42 42 0 1 1 0-84zm0 10a32 32 0 1 0 0 64 32 32 0 1 0 0-64z"/><path style="fill:#333" d="M48 19A5 5 0 0 0 43 24V46c0 2.352.37 4.44 1.464 5.536l12 12c4.714 4.908 12-2.36 7-7L53 46V24A5 5 0 0 0 43 24z"/></svg>'
                . " " . $Conf->unparse_time_full($tm) . "</span>";
        $sha1 = defval($data, "sha1");
        if ($sha1)
            $t[] = ($tooltip ? '<span class="nb need-tooltip" data-tooltip="SHA-1 checksum">' : '<span class="nb">')
                . '<svg width="12" height="12" viewBox="0 0 48 48" style="vertical-align:-2px"><path style="fill:#333" d="M19 32l-8-8-7 7 14 14 26-26-6-6-19 19z"/><path style="fill:#333" d="M15 3V10H8v5h7v7h5v-7H27V10h-7V3h-5z"/></svg>'
                . " " . bin2hex($sha1) . "</span>";
        if (!empty($t))
            return '<span class="hint">' . join(" <span class='barsep'>·</span> ", $t) . "</span>";
        else
            return "";
    }

    private function paptabDownload() {
        global $Me;
        assert(!$this->editable);
        $prow = $this->prow;
        $out = array();

        // download
        if ($Me->can_view_pdf($prow)) {
            $pdfs = array();

            $dprefix = "";
            $dtype = $prow->finalPaperStorageId > 1 ? DTYPE_FINAL : DTYPE_SUBMISSION;
            if (($doc = $prow->document($dtype)) && $doc->paperStorageId > 1) {
                if (($stamps = self::pdf_stamps_html($doc)))
                    $stamps = "<span class='sep'></span>" . $stamps;
                if ($dtype == DTYPE_FINAL)
                    $dname = $this->conf->_c("paper_pdf_name", "Final version");
                else if ($prow->timeSubmitted > 0)
                    $dname = $this->conf->_c("paper_pdf_name", "Submission");
                else
                    $dname = $this->conf->_c("paper_pdf_name", "Draft submission");
                $pdfs[] = $dprefix . $doc->link_html('<span class="pavfn">' . $dname . '</span>', DocumentInfo::L_REQUIREFORMAT) . $stamps;
            }

            foreach ($prow ? $prow->options() : [] as $id => $ov)
                if ($ov->option->display() === PaperOption::DISP_SUBMISSION
                    && $ov->option->has_document()
                    && $Me->can_view_paper_option($prow, $ov->option)) {
                    foreach ($ov->documents() as $d) {
                        $name = '<span class="pavfn">' . htmlspecialchars($ov->option->name) . '</span>';
                        if ($ov->option->has_attachments())
                            $name .= "/" . htmlspecialchars($d->unique_filename);
                        $pdfs[] = $d->link_html($name);
                    }
                }

            if ($prow->finalPaperStorageId > 1 && $prow->paperStorageId > 1)
                $pdfs[] = "<small>" . $prow->document(DTYPE_SUBMISSION)->link_html("Submission version", DocumentInfo::L_SMALL | DocumentInfo::L_NOSIZE) . "</small>";

            foreach ($pdfs as $p)
                $out[] = '<p class="xd">' . $p . '</p>';
        }

        // conflicts
        if ($Me->isPC && !$prow->has_conflict($Me)
            && $this->conf->timeUpdatePaper($prow) && $this->mode !== "assign"
            && $this->mode !== "contact")
            $out[] = Ht::xmsg("warning", 'The authors still have <a href="' . hoturl("deadlines") . '">time</a> to make changes.');

        echo join("", $out);
    }

    private function is_ready() {
        return $this->is_ready_checked() && ($this->prow || $this->conf->opt("noPapers"));
    }

    private function is_ready_checked() {
        if ($this->useRequest)
            return !!$this->qreq->submitpaper;
        else if ($this->prow && $this->prow->timeSubmitted > 0)
            return true;
        else
            return !$this->conf->setting("sub_freeze")
                && (!$this->prow
                    || (!$this->conf->opt("noPapers") && $this->prow->paperStorageId <= 1));
    }

    private function echo_editable_complete() {
        $checked = $this->is_ready_checked();
        echo "<div id='foldisready' class='",
            (($this->prow && $this->prow->paperStorageId > 1)
             || $this->conf->opt("noPapers") ? "foldo" : "foldc"),
            "'><table class='fx'><tr><td class='nw'>",
            Ht::checkbox("submitpaper", 1, $checked, ["id" => "paperisready", "onchange" => "paperform_checkready()"]), "&nbsp;";
        if ($this->conf->setting('sub_freeze'))
            echo "</td><td>", Ht::label("<strong>" . $this->conf->_("The submission is complete.") . "</strong>"),
                "</td></tr><tr><td></td><td><small>You must complete your submission before the deadline or it will not be reviewed. Completed submissions are frozen and cannot be changed further.</small>";
        else
            echo Ht::label("<strong>" . $this->conf->_("The submission is ready for review.") . "</strong>");
        echo "</td></tr></table></div>\n";
        Ht::stash_script("$(function(){var x=\$\$(\"paperUpload\");if(x&&x.value)fold(\"isready\",0);paperform_checkready()})");
    }

    public function echo_editable_document(PaperOption $docx, $storageId, $flags) {
        global $Me;

        $prow = $this->prow;
        $docclass = $this->conf->docclass($docx->id);
        $documentType = $docx->id;
        $optionType = $docx->type;
        $main_submission = ($documentType == DTYPE_SUBMISSION || $documentType == DTYPE_FINAL);

        $filetypes = array();
        $accepts = array();
        if ($documentType == DTYPE_SUBMISSION
            && ($this->conf->opt("noPapers") === 1 || $this->conf->opt("noPapers") === true))
            return;

        $accepts = $docx->mimetypes();
        $field = $docx->field_key();
        $msgs = [];
        if (($accepts = $docx->mimetypes()))
            $msgs[] = htmlspecialchars(Mimetype::description($accepts));
        $msgs[] = "max " . ini_get("upload_max_filesize") . "B";
        echo $this->editable_papt($field, $this->field_name(htmlspecialchars($docx->name)) . ' <span class="papfnh">(' . join(", ", $msgs) . ")</span>");
        echo $this->field_hint(htmlspecialchars($docx->name), $docx->description);
        echo $this->messages_for($field);
        echo '<div class="papev">';
        if ($optionType)
            echo Ht::hidden("has_opt$docx->id", 1);

        // current version, if any
        $doc = null;
        $has_cf = false;
        $inputid = ($optionType ? "opt" . $documentType : "paperUpload");
        if ($prow && $Me->can_view_pdf($prow) && $storageId > 1
            && (($doc = $prow->document($documentType, $storageId, true)))) {
            if ($doc->mimetype === "application/pdf") {
                if (!$this->cf)
                    $this->cf = new CheckFormat(CheckFormat::RUN_NO);
                if (($has_cf = $this->cf->has_spec($documentType)))
                    $this->cf->check_document($prow, $doc);
            }

            echo "<table id='current_$inputid'><tr>",
                "<td class='nw'>", $doc->link_html(), "</td><td>";
            if (($stamps = self::pdf_stamps_html($doc)))
                echo "<span class='sep'></span>", $stamps;
            if ($has_cf && ($this->cf->failed || $this->cf->need_run))
                echo "<span class='sep'></span><a href='#' onclick='return docheckformat.call(this, $documentType)'>Check format</a>";
            else if ($has_cf) {
                if (!$this->cf->has_problem())
                    echo "<span class='sep'></span><span class=\"confirm\">Format OK</span>";
                if ($this->cf->possible_run)
                    echo '<span class="sep"></span><a href="#" onclick="return docheckformat.call(this, ', $documentType, ')">Recheck format</a>';
            }
            echo "</td></tr></table>\n";
        }

        // uploader
        $uploader = "";
        if ($doc) {
            echo '<div class="g" id="removable_', $inputid, '">';
            $uploader .= 'Replace:&nbsp; ';
        }
        $uploader .= "<input id='$inputid' type='file' name='$inputid'";
        if (count($accepts) == 1)
            $uploader .= " accept='" . $accepts[0]->mimetype . "'";
        $uploader .= ' size="30"';
        if ($documentType == DTYPE_SUBMISSION || ($flags & self::ENABLESUBMIT)) {
            $uploader .= ' onchange="false';
            if ($documentType == DTYPE_SUBMISSION)
                $uploader .= ";fold('isready',0);paperform_checkready()";
            if ($flags & self::ENABLESUBMIT)
                $uploader .= ";form.submitpaper.disabled=false";
            $uploader .= '"';
        }
        $uploader .= " />";
        if ($doc && $optionType)
            $uploader .= " <span class='barsep'>·</span> "
                . "<a id='remover_$inputid' href='#remover_$inputid' onclick='return doremovedocument(this)'>Delete</a>";
        if ($doc)
            $uploader .= "</div>";

        if ($has_cf) {
            $cf_open = !$this->cf->failed && $this->cf->has_problem();
            echo '<div id="foldcheckformat', $documentType, '" class="',
                $cf_open ? "foldo" : "foldc", '" data-docid="', $doc->paperStorageId, '">';
            if ($cf_open)
                echo $this->cf->document_report($prow, $doc);
            echo '</div>';
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
            $this->echo_editable_document($this->conf->paper_opts->find_document(DTYPE_FINAL), $this->prow ? $this->prow->finalPaperStorageId : 0, $flags);
        else
            $this->echo_editable_document($this->conf->paper_opts->find_document(DTYPE_SUBMISSION), $this->prow ? $this->prow->paperStorageId : 0, $flags);
        echo "</div>\n\n";
    }

    private function echo_editable_abstract() {
        $title = $this->field_name("Abstract");
        if ($this->conf->opt("noAbstract") === 2)
            $title .= ' <span class="papfnh">(optional)</span>';
        echo $this->editable_papt("abstract", $title),
            $this->field_hint("Abstract"),
            $this->messages_for("abstract"),
            '<div class="papev abstract">';
        if (($f = $this->conf->format_info($this->prow ? $this->prow->paperFormat : null))
            && ($t = get($f, "description")))
            echo $t;
        echo $this->editable_textarea("abstract"),
            "</div></div>\n\n";
    }

    private function paptabAbstract() {
        $text = $this->entryData("abstract");
        if (trim($text) === "" && $this->conf->opt("noAbstract"))
            return false;
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
            echo ' need-format" data-format="', $format, '.abs';
            Ht::stash_script('$(render_text.on_page)', 'render_on_page');
        } else
            $text = Ht::link_urls(Text::single_line_paragraphs($text));
        echo '">', $text, "</div>";
        if ($extra)
            echo '<div class="fn6 textdiv-shade"></div>',
                '<div class="fn6 textdiv-expander"><a class="x" href="#" onclick="return foldup(this,event,{n:6,s:\'foldpaperb\'})">[more]</a></div>';
        echo "</div></div></div></div>\n";
        if ($extra)
            echo Ht::unstash_script("render_text.on_page()");
        return true;
    }

    private function editable_authors_tr($n, $name, $email, $aff, $max_authors) {
        $t = '<tr>';
        if ($max_authors != 1)
            $t .= '<td class="rxcaption">' . $n . '.</td>';
        return $t . '<td class="lentry">'
            . Ht::entry("auname$n", $name, array("size" => "35", "onchange" => "author_change(this)", "placeholder" => "Name", "class" => "need-autogrow eauname" . $this->error_class("auname$n"))) . ' '
            . Ht::entry("auemail$n", $email, array("size" => "30", "onchange" => "author_change(this)", "placeholder" => "Email", "class" => "need-autogrow eauemail" . $this->error_class("auemail$n"))) . ' '
            . Ht::entry("auaff$n", $aff, array("size" => "32", "onchange" => "author_change(this)", "placeholder" => "Affiliation", "class" => "need-autogrow eauaff" . $this->error_class("auaff$n")))
            . '<span class="nb btnbox aumovebox"><a href="#" class="qx btn need-tooltip" data-tooltip="Move up" onclick="return author_change(this,-1)" tabindex="-1">&#x25b2;</a><a href="#" class="qx btn need-tooltip" data-tooltip="Move down" onclick="return author_change(this,1)" tabindex="-1">&#x25bc;</a><a href="#" class="qx btn need-tooltip" data-tooltip="Delete" onclick="return author_change(this,Infinity)" tabindex="-1">✖</a></span></td></tr>';
    }

    private function echo_editable_authors() {
        $max_authors = (int) $this->conf->opt("maxAuthors");
        $min_authors = $max_authors > 0 ? min(5, $max_authors) : 5;

        echo $this->editable_papt("authors", $this->conf->_c("paper_edit_field", "Authors", $max_authors));
        $hint = "List the authors, including email addresses and affiliations.";
        if ($this->conf->submission_blindness() == Conf::BLIND_ALWAYS)
            $hint .= " Submission is blind, so reviewers will not be able to see author information.";
        $hint .= " Any author with an account on this site can edit the submission.";
        echo $this->field_hint("Authors", $hint),
            $this->messages_for("authors"),
            '<div class="papev"><table id="auedittable" class="auedittable">',
            '<tbody data-last-row-blank="true" data-min-rows="', $min_authors, '" ',
            ($max_authors > 0 ? 'data-max-rows="' . $max_authors . '" ' : ''),
            'data-row-template="', htmlspecialchars($this->editable_authors_tr('$', "", "", "", $max_authors)), '">';

        $blankAu = array("", "", "", "");
        if ($this->useRequest) {
            for ($n = 1; $this->qreq["auname$n"] || $this->qreq["auemail$n"] || $this->qreq["auaff$n"]; ++$n)
                echo $this->editable_authors_tr($n, (string) $this->qreq["auname$n"], (string) $this->qreq["auemail$n"], (string) $this->qreq["auaff$n"], $max_authors);
        } else {
            $aulist = $this->prow ? $this->prow->author_list() : array();
            for ($n = 1; $n <= count($aulist); ++$n) {
                $au = $aulist[$n - 1];
                if ($au->firstName && $au->lastName && !preg_match('@^\s*(v[oa]n\s+|d[eu]\s+)?\S+(\s+jr.?|\s+sr.?|\s+i+)?\s*$@i', $au->lastName))
                    $auname = $au->lastName . ", " . $au->firstName;
                else
                    $auname = $au->name();
                echo $this->editable_authors_tr($n, $auname, $au->email, $au->affiliation, $max_authors);
            }
        }
        if ($max_authors <= 0 || $n <= $max_authors)
            do {
                echo $this->editable_authors_tr($n, "", "", "", $max_authors);
                ++$n;
            } while ($n <= $min_authors);
        echo "</tbody></table></div></div>\n\n";
    }

    private function authorData($table, $type, $viewAs = null, $prefix = "") {
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
        // clean author information
        $aulist = $this->prow->author_list();

        // find contact author information, combine with author table
        $result = $this->conf->qe("select firstName, lastName, '' affiliation, email, contactId from ContactInfo where contactId?a", array_keys($this->prow->contacts()));
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
                Contact::set_sorter($row, $this->conf);
                $contacts[] = $row;
            }
        }

        uasort($contacts, "Contact::compare");
        return array($aulist, $contacts);
    }

    private function paptabAuthors($skip_contacts) {
        global $Me;

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
            '<div class="pavt childfold', $this->error_class("authors"),
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
        echo '</span></div>';

        // contents
        $inauthors = "";
        if ($viewable && $this->conf->submission_blindness() == Conf::BLIND_OPTIONAL && $this->prow->blind)
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
        global $Me;
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
            $ox = $o->unparse_page_html($this->prow, $oa);
            if (!is_array($ox))
                $ox = [$ox, false];
            if ($ox[0] === null || $ox[0] === "")
                continue;

            // display it
            $on = htmlspecialchars($o->name);
            $folded = $showAllOptions && !$Me->can_view_paper_option($this->prow, $o, false);
            if ($o->display() !== PaperOption::DISP_TOPICS) {
                $x = '<div class="pgsm' . ($folded ? " fx8" : "") . '">'
                    . '<div class="pavt"><span class="pavfn">'
                    . ($ox[1] ? $ox[0] : $on) . "</span>"
                    . '</div>';
                if (!$ox[1])
                    $x .= '<div class="pavb">' . $ox[0] . "</div>";
                $xoptionhtml[] = $x . "</div>\n";
            } else {
                if ($ox[1])
                    $x = '<div>' . $ox[0] . '</div>';
                else {
                    if ($ox[0][0] !== "<"
                        || !preg_match('/\A((?:<(?:div|p).*?>)*)([\s\S]*)\z/', $ox[0], $cm))
                        $cm = [null, "", $ox[0]];
                    $x = '<div class="papov">' . $cm[1] . '<span class="papon">'
                        . $on . ':</span> ' . $cm[2] . '</div>';
                }
                if ($folded) {
                    $x = "<span class='fx8'>" . $x . "</span>";
                    ++$nfolded;
                }
                $optionhtml[] = $x . "\n";
                if ($o->has_document())
                    ++$ndocuments;
            }
        }

        if (!empty($xoptionhtml))
            echo '<div class="pg">', join("", $xoptionhtml), "</div>\n";

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

            if (!empty($optionhtml)) {
                echo "<div class='pg", ($extra ? "" : $eclass),
                    ($nfolded == count($optionhtml) ? " fx8" : ""), "'>",
                    $this->papt("options", array($options_name, $tanda), $extra),
                    "<div class=\"pavb$eclass\">", join("", $optionhtml), "</div></div>\n\n";
            }
        }
    }

    private function echo_editable_new_contact_author() {
        global $Me;
        echo $this->editable_papt("contactAuthor", $this->field_name("Contact")),
            $this->field_hint("Contact", "You can add more contacts after you register the submission."),
            '<div class="papev">';
        $name = $this->useRequest ? trim((string) $this->qreq->newcontact_name) : "";
        $email = $this->useRequest ? trim((string) $this->qreq->newcontact_email) : "";
        echo '<table><tr><td class="lcaption">Add</td>',
            '<td></td><td>',
            Ht::entry('newcontact_name', $name,
                      ["id" => "newcontact_name", "size" => 30, "placeholder" => "Name"]),
            '&nbsp;&nbsp;',
            Ht::entry('newcontact_email', $email,
                      ["id" => "newcontact_email", "size" => 20, "placeholder" => "Email"]),
            '</td></tr></table>';
        echo "</div></div>\n\n";
    }

    private function echo_editable_contact_author($always_unfold = false) {
        global $Me;
        $paperId = $this->prow->paperId;
        list($aulist, $contacts) = $this->_analyze_authors();

        $cerror = $this->has_problem_at("contactAuthor") || $this->has_problem_at("contacts");
        $open = $cerror || $always_unfold
            || ($this->useRequest && $this->qreq->setcontacts == 2);
        echo Ht::hidden("setcontacts", $open ? 2 : 1, array("id" => "setcontacts")),
            '<div id="foldcontactauthors" class="papeg ',
            ($open ? "foldo" : "foldc"),
            '"><div class="papet childfold fn0" ',
            "onclick=\"\$\$('setcontacts').value=2;return foldup(this,event)\"",
            '><span class="papfn"><a class="qq" href="#" ',
            "onclick=\"\$\$('setcontacts').value=2;return foldup(this,event)\"",
            ' title="Edit contacts">', expander(true),
            $this->field_name("Contacts"),
            '</a></span></div>',
            '<div class="papet fx0',
            ($cerror ? " error" : ""),
            '"><span class="papfn">',
            $this->field_name("Contacts"),
            '</span></div>';

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
            'Contacts are HotCRP users who can edit the submission and view reviews. Authors with HotCRP accounts are always contacts, but you can add additional contacts who aren’t in the author list or create accounts for authors who haven’t yet logged in.',
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
                echo Ht::checkbox($control, Text::name_text($au), $checked);
            echo '&nbsp;</td><td>', Ht::label(Text::user_html_nolink($au)),
                '</td></tr>';
            $title = "";
        }
        $title = "Non-authors";
        foreach ($contacts as $au) {
            $control = "contact_" . html_id_encode($au->email);
            $checked = $this->useRequest ? $this->qreq[$control] : true;
            echo '<tr><td class="lcaption">', $title, '</td>',
                '<td>', Ht::checkbox($control, Text::name_text($au), $checked),
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
                            "placeholder" => "Name",
                            "class" => $cerror ? "error" : null)),
            '&nbsp;&nbsp;',
            Ht::entry('newcontact_email', $email,
                      array("id" => "newcontact_email", "size" => 20,
                            "placeholder" => "Email",
                            "class" => $cerror ? "error" : null)),
            '</td></tr>';
        echo "</table></div></div>\n\n";
    }

    private function echo_editable_anonymity() {
        $blind = ($this->useRequest ? !!$this->qreq->blind : (!$this->prow || $this->prow->blind));
        assert(!!$this->editable);
        echo $this->editable_papt("blind", Ht::checkbox("blind", 1, $blind)
                                  . "&nbsp;" . Ht::label($this->field_name("Anonymous submission"))),
            $this->field_hint("Anonymous submission", "Check this box to submit anonymously (reviewers won’t be shown the author list). Make sure you also remove your name from the submission itself!"),
            $this->messages_for("blind"),
            "</div>\n\n";
    }

    private function echo_editable_collaborators() {
        if (!$this->conf->setting("sub_collab"))
            return;
        $sub_pcconf = $this->conf->setting("sub_pcconf");
        assert(!!$this->editable);

        echo $this->editable_papt("collaborators", $this->field_name($sub_pcconf ? "Other conflicts" : "Potential conflicts")),
            "<div class='paphint'>";
        if ($this->conf->setting("sub_pcconf"))
            echo "List <em>other</em> people and institutions with which
        the authors have conflicts of interest.  This will help us avoid
        conflicts when assigning external reviews.  No need to list people
        at the authors’ own institutions.";
        else
            echo "List people and institutions with which the authors have
        conflicts of interest. ", $this->conf->message_html("conflictdef"), "
        Be sure to include conflicted <a href='", hoturl("users", "t=pc"), "'>PC members</a>.
        We use this information when assigning PC and external reviews.";
        echo " <strong>List one conflict per line.</strong> For example: &ldquo;<samp>Jelena Markovic (EPFL)</samp>&rdquo; or, for a whole institution, &ldquo;<samp>EPFL</samp>&rdquo;.</div>",
            $this->messages_for("collaborators"),
            '<div class="papev">',
            $this->editable_textarea("collaborators"),
            "</div></div>\n\n";
    }

    private function _papstripBegin($foldid = null, $folded = null, $extra = null) {
        global $Me;
        if (!$this->npapstrip) {
            echo '<div class="pspcard_container"><div class="pspcard">',
                '<div class="pspcard_body"><div class="pspcard_fold">',
                '<div style="float:right;margin-left:1em"><span class="psfn">More ', expander(true), '</span></div>';

            if ($this->prow && ($viewable = $this->prow->viewable_tags($Me))) {
                $tagger = new Tagger;
                $color = $this->prow->conf->tags()->color_classes($viewable);
                echo '<div class="', trim("has-tag-classes pscopen $color"), '">',
                    '<span class="psfn">Tags:</span> ',
                    $tagger->unparse_and_link($viewable, false),
                    '</div>';
            }

            echo '</div><div class="pspcard_open">';
            Ht::stash_script('$(".pspcard_fold").click(function(e){$(".pspcard_fold").hide();$(".pspcard_open").show();e.preventDefault();return false})');
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
                echo "\" $k=\"", str_replace("\"", "&quot;", $v);
        echo '">';
        ++$this->npapstrip;
    }

    private function papstripCollaborators() {
        if (!$this->conf->setting("sub_collab") || !$this->prow->collaborators
            || strcasecmp(trim($this->prow->collaborators), "None") == 0)
            return;
        $name = $this->conf->setting("sub_pcconf") ? "Other conflicts" : "Potential conflicts";
        $fold = $this->conf->session("foldpscollab", 1) ? 1 : 0;

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
        assert(!!$this->editable);
        $topicMode = (int) $this->useRequest;
        if (($topicTable = topicTable($this->prow, $topicMode))) {
            echo $this->editable_papt("topics", $this->field_name("Topics")),
                $this->field_hint("Topics", "Select any topics that apply to your submission."),
                $this->messages_for("topics"),
                '<div class="papev">',
                Ht::hidden("has_topics", 1),
                $topicTable,
                "</div></div>\n\n";
        }
    }

    public function echo_editable_option_papt(PaperOption $o, $label = null) {
        echo $this->editable_papt("opt$o->id", $label ? : $this->field_name(htmlspecialchars($o->name)),
                                  ["id" => "opt{$o->id}_div"]);
        echo $this->field_hint(htmlspecialchars($o->name), $o->description);
        echo $this->messages_for("opt$o->id"), Ht::hidden("has_opt$o->id", 1);
    }

    private function make_echo_editable_option($o) {
        return function () use ($o) {
            $ov = null;
            if ($this->prow)
                $ov = $this->prow->option($o->id);
            $ov = $ov ? : new PaperOptionValue($this->prow, $o);
            $o->echo_editable_html($ov, $this->useRequest ? $this->qreq["opt$o->id"] : null, $this);
        };
    }

    private function echo_editable_pc_conflicts() {
        global $Me;

        assert(!!$this->editable);
        if (!$this->conf->setting("sub_pcconf"))
            return;
        $pcm = pcMembers();
        if (!count($pcm))
            return;

        $selectors = $this->conf->setting("sub_pcconfsel");
        $show_colors = $Me->can_view_reviewer_tags($this->prow);

        $conflict = array();
        if ($this->useRequest) {
            foreach ($pcm as $id => $row)
                if (isset($this->qreq["pcc$id"])
                    && ($ct = cvtint($this->qreq["pcc$id"])) > 0)
                    $conflict[$id] = Conflict::force_author_mark($ct, $this->admin);
        }
        if ($this->prow) {
            $result = $this->conf->qe_raw("select contactId, conflictType from PaperConflict where paperId=" . $this->prow->paperId);
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
            $extra = array("class" => "pctbconfselector");
            if ($this->admin) {
                $ctypes["xsep"] = null;
                $ctypes[CONFLICT_CHAIRMARK] = "Confirmed conflict";
                $extra["optionstyles"] = array(CONFLICT_CHAIRMARK => "font-weight:bold");
            }
        }

        echo $this->editable_papt("pcconf", $this->field_name("PC conflicts")),
            "<div class='paphint'>Select the PC members who have conflicts of interest with this submission. ", $this->conf->message_html("conflictdef"), "</div>\n",
            $this->messages_for("pcconf"),
            '<div class="papev">',
            Ht::hidden("has_pcconf", 1),
            '<div class="pc_ctable">';
        foreach ($pcm as $id => $p) {
            $label = Ht::label($Me->name_html_for($p), "pcc$id", array("class" => "taghl"));
            if ($p->affiliation)
                $label .= '<div class="pcconfaff">' . htmlspecialchars(UnicodeHelper::utf8_abbreviate($p->affiliation, 60)) . '</div>';
            $ct = defval($conflict, $id, $nonct);

            echo '<div class="ctelt"><div class="ctelti clearfix';
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
                    Ht::checkbox("pcc$id", $checked ? $ct->value : CONFLICT_AUTHORMARK,
                                 $checked, array("id" => "pcc$id", "disabled" => $disabled)),
                    '&nbsp;</td><td>', $label, '</td></tr></table>';
            }
            echo "</div></div>";
        }
        echo "</div>\n</div></div>\n\n";
    }

    private function papstripPCConflicts() {
        global $Me;
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
        global $Me;
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
            $this->conf->stash_hotcrp_pc($Me);
            echo '<form class="fx"><div>',
                Ht::select($type, [], 0, ["class" => "psc-select need-pcselector want-focus", "style" => "width:99%", "data-pcselector-options" => join(" ", $selopt), "data-pcselector-selected" => $value]),
                '</div></form>';
            Ht::stash_script('make_pseditor("' . $type . '",{p:' . $this->prow->paperId . ',fn:"set' . $type . '"})');
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
        global $Me;
        if (!$this->prow || !$Me->can_view_tags($this->prow))
            return;
        $tags = $this->prow->all_tags_text();
        $is_editable = $Me->can_change_some_tag($this->prow);
        if ($tags === "" && !$is_editable)
            return;

        // Note that tags MUST NOT contain HTML special characters.
        $tagger = new Tagger;
        $viewable = $this->prow->viewable_tags($Me);

        $tx = $tagger->unparse_and_link($viewable, false);
        $unfolded = $is_editable && ($this->has_problem_at("tags") || $this->qreq->atab === "tags");

        $this->_papstripBegin("tags", !$unfolded, ["data-onunfold" => "save_tags.open()"]);
        $color = $this->prow->conf->tags()->color_classes($viewable);
        echo '<div class="', trim("has-tag-classes pscopen $color"), '">';

        if ($is_editable)
            echo Ht::form_div(hoturl("paper", "p=" . $this->prow->paperId), ["id" => "tagform", "onsubmit" => "return save_tags()"]);

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
            $editable = $tags;
            if ($this->prow)
                $editable = $this->prow->editable_tags($Me);
            echo '<div style="position:relative">',
                '<textarea cols="20" rows="4" name="tags" style="width:97%;margin:0" class="want-focus" tabindex="1000">',
                $tagger->unparse($editable),
                "</textarea></div>",
                '<div style="padding:1ex 0;text-align:right">',
                Ht::submit("cancel", "Cancel", ["tabindex" => 1001]),
                " &nbsp;", Ht::submit("save", "Save", ["tabindex" => 1000]),
                "</div>",
                "<span class='hint'><a href='", hoturl("help", "t=tags"), "'>Learn more</a> <span class='barsep'>·</span> <strong>Tip:</strong> Twiddle tags like &ldquo;~tag&rdquo; are visible only to you.</span>",
                "</div>";
        } else
            echo '<div class="taghl">', ($tx === "" ? "None" : $tx), '</div>';
        echo "</div>";

        if ($is_editable)
            echo "</div></form>";
        if ($unfolded) {
            Ht::stash_script('save_tags.open(1)');
            echo Ht::unstash();
        }
        echo "</div></div>\n";
    }

    function papstripOutcomeSelector() {
        $this->_papstripBegin("decision", $this->qreq->atab !== "decision");
        echo $this->papt("decision", "Decision", array("type" => "ps", "fold" => "decision")),
            '<div class="psv"><form class="fx"><div>';
        if (isset($this->qreq->forceShow))
            echo Ht::hidden("forceShow", $this->qreq->forceShow ? 1 : 0);
        echo decisionSelector($this->prow->outcome, null, " class=\"want-focus\" style=\"width:99%\""),
            '</div></form><p class="fn odname">',
            htmlspecialchars($this->conf->decision_name($this->prow->outcome)),
            "</p></div></div>\n";
        Ht::stash_script('make_pseditor("decision",{p:' . $this->prow->paperId . ',fn:"setdecision"})');
    }

    function papstripReviewPreference() {
        $this->_papstripBegin();
        echo $this->papt("revpref", "Review preference", array("type" => "ps")),
            "<div class=\"psv\"><form onsubmit=\"return false\"><div>";
        $rp = unparse_preference($this->prow);
        $rp = ($rp == "0" ? "" : $rp);
        echo "<input id=\"revprefform_d\" type=\"text\" class=\"revpref\" name=\"revpref", $this->prow->paperId,
            "\" size=\"4\" value=\"$rp\" tabindex=\"1\" class=\"want-focus want-select\" />",
            "</div></form></div></div>\n";
        Ht::stash_script("add_revpref_ajax(\"#revprefform_d\");shortcut(\"revprefform_d\").add()");
        if (($l = SessionList::active()) && str_starts_with($l->url, "reviewprefs"))
            Ht::stash_script("crpfocus('revprefform')");
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
        $class = "is-nonempty-tags floatright";
        $extradiv = "";
        if ($type === "vote" || $type === "approval") {
            $class .= " need-tooltip";
            $extradiv = ' data-tooltip-dir="h" data-tooltip-content-promise="votereport(\'' . $tag . '\')"';
        }
        return '<div class="' . $class . '" style="display:' . ($totval ? "block" : "none")
            . '"' . $extradiv
            . '><a class="qq" href="' . hoturl("search", "q=" . urlencode("show:#$tag sort:" . ($reverse ? "-" : "") . "#$tag")) . '">'
            . '<span class="is-tag-index" data-tag-base="' . $tag . '">' . $totval . '</span> ' . $kind . '</a></div>';
    }

    private function papstrip_tag_entry_title($start, $tag, $value) {
        $title = $start . '<span class="fn is-nonempty-tags"';
        if ($value === "")
            $title .= ' style="display:none"';
        return $title . '>: <span class="is-tag-index" data-tag-base="' . $tag . '">' . $value . '</span></span>';
    }

    private function papstripRank($tag) {
        global $Me;
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
                      array("size" => 4, "tabindex" => 1,
                            "onchange" => "save_tag_index(this)",
                            "class" => "is-tag-index want-focus",
                            "data-tag-base" => "~$tag")),
            ' <span class="barsep">·</span> ',
            '<a href="', hoturl("search", "q=" . urlencode("editsort:#~$tag")), '">Edit all</a>',
            " <div class='hint' style='margin-top:4px'><strong>Tip:</strong> <a href='", hoturl("search", "q=" . urlencode("editsort:#~$tag")), "'>Search “editsort:#~{$tag}”</a> to drag and drop your ranking, or <a href='", hoturl("offline"), "'>use offline reviewing</a> to rank many papers at once.</div>",
            "</div></div></div></form></div>\n";
    }

    private function papstripVote($tag, $allotment) {
        global $Me;
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
                      array("size" => 4, "tabindex" => 1,
                            "onchange" => "save_tag_index(this)",
                            "class" => "is-tag-index want-focus",
                            "data-tag-base" => "~$tag")),
            " &nbsp;of $allotment",
            ' <span class="barsep">·</span> ',
            '<a href="', hoturl("search", "q=" . urlencode("editsort:-#~$tag")), '">Edit all</a>',
            "</div></div></div></form></div>\n";
    }

    private function papstripApproval($tag) {
        global $Me;
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
                                      array("tabindex" => 1,
                                            "onchange" => "save_tag_index(this)",
                                            "class" => "is-tag-index want-focus",
                                            "data-tag-base" => "~$tag",
                                            "style" => "padding-left:0;margin-left:0;margin-top:0"))
                         . "&nbsp;" . Ht::label("#$tag vote"),
                         array("type" => "ps", "float" => $totmark)),
            "</div></form></div>\n\n";
    }

    private function papstripWatch() {
        global $Me;
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
        $result = $this->conf->q_raw("select
        ContactInfo.contactId, reviewType, commentId, conflictType, watch
        from ContactInfo
        left join PaperReview on (PaperReview.paperId=$prow->paperId and PaperReview.contactId=ContactInfo.contactId)
        left join PaperComment on (PaperComment.paperId=$prow->paperId and PaperComment.contactId=ContactInfo.contactId)
        left join PaperConflict on (PaperConflict.paperId=$prow->paperId and PaperConflict.contactId=ContactInfo.contactId)
        left join PaperWatch on (PaperWatch.paperId=$prow->paperId and PaperWatch.contactId=ContactInfo.contactId)
        where ContactInfo.contactId=$Me->contactId");
        $row = edb_row($result);

        $this->_papstripBegin();

        if ($row[4] && ($row[4] & ($this->watchCheckbox << WATCHSHIFT_ISSET)))
            $watchValue = $row[4];
        else if ($row[1] || $row[2] || $row[3] >= CONFLICT_AUTHOR
                 || $prow->managerContactId == $Me->contactId)
            $watchValue = $Me->defaultWatch;
        else
            $watchValue = 0;

        echo "<form><div>",
            $this->papt("watch",
                        Ht::checkbox("follow", 1,
                                     $watchValue & ($this->watchCheckbox << WATCHSHIFT_ON),
                                     ["onchange" => "setfollow.call(this)",
                                      "style" => "padding-left:0;margin-left:0"])
                        . "&nbsp;" . Ht::label("Email notification"),
                        array("type" => "ps")),
            "<div class='pshint'>Select to receive email on updates to reviews and comments.</div>",
            "</div></form></div>\n\n";
    }


    // Functions for editing

    function deadlineSettingIs($dname) {
        $deadline = $this->conf->printableTimeSetting($dname, "span");
        if ($deadline === "N/A")
            return "";
        else if (time() < $this->conf->setting($dname))
            return " The deadline is $deadline.";
        else
            return " The deadline was $deadline.";
    }

    private function _override_message() {
        if ($this->admin)
            return " As an administrator, you can override this deadline.";
        else
            return "";
    }

    private function _edit_message_new_paper() {
        global $Now;
        $startDeadline = $this->deadlineSettingIs("sub_reg");
        $msg = "";
        if (!$this->conf->timeStartPaper()) {
            $sub_open = $this->conf->setting("sub_open");
            if ($sub_open <= 0 || $sub_open > $Now)
                $msg = "The conference site is not open for submissions." . $this->_override_message();
            else
                $msg = 'The <a href="' . hoturl("deadlines") . '">deadline</a> for registering submissions has passed.' . $startDeadline . $this->_override_message();
            if (!$this->admin) {
                $this->quit = true;
                return '<div class="merror">' . $msg . '</div>';
            }
            $msg = Ht::xmsg("info", $msg);
        }
        $t1 = $this->conf->_("Enter information about your paper.");
        if ($startDeadline && !$this->conf->setting("sub_freeze"))
            $t2 = "You can make changes until the deadline, but thereafter incomplete submissions will not be considered.";
        else if (!$this->conf->opt("noPapers"))
            $t2 = "You don’t have to upload the PDF right away, but incomplete submissions will not be considered.";
        else
            $t2 = "Incomplete submissions will not be considered.";
        $t2 = $this->conf->_($t2);
        $msg .= Ht::xmsg("info", space_join($t1, $t2, $startDeadline));
        if (($v = $this->conf->message_html("submit")))
            $msg .= Ht::xmsg("info", $v);
        return $msg;
    }

    private function _edit_message_for_author(PaperInfo $prow) {
        global $Me;
        $can_view_decision = $prow->outcome != 0 && $Me->can_view_decision($prow);
        if ($can_view_decision && $prow->outcome < 0)
            return Ht::xmsg("warning", "The submission was not accepted.");
        else if ($prow->timeWithdrawn > 0) {
            if ($Me->can_revive_paper($prow))
                return Ht::xmsg("warning", "The submission has been withdrawn, but you can still revive it." . $this->deadlineSettingIs("sub_update"));
        } else if ($prow->timeSubmitted <= 0) {
            if ($Me->can_update_paper($prow)) {
                if ($this->conf->setting("sub_freeze"))
                    $t = "This submission must be completed before it can be reviewed.";
                else if ($prow->paperStorageId <= 1 && !$this->conf->opt("noPapers"))
                    $t = "This submission is not ready for review and will not be considered as is, but you can still make changes.";
                else
                    $t = "This submission is not ready for review and will not be considered as is, but you can still mark it ready for review and make other changes if appropriate.";
                return Ht::xmsg("warning", $t . $this->deadlineSettingIs("sub_update"));
            } else if ($Me->can_finalize_paper($prow))
                return Ht::xmsg("warning", 'The submission is not ready for review. You cannot make any changes as the <a href="' . hoturl("deadlines") . '">deadline</a> has passed, but the current version can be still be submitted.' . $this->deadlineSettingIs("sub_sub") . $this->_override_message());
            else if ($this->conf->deadlinesBetween("", "sub_sub", "sub_grace"))
                return Ht::xmsg("warning", 'The site is not open for updates at the moment.' . $this->_override_message());
            else
                return Ht::xmsg("warning", 'The <a href="' . hoturl("deadlines") . '">submission deadline</a> has passed and the submission will not be reviewed.' . $this->deadlineSettingIs("sub_sub") . $this->_override_message());
        } else if ($Me->can_update_paper($prow)) {
            if ($this->mode === "edit")
                return Ht::xmsg("confirm", 'The submission is ready and will be considered for review. You can still make changes if necessary.' . $this->deadlineSettingIs("sub_update"));
        } else if ($this->conf->collectFinalPapers()
                   && $prow->outcome > 0 && $can_view_decision) {
            if ($Me->can_submit_final_paper($prow)) {
                if (($t = $this->conf->message_html("finalsubmit", array("deadline" => $this->deadlineSettingIs("final_soft")))))
                    return Ht::xmsg("info", $t);
            } else {
                $override2 = $this->admin ? " As an administrator, you can update the submission anyway." : "";
                if ($this->mode === "edit")
                    return Ht::xmsg("warning", "The deadline for updating final versions has passed. You can still update contact information, however.$override2");
            }
        } else {
            $override2 = ($this->admin ? " As an administrator, you can update the submission anyway." : "");
            if ($this->mode === "edit") {
                $t = "";
                if ($Me->can_withdraw_paper($prow))
                    $t = " or withdraw it from consideration";
                return Ht::xmsg("info", "The submission is under review and can’t be changed, but you can change its contacts$t.$override2");
            }
        }
        return "";
    }

    private function editMessage() {
        global $Me;
        if (!($prow = $this->prow))
            return $this->_edit_message_new_paper();

        $m = "";
        $has_author = $prow->has_author($Me);
        $can_view_decision = $prow->outcome != 0 && $Me->can_view_decision($prow);
        if ($has_author)
            $m .= $this->_edit_message_for_author($prow);
        else if ($this->conf->collectFinalPapers()
                 && $prow->outcome > 0 && !$prow->can_author_view_decision())
            $m .= Ht::xmsg("info", "The submission has been accepted, but its authors can’t see that yet. Once decisions are visible, the system will allow accepted authors to upload final versions.");
        else
            $m .= Ht::xmsg("info", "You aren’t a contact for this submission, but as an administrator you can still make changes.");
        if ($Me->can_update_paper($prow, true) && ($v = $this->conf->message_html("submit")))
            $m .= Ht::xmsg("info", $v);
        if ($this->edit_status && $this->edit_status->has_problem()
            && ($this->edit_status->has_problem_at("contacts") || $this->editable))
            $m .= Ht::xmsg("warning", "There are problems with the submission. Please scroll through the form and fix them as appropriate.");
        return $m;
    }

    function _collectActionButtons() {
        global $Me;
        $prow = $this->prow;
        $pid = $prow ? $prow->paperId : "new";

        // Withdrawn papers can be revived
        if ($prow && $prow->timeWithdrawn > 0) {
            $revivable = $this->conf->timeFinalizePaper($prow);
            if ($revivable)
                $b = Ht::submit("revive", "Revive submission", ["class" => "btn"]);
            else {
                $b = "The <a href='" . hoturl("deadlines") . "'>deadline</a> for reviving withdrawn submissions has passed.";
                if ($this->admin)
                    $b = array(Ht::js_button("Revive submission", "override_deadlines(this)", ["class" => "btn", "data-override-text" => $b, "data-override-submit" => "revive"]), "(admin only)");
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
                $whyNot = array_merge($prow ? $prow->initial_whynot() : [], ["deadline" => get($whyNot, "deadline"), "rejected" => get($whyNot, "rejected")]);
            else
                $whyNot = null;
            // produce button
            $save_name = $this->is_ready() ? "Save and resubmit" : "Save draft";
            if (!$whyNot)
                $buttons[] = array(Ht::submit($updater, $save_name, ["class" => "btn btn-default btn-savepaper"]), "");
            else if ($this->admin)
                $buttons[] = array(Ht::js_button($save_name, "override_deadlines(this)", ["class" => "btn btn-default btn-savepaper", "data-override-text" => whyNotText($whyNot, $prow ? "update" : "register"), "data-override-submit" => $updater]), "(admin only)");
            else if ($prow && $prow->timeSubmitted > 0)
                $buttons[] = array(Ht::submit("updatecontacts", "Save contacts", ["class" => "btn"]), "");
            else if ($this->conf->timeFinalizePaper($prow))
                $buttons[] = array(Ht::submit("update", $save_name, ["class" => "btn btn-savepaper"]));
        }

        // withdraw button
        if (!$prow || !$Me->can_withdraw_paper($prow, true))
            $b = null;
        else if ($prow->timeSubmitted <= 0)
            $b = Ht::submit("withdraw", "Withdraw");
        else {
            $b = Ht::button("Withdraw", ["onclick" => "popup(this,'w',0,true)"]);
            $admins = "";
            if ((!$this->admin || $prow->has_author($Me))
                && !$this->conf->timeFinalizePaper($prow))
                $admins = "Only administrators can undo this step.";
            $override = "";
            if (!$Me->can_withdraw_paper($prow))
                $override = "<div>" . Ht::checkbox("override", array("id" => "dialog_override")) . "&nbsp;"
                    . Ht::label("Override deadlines") . "</div>";
            Ht::stash_html("<div class='popupbg'><div id='popup_w' class='popupc'>
  <p>Are you sure you want to withdraw this submission from consideration and/or
  publication? $admins</p>\n"
    . Ht::form_div(hoturl_post("paper", "p=" . $prow->paperId . "&amp;m=edit"))
    . Ht::textarea("reason", null,
                   array("id" => "withdrawreason", "rows" => 3, "cols" => 40,
                         "style" => "width:99%", "placeholder" => "Optional explanation", "spellcheck" => "true"))
    . $override
    . Ht::hidden("doemail", 1, array("class" => "popup_populate"))
    . Ht::hidden("emailNote", "", array("class" => "popup_populate"))
    . "<div class='popup-actions'>"
    . Ht::submit("withdraw", "Withdraw", ["class" => "btn"])
    . Ht::js_button("Cancel", "popup(null,'w',1)", ["class" => "btn"])
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
        if ($this->admin && !$top) {
            $v = (string) $this->qreq->emailNote;
            echo "<div>", Ht::checkbox("doemail", 1, true), "&nbsp;",
                Ht::label("Email authors, including:"), "&nbsp; ",
                Ht::entry("emailNote", $v,
                          array("id" => "emailNote", "size" => 30, "placeholder" => "Optional explanation")), "</div>\n";
        }

        $buttons = $this->_collectActionButtons();

        if ($this->admin && $this->prow) {
            $buttons[] = array(Ht::js_button("Delete", "popup(this,'delp',0,true)", ["class" => "btn"]), "(admin only)");
            Ht::stash_html("<div class='popupbg'><div id='popup_delp' class='popupc'>"
    . Ht::form_div(hoturl_post("paper", "p={$this->prow->paperId}&amp;m=edit"))
    . "<p>Be careful: This will permanently delete all information about this submission from the database and <strong>cannot be undone</strong>.</p>\n"
    . Ht::hidden("doemail", 1, array("class" => "popup_populate"))
    . Ht::hidden("emailNote", "", array("class" => "popup_populate"))
    . "<div class='popup-actions'>"
    . Ht::submit("delete", "Delete", ["class" => "btn dangerous"])
    . Ht::js_button("Cancel", "popup(null,'delp',1)", ["class" => "btn"])
    . "</div></div></form></div></div>", "popup_delp");
        }

        echo Ht::actions($buttons, array("class" => "aab aabr aabig"));
    }


    // Functions for overall paper table viewing

    function _papstrip() {
        global $Me;
        $prow = $this->prow;
        if (($prow->managerContactId || ($Me->privChair && $this->mode === "assign"))
            && $Me->can_view_manager($prow))
            $this->papstripManager($Me->privChair);
        $this->papstripTags();
        $this->npapstrip_tag_entry = 0;
        foreach ($this->conf->tags() as $ltag => $t)
            if ($Me->can_change_tag($prow, "~$ltag", null, 0)) {
                if ($t->approval)
                    $this->papstripApproval($t->tag);
                else if ($t->vote)
                    $this->papstripVote($t->tag, $t->vote);
                else if ($t->rank)
                    $this->papstripRank($t->tag);
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
            && $this->conf->timePCReviewPreferences()
            && ($Me->roles & (Contact::ROLE_PC | Contact::ROLE_CHAIR)))
            $this->papstripReviewPreference();
        echo Ht::unstash_script("$(\".need-pcselector\").each(populate_pcselector)");
    }

    function _paptabTabLink($text, $link, $image, $highlight) {
        return '<div class="' . ($highlight ? "papmodex" : "papmode")
            . '"><a href="' . $link . '" class="xx">'
            . Ht::img($image, "[$text]", "papmodeimg")
            . "&nbsp;<u" . ($highlight ? ' class="x"' : "") . ">" . $text
            . "</u></a></div>\n";
    }

    private function _paptabBeginKnown() {
        global $Me;
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
            $t .= $this->_paptabTabLink("Main", hoturl("paper", "p=$prow->paperId$a"), "view48.png", $highlight);

            if ($canEdit)
                $t .= $this->_paptabTabLink("Edit", hoturl("paper", "p=$prow->paperId&amp;m=edit"), "edit48.png", $this->mode === "edit");

            if ($canReview)
                $t .= $this->_paptabTabLink("Review", hoturl("review", "p=$prow->paperId&amp;m=re"), "review48.png", $this->mode === "re" && (!$this->editrrow || $this->editrrow->contactId == $Me->contactId));

            if ($canAssign)
                $t .= $this->_paptabTabLink("Assign", hoturl("assign", "p=$prow->paperId"), "assign48.png", $this->mode === "assign");

            $t .= "</div>";
        }

        return $t;
    }

    static private function _echo_clickthrough($ctype) {
        global $Conf, $Now;
        $data = $Conf->message_html("clickthrough_$ctype");
        echo Ht::form(hoturl_post("profile"), ["onsubmit" => "return handle_clickthrough(this)", "data-clickthrough-enable" => ".editrevform input[name=submitreview], .editrevform input[name=savedraft]"]), "<div class='aahc'>", $data;
        $buttons = array(Ht::submit("clickthrough_accept", "Agree", array("class" => "btn btnbig btn-highlight")));
        echo "<div class='g'></div>",
            Ht::hidden("clickthrough", $ctype),
            Ht::hidden("clickthrough_sha1", sha1($data)),
            Ht::hidden("clickthrough_time", $Now),
            Ht::actions($buttons, ["class" => "aab aabig aabr"]), "</div></form>";
    }

    static public function echo_review_clickthrough() {
        echo '<div class="revcard clickthrough"><div class="revcard_head"><h3>Reviewing terms</h3></div><div class="revcard_body">', Ht::xmsg("error", "You must agree to these terms before you can save reviews.");
        self::_echo_clickthrough("review");
        echo "</form></div></div>";
    }

    private function add_edit_field($prio, $callback, $name) {
        $this->edit_fields[] = [$prio, count($this->edit_fields), $callback, $name];
    }

    private function _echo_editable_body($form) {
        global $Me;
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

        $this->edit_fields = [];
        $this->add_edit_field(0, [$this, "echo_editable_title"], "title");
        $this->add_edit_field(10000, [$this, "echo_editable_submission"], "submission");
        $this->add_edit_field(20000, [$this, "echo_editable_authors"], "authors");
        if ($this->prow)
            $this->add_edit_field(20200, [$this, "echo_editable_contact_author"], "contact_author");
        else if ($Me->privChair)
            $this->add_edit_field(20200, [$this, "echo_editable_new_contact_author"], "new_contact_author");
        if ($this->conf->submission_blindness() == Conf::BLIND_OPTIONAL
            && $this->editable !== "f")
            $this->add_edit_field(20100, [$this, "echo_editable_anonymity"], "anonymity");
        if (($x = $this->conf->opt("noAbstract")) !== 1 && $x !== true)
            $this->add_edit_field(30000, [$this, "echo_editable_abstract"], "abstract");
        $this->add_edit_field(40000, [$this, "echo_editable_topics"], "topics");
        if ($this->editable !== "f" || $this->admin) {
            $this->add_edit_field(60000, [$this, "echo_editable_pc_conflicts"], "pc_conflicts");
            $this->add_edit_field(61000, [$this, "echo_editable_collaborators"], "collaborators");
        }
        foreach ($this->canUploadFinal ? $this->conf->paper_opts->option_list() : $this->conf->paper_opts->nonfinal_option_list() as $opt)
            if (!$this->prow || $Me->can_view_paper_option($this->prow, $opt, true))
                $this->add_edit_field($opt->form_priority(), $this->make_echo_editable_option($opt), $opt);
        usort($this->edit_fields, function ($a, $b) {
            return $a[0] - $b[0] ? : $a[1] - $b[1];
        });
        for ($this->edit_fields_position = 0;
             $this->edit_fields_position < count($this->edit_fields);
             ++$this->edit_fields_position)
            call_user_func($this->edit_fields[$this->edit_fields_position][2]);

        // Submit button
        $this->echo_editable_complete();
        $this->echoActions(false);

        echo "</div></form>";
        Ht::stash_script("jQuery('textarea.papertext').autogrow()");
    }

    function paptabBegin() {
        global $Me;
        $prow = $this->prow;

        if ($prow)
            $this->_papstrip();
        if ($this->npapstrip)
            echo "</div></div></div></div>\n<div class=\"papcard\">";
        else
            echo '<div class="pedcard">';
        if ($this->editable)
            echo '<div class="pedcard_body">';
        else
            echo '<div class="papcard_body">';

        $form_js = array("id" => "paperform");
        if ($prow && $prow->paperStorageId > 1 && $prow->timeSubmitted > 0
            && !$this->conf->setting('sub_freeze'))
            $form_js["onsubmit"] = "return docheckpaperstillready()";
        if ($prow && $prow->timeSubmitted > 0)
            $form_js["data-submitted"] = $prow->timeSubmitted;
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
            $status_info = $Me->paper_status_info($this->prow);
            echo '<p class="xd"><span class="pstat ', $status_info[0], '">',
                htmlspecialchars($status_info[1]), "</span></p>";
            $this->paptabDownload();
            if ($this->paptabAbstract())
                echo '<div class="paptab">';
            else
                echo '<div class="paptab1">';
            $this->paptabAuthors(!$this->editable && $this->mode === "edit"
                                 && $prow->timeSubmitted > 0);
            $this->paptabTopicsOptions($Me->can_administer($prow));
            echo '</div>';
        }
        $this->echoDivExit();

        if (!$this->editable && $this->mode === "edit") {
            echo $form;
            if ($prow->timeSubmitted > 0)
                $this->echo_editable_contact_author(true);
            $this->echoActions(false);
            echo "</form>";
        } else if (!$this->editable && $Me->act_author_view($prow) && !$Me->contactId) {
            echo '<hr class="papcard_sep" />',
                "To edit this submission, <a href=\"", hoturl("index"), "\">sign in using your email and password</a>.";
        }

        Ht::stash_script("shortcut().add()");
        if ($this->editable)
            Ht::stash_script('hiliter_children("#paperform")');
    }

    private function _paptabSepContaining($t) {
        if ($t !== "")
            echo '<hr class="papcard_sep" />', $t;
    }

    function _paptabReviewLinks($rtable, $editrrow, $ifempty) {
        global $Me;
        require_once("reviewtable.php");

        $t = "";
        if ($rtable)
            $t .= reviewTable($this->prow, $this->all_rrows, $this->mycrows,
                              $editrrow, $this->mode);
        $t .= reviewLinks($this->prow, $this->all_rrows, $this->mycrows,
                          $editrrow, $this->mode, $this->allreviewslink);
        if (($empty = ($t === "")))
            $t = $ifempty;
        if ($t)
            echo '<hr class="papcard_sep" />';
        echo $t, "</div></div>\n";
        return $empty;
    }

    function _privilegeMessage() {
        $a = "<a href=\"" . selfHref(array("forceShow" => 0)) . "\">";
        return $a . Ht::img("override24.png", "[Override]", "dlimg")
            . "</a>&nbsp;You have used administrator privileges to view and edit reviews for this submission. (" . $a . "Unprivileged view</a>)";
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
        global $Me;
        return !$this->allreviewslink
            && (count($this->mycrows)
                || $Me->can_comment($this->prow, null)
                || $this->conf->time_author_respond());
    }

    function paptabEndWithReviewsAndComments() {
        global $Me;
        $prow = $this->prow;

        if ($Me->is_admin_force()
            && !$Me->can_view_review($prow, null, false))
            $this->_paptabSepContaining($this->_privilegeMessage());
        else if ($Me->contactId == $prow->managerContactId && !$Me->privChair
                 && $Me->contactId > 0)
            $this->_paptabSepContaining("You are this submission’s administrator.");

        $empty = $this->_paptabReviewLinks(true, null, "<div class='hint'>There are no reviews or comments for you to view.</div>");
        if ($empty)
            return;

        // text format link
        $viewable = array();
        foreach ($this->viewable_rrows as $rr)
            if ($rr->reviewModified > 1) {
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

        $rf = $this->conf->review_form();
        $rf->set_can_view_ratings($prow, $this->all_rrows, $Me);

        $rcjs = [];
        foreach ($this->viewable_rrows as $rr)
            if ($rr->reviewSubmitted || $rr->reviewModified > 1)
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
        global $Me;
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
                foreach ($this->conf->time_author_respond() as $i => $rname) {
                    if (!$this->has_response($i)) {
                        ++$ncmt;
                        $s .= "papercomment.add({is_new:true,editable:true,response:" . json_encode($rname) . "},true);\n";
                    }
                }
        }

        if ($ncmt)
            CommentInfo::echo_script($this->prow);
        echo Ht::unstash_script($s);
    }

    function paptabComments() {
        global $Me;
        if ($this->include_comments()) {
            $rcjs = [];
            foreach ($this->mycrows as $cr)
                $rcjs[] = $cr->unparse_json($Me, true);
            $this->render_rcjs($rcjs);
        }
    }

    function paptabEndWithReviewMessage() {
        global $Me;
        if ($this->editable) {
            echo "</div></div>\n";
            return;
        }

        $m = array();
        if ($this->all_rrows
            && ($whyNot = $Me->perm_view_review($this->prow, null, null)))
            $m[] = "You can’t see the reviews for this submission. " . whyNotText($whyNot, "review");
        if ($this->prow && $this->prow->reviewType && !$this->conf->time_review_open()) {
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
        global $Me;
        $prow = $this->prow;
        $act_pc = $Me->act_pc($prow);
        $actChair = $Me->can_administer($prow);

        // review messages
        $whyNot = $Me->perm_view_review($prow, null, false);
        $msgs = array();
        if (!$this->rrow && $this->prow->reviewType <= 0)
            $msgs[] = "You haven’t been assigned to review this submission, but you can review it anyway.";
        if ($whyNot && $Me->is_admin_force()) {
            $msgs[] = $this->_privilegeMessage();
        } else if ($whyNot && isset($whyNot["reviewNotComplete"])
                   && ($Me->isPC || $this->conf->setting("extrev_view"))) {
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
            && !$this->conf->time_review($this->editrrow, $act_pc, true)) {
            if ($actChair)
                $override = " As an administrator, you can override this deadline.";
            else {
                $override = "";
                if ($this->editrrow->reviewSubmitted)
                    $opt["edit"] = false;
            }
            if ($this->conf->time_review_open())
                $opt["editmessage"] = "The <a href='" . hoturl("deadlines") . "'>review deadline</a> has passed, so the review can no longer be changed.$override";
            else
                $opt["editmessage"] = "The site is not open for reviewing, so the review cannot be changed.$override";
        } else if (!$Me->can_review($prow, $this->editrrow))
            $opt["edit"] = false;

        // maybe clickthrough
        if ($opt["edit"] && !$Me->can_clickthrough("review"))
            self::echo_review_clickthrough();

        $rf = $this->conf->review_form();
        $rf->set_can_view_ratings($prow, $this->all_rrows, $Me);
        $rf->show($prow, $this->all_rrows, $this->editrrow, $opt);
    }


    // Functions for loading papers

    static private function _maybeSearchPaperId() {
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
                && $k !== "forceShow" && $k !== "go" && $k !== "actas" && $k !== "t"
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
            $result = $Conf->q_raw($q);
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
            // DISABLED: check if the paper is in the current list
            // if (($curpl = SessionList::requested())
            //     && isset($curpl->listno) && $curpl->listno
            //     && str_starts_with($curpl->listid, "p")
            //     && !preg_match(',\Ap/[^/]*//,', $curpl->listid)
            //     && array_search($pl->ids[0], $curpl->ids) !== false) {
            //    // preserve current list
            //    if (isset($pl->matchPreg) && $pl->matchPreg)
            //        $Conf->save_session("temp_matchPreg", $pl->matchPreg);
            //    $pl = $curpl;
            // }
            unset($_REQUEST["ls"], $_GET["ls"], $_POST["ls"]);
            $pl->set_cookie();
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
        if (!isset($_REQUEST["reviewId"])
            && preg_match(',\A/\d+[A-Z]+\z,i', Navigation::path()))
            $_REQUEST["reviewId"] = $_GET["reviewId"] = $_POST["reviewId"] = substr(Navigation::path(), 1);
        else if (!isset($_REQUEST["paperId"]) && ($pc = Navigation::path_component(0)))
            $_REQUEST["paperId"] = $_GET["paperId"] = $_POST["paperId"] = $pc;
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
        if (isset($sel["reviewId"])) {
            $sel["paperId"] = $prow->paperId;
            $rrow = $Conf->reviewRow($sel);
        }
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
        global $Me;

        $sel = array("paperId" => $this->prow->paperId, "array" => true);
        if ($this->conf->setting("rev_ratings") != REV_RATINGS_NONE) {
            $sel["ratings"] = true;
            $sel["myRating"] = $Me->contactId;
        }
        $this->all_rrows = $this->conf->reviewRow($sel, $whyNot);

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
        $rf = $this->conf->review_form();
        Ht::stash_script("review_form.set_form(" . json_encode($rf->unparse_json($round_mask, $min_view_score)) . ")");
        if ($Me->can_view_review_ratings())
            Ht::stash_script("review_form.set_ratings(" . json_encode($rf->unparse_ratings_json()) . ")");

        $rrid = strtoupper(defval($_REQUEST, "reviewId", ""));
        while ($rrid !== "" && $rrid[0] === "0")
            $rrid = substr($rrid, 1);

        $this->rrow = $myrrow = $approvable_rrow = null;
        foreach ($this->viewable_rrows as $rrow) {
            if ($rrid !== ""
                && (strcmp($rrow->reviewId, $rrid) == 0
                    || ($rrow->reviewOrdinal
                        && strcmp($rrow->paperId . unparseReviewOrdinal($rrow->reviewOrdinal), $rrid) == 0)))
                $this->rrow = $rrow;
            if ($rrow->contactId == $Me->contactId
                || (!$myrrow && $Me->is_my_review($rrow)))
                $myrrow = $rrow;
            if ($rrow->requestedBy == $Me->contactId
                && !$rrow->reviewSubmitted
                && $rrow->timeApprovalRequested)
                $approvable_rrow = $rrow;
        }

        if ($this->rrow)
            $this->editrrow = $this->rrow;
        else if (!$approvable_rrow || ($myrrow && $myrrow->reviewModified && !$this->prefer_approvable))
            $this->editrrow = $myrrow;
        else
            $this->editrrow = $approvable_rrow;

        if ($want_review && $Me->can_review($this->prow, $this->editrrow, false))
            $this->mode = "re";
    }

    function resolveComments() {
        global $Me;
        $this->crows = $this->mycrows = array();
        if ($this->prow) {
            $this->crows = $this->prow->all_comments();
            $this->mycrows = $this->prow->viewable_comments($Me, null);
        }
    }

    function viewable_comments() {
        return $this->mycrows;
    }

    function fixReviewMode() {
        global $Me;
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
        if ($this->mode === "p" && $prow && empty($this->viewable_rrows)
            && empty($this->mycrows)
            && $prow->has_author($Me)
            && !$Me->allow_administer($prow)
            && ($this->conf->timeFinalizePaper($prow) || $prow->timeSubmitted <= 0))
            $this->mode = "edit";
    }
}
