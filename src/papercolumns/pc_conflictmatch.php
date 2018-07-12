<?php
// pc_conflictmatch.php -- HotCRP paper columns for author/collaborator match
// Copyright (c) 2006-2018 Eddie Kohler; see LICENSE.

class ConflictMatch_PaperColumn extends PaperColumn {
    private $isau;
    private $highlight;
    function __construct(Conf $conf, $cj) {
        parent::__construct($conf, $cj);
        $this->isau = $cj->name === "authorsmatch";
    }
    private function field() {
        return $this->isau ? "authorInformation" : "collaborators";
    }
    function prepare(PaperList $pl, $visible) {
        $this->contact = $pl->reviewer_user();
        $this->highlight = $pl->search->field_highlighter($this->field());
        $general_pregexes = $this->contact->aucollab_general_pregexes();
        return $pl->user->is_manager() && !empty($general_pregexes);
    }
    function header(PaperList $pl, $is_text) {
        $what = "Potential conflict in " . ($this->isau ? "authors" : "collaborators");
        if (!$is_text)
            $what = "<strong>$what</strong>";
        return $what;
    }
    function content_empty(PaperList $pl, PaperInfo $row) {
        return !$pl->user->allow_administer($row);
    }
    function content(PaperList $pl, PaperInfo $row) {
        if (!$row->field_match_pregexes($this->contact->aucollab_general_pregexes(), $this->field()))
            return "";
        $text = [];
        $aus = $this->isau ? $row->author_list() : $row->collaborator_list();
        foreach ($aus as $au) {
            $matchers = [];
            foreach ($this->contact->aucollab_matchers() as $matcher)
                if (($this->isau || !$matcher->nonauthor)
                    && $matcher->test($au, $matcher->nonauthor))
                    $matchers[] = $matcher;
            if (!empty($matchers))
                $text[] = AuthorMatcher::highlight_all($au, $matchers);
        }
        if (!empty($text))
            unset($row->folded);
        return join("; ", $text);
    }
}
