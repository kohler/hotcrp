<?php
// api/api_follow.php -- HotCRP paper-related API calls
// Copyright (c) 2008-2023 Eddie Kohler; see LICENSE.

class Follow_API {
    /** @param ?PaperInfo $prow */
    static function run(Contact $user, $qreq, $prow) {
        $reviewer = APIHelpers::parse_reviewer_for($qreq->u ?? $qreq->reviewer, $user, $prow);
        $following = friendly_boolean($qreq->following);
        if ($following === null) {
            return JsonResult::make_parameter_error("following", "Expected boolean");
        }
        $bits = Contact::WATCH_REVIEW_EXPLICIT | ($following ? Contact::WATCH_REVIEW : 0);
        $user->conf->qe("insert into PaperWatch set paperId=?, contactId=?, watch=? on duplicate key update watch=(watch&~?)|?",
            $prow->paperId, $reviewer->contactId, $bits,
            Contact::WATCH_REVIEW_EXPLICIT | Contact::WATCH_REVIEW, $bits);
        return ["ok" => true, "following" => $following];
    }
}
