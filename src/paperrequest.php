<?php
// paperrequest.php -- HotCRP helper class for parsing paper requests
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class PaperRequest {
    /** @var PaperInfo */
    public $prow;
    /** @var ?ReviewInfo */
    public $rrow;

    /** @param bool $review */
    function __construct(Contact $user, Qrequest $qreq, $review) {
        $this->normalize($user->conf, $qreq, $review);
        $this->prow = $this->find_paper($user->conf, $user, $qreq);
        if ($review && $this->prow->paperId > 0) {
            $this->rrow = $this->find_review($user, $qreq);
        }
    }

    /** @return PaperRequest|Redirection|PermissionProblem */
    static function make(Contact $user, Qrequest $qreq, $review) {
        try {
            return new PaperRequest($user, $qreq, $review);
        } catch (PermissionProblem $perm) {
            return $perm;
        } catch (Redirection $redir) {
            return $redir;
        }
    }

    static function simple_qreq(Qrequest $qreq) {
        return ($qreq->is_get() || $qreq->is_head())
            && !array_diff($qreq->keys(), ["p", "paperId", "m", "mode", "forceShow", "t", "q", "r", "reviewId", "cap", "actas", "accept", "decline"]);
    }

    private function normalize(Conf $conf, Qrequest $qreq, $review) {
        // standardize on paperId, reviewId, commentId
        if (!isset($qreq->paperId) && isset($qreq->p)) {
            $qreq->paperId = $qreq->p;
        }
        if (!isset($qreq->reviewId) && isset($qreq->r)) {
            $qreq->reviewId = $qreq->r;
        }
        if (!isset($qreq->commentId) && isset($qreq->c)) {
            $qreq->commentId = $qreq->c;
        }
        // read paperId, reviewId from path
        if (($pc = $qreq->path_component(0)) !== null && $pc !== "") {
            if (preg_match('/\A(\d+|new\z)(|[A-Z]+|r[1-9]\d*|rnew)\z/', $pc, $m)) {
                $qreq->paperId = $qreq->paperId ?? $m[1];
                if ($qreq->paperId !== $m[1]) {
                    throw new Redirection($conf->selfurl($qreq));
                }
                if ($m[2] !== "" && $review) {
                    $qreq->reviewId = $qreq->reviewId ?? $pc;
                    if ($qreq->reviewId !== $pc) {
                        throw new Redirection($conf->selfurl($qreq));
                    }
                } else if ($m[2] !== "") {
                    throw new Redirection($conf->selfurl($qreq));
                }
            }
        }
        // read paperId from reviewId
        if (!isset($qreq->paperId)
            && isset($qreq->reviewId)
            && preg_match('/\A(\d+)(?:[A-Z]+|r[1-9]\d*|rnew)\z/', $qreq->reviewId, $m)) {
            $qreq->paperId = $m[1];
        }
        // clear query
        if (isset($qreq->paperId) || isset($qreq->reviewId)) {
            unset($qreq->q);
        }
        // check format
        if (isset($qreq->paperId)
            && !ctype_digit($qreq->paperId)
            && $qreq->paperId !== "new") {
            throw new PermissionProblem($conf, ["invalidId" => "paper", "paperId" => $qreq->paperId]);
        } else if (isset($qreq->reviewId)
                   && !preg_match('/\A\d+(?:|[A-Z]+|r[1-9]\d*|rnew)\z/', $qreq->reviewId)) {
            throw new PermissionProblem($conf, ["invalidId" => "review", "reviewId" => $qreq->reviewId]);
        }
    }

    /** @param Conf $conf
     * @param Contact $user
     * @param Qrequest $qreq
     * @return int */
    private function find_pid($conf, $user, $qreq) {
        // check paperId
        if (($pid = $qreq->paperId) !== null) {
            if ($pid === "new") {
                return 0;
            } else if (ctype_digit($pid) && ($p = intval($pid)) > 0) {
                if ("{$p}" === $pid) {
                    return $p;
                } else if (str_pad("{$p}", strlen($pid), "0", STR_PAD_LEFT) === $pid) {
                    throw new Redirection($conf->selfurl($qreq, ["p" => $p]));
                }
            }
            throw new PermissionProblem($conf, ["invalidId" => "paper", "paperId" => $pid]);
        }
        // check reviewId
        if (($rid = $qreq->reviewId) !== null) {
            assert(ctype_digit($rid));
            if (($p = $conf->fetch_ivalue("select paperId from PaperReview where reviewId=?", $rid)) > 0) {
                return $p;
            } else {
                throw new PermissionProblem($conf, ["invalidId" => "review", "reviewId" => $qreq->reviewId]);
            }
        }
        // give up on POST, empty user
        if (!self::simple_qreq($qreq) || $user->is_empty()) {
            throw new PermissionProblem($conf, ["missingId" => "paper"]);
        }
        // check query
        if (($q = $qreq->q) !== null) {
            if (preg_match('/\A\s*#?(\d+)\s*\z/', $q, $m)) {
                throw new Redirection($conf->selfurl($qreq, ["q" => null, "p" => $m[1]]));
            } else if ($q === "" || $q === "(All)") {
                throw new Redirection($conf->hoturl("search", ["q" => "", "t" => $qreq->t]));
            } else {
                $search = new PaperSearch($user, ["q" => $q, "t" => $qreq->t]);
                $ps = $search->paper_ids();
                if (count($ps) === 1) {
                    // DISABLED: check if the paper is in the current list
                    $list = $search->session_list_object();
                    $list->set_cookie($user);
                    throw new Redirection($conf->selfurl($qreq, ["q" => null, "p" => $ps[0]]));
                } else {
                    throw new Redirection($conf->hoturl("search", ["q" => $q, "t" => $qreq->t]));
                }
            }
        }
        // given no direction, find any paper that makes sense
        $search = new PaperSearch($user, ["q" => ""]);
        $ps = $search->paper_ids();
        if (empty($ps)) {
            throw new PermissionProblem($conf, ["missingId" => "paper"]);
        } else {
            throw new Redirection($conf->selfurl($qreq, ["p" => $ps[0]]));
        }
    }

    /** @param Conf $conf
     * @param Qrequest $qreq
     * @param int $pid
     * @return Redirection */
    private function signin_redirection($conf, $qreq, $pid) {
        return new Redirection($conf->hoturl("signin", ["redirect" => $conf->selfurl($qreq, ["p" => $pid ? : "new"], Conf::HOTURL_SITEREL | Conf::HOTURL_RAW)]));
    }

    /** @param ?PaperInfo $prow
     * @param Contact $user
     * @param Qrequest $qreq
     * @return bool */
    static private function check_prow($prow, $user, $qreq) {
        return $prow
            && $user->can_view_paper($prow, false)
            && (isset($qreq->paperId)
                || !isset($qreq->reviewId)
                || $user->privChair
                || (($rrow = $prow->review_by_ordinal_id($qreq->reviewId))
                    && $user->can_view_review_assignment($prow, $rrow)));
    }

    /** @param ?PaperInfo $prow
     * @param Contact $user
     * @param Qrequest $qreq */
    static private function try_other_user($prow, $user, $qreq) {
        if ($prow
            && ($qreq->method() === "GET" || $qreq->method() === "HEAD")
            && count(Contact::session_users()) > 1
            && self::other_user_redirectable()) {
            foreach (Contact::session_users() as $email) {
                $user->conf->prefetch_user_by_email($email);
            }
            foreach (Contact::session_users() as $i => $email) {
                if (strcasecmp($user->email, $email) !== 0
                    && ($u = $user->conf->cached_user_by_email($email))
                    && self::check_prow($prow, $u, $qreq)) {
                    $nav = Navigation::get();
                    throw new Redirection($user->conf->make_absolute_site("u/{$i}/{$nav->raw_page}{$nav->path}{$nav->query}"));
                }
            }
        }
    }

    /** @return bool */
    static private function other_user_redirectable() {
        $page = Navigation::self();
        foreach ($_COOKIE as $k => $v) {
            if (str_starts_with($k, "hc-uredirect-") && $v === $page)
                return true;
        }
        return false;
    }

    /** @param Conf $conf
     * @param Contact $user
     * @param Qrequest $qreq
     * @return PaperInfo */
    function find_paper($conf, $user, $qreq) {
        $pid = $this->find_pid($conf, $user, $qreq);
        if ($pid === 0) {
            if ($user->has_email()) {
                return PaperInfo::make_new($user);
            } else {
                throw $this->signin_redirection($conf, $qreq, 0);
            }
        } else {
            $options = ["topics" => true, "options" => true];
            if ($user->privChair
                || ($user->isPC && $conf->timePCReviewPreferences())) {
                $options["reviewerPreference"] = true;
            }
            $prow = $user->paper_by_id($pid, $options);
            if (!self::check_prow($prow, $user, $qreq)) {
                self::try_other_user($prow, $user, $qreq);
                if (!isset($qreq->paperId) && isset($qreq->reviewId)) {
                    throw new PermissionProblem($conf, ["missingId" => "paper"]);
                } else if (!$user->has_email()) {
                    throw $this->signin_redirection($conf, $qreq, $pid);
                } else {
                    throw $user->perm_view_paper($prow, false, $pid);
                }
            } else if (!isset($qreq->paperId) && isset($qreq->reviewId)) {
                throw new Redirection($conf->selfurl($qreq, ["p" => $prow->paperId]));
            }
            return $prow;
        }
    }

    /** @param Contact $user
     * @param Qrequest $qreq
     * @return ?ReviewInfo */
    function find_review($user, $qreq) {
        if (isset($qreq->reviewId) && str_ends_with($qreq->reviewId, "new")) {
            return null;
        } else if (isset($qreq->reviewId)) {
            $rrow = $this->prow->review_by_ordinal_id($qreq->reviewId);
            if (!$rrow) {
                if (($racid = $user->capability("@ra{$this->prow->paperId}"))) {
                    // XXX @ra nonsense
                    return null;
                } else {
                    throw new PermissionProblem($user->conf, ["invalidId" => "review"]);
                }
            } else if (($whynot = $user->perm_view_review($this->prow, $rrow))) {
                $whynot2 = $user->perm_view_review($this->prow, null);
                throw $whynot2 ?? $whynot;
            }
            return $rrow;
        } else if (($racid = $user->capability("@ra{$this->prow->paperId}"))) {
            return $this->prow->review_by_user($racid);
        } else {
            return null;
        }
    }
}
