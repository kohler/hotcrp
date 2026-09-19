<?php
// search/st_namedsearch.php -- HotCRP helper class for searching for papers
// Copyright (c) 2006-2024 Eddie Kohler; see LICENSE.

class NamedSearch_SearchTerm {
    /** @param string $word
     * @return ?SearchTerm */
    static function parse($word, SearchWord $sword, PaperSearch $srch) {
        if (!$srch->user->isPC) {
            return null;
        }

        // check search name-related permissions
        $name = $word;
        $twiddle = strpos($word, "~");
        if ($twiddle === false) {
            /* ok */
        } else if ($twiddle === 0 && str_starts_with($word, "~~")) {
            if (!$srch->user->privChair) {
                $srch->lwarning($sword, "<0>Search reserved for chairs");
                return null;
            }
        } else if ($twiddle === 0) {
            $name = $srch->user->contactId . $name;
        } else if (str_starts_with($word, $srch->user->contactId . "~")) {
            /* always ok */
        } else if (!$srch->user->privChair) {
            $srch->lwarning($sword, "<0>Search name reserved");
            return null;
        } else if (ctype_digit(substr($word, 0, $twiddle))) {
            /* ok */
        } else {
            $cids = ContactSearch::make_pc(substr($word, 0, $twiddle), $srch->user)->user_ids();
            if (count($cids) !== 1) {
                $srch->lwarning($sword, "<0>No match for search name");
                return null;
            }
            $name = $cids[0] . substr($word, $twiddle);
        }

        // find search
        $sj = ($srch->conf->named_searches())[strtolower($name)] ?? null;
        if (!$sj) {
            $srch->lwarning($sword, "<0>Search not found");
            return null;
        }

        // expand search
        $q = $sj->q ?? "";
        if ($q !== "" && ($sj->t ?? "") !== "" && $sj->t !== "s") {
            $q = "({$q}) in:{$sj->t}";
        }
        if ($q === "") {
            $srch->lwarning($sword, "<0>Search defined incorrectly");
            return null;
        }

        // check for recursion
        if ($sj->__recursion ?? false) {
            $mi = MessageItem::error("<0>Saved searches are circularly defined");
            $mis = $srch->expand_message_context($mi, $sword->kwpos1, $sword->pos2, $sword->string_context);
            $srch->append_list($mis);
            $sword->string_context->charge_abort();
            return null;
        }

        // recurse
        try {
            $sj->__recursion = true;
            return $srch->parse_named_search_body($q, $sword);
        } finally {
            $sj->__recursion = false;
        }
    }
}
