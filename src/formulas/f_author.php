<?php
// formulas/f_author.php -- HotCRP helper class for formula expressions
// Copyright (c) 2009-2025 Eddie Kohler; see LICENSE.

class Author_Fexpr extends Fexpr {
    private $matchtype;
    private $matchidx;
    function __construct(FormulaCall $ff) {
        parent::__construct($ff);
        $m = $ff->modifier;
        if ($m === "none") {
            $this->matchtype = $m;
        } else if (is_array($m) && $m[0] === $ff->user->contactId) {
            $this->matchtype = $ff->user->contactId;
        } else if (is_array($m) || is_object($m)) {
            $this->matchtype = "m";
            $this->matchidx = $ff->formula->register_info($m);
        }
    }
    static function make(FormulaCall $ff) {
        if (!$ff->user->can_view_some_authors()) {
            return Fexpr::cnever();
        }
        return new Author_Fexpr($ff);
    }
    static function parse_modifier(FormulaCall $ff, $arg) {
        if ($ff->modifier !== null || str_starts_with($arg, ".")) {
            return false;
        }
        if (str_starts_with($arg, ":")) {
            $arg = substr($arg, 1);
        }
        $csm = new ContactSearch(ContactSearch::F_TAG, $arg, $ff->user);
        if (!$csm->has_error()) {
            $ff->modifier = $csm->user_ids();
        } else if (!str_starts_with($arg, "#")) {
            $ff->modifier = Text::star_text_pregexes($arg);
        } else {
            return false;
        }
        return true;
    }
    function compile(FormulaCompiler $state) {
        $prow = $state->_prow();
        $state->queryOptions["authorInformation"] = true;
        if ($this->matchtype === null) {
            $v = "count({$prow}->author_list())";
        } else if ($this->matchtype === "none") {
            $v = "!{$prow}->author_list()";
        } else if (is_int($this->matchtype)) {
            // can always see if you are an author
            return "({$prow}->has_author(" . $this->matchtype . ") ? 1 : 0)";
        } else {
            $v = "Author_Fexpr::count_matches({$prow}, \$formula->info[{$this->matchidx}])";
        }
        if ($state->user->is_root_user()) {
            return $v;
        }
        return "(\$user->allow_view_authors({$prow}) ? {$v} : null)";
    }
    static function count_matches(PaperInfo $prow, $mf) {
        $n = 0;
        if (is_array($mf)) {
            foreach ($prow->contact_list() as $u) {
                if (array_search($u->contactId, $mf) !== false)
                    ++$n;
            }
        } else {
            foreach ($prow->author_list() as $au) {
                if ($mf->match($au->name(NAME_E|NAME_A)))
                    ++$n;
            }
        }
        return $n;
    }
}
