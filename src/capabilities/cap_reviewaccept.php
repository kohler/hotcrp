<?php
// cap_reviewaccept.php -- HotCRP review-acceptor capability management
// Copyright (c) 2006-2023 Eddie Kohler; see LICENSE.

class ReviewAccept_Capability {
    /** @param ReviewInfo $rrow
     * @param bool $create
     * @return ?TokenInfo */
    static function make($rrow, $create) {
        if ($rrow->reviewId <= 0) {
            return null;
        }

        $result = $rrow->conf->qe("select * from Capability where salt>=? and salt<?",
            "hcra{$rrow->reviewId}@", "hcra{$rrow->reviewId}~");
        $tok = null;
        while (($xtok = TokenInfo::fetch($result, $rrow->conf))) {
            if ($xtok->capabilityType === TokenInfo::REVIEWACCEPT
                && $xtok->reviewId === $rrow->reviewId
                && (!$tok || $xtok->is_active()))
                $tok = $xtok;
        }
        Dbl::free($result);

        if (!$tok && $create) {
            $tok = new TokenInfo($rrow->conf, TokenInfo::REVIEWACCEPT);
            $tok->paperId = $rrow->paperId;
            $tok->reviewId = $rrow->reviewId;
            $tok->contactId = $rrow->contactId;
            $tok->set_invalid_after(2592000); /* 30 days */
            $tok->set_expires_after(5184000); /* 60 days */
            $tok->set_token_pattern("hcra{$rrow->reviewId}[16]");
            if (!$tok->create()) {
                return null;
            }
        }

        return $tok;
    }

    static function apply_review_acceptor(Contact $user, $uf) {
        $rrowid = (int) $uf->match_data[1];
        if (($tok = TokenInfo::find($uf->name, $user->conf))
            && $tok->capabilityType === TokenInfo::REVIEWACCEPT
            && $tok->is_active()) {
            $user->set_capability("@ra{$tok->paperId}", $tok->contactId);
            $user->set_default_cap_param($uf->name, true);
            if ($tok->timeUsed === 0) {
                $user->conf->qe("update Capability set timeInvalid=? where salt>=? and salt<? and salt!=? and (timeInvalid=0 or timeInvalid>?)",
                    Conf::$now, "hcra{$rrowid}@", "hcra{$rrowid}~", $uf->name, Conf::$now);
            }
            $tok->update_use()
                ->extend_validity(7776000) /* 90 days */
                ->extend_expiry(15552000) /* 180 days */
                ->update();
        } else {
            // Token not found, but users often follow links after they expire.
            // Do not report an error if the logged-in user corresponds to the review.
            $rrow = $refused = null;
            if ($user->contactId) {
                $result = $user->conf->qe("select * from PaperReview where reviewId=?", $rrowid);
                $rrow = ReviewInfo::fetch($result, null, $user->conf);
                Dbl::free($result);
            }
            if (!$rrow && $user->contactId) {
                $result = $user->conf->qe("select * from PaperReviewRefused where refusedReviewId=? order by timeRefused desc limit 1", $rrowid);
                $refused = ReviewRefusalInfo::fetch($result, $user->conf);
                Dbl::free($result);
            }
            if ((!$rrow || $rrow->contactId !== $user->contactId)
                && (!$refused || $refused->contactId !== $user->contactId)) {
                $t = "<5>The review link you followed to get here is invalid or expired.";
                if (!$user->contactId) {
                    $t .= " <a href=\"" . $user->conf->hoturl("signin") . "\">Sign in to the site</a> to view or edit your reviews.";
                }
                $user->conf->feedback_msg([
                    new MessageItem(null, "<0>Bad review link", MessageSet::ERROR),
                    new MessageItem(null, $t, MessageSet::INFORM)
                ]);
                error_log("bad review acceptor {$uf->name}: "
                          . (!$tok || $tok->capabilityType !== TokenInfo::REVIEWACCEPT
                             ? "not found"
                             : "created {$tok->timeCreated}, used {$tok->timeUsed}, invalid {$tok->timeInvalid}, expired {$tok->timeExpires}, user {$tok->contactId}"));
            }
        }
    }

    static function apply_old_review_acceptor(Contact $user, $uf) {
        $uf->name = "hc" . $uf->name;
        return self::apply_review_acceptor($user, $uf);
    }
}
