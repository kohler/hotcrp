<?php
// search/st_option.php -- HotCRP helper class for searching for papers
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

abstract class Option_SearchTerm extends SearchTerm {
    /** @var Contact */
    protected $user;
    /** @var PaperOption */
    protected $option;

    /** @param string $type */
    function __construct(Contact $user, PaperOption $option, $type) {
        parent::__construct($type);
        $this->user = $user;
        $this->option = $option;
    }

    /** @return PaperOption */
    function option() {
        return $this->option;
    }

    function paper_requirements(&$options) {
        if ($this->option->id === PaperOption::TOPICSID) {
            $options["topics"] = true;
        } else {
            $options["options"] = true;
        }
    }

    function sqlexpr(SearchQueryInfo $sqi) {
        if ($this->option->id > 0) {
            $sqi->add_options_columns();
            if (!$this->option->include_empty) {
                return "exists (select * from PaperOption where paperId=Paper.paperId and optionId={$this->option->id})";
            }
        } else if ($this->option->id === PaperOption::TOPICSID) {
            $sqi->add_topics_columns();
            return "exists (select * from PaperTopic where paperId=Paper.paperId)";
        }
        return "true";
    }

    static function parse_factory($keyword, XtParams $xtp, $kwfj, $m) {
        $f = $xtp->conf->find_all_fields($keyword);
        if (count($f) === 1 && $f[0] instanceof PaperOption) {
            return (object) [
                "name" => $keyword,
                "parse_function" => "Option_SearchTerm::parse",
                "has" => "any"
            ];
        } else {
            return null;
        }
    }

    /** @return SearchTerm */
    static function parse($word, SearchWord $sword, PaperSearch $srch) {
        // option name and option content
        if ($sword->kwdef->name === "option") {
            if (preg_match('/\A(.*?)(?::|(?=[#=!<>]|≠|≤|≥))(.*)\z/s', $word, $m)) {
                $oname = $m[1];
                $ocontent = $m[2];
            } else {
                $oname = $word;
                $ocontent = "any";
            }
        } else {
            $oname = $sword->kwdef->name;
            $ocontent = $word;
        }

        // find option by name
        $opt = $srch->conf->abbrev_matcher()->find1($oname, Conf::MFLAG_OPTION);
        if ($opt === null) {
            if (($os2 = $srch->conf->abbrev_matcher()->find_all($oname, Conf::MFLAG_OPTION))) {
                $ts = array_map(function ($o) { return "‘" . $o->search_keyword() . "’"; }, $os2);
                $srch->lwarning($sword, "<0>Submission field ‘{$oname}’ is ambiguous");
                $srch->message_set()->msg_at(null, "<0>Submission fields include " . commajoin($ts, " and ") . ".", MessageSet::INFORM);
            } else {
                $srch->lwarning($sword, "<0>Submission field ‘{$oname}’ not found");
            }
            return new False_SearchTerm;
        }

        // parse by delegating to option
        $sword->set_compar_word($ocontent);
        return self::parse_option($sword, $srch, $opt);
    }

    /** @return SearchTerm */
    static function parse_option(SearchWord $sword, PaperSearch $srch, PaperOption $opt) {
        // handle any/none
        if (!$sword->quoted && strcasecmp($sword->cword, "any") === 0) {
            return new OptionPresent_SearchTerm($srch->user, $opt);
        } else if (!$sword->quoted && strcasecmp($sword->cword, "none") === 0) {
            return (new OptionPresent_SearchTerm($srch->user, $opt))->negate();
        } else {
            $nmsg = $srch->message_set()->message_count();
            if (($st = $opt->parse_search($sword, $srch))) {
                return $st;
            } else {
                if ($srch->message_set()->message_count() === $nmsg) {
                    $srch->lwarning($sword, "<0>Submission field ‘" . $opt->title() . "’ does not support this search");
                }
                return new False_SearchTerm;
            }
        }
    }
}
