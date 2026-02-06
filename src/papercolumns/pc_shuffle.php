<?php
// pc_shuffle.php -- HotCRP helper classes for paper list content
// Copyright (c) 2006-2025 Eddie Kohler; see LICENSE.

class Shuffle_PaperColumn extends PaperColumn {
    /** @var StableIDPermutation */
    private $idperm;

    function __construct(Conf $conf, $cj) {
        parent::__construct($conf, $cj);
    }
    function view_option_schema() {
        return ["key$^"];
    }
    function prepare(PaperList $pl, $visible) {
        $v = $this->view_option("key") ?? "";
        if (ctype_xdigit($v)) {
            if (strlen($v) % 2 === 1) {
                $v .= "0";
            }
            $this->idperm = new StableIDPermutation(hex2bin($v));
        } else {
            if ($v === "" || strcasecmp($v, "me") === 0) {
                $u = $pl->user;
            } else if (strcasecmp($v, "reviewer") === 0) {
                $u = $pl->reviewer_user();
            } else {
                $cu = ContactSearch::make_pc($v, $pl->user);
                if (count($cu->users()) !== 1) {
                    return false;
                }
                $u = ($cu->users())[0];
            }
            if ($u !== $pl->user && !$pl->user->privChair) {
                return false;
            }
            $this->idperm = StableIDPermutation::make_user($u);
        }
        $this->idperm->prefetch($pl->unordered_rowset()->paper_ids());
        return true;
    }
    function sort_name() {
        return $this->sort_name_with_options("key");
    }
    function compare(PaperInfo $a, PaperInfo $b, PaperList $pl) {
        return $this->idperm->get($a->paperId) <=> $this->idperm->get($b->paperId);
    }
    function content(PaperList $pl, PaperInfo $row) {
        return (string) $this->idperm->order($row->paperId);
    }
    function text(PaperList $pl, PaperInfo $row) {
        return (string) $this->idperm->order($row->paperId);
    }
    function json(PaperList $pl, PaperInfo $row) {
        return $this->idperm->order($row->paperId);
    }
}
