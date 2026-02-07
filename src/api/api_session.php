<?php
// api_session.php -- HotCRP session API calls
// Copyright (c) 2008-2025 Eddie Kohler; see LICENSE.

class Session_API {
    static private function session_result(Contact $user, Qrequest $qreq, $ok) {
        $si = ["postvalue" => $qreq->post_value()];
        if ($user->email) {
            $si["email"] = $user->email;
        }
        if ($user->contactId) {
            $si["uid"] = $user->contactId;
        }
        return [
            "ok" => $ok,
            "sessioninfo" => $si
        ];
    }

    static function getsession(Contact $user, Qrequest $qreq) {
        // create session cookie
        $qreq->open_session();

        // SECURITY NOTE: This API’s purpose is to allow browser JS to
        // update its CSRF token. It also may be called by unauthenticated
        // users (`auth: false`), which enables CORS. We do not want to
        // expose user information or the CSRF token to other origins!
        $sfs = $qreq->raw_header("HTTP_SEC_FETCH_SITE");
        if ($sfs === null) {
            $sfs = $qreq->raw_header("HTTP_ORIGIN") === null ? "same-origin" : "cross-site";
        }
        if ($sfs !== "same-origin" && $sfs !== "none") {
            return ["ok" => true];
        }

        return self::session_result($user, $qreq, true);
    }

    /** @param Qrequest $qreq
     * @param string $v
     * @return bool */
    static function change_session($qreq, $v) {
        $qreq->open_session();
        $ok = true;
        $view = [];
        preg_match_all('/(?:\A|\s)(foldpaper|foldpscollab|foldhomeactivity|(?:pl|pf|ul)display|(?:|ul)scoresort)(|\.[^=]*)(=\S*|)(?=\s|\z)/', $v, $ms, PREG_SET_ORDER);
        foreach ($ms as $m) {
            $unfold = intval(substr($m[3], 1) ? : "0") === 0;
            if ($m[1] === "foldpaper" && $m[2] !== "") {
                $x = $qreq->csession($m[1]) ?? [];
                if (is_string($x)) {
                    $x = explode(" ", $x);
                }
                $x = array_diff($x, [substr($m[2], 1)]);
                if ($unfold) {
                    $x[] = substr($m[2], 1);
                }
                $v = join(" ", $x);
                if ($v === "") {
                    $qreq->unset_csession("foldpaper");
                } else if (substr_count($v, " ") === count($x) - 1) {
                    $qreq->set_csession("foldpaper", $v);
                } else {
                    $qreq->set_csession("foldpaper", $x);
                }
                // XXX backwards compat
                $qreq->unset_csession("foldpapera");
                $qreq->unset_csession("foldpaperb");
                $qreq->unset_csession("foldpaperp");
                $qreq->unset_csession("foldpapert");
            } else if ($m[1] === "scoresort" && $m[2] === "" && $m[3] !== "") {
                $ss = ScoreInfo::parse_score_sort(substr($m[3], 1));
                if ($ss !== null) {
                    $view["pl"][] = "sort:[score {$ss}]";
                }
            } else if ($m[1] === "ulscoresort" && $m[2] === "" && $m[3] !== "") {
                $want = ScoreInfo::parse_score_sort(substr($m[3], 1));
                if ($want === "variance" || $want === "maxmin") {
                    $qreq->set_csession("ulscoresort", $want);
                } else if ($want === "average") {
                    $qreq->unset_csession("ulscoresort");
                }
            } else if (($m[1] === "pldisplay" || $m[1] === "pfdisplay")
                       && $m[2] !== "") {
                $view[substr($m[1], 0, 2)][] = ($unfold ? "show:" : "hide:") . substr($m[2], 1);
            } else if ($m[1] === "uldisplay"
                       && preg_match('/\A\.[-a-zA-Z0-9_:]+\z/', $m[2])) {
                self::change_uldisplay($qreq, [substr($m[2], 1) => $unfold]);
            } else if (substr($m[1], 0, 4) === "fold" && $m[2] === "") {
                if ($unfold) {
                    $qreq->set_csession($m[1], 0);
                } else {
                    $qreq->unset_csession($m[1]);
                }
            } else {
                $ok = false;
            }
        }
        foreach ($view as $report => $viewlist) {
            self::parse_view($qreq, $report, join(" ", $viewlist));
        }
        return $ok;
    }

    /** @param Qrequest $qreq
     * @return array{ok:bool,sessioninfo:array} */
    static function setsession(Contact $user, $qreq) {
        // NB This is for POSTs and requires authentication.
        assert($user === $qreq->user());
        $qreq->open_session();
        $ok = self::change_session($qreq, $qreq->v);
        return self::session_result($user, $qreq, $ok);
    }

