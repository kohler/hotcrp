<?php
// search/st_sclass.php -- HotCRP helper class for searching for papers
// Copyright (c) 2006-2023 Eddie Kohler; see LICENSE.

class Sclass_SearchTerm extends SearchTerm {
    /** @var SubmissionRound */
    public $sr;
    /** @var bool */
    public $negate;

    /** @param SubmissionRound $sr
     * @param bool $negate */
    function __construct($sr, $negate) {
        parent::__construct("sclass");
        $this->sr = $sr;
        $this->negate = $negate;
    }

    static function parse($word, SearchWord $sword, PaperSearch $srch) {
        $tagger = new Tagger($srch->user);
        $tag = $tagger->check($word, Tagger::ALLOWRESERVED | Tagger::NOPRIVATE | Tagger::NOCHAIR);
        if ($tag === false) {
            $srch->lwarning($sword, $tagger->error_ftext(true));
            return new False_SearchTerm;
        }

        if (strcasecmp($tag, "any") === 0) {
            return new Sclass_SearchTerm($srch->conf->unnamed_submission_round(), true);
        } else if (($sr = $srch->conf->submission_round_by_tag($tag))) {
            return new Sclass_SearchTerm($sr, false);
        } else {
            $srch->lwarning($sword, "<0>Submission class ‘{$tag}’ not found");
            return new False_SearchTerm;
        }
    }
    function sqlexpr(SearchQueryInfo $sqi) {
        if (!$this->sr->unnamed) {
            return Dbl::format_query($sqi->srch->conf->dblink,
                "exists (select * from PaperTag where paperId=Paper.paperId and tag=?)",
                $this->sr->tag);
        } else {
            return "true";
        }
    }
    function test(PaperInfo $row, $xinfo) {
        return ($row->submission_round() === $this->sr) !== $this->negate;
    }
    function debug_json() {
        return ["type" => $this->type, "sclass" => $this->negate ? "any" : $this->sr->tag];
    }
    function about() {
        return self::ABOUT_PAPER;
    }
}
