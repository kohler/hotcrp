<?php
// papertable.php -- HotCRP helper class for producing paper tables
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class PaperTable {
    /** @var Conf
     * @readonly */
    public $conf;
    /** @var Contact
     * @readonly */
    public $user;
    /** @var Qrequest
     * @readonly */
    private $qreq;
    /** @var PaperInfo
     * @readonly */
    public $prow;
    /** @var 'p'|'edit'|'re'|'assign'
     * @readonly */
    public $mode;
    /** @var bool
     * @readonly */
    private $allow_admin;
    /** @var bool
     * @readonly */
    private $admin;
    /** @var bool
     * @readonly */
    private $allow_edit_final;
    /** @var bool
     * @readonly */
    private $can_view_reviews;

    /** @var ?ReviewInfo */
    public $rrow;
    /** @var list<ReviewInfo> */
    private $all_rrows = [];
    /** @var list<ReviewInfo> */
    private $viewable_rrows = [];
    /** @var array<int,CommentInfo> */
    private $crows;
    /** @var array<int,CommentInfo> */
    private $mycrows;
    /** @var ?ReviewInfo */
    public $editrrow;
    /** @var bool */
    private $prefer_approvable = false;
    /** @var bool */
    private $allreviewslink;

    /** @var bool
     * @readonly */
    public $editable = false;
    /** @var bool */
    private $useRequest;
    /** @var ?PaperStatus */
    private $edit_status;
    /** @var list<PaperOption> */
    private $edit_fields;
    /** @var bool */
    public $edit_show_all_visibility = false;

    /** @var ?list<MessageItem> */
    private $pre_status_feedback;
    /** @var int */
    private $npapstrip = 0;
    /** @var bool */
    private $allow_folds;
    /** @var bool */
    private $unfold_all = false;
    /** @var ?ReviewValues */
    private $review_values;
    /** @var array<string,TextPregexes> */
    private $matchPreg;
    /** @var array<int,bool> */
    private $foldmap;
    /** @var array<string,int> */
    private $foldnumber;

    /** @var ?CheckFormat */
    public $cf;
    /** @var bool */
    private $quit = false;

    function __construct(Contact $user, Qrequest $qreq, PaperInfo $prow = null) {
        $this->conf = $user->conf;
        $this->user = $user;
        $this->qreq = $qreq;
        $this->prow = $prow ?? PaperInfo::make_new($user);
        $this->allow_admin = $user->allow_administer($this->prow);
        $this->admin = $user->can_administer($this->prow);
        $this->allow_edit_final = $this->user->allow_edit_final_paper($this->prow);

        if (!$prow || !$this->prow->paperId) {
            $this->can_view_reviews = false;
            $this->mode = "edit";
            return;
        }

        $this->can_view_reviews = $user->can_view_review($prow, null);
        if (!$this->can_view_reviews && $prow->has_reviewer($user)) {
            foreach ($prow->reviews_by_user($user) as $rrow) {
                if ($rrow->reviewStatus >= ReviewInfo::RS_COMPLETED) {
                    $this->can_view_reviews = true;
                }
            }
        }

        // enumerate allowed modes
        $page = $qreq->page();
        if ($page === "review" && $this->allow_review()) {
            $this->mode = "re";
        } else if ($page === "paper"
                   && $this->paper_page_prefers_edit_mode()) {
            $this->mode = "edit";
        } else {
            $this->mode = "p";
        }
        if ($page === "assign") {
            $this->mode = "assign";
        } else {
            $m = $this->qreq->m ?? $this->qreq->mode;
            if (($m === "edit" || $m === "pe")
                && $page === "paper"
                && ($this->allow_admin || $this->allow_edit())) {
                $this->mode = "edit";
            } else if (($m === "re" || $m === "rea")
                       && $page === "review"
                       && $this->allow_review()) {
                $this->mode = "re";
                $this->prefer_approvable = $m === "rea";
            } else if ($m === "view" || $m === "r" || $m === "main") {
                $this->mode = "p";
            }
        }
    }

    /** @return bool */
    private function allow_edit() {
        return $this->admin || $this->prow->has_author($this->user);
    }

    /** @return bool */
    private function allow_review() {
        return $this->user->can_edit_some_review($this->prow);
    }

    /** @return bool */
    private function allow_assign() {
        return $this->admin || $this->user->can_request_review($this->prow, null, true);
    }

    /** @return bool */
    function paper_page_prefers_edit_mode() {
        return $this->prow->paperId === 0
            || ($this->prow->has_author($this->user) && $this->conf->time_finalize_paper($this->prow));
    }

    /** @param ?PaperTable $paperTable
     * @param Qrequest $qreq
     * @param bool $error */
    static function print_header($paperTable, $qreq, $error = false) {
        $conf = $paperTable ? $paperTable->conf : $qreq->conf();
        $prow = $paperTable ? $paperTable->prow : null;
        $format = 0;

        $t = '<header id="header-page" class="header-page-submission"><h1 class="paptitle';

        if (!$paperTable) {
            if (($pid = $qreq->paperId) && ctype_digit($pid)) {
                $title = "#$pid";
            } else {
                $title = $conf->_c("paper_title", "Submission");
            }
            $t .= '">' . $title;
        } else if (!$prow->paperId) {
            $title = $conf->_c("paper_title", "New submission");
            $t .= '">' . $title;
        } else {
            $paperTable->initialize_list();
            $title = "#" . $prow->paperId;
            $viewable_tags = $prow->viewable_tags($paperTable->user);
            if ($viewable_tags || $paperTable->user->can_view_tags($prow)) {
                $t .= ' has-tag-classes';
                if (($color = $prow->conf->tags()->color_classes($viewable_tags)))
                    $t .= ' ' . $color;
            }
            $t .= '"><a class="noq ulh" href="' . $prow->hoturl()
                . '"><span class="taghl"><span class="pnum">' . $title . '</span>'
                . ' &nbsp; ';

            $highlight_text = null;
            $title_matches = 0;
            if ($paperTable->matchPreg
                && ($highlight = $paperTable->matchPreg["ti"] ?? null)) {
                $highlight_text = Text::highlight($prow->title, $highlight, $title_matches);
            }

            if (!$title_matches && ($format = $prow->title_format())) {
                $t .= '<span class="ptitle need-format" data-format="' . $format . '">';
            } else {
                $t .= '<span class="ptitle">';
            }
            if ($highlight_text) {
                $t .= $highlight_text;
            } else if ($prow->title === "") {
                $t .= "[No title]";
            } else {
                $t .= htmlspecialchars($prow->title);
            }

            $t .= '</span></span></a>';
            if ($viewable_tags && $conf->tags()->has_decoration) {
                $tagger = new Tagger($paperTable->user);
                $t .= $tagger->unparse_decoration_html($viewable_tags);
            }
        }

        $t .= '</h1></header>';
        if ($paperTable && $prow->paperId) {
            $t .= $paperTable->_mode_nav();
        }

        $amode = $qreq->page();
        assert(in_array($amode, ["paper", "review", "assign"]));
        if ($qreq->m === "edit"
            && (!$paperTable || $paperTable->mode === "edit")) {
            $amode = "edit";
        }

        if ($amode === "paper") {
            $id = "paper-view";
        } else if ($amode === "edit") {
            $id = "paper-edit";
        } else {
            $id = $amode;
        }

        $body_class = "paper";
        if ($error) {
            $body_class .= "-error";
        }
        if ($paperTable
            && $prow->paperId
            && $paperTable->user->has_overridable_conflict($prow)
            && ($paperTable->user->overrides() & Contact::OVERRIDE_CONFLICT)) {
            $body_class .= " fold5o";
        } else {
            $body_class .= " fold5c";
        }

        $qreq->print_header($title, $id, [
            "action_bar" => QuicklinksRenderer::make($qreq, $amode),
            "title_div" => $t,
            "body_class" => $body_class,
            "paperId" => $qreq->paperId,
            "save_messages" => !$error
        ]);
        if ($format) {
            echo Ht::unstash_script("hotcrp.render_text_page()");
        }
    }

    private function initialize_list() {
        assert(!$this->qreq->has_active_list());
        $list = $this->find_session_list();
        $this->qreq->set_active_list($list);

        $this->matchPreg = [];
        if (($list = $this->qreq->active_list())
            && $list->highlight
            && preg_match('/\Ap\/([^\/]*)\/([^\/]*)(?:\/|\z)/', $list->listid, $m)) {
            $hlquery = is_string($list->highlight) ? $list->highlight : urldecode($m[2]);
            $ps = new PaperSearch($this->user, ["t" => $m[1], "q" => $hlquery]);
            $this->matchPreg = $ps->field_highlighters();
        }
        if (empty($this->matchPreg)) {
            $this->matchPreg = null;
        }
    }

    private function find_session_list() {
        $prow = $this->prow;
        if ($prow->paperId <= 0) {
            return null;
        }

        if (($list = SessionList::load_cookie($this->user, "p"))
            && ($list->set_current_id($prow->paperId) || $list->digest)) {
            return $list;
        }

        // look up list description
        $list = null;
        $listdesc = $this->qreq->ls;
        if ($listdesc) {
            if (($opt = PaperSearch::unparse_listid($listdesc))) {
                $list = $this->try_list($opt, $prow);
            }
            if (!$list && preg_match('/\A(all|s):(.*)\z/s', $listdesc, $m)) {
                $list = $this->try_list(["t" => $m[1], "q" => $m[2]], $prow);
            }
            if (!$list && preg_match('/\A[a-z]+\z/', $listdesc)) {
                $list = $this->try_list(["t" => $listdesc], $prow);
            }
            if (!$list) {
                $list = $this->try_list(["q" => $listdesc], $prow);
            }
        }

        // default lists
        if (!$list) {
            $list = $this->try_list([], $prow);
        }
        if (!$list && $this->user->privChair) {
            $list = $this->try_list(["t" => "all"], $prow);
        }

        return $list;
    }
    private function try_list($opt, $prow) {
        $srch = new PaperSearch($this->user, $opt);
        if ($srch->test($prow)) {
            $list = $srch->session_list_object();
            $list->set_current_id($prow->paperId);
            return $list;
        } else {
            return null;
        }
    }

    /** @param bool $editable
     * @param bool $useRequest
     * @suppress PhanAccessReadOnlyProperty */
    function set_edit_status(PaperStatus $status, $editable, $useRequest) {
        assert($this->mode === "edit" && !$this->edit_status);
        $this->editable = $editable;
        $this->useRequest = $useRequest;
        $this->edit_status = $status;
    }

    function set_review_values(ReviewValues $rvalues = null) {
        $this->review_values = $rvalues;
    }

    /** @param MessageItem $mi */
    function add_pre_status_feedback($mi) {
        $this->pre_status_feedback[] = $mi;
    }

    /** @return bool */
    function can_view_reviews() {
        return $this->can_view_reviews;
    }

    /** @param string $abstract
     * @return bool */
    private function abstract_foldable($abstract) {
        return strlen($abstract) > 190;
    }

    private function _print_foldpaper_div() {
        $require_folds = $this->mode === "re" || $this->mode === "assign";
        $this->allow_folds = $require_folds
            || ($this->mode === "p" && $this->can_view_reviews && !empty($this->all_rrows))
            || ($this->mode === "edit" && !$this->editable);

        // 4="t": topics, 6="b": abstract, 7: [JavaScript abstract expansion],
        // 8="a": blind authors, 9="p": full authors
        $foldstorage = [4 => "p.t", 6 => "p.b", 9 => "p.p"];
        $this->foldnumber = ["topics" => 4];

        // other expansions
        $next_foldnum = 10;
        foreach ($this->prow->page_fields() as $o) {
            if ($o->page_order() !== false
                && $o->page_order() >= 1000
                && $o->page_order() < 5000
                && ($o->id <= 0 || $this->user->allow_view_option($this->prow, $o))
                && $o->page_group !== null) {
                if (strlen($o->page_group) > 1
                    && !isset($this->foldnumber[$o->page_group])) {
                    $this->foldnumber[$o->page_group] = $next_foldnum;
                    $foldstorage[$next_foldnum] = str_replace(" ", "_", "p." . $o->page_group);
                    ++$next_foldnum;
                }
                if ($o->page_expand) {
                    $this->foldnumber[$o->formid] = $next_foldnum;
                    $foldstorage[$next_foldnum] = "p." . $o->formid;
                    ++$next_foldnum;
                }
            }
        }

        // what is folded?
        // if highlighting, automatically unfold abstract/authors
        $vas = $this->user->view_authors_state($this->prow);
        $this->foldmap = [];
        foreach ($foldstorage as $num => $k) {
            $this->foldmap[$num] = $this->allow_folds && !$this->unfold_all;
        }
        $this->foldmap[8] = $vas === 1;
        if ($this->foldmap[6]) {
            $abstract = $this->highlight($this->prow->abstract_text(), "ab", $match);
            if ($match || !$this->abstract_foldable($abstract)) {
                $this->foldmap[6] = false;
            }
        }
        if ($this->matchPreg
            && $vas !== 0
            && ($this->foldmap[8] || $this->foldmap[9])) {
            $this->highlight($this->prow->authorInformation, "au", $match);
            if ($match) {
                $this->foldmap[8] = $this->foldmap[9] = false;
            }
        }

        // collect folders
        $folders = [];
        foreach ($this->foldmap as $num => $f) {
            if ($num !== 8 || $vas === 1) {
                $folders[] = "fold" . $num . ($f ? "c" : "o");
            }
        }
        echo '<div id="foldpaper" class="', join(" ", $folders);
        if ($require_folds) {
            echo '">';
        } else {
            echo (empty($folders) ? "" : " "),
                'need-fold-storage" data-fold-storage="',
                htmlspecialchars(json_encode_browser($foldstorage)), '">';
            Ht::stash_script("hotcrp.fold_storage()");
        }
    }

    /** @param string $field
     * @return int */
    private function problem_status_at($field) {
        if ($this->edit_status) {
            return $this->edit_status->problem_status_at($field);
        } else {
            return 0;
        }
    }
    /** @param string $field
     * @param string $msg
     * @param -5|-4|-3|-2|-1|0|1|2|3 $status
     * @return MessageItem */
    function msg_at($field, $msg, $status) {
        $this->edit_status = $this->edit_status ?? new MessageSet;
        return $this->edit_status->msg_at($field, $msg, $status);
    }
    /** @param string $field
     * @return bool */
    function has_problem_at($field) {
        return $this->problem_status_at($field) > 0;
    }
    /** @param string $field
     * @return string */
    function has_error_class($field) {
        return $this->has_problem_at($field) ? " has-error" : "";
    }
    /** @param string $field
     * @return string */
    function control_class($field, $rest = "", $prefix = "has-") {
        return MessageSet::status_class($this->problem_status_at($field), $rest, $prefix);
    }
    /** @param list<string> $fields
     * @return string */
    function max_control_class($fields, $rest = "", $prefix = "has-") {
        $ps = $this->edit_status ? $this->edit_status->max_problem_status_at($fields) : 0;
        return MessageSet::status_class($ps, $rest, $prefix);
    }

    /** @param ?string $heading
     * @return void */
    function print_editable_option_papt(PaperOption $opt, $heading = null, $rest = []) {
        if (!isset($rest["for"])) {
            $for = $opt->readable_formid();
        } else {
            $for = $rest["for"] ?? false;
        }
        echo '<div class="pf pfe';
        if (!$opt->test_exists($this->prow) || ($rest["hidden"] ?? false)) {
            echo ' hidden';
        }
        if ($opt->exists_condition()) {
            echo ' want-fieldchange has-edit-condition" data-edit-condition="', htmlspecialchars(json_encode_browser($opt->exists_script_expression($this->prow)));
            Ht::stash_script('$(hotcrp.paper_edit_conditions)', 'edit_condition');
        }
        echo '"><h3 class="', $this->control_class($opt->formid, "pfehead");
        if ($for === "checkbox") {
            echo " checki";
        }
        if (($tclass = $rest["tclass"] ?? false)) {
            echo " ", ltrim($tclass);
        }
        if (($id = $rest["id"] ?? false)) {
            echo '" id="' . $id;
        }
        echo '">', Ht::label($heading ?? $this->edit_title_html($opt),
            $for === "checkbox" ? false : $for, ["class" => $opt->required ? "field-required" : ""]);
        $vis = $opt->visibility();
        if ($vis === PaperOption::VIS_ADMIN) {
            echo '<div class="field-visibility">(hidden from reviewers)</div>';
        } else if ($this->edit_show_all_visibility) {
            if ($vis === PaperOption::VIS_AUTHOR) {
                echo '<div class="field-visibility">(hidden on anonymous submissions)</div>';
            } else if ($vis === PaperOption::VIS_REVIEW) {
                echo '<div class="field-visibility">(hidden until review)</div>';
            } else if ($vis === PaperOption::VIS_CONFLICT) {
                // XXX
            }
        }
        echo '</h3>';
        $this->print_field_hint($opt, $rest["context_args"] ?? null);
        echo Ht::hidden("has_{$opt->formid}", 1);
    }

    /** @param array<string,int|string> $extra
     * @return string */
    private function papt($what, $name, $extra = []) {
        $fold = $extra["fold"] ?? false;
        $editfolder = $extra["editfolder"] ?? false;
        $foldnum = $fold || $editfolder ? $extra["foldnum"] ?? 0 : 0;
        $foldnumclass = "";
        if ($foldnum || isset($extra["foldopen"])) {
            $foldnumclass = " data-fold-target=\"{$foldnum}"
                . (isset($extra["foldopen"]) ? "o\"" : "\"");
        }

        if (($extra["type"] ?? null) === "ps") {
            list($divclass, $hdrclass) = ["pst", "psfn"];
        } else {
            list($divclass, $hdrclass) = ["pavt", "pavfn"];
        }

        $c = "<div class=\"" . $this->control_class($what, $divclass);
        if (($fold || $editfolder) && !($extra["float"] ?? false)) {
            $c .= " ui js-foldup\"" . $foldnumclass . ">";
        } else {
            $c .= "\">";
        }
        $c .= "<h3 class=\"$hdrclass";
        if (isset($extra["fnclass"])) {
            $c .= " " . $extra["fnclass"];
        }
        $c .= '">';
        if (!$fold) {
            $n = (is_array($name) ? $name[0] : $name);
            if ($editfolder) {
                $c .= "<a class=\"q fn ui js-foldup\" "
                    . "href=\"" . $this->conf->selfurl($this->qreq, ["atab" => $what])
                    . "\"" . $foldnumclass . ">" . $n
                    . '<span class="t-editor">✎ </span>'
                    . "</a><span class=\"fx\">" . $n . "</span>";
            } else {
                $c .= $n;
            }
        } else {
            '@phan-var-force int $foldnum';
            '@phan-var-force string $foldnumclass';
            $c .= '<a class="q ui js-foldup" href=""' . $foldnumclass;
            if (($title = $extra["foldtitle"] ?? false)) {
                $c .= ' title="' . $title . '"';
            }
            if (isset($this->foldmap[$foldnum])) {
                $c .= ' role="button" aria-expanded="' . ($this->foldmap[$foldnum] ? "false" : "true") . '"';
            }
            $c .= '>' . expander(null, $foldnum);
            if (!is_array($name)) {
                $name = [$name, $name];
            }
            if ($name[0] !== $name[1]) {
                $c .= '<span class="fn' . $foldnum . '">' . $name[1] . '</span><span class="fx' . $foldnum . '">' . $name[0] . '</span>';
            } else {
                $c .= $name[0];
            }
            $c .= '</a>';
        }
        $c .= "</h3>";
        if (isset($extra["float"])) {
            $c .= $extra["float"];
        }
        $c .= "</div>";
        return $c;
    }

    /** @param string $text
     * @param string $pregname
     * @param int &$n
     * @return string */
    function highlight($text, $pregname, &$n = null) {
        if ($this->matchPreg && isset($this->matchPreg[$pregname])) {
            $text = Text::highlight($text, $this->matchPreg[$pregname], $n);
        } else {
            $text = htmlspecialchars($text);
            $n = 0;
        }
        return $text;
    }

    /** @param string $field
     * @return string */
    function messages_at($field) {
        return $this->edit_status ? $this->edit_status->feedback_html_at($field) : "";
    }

    /** @param PaperOption $opt
     * @param ?list<mixed> $context_args */
    function print_field_hint($opt, $context_args = null) {
        echo $this->messages_at($opt->formid);
        $fr = new FieldRender(FieldRender::CFHTML);
        $context_args = $context_args ?? [];
        $opt->render_description($fr, ...$context_args);
        if (!$fr->is_empty()) {
            echo $fr->value_html("field-d");
        }
        echo $this->messages_at($opt->formid . ":context");
    }

    /** @param PaperOption $opt
     * @return string */
    function edit_title_html($opt) {
        $t = $opt->edit_title();
        if (str_ends_with($t, ")")
            && preg_match('/\A([^()]* +)(\([^()]+\))\z/', $t, $m)) {
            return htmlspecialchars($m[1]) . '<span class="n">' . htmlspecialchars($m[2]) . '</span>';
        } else {
            return htmlspecialchars($t);
        }
    }

    /** @param DocumentInfo $doc
     * @param array{notooltip?:bool} $options
     * @return string */
    static function pdf_stamps_html($doc, $options = null) {
        $tooltip = !$options || !($options["notooltip"] ?? null);
        $t = [];

        if ($doc->timestamp > 0) {
            $t[] = ($tooltip ? '<span class="nb need-tooltip" aria-label="Upload time">' : '<span class="nb">')
                . '<svg width="12" height="12" viewBox="0 0 96 96" class="licon"><path d="M48 6a42 42 0 1 1 0 84 42 42 0 1 1 0-84zm0 10a32 32 0 1 0 0 64 32 32 0 1 0 0-64zM48 19A5 5 0 0 0 43 24V46c0 2.352.37 4.44 1.464 5.536l12 12c4.714 4.908 12-2.36 7-7L53 46V24A5 5 0 0 0 43 24z"/></svg>'
                . " " . $doc->conf->unparse_time($doc->timestamp) . "</span>";
        }

        $ha = new HashAnalysis($doc->sha1);
        if ($ha->ok()) {
            $h = $ha->text_data();
            $x = '<span class="nb checksum';
            if ($tooltip) {
                $x .= ' need-tooltip" data-tooltip="';
                if ($ha->algorithm() === "sha256")  {
                    $x .= "SHA-256 checksum";
                } else if ($ha->algorithm() === "sha1") {
                    $x .= "SHA-1 checksum";
                }
            }
            $x .= '"><svg width="12" height="12" viewBox="0 0 48 48" class="licon"><path d="M19 32l-8-8-7 7 14 14 26-26-6-6-19 19zM15 3V10H8v5h7v7h5v-7H27V10h-7V3h-5z"/></svg> '
                . '<span class="checksum-overflow">' . $h . '</span>'
                . '<span class="checksum-abbreviation">' . substr($h, 0, 8) . '</span></span>';
            $t[] = $x;
        }

        if (!empty($t)) {
            return '<span class="hint">' . join(' <span class="barsep">·</span> ', $t) . "</span>";
        } else {
            return "";
        }
    }

    /** @param PaperOption $o */
    function render_submission(FieldRender $fr, $o) {
        assert(!$this->editable && $o->id == 0);
        $fr->title = false;
        $fr->value = "";
        $fr->value_format = 5;

        // conflicts
        if ($this->user->isPC
            && !$this->prow->has_conflict($this->user)
            && $this->mode !== "assign"
            && $this->mode !== "contact"
            && $this->prow->can_author_edit_paper()) {
            $fr->value .= Ht::msg('The authors still have <a href="' . $this->conf->hoturl("deadlines") . '">time</a> to make changes.', 1);
        }

        // download
        if ($this->user->can_view_pdf($this->prow)) {
            $dprefix = "";
            $dtype = $this->prow->finalPaperStorageId > 1 ? DTYPE_FINAL : DTYPE_SUBMISSION;
            if (($doc = $this->prow->document($dtype))
                && $doc->paperStorageId > 1) {
                if (($stamps = self::pdf_stamps_html($doc))) {
                    $stamps = '<span class="sep"></span>' . $stamps;
                }
                if ($dtype === DTYPE_FINAL) {
                    $dhtml = $this->conf->option_by_id($dtype)->title_html();
                } else {
                    $dhtml = $o->title_html($this->prow->timeSubmitted == 0);
                }
                $fr->value .= '<p class="pgsm">' . $dprefix . $doc->link_html('<span class="pavfn">' . $dhtml . '</span>', DocumentInfo::L_REQUIREFORMAT) . $stamps . '</p>';
            }
        }
    }

    /** @param bool $checkbox
     * @return bool */
    private function is_ready($checkbox) {
        if ($this->useRequest) {
            return !!$this->qreq->submitpaper
                && ($checkbox
                    || $this->conf->opt("noPapers")
                    || $this->prow->paperStorageId > 1);
        } else {
            return $this->prow->timeSubmitted > 0
                || ($checkbox
                    && $this->prow->can_update_until_deadline()
                    && (!$this->prow->paperId
                        || (!$this->conf->opt("noPapers") && $this->prow->paperStorageId <= 1)));
        }
    }

    private function print_editable_complete() {
        if ($this->allow_edit_final) {
            echo Ht::hidden("submitpaper", 1);
            return;
        }

        $can_upd = $this->prow->can_update_until_deadline();
        $upd = $can_upd ? $this->prow->update_deadline() : 0;

        $checked = $this->is_ready(true);
        $ready_open = $this->prow->paperStorageId > 1 || $this->conf->opt("noPapers");
        echo '<div class="ready-container ',
            $ready_open ? "foldo" : "foldc",
            '"><div class="checki fx"><span class="checkc">',
            Ht::checkbox("submitpaper", 1, $checked, ["disabled" => !$ready_open]),
            "</span>";

        // script.js depends on the HTML here
        $upd_html = "";
        if (Conf::$now <= $upd) {
            // can update until future deadline
            $upd_html = $this->conf->unparse_time_with_local_span($upd);
            echo Ht::label("<strong>" . $this->conf->_("The submission is ready for review") . "</strong>", null, ["class" => $checked ? null : "is-error"]),
                '<p class="feedback is-urgent-note if-unready ', $checked ? "hidden" : "",
                '">Submissions not marked ready for review by the deadline will not be considered.</p>';
        } else if ($can_upd) {
            echo Ht::label("<strong>" . $this->conf->_("The submission is ready for review") . "</strong>");
        } else {
            echo Ht::label("<strong>" . $this->conf->_("The submission is complete") . "</strong>", null, ["class" => $checked ? null : "is-error"]),
                '<p class="feedback is-urgent-note">You must complete the submission before the deadline or it will not be reviewed. Completed submissions are frozen and cannot be changed further.</p>';
        }
        echo "</div></div>\n";

        // update message
        if (Conf::$now <= $upd) {
            echo '<div class="mt-2 feedback is-note">You can update the submission until ', $upd_html, '.</div>';
        }
    }

    static function document_upload_input($inputid, $dtype, $accepts) {
        $t = '<input id="' . $inputid . '" type="file" name="' . $inputid . '"';
        if ($accepts !== null && count($accepts) == 1) {
            $t .= ' accept="' . $accepts[0]->mimetype . '"';
        }
        return $t . ' size="30" class="uich document-uploader">';
    }

    function render_abstract(FieldRender $fr, PaperOption $o) {
        $fr->title = false;
        $fr->value_format = 5;

        $html = $this->highlight($this->prow->abstract_text(), "ab", $match);
        if (trim($html) === "") {
            if ($this->conf->opt("noAbstract"))
                return;
            $html = "[No abstract]";
        }
        $extra = [];
        if ($this->allow_folds && $this->abstract_foldable($html)) {
            $extra = ["fold" => "paper", "foldnum" => 6,
                      "foldtitle" => "Toggle full abstract"];
        }
        $fr->value = '<div class="paperinfo-abstract"><div class="pg">'
            . $this->papt("abstract", $o->title_html(), $extra)
            . '<div class="pavb abstract';
        if (!$match && ($format = $this->prow->format_of($html))) {
            $fr->value .= ' need-format" data-format="' . $format . '">' . $html;
        } else {
            $fr->value .= ' format0">' . Ht::format0_html($html);
        }
        $fr->value .= "</div></div></div>";
        if ($extra) {
            $fr->value .= '<div class="fn6 fx7 longtext-fader"></div>'
                . '<div class="fn6 fx7 longtext-expander"><a class="ulh ui js-foldup" href="" role="button" aria-expanded="false" data-fold-target="6">[more]</a></div>'
                . Ht::unstash_script("hotcrp.render_text_page()");
        }
    }

    /** @param list<Author> $table
     * @param string $type
     * @param ?Contact $viewAs
     * @return string */
    private function authorData($table, $type, $viewAs = null) {
        if ($this->matchPreg && isset($this->matchPreg["au"])) {
            $highpreg = $this->matchPreg["au"];
        } else {
            $highpreg = false;
        }
        $names = [];

        if (empty($table)) {
            return "[No authors]";
        } else if ($type === "last") {
            foreach ($table as $au) {
                $n = Text::nameo($au, NAME_P|NAME_I);
                $names[] = Text::highlight($n, $highpreg);
            }
            return join(", ", $names);
        } else {
            foreach ($table as $au) {
                $n = trim(Text::highlight("$au->firstName $au->lastName", $highpreg));
                if ($au->email !== "") {
                    $s = Text::highlight($au->email, $highpreg);
                    $ehtml = htmlspecialchars($au->email);
                    $e = "&lt;<a href=\"mailto:{$ehtml}\" class=\"q\">{$s}</a>&gt;";
                } else {
                    $e = "";
                }
                $t = ($n === "" ? $e : $n);
                if ($au->affiliation !== "") {
                    $s = Text::highlight($au->affiliation, $highpreg);
                    $t .= " <span class=\"auaff\">({$s})</span>";
                }
                if ($n !== "" && $e !== "") {
                    $t .= " " . $e;
                }
                $t = trim($t);
                if ($au->email !== ""
                    && $au->contactId
                    && $viewAs !== null
                    && $viewAs->email !== $au->email
                    && $viewAs->privChair) {
                    $t .= " <a href=\""
                        . $this->conf->selfurl($this->qreq, ["actas" => $au->email])
                        . "\">" . Ht::img("viewas.png", "[Act as]", ["title" => "Act as " . Text::nameo($au, NAME_P)]) . "</a>";
                }
                $names[] = '<p class="odname">' . $t . '</p>';
            }
            return join("\n", $names);
        }
    }

    /** @return array{list<Author>,list<Author>} */
    private function _analyze_authors() {
        // clean author information
        $aulist = $this->prow->author_list();
        if (empty($aulist)) {
            return [[], []];
        }

        // find contact author information, combine with author table
        // XXX fix this, it too aggressively combines information!!!!
        $result = $this->conf->qe("select contactId, firstName, lastName, '' affiliation, email from ContactInfo where contactId?a", array_keys($this->prow->contacts()));
        $contacts = [];
        while ($result && ($row = $result->fetch_object("Author"))) {
            $match = -1;
            for ($i = 0; $match < 0 && $i < count($aulist); ++$i) {
                if (strcasecmp($aulist[$i]->email, $row->email) == 0)
                    $match = $i;
            }
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
                if ($au->email === "") {
                    $au->email = $row->email;
                }
            } else {
                $contacts[] = $au = $row;
                $au->nonauthor = true;
            }
            $au->contactId = (int) $row->contactId;
        }
        Dbl::free($result);

        usort($contacts, $this->conf->user_comparator());
        return [$aulist, $contacts];
    }

    function render_authors(FieldRender $fr, PaperOption $o) {
        $fr->title = false;
        $fr->value_format = 5;

        $vas = $this->user->view_authors_state($this->prow);
        if ($vas === 0) {
            $fr->value = '<div class="pg">'
                . $this->papt("authors", $o->title_html(0))
                . '<div class="pavb"><i>Hidden</i></div>'
                . "</div>\n\n";
            return;
        }

        // clean author information
        list($aulist, $contacts) = $this->_analyze_authors();

        // "author" or "authors"?
        $auname = $o->title_html(count($aulist));
        if ($vas === 1) {
            $auname .= " <span class=\"n\">(deanonymized)</span>";
        } else if ($this->user->act_author_view($this->prow)) {
            // Tell authors whether they are blind.
            // Accepted papers are sometimes not blind.
            if ($this->prow->outcome_sign <= 0
                || !$this->user->can_view_decision($this->prow)
                || $this->conf->setting("seedec_hideau")) {
                $sb = $this->conf->submission_blindness();
                if ($sb === Conf::BLIND_ALWAYS
                    || ($sb === Conf::BLIND_OPTIONAL && $this->prow->blind)) {
                    $auname .= " <span class=\"n\">(anonymous)</span>";
                } else if ($sb === Conf::BLIND_UNTILREVIEW) {
                    $auname .= " <span class=\"n\">(anonymous until review)</span>";
                }
            }
        }

        // header with folding
        $fr->value = '<div class="pg">'
            . '<div class="'
            . $this->control_class("authors", "pavt ui js-aufoldup")
            . '"><h3 class="pavfn">';
        if ($vas === 1 || $this->allow_folds) {
            $fr->value .= '<a class="q ui js-aufoldup" href="" title="Toggle author display" role="button" aria-expanded="' . ($this->foldmap[8] ? "false" : "true") . '">';
        }
        if ($vas === 1) {
            $fr->value .= '<span class="fn8">' . $o->title_html(0) . '</span><span class="fx8">';
        }
        if ($this->allow_folds) {
            $fr->value .= expander(null, 9);
        } else if ($vas === 1) {
            $fr->value .= expander(false);
        }
        $fr->value .= $auname;
        if ($vas === 1) {
            $fr->value .= '</span>';
        }
        if ($vas === 1 || $this->allow_folds) {
            $fr->value .= '</a>';
        }
        if ($this->admin) {
            $mailt = "s";
            if ($this->prow->timeSubmitted <= 0) {
                $mailt = "all";
            } else if ($this->prow->outcome !== 0 && $this->prow->can_author_view_decision()) {
                $dec = $this->prow->decision();
                if ($dec->category !== DecisionInfo::CAT_NONE) {
                    $mailt = $dec->category === DecisionInfo::CAT_YES ? "dec:yes" : "dec:no";
                }
            }
            $fr->value .= ' <a class="fx9 q" href="'
                . $this->conf->hoturl("mail", ["t" => $mailt, "plimit" => 1, "q" => $this->prow->paperId])
                . '">✉️</a>';
        }
        $fr->value .= '</h3></div>';

        // contents
        $fr->value .= '<div class="pavb">';
        if ($vas === 1) {
            $fr->value .= '<a class="q fn8 ui js-aufoldup" href="" title="Toggle author display">'
                . '+&nbsp;<i>Hidden</i>'
                . '</a><div class="fx8">';
        }
        if ($this->allow_folds) {
            $fr->value .= '<div class="fn9">'
                . $this->authorData($aulist, "last", null)
                . ' <a class="ui js-aufoldup" href="">[details]</a>'
                . '</div><div class="fx9">';
        }
        $fr->value .= $this->authorData($aulist, "col", $this->user);
        if ($this->allow_folds) {
            $fr->value .= '</div>';
        }
        if ($vas === 1) {
            $fr->value .= '</div>';
        }
        $fr->value .= "</div></div>\n\n";

        // contacts
        if (!empty($contacts)
            && ($this->editable
                || $this->mode !== "edit"
                || $this->prow->timeSubmitted <= 0)) {
            $contacts_option = $this->conf->option_by_id(PaperOption::CONTACTSID);
            $fr->value .= '<div class="pg fx9' . ($vas > 1 ? "" : " fx8") . '">'
                . $this->papt("contacts", $contacts_option->title_html(count($contacts)))
                . '<div class="pavb">'
                . $this->authorData($contacts, "col", $this->user)
                . "</div></div>\n\n";
        }
    }

    /** @param PaperOption $o
     * @param FieldRender $fr */
    private function clean_render($o, $fr) {
        if ($fr->title === false) {
            assert($fr->value_format === 5);
            return;
        }

        if ($fr->title === null) {
            $fr->title = $o->title();
        }

        $fr->value = $fr->value_html();
        $fr->value_format = 5;

        if ($fr->title !== "" && $o->page_group && !$fr->value_long) {
            $title = htmlspecialchars($fr->title);
            if ($fr->value === "") {
                $fr->value = "<h3 class=\"pavfn\">{$title}</h3>";
            } else if ($fr->value[0] === "<"
                       && preg_match('/\A((?:<(?:div|ul|ol|li).*?>)*)/', $fr->value, $cm)) {
                $fr->value = "{$cm[1]}<h3 class=\"pavfn pavfnsp\">{$title}:</h3> "
                    . substr($fr->value, strlen($cm[1]));
            } else {
                $fr->value = "<h3 class=\"pavfn pavfnsp\">{$title}:</h3> {$fr->value}";
            }
            $fr->value_long = false;
            $fr->title = "";
        }
    }

    /** @param list<PaperTableFieldRender> $renders
     * @param int $first
     * @param int $last
     * @param int $vos
     * @return string */
    private function _group_name_html($renders, $first, $last, $vos) {
        $group_names = [];
        $group_flags = 0;
        for ($i = $first; $i !== $last; ++$i) {
            if ($renders[$i]->view_state >= $vos) {
                $o = $renders[$i]->option;
                $group_names[] = $o->title();
                if ($o->id === -1005) {
                    $group_flags |= 1;
                } else if ($o->has_document()) {
                    $group_flags |= 2;
                } else {
                    $group_flags |= 4;
                }
            }
        }
        $group_types = [];
        if ($group_flags & 1) {
            $group_types[] = "Topics";
        }
        if ($group_flags & 2) {
            $group_types[] = "Attachments";
        }
        if ($group_flags & 4) {
            $group_types[] = "Options";
        }
        return htmlspecialchars($this->conf->_c("field_group", $renders[$first]->option->page_group, commajoin($group_names), commajoin($group_types)));
    }

    private function _print_pre_status_feedback() {
        if (($psf = MessageSet::feedback_html($this->pre_status_feedback ?? []))) {
            echo '<div class="mb-3">', $psf, '</div>';
        }
    }

    private function _print_accept_decline() {
        $rrow = $this->editrrow;
        if ($rrow->reviewId <= 0
            || $rrow->reviewType >= REVIEW_SECONDARY
            || $rrow->reviewStatus > ReviewInfo::RS_ACCEPTED
            || (!$this->user->can_administer($this->prow)
                && (!$this->user->is_my_review($rrow)
                    || !$this->user->time_review($this->prow, $rrow)))) {
            return;
        }
        $acc = $rrow->reviewStatus === ReviewInfo::RS_ACCEPTED;
        echo Ht::form(["method" => "post", "class" => ($acc ? "msg" : "msg msg-warning") . ' d-flex demargin remargin-left remargin-right']),
            '<div class="flex-grow-1 align-self-center">';
        if ($acc) {
            echo 'Thank you for confirming your intention to finish this review.';
        } else if ($rrow->requestedBy
                   && ($requester = $this->conf->user_by_id($rrow->requestedBy, USER_SLICE))) {
            echo 'Please take a moment to accept or decline ' . Text::nameo_h($requester, NAME_P) . '’s review request.';
        } else {
            echo 'Please take a moment to accept or decline our review request.';
        }
        echo '</div><div class="aabr align-self-center">';
        if ($acc) {
            echo '<div class="aabut">', Ht::submit("Decline review after all", ["class" => "btn-danger ui js-acceptish-review", "formaction" => $this->conf->hoturl("=api/declinereview", ["p" => $rrow->paperId, "r" => $rrow->reviewId, "smsg" => 1])]), '</div>';
        } else {
            echo '<div class="aabut">', Ht::submit("Decline", ["class" => "btn-danger ui js-acceptish-review", "formaction" => $this->conf->hoturl("=api/declinereview", ["p" => $rrow->paperId, "r" => $rrow->reviewId, "smsg" => 1])]), '</div>',
                '<div class="aabut">', Ht::submit("Accept", ["class" => "btn-success ui js-acceptish-review", "formaction" => $this->conf->hoturl("=api/acceptreview", ["p" => $rrow->paperId, "r" => $rrow->reviewId, "smsg" => 1])]), '</div>';
        }
        echo '</div></form>';
        if ($rrow->reviewStatus === ReviewInfo::RS_EMPTY) {
            $this->unfold_all = true;
        }
    }

    private function _print_decline_reason(Contact $capu, ReviewRefusalInfo $refusal) {
        echo Ht::form($this->conf->hoturl("=api/declinereview", ["p" => $this->prow->paperId, "r" => $refusal->refusedReviewId, "smsg" => 1]),
            ["class" => "msg msg-warning demargin remargin-left remargin-right ui-submit js-acceptish-review"]);
        echo '<p>You have declined to complete this review. Thank you for informing us.</p>',
            '<div class="f-i mt-3"><label for="declinereason">Optional explanation</label>',
            (empty($refusal->reason) ? '<div class="field-d">If you’d like, you may enter a brief explanation here.</div>' : ''),
            Ht::textarea("reason", $refusal->reason ?? "", ["rows" => 3, "cols" => 40, "spellcheck" => true, "class" => "w-text", "id" => "declinereason"]),
            '</div><div class="aab mt-3">',
            '<div class="aabut">', Ht::submit("Save explanation", ["class" => "btn-primary"]), '</div>';
        if ($this->conf->time_review($refusal->reviewRound, $refusal->refusedReviewType, true)) {
            echo '<div class="aabut">', Ht::submit("Accept review after all", ["formaction" => $this->conf->hoturl("=api/acceptreview", ["p" => $this->prow->paperId, "r" => $refusal->refusedReviewId, "smsg" => 1]), "class" => "ui js-acceptish-review"]), '</div>';
        }
        echo '</div></form>';
    }

    private function _print_normal_body() {
        // pre-status feedback
        $this->_print_pre_status_feedback();

        // review accept/decline message
        if ($this->mode === "re"
            && $this->editrrow
            && $this->editrrow->reviewStatus <= ReviewInfo::RS_ACCEPTED
            && $this->user->is_my_review($this->editrrow)) {
            $this->_print_accept_decline();
        } else if ($this->mode === "p"
                   && $this->qreq->page() === "review") {
            $capuid = $this->user->capability("@ra{$this->prow->paperId}");
            $capu = $capuid ? $this->conf->user_by_id($capuid, USER_SLICE) : $this->user;
            $refusals = $capu ? $this->prow->review_refusals_by_user($capu) : [];
            if ($refusals && $refusals[0]->refusedReviewId) {
                $this->_print_decline_reason($capu, $refusals[0]);
            } else if ($capuid) {
                echo '<div class="msg msg-warning demargin remargin-left remargin-right"><p>You have declined to complete a review. Thank you for informing us.</p></div>';
            }
        }

        $this->_print_foldpaper_div();

        // status
        list($class, $name) = $this->prow->status_class_and_name($this->user);
        echo '<p class="pgsm"><span class="pstat ', $class, '">',
            htmlspecialchars($name), "</span></p>";

        $renders = [];
        $fr = new FieldRender(FieldRender::CPAGE, $this->user);
        $fr->table = $this;
        foreach ($this->prow->page_fields() as $o) {
            if ($o->page_order() === false
                || $o->page_order() < 1000
                || $o->page_order() >= 5000
                || ($vos = $this->user->view_option_state($this->prow, $o)) === 0) {
                continue;
            }

            $fr->clear();
            $o->render($fr, $this->prow->force_option($o));
            if (!$fr->is_empty()) {
                $this->clean_render($o, $fr);
                $renders[] = new PaperTableFieldRender($o, $vos, $fr);
            }
        }

        $lasto1 = null;
        $in_paperinfo_i = false;
        for ($first = 0; $first !== count($renders); $first = $last) {
            // compute size of group
            $o1 = $renders[$first]->option;
            $last = $first + 1;
            if ($o1->page_group !== null && $this->allow_folds) {
                while ($last !== count($renders)
                       && $renders[$last]->option->page_group === $o1->page_group) {
                    ++$last;
                }
            }

            $nvos1 = 0;
            for ($i = $first; $i !== $last; ++$i) {
                if ($renders[$i]->view_state === 1) {
                    ++$nvos1;
                }
            }

            // change column
            if ($o1->page_order() >= 2000) {
                if (!$lasto1 || $lasto1->page_order() < 2000) {
                    echo '<div class="paperinfo"><div class="paperinfo-c">';
                } else if ($o1->page_order() >= 3000
                           && $lasto1->page_order() < 3000) {
                    if ($in_paperinfo_i) {
                        echo '</div>'; // paperinfo-i
                        $in_paperinfo_i = false;
                    }
                    echo '</div><div class="paperinfo-c">';
                }
                if ($o1->page_expand) {
                    if ($in_paperinfo_i) {
                        echo '</div>';
                        $in_paperinfo_i = false;
                    }
                    echo '<div class="paperinfo-i paperinfo-i-expand">';
                } else if (!$in_paperinfo_i) {
                    echo '<div class="paperinfo-i">';
                    $in_paperinfo_i = true;
                }
            }

            // echo start of group
            if ($o1->page_group !== null && $this->allow_folds) {
                if ($nvos1 === 0 || $nvos1 === $last - $first) {
                    $group_html = $this->_group_name_html($renders, $first, $last, $nvos1 === 0 ? 2 : 1);
                } else {
                    $group_html = $this->_group_name_html($renders, $first, $last, 2);
                    $gn1 = $this->_group_name_html($renders, $first, $last, 1);
                    if ($group_html !== $gn1) {
                        $group_html = "<span class=\"fn8\">{$group_html}</span><span class=\"fx8\">{$gn1}</span>";
                    }
                }

                $class = "pg";
                if ($nvos1 === $last - $first) {
                    $class .= " fx8";
                }
                $foldnum = $this->foldnumber[$o1->page_group] ?? 0;
                if ($foldnum && $renders[$first]->title !== "") {
                    $group_html = "<span class=\"fn{$foldnum}\">{$group_html}</span><span class=\"fx{$foldnum}\">" . $renders[$first]->title . '</span>';
                    $renders[$first]->title = false;
                    $renders[$first]->value = '<div class="'
                        . ($renders[$first]->value_long ? "pg" : "pgsm")
                        . ' pavb">' . $renders[$first]->value . '</div>';
                }
                echo '<div class="', $class, '">';
                if ($foldnum) {
                    echo '<div class="pavt ui js-foldup" data-fold-target="', $foldnum, '">',
                        '<h3 class="pavfn">',
                        '<a class="q ui js-foldup" href="" data-fold-target="', $foldnum, '" title="Toggle visibility" role="button" aria-expanded="',
                        $this->foldmap[$foldnum] ? "false" : "true",
                        '">', expander(null, $foldnum),
                        $group_html,
                        '</a></h3></div><div class="pg fx', $foldnum, '">';
                } else {
                    echo '<div class="pavt"><h3 class="pavfn">',
                        $group_html,
                        '</h3></div><div class="pg">';
                }
            }

            // echo contents
            for ($i = $first; $i !== $last; ++$i) {
                $x = $renders[$i];
                if ($x->value_long === false
                    || (!$x->value_long && $x->title === "")) {
                    $class = "pgsm";
                } else {
                    $class = "pg";
                }
                if ($x->value === ""
                    || ($x->title === "" && preg_match('{\A(?:[^<]|<a|<span)}', $x->value))) {
                    $class .= " outdent";
                }
                if ($x->view_state === 1) {
                    $class .= " fx8";
                }
                if ($x->title === false) {
                    echo $x->value;
                } else if ($x->title === "") {
                    echo '<div class="', $class, '">', $x->value, '</div>';
                } else if ($x->value === "") {
                    echo '<div class="', $class, '"><h3 class="pavfn">', $x->title, '</h3></div>';
                } else {
                    echo '<div class="', $class, '"><div class="pavt"><h3 class="pavfn">', $x->title, '</h3></div><div class="pavb">', $x->value, '</div></div>';
                }
            }

            // echo end of group
            if ($o1->page_group !== null && $this->allow_folds) {
                echo '</div></div>';
            }
            if ($o1->page_order() >= 2000
                && $o1->page_expand) {
                echo '</div>';
            }
            $lasto1 = $o1;
        }

        // close out display
        if ($in_paperinfo_i) {
            echo '</div>';
        }
        if ($lasto1 && $lasto1->page_order() >= 2000) {
            echo '</div></div>';
        }
        echo '</div>'; // #foldpaper
    }


    private function _papstrip_framework() {
        if (!$this->npapstrip) {
            echo '<article class="pcontainer"><div class="pcard-left',
                '"><div class="pspcard"><div class="ui pspcard-fold">',
                '<div style="float:right;margin-left:1em;cursor:pointer"><span class="psfn">More ', expander(true), '</span></div>';

            if (($viewable = $this->prow->sorted_viewable_tags($this->user))) {
                $tagger = new Tagger($this->user);
                echo '<span class="psfn">Tags:</span> ',
                    $tagger->unparse_link($viewable);
            } else {
                echo '<hr class="c">';
            }

            echo '</div><div class="pspcard-open">';
        }
        ++$this->npapstrip;
    }

    private function _papstripBegin($foldid = null, $folded = null, $extra = null) {
        $this->_papstrip_framework();
        echo '<div';
        if ($foldid) {
            echo " id=\"fold{$foldid}\"";
        }
        echo ' class="psc';
        if ($foldid) {
            echo " fold", ($folded ? "c" : "o");
        }
        if ($extra) {
            if (isset($extra["class"])) {
                echo " ", $extra["class"];
            }
            foreach ($extra as $k => $v) {
                if ($k !== "class")
                    echo "\" $k=\"", str_replace("\"", "&quot;", $v);
            }
        }
        echo '">';
    }

    private function _print_ps_collaborators() {
        if (!$this->conf->setting("sub_collab")
            || !$this->prow->collaborators
            || strcasecmp(trim($this->prow->collaborators), "None") == 0) {
            return;
        }
        $data = $this->highlight($this->prow->collaborators(), "co", $match);
        $option = $this->conf->option_by_id(PaperOption::COLLABORATORSID);
        $this->_papstripBegin("pscollab", false, ["data-fold-storage" => "p.collab", "class" => "need-fold-storage"]);
        echo Ht::unstash_script("hotcrp.fold_storage.call(\$\$(\"foldpscollab\"))"),
            $this->papt("collaborators", $option->title_html(),
                        ["type" => "ps", "fold" => "pscollab"]),
            '<ul class="fx x namelist-columns">';
        foreach (explode("\n", $data) as $line) {
            echo '<li class="od">', $line, '</li>';
        }
        echo '</ul></div>', "\n";
    }

    private function _print_ps_pc_conflicts() {
        assert(!$this->editable && $this->prow->paperId);
        $pcconf = [];
        $pcm = $this->conf->pc_members();
        foreach ($this->prow->pc_conflicts() as $id => $cflt) {
            if (Conflict::is_conflicted($cflt->conflictType)) {
                $p = $pcm[$id];
                $pcconf[$p->pc_index] = $this->user->reviewer_html_for($p);
            }
        }
        if (empty($pcconf)) {
            $pcconf[] = 'None';
        }
        ksort($pcconf);
        $option = $this->conf->option_by_id(PaperOption::PCCONFID);
        $this->_papstripBegin("pspcconf", $this->allow_folds, ["data-fold-storage" => "p.pcconf", "class" => "need-fold-storage"]);
        echo Ht::unstash_script("hotcrp.fold_storage.call(\$\$(\"foldpspcconf\"))"),
            $this->papt("pc_conflicts", $option->title_html(),
                        ["type" => "ps", "fold" => "pspcconf"]),
            '<ul class="fx x namelist-columns">';
        foreach ($pcconf as $n) {
            echo '<li class="od">', $n, '</li>';
        }
        echo '</ul></div>', "\n";
    }

    private function _papstripLeadShepherd($type, $name) {
        $editable = $type === "manager" ? $this->user->privChair : $this->admin;
        $extrev_shepherd = $type === "shepherd" && $this->conf->setting("extrev_shepherd");

        $field = $type . "ContactId";
        if ($this->prow->$field == 0 && !$editable) {
            return;
        }
        $value = $this->prow->$field;
        $id = "{$type}_{$this->prow->paperId}";

        $this->_papstripBegin($type, true, $editable ? ["class" => "ui-unfold js-unfold-pcselector js-unfold-focus need-paper-select-api"] : "");
        echo $this->papt($type, $editable ? Ht::label($name, $id) : $name,
            ["type" => "ps", "fold" => $editable ? $type : false]);
        if (!$value) {
            $n = "";
        } else if (($p = $this->conf->user_by_id($value, USER_SLICE))
                   && ($p->isPC
                       || ($extrev_shepherd && $this->prow->review_type($p) == REVIEW_EXTERNAL))) {
            $n = $this->user->reviewer_html_for($p);
        } else {
            $n = "<strong>[removed from PC]</strong>";
        }
        echo '<div class="pscopen"><p class="fn odname js-psedit-result">',
            $n, '</p></div>';

        if ($editable) {
            $this->conf->stash_hotcrp_pc($this->user);
            $selopt = "0 assignable";
            if ($type === "shepherd" && $this->conf->setting("extrev_shepherd")) {
                $selopt .= " extrev";
            }
            echo '<form class="ui-submit uin fx">',
                Ht::select($type, [], 0, ["class" => "w-99 want-focus", "data-pcselector-options" => $selopt . " selected", "data-pcselector-selected" => $value, "id" => $id]),
                '</form>';
        }

        echo "</div>\n";
    }

    private function papstripLead() {
        $this->_papstripLeadShepherd("lead", "Discussion lead");
    }

    private function papstripShepherd() {
        $this->_papstripLeadShepherd("shepherd", "Shepherd");
    }

    private function papstripManager() {
        $this->_papstripLeadShepherd("manager", "Paper administrator");
    }

    private function papstripTags() {
        if (!$this->prow->paperId || !$this->user->can_view_tags($this->prow)) {
            return;
        }

        $tags = $this->prow->all_tags_text();
        $editable = $this->user->can_edit_some_tag($this->prow);
        $is_sitewide = $editable && !$this->user->can_edit_most_tags($this->prow);
        if ($tags === "" && !$editable) {
            return;
        }

        // Note that tags MUST NOT contain HTML special characters.
        $tagger = new Tagger($this->user);
        $viewable = $this->prow->sorted_viewable_tags($this->user);

        $tx = $tagger->unparse_link($viewable);
        $unfolded = $editable && ($this->has_problem_at("tags") || $this->qreq->atab === "tags");
        $id = "tags {$this->prow->paperId}";

        $this->_papstripBegin("tags", true, $editable ? ["class" => "need-tag-form ui-unfold js-unfold-focus"] : []);

        if ($editable) {
            echo Ht::form($this->prow->hoturl(), ["data-pid" => $this->prow->paperId, "data-no-tag-report" => $unfolded ? 1 : null]);
        }

        echo $this->papt("tags", $editable ? Ht::label("Tags", $id) : "Tags",
            ["type" => "ps", "fold" => $editable ? "tags" : false, "foldopen" => true]);
        if ($editable) {
            $treport = Tags_API::tagmessages($this->user, $this->prow, null);
            $treport_warn = array_filter($treport->message_list, function ($mi) {
                return $mi->status > 0;
            });

            // uneditable
            if (empty($treport_warn)) {
                echo '<ul class="fn want-tag-report-warnings feedback-list hidden"></ul>';
            } else {
                echo '<ul class="fn want-tag-report-warnings feedback-list"><li>',
                    join("</li><li>", MessageSet::feedback_html_items($treport_warn)), "</li></ul>";
            }

            echo '<div class="fn js-tag-result">', $tx === "" ? "None" : $tx, '</div>';

            echo '<div class="fx js-tag-editor">';
            if (empty($treport->message_list)) {
                echo '<ul class="want-tag-report feedback-list hidden"></ul>';
            } else {
                echo '<ul class="want-tag-report feedback-list"><li>',
                    join("</li><li>", MessageSet::feedback_html_items($treport->message_list)), "</li></ul>";
            }
            if ($is_sitewide) {
                echo '<p class="feedback is-warning">You have a conflict with this submission, so you can only edit its ', Ht::link("site-wide tags", $this->conf->hoturl("settings", "group=tags#tag_sitewide")), '.';
                if ($this->user->allow_administer($this->prow)) {
                    echo ' ', Ht::link("Override your conflict", $this->conf->selfurl($this->qreq, ["forceShow" => 1])), ' to view and edit all tags.';
                }
                echo '</p>';
            }
            $editable_tags = $this->prow->sorted_editable_tags($this->user);
            echo '<textarea cols="20" rows="4" name="tags" class="w-99 want-focus need-suggest mf-label ',
                $is_sitewide ? "sitewide-editable-tags" : "editable-tags",
                '" spellcheck="false" id="', $id, '">',
                $tagger->unparse($editable_tags),
                '</textarea><div class="aab flex-row-reverse mt-1"><div class="aabut">',
                Ht::submit("save", "Save", ["class" => "btn-primary"]),
                '</div><div class="aabut">',
                Ht::submit("cancel", "Cancel"),
                "</div></div>",
                '<span class="hint"><a href="', $this->conf->hoturl("help", "t=tags"), '">Learn more</a> <span class="barsep">·</span> <strong>Tip:</strong> Twiddle tags like “~tag” are visible only to you.</span>',
                "</div>";
        } else {
            echo '<div class="js-tag-result">', ($tx === "" ? "None" : $tx), '</div>';
        }

        if ($editable) {
            echo "</form>";
        }
        if ($unfolded) {
            echo Ht::unstash_script('hotcrp.fold("tags",0)');
        }
        echo "</div>\n";
    }

    function papstripOutcomeSelector() {
        $id = "decision_{$this->prow->paperId}";
        $this->_papstripBegin("decision", $this->qreq->atab !== "decision", ["class" => "need-paper-select-api ui-unfold js-unfold-focus"]);
        echo $this->papt("decision", Ht::label("Decision", $id),
                ["type" => "ps", "fold" => "decision"]),
            '<form class="ui-submit uin fx">';
        if (isset($this->qreq->forceShow)) {
            echo Ht::hidden("forceShow", $this->qreq->forceShow ? 1 : 0);
        }
        $opts = [];
        foreach ($this->conf->decision_set() as $dec) {
            $opts[$dec->id] = $dec->name;
        }
        echo Ht::select("decision", $opts,
                        (string) $this->prow->outcome,
                        ["class" => "w-99 want-focus", "id" => $id]),
            '</form><p class="fn odname js-psedit-result">',
            htmlspecialchars($this->prow->decision()->name),
            "</p></div>\n";
    }

    function papstripReviewPreference() {
        $this->_papstripBegin("revpref");
        echo $this->papt("revpref", "Review preference", ["type" => "ps"]),
            "<form class=\"ui\">";
        $rp = unparse_preference($this->prow->preference($this->user));
        $rp = ($rp == "0" ? "" : $rp);
        echo "<input id=\"revprefform_d\" type=\"text\" name=\"revpref", $this->prow->paperId,
            "\" size=\"4\" value=\"$rp\" class=\"revpref want-focus want-select\">",
            "</form></div>\n";
        Ht::stash_script("hotcrp.add_preference_ajax(\"#revprefform_d\",true);hotcrp.shortcut(\"revprefform_d\").add()");
    }

    private function papstrip_tag_entry($id) {
        $this->_papstripBegin($id, !!$id, ["class" => "pste ui-unfold js-unfold-focus"]);
    }

    private function papstrip_tag_float($tag, $kind, $type) {
        if (!$this->user->can_view_tag($this->prow, $tag)) {
            return "";
        }
        $totval = $this->prow->tag_value($tag) ?? "";
        $class = "is-nonempty-tags float-right" . ($totval === "" ? " hidden" : "");
        $reverse = $type !== "rank";
        $extradiv = "";
        if (($type === "allotment" || $type === "approval")
            && $this->user->can_view_peruser_tag($this->prow, $tag)) {
            $class .= " need-tooltip";
            $extradiv = ' data-tooltip-anchor="h" data-tooltip-info="votereport" data-tag="' . htmlspecialchars($tag) . '"';
        }
        return '<div class="' . $class . '"' . $extradiv
            . '><a class="q" href="' . $this->conf->hoturl("search", ["q" => "show:#{$tag} sort:" . ($reverse ? "-" : "") . "#{$tag}"]) . '">'
            . '<span class="is-tag-index" data-tag-base="' . $tag . '">' . $totval . '</span> ' . $kind . '</a></div>';
    }

    private function papstrip_tag_entry_title($s, $tag, $value, $label) {
        $ts = "#$tag";
        if (($color = $this->conf->tags()->color_classes($tag))) {
            $ts = '<span class="' . $color . ' taghh">' . $ts . '</span>';
        }
        $s = str_replace("{{}}", $ts, $s);
        if ($value !== false) {
            $s .= '<span class="fn is-nonempty-tags'
                . ($value === "" ? " hidden" : "")
                . '">: <span class="is-tag-index" data-tag-base="~'
                . $tag . '">' . $value . '</span></span>';
        }
        return $label ? Ht::label($s, "tag:~{$tag} {$this->prow->paperId}") : $s;
    }

    private function papstrip_rank($tag) {
        $id = "rank_" . html_id_encode($tag);
        $myval = $this->prow->tag_value($this->user->contactId . "~$tag") ?? "";
        $totmark = $this->papstrip_tag_float($tag, "overall", "rank");

        $this->papstrip_tag_entry($id);
        echo Ht::form("", ["class" => "need-tag-index-form", "data-pid" => $this->prow->paperId]);
        if (isset($this->qreq->forceShow)) {
            echo Ht::hidden("forceShow", $this->qreq->forceShow);
        }
        echo $this->papt($id, $this->papstrip_tag_entry_title("{{}} rank", $tag, $myval, true),
                         ["type" => "ps", "fold" => $id, "float" => $totmark, "fnclass" => "mf"]),
            '<div class="fx">',
            Ht::entry("tagindex", $myval,
                ["size" => 4, "class" => "is-tag-index want-focus mf-label-success",
                 "data-tag-base" => "~{$tag}", "inputmode" => "decimal",
                 "id" => "tag:~{$tag} {$this->prow->paperId}"]),
            ' <span class="barsep">·</span> ',
            '<a href="', $this->conf->hoturl("search", ["q" => "editsort:#~{$tag}"]), '">Edit all</a>',
            ' <div class="hint" style="margin-top:4px"><strong>Tip:</strong> <a href="', $this->conf->hoturl("search", ["q" => "editsort:#~{$tag}"]), '">Search “editsort:#~', $tag, '”</a> to drag and drop your ranking, or <a href="', $this->conf->hoturl("offline"), '">use offline reviewing</a> to rank many papers at once.</div>',
            "</div></form></div>\n";
    }

    private function papstrip_allotment($tag, $allotment) {
        $id = "vote_" . html_id_encode($tag);
        $myval = $this->prow->tag_value($this->user->contactId . "~$tag") ?? "";
        $totmark = $this->papstrip_tag_float($tag, "total", "allotment");

        $this->papstrip_tag_entry($id);
        echo Ht::form("", ["class" => "need-tag-index-form", "data-pid" => $this->prow->paperId]);
        if (isset($this->qreq->forceShow)) {
            echo Ht::hidden("forceShow", $this->qreq->forceShow);
        }
        echo $this->papt($id, $this->papstrip_tag_entry_title("{{}} votes", $tag, $myval, true),
                         ["type" => "ps", "fold" => $id, "float" => $totmark]),
            '<div class="fx">',
            Ht::entry("tagindex", $myval,
                ["size" => 4, "class" => "is-tag-index want-focus mf-label-success",
                 "data-tag-base" => "~{$tag}", "inputmode" => "decimal",
                 "id" => "tag:~{$tag} {$this->prow->paperId}"]),
            " &nbsp;of $allotment",
            ' <span class="barsep">·</span> ',
            '<a href="', $this->conf->hoturl("search", ["q" => "editsort:-#~{$tag}"]), '">Edit all</a>',
            "</div></form></div>\n";
    }

    private function papstrip_approval($tag) {
        $id = "approval_" . html_id_encode($tag);
        $myval = $this->prow->tag_value($this->user->contactId . "~$tag") ?? "";
        $totmark = $this->papstrip_tag_float($tag, "total", "approval");

        $this->papstrip_tag_entry(null);
        echo Ht::form("", ["class" => "need-tag-index-form", "data-pid" => $this->prow->paperId]);
        if (isset($this->qreq->forceShow)) {
            echo Ht::hidden("forceShow", $this->qreq->forceShow);
        }
        echo $this->papt($id,
            $this->papstrip_tag_entry_title('<label><span class="checkc">'
                . Ht::checkbox("tagindex", "0", $myval !== "",
                    ["class" => "is-tag-index want-focus", "data-tag-base" => "~$tag"])
                . '</span>{{}} vote</label>', $tag, false, false),
                ["type" => "ps", "fnclass" => "checki", "float" => $totmark]),
            "</form></div>\n";
    }

    private function papstripWatch() {
        if ($this->prow->timeSubmitted <= 0
            || $this->user->contactId <= 0
            || ($this->prow->has_conflict($this->user)
                && !$this->prow->has_author($this->user)
                && !$this->user->is_admin_force())) {
            return;
        }

        $this->_papstripBegin();

        echo '<form class="ui-submit uin">',
            $this->papt("watch",
                '<label><span class="checkc">'
                . Ht::checkbox("follow", 1, $this->user->following_reviews($this->prow), ["class" => "uich js-follow-change"])
                . '</span>Email notification</label>',
                ["type" => "ps", "fnclass" => "checki"]),
            '<div class="pshint">Select to receive email on updates to reviews and comments.</div>',
            "</form></div>\n";
    }


    // Functions for editing

    /** @param string $dname
     * @param string $noun
     * @return string */
    function deadline_setting_is($dname, $noun = "deadline") {
        return $this->deadline_is($this->conf->setting($dname) ?? 0, $noun);
    }

    /** @param int $t
     * @param string $noun
     * @return string */
    function deadline_is($t, $noun = "deadline") {
        if ($t <= 0) {
            return "";
        }
        $ts = $this->conf->unparse_time_with_local_span($t);
        return Conf::$now < $t ? " The {$noun} is {$ts}." : " The {$noun} was {$ts}.";
    }

    private function _deadline_override_message() {
        if ($this->admin) {
            return " As an administrator, you can make changes anyway.";
        } else {
            return $this->_forceShow_message();
        }
    }
    private function _forceShow_message() {
        if (!$this->admin && $this->allow_admin) {
            return " " . Ht::link("(Override your conflict)", $this->conf->selfurl($this->qreq, ["forceShow" => 1]), ["class" => "nw"]);
        } else {
            return "";
        }
    }
    /** @param string $m
     * @param int $status */
    private function _main_message($m, $status) {
        $this->edit_status->msg_at(":main", $m, $status);
    }

    private function _edit_message_new_paper_deadline() {
        $opent = $this->prow->open_time();
        if ($opent <= 0 || $opent > Conf::$now) {
            $msg = "<5>The site is not open for submissions." . $this->_deadline_override_message();
        } else {
            $msg = '<5>The <a href="' . $this->conf->hoturl("deadlines") . '">deadline</a> for registering submissions has passed.' . $this->deadline_is($this->prow->registration_deadline()) . $this->_deadline_override_message();
        }
        $this->_main_message($msg, $this->admin ? 1 : 2);
    }

    private function _edit_message_new_paper() {
        if ($this->admin || $this->conf->time_start_paper()) {
            $t = [$this->conf->_("Enter information about your submission.")];
            $reg_dl = $this->prow->registration_deadline();
            $upd_dl = $this->prow->update_deadline();
            if ($reg_dl > 0 && $upd_dl > 0 && $reg_dl < $upd_dl) {
                $t[] = $this->conf->_("Submissions must be registered by %s and completed by %s.", $this->conf->unparse_time_long($reg_dl), $this->conf->unparse_time_long($this->prow->submission_deadline()));
                if (!$this->conf->opt("noPapers")) {
                    $t[] = $this->conf->_("PDF upload is not required to register.");
                }
            } else if ($upd_dl > 0) {
                $t[] = $this->conf->_("Submissions must be completed by %s.", $this->conf->unparse_time_long($upd_dl));
            }
            $this->_main_message("<5>" . join(" ", $t), 0);
            if (($v = $this->conf->_id("submit", ""))) {
                if (!Ftext::is_ftext($v)) {
                    $v = "<5>$v";
                }
                $this->_main_message($v, 0);
            }
        }
        if (!$this->conf->time_start_paper()) {
            $this->_edit_message_new_paper_deadline();
            $this->quit = $this->quit || !$this->admin;
        }
    }

    private function _edit_message_for_author() {
        $viewable_decision = $this->prow->viewable_decision($this->user);
        if ($viewable_decision->sign < 0) {
            $this->_main_message("<5>This submission was not accepted." . $this->_forceShow_message(), 1);
        } else if ($this->prow->timeWithdrawn > 0) {
            if ($this->user->can_revive_paper($this->prow)) {
                $this->_main_message("<5>This submission has been withdrawn, but you can still revive it." . $this->deadline_is($this->prow->update_deadline()), 1);
            } else {
                $this->_main_message("<5>This submission has been withdrawn." . $this->_forceShow_message(), 1);
            }
        } else if ($this->prow->timeSubmitted <= 0) {
            $whyNot = $this->user->perm_edit_paper($this->prow);
            $sub_dl = $this->prow->submission_deadline();
            if (!$whyNot) {
                if (($missing = PaperTable::missing_required_fields($this->prow))) {
                    $first = $this->conf->_("<5>This submission is not ready for review. Required fields %#s are missing.", PaperTable::field_title_links($missing, "missing_title"));
                } else {
                    $first = $this->conf->_("<5>This submission is marked as not ready for review.");
                    $first = "<strong>" . Ftext::unparse_as($first, 5) . "</strong>";
                }
                if (($upd_dl = $this->prow->update_deadline()) > 0) {
                    $rest = $this->conf->_("Submissions incomplete as of %s will not be considered.", $this->conf->unparse_time_long($upd_dl));
                } else {
                    $rest = $this->conf->_("Incomplete submissions will not be considered.");
                }
                $this->_main_message("<5>{$first} {$rest}", MessageSet::URGENT_NOTE);
            } else if (isset($whyNot["updateSubmitted"])
                       && $this->user->can_finalize_paper($this->prow)) {
                $this->_main_message('<5>This submission is not ready for review. Although you cannot make further changes, the current version can be still be submitted for review.' . $this->deadline_is($sub_dl) . $this->_deadline_override_message(), 1);
            } else if (isset($whyNot["deadline"])) {
                if ($this->conf->time_between(null, $sub_dl, $this->prow->submission_grace()) > 0) {
                    $this->_main_message('<5>The site is not open for updates at the moment.' . $this->_deadline_override_message(), 1);
                } else {
                    $this->_main_message('<5>The <a href="' . $this->conf->hoturl("deadlines") . '">submission deadline</a> has passed and this submission will not be reviewed.' . $this->deadline_is($sub_dl) . $this->_deadline_override_message(), 1);
                }
            } else {
                $this->_main_message('<5>This submission is not ready for review and can’t be changed further. It will not be reviewed.' . $this->_deadline_override_message(), MessageSet::URGENT_NOTE);
            }
        } else if ($this->conf->allow_final_versions()
                   && $viewable_decision->sign > 0) {
            if ($this->user->can_edit_final_paper($this->prow)) {
                if (($t = $this->conf->_id("finalsubmit", "", new FmtArg("deadline", $this->deadline_setting_is("final_soft"))))) {
                    $this->_main_message("<5>" . $t, MessageSet::SUCCESS);
                }
            } else if ($this->mode === "edit") {
                $this->_main_message("<5>The deadline for updating final versions has passed. You can still change contact information." . $this->_deadline_override_message(), 1);
            }
        } else if ($this->user->can_edit_paper($this->prow)) {
            if ($this->mode === "edit"
                && (!$this->edit_status || !$this->edit_status->has_error())) {
                $this->_main_message('<5>This submission is ready for review. You do not need to take further action, but you can still make changes if you wish.' . $this->deadline_is($this->prow->update_deadline(), "submission deadline"), MessageSet::SUCCESS);
            }
        } else if ($this->mode === "edit") {
            if ($this->user->can_withdraw_paper($this->prow, true)) {
                $t = "<5>This submission is under review and can’t be changed, but you can change its contacts or withdraw it from consideration.";
            } else {
                $t = "<5>This submission is under review and can’t be changed or withdrawn, but you can change its contacts.";
            }
            $this->_main_message($t . $this->_deadline_override_message(), MessageSet::MARKED_NOTE);
        }
    }

    /** @param iterable<PaperOption> $fields
     * @param 'title'|'edit_title'|'missing_title' $title_method
     * @return list<string> */
    static function field_title_links($fields, $title_method) {
        $x = [];
        foreach ($fields as $o) {
            $x[] = Ht::link(htmlspecialchars($o->$title_method()), "#" . $o->readable_formid());
        }
        return $x;
    }

    /** @return list<PaperOption> */
    static function missing_required_fields(PaperInfo $prow) {
        $missing = [];
        foreach ($prow->form_fields() as $o) {
            if ($o->test_required($prow) && !$o->value_present($prow->force_option($o)))
                $missing[] = $o;
        }
        return $missing;
    }

    private function _edit_message_existing_paper() {
        $has_author = $this->prow->has_author($this->user);
        if ($has_author) {
            $this->_edit_message_for_author();
        } else if ($this->conf->allow_final_versions()
                   && $this->prow->outcome_sign > 0
                   && !$this->prow->can_author_view_decision()) {
            $this->_main_message("<5>The submission has been accepted, but its authors can’t see that yet. Once decisions are visible, the system will allow accepted authors to upload final versions.", 1);
        } else {
            $this->_main_message("<5>You aren’t a contact for this submission, but as an administrator you can still make changes.", MessageSet::MARKED_NOTE);
        }
        if ($this->user->call_with_overrides($this->user->overrides() | Contact::OVERRIDE_TIME, "can_edit_paper", $this->prow)
            && ($v = $this->conf->_id("submit", ""))) {
            if (!Ftext::is_ftext($v)) {
                $v = "<5>$v";
            }
            $this->_main_message($v, 0);
        }
        if ($this->edit_status->has_problem()
            && ($this->edit_status->has_problem_at("contacts") || $this->editable)) {
            $fields = array_filter($this->edit_fields ?? [], function ($o) {
                return $this->edit_status->has_problem_at($o->formid);
            });
            if (!empty($fields)) {
                $this->_main_message($this->conf->_c("paper_edit", "<5>Please check %s before completing the submission.", commajoin(self::field_title_links($fields, "edit_title"))), $this->edit_status->problem_status());
            }
        }
    }

    private function _print_edit_messages($include_required) {
        if (!$this->prow->paperId) {
            $this->_edit_message_new_paper();
        } else {
            $this->_edit_message_existing_paper();
        }
        if ($include_required && !$this->quit) {
            foreach ($this->edit_fields as $e) {
                if ($e->required) {
                    $this->_main_message('<5><span class="field-required-explanation">* Required</span>', 0);
                    break;
                }
            }
        }
        if (($t = $this->messages_at(":main")) !== "") {
            echo '<div class="pge">', $t, '</div>';
        }
    }

    private function _save_name() {
        if (!$this->is_ready(false)) {
            return "Save draft";
        } else if ($this->prow->timeSubmitted > 0) {
            return "Save and resubmit";
        } else {
            return "Save and submit";
        }
    }

    private function _collect_actions() {
        // Withdrawn papers can be revived
        if ($this->prow->timeWithdrawn > 0) {
            $revivable = $this->conf->time_finalize_paper($this->prow);
            if ($revivable) {
                return [Ht::submit("revive", "Revive submission", ["class" => "btn-primary"])];
            } else if ($this->admin) {
                return [[Ht::button("Revive submission", ["class" => "ui js-override-deadlines", "data-override-text" => 'The <a href="' . $this->conf->hoturl("deadlines") . '">deadline</a> for reviving withdrawn submissions has passed. Are you sure you want to override it?', "data-override-submit" => "revive"]), "(admin only)"]];
            } else {
                return [];
            }
        }

        $buttons = [];
        $want_override = false;

        if ($this->mode === "edit") {
            // check whether we can save
            $old_overrides = $this->user->set_overrides(Contact::OVERRIDE_CHECK_TIME);
            if ($this->allow_edit_final) {
                $whyNot = $this->user->perm_edit_final_paper($this->prow);
            } else if ($this->prow->paperId) {
                $whyNot = $this->user->perm_edit_paper($this->prow);
            } else {
                $whyNot = $this->user->perm_start_paper();
            }
            $this->user->set_overrides($old_overrides);
            // produce button
            $save_name = $this->_save_name();
            if (!$whyNot) {
                $buttons[] = [Ht::submit("update", $save_name, ["class" => "btn-primary btn-savepaper uic js-mark-submit"]), ""];
            } else if ($this->admin) {
                $revWhyNot = $whyNot->filter(["deadline", "rejected"]);
                $x = $revWhyNot->unparse_html() . " Are you sure you want to override the deadline?";
                $buttons[] = [Ht::button($save_name, ["class" => "btn-primary btn-savepaper ui js-override-deadlines", "data-override-text" => $x, "data-override-submit" => "update"]), "(admin only)"];
            } else if (isset($whyNot["updateSubmitted"])
                       && $this->user->can_finalize_paper($this->prow)) {
                $buttons[] = Ht::submit("update", $save_name, ["class" => "btn-savepaper uic js-mark-submit"]);
            } else if ($this->prow->paperId) {
                $buttons[] = Ht::submit("updatecontacts", "Save contacts", ["class" => "btn-savepaper btn-primary uic js-mark-submit", "data-contacts-only" => 1]);
            }
            if (!empty($buttons)) {
                $buttons[] = Ht::submit("cancel", "Cancel", ["class" => "uic js-mark-submit"]);
                $buttons[] = "";
            }
            $want_override = $whyNot && !$this->admin;
        }

        // withdraw button
        if (!$this->prow->paperId
            || !$this->user->call_with_overrides($this->user->overrides() | Contact::OVERRIDE_TIME, "can_withdraw_paper", $this->prow, true)) {
            $b = null;
        } else if ($this->prow->timeSubmitted <= 0) {
            $b = Ht::submit("withdraw", "Withdraw", ["class" => "uic js-mark-submit"]);
        } else {
            $args = ["class" => "ui js-withdraw"];
            if ($this->user->can_withdraw_paper($this->prow, !$this->admin)) {
                $args["data-withdrawable"] = "true";
            }
            if (($this->admin && !$this->prow->has_author($this->user))
                || $this->conf->time_finalize_paper($this->prow)) {
                $args["data-revivable"] = "true";
            }
            $b = Ht::button("Withdraw", $args);
        }
        if ($b) {
            if ($this->admin && !$this->user->can_withdraw_paper($this->prow)) {
                $b = [$b, "(admin only)"];
            }
            $buttons[] = $b;
        }

        // override conflict button
        if ($want_override && !$this->admin && false) {
            if ($this->allow_admin) {
                $buttons[] = "";
                $buttons[] = [Ht::submit("updateoverride", "Override conflict", ["class" => "uic js-mark-submit"]), "(admin only)"];
            } else if ($this->user->privChair) {
                $buttons[] = "";
                $buttons[] = Ht::submit("updateoverride", "Override conflict", ["disabled" => true, "class" => "need-tooltip uic js-mark-submit", "title" => "You cannot override your conflict because this paper has an administrator."]);
            }
        }

        return $buttons;
    }

    private function print_actions() {
        if ($this->admin) {
            $v = (string) $this->qreq->emailNote;
            echo '<div class="checki"><label><span class="checkc">', Ht::checkbox("doemail", 1, true, ["class" => "ignore-diff"]), "</span>",
                "Email authors, including:</label> ",
                Ht::entry("emailNote", $v, ["size" => 30, "placeholder" => "Optional explanation", "class" => "ignore-diff js-autosubmit", "aria-label" => "Explanation for update"]),
                "</div>";
        }
        if ($this->mode === "edit" && $this->allow_edit_final) {
            echo Ht::hidden("submitfinal", 1);
        }

        $buttons = $this->_collect_actions();
        if ($this->admin && $this->prow->paperId) {
            $buttons[] = [Ht::button("Delete", ["class" => "ui js-delete-paper"]), "(admin only)"];
        }
        echo Ht::actions($buttons, ["class" => "aab aabig"]);
    }


    // Functions for overall paper table viewing

    function _papstrip() {
        if (($this->prow->managerContactId > 0
             || ($this->user->privChair && $this->mode === "assign"))
            && $this->user->can_view_manager($this->prow)) {
            $this->papstripManager();
        }
        $this->papstripTags();
        foreach ($this->conf->tags() as $ltag => $t) {
            if ($this->user->can_edit_tag($this->prow, "~$ltag", null, 0)) {
                if ($t->approval) {
                    $this->papstrip_approval($t->tag);
                } else if ($t->allotment) {
                    $this->papstrip_allotment($t->tag, $t->allotment);
                } else if ($t->rank) {
                    $this->papstrip_rank($t->tag);
                }
            }
        }
        $this->papstripWatch();
        if ($this->user->can_view_conflicts($this->prow) && !$this->editable) {
            $this->_print_ps_pc_conflicts();
        }
        if ($this->user->allow_view_authors($this->prow) && !$this->editable) {
            $this->_print_ps_collaborators();
        }
        if ($this->user->can_set_decision($this->prow)) {
            $this->papstripOutcomeSelector();
        }
        if ($this->user->can_view_lead($this->prow)) {
            $this->papstripLead();
        }
        if ($this->user->can_view_shepherd($this->prow)) {
            $this->papstripShepherd();
        }
        if ($this->user->can_edit_preference_for($this->user, $this->prow, true)
            && $this->conf->timePCReviewPreferences()
            && ($this->user->roles & (Contact::ROLE_PC | Contact::ROLE_CHAIR))) {
            $this->papstripReviewPreference();
        }
    }

    /** @param string $text
     * @param string $imgfile
     * @param string $url
     * @param bool $active
     * @param bool $nondisabled
     * @return string */
    private function _mode_nav_link($text, $imgfile, $url, $active, $nondisabled) {
        $class1 = $active ? " active" : "";
        $hl = $active ? " class=\"x\"" : "";
        $img = Ht::img($imgfile, "[{$text}]", "papmodeimg");
        if ($nondisabled) {
            return "<li class=\"papmode{$class1}\"><a href=\"{$url}\" class=\"noul\">{$img}&nbsp;<u{$hl}>{$text}</u></a></li>";
        } else {
            return "<li class=\"papmode{$class1}\"><a href=\"{$url}\" class=\"noul dim ui js-confirm-override-conflict\">{$img}&nbsp;<u class=\"x\">{$text}</u></a></li>";
        }
    }

    /** @return string */
    private function _mode_nav() {
        $tx = [];
        if (($allow = $this->allow_edit()) || $this->allow_admin) {
            $arg = ["m" => "edit", "p" => $this->prow->paperId];
            if (!$allow
                && $this->mode !== "edit"
                && !$this->user->can_edit_paper($this->prow)
                && !$this->prow->has_author($this->user)) {
                $arg["forceShow"] = 1;
            }
            $tx[] = $this->_mode_nav_link(
                "Edit", "edit48.png", $this->conf->hoturl("paper", $arg),
                $this->mode === "edit", $allow || isset($arg["forceShow"])
            );
        }
        if (($allow = $this->allow_review()) || $this->allow_admin) {
            $arg = ["p" => $this->prow->paperId];
            if (!$allow) {
                $arg["forceShow"] = 1;
            }
            $tx[] = $this->_mode_nav_link(
                "Review", "review48.png", $this->conf->hoturl("review", $arg),
                $this->mode === "re" && (!$this->editrrow || $this->user->is_my_review($this->editrrow)), $allow
            );
        }
        if (($allow = $this->allow_assign()) || $this->allow_admin) {
            $name = $this->allow_admin ? "Assign" : "Invite";
            $arg = ["p" => $this->prow->paperId];
            if (!$allow) {
                $arg["forceShow"] = 1;
            }
            $tx[] = $this->_mode_nav_link(
                $name, "assign48.png", $this->conf->hoturl("assign", $arg),
                $this->mode === "assign", $allow
            );
        }
        if (!empty($tx)
            || $this->qreq->page() !== "paper"
            || ($this->mode !== "p" && $this->prow->paperId > 0)) {
            array_unshift($tx, $this->_mode_nav_link(
                "Main", "view48.png",
                $this->prow->hoturl(["m" => $this->paper_page_prefers_edit_mode() ? "main" : null]),
                $this->mode === "p" && $this->qreq->page() === "paper", true
            ));
        }
        if (!empty($tx)) {
            return '<nav class="submission-modes"><ul>' . join("", $tx) . '</ul></nav>';
        } else {
            return "";
        }
    }

    static private function _print_clickthrough($ctype) {
        $data = Conf::$main->_id("clickthrough_{$ctype}", "");
        $buttons = [Ht::submit("Agree", ["class" => "btnbig btn-success ui js-clickthrough"])];
        echo Ht::form("", ["class" => "ui"]), '<div>', $data,
            Ht::hidden("clickthrough_type", $ctype),
            Ht::hidden("clickthrough_id", sha1($data)),
            Ht::hidden("clickthrough_time", Conf::$now),
            Ht::actions($buttons, ["class" => "aab aabig aabr"]), "</div></form>";
    }

    static function print_review_clickthrough() {
        echo '<div class="pcard revcard js-clickthrough-terms"><div class="revcard-head"><h2>Reviewing terms</h2></div><div class="revcard-body">', Ht::msg("You must agree to these terms before you can save reviews.", 2);
        self::_print_clickthrough("review");
        echo "</div></div>";
    }

    private function _print_editable_form() {
        $form_url = [
            "p" => $this->prow->paperId ? : "new", "m" => "edit"
        ];
        // This is normally added automatically, but isn't for new papers
        if ($this->user->is_admin_force()) {
            $form_url["forceShow"] = 1;
        }
        $form_js = [
            "id" => "form-paper",
            "class" => "need-unload-protection uich ui-submit js-submit-paper",
            "data-alert-toggle" => "paper-alert"
        ];
        if ($this->prow->timeSubmitted > 0) {
            $form_js["data-submitted"] = $this->prow->timeSubmitted;
        }
        if ($this->prow->paperId && !$this->editable) {
            $form_js["data-contacts-only"] = 1;
        }
        if ($this->useRequest) {
            $form_js["class"] .= " alert";
        }
        echo Ht::form($this->conf->hoturl("=paper", $form_url), $form_js);
        Ht::stash_script('$(hotcrp.load_editable_paper)');
    }

    private function _print_editable_body() {
        $this->_print_editable_form();
        $overrides = $this->user->add_overrides(Contact::OVERRIDE_EDIT_CONDITIONS);
        echo '<div class="pedcard-head"><h2><span class="pedcard-header-name">',
            $this->conf->_($this->prow->paperId ? "Edit Submission" : "New Submission"),
            '</span></h2></div>';

        $this->edit_fields = array_values(array_filter(
            $this->prow->form_fields(),
            function ($o) {
                return $this->user->can_edit_option($this->prow, $o);
            }
        ));

        $this->_print_pre_status_feedback();
        $this->_print_edit_messages(true);

        if (!$this->quit) {
            foreach ($this->edit_fields as $o) {
                $ov = $reqov = $this->prow->force_option($o);
                if ($this->useRequest
                    && $this->qreq["has_{$o->formid}"]
                    && ($x = $o->parse_qreq($this->prow, $this->qreq))) {
                    $reqov = $x;
                }
                $o->print_web_edit($this, $ov, $reqov);
            }

            // Submit button
            $this->print_editable_complete();
            $this->print_actions();
        }

        echo "</div></form>";
        $this->user->set_overrides($overrides);
    }

    function print_paper_info() {
        if ($this->prow->paperId) {
            $this->_papstrip();
        }
        if ($this->npapstrip) {
            Ht::stash_script("hotcrp.prepare_editable_paper()");
            echo '</div></div><nav class="pslcard-nav">';
        } else {
            echo '<article class="pcontainer"><div class="pcard-left pcard-left-nostrip"><nav class="pslcard-nav">';
        }
        $viewable_tags = $this->prow->viewable_tags($this->user);
        echo '<h4 class="pslcard-home">';
        if ($viewable_tags || $this->user->can_view_tags($this->prow)) {
            $color = $this->prow->conf->tags()->color_classes($viewable_tags);
            echo '<span class="pslcard-home-tag has-tag-classes taghh',
                ($color ? " $color" : ""), '">';
            $close = '</span>';
        } else {
            $close = '';
        }
        echo '<a href="#top" class="q"><span class="header-site-name">',
            htmlspecialchars($this->conf->short_name), '</span> ';
        if ($this->prow->paperId <= 0) {
            echo "new submission";
        } else if ($this->mode !== "re") {
            echo "#", $this->prow->paperId;
        } else if ($this->editrrow && $this->editrrow->reviewOrdinal) {
            echo "#", $this->editrrow->unparse_ordinal_id();
        } else {
            echo "#", $this->prow->paperId, " review";
        }
        echo '</a>', $close, '</h4><ul class="pslcard"></ul></nav></div>';

        if ($this->allow_admin && $this->prow->paperId > 0) {
            if (!$this->admin) {
                echo '<div class="pcard notecard override-conflict off"><p class="sd">',
                    '<a class="noul" href="', $this->conf->selfurl($this->qreq, ["forceShow" => 1]), '">',
                    '🔒&nbsp;<u>Override conflict</u></a> for administrator view</p></div>';
            } else if ($this->user->is_admin_force()
                       && $this->prow->has_conflict($this->user)) {
                $unprivurl = $this->mode === "assign"
                    ? $this->conf->hoturl("paper", ["p" => $this->prow->paperId, "forceShow" => null])
                    : $this->conf->selfurl($this->qreq, ["forceShow" => null]);
                echo '<div class="pcard notecard override-conflict on"><p class="sd">',
                    '🔓 You are using administrator privilege to override your conflict with this submission. ',
                    '<a class="noul ibw" href="', $unprivurl, '"><u>Unprivileged view</u></a>',
                    '</p></div>';
            }
        }

        echo '<div class="pcard papcard">';
        $this->conf->report_saved_messages();
        if ($this->editable && !$this->user->can_clickthrough("submit")) {
            echo '<div id="foldpaper js-clickthrough-container">',
                '<div class="js-clickthrough-terms">',
                '<h2>Submission terms</h2>',
                Ht::msg("You must agree to these terms to register a submission.", 2);
            self::_print_clickthrough("submit");
            echo '</div><div class="need-clickthrough-show hidden">';
            $this->_print_editable_body();
            echo '</div></div>';
        } else if ($this->editable) {
            echo '<div id="foldpaper">';
            $this->_print_editable_body();
            echo '</div>';
        } else {
            $this->_print_normal_body();
        }
        echo '</div>';

        if (!$this->editable && $this->mode === "edit") {
            echo '<div class="pcard papcard">',
                '<div class="pedcard-head"><h2><span class="pedcard-header-name">Edit Contacts</span></h2></div>';
            $this->_print_edit_messages(false);
            $this->_print_editable_form();
            $o = $this->conf->option_by_id(PaperOption::CONTACTSID);
            assert($o instanceof Contacts_PaperOption);
            $ov = $reqov = $this->prow->force_option($o);
            if ($this->useRequest
                && $this->qreq["has_{$o->formid}"]
                && ($x = $o->parse_qreq($this->prow, $this->qreq))) {
                $reqov = $x;
            }
            $o->print_web_edit($this, $ov, $reqov);
            $this->print_actions();
            echo '</form></div>';
        }

        if (!$this->editable
            && $this->mode !== "edit"
            && $this->user->act_author_view($this->prow)
            && !$this->user->contactId) {
            echo '<div class="pcard notecard"><p class="sd">',
                "To edit this submission, <a href=\"", $this->conf->hoturl("signin"), "\">sign in using your email and password</a>.",
                '</p></div>';
        }

        Ht::stash_script("hotcrp.shortcut().add()");
    }

    private function _paptabSepContaining($t) {
        if ($t !== "") {
            echo '<div class="pcard notecard"><p class="sd">', $t, '</p></div>';
        }
    }

    /** @param ReviewInfo $rr
     * @return string */
    private function _review_table_actas($rr) {
        if (!$rr->contactId || $rr->contactId === $this->user->contactId) {
            return "";
        } else {
            return ' <a href="' . $this->conf->selfurl($this->qreq, ["actas" => $rr->email]) . '">'
                . Ht::img("viewas.png", "[Act as]", ["title" => "Act as " . Text::nameo($rr, NAME_P)])
                . "</a>";
        }
    }

    /** @return string */
    function review_table() {
        $user = $this->user;
        $prow = $this->prow;
        $conf = $prow->conf;
        $subrev = [];
        $cflttype = $user->view_conflict_type($prow);
        $allow_actas = $user->privChair && $user->allow_administer($prow);
        $hideUnviewable = ($cflttype > 0 && !$this->admin)
            || (!$user->act_pc($prow) && !$conf->setting("extrev_view"));
        $show_ratings = $user->can_view_review_ratings($prow);
        $want_scores = !in_array($this->mode, ["assign", "edit", "re"]);
        $want_requested_by = false;
        $score_header = array_map(function ($x) { return ""; },
                                  $conf->review_form()->forder);
        $last_pc_reviewer = -1;

        // actual rows
        foreach ($this->all_rrows as $rr) {
            $want_my_scores = $want_scores;
            if ($user->is_owned_review($rr) && $this->mode === "re") {
                $want_my_scores = true;
            }
            $canView = $user->can_view_review($prow, $rr);

            // skip unsubmitted reviews;
            // assign page lists actionable reviews separately
            if (!$canView && $hideUnviewable) {
                $last_pc_reviewer = -1;
                continue;
            }

            $tclass = "";
            $isdelegate = $rr->is_subreview() && $rr->requestedBy === $last_pc_reviewer;
            if ($rr->reviewStatus < ReviewInfo::RS_COMPLETED && $isdelegate) {
                $tclass .= ($tclass ? " " : "") . "rldraft";
            }
            if ($rr->reviewType >= REVIEW_PC) {
                $last_pc_reviewer = +$rr->contactId;
            }

            // review ID
            $id = $rr->subject_to_approval() ? "Subreview" : "Review";
            if ($rr->reviewOrdinal && !$isdelegate) {
                $id .= " #" . $rr->unparse_ordinal_id();
            }
            if ($rr->reviewStatus < ReviewInfo::RS_ADOPTED) {
                $d = $rr->status_description();
                if ($d === "draft") {
                    $id = "Draft " . $id;
                } else {
                    $id .= " (" . $d . ")";
                }
            }
            $rlink = $rr->unparse_ordinal_id();

            $t = '<td class="rl nw">';
            if (!$canView
               || ($rr->reviewStatus < ReviewInfo::RS_DRAFTED && !$user->can_edit_review($prow, $rr))) {
                $t .= $id;
            } else {
                if ((!$this->can_view_reviews
                     || $rr->reviewStatus < ReviewInfo::RS_ADOPTED)
                    && $user->can_edit_review($prow, $rr)) {
                    $link = $prow->reviewurl(["r" => $rlink]);
                } else if ($this->qreq->page() === "paper") {
                    $link = "#r$rlink";
                } else {
                    $link = $prow->hoturl(["#" => "r$rlink"]);
                }
                $t .= '<a href="' . $link . '">' . $id . '</a>';
                if ($show_ratings
                    && $user->can_view_review_ratings($prow, $rr)
                    && ($ratings = $rr->ratings())) {
                    $all = 0;
                    foreach ($ratings as $r) {
                        $all |= $r;
                    }
                    if ($all & 126) {
                        $t .= " &#x2691;";
                    } else if ($all & 1) {
                        $t .= " &#x2690;";
                    }
                }
            }
            $t .= '</td>';

            // primary/secondary glyph
            $rtype = "";
            if ($rr->reviewType > 0 && $user->can_view_review_meta($prow, $rr)) {
                $rtype = $rr->icon_h() . $rr->round_h();
            }

            // reviewer identity
            $showtoken = $rr->reviewToken && $user->can_edit_review($prow, $rr);
            if (!$user->can_view_review_identity($prow, $rr)) {
                $t .= ($rtype ? "<td class=\"rl\">{$rtype}</td>" : '<td></td>');
            } else {
                if (!$showtoken || !Contact::is_anonymous_email($rr->email)) {
                    $n = $user->reviewer_html_for($rr);
                } else {
                    $n = "[Token " . encode_token((int) $rr->reviewToken) . "]";
                }
                if ($allow_actas) {
                    $n .= $this->_review_table_actas($rr);
                }
                $t .= '<td class="rl"><span class="taghl" title="'
                    . $rr->email . '">' . $n . '</span>'
                    . ($rtype ? " $rtype" : "") . "</td>";
            }

            // requester
            if ($this->mode === "assign") {
                if ($rr->reviewType < REVIEW_SECONDARY
                    && !$showtoken
                    && $rr->requestedBy
                    && $rr->requestedBy !== $rr->contactId
                    && $user->can_view_review_requester($prow, $rr)) {
                    $t .= '<td class="rl small">requested by ';
                    if ($rr->requestedBy === $user->contactId) {
                        $t .= "you";
                    } else {
                        $t .= $user->reviewer_html_for($rr->requestedBy);
                    }
                    $t .= '</td>';
                    $want_requested_by = true;
                } else {
                    $t .= '<td></td>';
                }
            }

            // scores
            $scores = [];
            if ($want_my_scores && $canView) {
                $view_score = $user->view_score_bound($prow, $rr);
                foreach ($conf->review_form()->forder as $f) {
                    if ($f instanceof Score_ReviewField
                        && $f->view_score > $view_score
                        && $rr->has_nonempty_field($f)) {
                        if ($score_header[$f->short_id] === "") {
                            $score_header[$f->short_id] = '<th class="rlscore">' . $f->web_abbreviation() . "</th>";
                        }
                        $scores[$f->short_id] = '<td class="rlscore need-tooltip" data-rf="' . $f->uid() . '" data-tooltip-info="rf-score">'
                            . $f->value_unparse($rr->fields[$f->order], ReviewField::VALUE_SC)
                            . '</td>';
                    }
                }
            }

            // affix
            $subrev[] = [$tclass, $t, $scores];
        }

        // completion
        if (!empty($subrev)) {
            if ($want_requested_by) {
                array_unshift($score_header, '<th class="rl"></th>');
            }
            $score_header_text = join("", $score_header);
            $t = "<div class=\"reinfotable-container demargin\"><div class=\"reinfotable remargin-left remargin-right relative\"><table class=\"reviewers nw";
            if ($score_header_text) {
                $t .= " has-scores";
            }
            $t .= "\">";
            $nscores = 0;
            if ($score_header_text) {
                foreach ($score_header as $x) {
                    $nscores += $x !== "" ? 1 : 0;
                }
                $t .= '<thead><tr><th colspan="2"></th>';
                if ($this->mode === "assign" && !$want_requested_by) {
                    $t .= '<th></th>';
                }
                $t .= $score_header_text . "</tr></thead>";
            }
            $t .= '<tbody>';
            foreach ($subrev as $r) {
                $t .= '<tr class="rl' . ($r[0] ? " $r[0]" : "") . '">' . $r[1];
                if ($r[2] ?? null) {
                    foreach ($score_header as $fid => $header_needed) {
                        if ($header_needed !== "") {
                            $x = $r[2][$fid] ?? null;
                            $t .= $x ? : "<td class=\"rlscore rs_$fid\"></td>";
                        }
                    }
                } else if ($nscores > 0) {
                    $t .= '<td colspan="' . $nscores . '"></td>';
                }
                $t .= "</tr>";
            }
            return $t . "</tbody></table></div></div>\n";
        } else {
            return "";
        }
    }

    /** @return string */
    private function _review_links() {
        $prow = $this->prow;
        $cflttype = $this->user->view_conflict_type($prow);
        $any_comments = false;

        $nvisible = 0;
        $myrr = null;
        foreach ($this->all_rrows as $rr) {
            if ($this->user->can_view_review($prow, $rr)) {
                $nvisible++;
            }
            if ($rr->contactId == $this->user->contactId
                || (!$myrr && $this->user->is_my_review($rr))) {
                $myrr = $rr;
            }
        }

        // comments
        $pret = "";
        if ($this->mycrows
            && $this->mode !== "edit") {
            $tagger = new Tagger($this->user);
            $viewable_crows = [];
            foreach ($this->mycrows as $cr) {
                if ($this->user->can_view_comment($cr->prow, $cr)) {
                    $viewable_crows[] = $cr;
                }
            }
            $cxs = CommentInfo::group_by_identity($viewable_crows, $this->user, true);
            if (!empty($cxs)) {
                $count = array_reduce($cxs, function ($n, $cx) { return $n + $cx[1]; }, 0);
                $cnames = array_map(function ($cx) {
                    $tclass = "cmtlink";
                    if (($tags = $cx[0]->viewable_tags($this->user))
                        && ($color = $cx[0]->conf->tags()->color_classes($tags))) {
                        $tclass .= " $color taghh";
                    }
                    $cid = $cx[0]->unparse_html_id();
                    return "<span class=\"nb\"><a class=\"{$tclass} track\" href=\"#{$cid}\">"
                        . $cx[0]->unparse_commenter_html($this->user)
                        . "</a>"
                        . ($cx[1] > 1 ? " ({$cx[1]})" : "")
                        . $cx[2] . "</span>";
                }, $cxs);
                $first_cid = $cxs[0][0]->unparse_html_id();
                $pret = '<div class="revnotes"><a class="track" href="#' . $first_cid . '"><strong>'
                    . plural($count, "Comment") . '</strong></a>: '
                    . join(" ", $cnames) . '</div>';
                $any_comments = true;
            }
        }

        $t = [];
        $dlimgjs = ["class" => "dlimg", "width" => 24, "height" => 24];

        // see all reviews
        $this->allreviewslink = false;
        if (($nvisible > 1 || ($nvisible > 0 && !$myrr))
            && $this->mode !== "p") {
            $this->allreviewslink = true;
            $t[] = '<a href="' . $prow->hoturl() . '" class="noul revlink">'
                . Ht::img("view48.png", "[All reviews]", $dlimgjs) . "&nbsp;<u>All reviews</u></a>";
        }

        // edit paper
        if ($this->mode !== "edit"
            && $prow->has_author($this->user)
            && !$this->user->can_administer($prow)) {
            $t[] = '<a href="' . $prow->hoturl(["m" => "edit"]) . '" class="noul revlink">'
                . Ht::img("edit48.png", "[Edit]", $dlimgjs) . "&nbsp;<u><strong>Edit submission</strong></u></a>";
        }

        // edit review
        if ($this->mode === "re"
            || ($this->mode === "assign" && !empty($t))
            || !$prow) {
            /* no link */;
        } else if ($myrr) {
            $a = '<a href="' . $prow->reviewurl(["r" => $myrr->unparse_ordinal_id()]) . '" class="noul revlink">';
            if ($this->user->can_edit_review($prow, $myrr)) {
                $x = $a . Ht::img("review48.png", "[Edit review]", $dlimgjs) . "&nbsp;<u><b>Edit your review</b></u></a>";
            } else {
                $x = $a . Ht::img("review48.png", "[Your review]", $dlimgjs) . "&nbsp;<u><b>Your review</b></u></a>";
            }
            $t[] = $x;
        } else if ($this->user->can_edit_some_review($prow)) {
            $t[] = '<a href="' . $prow->reviewurl(["m" => "re"]) . '" class="noul revlink">'
                . Ht::img("review48.png", "[Write review]", $dlimgjs) . "&nbsp;<u><b>Write review</b></u></a>";
        }

        // review assignments
        if ($this->mode !== "assign"
            && $this->mode !== "edit"
            && $this->user->can_request_review($prow, null, true)) {
            $t[] = '<a href="' . $this->conf->hoturl("assign", "p=$prow->paperId") . '" class="noul revlink">'
                . Ht::img("assign48.png", "[Assign]", $dlimgjs) . "&nbsp;<u>" . ($this->admin ? "Assign reviews" : "External reviews") . "</u></a>";
        }

        // new comment
        $nocmt = in_array($this->mode, ["assign", "contact", "edit", "re"]);
        if (!$this->allreviewslink
            && !$nocmt
            && $this->user->add_comment_state($prow) !== 0) {
            $img = Ht::img("comment48.png", "[Add comment]", $dlimgjs);
            $t[] = "<a class=\"uic js-edit-comment noul revlink\" href=\"#cnew\">{$img} <u>Add comment</u></a>";
            $any_comments = true;
        }

        // new response
        if (!$nocmt
            && ($prow->has_author($this->user) || $this->allow_admin)
            && $this->conf->any_response_open) {
            foreach ($this->conf->response_rounds() as $rrd) {
                $cr = $this->response_by_id($rrd->id)
                    ?? CommentInfo::make_response_template($rrd, $prow);
                if ($this->user->can_edit_response($prow, $cr)) {
                    if ($cr->commentId) {
                        $what = $cr->commentType & CommentInfo::CT_DRAFT ? "Edit draft" : "Edit";
                    } else {
                        $what = "Add";
                    }
                    $title_prefix = $rrd->unnamed ? "" : "{$rrd->name} ";
                    $img = Ht::img("comment48.png", "[{$what} response]", $dlimgjs);
                    $uk = $cflttype >= CONFLICT_AUTHOR ? ' class="font-weight-bold"' : '';
                    $cid = $cr->unparse_html_id();
                    $t[] = "<a class=\"uic js-edit-comment noul revlink\" href=\"#{$cid}\">{$img} <u{$uk}>{$what} {$title_prefix}response</u></a>";
                    $any_comments = true;
                }
            }
        }

        // override conflict
        if ($this->user->privChair && !$this->allow_admin) {
            $t[] = '<span class="revlink">You can’t override your conflict because this submission has an administrator.</span>';
        }

        $aut = "";
        if ($prow->has_author($this->user)) {
            if ($prow->author_by_email($this->user->email)) {
                $aut = $this->conf->_('You are an <span class="author">author</span> of this submission.');
            } else {
                $aut = $this->conf->_('You are a <span class="author">contact</span> for this submission.');
            }
        } else if ($prow->has_conflict($this->user)) {
            $aut = $this->conf->_('You have a <span class="conflict">conflict</span> with this submission.');
        }
        return $pret
            . ($aut ? "<p class=\"sd\">{$aut}</p>" : "")
            . ($any_comments ? CommentInfo::script($prow) : "")
            . (empty($t) ? "" : '<p class="sd">' . join("", $t) . '</p>');
    }

    private function _review_overview_card($rtable, $ifempty, $msgs) {
        $t = "";
        if ($rtable) {
            $t .= $this->review_table();
        }
        $t .= $this->_review_links();
        if (($empty = ($t === ""))) {
            $t = $ifempty;
        }
        if ($msgs) {
            $t .= join("", $msgs);
        }
        if ($t) {
            echo '<div class="pcard notecard">', $t, '</div>';
        }
        return $empty;
    }

    private function include_comments() {
        return !$this->allreviewslink
            && (!empty($this->mycrows)
                || $this->user->add_comment_state($this->prow) !== 0
                || $this->conf->any_response_open);
    }

    function paptabEndWithReviewsAndComments() {
        if ($this->prow->managerContactId === $this->user->contactXid
            && !$this->user->privChair) {
            $this->_paptabSepContaining("You are this submission’s administrator.");
        }

        // text format link
        $m = $viewable = [];
        foreach ($this->viewable_rrows as $rr) {
            if ($rr->reviewStatus >= ReviewInfo::RS_DRAFTED) {
                $viewable[] = "reviews";
                break;
            }
        }
        foreach ($this->crows as $cr) {
            if ($this->user->can_view_comment($this->prow, $cr)) {
                $viewable[] = "comments";
                break;
            }
        }
        if (!empty($viewable)) {
            $m[] = '<p class="sd mt-5"><a href="' . $this->prow->reviewurl(["m" => "r", "text" => 1]) . '" class="noul">'
                . Ht::img("txt24.png", "[Text]", "dlimg")
                . "&nbsp;<u>" . ucfirst(join(" and ", $viewable))
                . " in plain text</u></a></p>";
        }

        if (!$this->_review_overview_card(true, '<p class="sd">There are no reviews or comments for you to view.</p>', $m)) {
            $this->print_rc($this->viewable_rrows, $this->include_comments());
        }
    }

    /** @param int $respround
     * @return ?CommentInfo */
    private function response_by_id($respround) {
        foreach ($this->mycrows as $cr) {
            if (($cr->commentType & CommentInfo::CT_RESPONSE)
                && $cr->commentRound == $respround)
                return $cr;
        }
        return null;
    }

    /** @param list<ReviewInfo> $rrows
     * @param bool $comments */
    function print_rc($rrows, $comments) {
        $rcs = [];
        $any_submitted = false;
        foreach ($rrows as $rrow) {
            if ($rrow->reviewStatus >= ReviewInfo::RS_DRAFTED) {
                $rcs[] = $rrow;
                $any_submitted = $any_submitted || $rrow->reviewStatus >= ReviewInfo::RS_COMPLETED;
            }
        }
        if ($comments && $this->mycrows) {
            $rcs = $this->prow->merge_reviews_and_comments($rcs, $this->mycrows);
        }

        $s = "";
        $ncmt = 0;
        $rf = $this->conf->review_form();
        foreach ($rcs as $rc) {
            if (isset($rc->reviewId)) {
                $rcj = $rf->unparse_review_json($this->user, $this->prow, $rc);
                if (($any_submitted || $rc->reviewStatus === ReviewInfo::RS_ADOPTED)
                    && $rc->reviewStatus < ReviewInfo::RS_COMPLETED
                    && !$this->user->is_my_review($rc)) {
                    $rcj->folded = true;
                }
                $s .= "hotcrp.add_review(" . json_encode_browser($rcj) . ");\n";
            } else {
                ++$ncmt;
                $rcj = $rc->unparse_json($this->user);
                $s .= "hotcrp.add_comment(" . json_encode_browser($rcj) . ");\n";
            }
        }

        if ($comments) {
            $cs = [];
            if (($crow = CommentInfo::make_new_template($this->user, $this->prow))
                && $crow->commentType !== 0) {
                $cs[] = $crow;
            }
            if ($this->admin || $this->prow->has_author($this->user)) {
                foreach ($this->conf->response_rounds() as $rrd) {
                    if (!$this->response_by_id($rrd->id)
                        && $rrd->relevant($this->user, $this->prow)) {
                        $crow = CommentInfo::make_response_template($rrd, $this->prow);
                        if ($this->user->can_edit_response($this->prow, $crow)) {
                            $cs[] = $crow;
                        }
                    }
                }
            }
            foreach ($cs as $crow) {
                ++$ncmt;
                $s .= "hotcrp.add_comment(" . json_encode_browser($crow->unparse_json($this->user)) . ");\n";
            }
        }

        if ($ncmt) {
            CommentInfo::print_script($this->prow);
        }
        if ($s !== "") {
            echo Ht::unstash_script($s);
        }
    }

    function print_comments() {
        $this->print_rc([], $this->include_comments());
    }

    function paptabEndWithoutReviews() {
        echo "</div></div>\n";
    }

    function paptabEndWithReviewMessage() {
        assert(!$this->editable);

        $m = [];
        if ($this->all_rrows
            && ($whyNot = $this->user->perm_view_review($this->prow, null))) {
            $m[] = "<p class=\"sd\">You can’t see the reviews for this submission. " . $whyNot->unparse_html() . "</p>";
        }
        if (!$this->conf->time_review_open()
            && $this->prow->review_type($this->user)) {
            if ($this->rrow) {
                $m[] = "<p class=\"sd\">You can’t edit your review because the site is not open for reviewing.</p>";
            } else {
                $m[] = "<p class=\"sd\">You can’t begin your assigned review because the site is not open for reviewing.</p>";
            }
        }

        $this->_review_overview_card($this->user->can_view_review_assignment($this->prow, null), "", $m);
    }

    /** @param bool $editable
     * @return bool */
    private function _mark_review_messages($editable, ReviewInfo $rrow) {
        if (($this->user->is_owned_review($rrow) || $this->admin)
            && !$this->conf->time_review($rrow->reviewRound, $rrow->reviewType, true)) {
            if ($this->conf->time_review_open()) {
                $t = 'The <a href="' . $this->conf->hoturl("deadlines") . '">review deadline</a> has passed, so the review can no longer be changed.';
            } else {
                $t = "The site is not open for reviewing, so the review cannot be changed.";
            }
            if (!$this->admin) {
                $rrow->message_list[] = new MessageItem(null, $t, MessageSet::URGENT_NOTE);
                return false;
            } else {
                $rrow->message_list[] = new MessageItem(null, "{$t} As an administrator, you can override this deadline.", MessageSet::WARNING);
            }
        } else if (!$this->user->can_edit_review($this->prow, $rrow)) {
            return false;
        }

        // administrator?
        if (!$this->user->is_my_review($rrow)) {
            if ($this->user->is_owned_review($rrow)) {
                $rrow->message_list[] = new MessageItem(null, "This isn’t your review, but you can make changes since you requested it.", MessageSet::MARKED_NOTE);
            } else if ($this->admin) {
                $rrow->message_list[] = new MessageItem(null, "This isn’t your review, but as an administrator you can still make changes.", MessageSet::MARKED_NOTE);
            }
        }

        // delegate?
        if (!$rrow->reviewSubmitted
            && $rrow->contactId == $this->user->contactId
            && $rrow->reviewType == REVIEW_SECONDARY
            && $this->conf->ext_subreviews < 3) {
            $ndelegated = 0;
            $napproval = 0;
            foreach ($this->prow->all_reviews() as $rr) {
                if ($rr->reviewType === REVIEW_EXTERNAL
                    && $rr->requestedBy === $rrow->contactId) {
                    ++$ndelegated;
                    if ($rr->reviewStatus === ReviewInfo::RS_DELIVERED) {
                        ++$napproval;
                    }
                }
            }

            if ($ndelegated == 0) {
                $t = "As a secondary reviewer, you can <a href=\"" . $this->conf->hoturl("assign", "p=$rrow->paperId") . "\">delegate this review to an external reviewer</a>, but if your external reviewer declines to review the paper, you should complete this review yourself.";
            } else if ($rrow->reviewNeedsSubmit == 0) {
                $t = "A delegated external reviewer has submitted their review, but you can still complete your own if you’d like.";
            } else if ($napproval) {
                $t = "A delegated external reviewer has submitted their review for approval. If you approve that review, you won’t need to submit your own.";
            } else {
                $t = "Your delegated external reviewer has not yet submitted a review.  If they do not, you should complete this review yourself.";
            }
            $rrow->message_list[] = new MessageItem(null, $t, MessageSet::MARKED_NOTE);
        }

        return $editable;
    }

    function print_review_form() {
        $editable = $this->mode === "re";
        if ($this->editrrow) {
            $editable = $this->_mark_review_messages($editable, $this->editrrow);
        }
        if ($editable) {
            if (!$this->user->can_clickthrough("review", $this->prow)) {
                self::print_review_clickthrough();
            }
            $this->conf->review_form()->print_form($this->prow, $this->editrrow, $this->user, $this->review_values);
        } else {
            $this->print_rc([$this->editrrow], false);
        }
    }

    function print_main_link() {
        // intended for pages like review editing where we need a link back
        $title = count($this->viewable_rrows) > 1 ? "All reviews" : "Main";
        echo '<div class="pcard notecard"><p class="sd">',
            '<a href="', $this->prow->hoturl(["m" => $this->paper_page_prefers_edit_mode() ? "main" : null]), '" class="noul revlink">',
            Ht::img("view48.png", "[{$title}]", ["class" => "dlimg", "width" => 24, "height" => 24]) . "&nbsp;<u>{$title}</u></a>",
            "</p></div>\n";
    }


    /** @param bool $want_review
     * @suppress PhanAccessReadOnlyProperty */
    function resolve_review($want_review) {
        $this->prow->ensure_full_reviews();
        $this->all_rrows = $this->prow->reviews_as_display();
        $this->viewable_rrows = [];
        $rf = $this->conf->review_form();
        $unresolved_fields = $rf->all_fields();
        foreach ($this->all_rrows as $rrow) {
            if ($this->user->can_view_review($this->prow, $rrow)) {
                $this->viewable_rrows[] = $rrow;
                if (!empty($unresolved_fields)) {
                    $bound = $this->user->view_score_bound($this->prow, $rrow);
                    $this_resolved_fields = [];
                    foreach ($unresolved_fields as $f) {
                        if ($f->view_score > $bound && $rrow->has_nonempty_field($f))
                            $this_resolved_fields[] = $f;
                    }
                    foreach ($this_resolved_fields as $f) {
                        unset($unresolved_fields[$f->short_id]);
                    }
                }
            }
        }
        $fj = [];
        foreach (array_diff_key($rf->all_fields(), $unresolved_fields) as $f) {
            $fj[] = $f->unparse_json(ReviewField::UJ_EXPORT);
        }
        Ht::stash_script("hotcrp.set_review_form(" . json_encode_browser($fj) . ")");

        $this->rrow = $this->prow->review_by_ordinal_id((string) $this->qreq->reviewId);

        $myrrow = $approvable_rrow = null;
        foreach ($this->viewable_rrows as $rrow) {
            if ($rrow->contactId === $this->user->contactId
                || (!$myrrow && $this->user->is_my_review($rrow))) {
                $myrrow = $rrow;
            }
            if (($rrow->requestedBy === $this->user->contactId || $this->admin)
                && $rrow->reviewStatus === ReviewInfo::RS_DELIVERED
                && !$approvable_rrow) {
                $approvable_rrow = $rrow;
            }
        }

        if ($this->rrow) {
            $this->editrrow = $this->rrow;
        } else if (!$approvable_rrow
                   || ($myrrow
                       && $myrrow->reviewStatus !== 0
                       && !$this->prefer_approvable)) {
            $this->editrrow = $myrrow;
        } else {
            $this->editrrow = $approvable_rrow;
        }

        if ($want_review
            && ($this->editrrow
                ? $this->user->can_edit_review($this->prow, $this->editrrow, false)
                : $this->user->can_create_review($this->prow))) {
            $this->mode = "re";
        }

        // fix mode
        if ($this->mode === "re"
            && $this->rrow
            && !$this->user->can_edit_review($this->prow, $this->rrow, false)
            && ($this->rrow->contactId != $this->user->contactId
                || $this->rrow->reviewStatus >= ReviewInfo::RS_COMPLETED)) {
            $this->mode = "p";
        }
        if ($this->mode === "p"
            && $this->rrow
            && !$this->user->can_view_review($this->prow, $this->rrow)) {
            $this->rrow = $this->editrrow = null;
        }
        if ($this->mode === "p"
            && $this->prow->paperId
            && empty($this->viewable_rrows)
            && empty($this->mycrows)
            && !$this->allow_admin
            && $this->qreq->page() === "paper"
            && ($this->allow_admin || $this->allow_edit())
            && ($this->conf->time_finalize_paper($this->prow)
                || $this->prow->timeSubmitted <= 0)) {
            $this->mode = "edit";
        }
    }

    function resolve_comments() {
        $this->crows = $this->prow->all_comments();
        $this->mycrows = $this->prow->viewable_comments($this->user, true);
    }

    /** @return list<ReviewInfo> */
    function all_reviews() {
        return $this->all_rrows;
    }
}

class PaperTableFieldRender {
    /** @var PaperOption */
    public $option;
    /** @var int */
    public $view_state;
    public $title;
    public $value;
    /** @var ?bool */
    public $value_long;

    /** @param PaperOption $option */
    function __construct($option, $view_state, FieldRender $fr) {
        $this->option = $option;
        $this->view_state = $view_state;
        $this->title = $fr->title;
        $this->value = $fr->value;
        $this->value_long = $fr->value_long;
    }
}
