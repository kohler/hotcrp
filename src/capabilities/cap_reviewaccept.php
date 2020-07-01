<?php
// cap_reviewaccept.php -- HotCRP review-acceptor capability management
// Copyright (c) 2006-2020 Eddie Kohler; see LICENSE.

class ReviewAccept_Capability {
    private static function make_review_acceptor($user, $at, $pid, $cid, $uf) {
        if ($at && $at >= Conf::$now - 2592000) {
            $user->set_capability("@ra$pid", $cid);
            if ($user->is_activated()) {
                ensure_session();
                CapabilityInfo::set_default_cap_param($uf->name, !!$cid);
            }
        } else if ($cid && $cid != $user->contactId) {
            $user->conf->warnMsg("The review link you followed has expired. Sign in to the site to view or edit reviews.");
        }
    }

    static function apply_review_acceptor(Contact $user, $uf, $isadd) {
        $result = $user->conf->qe("select * from PaperReview where reviewId=?", $uf->match_data[1]);
        $rrow = ReviewInfo::fetch($result, null, $user->conf);
        Dbl::free($result);
        if ($rrow && $rrow->acceptor_is($uf->match_data[2])) {
            self::make_review_acceptor($user, $rrow->acceptor()->at, $rrow->paperId, $isadd ? (int) $rrow->contactId : null, $uf);
            return;
        }

        $result = $user->conf->qe("select * from PaperReviewRefused where `data` is not null and timeRefused>=?", Conf::$now - 604800);
        while (($refusal = $result->fetch_object())) {
            $data = json_decode((string) $refusal->data);
            if ($data
                && isset($data->acceptor)
                && isset($data->acceptor->text)
                && $data->acceptor->text === $uf->match_data[2]) {
                self::make_review_acceptor($user, $data->acceptor->at, $refusal->paperId, $isadd ? (int) $refusal->contactId : null, $uf);
                Dbl::free($result);
                return;
            }
        }
        Dbl::free($result);

        $user->conf->warnMsg("The review link you followed is no longer active. Sign in to the site to view or edit reviews.");
    }
}
