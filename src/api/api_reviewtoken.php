<?php
// api_reviewtoken.php -- HotCRP review token API call
// Copyright (c) 2008-2022 Eddie Kohler; see LICENSE.

class ReviewToken_API {
    static function run(Contact $user, Qrequest $qreq) {
        assert(!$user->is_empty());
        $ml = [];
        if ($qreq->valid_post() && isset($qreq->token)) {
            if (str_starts_with($qreq->token, "[")) {
                $ttexts = json_decode($qreq->token);
            } else {
                $ttexts = preg_split('/[\s,;]+/', $qreq->token);
            }

            $tval = [];
            foreach ($ttexts as $t) {
                if ($t === "") {
                } else if (!($token = decode_token($t, "V"))) {
                    $ml[] = MessageItem::error("<0>Invalid review token ‘{$t}’");
                } else if ($user->session("rev_token_fail", 0) >= 5) {
                    break;
                } else if (($pid = $user->conf->fetch_ivalue("select paperId from PaperReview where reviewToken=?", $token))) {
                    $tval[] = $token;
                    $ml[] = MessageItem::success("<5>Review token ‘" . htmlspecialchars($t) . "’ lets you review <a href=\"" . $user->conf->hoturl("paper", "p=$pid") . "\">submission #{$pid}</a>");
                } else {
                    $ml[] = MessageItem::error("<0>Review token ‘{$t}’ not found");
                    $nfail = $user->session("rev_token_fail", 0) + 1;
                    $user->save_session("rev_token_fail", $nfail);
                }
            }
            if ($user->session("rev_token_fail", 0) >= 5) {
                $ml[] = MessageItem::error("<0>Too many failed attempts to use a review token. You need to sign out to try again");
            }
            if (MessageSet::list_status($ml) >= 2) {
                return ["ok" => false, "message_list" => $ml];
            }

            $cleared = $user->change_review_token(false, false);
            foreach ($tval as $token) {
                $user->change_review_token($token, true);
            }
            if ($cleared && !$tval) {
                $ml[] = MessageItem::success("<0>Review tokens cleared");
            }
        }
        return [
            "ok" => true,
            "message_list" => $ml,
            "token" => array_map("encode_token", $user->review_tokens())
        ];
    }
}
