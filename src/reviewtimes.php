<?php
// src/reviewtimes.php -- HotCRP review form definition page
// HotCRP is Copyright (c) 2006-2015 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

class ReviewTimes {

    private $r = array();
    private $dl = array();

    public function __construct($rounds = null) {
        global $Conf;
        $qp = "select contactId, timeRequested, reviewSubmitted, reviewRound
                from PaperReview where timeRequested>0 and reviewType>=" . REVIEW_PC;
        $qa = array();
        if ($rounds) {
            $qp .= " and reviewRound ?a";
            $qa[] = $rounds;
        }
        $result = Dbl::qe_apply($qp, $qa);
        while (($row = edb_row($result)))
            $this->r[(int) $row[0]][] = array((int) $row[1], (int) $row[2], (int) $row[3]);
        Dbl::free($result);
        foreach ($Conf->round_list() as $rn => $r) {
            $dl = $Conf->review_deadline($rn, true, false);
            $this->dl[$rn] = +$Conf->setting($dl);
        }
    }

    public function json() {
        $users = array();
        $pcm = pcMembers();
        foreach ($this->r as $cid => $x)
            if (($p = $pcm[$cid]))
                $users[$cid] = Text::name_text($p);
        return (object) array("reviews" => $this->r, "deadlines" => $this->dl, "users" => $users);
    }

}
