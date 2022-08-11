<?php
// search/st_topic.php -- HotCRP helper class for searching for papers
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class Topic_SearchTerm {
    static function parse($word, SearchWord $sword, PaperSearch $srch) {
        $opt = $srch->conf->option_by_id(PaperOption::TOPICSID);
        $sword->set_compar_word($sword->word);
        return Option_SearchTerm::parse_option($sword, $srch, $opt);
    }
}
