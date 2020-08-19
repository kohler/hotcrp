<?php
// search/st_documentname.php -- HotCRP helper class for searching for papers
// Copyright (c) 2006-2020 Eddie Kohler; see LICENSE.

class DocumentName_SearchTerm extends Option_SearchTerm {
    /** @var bool */
    private $want;
    /** @var string */
    private $match;
    /** @var ?TextPregexes */
    private $pregexes;
    /** @param bool $want
     * @param string $match */
    function __construct(PaperOption $o, $want, $match) {
        parent::__construct("documentname", $o);
        $this->want = $want;
        $this->match = $match;
    }
    function debug_json() {
        return [$this->type, $this->option->search_keyword(), $this->match];
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
