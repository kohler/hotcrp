<?php
// src/reviewtimes.php -- HotCRP review form definition page
// HotCRP is Copyright (c) 2006-2017 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

class ReviewTimes {
    private $contact;
    private $r = array();
    private $dl = array();

    public function __construct($user, $rounds = null) {
        global $Conf;
        $this->contact = $user;
        $qp = "select PaperReview.contactId, timeRequested, reviewSubmitted, reviewRound";
        if (!$this->contact->privChair)
            $qp .= ", conflictType from PaperReview left join PaperConflict on (PaperConflict.paperId=PaperReview.paperId and PaperConflict.contactId=" . $this->contact->contactId . ")";
        else
            $qp .= ", 0 conflictType from PaperReview";
        $qp .= " where reviewType>" . REVIEW_PC . " or (reviewType=" . REVIEW_PC . " and timeRequested>0 and reviewSubmitted>0)";
        if (!$this->contact->privChair)
            $qp .= " and coalesce(conflictType,0)=0";
        $qa = array();
        if ($rounds) {
            $qp .= " and reviewRound ?a";
            $qa[] = $rounds;
        }
        $result = Dbl::qe_apply($qp, $qa);
        while (($row = edb_row($result))) {
            $cid = (int) $row[4] ? "conflicts" : (int) $row[0];
            $this->r[$cid][] = array((int) $row[1], (int) $row[2], (int) $row[3]);
        }
        Dbl::free($result);
        foreach ($Conf->round_list() as $rn => $r) {
            $dl = $Conf->review_deadline($rn, true, false);
            $this->dl[$rn] = +$Conf->setting($dl);
        }

        // maybe hide who's who
        if (!$this->contact->can_view_aggregated_review_identity()) {
            $who = $r = array();
            foreach ($this->r as $cid => $data)
                if ($cid === "conflicts" || $cid == $this->contact->contactId)
                    $r[$cid] = $data;
                else {
                    do {
                        $ncid = mt_rand(1, 10 * count(pcMembers()));
                    } while (isset($who[$ncid]));
                    $who[$ncid] = true;
                    $r["x" . $ncid] = $data;
                }
            $this->r = $r;
        }
    }

    public function json() {
        // find out who is light and who is heavy
        // (light => less than 0.66 * (80th percentile))
        $nass = array();
        foreach ($this->r as $cid => $x)
            $nass[] = count($x);
        sort($nass);
        $heavy_boundary = 0;
        if (count($nass))
            $heavy_boundary = 0.66 * $nass[(int) (0.8 * count($nass))];

        $contacts = pcMembers();
        $need_contacts = [];
        foreach ($this->r as $cid => $x)
            if (!isset($contacts[$cid]) && ctype_digit($cid))
                $need_contacts[] = $cid;
        if (count($need_contacts)) {
            $result = Dbl::q("select firstName, lastName, affiliation, email, contactId, roles, contactTags, disabled from ContactInfo where contactId ?a", $need_contacts);
            while ($result && ($row = Contact::fetch($result)))
                $contacts[$row->contactId] = $row;
        }

        $users = array();
        $tags = $this->contact->can_view_reviewer_tags();
        foreach ($this->r as $cid => $x)
            if ($cid != "conflicts") {
                $users[$cid] = $u = (object) array();
                $p = get($contacts, $cid);
                if ($p)
                    $u->name = Text::name_text($p);
                if (count($x) < $heavy_boundary)
                    $u->light = true;
                if ($p && $tags && ($t = $p->viewable_color_classes($this->contact)))
                    $u->color_classes = $t;
            }

        return (object) array("reviews" => $this->r, "deadlines" => $this->dl, "users" => $users);
    }
}
