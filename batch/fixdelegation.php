<?php
// fixdelegation.php -- HotCRP paper export script
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

if (realpath($_SERVER["PHP_SELF"]) === __FILE__) {
    require_once(dirname(__DIR__) . "/src/init.php");
    exit(FixDelegation_Batch::make_args($argv)->run());
}

class FixDelegation_Batch {
    /** @var Conf */
    public $conf;

    function __construct(Conf $conf) {
        $this->conf = $conf;
    }

    private function fix_one_delegation() {
        $drow = Dbl::fetch_first_object($this->conf->dblink,
            "select r.paperId, r.reviewId, r.contactId, u.email, q.ct, q.cs, r.reviewNeedsSubmit
                from PaperReview r
                left join (select paperId, requestedBy, count(reviewId) ct, count(reviewSubmitted) cs
                           from PaperReview where reviewType>0 and reviewType<" . REVIEW_SECONDARY . "
                           group by paperId, requestedBy) q
                    on (q.paperId=r.paperId and q.requestedBy=r.contactId)
                left join ContactInfo u on (u.contactId=r.contactId)
                where r.reviewType=" . REVIEW_SECONDARY . " and r.reviewSubmitted is null
                and if(coalesce(q.ct,0)=0,1,if(q.cs=0,-1,0))!=r.reviewNeedsSubmit
                limit 1");
        if (!$drow) {
            return false;
        }
        $req_cid = (int) $drow->contactId;
        $prow = $this->conf->paper_by_id((int) $drow->paperId);
        fwrite(STDERR, "Problem: #{$drow->paperId} review {$drow->reviewId} by {$drow->email}\n");
        $xrns = (int) ($drow->ct ?? 0) === 0 ? 1 : ((int) ($drow->cs ?? 0) === 0 ? -1 : 0);
        fwrite(STDERR, "  reviewNeedsSubmit {$drow->reviewNeedsSubmit}, " . plural((int) ($drow->ct ?? 0), "delegate")
                       . ", " . plural((int) ($drow->cs ?? 0), "submitted delegate")
                       . ", expected {$xrns}\n");

        $result = $this->conf->qe("select l.* from ActionLog l where paperId=? order by logId asc", $drow->paperId);
        $proposals = $confirmations = [];
        while (($row = $result->fetch_object())) {
            if ($row->contactId == $req_cid
                && preg_match('/\ALogged proposal for (\S+) to review/', $row->action, $m)
                && ($xuser = $this->conf->cached_user_by_email($m[1]))) {
                $proposals[$xuser->contactId] = true;
            } else if (preg_match('/\AAdded External review by (\S+)/', $row->action, $m)
                       && ($pc = $this->conf->pc_member_by_email($m[1]))
                       && $pc->can_administer($prow)) {
                $confirmations[$row->contactId] = $pc->contactId;
            }
        }
        Dbl::free($result);

        foreach ($proposals as $xid => $x) {
            if (isset($confirmations[$xid])) {
                $result1 = $this->conf->qe("update PaperReview set requestedBy=? where paperId=? and contactId=? and requestedBy=?", $req_cid, $drow->paperId, $xid, $confirmations[$xid]);
                $result2 = $this->conf->qe("update PaperReview r, PaperReview q set r.reviewNeedsSubmit=0 where r.paperId=? and r.contactId=? and q.paperId=? and q.contactId=? and q.reviewSubmitted is not null", $drow->paperId, $req_cid, $drow->paperId, $xid);
                if ($result1->affected_rows || $result2->affected_rows) {
                    return true;
                }
            }
        }

        error_log("Failed to resolve paper #{$drow->paperId} review {$drow->reviewId} by {$drow->email}");
        return false;
    }

    /** @return int */
    function run() {
        while ($this->fix_one_delegation()) {
        }
        return 0;
    }

    /** @param list<string> $argv
     * @return FixDelegation_Batch */
    static function make_args($argv) {
        $arg = (new Getopt)->long(
            "name:,n: !",
            "config: !",
            "help,h !"
        )->description("Attempt to resolve HotCRP database problems with secondary review delegation.
Usage: php batch/fixdelegation.php")
         ->helpopt("help")
         ->maxarg(0)
         ->parse($argv);

        $conf = initialize_conf($arg["config"] ?? null, $arg["name"] ?? null);
        return new FixDelegation_Batch($conf);
    }
}
