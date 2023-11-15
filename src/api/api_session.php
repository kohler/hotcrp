<?php
// api_session.php -- HotCRP session API calls
// Copyright (c) 2008-2023 Eddie Kohler; see LICENSE.

class Session_API {
    static private function session_result(Contact $user, Qrequest $qreq, $ok) {
        $si = ["postvalue" => $qreq->post_value()];
        if ($user->contactId) {
            $si["cid"] = $user->contactId; // XXX backward compat
            $si["uid"] = $user->contactId;
        }
        return ["ok" => $ok, "postvalue" => $qreq->post_value(), "sessioninfo" => $si];
    }

    static function getsession(Contact $user, Qrequest $qreq) {
        $qreq->open_session();
        return self::session_result($user, $qreq, true);
    }

    /** @param Qrequest $qreq
     * @param string $v
     * @return bool */
    static function change_session($qreq, $v) {
        $qreq->open_session();
        $ok = true;
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
                    self::parse_view($qreq, "pl", "sort:[score {$ss}]");
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
                $view = ($unfold ? "show:" : "hide:") . substr($m[2], 1);
                self::parse_view($qreq, substr($m[1], 0, 2), $view);
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
        return $ok;
    }

    /** @param Qrequest $qreq
     * @return array{ok:bool,postvalue:string} */
    static function setsession(Contact $user, $qreq) {
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
}
