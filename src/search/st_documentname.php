<?php
// search/st_documentname.php -- HotCRP helper class for searching for papers
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class DocumentName_SearchTerm extends Option_SearchTerm {
    /** @var bool */
    private $want;
    /** @var string */
    private $match;
    /** @var ?TextPregexes */
    private $pregexes;
    /** @param bool $want
     * @param string $match */
    function __construct(Contact $user, PaperOption $o, $want, $match) {
        parent::__construct($user, $o, "documentname");
        $this->want = $want;
        $this->match = $match;
    }
    function debug_json() {
        return [$this->type, $this->option->search_keyword(), $this->match];
    }
    function test(PaperInfo $row, $xinfo) {
        if ($this->user->can_view_option($row, $this->option)
            && ($ov = $row->option($this->option))) {
            $this->pregexes = $this->pregexes ?? Text::star_text_pregexes($this->match);
            foreach ($ov->document_set() as $d) {
                $m = $this->pregexes->match($d->filename, null);
                if ($m === $this->want)
                    return true;
            }
        }
        return false;
    }
    function about() {
        return self::ABOUT_PAPER;
    }
}
