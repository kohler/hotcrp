<?php
// pages/p_api.php -- HotCRP JSON API access page
// Copyright (c) 2006-2026 Eddie Kohler; see LICENSE.

class API_Page {
    /** @param string $fn
     * @return JsonResult */
    static function go(Contact $user, Qrequest $qreq, $fn) {
        // initialize user, paper request
        $conf = $user->conf;
        $conf->set_site_path_relative($qreq->navigation(), $qreq->base ?? null);
        if (!$user->has_account_here()
            && ($key = $user->capability("@kiosk"))) {
            $kiosks = $conf->setting_json("__tracker_kiosk") ? : (object) [];
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

        // handle requests
        $jr = null;
        if ($fn !== "status") {
            if ($fn !== "track" || $user->is_disabled()) {
                $jr = self::normal_api($fn, $user, $qreq);
            } else {
                $jr = MeetingTracker::track_api($user, $qreq);
            }
        }
        return $jr ?? self::status_api($fn, $user, $qreq);
    }

    /** @param string $fn
     * @param Contact $user
     * @param Qrequest $qreq
     * @return JsonResult */
    static private function normal_api($fn, $user, $qreq) {
        JsonCompletion::$allow_short_circuit = true;
        $conf = $user->conf;
        $uf = $conf->api($fn, $user, $qreq->method());
        // CORS: Allow if user provides CSRF token or auth is explicitly false.
        if ((($uf && ($uf->auth ?? null) === false) || $qreq->valid_token())
            && ($origin = $qreq->raw_header("HTTP_ORIGIN")) !== null) {
            header("Access-Control-Allow-Origin: {$origin}");
            header("Access-Control-Allow-Credentials: true");
        }
        $validator = null;
        if ($uf && $conf->opt("validateApiSpec")) {
            $validator = new SpecValidator_API($fn, $uf, $qreq);
            $validator->request();
        }
        $jr = $conf->call_api_on($uf, $fn, $user, $qreq);
        if ($validator) {
            $validator->response($jr);
        }
        if ($jr instanceof Downloader) {
            $conf->emit_browser_security_headers($qreq);
            $jr->emit();
            exit(0);
        } else if ($jr instanceof PageCompletion) {
            $jr->emit($qreq);
            exit(0);
        }
        if ($uf
            && ($uf->redirect ?? false)
            && ($url = $conf->qreq_redirect_url($qreq))) {
            $conf->feedback_msg(self::export_messages($jr));
            $conf->redirect($url);
        }
        return $jr;
    }

    /** @param string $fn
     * @param Contact $user
     * @param Qrequest $qreq
     * @return JsonResult */
    static private function status_api($fn, $user, $qreq) {
        $prow = $qreq->paper();
        // default status API to not being pretty printed; it's frequently called
        $jr = (new JsonResult($user->status_json($prow ? [$prow] : [])))
            ->set_pretty_print(false);
        $jr["ok"] = true;
        if ($fn === "track"
            && ($new_trackerid = $qreq->annex("new_trackerid"))) {
            $jr["new_trackerid"] = $new_trackerid;
        }
        if ($prow
            && $user->can_view_tags($prow)
            && !$user->is_disabled()) {
            $pj = new TagMessageReport;
            $pj->pid = $prow->paperId;
            $prow->add_tag_info_json($pj, $user);
            if (count((array) $pj) > 1) {
                $jr["p"] = [$prow->paperId => $pj];
            }
        }
        return $jr;
    }

    /** @return list<MessageItem> */
    static function export_messages(JsonResult $jr) {
        $ml = [];
        foreach ($jr->content["message_list"] ?? [] as $mi) {
            if ($mi instanceof MessageItem) {
                $ml[] = $mi;
            }
        }
        if (empty($ml) && isset($jr->content["error"])) { // XXX backward compat
            $ml[] = MessageItem::error("<0>" . $jr->content["error"]);
        }
        if (empty($ml) && !($jr->content["ok"] ?? ($jr->status <= 299))) {
            $ml[] = MessageItem::error("<0>Internal error");
        }
        return $ml;
    }

    static function go_options(NavigationState $nav) {
        if ($nav->page === "u"
            && ($unum = $nav->path_component(0)) !== false
            && ctype_digit($unum)) {
            $nav->shift_path_components(2);
        }
        if ($nav->page === "api") {
            $cors_type = "api";
            $allow = "OPTIONS, GET, HEAD, POST, DELETE";
        } else if (in_array($nav->page, ["cacheable", "scorechart", "images", "scripts", "stylesheets", ".well-known"], true)) {
            $cors_type = "static";
            $allow = "OPTIONS, GET, HEAD";
        } else {
            $cors_type = null;
            $allow = "OPTIONS, GET, HEAD, POST";
        }
        if ($cors_type !== null) {
            header("Access-Control-Allow-Origin: " . ($_SERVER["HTTP_ORIGIN"] ?? "*"));
        }
        if ($cors_type === "api") {
            header("Access-Control-Allow-Credentials: true");
        }
        $ok = true;
        if ($_SERVER["HTTP_ACCESS_CONTROL_REQUEST_METHOD"] ?? null) {
            if ($cors_type === null) {
                http_response_code(403);
                exit(0);
            }
            header("Access-Control-Allow-Headers: *");
            header("Access-Control-Allow-Methods: {$allow}");
            header("Access-Control-Max-Age: 86400");
        }
        header("Allow: {$allow}");
        http_response_code(204);
        exit(0);
    }

    static function parameter_error_exit($param, $message) {
        http_response_code(400);
        header("Content-Type: application/json; charset=utf-8");
        echo "{\"ok\": false, \"message_list\": [{\"field\": \"{$param}\", \"message\": \"{$message}\", \"status\": 2}]}\n";
        exit(0);
    }

    /** @param NavigationState $nav
     * @param Conf $conf */
    static function go_nav($nav, $conf) {
        // extract function from path
        $pcindex = 0;
        $fn = $nav->path_component(0, true);
        if ($fn && (ctype_digit($fn) || $fn === "new")) {
            if (isset($_GET["p"]) && $_GET["p"] !== $fn) {
                self::parameter_error_exit("p", "<0>Parameter conflict");
            }
            $_GET["p"] = $fn;
            ++$pcindex;
            $fn = $nav->path_component($pcindex, true);
        }
        if (!$fn) {
            self::parameter_error_exit("fn", "<0>Parameter missing");
        }

        // process request
        $qreq = initialize_request($conf, $nav);
        $qreq->set_path_component_index($pcindex + 1);
        try {
            if ($fn === "trackerstatus") {
                // special case: prevent session creation
                $jr = MeetingTracker::trackerstatus_api(Contact::make($conf));
            } else {
                $user = initialize_user($qreq, ["bearer" => true]);
                $jr = self::go($user, $qreq, $fn);
            }
        } catch (JsonCompletion $jc) {
            $jr = $jc->result;
        }
        $jr->emit($qreq);
    }
}
