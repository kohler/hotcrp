<?php
// api_user.php -- HotCRP user-related API calls
// Copyright (c) 2008-2023 Eddie Kohler; see LICENSE.

class User_API {
    static function whoami(Contact $user, Qrequest $qreq) {
        return ["ok" => true, "email" => $user->email];
    }

    /** @return JsonResult */
    static function user(Contact $user, Qrequest $qreq, PaperInfo $prow = null) {
        if (!$user->can_lookup_user()) {
            return JsonResult::make_permission_error();
        }
        if (($email = trim($qreq->email ?? "")) === "") {
            return JsonResult::make_missing_error("email");
        }

        $slice = Contact::SLICE_MINIMAL - Contact::SLICEBIT_COLLABORATORS
            - Contact::SLICEBIT_COUNTRY - Contact::SLICEBIT_ORCID;
        $broad_lookup = $user->conf->opt("allowLookupUser");

        $found = null;
        if (strcasecmp($user->email, $email) >= 0
            && strcasecmp($user->email, "{$email}~") < 0) {
            $found = $user;
        }

        if (($user->can_view_pc() || $broad_lookup)
            && (!$found || strcasecmp($found->email, $email) !== 0)) {
            $roles = "";
            if (!$user->is_manager() && !$broad_lookup) {
                $roles = " and roles!=0 and (roles&" . Contact::ROLE_PC . ")!=0";
            }
            $result = $user->conf->qe("select " . $user->conf->user_query_fields($slice) . " from ContactInfo where email>=? and email<? and (cflags&?)=0{$roles} order by email asc limit 1",
                $email, "{$email}~", Contact::CFM_DISABLEMENT);
            while (($u = Contact::fetch($result, $user->conf))) {
                if (!$found || strcasecmp($found->email, $u->email) > 0)
                    $found = $u;
            }
            Dbl::free($result);
        }

        if ($broad_lookup
            && ($db = $user->conf->contactdb())
            && (!$found || strcasecmp($found->email, $email) !== 0)) {
            $result = Dbl::qe($db, "select " . $user->conf->contactdb_user_query_fields($slice) . " from ContactInfo where email>=? and email<? and (cflags&?)=0 order by email asc limit 1",
                $email, "{$email}~", Contact::CFM_DISABLEMENT);
            $i = 0;
            while (($u = Contact::fetch($result, $user->conf))) {
                if (!$found || strcasecmp($found->email, $u->email) > 0)
                    $found = $u;
            }
            Dbl::free($result);
        }

        if (!$found) {
            return new JsonResult(["ok" => false]);
        }

        $ok = strcasecmp($found->email, $email) === 0;
        $rj = [
            "ok" => $ok,
            "email" => $found->email,
            "firstName" => $found->firstName,
            "lastName" => $found->lastName,
            "affiliation" => $found->affiliation
        ];
        if ($found->country() !== "") {
            $rj["country"] = $found->country();
        }
        if ($found->orcid() !== "") {
            $rj["orcid"] = $found->orcid();
        }
        if ($prow
            && $user->allow_view_authors($prow)
            && $qreq->potential_conflict
            && ($potconf = $prow->potential_conflict_html($found))) {
            $rj["potential_conflict"] = PaperInfo::potential_conflict_tooltip_html($potconf);
        }
        return new JsonResult($rj);
    }

    static function clickthrough(Contact $user, Qrequest $qreq) {
        if ($qreq->accept
            && $qreq->clickthrough_id
            && ($hash = HashAnalysis::sha1_hash_as_text($qreq->clickthrough_id))) {
            if ($user->has_email()) {
                $dest_user = $user;
            } else if ($qreq->p
                       && ctype_digit($qreq->p)
                       && ($ru = $user->reviewer_capability_user(intval($qreq->p)))) {
                $dest_user = $ru;
            } else {
                return JsonResult::make_error(404, "<0>User not found");
            }
            $dest_user->ensure_account_here();
            $dest_user->merge_and_save_data(["clickthrough" => [$hash => Conf::$now]]);
            $user->log_activity_for($dest_user, "Terms agreed " . substr($hash, 0, 10) . "...");
            return ["ok" => true];
        } else if ($qreq->clickthrough_accept) {
            return JsonResult::make_error(400, "<0>Parameter error");
        } else {
            return ["ok" => false];
        }
    }

    /** @param bool $disabled
     * @return JsonResult */
    static function account_disable(Contact $user, Contact $viewer, $disabled) {
        if (!$viewer->privChair) {
            return JsonResult::make_permission_error();
        } else if ($viewer->contactId === $user->contactId) {
            return JsonResult::make_error(400, "<0>You cannot disable your own account");
        } else {
            $ustatus = new UserStatus($viewer);
            $ustatus->set_user($user);
            if ($ustatus->save_user((object) ["disabled" => $disabled], $user)) {
                return new JsonResult([
                    "ok" => true,
                    "u" => $user->email,
                    "disabled" => $user->is_disabled(),
                    "placeholder" => $user->is_placeholder()
                ]);
            } else {
                return new JsonResult(["ok" => false, "u" => $user->email]);
            }
        }
    }

    /** @return JsonResult */
    static function account_sendinfo(Contact $user, Contact $viewer) {
        if (!$viewer->privChair) {
            return JsonResult::make_permission_error();
        }
        if ($user->activate_placeholder_prop(false)) {
            $user->save_prop();
        }
        $prep = $user->prepare_mail("@accountinfo");
        if ($prep->send()) {
            $jr = new JsonResult(200);
        } else {
            $jr = new JsonResult(400);
            $jr->content["message_list"] = $prep->message_list();
        }
        $jr->content["u"] = $user->email;
        return $jr;
    }

    /** @return JsonResult */
    static function account(Contact $viewer, Qrequest $qreq) {
        if (!isset($qreq->u) || $qreq->u === "me" || strcasecmp($qreq->u, $viewer->email) === 0) {
            $user = $viewer;
        } else if ($viewer->isPC) {
            $user = $viewer->conf->user_by_email($qreq->u);
        } else {
            return JsonResult::make_permission_error();
        }
        if (!$user) {
            return JsonResult::make_error(404, "<0>User not found");
        }
        if ($qreq->valid_post() && ($qreq->disable || $qreq->enable)) {
            return self::account_disable($user, $viewer, !!$qreq->disable);
        } else if ($qreq->valid_post() && $qreq->sendinfo) {
            return self::account_sendinfo($user, $viewer);
        } else {
            return new JsonResult(["ok" => true]);
        }
    }
}
