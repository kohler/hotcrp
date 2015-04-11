<?php
// src/reviewtimes.php -- HotCRP review form definition page
// HotCRP is Copyright (c) 2006-2015 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

class ReviewTimes {

    private $r = array();
    private $dl = array();

    public function __construct($rounds = null) {
        global $Conf, $Me;
        $qp = "select PaperReview.contactId, timeRequested, reviewSubmitted, reviewRound";
        if (!$Me->privChair)
            $qp .= ", conflictType from PaperReview left join PaperConflict on (PaperConflict.paperId=PaperReview.paperId and PaperConflict.contactId=$Me->contactId)";
        else
            $qp .= ", 0 conflictType from PaperReview";
        $qp .= " where reviewType>" . REVIEW_PC . " or (reviewType=" . REVIEW_PC . " and timeRequested>0 and reviewSubmitted>0)";
        if (!$Me->privChair)
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

        $users = array();
        $pcm = pcMembers();
        foreach ($this->r as $cid => $x)
            if ($cid != "conflicts") {
                $users[$cid] = $u = (object) array();
                if (($p = $pcm[$cid]))
                    $u->name = Text::name_text($p);
                if (count($x) < $heavy_boundary)
                    $u->light = true;
            }

        return (object) array("reviews" => $this->r, "deadlines" => $this->dl, "users" => $users);
    }

}
