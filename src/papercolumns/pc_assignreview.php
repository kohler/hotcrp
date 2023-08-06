<?php
// pc_assignreview.php -- HotCRP helper classes for paper list content
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class AssignReview_PaperColumn extends PaperColumn {
    /** @var Contact */
    private $contact;
    /** @var bool */
    private $simple = false;
    /** @var array<int,int> */
    private $sortmap;
    function __construct(Conf $conf, $cj) {
        parent::__construct($conf, $cj);
        if (isset($cj->user)) {
            $this->contact = $conf->pc_member_by_email($cj->user);
        }
    }
    function add_decoration($decor) {
        if ($decor === "simple") {
            $this->simple = true;
            return $this->__add_decoration($decor);
        } else {
            return parent::add_decoration($decor);
        }
    }
    function prepare(PaperList $pl, $visible) {
        $this->contact = $this->contact ?? $pl->reviewer_user();
        return $pl->user->is_manager();
    }
    /** @return Contact */
    function contact() {
        return $this->contact;
    }
    function header(PaperList $pl, $is_text) {
        if ($this->simple) {
            return "Assignment";
        } else if ($is_text) {
            return $pl->user->reviewer_text_for($this->contact) . " assignment";
        } else {
            return $pl->user->reviewer_html_for($this->contact) . "<br>assignment";
        }
    }
    function prepare_sort(PaperList $pl, $sortindex) {
        $this->sortmap = [];
        foreach ($pl->rowset() as $row) {
            if ($pl->user->allow_administer($row)) {
                $ci = $row->contact_info($this->contact);
                if ($ci->conflictType >= CONFLICT_AUTHOR) {
                    $v = -100;
                } else if ($ci->conflictType > CONFLICT_MAXUNCONFLICTED) {
                    $v = -1;
                } else {
                    $v = min(max($ci->reviewType, 0), REVIEW_META);
                }
            } else {
                $v = -200;
            }
            $this->sortmap[$row->paperXid] = $v;
        }
    }
    function compare(PaperInfo $a, PaperInfo $b, PaperList $pl) {
        return $this->sortmap[$a->paperXid] <=> $this->sortmap[$b->paperXid];
    }
    function content_empty(PaperList $pl, PaperInfo $row) {
        return !$pl->user->allow_administer($row);
    }
    function content(PaperList $pl, PaperInfo $row) {
        $ci = $row->contact_info($this->contact);
        if ($ci->conflictType >= CONFLICT_AUTHOR) {
            return '<span class="author">Author</span>';
        }
        if ($ci->conflictType > CONFLICT_MAXUNCONFLICTED) {
            $rt = "conflict";
        } else {
            $rt = ReviewInfo::unparse_type(min(max($ci->reviewType, 0), REVIEW_META));
        }
        $rs = $ci->reviewSubmitted ? " s" : "";
        $pl->need_render = true;
        $t = '<span class="need-assignment-selector';
        if (!$this->contact->can_accept_review_assignment_ignore_conflict($row)
            && $rt <= 0) {
            $t .= " conflict";
        }
        return "{$t}\" data-assignment=\"{$this->contact->contactId} {$rt}{$rs}\"></span>";
    }
}
