<?php
// listactions/la_getreviewforms.php -- HotCRP helper classes for list actions
// Copyright (c) 2006-2026 Eddie Kohler; see LICENSE.

class GetReviewForms_ListAction extends GetReviewBase_ListAction {
    private $all;
    function __construct($conf, $fj) {
        parent::__construct(true, $fj->zip);
        $this->all = $fj->all;
    }
    function allow(Contact $user, Qrequest $qreq) {
        return ($this->all ? $user->is_manager() : $user->is_reviewer())
            && $user->scope_allows_some(TokenScope::S_REV_READ);
    }
    function run(Contact $user, Qrequest $qreq, SearchSelection $ssel) {
        $rf = $user->conf->review_form();
        if ($ssel->is_empty()) {
            // blank form
            return $user->conf->make_text_downloader("review")
                ->set_content($rf->text_form_header(false) . $rf->text_form(null, null, $user) . "\n");
        }

        $texts = [];
        '@phan-var-force list<array{int,string,int}> $texts';
        $ms = (new MessageSet)->set_ignore_duplicates(true)
            ->set_message_formatter($user->conf);
        foreach ($ssel->paper_set($user) as $prow) {
            if (!$this->all || !$user->allow_admin($prow)) {
                $rrows = $prow->full_reviews_by_user($user);
            } else {
                $prow->ensure_full_reviews();
                $rrows = $prow->reviews_as_display();
            }
            $time = null;
            $t = "";
            foreach ($rrows as $rrow) {
                if ($user->can_view_review($prow, $rrow)) {
                    $t .= $rf->text_form($prow, $rrow, $user) . "\n";
                    $time = max($time ?? 0, $rrow->mtime($user));
                }
            }
            if ($t === "") {
                if (($fr = $user->perm_view_blank_review_form($prow))) {
                    $fr->append_to($ms, null, 2);
                    continue;
                }
                $t .= $rf->text_form($prow, null, $user) . "\n";
            }
            $texts[] = [$prow->paperId, $t, $time];
        }

        return $this->finish($user, $texts, $ms);
    }
}
