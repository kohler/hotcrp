<?php
// contactintersection.php -- HotCRP helper class for intersecting permissions
// Copyright (c) 2006-2026 Eddie Kohler; see LICENSE.

class ContactIntersection extends ContactPermissions {
    /** @var ContactPermissions */
    private $a;
    /** @var ContactPermissions */
    private $b;

    function __construct(ContactPermissions $a, ContactPermissions $b) {
        $this->conf = $a->conf;
        $this->a = $a;
        $this->b = $b;
    }

    /** @return ContactPermissions */
    static function make(?Contact $a, ?Contact $b) {
        assert($a !== null || $b !== null);
        if (!$a || $a->is_root_user()) {
            return $b ?? $a;
        } else if (!$b || $b->is_root_user()) {
            return $a;
        }
        return new ContactIntersection($a, $b);
    }

    function is_chairlike() {
        return $this->a->is_chairlike()
            && $this->b->is_chairlike();
    }
    function is_admin(PaperInfo $prow) {
        return $this->a->is_admin($prow)
            && $this->b->is_admin($prow);
    }
    function implies_author_view(PaperInfo $prow) {
        return $this->a->implies_author_view($prow)
            || $this->b->implies_author_view($prow);
    }
    function can_view_authors(PaperInfo $prow) {
        return $this->a->can_view_authors($prow)
            && $this->b->can_view_authors($prow);
    }
    function can_view_option(PaperInfo $prow, $opt, $override = 0) {
        return $this->a->can_view_option($prow, $opt, $override)
            && $this->b->can_view_option($prow, $opt, $override);
    }
    function is_my_review(?ReviewInfo $rrow) {
        return false;
    }
    function can_view_review(PaperInfo $prow, $rrow, $viewscore = null, $flags = 0) {
        return $this->a->can_view_review($prow, $rrow, $viewscore, $flags)
            && $this->b->can_view_review($prow, $rrow, $viewscore, $flags);
    }
    function can_view_review_assignment(PaperInfo $prow, $rrow) {
        return $this->a->can_view_review_assignment($prow, $rrow)
            && $this->b->can_view_review_assignment($prow, $rrow);
    }
    function can_view_review_identity(PaperInfo $prow, $rbase = null) {
        return $this->a->can_view_review_identity($prow, $rbase)
            && $this->b->can_view_review_identity($prow, $rbase);
    }
    function can_view_review_meta(PaperInfo $prow, $rbase = null) {
        return $this->a->can_view_review_meta($prow, $rbase)
            && $this->b->can_view_review_meta($prow, $rbase);
    }
    function view_score_bound(PaperInfo $prow, ReviewInfo $rrow) {
        return max($this->a->view_score_bound($prow, $rrow),
                   $this->b->view_score_bound($prow, $rrow));
    }
    function is_my_comment(PaperInfo $prow, $crow) {
        return false;
    }
    function can_view_comment(PaperInfo $prow, CommentInfo $crow, $textless = false) {
        return $this->a->can_view_comment($prow, $crow, $textless)
            && $this->b->can_view_comment($prow, $crow, $textless);
    }
    function can_view_comment_content(PaperInfo $prow, CommentInfo $crow) {
        return $this->a->can_view_comment_content($prow, $crow)
            && $this->b->can_view_comment_content($prow, $crow);
    }
    function can_view_comment_identity(PaperInfo $prow, CommentInfo $crow) {
        return $this->a->can_view_comment_identity($prow, $crow)
            && $this->b->can_view_comment_identity($prow, $crow);
    }
    function can_view_comment_time(PaperInfo $prow, CommentInfo $crow) {
        return $this->a->can_view_comment_time($prow, $crow)
            && $this->b->can_view_comment_time($prow, $crow);
    }
    function can_view_comment_tags(PaperInfo $prow, CommentInfo $crow) {
        return $this->a->can_view_comment_tags($prow, $crow)
            && $this->b->can_view_comment_tags($prow, $crow);
    }
    function can_view_manager(?PaperInfo $prow = null) {
        return $this->a->can_view_manager($prow)
            && $this->b->can_view_manager($prow);
    }
    function can_view_shepherd(?PaperInfo $prow) {
        return $this->a->can_view_shepherd($prow)
            && $this->b->can_view_shepherd($prow);
    }
    function can_view_decision(PaperInfo $prow) {
        return $this->a->can_view_decision($prow)
            && $this->b->can_view_decision($prow);
    }
    function tag_perm_flags(?PaperInfo $prow) {
        return $this->a->tag_perm_flags($prow)
            & $this->b->tag_perm_flags($prow);
    }
    function can_view_tag(?PaperInfo $prow, $tag) {
        return $this->a->can_view_tag($prow, $tag)
            && $this->b->can_view_tag($prow, $tag);
    }
}
