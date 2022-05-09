<?php
// api_user.php -- HotCRP user-related API calls
// Copyright (c) 2008-2022 Eddie Kohler; see LICENSE.

class User_API {
    static function whoami(Contact $user, Qrequest $qreq) {
        return ["ok" => true, "email" => $user->email];
    }

    static function user(Contact $user, Qrequest $qreq, PaperInfo $prow = null) {
        if (!$user->can_lookup_user()) {
            return JsonResult::make_error(403, "<0>Permission error");
        }
        if (!($email = trim($qreq->email))) {
            return JsonResult::make_error(400, "<0>Parameter error");
        }

        $users = [];
        if ($user->privChair || $user->can_view_pc()) {
            $roles = $user->is_manager() ? "" : " and roles!=0 and (roles&" . Contact::ROLE_PC . ")!=0";
            $result = $user->conf->qe("select contactId, email, firstName, lastName, affiliation, collaborators from ContactInfo where email>=? and email<? and not disabled$roles order by email asc limit 2", $email, $email . "~");
            while (($u = Contact::fetch($result, $user->conf))) {
                $users[] = $u;
            }
            Dbl::free($result);
        }

        if ((empty($users) || strcasecmp($users[0]->email, $email) !== 0)
            && $user->conf->opt("allowLookupUser")) {
            if (($db = $user->conf->contactdb())) {
                $idk = "contactDbId";
            } else {
                $db = $user->conf->dblink;
                $idk = "contactId";
            }
            $result = Dbl::qe($db, "select $idk, email, firstName, lastName, affiliation, collaborators from ContactInfo where email>=? and email<? and not disabled order by email asc limit 2", $email, $email . "~");
            $users = [];
            while (($u = Contact::fetch($result, $user->conf))) {
                $users[] = $u;
            }
            Dbl::free($result);
        }

        if (empty($users)
            && strcasecmp($user->email, $email) >= 0
            && strcasecmp($user->email, $email . "~") < 0) {
            $users[] = $user;
        }

        if (empty($users)) {
            return new JsonResult(["ok" => false]);
        } else {
            $u = $users[0];
            $ok = strcasecmp($u->email, $email) === 0;
            $rj = ["ok" => $ok, "email" => $u->email, "firstName" => $u->firstName, "lastName" => $u->lastName, "affiliation" => $u->affiliation];
            if ($prow
                && $user->allow_view_authors($prow)
                && $qreq->potential_conflict
                && ($pc = $prow->potential_conflict_html($u))) {
                $rj["potential_conflict"] = PaperInfo::potential_conflict_tooltip_html($pc);
            }
            return new JsonResult($rj);
        }
    }

    static function clickthrough(Contact $user, Qrequest $qreq) {
        if ($qreq->accept
            && $qreq->clickthrough_id
            && ($hash = Filer::sha1_hash_as_text($qreq->clickthrough_id))) {
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

    static function account_disable(Contact $user, Contact $viewer, $disabled) {
        if (!$viewer->privChair) {
            return JsonResult::make_error(403, "<0>Permission error");
        } else if ($viewer->contactId === $user->contactId) {
            return JsonResult::make_error(400, "<0>You cannot disable your own account");
        } else {
            $ustatus = new UserStatus($viewer);
            $ustatus->set_user($user);
            if ($ustatus->save_user((object) ["disabled" => $disabled], $user)) {
                return new JsonResult(["ok" => true, "u" => $user->email, "disabled" => $user->disablement !== 0]);
            } else {
                return new JsonResult(["ok" => false, "u" => $user->email]);
            }
        }
    }

    static function account_sendinfo(Contact $user, Contact $viewer) {
        if (!$viewer->privChair) {
            return JsonResult::make_error(403, "<0>Permission error");
        } else if ($user->disablement === 0) {
            $user->send_mail("@accountinfo");
            return new JsonResult(["ok" => true, "u" => $user->email]);
        } else {
            $j = MessageItem::make_error_json("<0>User disabled");
            $j["u"] = $user->email;
            return new JsonResult($j);
        }
    }

    static function account(Contact $viewer, Qrequest $qreq) {
        if (!isset($qreq->u) || $qreq->u === "me" || strcasecmp($qreq->u, $viewer->email) === 0) {
            $user = $viewer;
        } else if ($viewer->isPC) {
            $user = $viewer->conf->user_by_email($qreq->u);
        } else {
            return JsonResult::make_error(403, "<0>Permission error");
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
