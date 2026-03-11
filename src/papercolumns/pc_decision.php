<?php
// pc_decision.php -- HotCRP helper classes for paper list content
// Copyright (c) 2006-2026 Eddie Kohler; see LICENSE.

class Decision_PaperColumn extends PaperColumn {
    /** @var bool */
    private $edit;
    /** @var array */
    private $edit_opts;
    function __construct(Conf $conf, $cj) {
        parent::__construct($conf, $cj);
        $this->override = PaperColumn::OVERRIDE_BOTH;
    }
    function view_option_schema() {
        return ["edit"];
    }
    function prepare(PaperList $pl, $visible) {
        $this->edit = $this->view_option("edit") ?? false;
        if ($this->edit) {
            $this->edit_opts = [];
            foreach ($pl->conf->decision_set() as $dec) {
                $this->edit_opts[$dec->id] = $dec->name_as(5);
            }
        }
        return true;
    }
    function compare(PaperInfo $a, PaperInfo $b, PaperList $pl) {
        $da = $a->viewable_decision($pl->user)->order ? : PHP_INT_MIN;
        $db = $b->viewable_decision($pl->user)->order ? : PHP_INT_MIN;
        return $da <=> $db;
    }
    function content(PaperList $pl, PaperInfo $row) {
        if ($this->edit && $pl->user->can_set_decision($row)) {
            return Ht::select("decision{$row->paperId}", $this->edit_opts, (string) $row->outcome, ["class" => "uich js-decide"]);
        }
        $dec = $row->viewable_decision($pl->user);
        return "<span class=\"pstat " . $dec->status_class() . "\">" . $dec->name_as(5) . "</span>";
    }
    function text(PaperList $pl, PaperInfo $row) {
        $dec = $row->viewable_decision($pl->user);
        return $dec->name;
    }
}
