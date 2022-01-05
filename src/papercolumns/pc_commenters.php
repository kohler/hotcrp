<?php
// pc_commenters.php -- HotCRP helper classes for paper list content
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class Commenters_PaperColumn extends PaperColumn {
    function __construct(Conf $conf, $cj) {
        parent::__construct($conf, $cj);
    }
    function content_empty(PaperList $pl, PaperInfo $row) {
        return !$row->viewable_comments($pl->user);
    }
    function content(PaperList $pl, PaperInfo $row) {
        $crows = $row->viewable_comments($pl->user);
        $cnames = array_map(function ($cx) use ($pl) {
            $n = $t = $cx[0]->unparse_commenter_html($pl->user);
            if (($tags = $cx[0]->viewable_tags($pl->user))
                && ($color = $cx[0]->conf->tags()->color_classes($tags)))
                $t = '<span class="cmtlink ' . $color . ' taghh">' . $n . '</span>';
            if ($cx[1] > 1)
                $t .= " ({$cx[1]})";
            return $t . $cx[2];
        }, CommentInfo::group_by_identity($crows, $pl->user, true));
        return join(" ", $cnames);
    }
    function text(PaperList $pl, PaperInfo $row) {
        $crows = $row->viewable_comments($pl->user);
        $cnames = array_map(function ($cx) use ($pl) {
            $t = $cx[0]->unparse_commenter_text($pl->user);
            if ($cx[1] > 1)
                $t .= " ({$cx[1]})";
            return $t . $cx[2];
        }, CommentInfo::group_by_identity($crows, $pl->user, false));
        return join(" ", $cnames);
    }
}
