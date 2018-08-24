<?php
// src/partials/p_reviewtoken.php -- HotCRP review token handler
// Copyright (c) 2006-2018 Eddie Kohler; see LICENSE.

class ReviewToken_Partial {
    static function request(Contact $user, Qrequest $qreq) {
        assert(!$user->is_empty() && $qreq->post_ok() && $qreq->token);

        $cleared = $user->change_review_token(false, false);
        $tokeninfo = array();
        foreach (preg_split('/\s+/', $qreq->token) as $x)
            if ($x == "")
                /* no complaints */;
            else if (!($token = decode_token($x, "V")))
                Conf::msg_error("Invalid review token &ldquo;" . htmlspecialchars($x) . "&rdquo;.  Check your typing and try again.");
            else if ($user->conf->session("rev_token_fail", 0) >= 5)
                Conf::msg_error("Too many failed attempts to use a review token.  <a href='" . hoturl("index", "signout=1") . "'>Sign out</a> and in to try again.");
            else {
                $result = Dbl::qe("select paperId from PaperReview where reviewToken=" . $token);
                if (($row = edb_row($result))) {
                    $tokeninfo[] = "Review token “" . htmlspecialchars($x) . "” lets you review <a href='" . hoturl("paper", "p=$row[0]") . "'>paper #" . $row[0] . "</a>.";
                    $user->change_review_token($token, true);
                } else {
                    Conf::msg_error("Review token “" . htmlspecialchars($x) . "” hasn’t been assigned.");
                    $nfail = $user->conf->session("rev_token_fail", 0) + 1;
                    $user->conf->save_session("rev_token_fail", $nfail);
                }
            }
        if ($cleared && !count($tokeninfo))
            $tokeninfo[] = "Review tokens cleared.";
        if (!empty($tokeninfo))
            $user->conf->infoMsg(join("<br />\n", $tokeninfo));
        SelfHref::redirect($qreq);
    }
}
