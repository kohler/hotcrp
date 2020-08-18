<?php
// search/st_documentname.php -- HotCRP helper class for searching for papers
// Copyright (c) 2006-2020 Eddie Kohler; see LICENSE.

class DocumentName_SearchTerm extends SearchTerm {
    /** @var PaperOption */
    private $option;
    /** @var bool */
    private $want;
    /** @var string */
    private $match;
    /** @var ?TextPregexes */
    private $pregexes;
    /** @param bool $want
     * @param string $match */
    function __construct(PaperOption $o, $want, $match) {
        parent::__construct("documentname");
        $this->option = $o;
        $this->want = $want;
        $this->match = $match;
    }
    function debug_json() {
        return [$this->type, $this->option->search_keyword(), $this->match];
    }
    function sqlexpr(SearchQueryInfo $sqi) {
        $sqi->add_options_columns();
        if (!$sqi->negated && !$this->option->include_empty) {
            return "exists (select * from PaperOption where paperId=Paper.paperId and optionId={$this->option->id})";
        } else {
            return "true";
        }
    }
    function exec(PaperInfo $row, PaperSearch $srch) {
        if ($srch->user->can_view_option($row, $this->option)
            && ($ov = $row->option($this->option))) {
            $this->pregexes = $this->pregexes ?? Text::star_text_pregexes($this->match);
            foreach ($ov->document_set() as $d) {
                $m = $this->pregexes->match($d->filename, false);
                if ($m === $this->want)
                    return true;
            }
        }
        return false;
    }
}
