<?php
// api_reviewtoken.php -- HotCRP review token API call
// Copyright (c) 2008-2020 Eddie Kohler; see LICENSE.

class ReviewToken_API {
    static function run(Contact $user, Qrequest $qreq) {
        assert(!$user->is_empty());
        $confirm = null;
        if ($qreq->valid_post() && isset($qreq->token)) {
            if (str_starts_with($qreq->token, "[")) {
                $ttexts = json_decode($qreq->token);
            } else {
                $ttexts = preg_split('/[\s,;]+/', $qreq->token);
            }

            $err = $confirm = $tval = [];
            foreach ($ttexts as $t) {
                if ($t === "") {
                } else if (!($token = decode_token($t, "V"))) {
                    $err[] = "Invalid review token “" . htmlspecialchars($t) . "”.";
                } else if ($user->session("rev_token_fail", 0) >= 5) {
                    break;
                } else if (($pid = $user->conf->fetch_ivalue("select paperId from PaperReview where reviewToken=?", $token))) {
                    $tval[] = $token;
                    $confirm[] = "Review token " . htmlspecialchars($t) . " lets you review <a href=\"" . $user->conf->hoturl("paper", "p=$pid") . "\">paper #$pid</a>.";
                } else {
                    $err[] = "Review token " . htmlspecialchars($t) . " hasn’t been assigned.";
                    $nfail = $user->session("rev_token_fail", 0) + 1;
                    $user->save_session("rev_token_fail", $nfail);
                }
            }
            if ($user->session("rev_token_fail", 0) >= 5) {
                $err[] = "Too many failed attempts to use a review token. You need to sign out to try again.";
            }
            if ($err) {
                return ["ok" => false, "error" => $err];
            }

            $cleared = $user->change_review_token(false, false);
            foreach ($tval as $token) {
                $user->change_review_token($token, true);
            }
            if ($cleared && !$tval) {
                $confirm[] = "Review tokens cleared.";
            }
        }
        return [
            "ok" => true,
            "message" => $confirm ? [$confirm, "confirm"] : null,
            "token" => array_map("encode_token", $user->review_tokens())
        ];
    }
}
