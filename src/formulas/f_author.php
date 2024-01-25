<?php
// formulas/f_author.php -- HotCRP helper class for formula expressions
// Copyright (c) 2009-2024 Eddie Kohler; see LICENSE.

class Author_Fexpr extends Fexpr {
    private $matchtype;
    private $matchidx;
    static private $matchers = [];
    function __construct(FormulaCall $ff, Formula $formula) {
        parent::__construct($ff);
        $m = $ff->modifier;
        if ($m === "none") {
            $this->matchtype = $m;
        } else if (is_array($m) && $m[0] === $formula->user->contactId) {
            $this->matchtype = $formula->user->contactId;
        } else if (is_array($m) || is_object($m)) {
            self::$matchers[] = $m;
            $this->matchtype = "m";
            $this->matchidx = count(self::$matchers) - 1;
        }
    }
    static function parse_modifier(FormulaCall $ff, $arg, $rest, Formula $formula) {
        if ($ff->modifier === null && !str_starts_with($arg, ".")) {
            if (str_starts_with($arg, ":")) {
                $arg = substr($arg, 1);
            }
            $csm = new ContactSearch(ContactSearch::F_TAG, $arg, $formula->user);
            if (!$csm->has_error()) {
                $ff->modifier = $csm->user_ids();
            } else if (!str_starts_with($arg, "#")) {
                $ff->modifier = Text::star_text_pregexes($arg);
            } else {
                return false;
            }
            return true;
        } else {
            return false;
        }
    }
    function viewable_by(Contact $user) {
        return $user->can_view_some_authors();
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
            $v = "Author_Fexpr::count_matches({$prow}, " . $this->matchidx . ')';
        }
        if ($state->user->is_root_user()) {
            return $v;
        } else {
            return "(\$contact->allow_view_authors({$prow}) ? {$v} : null)";
        }
    }
    static function count_matches(PaperInfo $prow, $matchidx) {
        $mf = self::$matchers[$matchidx];
        $n = 0;
        if (is_array($mf)) {
            foreach ($prow->contact_list() as $u) {
                if (array_search($u->contactId, $mf) !== false)
                    ++$n;
            }
        } else {
            foreach ($prow->author_list() as $au) {
                $text = $au->name(NAME_E|NAME_A);
                if (Text::match_pregexes($mf, $text, UnicodeHelper::deaccent($text)))
                    ++$n;
            }
        }
        return $n;
    }
}
