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
    function view_option_schema() {
        return ["simple"];
    }
    function prepare(PaperList $pl, $visible) {
        $this->simple = $this->view_option("simple") ?? false;
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
        if ($ci->is_author()) {
            return '<span class="author">Author</span>';
        }
        $rtype = min(max($ci->reviewType, 0), REVIEW_META);
        if ($rtype === 0 && $ci->conflicted()) {
            $rt = "conflict";
        } else {
            $rt = ReviewInfo::unparse_type($rtype);
        }
        if ($ci->review_submitted()) {
            $rt .= " rs";
        }
        if (!$this->contact->pc_track_assignable($row) || $ci->conflicted()) {
            $rt .= " na";
        }
        $pl->need_render = true;
        return "<span class=\"need-assignment-selector\" data-assignment=\"{$this->contact->contactId} {$rt}\"></span>";
    }
}
