<?php
// search/st_optiontext.php -- HotCRP helper class for searching for papers
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class OptionText_SearchTerm extends Option_SearchTerm {
    /** @var string */
    private $match;
    /** @var ?TextPregexes */
    private $pregexes;
    /** @param string $match */
    function __construct(Contact $user, PaperOption $o, $match) {
        parent::__construct($user, $o, "optiontext");
        $this->match = $match;
    }
    function debug_json() {
        return [$this->type, $this->option->search_keyword(), $this->match];
    }
    function test(PaperInfo $row, $xinfo) {
        if ($this->user->can_view_option($row, $this->option)
            && ($ov = $row->option($this->option))
            && ($ov->data() ?? "") !== "") {
            $this->pregexes = $this->pregexes ?? Text::star_text_pregexes($this->match);
            return Text::match_pregexes($this->pregexes, (string) $ov->data(), null);
        } else {
            return false;
        }
    }
    function about() {
        return self::ABOUT_PAPER;
    }
}