    /** @param string $report
     * @param string|Qrequest $view */
    static function parse_view(Qrequest $qreq, $report, $view) {
        $search = new PaperSearch($qreq->user(), "NONE");
        $pl = new PaperList($report, $search, ["sort" => true], $qreq);
        $pl->apply_view_report_default(PaperList::VIEWORIGIN_REPORT);
        $pl->apply_view_session($qreq);
        if ($view instanceof Qrequest) {
            $pl->apply_view_qreq($view);
        } else {
            $pl->parse_view($view, PaperList::VIEWORIGIN_MAX);
        }
        $vd = $pl->unparse_view(PaperList::VIEWORIGIN_REPORT, false);
        if (!empty($vd)) {
            $qreq->set_csession("{$report}display", join(" ", $vd));
        } else {
            $qreq->unset_csession("{$report}display");
        }
    }

    /** @param array<string,bool> $settings */
    static private function change_uldisplay(Qrequest $qreq, $settings) {
        $curl = explode(" ", trim(ContactList::uldisplay($qreq)));
        foreach ($settings as $name => $setting) {
            if (($f = $qreq->conf()->review_field($name))) {
                $terms = [$f->short_id];
                if ($f->main_storage !== null && $f->main_storage !== $f->short_id) {
                    $terms[] = $f->main_storage;
                }
            } else {
                $terms = [$name];
            }
            foreach ($terms as $i => $term) {
                $p = array_search($term, $curl, true);
                if ($i === 0 && $setting && $p === false) {
                    $curl[] = $term;
                }
                while (($i !== 0 || !$setting) && $p !== false) {
                    array_splice($curl, $p, 1);
                    $p = array_search($term, $curl, true);
                }
            }
        }

        $defaultl = explode(" ", trim(ContactList::uldisplay($qreq, true)));
        sort($defaultl);
        sort($curl);
        if ($curl === $defaultl) {
            $qreq->unset_csession("uldisplay");
        } else if ($curl === [] || $curl === [""]) {
            $qreq->set_csession("uldisplay", " ");
        } else {
            $qreq->set_csession("uldisplay", " " . join(" ", $curl) . " ");
        }
    }

    static function whoami(Contact $user, Qrequest $qreq) {
        $j = [
            "ok" => true,
            "email" => $user->email,
            "given_name" => $user->firstName,
            "family_name" => $user->lastName,
            "affiliation" => $user->affiliation
        ];
        $roles = UserStatus::unparse_roles_json($user->roles) ?? [];
        if (!$user->privChair
            && ($user->is_manager() || $user->is_track_manager())) {
            $roles[] = "manager";
        }
        if ($user->is_author()) {
            $roles[] = "author";
        }
        if ($user->has_review()) {
            $roles[] = "reviewer";
        }
        if (!empty($roles)) {
            $j["roles"] = $roles;
        }
        return $j;
    }

    static function stashmessages(Contact $user, Qrequest $qreq) {
        if (isset($qreq->smsg)
            && (strlen($qreq->smsg) < 10 || strlen($qreq->smsg) > 64 || !ctype_alnum($qreq->smsg))) {
            return JsonResult::make_parameter_error("smsg");
        }
        if (!isset($qreq->message_list)) {
            return JsonResult::make_missing_error("message_list");
        }
        $mlj = json_decode($qreq->message_list);
        if (!is_array($mlj)) {
            return JsonResult::make_parameter_error("message_list");
        }
        $ml = [];
        foreach ($mlj as $mx) {
            $status = $mx->status ?? null;
            $message = $mx->message ?? "";
            if (!is_int($status)
                || $status < MessageSet::MIN_STATUS
                || $status > MessageSet::MAX_STATUS
                || !is_string($message)) {
                continue;
            }
            if ($message === "") {
                $ml[] = new MessageItem($status);
                continue;
            }
            // If nonempty, only formats <0>, <1>, and clean <5> allowed
            $fmt = Ftext::format($message);
            if ($fmt === null) {
                $ml[] = new MessageItem($status, null, "<0>{$message}");
            } else if ($fmt === 0
                       || $fmt === 1
                       || ($fmt === 5 && CleanHtml::basic_clean(substr($message, 3)))) {
                $ml[] = new MessageItem($status, null, $message);
            }
        }
        if (empty($ml)) {
            return JsonResult::make_ok()->set("smsg", false);
        }
        $smsg = $qreq->smsg ?? base48_encode(random_bytes(6));
        $qreq->open_session();
        $smsgs = $qreq->gsession("smsg") ?? [];
        $smsgs[] = [$smsg, Conf::$now, Ht::feedback_msg_content($ml)];
        $qreq->set_gsession("smsg", $smsgs);
        return (new JsonResult(200))->set("smsg", $smsg);
    }

    static function reauth(Contact $user, Qrequest $qreq) {
        return $user->authentication_checker($qreq, $qreq->reason ?? "api")->api();
    }
}
