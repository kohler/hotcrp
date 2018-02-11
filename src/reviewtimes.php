<?php
// src/reviewtimes.php -- HotCRP review form definition page
// Copyright (c) 2006-2018 Eddie Kohler; see LICENSE.

class ReviewTimes {
    private $conf;
    private $user;
    private $r;
    private $dl;

    function __construct(Contact $user) {
        $this->conf = $user->conf;
        $this->user = $user;
        $overrides = $user->add_overrides(Contact::OVERRIDE_CONFLICT);

        $this->dl = [];
        foreach ($this->conf->round_list() as $rn => $r) {
            $dl = $this->conf->review_deadline($rn, true, false);
            $this->dl[$rn] = +$this->conf->setting($dl);
        }

        $rs = $rs_nvis = [];
        foreach ($user->paper_set(["reviewSignatures" => true]) as $prow) {
            if (!$user->can_view_paper($prow)
                || ($prow->conflict_type($user) > 0
                    && (!$user->can_view_review_assignment($prow, null)
                        || !$user->can_view_review_identity($prow, null)))) {
                continue;
            }
            foreach ($prow->reviews_by_id() as $rrow) {
                if ($rrow->reviewType > REVIEW_PC
                    || ($rrow->reviewType == REVIEW_PC
                        && ($rrow->reviewSubmitted
                            || ($rrow->requestedBy != $rrow->contactId
                                && $rrow->requestedBy != 0)))) {
                    $viewable = $user->privChair
                        || ($user->can_view_review_assignment($prow, $rrow)
                            && $user->can_view_review_identity($prow, $rrow));
                    $rs[$rrow->contactId][] = [(int) $rrow->reviewSubmitted, (int) $rrow->reviewRound, $viewable];
                    if ($viewable)
                        $rs_nvis[$rrow->contactId] = get($rs_nvis, $rrow->contactId, 0) + 1;
                }
            }
        }

        if ($user->privChair) {
            $this->r = $rs;
        } else {
            // count number of reviewers whose names we can show
            $rs_isvis = [];
            foreach ($rs as $cid => $r) {
                $nvis = get($rs_nvis, $cid, 0);
                if ($nvis == count($rs[$cid])
                    || ($nvis > 5 && $nvis >= 0.75 * count($rs[$cid]))
                    || $cid == $user->contactId)
                    $rs_isvis[$cid] = true;
            }
            // maybe blind everyone
            if (count($rs_isvis) != count($rs)
                && (count($rs_isvis) >= count($rs) - 4
                    || count($rs_isvis) >= 0.75 * count($rs)))
                $rs_isvis = [$user->contactId => true];
            // accomplish blinding
            $this->r = [];
            foreach ($rs as $cid => $r) {
                if (isset($rs_isvis[$cid])) {
                    $this->r[$cid] = array_filter($r, function ($x) {
                        return $x[2];
                    });
                } else {
                    do {
                        $ncid = "x" . mt_rand(1, 9999);
                    } while (isset($this->r[$ncid]));
                    $this->r[$ncid] = $r;
                }
            }
        }

        // filter visibility information
        foreach ($this->r as &$r) {
            $r = array_map(function ($x) { return [$x[0], $x[1]]; }, $r);
        }
    }

    function json() {
        // find out who is light and who is heavy
        // (light => less than 0.66 * (80th percentile))
        $nass = array();
        foreach ($this->r as $cid => $x)
            $nass[] = count($x);
        sort($nass);
        $heavy_boundary = 0;
        if (count($nass))
            $heavy_boundary = 0.66 * $nass[(int) (0.8 * count($nass))];

        $contacts = $this->conf->pc_members();
        $need_contacts = [];
        foreach ($this->r as $cid => $x)
            if (!isset($contacts[$cid]) && ctype_digit($cid))
                $need_contacts[] = $cid;
        if (count($need_contacts)) {
            $result = $this->conf->q("select firstName, lastName, affiliation, email, contactId, roles, contactTags, disabled from ContactInfo where contactId ?a", $need_contacts);
            while (($row = Contact::fetch($result, $this->conf)))
                $contacts[$row->contactId] = $row;
        }

        $users = array();
        $tags = $this->user->can_view_reviewer_tags();
        foreach ($this->r as $cid => $x)
            if ($cid != "conflicts") {
                $users[$cid] = $u = (object) array();
                $p = get($contacts, $cid);
                if ($p)
                    $u->name = Text::name_text($p);
                if (count($x) < $heavy_boundary)
                    $u->light = true;
                if ($p && $tags && ($t = $p->viewable_color_classes($this->user)))
                    $u->color_classes = $t;
            }

        return (object) array("reviews" => $this->r, "deadlines" => $this->dl, "users" => $users);
    }
}
