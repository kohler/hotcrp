<?php
// papertable.php -- HotCRP helper class for producing paper tables
// Copyright (c) 2006-2019 Eddie Kohler; see LICENSE.

class PaperTable {
    public $conf;
    public $prow;
    private $_prow;
    public $user;
    private $all_rrows;
    private $viewable_rrows;
    private $crows;
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
    private $review_values;
    private $npapstrip = 0;
    private $npapstrip_tag_entry;
    private $allFolded;
    private $matchPreg;
    private $entryMatches;
    private $canUploadFinal;
    private $foldmap;

    private $allow_admin;
    private $admin;
    private $view_authors = 0;
    private $view_options = [];

    private $cf = null;
    private $quit = false;

    static private $textAreaRows = array("title" => 1, "abstract" => 5, "authorInformation" => 5, "collaborators" => 5);

    function __construct($prow, $qreq, $mode = null) {
        global $Conf, $Me;

        $this->conf = $Conf;
        $this->prow = $prow;
        $this->_prow = $this->prow ? : new PaperInfo(null, null, $this->conf);
        $this->user = $user = $Me;
        $this->allow_admin = $user->allow_administer($prow);
        $this->admin = $user->can_administer($prow);
        $this->qreq = $qreq;

        $this->canUploadFinal = $this->prow
            && $this->user->allow_edit_final_paper($this->prow);

        if (!$this->prow) {
            $this->mode = "edit";
            return;
        }

        // enumerate allowed modes
        $ms = [];
        if ($user->can_view_review($prow, null)
            || $prow->review_submitted($user))
            $this->can_view_reviews = $ms["p"] = true;
        else if ($prow->timeWithdrawn > 0 && !$this->conf->timeUpdatePaper($prow))
            $ms["p"] = true;
        if ($user->can_review($prow, null))
            $ms["re"] = true;
        if ($user->can_view_paper($prow) && $this->allow_admin)
            $ms["p"] = true;
        if ($prow->has_author($user)
            && ($this->conf->timeFinalizePaper($prow) || $prow->timeSubmitted <= 0))
            $ms["edit"] = true;
        if ($user->can_view_paper($prow))
            $ms["p"] = true;
        if ($prow->has_author($user) || $this->allow_admin)
            $ms["edit"] = true;
        if ($prow->review_type($user) >= REVIEW_SECONDARY || $this->allow_admin)
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

        // calculate visibility of authors and options
        // 0: not visible; 1: fold (admin only); 2: visible
        $new_overrides = 0;
        if ($this->allow_admin)
            $new_overrides |= Contact::OVERRIDE_CONFLICT;
        if ($this->mode === "edit")
            $new_overrides |= Contact::OVERRIDE_EDIT_CONDITIONS;
        $overrides = $user->add_overrides($new_overrides);

        if ($user->can_view_authors($prow))
            $this->view_authors = 2;

        $olist = $this->conf->paper_opts->feature_list($prow);
        foreach ($olist as $o) {
            if ($o->id > 0 && $user->can_view_paper_option($prow, $o))
                $this->view_options[$o->id] = 2;
        }

        if ($this->allow_admin) {
            $user->remove_overrides(Contact::OVERRIDE_CONFLICT);
            if ($this->view_authors && !$user->can_view_authors($prow))
                $this->view_authors = 1;
            foreach ($olist as $o)
                if (isset($this->view_options[$o->id])
                    && !$user->can_view_paper_option($prow, $o))
                    $this->view_options[$o->id] = 1;
        }

        $user->set_overrides($overrides);

        // choose list
        if (!$this->conf->has_active_list())
            $this->conf->set_active_list($this->find_session_list($prow->paperId));
        else {
            $list = $this->conf->active_list();
            assert($list && ($list->set_current_id($prow->paperId) || $list->digest));
        }

        $this->matchPreg = [];
        if (($list = $this->conf->active_list()) && $list->highlight
            && preg_match('_\Ap/([^/]*)/([^/]*)(?:/|\z)_', $list->listid, $m)) {
            $hlquery = is_string($list->highlight) ? $list->highlight : urldecode($m[2]);
            $ps = new PaperSearch($user, ["t" => $m[1], "q" => $hlquery]);
            foreach ($ps->field_highlighters() as $k => $v)
                $this->matchPreg[$k] = $v;
        }
        if (empty($this->matchPreg))
            $this->matchPreg = null;
    }
    private function find_session_list($pid) {
        if (($list = SessionList::load_cookie("p"))
            && ($list->set_current_id($pid) || $list->digest))
            return $list;

        // look up list description
        $list = null;
        $listdesc = $this->qreq->ls;
        if ($listdesc) {
            if (($opt = PaperSearch::unparse_listid($listdesc)))
                $list = $this->try_list($opt, $pid);
            if (!$list && preg_match('{\A(all|s):(.*)\z}s', $listdesc, $m))
                $list = $this->try_list(["t" => $m[1], "q" => $m[2]], $pid);
            if (!$list && preg_match('{\A[a-z]+\z}', $listdesc))
                $list = $this->try_list(["t" => $listdesc], $pid);
            if (!$list)
                $list = $this->try_list(["q" => $listdesc], $pid);
        }

        // default lists
        if (!$list)
            $list = $this->try_list([], $pid);
        if (!$list && $this->user->privChair)
            $list = $this->try_list(["t" => "all"], $pid);

        return $list;
    }
    private function try_list($opt, $pid) {
        $srch = new PaperSearch($this->user, $opt);
        $list = $srch->session_list_object();
        return $list->set_current_id($pid);
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
        $this->editable = $editable;
        $this->useRequest = $useRequest;
        $this->allFolded = $this->mode === "re" || $this->mode === "assign"
            || ($this->mode !== "edit"
                && (!empty($this->all_rrows) || !empty($this->crows)));
    }

    function set_edit_status(PaperStatus $status) {
        $this->edit_status = $status;
    }

    function set_review_values(ReviewValues $rvalues = null) {
        $this->review_values = $rvalues;
    }

    function can_view_reviews() {
        return $this->can_view_reviews;
    }

    static function do_header($paperTable, $id, $action_mode, $qreq) {
        global $Conf, $Me;
        $prow = $paperTable ? $paperTable->prow : null;
        $format = 0;

        $t = '<div id="header-page" class="header-page-submission"><div id="header-page-submission-inner"><h1 class="paptitle';

        if (!$paperTable && !$prow) {
            if (($pid = $qreq->paperId) && ctype_digit($pid))
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
            else if ($prow->title === "")
                $t .= "[No title]";
            else
                $t .= htmlspecialchars($prow->title);

            $t .= '</span></span></a>';
            if ($viewable_tags && $Conf->tags()->has_decoration) {
                $tagger = new Tagger($Me);
                $t .= $tagger->unparse_decoration_html($viewable_tags);
            }
        }

        $t .= '</h1></div></div>';
        if ($paperTable && $prow)
            $t .= $paperTable->_paptabBeginKnown();

        $Conf->header($title, $id, [
            "action_bar" => actionBar($action_mode, $qreq),
            "title_div" => $t, "class" => "paper", "paperId" => $qreq->paperId
        ]);
        if ($format)
            echo Ht::unstash_script("render_text.on_page()");
    }

    private function abstract_foldable($abstract) {
        return strlen($abstract) > 190;
    }

    private function echoDivEnter() {
        // 5: topics, 6: abstract, 8: blind authors, 9: full authors
        $folds = [
            5 => $this->allFolded && $this->user->session("foldpapert", 1),
            6 => $this->allFolded && $this->user->session("foldpaperb", 1),
            8 => !!$this->user->session("foldpapera", 1),
            9 => $this->allFolded && $this->user->session("foldpaperp", 1)
        ];

        // if highlighting, automatically unfold abstract/authors
        if ($this->prow && $folds[6]) {
            $abstract = $this->entryData("abstract");
            if ($this->entryMatches || !$this->abstract_foldable($abstract))
                $folds[6] = false;
        }
        if ($this->matchPreg && $this->prow && $folds[8]) {
            $this->entryData("authorInformation");
            if ($this->entryMatches)
                $folds[8] = $folds[9] = false;
        }
        $this->foldmap = $folds;

        // collect folders
        $folders = array("clearfix");
        if ($this->prow) {
            if ($this->view_authors == 1)
                $folders[] = $folds[8] ? "fold8c" : "fold8o";
            if ($this->view_authors && $this->allFolded)
                $folders[] = $folds[9] ? "fold9c" : "fold9o";
        }
        $folders[] = $folds[5] ? "fold5c" : "fold5o";
        $folders[] = $folds[6] ? "fold6c" : "fold6o";

        // echo div
        echo '<div id="foldpaper" class="', join(" ", $folders), '" data-fold-session="'
            . htmlspecialchars(json_encode_browser([
                "5" => "foldpapert", "6" => "foldpaperb",
                "8" => "foldpapera", "9" => "foldpaperp"
            ])) . '">';
    }

    private function echoDivExit() {
        echo "</div>";
    }

    private function problem_status_at($f) {
        if ($this->edit_status) {
            if (str_starts_with($f, "au")) {
                if ($f === "authorInformation")
                    $f = "authors";
                else if (preg_match('/\A.*?(\d+)\z/', $f, $m)
                         && ($ps = $this->edit_status->problem_status_at("author$m[1]")))
                    return $ps;
            }
            return $this->edit_status->problem_status_at($f);
        } else
            return 0;
    }
    function has_problem_at($f) {
        return $this->problem_status_at($f) != 0;
    }
    function has_error_class($f) {
        return $this->has_problem_at($f) ? " has-error" : "";
    }
    function control_class($f, $rest = "", $prefix = "has-") {
        return MessageSet::status_class($this->problem_status_at($f), $rest, $prefix);
    }

    private function editable_papt($what, $heading, $extra = [], PaperOption $opt = null) {
        $for = get($extra, "for", false);
        $t = '<div class="papeg';
        if ($opt && $opt->edit_condition()) {
            $t .= ' has-edit-condition';
            if (!$opt->test_edit_condition($this->_prow))
                $t .= ' hidden';
            $t .= '" data-edit-condition="' . htmlspecialchars(json_encode($opt->compile_edit_condition($this->_prow)));
            Ht::stash_script('$(edit_paper_ui.edit_condition)', 'edit_condition');
        }
        $t .= '"><div class="' . $this->control_class($what, "papet");
        if ($for === "checkbox")
            $t .= " checki";
        if (($tclass = get($extra, "tclass")))
            $t .= " " . ltrim($tclass);
        if (($id = get($extra, "id")))
            $t .= '" id="' . $id;
        return $t . '">' . Ht::label($heading, $for === "checkbox" ? false : $for, ["class" => "papfn"]) . '</div>';
    }

    function messages_at($field) {
        $t = "";
        if ($this->edit_status)
            foreach ($this->edit_status->messages_at($field, true) as $mx)
                $t .= '<p class="' . MessageSet::status_class($mx[2], "settings-ap f-h", "is-")
                    . '">' . $mx[1] . '</p>';
        return $t;
    }

