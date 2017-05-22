<?php
// paperactions.php -- HotCRP helpers for common paper actions
// HotCRP is Copyright (c) 2008-2017 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

class PaperActions {
    static function save_review_preferences($prefarray) {
        global $Conf;
        $q = array();
        foreach ($prefarray as $p)
            $q[] = "($p[0],$p[1],$p[2]," . ($p[3] === null ? "NULL" : $p[3]) . ")";
        if (count($q))
            return Dbl::qe_raw("insert into PaperReviewPreference (paperId,contactId,preference,expertise) values " . join(",", $q) . " on duplicate key update preference=values(preference), expertise=values(expertise)");
        return true;
    }
}
