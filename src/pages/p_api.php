<?php
// pages/p_api.php -- HotCRP JSON API access page
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class API_Page {
    static function go(Contact $user, Qrequest $qreq) {
        $conf = $user->conf;
        if ($qreq->base !== null) {
            $conf->set_siteurl($qreq->base);
        }
        if (!$user->has_account_here()
            && ($key = $user->capability("@kiosk"))) {
            $kiosks = $conf->setting_json("__tracker_kiosk") ? : (object) array();
            if (isset($kiosks->$key) && $kiosks->$key->update_at >= Conf::$now - 172800) {
                if ($kiosks->$key->update_at < Conf::$now - 3600) {
                    $kiosks->$key->update_at = Conf::$now;
                    $conf->save_setting("__tracker_kiosk", 1, $kiosks);
                }
                $user->tracker_kiosk_state = $kiosks->$key->show_papers ? 2 : 1;
            }
        }
        if ($qreq->p) {
            $conf->set_paper_request($qreq, $user);
        }

        // requests
        $fn = $qreq->fn;
        $is_track = $fn === "track";
        if (!$user->is_disabled() && ($is_track || $fn === "status")) {
            if ($is_track) {
                MeetingTracker::track_api($user, $qreq); // may fall through to act like `status`
            }

            $j = $user->my_deadlines($conf->paper ? [$conf->paper] : []);
            $j->ok = true;
            if ($is_track && ($new_trackerid = $qreq->annex("new_trackerid"))) {
                $j->new_trackerid = $new_trackerid;
            }

            if ($conf->paper && $user->can_view_tags($conf->paper)) {
                $pj = new TagMessageReport;
                $pj->pid = $conf->paper->paperId;
                $conf->paper->add_tag_info_json($pj, $user);
                if (count((array) $pj) > 1) {
                    $j->p = [$conf->paper->paperId => $pj];
                }
            }

            json_exit($j);
        } else {
            JsonCompletion::$allow_short_circuit = true;
            $uf = $conf->api($fn, $user, $qreq->method());
            $jr = $conf->call_api_on($uf, $fn, $user, $qreq, $conf->paper);
            if ($uf
                && $qreq->redirect
                && ($uf->redirect ?? false)
                && preg_match('/\A(?![a-z]+:|\/)./', $qreq->redirect)) {
                $jr->export_messages($conf);
                $conf->redirect($conf->make_absolute_site($qreq->redirect));
            }
            json_exit($jr);
        }
    }

    /** @param NavigationState $nav
     * @param Conf $conf */
    static function go_nav($nav, $conf) {
        // argument cleaning
        if (!isset($_GET["fn"])) {
            $fn = $nav->path_component(0, true);
            if ($fn && ctype_digit($fn)) {
                if (!isset($_GET["p"])) {
                    $_GET["p"] = $fn;
                }
                $fn = $nav->path_component(1, true);
            }
            if ($fn) {
                $_GET["fn"] = $fn;
            } else if (isset($_GET["track"])) {
                $_GET["fn"] = "track";
            } else {
                http_response_code(404);
                header("Content-Type: text/plain; charset=utf-8");
                echo json_encode(["ok" => false, "error" => "API function missing"]);
                exit;
            }
        }
        if ($_GET["fn"] === "deadlines") {
            $_GET["fn"] = "status";
        }
        if (!isset($_GET["p"])
            && ($p = $nav->path_component(1, true))
            && ctype_digit($p)) {
            $_GET["p"] = $p;
        }

        // trackerstatus is a special case: prevent session creation
        if ($_GET["fn"] === "trackerstatus") {
            global $Opt;
            $Opt["__no_main_user"] = true;
            initialize_request();
            MeetingTracker::trackerstatus_api(Contact::make(Conf::$main));
        } else {
            initialize_request();
            self::go(Contact::$main_user, Qrequest::$main_request);
        }
    }
}
