<?php
// pc_commenters.php -- HotCRP helper classes for paper list content
// HotCRP is Copyright (c) 2006-2017 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

class Commenters_PaperColumn extends PaperColumn {
    function __construct($cj) {
        parent::__construct($cj);
    }
    function header(PaperList $pl, $is_text) {
        return "Commenters";
    }
    function content_empty(PaperList $pl, PaperInfo $row) {
        return !$row->viewable_comments($pl->user, null);
    }
    function content(PaperList $pl, PaperInfo $row) {
        $crows = $row->viewable_comments($pl->user, null);
        $cnames = array_map(function ($cx) use ($pl) {
            $n = $t = $cx[0]->unparse_user_html($pl->user, null);
            if (($tags = $cx[0]->viewable_tags($pl->user, null))
                && ($color = $cx[0]->conf->tags()->color_classes($tags)))
                $t = '<span class="cmtlink ' . $color . ' taghl">' . $n . '</span>';
            if ($cx[1] > 1)
                $t .= " ({$cx[1]})";
            return $t . $cx[2];
        }, CommentInfo::group_by_identity($crows, $pl->user, true));
        return join(" ", $cnames);
    }
    function text(PaperList $pl, PaperInfo $row) {
        $crows = $row->viewable_comments($pl->user, null);
        $cnames = array_map(function ($cx) use ($pl) {
            $t = $cx[0]->unparse_user_text($pl->user, null);
            if ($cx[1] > 1)
                $t .= " ({$cx[1]})";
            return $t . $cx[2];
        }, CommentInfo::group_by_identity($crows, $pl->user, false));
        return join(" ", $cnames);
    }
}
