<?php
// mentionlister.php -- HotCRP helper class for listing mentions
// Copyright (c) 2006-2024 Eddie Kohler; see LICENSE.

class MentionLister {
    /** @var array<string,list<Contact|Author>> */
    private $lists = [];
    /** @var list<int> */
    private $rcids = [];
    /** @var bool */
    private $saw_shepherd = false;

    const FOR_PARSE = 0;
    const FOR_COMPLETION = 1;

    /** @param Contact $user
     * @param ?PaperInfo $prow
     * @param int $cvis
     * @param 0|1 $reason */
    function __construct($user, $prow, $cvis, $reason) {
        if ($prow
            && $user->can_view_authors($prow)
            && $cvis >= CommentInfo::CTVIS_AUTHOR) {
            $this->add_authors($prow, $user);
        }

        if ($prow
            && $user->can_view_review_assignment($prow, null)) {
            $this->add_reviewers($prow, $user, $cvis);
        }

        // XXX list lead?

        if ($prow
            && $prow->shepherdContactId > 0
            && $prow->shepherdContactId !== $user->contactId) {
            $this->add_shepherd($prow, $user);
        }

        if ($prow) {
            $this->add_commenters($prow, $user, $cvis);
        }

        if ((!$prow || !$prow->has_author($user))
            && $user->can_view_pc()) {
            if (!$prow
                || $reason === self::FOR_PARSE
                || !$user->conf->check_track_view_sensitivity()) {
                $pclist = $user->conf->enabled_pc_members();
            } else {
                $pclist = [];
                foreach ($user->conf->pc_members() as $u) {
                    if (!$u->is_dormant()
                        && $u->can_pc_view_paper_track($prow))
                        $pclist[] = $u;
                }
            }
            if (!empty($pclist)) {
                $this->lists["pc"] = $pclist;
            }
        }
    }

    /** @param PaperInfo $prow
     * @param Contact $user */
    private function add_authors($prow, $user) {
        $alist = [];
        foreach ($prow->contact_list() as $au) {
            if ($au->contactId !== $user->contactId)
                $alist[] = $au;
        }
        if (!empty($alist)) {
            $this->lists["authors"] = $alist;
        }
    }

    /** @param PaperInfo $prow
     * @param Contact $user
     * @param int $cvis */
    private function add_reviewers($prow, $user, $cvis) {
        $prow->ensure_reviewer_names();
        $xview = $user->conf->time_some_external_reviewer_view_comment();
        $rlist = $this->lists["reviewers"] ?? [];
        foreach ($prow->reviews_as_display() as $rrow) {
            if (($rrow->reviewType < REVIEW_PC && !$xview)
                || $rrow->contactId === $user->contactId) {
                continue;
            }
            $viewid = $user->can_view_review_identity($prow, $rrow);
            if ($rrow->reviewOrdinal
                && $user->can_view_review($prow, $rrow)) {
                $au = new Author;
                $au->lastName = "Reviewer " . unparse_latin_ordinal($rrow->reviewOrdinal);
                $au->contactId = $rrow->contactId;
                $au->status = $viewid ? Author::STATUS_REVIEWER : Author::STATUS_ANONYMOUS_REVIEWER;
                $rlist[] = $au;
            }
            if ($viewid
                && ($cvis >= CommentInfo::CTVIS_REVIEWER || $rrow->reviewType >= REVIEW_PC)
                && !$rrow->reviewer()->is_dormant()) {
                $rlist[] = $rrow->reviewer();
                $this->rcids[] = $rrow->contactId;
            }
        }
        if (!empty($rlist)) {
            $this->lists["reviewers"] = $rlist;
        }
    }

    /** @param PaperInfo $prow
     * @param Contact $user */
    private function add_shepherd($prow, $user) {
        $viewid = $user->can_view_shepherd($prow);
        $au = new Author;
        $au->lastName = "Shepherd";
        $au->contactId = $prow->shepherdContactId;
        $au->status = $viewid ? Author::STATUS_REVIEWER : Author::STATUS_ANONYMOUS_REVIEWER;
        $this->lists["reviewers"][] = $au;
        if ($viewid
            && !in_array($prow->shepherdContactId, $this->rcids)
            && ($shepherd = $user->conf->user_by_id($prow->shepherdContactId, USER_SLICE))
            && !$shepherd->is_dormant()) {
            $this->lists["reviewers"][] = $shepherd;
            $this->rcids[] = $prow->shepherdContactId;
        }
    }

    /** @param PaperInfo $prow
     * @param Contact $user
     * @param int $cvis */
    private function add_commenters($prow, $user, $cvis) {
        foreach ($prow->viewable_comment_skeletons($user) as $crow) {
            if (!in_array($crow->contactId, $this->rcids)
                && $user->can_view_comment_identity($prow, $crow)
                && ($commenter = $crow->commenter())
                && !$commenter->is_dormant()) {
                $this->lists["reviewers"][] = $commenter;
                $this->rcids[] = $crow->contactId;
            }
        }
    }


    /** @return array<string,list<Author|Contact>> */
    function lists() {
        return $this->lists;
    }

    /** @return list<list<Author|Contact>> */
    function list_values() {
        return array_values($this->lists);
    }

    /** @param Qrequest $qreq
     * @param ?PaperInfo $prow */
    static function mentioncompletion_api(Contact $user, $qreq, $prow) {
        $mlister = new MentionLister($user, $prow, CommentInfo::CTVIS_AUTHOR, self::FOR_COMPLETION);
        $comp = $aunames = [];
        foreach ($mlister->lists() as $key => $mlist) {
            $isau = $key === "authors";
            $ispc = $key === "pc";
            $skey = $ispc ? "sm1" : "s";
            foreach ($mlist as $au) {
                $n = Text::name($au->firstName, $au->lastName, $au->email, NAME_P);
                $x = [$skey => $n];
                if ($isau) {
                    $x["au"] = true;
                    if (in_array($n, $aunames)) { // duplicate contact names are common
                        continue;
                    }
                    $aunames[] = $n;
                }
                if (!$ispc) {
                    $x["pri"] = 1;
                }
                $comp[] = $x;
            }
        }
        return ["ok" => true, "mentioncompletion" => $comp];
    }
}
