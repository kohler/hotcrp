<?php
// autoassigners/aa_prefconflict.php -- HotCRP helper classes for autoassignment
// Copyright (c) 2006-2025 Eddie Kohler; see LICENSE.

class PrefConflict_Autoassigner extends Autoassigner {
    function __construct(Contact $user) {
        parent::__construct($user);
        $this->set_assignment_action("conflict");
    }

    function configure() {
    }

    /** @param bool $exists_submitted
     * @return Dbl_Result */
    static function query_result(Conf $conf, $exists_submitted) {
        $qsuffix = $exists_submitted ? " and P.timeSubmitted>0 limit 1" : "";
        return $conf->ql_raw("select PRP.paperId, PRP.contactId, PRP.preference
                from PaperReviewPreference PRP
                join ContactInfo c on (c.contactId=PRP.contactId and c.roles!=0 and (c.roles&" . Contact::ROLE_PC . ")!=0)
                join Paper P on (P.paperId=PRP.paperId)
                left join PaperConflict PC on (PC.paperId=PRP.paperId and PC.contactId=PRP.contactId)
                where PRP.preference<=-100 and coalesce(PC.conflictType,0)<=" . CONFLICT_MAXUNCONFLICTED . "
                  and P.timeWithdrawn<=0" . $qsuffix);
    }

    function run() {
        $result = self::query_result($this->conf, false);
        while (($row = $result->fetch_row())) {
            $this->assign1((int) $row[1], (int) $row[0]);
        }
        Dbl::free($result);
    }
}
