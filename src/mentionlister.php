<?php
// mentionlister.php -- HotCRP helper class for listing mentions
// Copyright (c) 2006-2026 Eddie Kohler; see LICENSE.

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
            $pclist = [];
            foreach ($this->get_pc($prow, $user, $reason) as $pc) {
                if (!$pc->is_dormant())
                    $pclist[] = $pc;
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
            if ($rrow->reviewOrdinal
                && $user->can_view_review($prow, $rrow)) {
                $au = Author::make_last("Reviewer " . unparse_latin_ordinal($rrow->reviewOrdinal));
                $au->contactId = $rrow->contactId;
                $au->status = Author::STATUS_ANONYMOUS_REVIEWER;
                $rlist[] = $au;
            } else if ($rrow->reviewType === REVIEW_META) {
                $au = Author::make_last("Metareviewer");
                $au->contactId = $rrow->contactId;
                $au->status = Author::STATUS_ANONYMOUS_REVIEWER;
                $rlist[] = $au;
            }
            if (($cvis >= CommentInfo::CTVIS_REVIEWER || $rrow->reviewType >= REVIEW_PC)
                && $user->can_view_review_identity($prow, $rrow)
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
        $au = Author::make_last("Shepherd");
        $au->contactId = $prow->shepherdContactId;
        $au->status = Author::STATUS_ANONYMOUS_REVIEWER;
        $this->lists["reviewers"][] = $au;
        if (!in_array($prow->shepherdContactId, $this->rcids, true)
            && $user->can_view_shepherd($prow)
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
            if (!in_array($crow->contactId, $this->rcids, true)
                && $user->can_view_comment_identity($prow, $crow)
                && ($commenter = $crow->commenter())
                && !$commenter->is_dormant()) {
                $this->lists["reviewers"][] = $commenter;
                $this->rcids[] = $crow->contactId;
            }
        }
    }

    /** @param PaperInfo $prow
     * @param Contact $user
     * @param 0|1 $reason
     * @return array<Contact> */
    private function get_pc($prow, $user, $reason) {
        if (!$prow
            || $reason === self::FOR_PARSE
            || !$prow->conf->check_track_view_sensitivity()
            || !$user->can_view_user_tags()) {
            return $prow->conf->pc_members();
        }
        // enumerate track permissions that allow viewing this paper,
        // but leave off permissions this user can't see (e.g. `+~~chair_tag`)
        $relevant_perm = [];
        $unmatched = true;
        foreach ($prow->conf->track_list() as $tr) {
            if ($tr->is_default ? $unmatched : $prow->has_tag($tr->ltag)) {
                $unmatched = false;
                $p = $tr->perm[Track::VIEW];
                if ($p === null) {
                    return $prow->conf->pc_members();
                } else if ($p !== "+none"
                           && $user->can_view_tag_somewhere(substr($p, 1))) {
                    $relevant_perm[] = $p;
                }
            }
        }
        // return PC members who match at least one of the permissions,
        // or are chairs
        // XXX managerContactId, track admins?
        $allowed = [];
        foreach ($prow->conf->pc_members() as $pc) {
            foreach ($relevant_perm as $perm) {
                if ($pc->has_permission($perm)) {
                    $allowed[] = $pc;
                    continue 2;
                }
            }
            if ($pc->privChair) {
                $allowed[] = $pc;
            }
        }
        return $allowed;
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
                    if (in_array($n, $aunames, true)) { // duplicate contact names are common
                        continue;
                    }
                    $aunames[] = $n;
                }
                if (!$ispc) {
                    $x["pri"] = 1;
                } else if ($prow ? $au->is_admin($prow) : $au->privChair) {
                    $x["admin"] = true;
                }
                $comp[] = $x;
            }
        }
        return ["ok" => true, "mentioncompletion" => $comp];
    }
}
