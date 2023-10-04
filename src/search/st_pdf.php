<?php
// search/st_pdf.php -- HotCRP helper class for searching for papers
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class PaperPDF_SearchTerm extends SearchTerm {
    /** @var Contact */
    private $user;
    /** @var int */
    private $dtype;
    /** @var bool */
    private $present;
    /** @var ?bool */
    private $format_problem;
    private $format_errf;
    /** @var PaperSearch */
    private $srch;
    /** @var ?CheckFormat */
    private $cf;
    private $cf_warn = false;

    function __construct(PaperSearch $srch, $dtype, $present,
                         $format_problem = null, $format_errf = null) {
        assert($format_problem === null || $present === true);
        parent::__construct("pdf");
        $this->user = $srch->user;
        $this->dtype = $dtype;
        $this->present = $present;
        $this->format_problem = $format_problem;
        $this->format_errf = $format_errf;
        $this->srch = $srch;
        if ($this->format_problem !== null) {
            $this->cf = new CheckFormat($srch->conf, CheckFormat::RUN_IF_NECESSARY_TIMEOUT);
        }
        assert($this->present || $this->format_problem === null);
    }
    static function parse($word, SearchWord $sword, PaperSearch $srch) {
        if ($sword->kwdef->final === null) {
            $dtype = null;
        } else {
            $dtype = $sword->kwdef->final ? DTYPE_FINAL : DTYPE_SUBMISSION;
        }
        $lword = strtolower($word);
        if ($lword === "any" || $lword === "yes") {
            return new PaperPDF_SearchTerm($srch, $dtype, true);
        } else if ($lword === "none" || $lword === "no") {
            return new PaperPDF_SearchTerm($srch, $dtype, false);
        }
        $cf = new CheckFormat($srch->conf);
        $errf = $cf->known_fields($dtype ?? DTYPE_SUBMISSION);
        if ($dtype === null && empty($errf)) {
            $errf = $cf->known_fields(DTYPE_FINAL);
        }
        if (empty($errf)) {
            $srch->lwarning($sword, "<0>Format checking is not enabled");
            return null;
        } else if ($lword === "good" || $lword === "ok") {
            return new PaperPDF_SearchTerm($srch, $dtype, true, false);
        } else if ($lword === "bad" || $lword === "problem") {
            return new PaperPDF_SearchTerm($srch, $dtype, true, true);
        } else if (in_array($lword, $errf) || $lword === "error") {
            return new PaperPDF_SearchTerm($srch, $dtype, true, true, $lword);
        } else {
            $srch->lwarning($sword, "<0>Format error not found");
            return null;
        }
    }
    static function add_columns(SearchQueryInfo $sqi) {
        $sqi->add_column("paperStorageId", "Paper.paperStorageId");
        $sqi->add_column("finalPaperStorageId", "Paper.finalPaperStorageId");
        $sqi->add_column("mimetype", "Paper.mimetype");
        $sqi->add_column("sha1", "Paper.sha1");
        $sqi->add_column("pdfFormatStatus", "Paper.pdfFormatStatus");
    }
    function sqlexpr(SearchQueryInfo $sqi) {
        if ($this->format_problem !== null) {
            $this->add_columns($sqi);
        } else {
            if ($this->dtype === DTYPE_SUBMISSION || $this->dtype === null) {
                $sqi->add_column("paperStorageId", "Paper.paperStorageId");
            }
            if ($this->dtype === DTYPE_FINAL || $this->dtype === null) {
                $sqi->add_column("finalPaperStorageId", "Paper.finalPaperStorageId");
            }
        }
        $f = [];
        if ($this->dtype === DTYPE_SUBMISSION || $this->dtype === null) {
            $f[] = "Paper.paperStorageId" . ($this->present ? ">1" : "<=1");
        }
        if ($this->dtype === DTYPE_FINAL || $this->dtype === null) {
            $f[] = "Paper.finalPaperStorageId" . ($this->present ? ">1" : "<=1");
        }
        return "(" . join($this->present ? " or " : " and ", $f) . ")";
    }
    function is_sqlexpr_precise() {
        return $this->dtype === DTYPE_SUBMISSION && $this->format_problem === null;
    }
    function test(PaperInfo $row, $xinfo) {
        $dtype = $this->dtype;
        if ($dtype === null) {
            if ($row->finalPaperStorageId > 1
                && $this->user->can_view_decision($row)) {
                $dtype = DTYPE_FINAL;
            } else {
                $dtype = DTYPE_SUBMISSION;
            }
        } else if ($dtype === DTYPE_FINAL
                   && !$this->user->can_view_decision($row)) {
            return false;
        }
        $sub = $dtype === DTYPE_FINAL ? $row->finalPaperStorageId : $row->paperStorageId;
        if ($sub > 1 && !$this->user->can_view_pdf($row)) {
            $sub = 0;
        }
        if (($sub > 1) !== $this->present) {
            return false;
        }
        if ($this->format_problem !== null) {
            $doc = $row->document($dtype, 0, true);
            if (!$doc || $doc->mimetype !== "application/pdf") {
                return false;
            }
            $this->cf->check_document($doc);
            if ($this->cf->need_recheck()) {
                if (!$this->cf_warn) {
                    $this->srch->warning("<0>I haven’t finished analyzing the submitted PDFs. You may want to reload this page later for more precise results.");
                    $this->cf_warn = true;
                }
                return true;
            }
            $errf = $this->cf->problem_fields();
            if (empty($errf) === $this->format_problem
                || ($this->format_errf && !in_array($this->format_errf, $errf))) {
                return false;
            }
        }
        return true;
    }
}

class Pages_SearchTerm extends SearchTerm {
    /** @var Contact */
    private $user;
    /** @var CountMatcher */
    private $cm;
    /** @var PaperSearch */
    private $srch;
    /** @var CheckFormat */
    private $cf;
    /** @var bool */
    private $cf_warn = false;

    function __construct(PaperSearch $srch, CountMatcher $cm) {
        parent::__construct("pages");
        $this->user = $srch->user;
        $this->cm = $cm;
        $this->srch = $srch;
        $this->cf = new CheckFormat($srch->conf, CheckFormat::RUN_IF_NECESSARY_TIMEOUT);
    }
    static function parse($word, SearchWord $sword, PaperSearch $srch) {
        $cm = new CountMatcher($word);
        if ($cm->ok()) {
            return new Pages_SearchTerm($srch, new CountMatcher($word));
        } else {
            $srch->lwarning($sword, "<0>Page number comparison expected");
            return null;
        }
    }
    function sqlexpr(SearchQueryInfo $sqi) {
        PaperPDF_SearchTerm::add_columns($sqi);
        return "true";
    }
    function test(PaperInfo $row, $xinfo) {
        $dtype = DTYPE_SUBMISSION;
        if ($this->user->can_view_decision($row)
            && $row->outcome > 0
            && $row->finalPaperStorageId > 1) {
            $dtype = DTYPE_FINAL;
        }
        $doc = $row->document($dtype);
        if (!$doc || $doc->mimetype !== "application/pdf") {
            return false;
        }
        $np = $doc->npages($this->cf);
        if ($np !== null) {
            return $this->cm->test($np);
        } else if ($this->cf->need_recheck()) {
            if (!$this->cf_warn) {
                $this->srch->warning("<0>Submitted PDFs have not been fully analyzed");
                $this->srch->msg_at(null, "<0>Reloading this page later may give more precise results.", MessageSet::INFORM);
                $this->cf_warn = true;
            }
            return true;
        } else {
            return false;
        }
    }
    function about() {
        return self::ABOUT_PAPER;
    }
}
