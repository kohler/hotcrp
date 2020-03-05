<?php
// api_user.php -- HotCRP user-related API calls
// Copyright (c) 2008-2020 Eddie Kohler; see LICENSE.

class User_API {
    static function whoami(Contact $user, Qrequest $qreq) {
        return ["ok" => true, "email" => $user->email];
    }

    static function user(Contact $user, Qrequest $qreq) {
        if (!$user->can_lookup_user())
            return new JsonResult(403, "Permission error.");
        if (!($email = trim($qreq->email)))
            return new JsonResult(400, "Parameter error.");

        $users = [];
        if ($user->privChair || $user->can_view_pc()) {
            $roles = $user->is_manager() ? "" : " and (roles&" . Contact::ROLE_PC . ")!=0";
            $result = $user->conf->qe("select email, firstName, lastName, affiliation from ContactInfo where email>=? and email<? and not disabled$roles order by email asc", $email, $email . "~");
            while (($u = $result->fetch_object()))
                $users[] = $u;
            Dbl::free($result);
        }

        if ((empty($users) || strcasecmp($users[0]->email, $email) !== 0)
            && $user->conf->opt("allowLookupUser")
            && ($cdb = $user->conf->contactdb())) {
            $result = Dbl::qe($cdb, "select email, firstName, lastName, affiliation from ContactInfo where email>=? and email<? and not disabled order by email asc", $email, $email . "~");
            $users = [];
            while (($u = $result->fetch_object()))
                $users[] = $u;
            Dbl::free($result);
        }

        if (empty($users)
            && strcasecmp($user->email, $email) >= 0
            && strcasecmp($user->email, $email . "~") < 0) {
            $users[] = $user;
        }

        if (empty($users)) {
            return new JsonResult(404, ["ok" => false, "user_error" => true]);
        } else {
            $u = $users[0];
            $ok = strcasecmp($u->email, $email) === 0;
            $rj = ["ok" => $ok, "email" => $u->email, "firstName" => $u->firstName, "lastName" => $u->lastName, "affiliation" => $u->affiliation];
            if (!$ok)
                $rj["user_error"] = true;
            return new JsonResult($ok ? 200 : 404, $rj);
        }
    }

    static function clickthrough(Contact $user, Qrequest $qreq) {
        global $Now;
        if ($qreq->accept
            && $qreq->clickthrough_id
            && ($hash = Filer::sha1_hash_as_text($qreq->clickthrough_id))) {
            $user->activate_database_account();
            $user->merge_and_save_data(["clickthrough" => [$hash => $Now]]);
            $user->log_activity("Terms agreed " . substr($hash, 0, 10) . "...");
            return ["ok" => true];
        } else if ($qreq->clickthrough_accept) {
            return new JsonResult(400, "Parameter error.");
        } else {
            return ["ok" => false];
        }
    }
}