    private function papt($what, $name, $extra = array()) {
        $fold = defval($extra, "fold", false);
        $editfolder = defval($extra, "editfolder", false);
        if ($fold || $editfolder) {
            $foldnum = defval($extra, "foldnum", 0);
            $foldnumclass = $foldnum ? " data-fold-target=\"$foldnum\"" : "";
        }

        if (get($extra, "type") === "ps")
            list($divclass, $hdrclass) = array("pst", "psfn");
        else
            list($divclass, $hdrclass) = array("pavt", "pavfn");

        $c = "<div class=\"" . $this->control_class($what, $divclass);
        if (($fold || $editfolder) && !get($extra, "float"))
            $c .= " ui js-foldup\"" . $foldnumclass . ">";
        else
            $c .= "\">";
        $c .= "<span class=\"$hdrclass\">";
        if (!$fold) {
            $n = (is_array($name) ? $name[0] : $name);
            if ($editfolder)
                $c .= "<a class=\"q fn ui js-foldup\" "
                    . "href=\"" . $this->conf->selfurl($this->qreq, ["atab" => $what])
                    . "\"" . $foldnumclass . ">" . $n
                    . "</a><span class=\"fx\">" . $n . "</span>";
            else
                $c .= $n;
        } else {
            $c .= '<a class="q ui js-foldup" href=""' . $foldnumclass;
            if (($title = defval($extra, "foldtitle")))
                $c .= ' title="' . $title . '"';
            if (isset($this->foldmap[$foldnum]))
                $c .= ' role="button" aria-expanded="' . ($this->foldmap[$foldnum] ? "false" : "true") . '"';
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
                . "<a class=\"ui xx need-tooltip js-foldup\" href=\""
                . $this->conf->selfurl($this->qreq, ["atab" => $what])
                . "\"" . $foldnumclass . " data-tooltip=\"Edit\">"
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
        $js = ["id" => $fieldName,
               "class" => $this->control_class($fieldName, "papertext need-autogrow"),
               "rows" => self::$textAreaRows[$fieldName], "cols" => 60];
        if ($fieldName === "abstract")
            $js["spellcheck"] = true;
        $value = $pvalue = $this->prow ? $this->prow->$fieldName : "";
        if ($this->useRequest && isset($this->qreq[$fieldName])) {
            $value = cleannl($this->qreq[$fieldName]);
            if (self::$textAreaRows[$fieldName] === 1)
                $value = trim($value);
            if ($value !== $pvalue)
                $js["data-default-value"] = $pvalue;
        }
        return Ht::textarea($fieldName, $value, $js);
    }

    private function entryData($fieldName, $table_type = false) {
        $this->entryMatches = 0;
        $text = $this->prow ? $this->prow->$fieldName : "";
        if ($this->matchPreg
            && isset(self::$textAreaRows[$fieldName])
            && isset($this->matchPreg[$fieldName]))
            $text = Text::highlight($text, $this->matchPreg[$fieldName], $this->entryMatches);
        else
            $text = htmlspecialchars($text);
        return $table_type === "col" ? nl2br($text) : $text;
    }

    function field_name($name) {
        return $this->conf->_c("paper_field/edit", $name);
    }

    private function field_hint($name, $itext = "") {
        $args = array_merge(["paper_edit_description"], func_get_args());
        if (count($args) === 2)
            $args[] = "";
        $t = call_user_func_array([$this->conf->ims(), "xci"], $args);
        if ($t !== "")
            return '<div class="paphint">' . $t . '</div>';
        return "";
    }

    private function echo_editable_title() {
        echo $this->editable_papt("title", $this->field_name("Title"), ["for" => "title"]),
            $this->field_hint("Title"),
            '<div class="papev">', $this->editable_textarea("title"),
            $this->messages_at("title"),
            "</div></div>\n\n";
    }

    static function pdf_stamps_html($data, $options = null) {
        global $Conf;
        $tooltip = !$options || !get($options, "notooltip");
        $t = [];

        $tm = get($data, "timestamp", get($data, "timeSubmitted", 0));
        if ($tm > 0)
            $t[] = ($tooltip ? '<span class="nb need-tooltip" aria-label="Upload time">' : '<span class="nb">')
                . '<svg width="12" height="12" viewBox="0 0 96 96" class="licon"><path d="M48 6a42 42 0 1 1 0 84 42 42 0 1 1 0-84zm0 10a32 32 0 1 0 0 64 32 32 0 1 0 0-64zM48 19A5 5 0 0 0 43 24V46c0 2.352.37 4.44 1.464 5.536l12 12c4.714 4.908 12-2.36 7-7L53 46V24A5 5 0 0 0 43 24z"/></svg>'
                . " " . $Conf->unparse_time($tm) . "</span>";

        $ha = new HashAnalysis(get($data, "sha1"));
        if ($ha->ok()) {
            $x = '<span class="nb checksum';
            if ($tooltip) {
                $x .= ' need-tooltip" data-tooltip="';
                if ($ha->algorithm() === "sha256")
                    $x .= "SHA-256 checksum";
                else if ($ha->algorithm() === "sha1")
                    $x .= "SHA-1 checksum";
            }
            $h = $ha->text_data();
            $x .= '"><svg width="12" height="12" viewBox="0 0 48 48" class="licon"><path d="M19 32l-8-8-7 7 14 14 26-26-6-6-19 19zM15 3V10H8v5h7v7h5v-7H27V10h-7V3h-5z"/></svg> '
                . '<span class="checksum-overflow">' . $h . '</span>'
                . '<span class="checksum-abbreviation">' . substr($h, 0, 8) . '</span></span>';
            $t[] = $x;
        }

        if (!empty($t))
            return '<span class="hint">' . join(' <span class="barsep">·</span> ', $t) . "</span>";
        else
            return "";
    }

    private function paptabDownload() {
        assert(!$this->editable);
        $prow = $this->prow;
        $out = array();

        // download
        if ($this->user->can_view_pdf($prow)) {
            $dprefix = "";
            $dtype = $prow->finalPaperStorageId > 1 ? DTYPE_FINAL : DTYPE_SUBMISSION;
            if (($doc = $prow->document($dtype)) && $doc->paperStorageId > 1) {
                if (($stamps = self::pdf_stamps_html($doc)))
                    $stamps = '<span class="sep"></span>' . $stamps;
                if ($dtype == DTYPE_FINAL)
                    $dname = $this->conf->_c("paper_field", "Final version");
                else if ($prow->timeSubmitted != 0)
                    $dname = $this->conf->_c("paper_field", "Submission");
                else
                    $dname = $this->conf->_c("paper_field", "Draft submission");
                $out[] = '<p class="xd">' . $dprefix . $doc->link_html('<span class="pavfn">' . $dname . '</span>', DocumentInfo::L_REQUIREFORMAT) . $stamps . '</p>';
            }

            foreach ($prow ? $prow->options() : [] as $ov) {
                $o = $ov->option;
                if ($o->display() === PaperOption::DISP_SUBMISSION
                    && get($this->view_options, $o->id)
                    && ($oh = $this->unparse_option_html($ov))) {
                    $out = array_merge($out, $oh);
                }
            }

            if ($prow->finalPaperStorageId > 1 && $prow->paperStorageId > 1)
                $out[] = '<p class="xd"><small>' . $prow->document(DTYPE_SUBMISSION)->link_html("Submission version", DocumentInfo::L_SMALL | DocumentInfo::L_NOSIZE) . "</small></p>";
        }

        // conflicts
        if ($this->user->isPC && !$prow->has_conflict($this->user)
            && $this->conf->timeUpdatePaper($prow)
            && $this->mode !== "assign"
            && $this->mode !== "contact"
            && $prow->outcome >= 0)
            $out[] = Ht::msg('The authors still have <a href="' . hoturl("deadlines") . '">time</a> to make changes.', 1);

        echo join("", $out);
    }

    private function is_ready($checkbox) {
        if ($this->useRequest)
            return !!$this->qreq->submitpaper
                && ($checkbox
                    || $this->conf->opt("noPapers")
                    || ($this->prow && $this->prow->paperStorageId > 1));
        else if ($this->prow && $this->prow->timeSubmitted > 0)
            return true;
        else
            return $checkbox
                && !$this->conf->setting("sub_freeze")
                && (!$this->prow
                    || (!$this->conf->opt("noPapers") && $this->prow->paperStorageId <= 1));
    }

    private function echo_editable_complete() {
        if ($this->canUploadFinal) {
            echo Ht::hidden("submitpaper", 1);
            return;
        }

        $checked = $this->is_ready(true);
        echo '<div class="ready-container ',
            (($this->prow && $this->prow->paperStorageId > 1)
             || $this->conf->opt("noPapers") ? "foldo" : "foldc"),
            '"><div class="checki fx"><span class="checkc">',
            Ht::checkbox("submitpaper", 1, $checked, ["class" => "js-check-submittable"]),
            " </span>";
        if ($this->conf->setting("sub_freeze"))
            echo Ht::label("<strong>" . $this->conf->_("The submission is complete") . "</strong>"),
                '<p class="settings-ap hint">You must complete your submission before the deadline or it will not be reviewed. Completed submissions are frozen and cannot be changed further.</p>';
        else
            echo Ht::label("<strong>" . $this->conf->_("The submission is ready for review") . "</strong>");
        echo "</div></div>\n";
    }

    static function document_upload_input($inputid, $dtype, $accepts) {
        $t = '<input id="' . $inputid . '" type="file" name="' . $inputid . '"';
        if ($accepts !== null && count($accepts) == 1)
            $t .= ' accept="' . $accepts[0]->mimetype . '"';
        $t .= ' size="30" class="';
        $k = ["document-uploader"];
        if ($dtype == DTYPE_SUBMISSION || $dtype == DTYPE_FINAL)
            $k[] = "js-check-submittable";
        return $t . join(" ", $k) . '" />';
    }

    function echo_editable_document(PaperOption $docx, $storageId) {
        $dtype = $docx->id;
        if ($dtype == DTYPE_SUBMISSION || $dtype == DTYPE_FINAL) {
            $noPapers = $this->conf->opt("noPapers");
            if ($noPapers === 1 || $noPapers === true)
                return;
        }
        $inputid = $dtype > 0 ? "opt" . $dtype : "paperUpload";

        $accepts = $docx->mimetypes();
        $field = $docx->field_key();
        $doc = null;
        if ($this->prow && $this->user->can_view_pdf($this->prow) && $storageId > 1)
            $doc = $this->prow->document($dtype, $storageId, true);

        $msgs = [];
        if ($accepts)
            $msgs[] = htmlspecialchars(Mimetype::description($accepts));
        $msgs[] = "max " . ini_get("upload_max_filesize") . "B";
        $heading = $this->field_name(htmlspecialchars($docx->title)) . ' <span class="n">(' . join(", ", $msgs) . ")</span>";
        echo $this->editable_papt($field, $heading, ["for" => $doc ? false : $inputid, "id" => $docx->readable_formid], $docx),
            $this->field_hint(htmlspecialchars($docx->title), $docx->description);

        echo '<div class="papev has-document" data-dtype="', $dtype,
            '" data-document-name="', $docx->field_key(), '"';
        if ($doc)
            echo ' data-docid="', $doc->paperStorageId, '"';
        if ($accepts)
            echo ' data-document-accept="', htmlspecialchars(join(",", array_map(function ($m) { return $m->mimetype; }, $accepts))), '"';
        echo '>';
        if ($dtype > 0)
            echo Ht::hidden("has_opt" . $dtype, 1);

        // current version, if any
        $has_cf = false;
        if ($doc) {
            if ($doc->mimetype === "application/pdf") {
                if (!$this->cf)
                    $this->cf = new CheckFormat($this->conf, CheckFormat::RUN_NO);
                $spec = $this->conf->format_spec($dtype);
                $has_cf = $spec && !$spec->is_empty();
                if ($has_cf)
                    $this->cf->check_document($this->prow, $doc);
            }

            echo '<div class="document-file">',
                $doc->link_html(htmlspecialchars($doc->filename ? : "")),
                '</div><div class="document-stamps">';
            if (($stamps = self::pdf_stamps_html($doc)))
                echo $stamps;
            echo '</div><div class="document-actions">';
            if ($dtype > 0)
                echo '<a href="" class="ui js-remove-document document-action">Delete</a>';
            if ($has_cf
                && ($this->cf->failed || $this->cf->need_run || $this->cf->possible_run)) {
                echo '<a href="" class="ui js-check-format document-action">',
                    ($this->cf->failed || $this->cf->need_run ? "Check format" : "Recheck format"),
                    '</a>';
            } else if ($has_cf && !$this->cf->has_problem()) {
                echo '<span class="document-action dim">Format OK</span>';
            }
            echo '</div>';
            if ($has_cf) {
                echo '<div class="document-format">';
                if (!$this->cf->failed && $this->cf->has_problem())
                    echo $this->cf->document_report($this->prow, $doc);
                echo '</div>';
            }
        }

        echo '<div class="document-replacer">',
            Ht::button($doc ? "Replace" : "Upload", ["class" => "ui js-replace-document", "id" => $inputid]),
            '</div>',
            $this->messages_at($field), "</div></div>\n\n";
    }

    private function echo_editable_submission() {
        if (!$this->canUploadFinal) {
            $this->echo_editable_document($this->conf->paper_opts->get(DTYPE_SUBMISSION), $this->prow ? $this->prow->paperStorageId : 0);
        }
    }

    private function echo_editable_final_version() {
        if ($this->canUploadFinal) {
            $this->echo_editable_document($this->conf->paper_opts->get(DTYPE_FINAL), $this->prow ? $this->prow->finalPaperStorageId : 0);
        }
    }

    private function echo_editable_abstract() {
        $noAbstract = $this->conf->opt("noAbstract");
        if ($noAbstract !== 1 && $noAbstract !== true) {
            $title = $this->field_name("Abstract");
            if ($noAbstract === 2)
                $title .= ' <span class="n">(optional)</span>';
            echo $this->editable_papt("abstract", $title, ["for" => "abstract"]),
                $this->field_hint("Abstract"),
                '<div class="papev abstract">';
            if (($fi = $this->conf->format_info($this->prow ? $this->prow->paperFormat : null)))
                echo $fi->description_preview_html();
            echo $this->editable_textarea("abstract"),
                $this->messages_at("abstract"),
                "</div></div>\n\n";
        }
    }

    private function paptabAbstract() {
        $text = $this->entryData("abstract");
        if (trim($text) === "") {
            if ($this->conf->opt("noAbstract"))
                return false;
            else
                $text = "[No abstract]";
        }
        $extra = [];
        if ($this->allFolded && $this->abstract_foldable($text))
            $extra = ["fold" => "paper", "foldnum" => 6,
                      "foldtitle" => "Toggle full abstract"];
        echo '<div class="paperinfo-cl"><div class="paperinfo-abstract"><div class="pg">',
            $this->papt("abstract", $this->conf->_c("paper_field", "Abstract"), $extra),
            '<div class="pavb abstract';
        if ($this->prow
            && !$this->entryMatches
            && ($format = $this->prow->format_of($text))) {
            echo ' need-format" data-format="', $format, '.abs">', $text;
            Ht::stash_script('$(render_text.on_page)', 'render_on_page');
        } else
            echo ' format0">', Ht::format0($text);
        echo "</div></div></div>";
        if ($extra)
            echo '<div class="fn6 fx7 longtext-fader"></div>',
                '<div class="fn6 fx7 longtext-expander"><a class="ui x js-foldup" href="" data-fold-target="6">[more]</a></div>';
        echo "</div>\n";
        if ($extra)
            echo Ht::unstash_script("render_text.on_page()");
        return true;
    }

    private function editable_author_component_entry($n, $pfx, $au) {
        $auval = "";
        if ($pfx === "auname") {
            $js = ["size" => "35", "placeholder" => "Name", "autocomplete" => "off", "aria-label" => "Author name"];
            if ($au && $au->firstName && $au->lastName && !preg_match('@^\s*(v[oa]n\s+|d[eu]\s+)?\S+(\s+jr.?|\s+sr.?|\s+i+)?\s*$@i', $au->lastName))
                $auval = $au->lastName . ", " . $au->firstName;
            else if ($au)
                $auval = $au->name();
        } else if ($pfx === "auemail") {
            $js = ["size" => "30", "placeholder" => "Email", "autocomplete" => "off", "aria-label" => "Author email"];
            $auval = $au ? $au->email : "";
        } else {
            $js = ["size" => "32", "placeholder" => "Affiliation", "autocomplete" => "off", "aria-label" => "Author affiliation"];
            $auval = $au ? $au->affiliation : "";
        }

        $val = $auval;
        if ($this->useRequest) {
            $val = ($pfx === '$' ? "" : (string) $this->qreq["$pfx$n"]);
        }

        $js["class"] = $this->control_class("$pfx$n", "need-autogrow js-autosubmit e$pfx");
        if ($au && !$this->prow && !$this->useRequest)
            $js["class"] .= " ignore-diff";
        if ($pfx === "auemail" && $this->user->can_lookup_user())
            $js["class"] .= " uii js-email-populate";
        if ($val !== $auval)
            $js["data-default-value"] = $auval;
        return Ht::entry("$pfx$n", $val, $js);
    }
    private function editable_authors_tr($n, $au, $max_authors) {
        $t = '<tr>';
        if ($max_authors !== 1)
            $t .= '<td class="rxcaption">' . $n . '.</td>';
        return $t . '<td class="lentry">'
            . $this->editable_author_component_entry($n, "auemail", $au) . ' '
            . $this->editable_author_component_entry($n, "auname", $au) . ' '
            . $this->editable_author_component_entry($n, "auaff", $au)
            . '<span class="nb btnbox aumovebox"><button type="button" class="ui qx need-tooltip row-order-ui moveup" aria-label="Move up" tabindex="-1">'
            . Icons::ui_triangle(0)
            . '</button><button type="button" class="ui qx need-tooltip row-order-ui movedown" aria-label="Move down" tabindex="-1">'
            . Icons::ui_triangle(2)
            . '</button><button type="button" class="ui qx need-tooltip row-order-ui delete" aria-label="Delete" tabindex="-1">✖</button></span>'
            . $this->messages_at("author$n")
            . $this->messages_at("auemail$n")
            . $this->messages_at("auname$n")
            . $this->messages_at("auaff$n")
            . '</td></tr>';
    }

    private function echo_editable_authors() {
        $max_authors = (int) $this->conf->opt("maxAuthors");
        $min_authors = $max_authors > 0 ? min(5, $max_authors) : 5;

        $sb = $this->conf->submission_blindness();
        $title = $this->field_name("Authors");
        if ($sb === Conf::BLIND_ALWAYS)
            $title .= " (blind)";
        else if ($sb === Conf::BLIND_UNTILREVIEW)
            $title .= " (blind until review)";
        echo $this->editable_papt("authors", $title, ["id" => "authors"]),
            $this->field_hint("Authors", "List the authors, including email addresses and affiliations.", $sb),
            '<div class="papev"><table class="js-row-order">',
            '<tbody class="need-row-order-autogrow" data-min-rows="', $min_authors, '" ',
            ($max_authors > 0 ? 'data-max-rows="' . $max_authors . '" ' : ''),
            'data-row-template="', htmlspecialchars($this->editable_authors_tr('$', null, $max_authors)), '">';

        $aulist = $this->prow ? $this->prow->author_list() : [];
        if ($this->useRequest) {
            $n = $nonempty_n = 0;
            while (1) {
                $auname = $this->qreq["auname" . ($n + 1)];
                $auemail = $this->qreq["auemail" . ($n + 1)];
                $auaff = $this->qreq["auaff" . ($n + 1)];
                if ($auname === null && $auemail === null && $auaff === null) {
                    break;
                }
                ++$n;
                if ((string) $auname !== "" || (string) $auemail !== "" || (string) $auaff !== "") {
                    $nonempty_n = $n;
                }
            }
            while (count($aulist) < $nonempty_n) {
                $aulist[] = null;
            }
        } else if (!$this->prow && !$this->user->privChair) {
            $aulist[] = $this->user;
        }

        $tr_maxau = $max_authors <= 0 ? 0 : max(count($aulist), $max_authors);
        for ($n = 1; $n <= count($aulist); ++$n) {
            echo $this->editable_authors_tr($n, get($aulist, $n - 1), $tr_maxau);
        }
        if ($max_authors <= 0 || $n <= $max_authors) {
            do {
                echo $this->editable_authors_tr($n, null, $tr_maxau);
                ++$n;
            } while ($n <= $min_authors);
        }
        echo "</tbody></table>",
            $this->messages_at("authors"),
            "</div></div>\n\n";
    }

    private function authorData($table, $type, $viewAs = null) {
        if ($this->matchPreg && isset($this->matchPreg["authorInformation"]))
            $highpreg = $this->matchPreg["authorInformation"];
        else
            $highpreg = false;
        $this->entryMatches = 0;
        $names = [];

        if (empty($table)) {
            return "[No authors]";
        } else if ($type === "last") {
            foreach ($table as $au) {
                $n = Text::abbrevname_text($au);
                $names[] = Text::highlight($n, $highpreg, $nm);
                $this->entryMatches += $nm;
            }
            return join(", ", $names);
        } else {
            foreach ($table as $au) {
                $nm1 = $nm2 = $nm3 = 0;
                $n = $e = $t = "";
                $n = trim(Text::highlight("$au->firstName $au->lastName", $highpreg, $nm1));
                if ($au->email !== "") {
                    $e = Text::highlight($au->email, $highpreg, $nm2);
                    $e = '&lt;<a href="mailto:' . htmlspecialchars($au->email)
                        . '" class="mailto">' . $e . '</a>&gt;';
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
                    $t .= " <a href=\""
                        . $this->conf->selfurl($this->qreq, ["actas" => $au->email])
                        . "\">" . Ht::img("viewas.png", "[Act as]", array("title" => "Act as " . Text::name_text($au))) . "</a>";
                $names[] = '<p class="odname">' . $t . '</p>';
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
        while ($result && ($row = $result->fetch_object("Author"))) {
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
                $au = $aulist[$match];
                if ($au->email === "")
                    $au->email = $row->email;
            } else {
                $contacts[] = $au = $row;
                $au->nonauthor = true;
            }
            $au->contactId = (int) $row->contactId;
            Contact::set_sorter($au, $this->conf);
        }
        Dbl::free($result);

        uasort($contacts, "Contact::compare");
        return array($aulist, $contacts);
    }

    private function paptabAuthors($skip_contacts) {
        if ($this->view_authors == 0) {
            echo '<div class="pg">',
                $this->papt("authorInformation", $this->conf->_c("paper_field", "Authors", 0)),
                '<div class="pavb"><i>Hidden for blind review</i></div>',
                "</div>\n\n";
            return;
        }

        // clean author information
        list($aulist, $contacts) = $this->_analyze_authors();

        // "author" or "authors"?
        $auname = $this->conf->_c("paper_field", "Authors", count($aulist));
        if ($this->view_authors == 1)
            $auname .= " (deblinded)";
        else if ($this->user->act_author_view($this->prow)) {
            $sb = $this->conf->submission_blindness();
            if ($sb === Conf::BLIND_ALWAYS
                || ($sb === Conf::BLIND_OPTIONAL && $this->prow->blind))
                $auname .= " (blind)";
            else if ($sb === Conf::BLIND_UNTILREVIEW)
                $auname .= " (blind until review)";
        }

        // header with folding
        echo '<div class="pg">',
            '<div class="', $this->control_class("authors", "pavt ui js-aufoldup"),
            '"><span class="pavfn">';
        if ($this->view_authors == 1 || $this->allFolded)
            echo '<a class="q ui js-aufoldup" href="" title="Toggle author display" role="button" aria-expanded="', $this->foldmap[8] ? "false" : "true", '">';
        if ($this->view_authors == 1)
            echo '<span class="fn8">', $this->conf->_c("paper_field", "Authors", 0), '</span><span class="fx8">';
        if ($this->allFolded)
            echo expander(null, 9);
        else if ($this->view_authors == 1)
            echo expander(false);
        echo $auname;
        if ($this->view_authors == 1)
            echo '</span>';
        if ($this->view_authors == 1 || $this->allFolded)
            echo '</a>';
        echo '</span></div>';

        // contents
        echo '<div class="pavb">';
        if ($this->view_authors == 1)
            echo '<a class="q fn8 ui js-aufoldup" href="" title="Toggle author display">',
                '+&nbsp;<i>Hidden for blind review</i>',
                '</a><div class="fx8">';
        if ($this->allFolded)
            echo '<div class="fn9">',
                $this->authorData($aulist, "last", null),
                ' <a class="ui js-aufoldup" href="">[details]</a>',
                '</div><div class="fx9">';
        echo $this->authorData($aulist, "col", $this->user);
        if ($this->allFolded)
            echo '</div>';
        if ($this->view_authors == 1)
            echo '</div>';
        echo "</div></div>\n\n";

        // contacts
        if (count($contacts) > 0 && !$skip_contacts) {
            echo '<div class="pg fx9', ($this->view_authors > 1 ? "" : " fx8"), '">',
                $this->papt("authorInformation",
                            $this->conf->_c("paper_field", "Contacts", count($contacts))),
                '<div class="pavb">',
                $this->authorData($contacts, "col", $this->user),
                "</div></div>\n\n";
        }
    }

    private function unparse_option_html(PaperOptionValue $ov) {
        $o = $ov->option;
        $phtml = $o->unparse_page_html($this->prow, $ov);
        if (!$phtml || count($phtml) <= 1)
            return [];
        $phtype = array_shift($phtml);
        $aufold = $this->view_options[$o->id] == 1;

        $ts = [];
        if ($o->display() === PaperOption::DISP_SUBMISSION) {
            $div = $aufold ? '<div class="xd fx8">' : '<div class="xd">';
            if ($phtype === PaperOption::PAGE_HTML_NAME) {
                foreach ($phtml as $p)
                    $ts[] = $div . '<span class="pavfn">' . $p . "</span></div>\n";
            } else if ($phtype === PaperOption::PAGE_HTML_FULL) {
                foreach ($phtml as $p)
                    $ts[] = $div . $p . "</div>\n";
            } else {
                $x = $div . '<span class="pavfn">' . htmlspecialchars($o->title) . '</span>';
                foreach ($phtml as $p)
                    $x .= '<div class="pavb">' . $p . '</div>';
                $ts[] = $x . "</div>\n";
            }
        } else if ($o->display() !== PaperOption::DISP_TOPICS) {
            $div = $aufold ? '<div class="pgsm fx8">' : '<div class="pgsm">';
            if ($phtype === PaperOption::PAGE_HTML_NAME) {
                foreach ($phtml as $p)
                    $ts[] = $div . '<div class="pavt"><span class="pavfn">' . $p . "</span></div></div>\n";
            } else if ($phtype === PaperOption::PAGE_HTML_FULL) {
                foreach ($phtml as $p)
                    $ts[] = $div . $p . "</div>\n";
            } else {
                $x = $div . '<div class="pavt"><span class="pavfn">' . htmlspecialchars($o->title) . '</span></div>';
                foreach ($phtml as $p)
                    $x .= '<div class="pavb">' . $p . '</div>';
                $ts[] = $x . "</div>\n";
            }
        } else {
            $div = $aufold ? '<div class="fx8">' : '<div>';
            if ($phtype === PaperOption::PAGE_HTML_NAME) {
                foreach ($phtml as $p)
                    $ts[] = $div . '<span class="papon">' . $p . "</span></div>\n";
            } else if ($phtype === PaperOption::PAGE_HTML_FULL) {
                foreach ($phtml as $p)
                    $ts[] = $div . $p . "</div>\n";
            } else {
                foreach ($phtml as $p) {
                    if (!empty($ts)
                        || $p === ""
                        || $p[0] !== "<"
                        || !preg_match('/\A((?:<(?:div|p).*?>)*)([\s\S]*)\z/', $p, $cm))
                        $cm = [null, "", $p];
                    $ts[] = $div . $cm[1] . '<span class="papon">' . htmlspecialchars($o->title) . ':</span> ' . $cm[2] . "</div>\n";
                }
            }
        }
        return $ts;
    }

    private function paptab_topics() {
        if (!($tmap = $this->prow->topic_map()))
            return "";
        $interests = $this->user->topic_interest_map();
        $lenclass = count($tmap) < 4 ? "long" : "short";
        $topics = $this->conf->topic_set();
        $ts = [];
        foreach ($tmap as $tid => $tname) {
            $t = '<li class="topicti';
            if (($i = get($interests, $tid)))
                $t .= ' topic' . $i;
            $x = $topics->unparse_name_html($tid);
            if ($this->user->isPC)
                $x = Ht::link($x, hoturl("search", ["q" => "topic:" . SearchWord::quote($tname)]), ["class" => "qq"]);
            $ts[] = $t . '">' . $x . '</li>';
            $lenclass = TopicSet::max_topici_lenclass($lenclass, $tname);
        }
        return '<ul class="topict topict-' . $lenclass . '">' . join("", $ts) . '</ul>';
    }

    private function paptabTopicsOptions() {
        $topicdata = $this->paptab_topics();
        $optt = $optp = [];
        $optp_nfold = $optt_ndoc = $optt_nfold = 0;

        foreach ($this->prow->options() as $ov) {
            $o = $ov->option;
            if ($o->display() !== PaperOption::DISP_SUBMISSION
                && $o->display() >= 0
                && get($this->view_options, $o->id)
                && ($oh = $this->unparse_option_html($ov))) {
                $aufold = $this->view_options[$o->id] == 1;
                if ($o->display() === PaperOption::DISP_TOPICS) {
                    $optt = array_merge($optt, $oh);
                    if ($aufold)
                        $optt_nfold += count($oh);
                    if ($o->has_document())
                        $optt_ndoc += count($oh);
                } else {
                    $optp = array_merge($optp, $oh);
                    if ($aufold)
                        $optp_nfold += count($oh);
                }
            }
        }

        if (!empty($optp)) {
            $div = count($optp) === $optp_nfold ? '<div class="pg fx8">' : '<div class="pg">';
            echo $div, join("", $optp), "</div>\n";
        }

        if ($topicdata !== "" || !empty($optt)) {
            $infotypes = array();
            if ($optt_ndoc > 0)
                $infotypes[] = "Attachments";
            if (count($optt) !== $optt_ndoc)
                $infotypes[] = "Options";
            $options_name = commajoin($infotypes);
            if ($topicdata !== "")
                array_unshift($infotypes, "Topics");
            $tanda = commajoin($infotypes);

            if ($this->allFolded) {
                $extra = array("fold" => "paper", "foldnum" => 5,
                               "foldtitle" => "Toggle " . strtolower($tanda));
                $eclass = " fx5";
            } else {
                $extra = null;
                $eclass = "";
            }

            if ($topicdata !== "") {
                echo '<div class="pg">',
                    $this->papt("topics", array("Topics", $tanda), $extra),
                    '<div class="pavb', $eclass, '">', $topicdata, "</div></div>\n\n";
                $extra = null;
                $tanda = $options_name;
            }

            if (!empty($optt)) {
                echo '<div class="pg', ($extra ? "" : $eclass),
                    (count($optt) === $optt_nfold ? " fx8" : ""), '">',
                    $this->papt("options", array($options_name, $tanda), $extra),
                    "<div class=\"pavb$eclass\">", join("", $optt), "</div></div>\n\n";
            }
        }
    }

    private function editable_newcontact_row($num) {
        if ($num === '$') {
            $checked = true;
            $name = $email = "";
        } else {
            $checked = !$this->useRequest || $this->qreq["newcontact_active_{$num}"];
            $email = (string) ($this->useRequest ? $this->qreq["newcontact_email_{$num}"] : "");
            $name = (string) ($this->useRequest ? $this->qreq["newcontact_name_{$num}"] : "");
        }
        $email = $email === "Email" ? "" : $email;
        $name = $name === "Name" ? "" : $name;

        return '<div class="' . $this->control_class("newcontact_{$num}", "checki")
            . '"><span class="checkc">'
            . Ht::checkbox("newcontact_active_{$num}", 1, $checked, ["data-default-checked" => 1, "id" => false])
            . ' </span>'
            . Ht::entry("newcontact_name_{$num}", $name, ["size" => 30, "placeholder" => "Name", "class" => "want-focus js-autosubmit", "autocomplete" => "off"])
            . '  '
            . Ht::entry("newcontact_email_{$num}", $email, ["size" => 20, "placeholder" => "Email", "class" => $this->control_class("newcontact_email_{$num}", "js-autosubmit"), "autocomplete" => "off"])
            . $this->messages_at("newcontact_{$num}")
            . $this->messages_at("newcontact_name_{$num}")
            . $this->messages_at("newcontact_email_{$num}")
            . '</div>';
    }

    private function echo_editable_contact_author() {
        if ($this->prow) {
            list($aulist, $contacts) = $this->_analyze_authors();
            $contacts = array_merge($aulist, $contacts);
        } else if (!$this->admin) {
            $contacts = [new Author($this->user)];
            $contacts[0]->contactId = $this->user->contactId;
            Contact::set_sorter($contacts[0], $this->conf);
        } else
            $contacts = [];
        usort($contacts, "Contact::compare");

        echo '<div class="papeg">',
            '<div class="', $this->control_class("contacts", "papet"),
            '" id="contacts"><span class="', $this->control_class("contacts", "papfn", "is-"), '">',
            $this->field_name("Contacts"),
            '</span></div>';

        // Editable version
        echo $this->field_hint("Contacts", "These users can edit the submission and view reviews. All listed authors with site accounts are contacts. You can add contacts who aren’t in the author list or create accounts for authors who haven’t yet logged in.", !!$this->prow),
            Ht::hidden("has_contacts", 1),
            '<div class="papev js-row-order"><div>';

        $req_cemail = [];
        if ($this->useRequest) {
            for ($cidx = 1; isset($this->qreq["contact_email_{$cidx}"]); ++$cidx)
                if ($this->qreq["contact_active_{$cidx}"])
                    $req_cemail[strtolower($this->qreq["contact_email_{$cidx}"])] = $cidx;
        }

        $cidx = 1;
        foreach ($contacts as $au) {
            $reqidx = get($req_cemail, strtolower($au->email));
            if ($au->nonauthor
                && (strcasecmp($this->user->email, $au->email) != 0 || $this->allow_admin)) {
                $ctl = Ht::hidden("contact_email_{$cidx}", $au->email)
                    . Ht::checkbox("contact_active_{$cidx}", 1, !$this->useRequest || $reqidx, ["data-default-checked" => true, "id" => false]);
            } else if ($au->contactId) {
                $ctl = Ht::hidden("contact_email_{$cidx}", $au->email)
                    . Ht::hidden("contact_active_{$cidx}", 1)
                    . Ht::checkbox(null, null, true, ["disabled" => true, "id" => false]);
            } else if ($au->email && validate_email($au->email)) {
                $ctl = Ht::hidden("contact_email_{$cidx}", $au->email)
                    . Ht::checkbox("contact_active_{$cidx}", 1, $this->useRequest && $reqidx, ["data-default-checked" => "", "id" => false]);
            } else
                continue;
            echo '<div class="',
                $reqidx ? $this->control_class("contact_{$reqidx}", "checki") : "checki",
                '"><label><span class="checkc">', $ctl, ' </span>',
                Text::user_html_nolink($au);
            if ($au->nonauthor)
                echo ' (<em>non-author</em>)';
            if ($this->user->privChair
                && $au->contactId
                && $au->contactId != $this->user->contactId)
                echo '&nbsp;', actas_link($au);
            echo '</label>', $this->messages_at("contact_{$cidx}"), '</div>';
            ++$cidx;
        }
        echo '</div><div data-row-template="',
            htmlspecialchars($this->editable_newcontact_row('$')),
            '">';
        if ($this->useRequest) {
            for ($i = 1; isset($this->qreq["newcontact_email_{$i}"]); ++$i)
                echo $this->editable_newcontact_row($i);
        }
        echo '</div><div class="ug">',
            Ht::button("Add contact", ["class" => "ui row-order-ui addrow"]),
            "</div>", $this->messages_at("contacts"), "</div></div>\n\n";
    }

    private function echo_editable_anonymity() {
        if ($this->conf->submission_blindness() != Conf::BLIND_OPTIONAL
            || $this->editable !== "f")
            return;
        $pblind = !$this->prow || $this->prow->blind;
        $blind = $this->useRequest ? !!$this->qreq->blind : $pblind;
        $heading = '<span class="checkc">' . Ht::checkbox("blind", 1, $blind, ["data-default-checked" => $pblind]) . " </span>" . $this->field_name("Anonymous submission");
        echo $this->editable_papt("blind", $heading, ["for" => "checkbox"]),
            $this->field_hint("Anonymous submission", "Check this box to submit anonymously (reviewers won’t be shown the author list). Make sure you also remove your name from the submission itself!"),
            $this->messages_at("blind"),
            "</div>\n\n";
    }

    private function echo_editable_collaborators() {
        if (!$this->conf->setting("sub_collab")
            || ($this->editable === "f" && !$this->admin))
            return;
        $sub_pcconf = $this->conf->setting("sub_pcconf");

        echo $this->editable_papt("collaborators", $this->field_name("Collaborators"), ["for" => "collaborators"]),
            '<div class="paphint"><div class="mmm">';
        if ($this->conf->setting("sub_pcconf"))
            echo "List <em>other</em> people and institutions with which
        the authors have conflicts of interest.  This will help us avoid
        conflicts when assigning external reviews.  No need to list people
        at the authors’ own institutions.";
        else
            echo "List people and institutions with which the authors have
        conflicts of interest. ", $this->conf->_i("conflictdef", false), '
        Be sure to include conflicted <a href="', hoturl("users", "t=pc"), '">PC members</a>.
        We use this information when assigning PC and external reviews.';
        echo "</div><div class=\"mmm\"><strong>List one conflict per line</strong>, using parentheses for affiliations and institutions. Examples: “Jelena Markovic (EPFL)”, “All (University of Southern California)”.</div></div>",
            '<div class="papev">',
            $this->editable_textarea("collaborators"),
            $this->messages_at("collaborators"),
            "</div></div>\n\n";
    }

    private function _papstripBegin($foldid = null, $folded = null, $extra = null) {
        if (!$this->npapstrip) {
            echo '<div class="pspcard">',
                '<div class="pspcard_body"><div class="pspcard_fold">',
                '<div style="float:right;margin-left:1em"><span class="psfn">More ', expander(true), '</span></div>';

            if ($this->prow && ($viewable = $this->prow->viewable_tags($this->user))) {
                $tagger = new Tagger($this->user);
                echo '<div class="pscopen">',
                    '<span class="psfn">Tags:</span> ',
                    $tagger->unparse_link($viewable),
                    '</div>';
            } else
                echo '<hr class="c" />';

            echo '</div><div class="pspcard_open">';
            Ht::stash_script('$(".pspcard_fold").click(function(evt){$(".pspcard_fold").hide();$(".pspcard_open").show();evt.preventDefault()})');
        }
        echo '<div';
        if ($foldid)
            echo " id=\"fold$foldid\"";
        echo ' class="psc';
        if (!$this->npapstrip)
            echo " psc1";
        if ($foldid)
            echo " fold", ($folded ? "c" : "o");
        if ($extra) {
            if (isset($extra["class"]))
                echo " ", $extra["class"];
            foreach ($extra as $k => $v)
                if ($k !== "class")
                    echo "\" $k=\"", str_replace("\"", "&quot;", $v);
        }
        echo '">';
        ++$this->npapstrip;
    }

    private function papstripCollaborators() {
        if (!$this->conf->setting("sub_collab")
            || !$this->prow->collaborators
            || strcasecmp(trim($this->prow->collaborators), "None") == 0)
            return;
        $fold = $this->user->session("foldpscollab", 1) ? 1 : 0;

        $data = $this->entryData("collaborators", "col");
        if ($this->entryMatches || !$this->allFolded)
            $fold = 0;

        $this->_papstripBegin("pscollab", $fold, ["data-fold-session" => "foldpscollab"]);
        echo $this->papt("collaborators", $this->conf->_c("paper_field", "Collaborators", $this->conf->setting("sub_pcconf")),
                         ["type" => "ps", "fold" => "pscollab", "folded" => $fold]),
            '<div class="psv"><div class="fx">', $data,
            "</div></div></div>\n\n";
    }

    private function echo_editable_topics() {
        if (!$this->conf->has_topics())
            return;
        echo $this->editable_papt("topics", $this->field_name("Topics"), ["id" => "topics"]),
            $this->field_hint("Topics", "Select any topics that apply to your submission."),
            '<div class="papev">',
            Ht::hidden("has_topics", 1),
            '<div class="ctable">';
        $ptopics = $this->prow ? $this->prow->topic_map() : [];
        $topics = $this->conf->topic_set();
        foreach ($topics->group_list() as $tg) {
            $arg = ["class" => "uix js-range-click topic-entry", "id" => false,
                    "data-range-type" => "topic"];
            $isgroup = count($tg) >= 4;
            if ($isgroup && strcasecmp($tg[0], $topics[$tg[1]]) === 0) {
                $tid = $tg[1];
                $arg["data-default-checked"] = $pchecked = isset($ptopics[$tid]);
                $checked = $this->useRequest ? isset($this->qreq["top$tid"]) : $pchecked;
                echo '<div class="ctelt cteltg"><div class="ctelti">',
                    '<label class="checki cteltx"><span class="checkc">',
                    Ht::checkbox("top$tid", 1, $checked, $arg),
                    ' </span>', htmlspecialchars($tg[0]), '</label>',
                    '<div class="checki">';
            } else if ($isgroup) {
                echo '<div class="ctelt cteltg"><div class="ctelti">',
                    '<div class="cteltx"><span class="topicg">',
                    htmlspecialchars($tg[0]), '</span></div>',
                    '<div class="checki">';
            }
            for ($i = 1; $i !== count($tg); ++$i) {
                $tid = $tg[$i];
                if ($isgroup) {
                    $tname = htmlspecialchars($topics->subtopic_name($tid));
                    if ($tname === "")
                        continue;
                } else {
                    $tname = $topics->unparse_name_html($tid);
                }
                $arg["data-default-checked"] = $pchecked = isset($ptopics[$tid]);
                $checked = $this->useRequest ? isset($this->qreq["top$tid"]) : $pchecked;
                echo ($isgroup ? '<label class="checki cteltx">' : '<div class="ctelt"><label class="checki ctelti">'),
                    '<span class="checkc">',
                    Ht::checkbox("top$tid", 1, $checked, $arg),
                    ' </span>', htmlspecialchars($tname), '</label>',
                    ($isgroup ? '' : '</div>');
            }
            if ($isgroup)
                echo '</div></div></div>';
        }
        echo "</div>", $this->messages_at("topics"), "</div></div>\n\n";
    }

    function echo_editable_option_papt(PaperOption $o, $heading = null, $rest = []) {
        if (!$heading)
            $heading = $this->field_name(htmlspecialchars($o->title));
        echo $this->editable_papt($o->formid, $heading, $rest, $o),
            $this->field_hint(htmlspecialchars($o->title), $o->description),
            Ht::hidden("has_{$o->formid}", 1);
    }

    private function echo_editable_pc_conflicts() {
        if (!$this->conf->setting("sub_pcconf"))
            return;
        if ($this->editable === "f" && !$this->admin) {
            foreach ($this->prow->pc_conflicts() as $cflt)
                echo Ht::hidden("pcc" . $cflt->contactId, $cflt->conflictType);
            return;
        }
        $pcm = $this->conf->full_pc_members();
        if (empty($pcm))
            return;

        $selectors = $this->conf->setting("sub_pcconfsel");
        $show_colors = $this->user->can_view_reviewer_tags($this->prow);

        if ($selectors) {
            $confset = $this->conf->conflict_types();
            $ctypes = [0 => $confset->unparse_text(0)];
            foreach ($confset->basic_conflict_types() as $n)
                $ctypes[$n] = $confset->unparse_text($n);
            $extra = ["class" => "pcconf-selector"];
            if ($this->admin) {
                $ctypes["xsep"] = null;
                $ctypes[CONFLICT_CHAIRMARK] = $confset->unparse_text(CONFLICT_CHAIRMARK);
                $extra["optionstyles"] = [CONFLICT_CHAIRMARK => "font-weight:bold"];
            }
            $author_ctype = $confset->unparse_html(CONFLICT_AUTHOR);
        }

        echo $this->editable_papt("pcconf", $this->field_name("PC conflicts"), ["id" => "pcconf"]),
            '<div class="paphint">Select the PC members who have conflicts of interest with this submission. ', $this->conf->_i("conflictdef", false), "</div>\n",
            '<div class="papev">',
            Ht::hidden("has_pcconf", 1),
            '<div class="pc-ctable">';
        foreach ($pcm as $id => $p) {
            $pct = $this->prow ? $this->prow->conflict_type($p) : 0;
            if ($this->useRequest)
                $ct = Conflict::constrain_editable($this->qreq["pcc$id"], $this->admin);
            else
                $ct = $pct;
            $pcconfmatch = null;
            if ($this->prow && $pct < CONFLICT_AUTHOR)
                $pcconfmatch = $this->prow->potential_conflict_html($p, $pct <= 0);

            $label = '<span class="taghl">' . $this->user->name_html_for($p) . '</span>';
            if ($p->affiliation)
                $label .= '<span class="pcconfaff">' . htmlspecialchars(UnicodeHelper::utf8_abbreviate($p->affiliation, 60)) . '</span>';

            echo '<div class="ctelt"><div class="ctelti';
            if (!$selectors)
                echo ' checki';
            echo ' clearfix';
            if ($show_colors && ($classes = $p->viewable_color_classes($this->user)))
                echo ' ', $classes;
            if ($pct)
                echo ' boldtag';
            if ($pcconfmatch)
                echo ' need-tooltip" data-tooltip-class="gray" data-tooltip="', str_replace('"', '&quot;', PaperInfo::potential_conflict_tooltip_html($pcconfmatch));
            echo '"><label>';

            $js = ["id" => "pcc$id"];
            $disabled = $pct >= CONFLICT_AUTHOR
                || ($pct > 0 && !$this->admin && !Conflict::is_author_mark($pct));
            if ($selectors) {
                echo '<span class="pcconf-editselector">';
                if ($disabled) {
                    echo '<strong>',
                        ($pct >= CONFLICT_AUTHOR ? $author_ctype : "Conflict"),
                        '</strong>',
                        Ht::hidden("pcc$id", $pct, ["class" => "conflict-entry"]);
                } else {
                    $js["class"] = "conflict-entry";
                    $js["data-default-value"] = Conflict::constrain_editable($pct, $this->admin);
                    echo Ht::select("pcc$id", $ctypes, Conflict::constrain_editable($ct, $this->admin), $js);
                }
                echo '</span>', $label;
            } else {
                $js["disabled"] = $disabled;
                $js["data-default-checked"] = $pct > 0;
                $js["data-range-type"] = "pcc";
                $js["class"] = "uix js-range-click conflict-entry";
                echo '<span class="checkc">',
                    Ht::checkbox("pcc$id", $ct > 0 ? $ct : CONFLICT_AUTHORMARK,
                                 $ct > 0, $js),
                    ' </span>', $label;
            }
            echo "</label>";
            if ($pcconfmatch)
                echo $pcconfmatch[0];
            echo "</div></div>";
        }
        echo "</div>", $this->messages_at("pcconf"), "</div></div>\n\n";
    }

    private function papstripPCConflicts() {
        assert(!$this->editable);
        if (!$this->prow)
            return;

        $pcconf = array();
        $pcm = $this->conf->pc_members();
        foreach ($this->prow->pc_conflicts() as $id => $x) {
            $p = $pcm[$id];
            $text = "<p class=\"odname\">" . $this->user->name_html_for($p) . "</p>";
            if ($this->user->isPC && ($classes = $p->viewable_color_classes($this->user)))
                $text = "<div class=\"pscopen $classes taghh\">$text</div>";
            $pcconf[$p->sort_position] = $text;
        }
        ksort($pcconf);
        if (!count($pcconf))
            $pcconf[] = "<p class=\"odname\">None</p>";
        $this->_papstripBegin();
        echo $this->papt("pcconflict", "PC conflicts", array("type" => "ps")),
            '<div class="psv psconf">', join("", $pcconf), "</div></div>\n";
    }

    private function _papstripLeadShepherd($type, $name, $showedit) {
        $editable = ($type === "manager" ? $this->user->privChair : $this->admin);

        $field = $type . "ContactId";
        if ($this->prow->$field == 0 && !$editable)
            return;
        $value = $this->prow->$field;

        $this->_papstripBegin($type, true, $editable ? ["class" => "ui-unfold js-unfold-pcselector"] : "");
        echo $this->papt($type, $name, array("type" => "ps", "fold" => $editable ? $type : false, "folded" => true)),
            '<div class="psv">';
        if (($p = $this->conf->pc_member_by_id($value)))
            $n = $this->user->name_html_for($p);
        else
            $n = $value ? "<strong>[removed from PC]</strong>" : "";
        $text = '<p class="fn odname js-psedit-result">' . $n . '</p>';
        echo '<div class="pcopen taghh';
        if ($p && ($classes = $this->user->user_color_classes_for($p)))
            echo ' ', $classes;
        echo '">', $text, '</div>';

        if ($editable) {
            $this->conf->stash_hotcrp_pc($this->user);
            echo '<form class="submit-ui fx"><div>',
                Ht::select($type, [], 0, ["class" => "psc-select want-focus", "style" => "width:99%", "data-pcselector-options" => "0 assignable selected", "data-pcselector-selected" => $value]),
                '</div></form>';
            Ht::stash_script('edit_paper_ui.prepare_psedit.call($$("fold' . $type . '"),{p:' . $this->prow->paperId . ',fn:"' . $type . '"})');
        }

        echo "</div></div>\n";
    }

    private function papstripLead($showedit) {
        $this->_papstripLeadShepherd("lead", "Discussion lead", $showedit || $this->qreq->atab === "lead");
    }

    private function papstripShepherd($showedit) {
        $this->_papstripLeadShepherd("shepherd", "Shepherd", $showedit || $this->qreq->atab === "shepherd");
    }

    private function papstripManager($showedit) {
        $this->_papstripLeadShepherd("manager", "Paper administrator", $showedit || $this->qreq->atab === "manager");
    }

    private function papstripTags() {
        if (!$this->prow || !$this->user->can_view_tags($this->prow))
            return;
        $tags = $this->prow->all_tags_text();
        $is_editable = $this->user->can_change_some_tag($this->prow);
        if ($tags === "" && !$is_editable)
            return;

        // Note that tags MUST NOT contain HTML special characters.
        $tagger = new Tagger($this->user);
        $viewable = $this->prow->viewable_tags($this->user);

        $tx = $tagger->unparse_link($viewable);
        $unfolded = $is_editable && ($this->has_problem_at("tags") || $this->qreq->atab === "tags");

        $this->_papstripBegin("tags", true);
        echo '<div class="pscopen">';

        if ($is_editable) {
            echo Ht::form(hoturl("paper", "p=" . $this->prow->paperId), ["data-pid" => $this->prow->paperId, "data-no-tag-report" => $unfolded ? 1 : null]);
            Ht::stash_script('edit_paper_ui.prepare_pstags.call($$("foldtags"))');
        }

        echo $this->papt("tags", "Tags", ["type" => "ps", "editfolder" => ($is_editable ? "tags" : 0)]),
            '<div class="psv">';
        if ($is_editable) {
            // tag report form
            $treport = PaperApi::tagreport($this->user, $this->prow);
            $tm0 = $tm1 = [];
            $tms = 0;
            foreach ($treport->tagreport as $tr) {
                $search = isset($tr->search) ? $tr->search : "#" . $tr->tag;
                $tm = Ht::link("#" . $tr->tag, hoturl("search", ["q" => $search]), ["class" => "q"]) . ": " . $tr->message;
                $tms = max($tms, $tr->status);
                $tm0[] = $tm;
                if ($tr->status > 0 && $this->prow->has_tag($tagger->expand($tr->tag)))
                    $tm1[] = $tm;
            }

            // uneditable
            echo '<div class="fn want-tag-report-warnings">';
            if (!empty($tm1))
                echo Ht::msg($tm1, 1);
            echo '</div><div class="fn js-tag-result">',
                ($tx === "" ? "None" : $tx), '</div>';

            echo '<div class="fx js-tag-editor"><div class="want-tag-report">';
            if (!empty($tm0))
                echo Ht::msg($tm0, $tms);
            echo "</div>";
            $editable = $tags;
            if ($this->prow)
                $editable = $this->prow->editable_tags($this->user);
            echo '<div style="position:relative">',
                '<textarea cols="20" rows="4" name="tags" style="width:97%;margin:0" class="want-focus need-suggest tags">',
                $tagger->unparse($editable),
                "</textarea></div>",
                '<div class="aab aabr aab-compact"><div class="aabut">',
                Ht::submit("save", "Save", ["class" => "btn-primary"]),
                '</div><div class="aabut">',
                Ht::submit("cancel", "Cancel"),
                "</div></div>",
                '<span class="hint"><a href="', hoturl("help", "t=tags"), '">Learn more</a> <span class="barsep">·</span> <strong>Tip:</strong> Twiddle tags like “~tag” are visible only to you.</span>',
                "</div>";
        } else
            echo '<div class="js-tag-result">', ($tx === "" ? "None" : $tx), '</div>';
        echo "</div>";

        if ($is_editable)
            echo "</form>";
        if ($unfolded)
            echo Ht::unstash_script('fold("tags",0)');
        echo "</div></div>\n";
    }

    function papstripOutcomeSelector() {
        $this->_papstripBegin("decision", $this->qreq->atab !== "decision");
        echo $this->papt("decision", "Decision", array("type" => "ps", "fold" => "decision")),
            '<div class="psv"><form class="submit-ui fx"><div>';
        if (isset($this->qreq->forceShow))
            echo Ht::hidden("forceShow", $this->qreq->forceShow ? 1 : 0);
        echo Ht::select("decision", $this->conf->decision_map(),
                        (string) $this->prow->outcome,
                        ["class" => "want-focus w-99"]),
            '</div></form><p class="fn odname js-psedit-result">',
            htmlspecialchars($this->conf->decision_name($this->prow->outcome)),
            "</p></div></div>\n";
        Ht::stash_script('edit_paper_ui.prepare_psedit.call($$("folddecision"),{p:' . $this->prow->paperId . ',fn:"decision"})');
    }

    function papstripReviewPreference() {
        $this->_papstripBegin("revpref");
        echo $this->papt("revpref", "Review preference", array("type" => "ps")),
            "<div class=\"psv\"><form class=\"ui\"><div>";
        $rp = unparse_preference($this->prow);
        $rp = ($rp == "0" ? "" : $rp);
        echo "<input id=\"revprefform_d\" type=\"text\" name=\"revpref", $this->prow->paperId,
            "\" size=\"4\" value=\"$rp\" class=\"revpref want-focus want-select\" />",
            "</div></form></div></div>\n";
        Ht::stash_script("add_revpref_ajax(\"#revprefform_d\",true);shortcut(\"revprefform_d\").add()");
    }

    private function papstrip_tag_entry($id, $folds) {
        if (!$this->npapstrip_tag_entry)
            $this->_papstripBegin(null, null, ["class" => "psc_te"]);
        ++$this->npapstrip_tag_entry;
        echo '<div', ($id ? " id=\"fold{$id}\"" : ""),
            ' class="pste', ($folds ? " $folds" : ""), '">';
    }

    private function papstrip_tag_float($tag, $kind, $type) {
        $class = "is-nonempty-tags float-right";
        if (($totval = $this->prow->tag_value($tag)) === false) {
            $totval = "";
            $class .= " hidden";
        }
        $reverse = $type !== "rank";
        $extradiv = "";
        if ($type === "vote" || $type === "approval") {
            $class .= " need-tooltip";
            $extradiv = ' data-tooltip-dir="h" data-tooltip-info="votereport" data-tag="' . htmlspecialchars($tag) . '"';
        }
        return '<div class="' . $class . '"' . $extradiv
            . '><a class="qq" href="' . hoturl("search", "q=" . urlencode("show:#$tag sort:" . ($reverse ? "-" : "") . "#$tag")) . '">'
            . '<span class="is-tag-index" data-tag-base="' . $tag . '">' . $totval . '</span> ' . $kind . '</a></div>';
    }

    private function papstrip_tag_entry_title($start, $tag, $value) {
        $title = $start . '<span class="fn is-nonempty-tags';
        if ($value === "")
            $title .= ' hidden';
        return $title . '">: <span class="is-tag-index" data-tag-base="' . $tag . '">' . $value . '</span></span>';
    }

    private function papstripRank($tag) {
        $id = "rank_" . html_id_encode($tag);
        if (($myval = $this->prow->tag_value($this->user->contactId . "~$tag")) === false)
            $myval = "";
        $totmark = $this->papstrip_tag_float($tag, "overall", "rank");

        $this->papstrip_tag_entry($id, "foldc fold2c");
        echo Ht::form("", ["class" => "need-tag-index-form", "data-pid" => $this->prow->paperId]);
        if (isset($this->qreq->forceShow))
            echo Ht::hidden("forceShow", $this->qreq->forceShow);
        echo $this->papt($id, $this->papstrip_tag_entry_title("#$tag rank", "~$tag", $myval),
                         array("type" => "ps", "fold" => $id, "float" => $totmark)),
            '<div class="psv"><div class="fx">',
            Ht::entry("tagindex", $myval,
                      array("size" => 4, "class" => "is-tag-index want-focus",
                            "data-tag-base" => "~$tag")),
            ' <span class="barsep">·</span> ',
            '<a href="', hoturl("search", "q=" . urlencode("editsort:#~$tag")), '">Edit all</a>',
            ' <div class="hint" style="margin-top:4px"><strong>Tip:</strong> <a href="', hoturl("search", "q=" . urlencode("editsort:#~$tag")), '">Search “editsort:#~', $tag, '”</a> to drag and drop your ranking, or <a href="', hoturl("offline"), '">use offline reviewing</a> to rank many papers at once.</div>',
            "</div></div></form></div>\n";
        Ht::stash_script('edit_paper_ui.prepare_pstagindex()');
    }

    private function papstripVote($tag, $allotment) {
        $id = "vote_" . html_id_encode($tag);
        if (($myval = $this->prow->tag_value($this->user->contactId . "~$tag")) === false)
            $myval = "";
        $totmark = $this->papstrip_tag_float($tag, "total", "vote");

        $this->papstrip_tag_entry($id, "foldc fold2c");
        echo Ht::form("", ["class" => "need-tag-index-form", "data-pid" => $this->prow->paperId]);
        if (isset($this->qreq->forceShow))
            echo Ht::hidden("forceShow", $this->qreq->forceShow);
        echo $this->papt($id, $this->papstrip_tag_entry_title("#$tag votes", "~$tag", $myval),
                         array("type" => "ps", "fold" => $id, "float" => $totmark)),
            '<div class="psv"><div class="fx">',
            Ht::entry("tagindex", $myval,
                      array("size" => 4, "class" => "is-tag-index want-focus",
                            "data-tag-base" => "~$tag")),
            " &nbsp;of $allotment",
            ' <span class="barsep">·</span> ',
            '<a href="', hoturl("search", "q=" . urlencode("editsort:-#~$tag")), '">Edit all</a>',
            "</div></div></form></div>\n";
        Ht::stash_script('edit_paper_ui.prepare_pstagindex()');
    }

    private function papstripApproval($tag) {
        $id = "approval_" . html_id_encode($tag);
        if (($myval = $this->prow->tag_value($this->user->contactId . "~$tag")) === false)
            $myval = "";
        $totmark = $this->papstrip_tag_float($tag, "total", "approval");

        $this->papstrip_tag_entry(null, null);
        echo Ht::form("", ["class" => "need-tag-index-form", "data-pid" => $this->prow->paperId]);
        if (isset($this->qreq->forceShow))
            echo Ht::hidden("forceShow", $this->qreq->forceShow);
        echo $this->papt($id,
                         Ht::checkbox("tagindex", "0", $myval !== "",
                                      array("class" => "is-tag-index want-focus",
                                            "data-tag-base" => "~$tag",
                                            "style" => "padding-left:0;margin-left:0;margin-top:0"))
                         . "&nbsp;" . Ht::label("#$tag vote"),
                         array("type" => "ps", "float" => $totmark)),
            "</form></div>\n\n";
        Ht::stash_script('edit_paper_ui.prepare_pstagindex()');
    }

    private function papstripWatch() {
        $prow = $this->prow;
        $conflictType = $prow->conflict_type($this->user);
        if (!($prow->timeSubmitted > 0
              && ($conflictType >= CONFLICT_AUTHOR
                  || $conflictType <= 0
                  || $this->user->is_admin_force())
              && $this->user->contactId > 0))
            return;
        // watch note
        $watch = $this->conf->fetch_ivalue("select watch from PaperWatch where paperId=? and contactId=?", $prow->paperId, $this->user->contactId);

        $this->_papstripBegin();

        echo '<form class="submit-ui"><div>',
            $this->papt("watch",
                        Ht::checkbox("follow", 1,
                                     $this->user->following_reviews($prow, $watch),
                                     ["class" => "js-follow-change",
                                      "style" => "padding-left:0;margin-left:0"])
                        . "&nbsp;" . Ht::label("Email notification"),
                        array("type" => "ps")),
            '<div class="pshint">Select to receive email on updates to reviews and comments.</div>',
            "</div></form></div>\n\n";
        Ht::stash_script('$(".js-follow-change").on("change", handle_ui)');
    }


    // Functions for editing

    function deadline_setting_is($dname, $dl = "deadline") {
        global $Now;
        $deadline = $this->conf->printableTimeSetting($dname, "span");
        if ($deadline === "N/A")
            return "";
        else if ($Now < $this->conf->setting($dname))
            return " The $dl is $deadline.";
        else
            return " The $dl was $deadline.";
    }

    private function _deadline_override_message() {
        if ($this->admin)
            return " As an administrator, you can make changes anyway.";
        else
            return $this->_forceShow_message();
    }
    private function _forceShow_message() {
        if (!$this->admin && $this->allow_admin)
            return " " . Ht::link("(Override your conflict)", $this->conf->selfurl($this->qreq, ["forceShow" => 1]), ["class" => "nw"]);
        else
            return "";
    }

    private function _edit_message_new_paper() {
        global $Now;
        $msg = "";
        if (!$this->conf->timeStartPaper()) {
            $sub_open = $this->conf->setting("sub_open");
            if ($sub_open <= 0 || $sub_open > $Now)
                $msg = "The site is not open for submissions." . $this->_deadline_override_message();
            else
                $msg = 'The <a href="' . hoturl("deadlines") . '">deadline</a> for registering submissions has passed.' . $this->deadline_setting_is("sub_reg") . $this->_deadline_override_message();
            if (!$this->admin) {
                $this->quit = true;
                return '<div class="merror">' . $msg . '</div>';
            }
            $msg = Ht::msg($msg, 1);
        }

        $t = [$this->conf->_("Enter information about your submission.")];
        $sub_reg = $this->conf->setting("sub_reg");
        $sub_upd = $this->conf->setting("sub_update");
        if ($sub_reg > 0 && $sub_upd > 0 && $sub_reg < $sub_upd) {
            $t[] = $this->conf->_("All submissions must be registered by %s and completed by %s.", $this->conf->printableTimeSetting("sub_reg"), $this->conf->printableTimeSetting("sub_sub"));
            if (!$this->conf->opt("noPapers"))
                $t[] = $this->conf->_("PDF upload is not required to register.");
        } else if ($sub_upd > 0)
            $t[] = $this->conf->_("All submissions must be completed by %s.", $this->conf->printableTimeSetting("sub_update"));
        $msg .= Ht::msg(space_join($t), 0);
        if (($v = $this->conf->_i("submit", false)))
            $msg .= Ht::msg($v, 0);
        return $msg;
    }

    private function _edit_message_for_author(PaperInfo $prow) {
        $can_view_decision = $prow->outcome != 0 && $this->user->can_view_decision($prow);
        if ($can_view_decision && $prow->outcome < 0) {
            return Ht::msg("The submission was not accepted." . $this->_forceShow_message(), 1);
        } else if ($prow->timeWithdrawn > 0) {
            if ($this->user->can_revive_paper($prow))
                return Ht::msg("The submission has been withdrawn, but you can still revive it." . $this->deadline_setting_is("sub_update"), 1);
            else
                return Ht::msg("The submission has been withdrawn." . $this->_forceShow_message(), 1);
        } else if ($prow->timeSubmitted <= 0) {
            $whyNot = $this->user->perm_update_paper($prow);
            if (!$whyNot) {
                $t = [];
                if (empty($prow->missing_fields(false, $this->user)))
                    $t[] = $this->conf->_("This submission is marked as not ready for review.");
                else
                    $t[] = $this->conf->_("This submission is incomplete.");
                if ($this->conf->setting("sub_update"))
                    $t[] = $this->conf->_("All submissions must be completed by %s to be considered.", $this->conf->printableTimeSetting("sub_update"));
                else
                    $t[] = $this->conf->_("Incomplete submissions will not be considered.");
                return Ht::msg(space_join($t), 1);
            } else if (isset($whyNot["updateSubmitted"])
                       && $this->user->can_finalize_paper($prow)) {
                return Ht::msg('The submission is not ready for review. Although you cannot make any further changes, the current version can be still be submitted for review.' . $this->deadline_setting_is("sub_sub") . $this->_deadline_override_message(), 1);
            } else if (isset($whyNot["deadline"])) {
                if ($this->conf->deadlinesBetween("", "sub_sub", "sub_grace")) {
                    return Ht::msg('The site is not open for updates at the moment.' . $this->_deadline_override_message(), 1);
                } else {
                    return Ht::msg('The <a href="' . hoturl("deadlines") . '">submission deadline</a> has passed and the submission will not be reviewed.' . $this->deadline_setting_is("sub_sub") . $this->_deadline_override_message(), 1);
                }
            } else {
                return Ht::msg('The submission is not ready for review and can’t be changed further. It will not be reviewed.' . $this->_deadline_override_message(), 1);
            }
        } else if ($this->user->can_update_paper($prow)) {
            if ($this->mode === "edit")
                return Ht::msg('The submission is ready and will be considered for review. You do not need to take further action. However, you can still make changes if you wish.' . $this->deadline_setting_is("sub_update", "submission deadline"), "confirm");
        } else if ($this->conf->allow_final_versions()
                   && $prow->outcome > 0
                   && $can_view_decision) {
            if ($this->user->can_submit_final_paper($prow)) {
                if (($t = $this->conf->_i("finalsubmit", false, $this->deadline_setting_is("final_soft"))))
                    return Ht::msg($t, 0);
            } else if ($this->mode === "edit") {
                return Ht::msg("The deadline for updating final versions has passed. You can still change contact information." . $this->_deadline_override_message(), 1);
            }
        } else if ($this->mode === "edit") {
            if ($this->user->can_withdraw_paper($prow, true))
                $t = "The submission is under review and can’t be changed, but you can change its contacts or withdraw it from consideration.";
            else
                $t = "The submission is under review and can’t be changed or withdrawn, but you can change its contacts.";
            return Ht::msg($t . $this->_deadline_override_message(), 0);
        }
        return "";
    }

    private function _edit_message() {
        if (!($prow = $this->prow))
            return $this->_edit_message_new_paper();

        $m = "";
        $has_author = $prow->has_author($this->user);
        $can_view_decision = $prow->outcome != 0 && $this->user->can_view_decision($prow);
        if ($has_author)
            $m .= $this->_edit_message_for_author($prow);
        else if ($this->conf->allow_final_versions()
                 && $prow->outcome > 0
                 && !$prow->can_author_view_decision())
            $m .= Ht::msg("The submission has been accepted, but its authors can’t see that yet. Once decisions are visible, the system will allow accepted authors to upload final versions.", 0);
        else
            $m .= Ht::msg("You aren’t a contact for this submission, but as an administrator you can still make changes.", 0);
        if ($this->user->call_with_overrides(Contact::OVERRIDE_TIME, "can_update_paper", $prow)
            && ($v = $this->conf->_i("submit", false)))
            $m .= Ht::msg($v, 0);
        if ($this->edit_status
            && $this->edit_status->has_problem()
            && ($this->edit_status->has_problem_at("contacts") || $this->editable)) {
            $fields = [];
            foreach ($this->edit_fields ? : [] as $uf)
                if (isset($uf->title) && $this->edit_status->has_problem_at($uf->name))
                    $fields[] = Ht::link($this->field_name($uf->title), "#" . (isset($uf->readable_formid) ? $uf->readable_formid : $uf->name));
            $m .= Ht::msg($this->conf->_c("paper_edit", "Please check %s before completing your submission.", commajoin($fields)), $this->edit_status->problem_status());
        }
        return $m;
    }

    function _collectActionButtons() {
        $pid = $this->prow ? $this->prow->paperId : "new";

        // Withdrawn papers can be revived
        if ($this->_prow->timeWithdrawn > 0) {
            $revivable = $this->conf->timeFinalizePaper($this->_prow);
            if ($revivable)
                $b = Ht::submit("revive", "Revive submission", ["class" => "btn-primary"]);
            else {
                $b = 'The <a href="' . hoturl("deadlines") . '">deadline</a> for reviving withdrawn submissions has passed. Are you sure you want to override it?';
                if ($this->admin)
                    $b = array(Ht::button("Revive submission", ["class" => "ui js-override-deadlines", "data-override-text" => $b, "data-override-submit" => "revive"]), "(admin only)");
            }
            return array($b);
        }

        $buttons = array();

        if ($this->mode === "edit") {
            // check whether we can save
            $old_overrides = $this->user->set_overrides(0);
            if ($this->canUploadFinal) {
                $updater = "submitfinal";
                $whyNot = $this->user->perm_submit_final_paper($this->prow);
            } else if ($this->prow) {
                $updater = "update";
                $whyNot = $this->user->perm_update_paper($this->prow);
            } else {
                $updater = "update";
                $whyNot = $this->user->perm_start_paper();
            }
            $this->user->set_overrides($old_overrides);
            // produce button
            if (!$this->is_ready(false))
                $save_name = "Save draft";
            else if ($this->prow && $this->prow->timeSubmitted > 0)
                $save_name = "Save and resubmit";
            else
                $save_name = "Save and submit";
            if (!$whyNot) {
                $buttons[] = array(Ht::submit($updater, $save_name, ["class" => "btn-primary btn-savepaper"]), "");
            } else if ($this->admin) {
                $revWhyNot = filter_whynot($whyNot, ["deadline", "rejected"]);
                $x = whyNotText($revWhyNot) . " Are you sure you want to override the deadline?";
                $buttons[] = array(Ht::button($save_name, ["class" => "btn-primary btn-savepaper ui js-override-deadlines", "data-override-text" => $x, "data-override-submit" => $updater]), "(admin only)");
            } else if (isset($whyNot["updateSubmitted"])
                       && $this->user->can_finalize_paper($this->_prow)) {
                $buttons[] = Ht::submit("update", $save_name, ["class" => "btn-savepaper"]);
            } else if ($this->prow) {
                $buttons[] = Ht::submit("updatecontacts", "Save contacts");
            }
            if (!empty($buttons)) {
                $buttons[] = Ht::submit("cancel", "Cancel");
                $buttons[] = "";
            }
        }

        // withdraw button
        if (!$this->prow
            || !$this->user->call_with_overrides(Contact::OVERRIDE_TIME, "can_withdraw_paper", $this->prow, true))
            $b = null;
        else if ($this->prow->timeSubmitted <= 0)
            $b = Ht::submit("withdraw", "Withdraw");
        else {
            $args = ["class" => "ui js-withdraw"];
            if ($this->user->can_withdraw_paper($this->prow, !$this->admin))
                $args["data-withdrawable"] = "true";
            if (($this->admin && !$this->prow->has_author($this->user))
                || $this->conf->timeFinalizePaper($this->prow))
                $args["data-revivable"] = "true";
            $b = Ht::button("Withdraw", $args);
        }
        if ($b) {
            if ($this->admin && !$this->user->can_withdraw_paper($this->prow))
                $b = array($b, "(admin only)");
            $buttons[] = $b;
        }

        return $buttons;
    }

    function echoActions($top) {
        if ($this->admin && !$top) {
            $v = (string) $this->qreq->emailNote;
            echo '<div class="checki"><span class="checkc">', Ht::checkbox("doemail", 1, true, ["class" => "ignore-diff"]), " </span>",
                Ht::label("Email authors, including:"), "&nbsp; ",
                Ht::entry("emailNote", $v, ["size" => 30, "placeholder" => "Optional explanation", "class" => "ignore-diff js-autosubmit", "aria-label" => "Explanation for update"]),
                "</div>\n";
        }

        $buttons = $this->_collectActionButtons();

        if ($this->admin && $this->prow)
            $buttons[] = array(Ht::button("Delete", ["class" => "ui js-delete-paper"]), "(admin only)");

        echo Ht::actions($buttons, array("class" => "aab aabr aabig"));
    }


    // Functions for overall paper table viewing

    function _papstrip() {
        $prow = $this->prow;
        if (($prow->managerContactId || ($this->user->privChair && $this->mode === "assign"))
            && $this->user->can_view_manager($prow))
            $this->papstripManager($this->user->privChair);
        $this->papstripTags();
        $this->npapstrip_tag_entry = 0;
        foreach ($this->conf->tags() as $ltag => $t)
            if ($this->user->can_change_tag($prow, "~$ltag", null, 0)) {
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
        if ($this->user->can_view_conflicts($prow) && !$this->editable)
            $this->papstripPCConflicts();
        if ($this->user->allow_view_authors($prow) && !$this->editable)
            $this->papstripCollaborators();

        if ($this->user->can_set_decision($prow))
            $this->papstripOutcomeSelector();
        if ($this->user->can_view_lead($prow))
            $this->papstripLead($this->mode === "assign");
        if ($this->user->can_view_shepherd($prow))
            $this->papstripShepherd($this->mode === "assign");

        if ($this->user->can_accept_review_assignment($prow)
            && $this->conf->timePCReviewPreferences()
            && ($this->user->roles & (Contact::ROLE_PC | Contact::ROLE_CHAIR)))
            $this->papstripReviewPreference();
    }

    function _paptabTabLink($text, $link, $image, $highlight) {
        return '<div class="' . ($highlight ? "papmodex" : "papmode")
            . '"><a href="' . $link . '" class="noul">'
            . Ht::img($image, "[$text]", "papmodeimg")
            . "&nbsp;<u" . ($highlight ? ' class="x"' : "") . ">" . $text
            . "</u></a></div>\n";
    }

    private function _paptabBeginKnown() {
        $prow = $this->prow;

        // what actions are supported?
        $canEdit = $this->user->allow_edit_paper($prow);
        $canReview = $this->user->can_review($prow, null);
        $canAssign = $this->admin;
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
                $t .= $this->_paptabTabLink("Review", hoturl("review", "p=$prow->paperId&amp;m=re"), "review48.png", $this->mode === "re" && (!$this->editrrow || $this->editrrow->contactId == $this->user->contactId));

            if ($canAssign)
                $t .= $this->_paptabTabLink("Assign", hoturl("assign", "p=$prow->paperId"), "assign48.png", $this->mode === "assign");

            $t .= "</div>";
        }

        return $t;
    }

    static private function _echo_clickthrough($ctype) {
        global $Conf, $Now;
        $data = $Conf->_i("clickthrough_$ctype", false);
        echo Ht::form(["class" => "ui"]), '<div>', $data;
        $buttons = [Ht::submit("Agree", ["class" => "btnbig btn-highlight ui js-clickthrough"])];
        echo Ht::hidden("clickthrough_type", $ctype),
            Ht::hidden("clickthrough_id", sha1($data)),
            Ht::hidden("clickthrough_time", $Now),
            Ht::actions($buttons, ["class" => "aab aabig aabr"]), "</div></form>";
    }

    static function echo_review_clickthrough() {
        echo '<div class="revcard js-clickthrough-terms"><div class="revcard_head"><h3>Reviewing terms</h3></div><div class="revcard_body">', Ht::msg("You must agree to these terms before you can save reviews.", 2);
        self::_echo_clickthrough("review");
        echo "</form></div></div>";
    }

    private function _echo_editable_form() {
        $form_js = ["id" => "paperform", "class" => "need-unload-protection"];
        if ($this->prow && $this->prow->timeSubmitted > 0)
            $form_js["data-submitted"] = $this->prow->timeSubmitted;
        if ($this->prow && !$this->editable)
            $form_js["data-contacts-only"] = 1;
        if ($this->useRequest)
            $form_js["class"] .= " alert";
        echo Ht::form(hoturl_post("paper", "p=" . ($this->prow ? $this->prow->paperId : "new") . "&amp;m=edit"), $form_js);
        Ht::stash_script('$("#paperform").on("change", ".js-check-submittable", handle_ui)');
        if ($this->prow
            && $this->prow->paperStorageId > 1
            && $this->prow->timeSubmitted > 0
            && !$this->conf->setting("sub_freeze"))
            Ht::stash_script('$("#paperform").on("submit", edit_paper_ui)');
        Ht::stash_script('$(function(){$("#paperform input[name=paperUpload]").trigger("change")})');
    }

    private function make_echo_editable_option($o) {
        return (object) [
            "name" => $o->formid,
            "readable_formid" => $o->readable_formid,
            "title" => htmlspecialchars($o->title),
            "position" => $o->form_position(),
            "option" => $o,
            "callback" => function () use ($o) {
                if ($o->edit_condition()
                    && !$o->compile_edit_condition($this->_prow))
                    return;
                $ov = $this->_prow->option($o->id);
                $ov = $ov ? : new PaperOptionValue($this->prow, $o);
                $reqv = null;
                if ($this->useRequest && $this->qreq["has_{$o->formid}"])
                    $reqv = $o->parse_request_display($this->qreq, $this->user, $this->prow);
                $o->echo_editable_html($ov, $reqv, $this);
            }
        ];
    }

    private function _echo_editable_body() {
        $this->_echo_editable_form();
        echo '<div>';

        $ofields = [];
        foreach ($this->conf->paper_opts->feature_list($this->prow) as $opt)
            if ($opt->id > 0
                && (!$this->prow || get($this->view_options, $opt->id))
                && !$opt->internal)
                $ofields[] = $this->make_echo_editable_option($opt);
        $gxt = new GroupedExtensions($this->user, ["etc/submissioneditgroups.json"], $this->conf->opt("submissionEditGroups"), $ofields);
        $this->edit_fields = array_values($gxt->groups());

        if (($m = $this->_edit_message()))
            echo $m;
        if ($this->quit) {
            echo "</div></form>";
            return;
        }

        $this->echoActions(true);

        for ($this->edit_fields_position = 0;
             $this->edit_fields_position < count($this->edit_fields);
             ++$this->edit_fields_position) {
            $uf = $this->edit_fields[$this->edit_fields_position];
            $cb = get($uf, "callback");
            if ($cb instanceof Closure)
                call_user_func($cb, $uf);
            else if (is_string($cb) && str_starts_with($cb, "*"))
                call_user_func([$this, substr($cb, 1)], $uf);
            else if ($cb)
                call_user_func($cb, $this, $uf);
        }

        // Submit button
        $this->echo_editable_complete();
        $this->echoActions(false);

        echo "</div></form>";
    }

    function paptabBegin() {
        $prow = $this->prow;

        if ($prow)
            $this->_papstrip();
        if ($this->npapstrip)
            echo "</div></div></div>\n<div class=\"papcard\">";
        else
            echo '<div class="pedcard">';
        if ($this->editable)
            echo '<div class="pedcard_body">';
        else
            echo '<div class="papcard_body">';

        $this->echoDivEnter();
        if ($this->editable) {
            if (!$this->user->can_clickthrough("submit")) {
                echo '<div class="js-clickthrough-container">',
                    '<div class="js-clickthrough-terms">',
                    '<h3>Submission terms</h3>',
                    Ht::msg("You must agree to these terms to register a submission.", 2);
                self::_echo_clickthrough("submit");
                echo '</div><div class="js-clickthrough-body hidden">';
                $this->_echo_editable_body();
                echo '</div></div>';
            } else
                $this->_echo_editable_body();
        } else {
            if ($this->mode === "edit" && ($m = $this->_edit_message()))
                echo $m, "<hr class=\"g\">\n";
            $status_info = $this->user->paper_status_info($this->prow);
            echo '<p class="xd"><span class="pstat ', $status_info[0], '">',
                htmlspecialchars($status_info[1]), "</span></p>";
            $this->paptabDownload();
            echo '<div class="paperinfo"><div class="paperinfo-row">';
            $has_abstract = $this->paptabAbstract();
            echo '<div class="paperinfo-c', ($has_abstract ? "r" : "b"), '">';
            $this->paptabAuthors(!$this->editable && $this->mode === "edit"
                                 && $prow->timeSubmitted > 0);
            $this->paptabTopicsOptions();
            echo '</div></div></div>';
        }
        $this->echoDivExit();

        if (!$this->editable && $this->mode === "edit") {
            $this->_echo_editable_form();
            $this->echo_editable_contact_author();
            $this->echoActions(false);
            echo "</form>";
        } else if (!$this->editable && $this->user->act_author_view($prow)
                   && !$this->user->contactId) {
            echo '<hr class="papcard_sep" />',
                "To edit this submission, <a href=\"", hoturl("index"), "\">sign in using your email and password</a>.";
        }

        Ht::stash_script("shortcut().add()");
        if ($this->editable || $this->mode === "edit")
            Ht::stash_script('hiliter_children("#paperform")');
    }

    private function _paptabSepContaining($t) {
        if ($t !== "")
            echo '<hr class="papcard_sep" />', $t;
    }

    function _paptabReviewLinks($rtable, $editrrow, $ifempty) {
        require_once("reviewtable.php");

        $t = "";
        if ($rtable)
            $t .= reviewTable($this->prow, $this->all_rrows,
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
        $a = "<a href=\"" . $this->conf->selfurl($this->qreq, ["forceShow" => 0]) . "\">";
        return $a . Ht::img("override24.png", "[Override]", "dlimg")
            . "</a>&nbsp;You have used administrator privileges to view and edit reviews for this submission. (" . $a . "Unprivileged view</a>)";
    }

    private function include_comments() {
        return !$this->allreviewslink
            && (!empty($this->mycrows)
                || $this->user->can_comment($this->prow, null)
                || $this->conf->any_response_open);
    }

    function paptabEndWithReviewsAndComments() {
        $prow = $this->prow;

        if ($this->user->is_admin_force()
            && !$this->user->call_with_overrides(0, "can_view_review", $prow, null))
            $this->_paptabSepContaining($this->_privilegeMessage());
        else if ($this->user->contactId == $prow->managerContactId
                 && !$this->user->privChair
                 && $this->user->contactId > 0)
            $this->_paptabSepContaining("You are this submission’s administrator.");

        $empty = $this->_paptabReviewLinks(true, null, '<p>There are no reviews or comments for you to view.</p>');
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
            if ($this->user->can_view_comment($prow, $cr)) {
                $viewable[] = "comments";
                break;
            }
        if (count($viewable))
            echo '<div class="notecard"><div class="notecard_body">',
                '<a href="', hoturl("review", "p=$prow->paperId&amp;m=r&amp;text=1"), '" class="xx">',
                Ht::img("txt24.png", "[Text]", "dlimg"),
                "&nbsp;<u>", ucfirst(join(" and ", $viewable)),
                " in plain text</u></a></div></div>\n";

        $this->render_rc(true, $this->include_comments());
    }

    private function has_response($respround) {
        foreach ($this->mycrows as $cr)
            if (($cr->commentType & COMMENTTYPE_RESPONSE)
                && $cr->commentRound == $respround)
                return true;
        return false;
    }

    private function render_rc($reviews, $comments) {
        $rcs = [];
        if ($reviews) {
            foreach ($this->viewable_rrows as $rrow)
                if ($rrow->reviewSubmitted || $rrow->reviewModified > 1)
                    $rcs[] = $rrow;
        }
        if ($comments && $this->mycrows)
            $rcs = array_merge($rcs, $this->mycrows);
        usort($rcs, "PaperInfo::review_or_comment_compare");

        $s = "";
        $ncmt = 0;
        $rf = $this->conf->review_form();
        foreach ($rcs as $rc) {
            if (isset($rc->reviewId)) {
                $rcj = $rf->unparse_review_json($this->prow, $rc, $this->user);
                $s .= "review_form.add_review(" . json_encode_browser($rcj) . ");\n";
            } else {
                ++$ncmt;
                $rcj = $rc->unparse_json($this->user);
                $s .= "papercomment.add(" . json_encode_browser($rcj) . ");\n";
            }
        }

        if ($comments) {
            $cs = [];
            if ($this->user->can_comment($this->prow, null)) {
                $ct = $this->prow->has_author($this->user) ? COMMENTTYPE_BYAUTHOR : 0;
                $cs[] = new CommentInfo((object) ["commentType" => $ct], $this->prow);
            }
            if ($this->admin || $this->prow->has_author($this->user)) {
                foreach ($this->conf->resp_rounds() as $rrd)
                    if (!$this->has_response($rrd->number)
                        && $rrd->relevant($this->user, $this->prow)) {
                        $crow = CommentInfo::make_response_template($rrd->number, $this->prow);
                        if ($this->user->can_respond($this->prow, $crow))
                            $cs[] = $crow;
                    }
            }
            foreach ($cs as $c) {
                ++$ncmt;
                $s .= "papercomment.add(" . json_encode_browser($c->unparse_json($this->user)) . ");\n";
            }
        }

        if ($ncmt)
            CommentInfo::echo_script($this->prow);
        if ($s !== "")
            echo Ht::unstash_script($s);
    }

    function paptabComments() {
        $this->render_rc(false, $this->include_comments());
    }

    function paptabEndWithReviewMessage() {
        if ($this->editable) {
            echo "</div></div>\n";
            return;
        }

        $m = array();
        if ($this->all_rrows
            && ($whyNot = $this->user->perm_view_review($this->prow, null)))
            $m[] = "You can’t see the reviews for this submission. " . whyNotText($whyNot);
        if ($this->prow
            && !$this->conf->time_review_open()
            && $this->prow->review_type($this->user)) {
            if ($this->rrow)
                $m[] = "You can’t edit your review because the site is not open for reviewing.";
            else
                $m[] = "You can’t begin your assigned review because the site is not open for reviewing.";
        }
        if (!empty($m))
            $this->_paptabSepContaining(join("<br>", $m));

        $this->_paptabReviewLinks(false, null, "");
    }

    function paptabEndWithEditableReview() {
        $prow = $this->prow;
        $act_pc = $this->user->act_pc($prow);

        // review messages
        $msgs = array();
        if (!$this->rrow && !$this->prow->review_type($this->user))
            $msgs[] = "You haven’t been assigned to review this submission, but you can review it anyway.";
        if ($this->user->is_admin_force()) {
            if (!$this->user->call_with_overrides(0, "can_view_review", $prow, null))
                $msgs[] = $this->_privilegeMessage();
        } else if (($whyNot = $this->user->perm_view_review($prow, null))
                   && isset($whyNot["reviewNotComplete"])
                   && ($this->user->isPC || $this->conf->setting("extrev_view"))) {
            $nother = 0;
            $myrrow = null;
            foreach ($this->all_rrows as $rrow)
                if ($this->user->is_my_review($rrow))
                    $myrrow = $rrow;
                else if ($rrow->reviewSubmitted)
                    ++$nother;
            if ($nother > 0) {
                if ($myrrow && $myrrow->timeApprovalRequested > 0)
                    $msgs[] = $this->conf->_("You’ll be able to see %d other reviews once yours is approved.", $nother);
                else
                    $msgs[] = $this->conf->_("You’ll be able to see %d other reviews once you complete your own.", $nother);
            }
        }
        if (count($msgs) > 0)
            $this->_paptabSepContaining(join("<br />\n", $msgs));

        // links
        $this->_paptabReviewLinks(true, $this->editrrow, "");

        // review form, possibly with deadline warning
        $opt = array("edit" => $this->mode === "re");

        if ($this->editrrow
            && ($this->user->is_owned_review($this->editrrow) || $this->admin)
            && !$this->conf->time_review($this->editrrow, $act_pc, true)) {
            if ($this->admin)
                $override = " As an administrator, you can override this deadline.";
            else {
                $override = "";
                if ($this->editrrow->reviewSubmitted)
                    $opt["edit"] = false;
            }
            if ($this->conf->time_review_open())
                $opt["editmessage"] = 'The <a href="' . hoturl("deadlines") . '">review deadline</a> has passed, so the review can no longer be changed.' . $override;
            else
                $opt["editmessage"] = "The site is not open for reviewing, so the review cannot be changed.$override";
        } else if (!$this->user->can_review($prow, $this->editrrow))
            $opt["edit"] = false;

        // maybe clickthrough
        $need_clickthrough = $opt["edit"] && !$this->user->can_clickthrough("review");
        $rf = $this->conf->review_form();
        if ($need_clickthrough) {
            echo '<div class="js-clickthrough-container">';
            self::echo_review_clickthrough();
            echo '<div class="js-clickthrough-body">';
        }
        $rf->show($prow, $this->editrrow, $opt, $this->review_values);
        if ($need_clickthrough)
            echo '</div></div>';
    }


    // Functions for loading papers

    static function clean_request(Qrequest $qreq) {
        if (!isset($qreq->paperId) && isset($qreq->p))
            $qreq->paperId = $qreq->p;
        if (!isset($qreq->reviewId) && isset($qreq->r))
            $qreq->reviewId = $qreq->r;
        if (!isset($qreq->commentId) && isset($qreq->c))
            $qreq->commentId = $qreq->c;
        if (!isset($qreq->reviewId)
            && preg_match(',\A/\d+[A-Z]+\z,i', Navigation::path()))
            $qreq->reviewId = substr(Navigation::path(), 1);
        else if (!isset($qreq->paperId)
                 && ($pc = Navigation::path_component(0)))
            $qreq->paperId = $pc;
        if (!isset($qreq->paperId)
            && isset($qreq->reviewId)
            && preg_match('/\A(\d+)[A-Z]+\z/i', $qreq->reviewId, $m))
            $qreq->paperId = $m[1];
        if (isset($qreq->paperId) || isset($qreq->reviewId))
            unset($qreq->q);
    }

    static private function simple_qreq($qreq) {
        return $qreq->method() === "GET"
            && !array_diff($qreq->keys(), ["p", "paperId", "m", "mode", "forceShow", "go", "actas", "t", "q", "r", "reviewId"]);
    }

    static private function lookup_pid($qreq, $user) {
        // if a number, don't search
        $pid = isset($qreq->paperId) ? $qreq->paperId : $qreq->q;
        if (preg_match('/\A\s*#?(\d+)\s*\z/', $pid, $m))
            return intval($m[1]);

        // look up a review ID
        if (!isset($pid) && isset($qreq->reviewId))
            return $user->conf->fetch_ivalue("select paperId from PaperReview where reviewId=?", $qreq->reviewId);

        // if a complex request, or a form upload, or empty user, don't search
        if (!self::simple_qreq($qreq) || $user->is_empty())
            return null;

        // if no paper ID set, find one
        if (!isset($pid)) {
            $q = "select min(Paper.paperId) from Paper ";
            if ($user->isPC)
                $q .= "where timeSubmitted>0";
            else if ($user->has_review())
                $q .= "join PaperReview on (PaperReview.paperId=Paper.paperId and PaperReview.contactId=$user->contactId)";
            else
                $q .= "join PaperConflict on (PaperConflict.paperId=Paper.paperId and PaperConflict.contactId=$user->contactId and PaperConflict.conflictType>=" . CONFLICT_AUTHOR . ")";
            return $user->conf->fetch_ivalue($q);
        }

        // actually try to search
        if ($pid === "" || $pid === "(All)")
            return null;

        $search = new PaperSearch($user, ["q" => $pid, "t" => $qreq->get("t")]);
        $ps = $search->paper_ids();
        if (count($ps) == 1) {
            $list = $search->session_list_object();
            // DISABLED: check if the paper is in the current list
            unset($qreq->ls);
            $list->set_cookie();
            return $ps[0];
        } else
            return null;
    }

    static function redirect_request($pid, Qrequest $qreq, Contact $user) {
        if ($pid !== null) {
            $qreq->paperId = $pid;
            unset($qreq->q, $qreq->p);
            $user->conf->self_redirect($qreq);
        } else if ((isset($qreq->paperId) || isset($qreq->q))
                   && !$user->is_empty()) {
            $q = "q=" . urlencode(isset($qreq->paperId) ? $qreq->paperId : $qreq->q);
            if ($qreq->t)
                $q .= "&t=" . urlencode($qreq->t);
            if (in_array(Navigation::page(), ["review", "assign"]))
                $q .= "&linkto=" . Navigation::page();
            go($user->conf->hoturl("search", $q));
        }
    }

    static function fetch_paper_request(Qrequest $qreq, Contact $user) {
        self::clean_request($qreq);
        $pid = self::lookup_pid($qreq, $user);
        if (self::simple_qreq($qreq)
            && ($pid === null || (string) $pid !== $qreq->paperId))
            self::redirect_request($pid, $qreq, $user);
        $sel = ["paperId" => $pid, "topics" => true, "options" => true];
        if ($user->privChair
            || ($user->isPC && $user->conf->timePCReviewPreferences()))
            $sel["reviewerPreference"] = true;
        $prow = $user->conf->fetch_paper($sel, $user);
        $whynot = $user->perm_view_paper($prow, false, $pid);
        if (!$whynot
            && !isset($qreq->paperId)
            && isset($qreq->reviewId)
            && !$user->privChair
            && (!($rrow = $prow->review_of_id($qreq->reviewId))
                || !$user->can_view_review($prow, $rrow)))
            $whynot = ["conf" => $user->conf, "invalidId" => "paper"];
        if ($whynot)
            $qreq->set_annex("paper_whynot", $whynot);
        return ($user->conf->paper = $whynot ? null : $prow);
    }

    function resolveReview($want_review) {
        $this->prow->ensure_full_reviews();
        $this->all_rrows = $this->prow->reviews_by_display();

        $this->viewable_rrows = array();
        $round_mask = 0;
        $min_view_score = VIEWSCORE_EMPTYBOUND;
        foreach ($this->all_rrows as $rrow)
            if ($this->user->can_view_review($this->prow, $rrow)) {
                $this->viewable_rrows[] = $rrow;
                if ($rrow->reviewRound !== null)
                    $round_mask |= 1 << (int) $rrow->reviewRound;
                $min_view_score = min($min_view_score, $this->user->view_score_bound($this->prow, $rrow));
            }
        $rf = $this->conf->review_form();
        Ht::stash_script("review_form.set_form(" . json_encode_browser($rf->unparse_json($round_mask, $min_view_score)) . ")");

        $rrid = strtoupper((string) $this->qreq->reviewId);
        while ($rrid !== "" && $rrid[0] === "0")
            $rrid = substr($rrid, 1);

        $this->rrow = $myrrow = $approvable_rrow = null;
        foreach ($this->viewable_rrows as $rrow) {
            if ($rrid !== ""
                && (strcmp($rrow->reviewId, $rrid) == 0
                    || ($rrow->reviewOrdinal
                        && strcmp($rrow->paperId . unparseReviewOrdinal($rrow->reviewOrdinal), $rrid) == 0)))
                $this->rrow = $rrow;
            if ($rrow->contactId == $this->user->contactId
                || (!$myrrow && $this->user->is_my_review($rrow)))
                $myrrow = $rrow;
            if (($rrow->requestedBy == $this->user->contactId || $this->admin)
                && !$rrow->reviewSubmitted
                && $rrow->timeApprovalRequested
                && !$approvable_rrow)
                $approvable_rrow = $rrow;
        }

        if ($this->rrow)
            $this->editrrow = $this->rrow;
        else if (!$approvable_rrow
                 || ($myrrow
                     && $myrrow->reviewModified
                     && !$this->prefer_approvable))
            $this->editrrow = $myrrow;
        else
            $this->editrrow = $approvable_rrow;

        if ($want_review && $this->user->can_review($this->prow, $this->editrrow, false))
            $this->mode = "re";
    }

    function resolveComments() {
        $this->crows = $this->mycrows = array();
        if ($this->prow) {
            $this->crows = $this->prow->all_comments();
            $this->mycrows = $this->prow->viewable_comments($this->user, true);
        }
    }

    function all_reviews() {
        return $this->all_rrows;
    }

    function fixReviewMode() {
        $prow = $this->prow;
        if ($this->mode === "re" && $this->rrow
            && !$this->user->can_review($prow, $this->rrow, false)
            && ($this->rrow->contactId != $this->user->contactId
                || $this->rrow->reviewSubmitted))
            $this->mode = "p";
        if ($this->mode === "p" && $this->rrow
            && !$this->user->can_view_review($prow, $this->rrow))
            $this->rrow = $this->editrrow = null;
        if ($this->mode === "p" && !$this->rrow && !$this->editrrow
            && $this->user->can_review($prow, null, false)) {
            $viewable_rrow = $my_rrow = null;
            foreach ($this->all_rrows as $rrow) {
                if ($this->user->can_view_review($prow, $rrow))
                    $viewable_rrow = $rrow;
                if ($rrow->contactId == $this->user->contactId
                    || (!$my_rrow && $this->user->is_my_review($rrow)))
                    $my_rrow = $rrow;
            }
            if (!$viewable_rrow) {
                $this->mode = "re";
                $this->editrrow = $my_rrow;
            }
        }
        if ($this->mode === "p" && $prow && empty($this->viewable_rrows)
            && empty($this->mycrows)
            && $prow->has_author($this->user)
            && !$this->allow_admin
            && ($this->conf->timeFinalizePaper($prow) || $prow->timeSubmitted <= 0))
            $this->mode = "edit";
    }
}
