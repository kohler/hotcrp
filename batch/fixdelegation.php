<?php
require_once(dirname(__DIR__) . "/src/init.php");

$arg = Getopt::rest($argv, "hn:q", array("help", "name:"));
if (isset($arg["h"]) || isset($arg["help"])
    || count($arg["_"]) > 0) {
    fwrite(STDOUT, "Usage: php batch/fixdelegation.php [-n CONFID]\n");
    exit(0);
}

function fix_one_delegation($conf) {
    $row = Dbl::fetch_first_row($conf->dblink,
        "select r.paperId, r.contactId, u.email, q.ct, q.cs, r.reviewNeedsSubmit
            from PaperReview r
            left join (select paperId, requestedBy, count(reviewId) ct, count(reviewSubmitted) cs
                       from PaperReview where reviewType>0 and reviewType<" . REVIEW_SECONDARY . "
                       group by paperId, requestedBy) q
                on (q.paperId=r.paperId and q.requestedBy=r.contactId)
            left join ContactInfo u on (u.contactId=r.contactId)
            where r.reviewType=" . REVIEW_SECONDARY . " and r.reviewSubmitted is null
            and if(coalesce(q.ct,0)=0,1,if(q.cs=0,-1,0))!=r.reviewNeedsSubmit
            limit 1");
    if (!$row) {
        return false;
    }
    $pid = (int) $row[0];
    $req_cid = (int) $row[1];
    $req_email = $row[2];
    $prow = $conf->paper_by_id($pid);
    fwrite(STDERR, "Problem: #$pid review by $req_email\n");
    fwrite(STDERR, "  reviewNeedsSubmit $row[5], " . plural($row[3] ? : 0, "delegate") . ", " . plural($row[4] ? : 0, "submitted delegate") . "\n");

    $result = $conf->qe("select l.* from ActionLog l where paperId=? order by logId asc", $pid);
    $proposals = $confirmations = [];
    while (($row = $result->fetch_object())) {
        if ($row->contactId == $req_cid
            && preg_match('/\ALogged proposal for (\S+) to review/', $row->action, $m)
            && ($xuser = $conf->cached_user_by_email($m[1]))) {
            $proposals[$xuser->contactId] = true;
        } else if (preg_match('/\AAdded External review by (\S+)/', $row->action, $m)
                   && ($pc = $conf->pc_member_by_email($m[1]))
                   && $pc->can_administer($prow)) {
            $confirmations[$row->contactId] = $pc->contactId;
        }
    }
    Dbl::free($result);

    foreach ($proposals as $xid => $x) {
        if (isset($confirmations[$xid])) {
            $result1 = $conf->qe("update PaperReview set requestedBy=? where paperId=? and contactId=? and requestedBy=?", $req_cid, $pid, $xid, $confirmations[$xid]);
            $result2 = $conf->qe("update PaperReview r, PaperReview q set r.reviewNeedsSubmit=0 where r.paperId=? and r.contactId=? and q.paperId=? and q.contactId=? and q.reviewSubmitted is not null", $pid, $req_cid, $pid, $xid);
            if ($result1->affected_rows || $result2->affected_rows) {
                return true;
            }
        }
    }

    error_log("Failed to resolve paper #$pid review by $req_email");
    return false;
}

while (fix_one_delegation($Conf)) {
    /* do nothing */;
}
