<?php
// search/st_pdf.php -- HotCRP helper class for searching for papers
// Copyright (c) 2006-2017 Eddie Kohler; see LICENSE.

class PaperPDF_SearchTerm extends SearchTerm {
    private $dtype;
    private $fieldname;
    private $present;
    private $format;
    private $format_errf;
    private $cf;

    function __construct($dtype, $present, $format = null, $format_errf = null) {
        parent::__construct("pdf");
        $this->dtype = $dtype;
        $this->fieldname = ($dtype == DTYPE_FINAL ? "finalPaperStorageId" : "paperStorageId");
        $this->present = $present;
        $this->format = $format;
        $this->format_errf = $format_errf;
        if ($this->format !== null)
            $this->cf = new CheckFormat(CheckFormat::RUN_PREFER_NO);
    }
    static function parse($word, SearchWord $sword, PaperSearch $srch) {
        $dtype = $sword->kwdef->final ? DTYPE_FINAL : DTYPE_SUBMISSION;
        $lword = strtolower($word);
        if ($lword === "any" || $lword === "yes")
            return new PaperPDF_SearchTerm($dtype, true);
        else if ($lword === "none" || $lword === "no")
            return new PaperPDF_SearchTerm($dtype, false);
        $cf = new CheckFormat;
        $errf = $cf->spec_error_kinds($dtype, $srch->conf);
        if (empty($errf)) {
            $srch->warn("“" . htmlspecialchars($sword->keyword . ":" . $word) . "”: Format checking is not enabled.");
            return null;
        } else if ($lword === "good" || $lword === "ok")
            return new PaperPDF_SearchTerm($dtype, true, true);
        else if ($lword === "bad")
            return new PaperPDF_SearchTerm($dtype, true, false);
        else if (in_array($lword, $errf) || $lword === "error")
            return new PaperPDF_SearchTerm($dtype, true, false, $lword);
        else {
            $srch->warn("“" . htmlspecialchars($word) . "” is not a valid error type for format checking.");
            return null;
        }
    }
    function trivial_rights(Contact $user, PaperSearch $srch) {
        return $this->dtype === DTYPE_SUBMISSION && $this->format === null;
    }
    static function add_columns(SearchQueryInfo $sqi) {
        $sqi->add_column("paperStorageId", "Paper.paperStorageId");
        $sqi->add_column("finalPaperStorageId", "Paper.finalPaperStorageId");
        $sqi->add_column("mimetype", "Paper.mimetype");
        $sqi->add_column("sha1", "Paper.sha1");
        $sqi->add_column("pdfFormatStatus", "Paper.pdfFormatStatus");
    }
    function sqlexpr(SearchQueryInfo $sqi) {
        if ($this->format !== null)
            $this->add_columns($sqi);
        else
            $sqi->add_column($this->fieldname, "Paper.{$this->fieldname}");
        return "Paper.{$this->fieldname}" . ($this->present ? ">1" : "<=1");
    }
    function exec(PaperInfo $row, PaperSearch $srch) {
        if (($this->dtype === DTYPE_FINAL && !$srch->user->can_view_decision($row, true))
            || ($row->{$this->fieldname} > 1) !== $this->present)
            return false;
        if ($this->format !== null) {
            if (!$srch->user->can_view_pdf($row))
                return false;
            if (($doc = $this->cf->fetch_document($row, $this->dtype)))
                $this->cf->check_document($row, $doc);
            $errf = $doc && !$this->cf->failed ? $this->cf->problem_fields() : ["error"];
            if (empty($errf) !== $this->format
                || ($this->format_errf && !in_array($this->format_errf, $errf)))
                return false;
        }
        return true;
    }
}

class Pages_SearchTerm extends SearchTerm {
    private $cf;
    private $cm;

    function __construct(CountMatcher $cm) {
        parent::__construct("pages");
        $this->cf = new CheckFormat(CheckFormat::RUN_PREFER_NO);
        $this->cm = $cm;
    }
    static function parse($word, SearchWord $sword, PaperSearch $srch) {
        $cm = new CountMatcher($word);
        if ($cm->ok())
            return new Pages_SearchTerm(new CountMatcher($word));
        else {
            $srch->warn("“{$keyword}:” expects a page number comparison.");
            return null;
        }
    }
    function sqlexpr(SearchQueryInfo $sqi) {
        PaperPDF_SearchTerm::add_columns($sqi);
        return "true";
    }
    function exec(PaperInfo $row, PaperSearch $srch) {
        $dtype = DTYPE_SUBMISSION;
        if ($srch->user->can_view_decision($row, true) && $row->outcome > 0
            && $row->finalPaperStorageId > 1)
            $dtype = DTYPE_FINAL;
        return ($doc = $row->document($dtype))
            && ($np = $doc->npages()) !== null
            && $this->cm->test($np);
    }
}
