<?php
// api_user.php -- HotCRP user-related API calls
// Copyright (c) 2008-2018 Eddie Kohler; see LICENSE.

class User_API {
    static function whoami(Contact $user, Qrequest $qreq) {
        return ["ok" => true, "email" => $user->email];
    }

    static function user(Contact $user, Qrequest $qreq) {
        if (!($email = trim($qreq->email)))
            return new JsonResult(400, "Parameter error.");
        $ask = $user->conf->cached_user_by_email($email);
        if (!$ask)
            $ask = $user->conf->contactdb_user_by_email($email);
        if ($ask)
            return new JsonResult(200, ["ok" => true, "email" => $ask->email, "firstName" => $ask->firstName, "lastName" => $ask->lastName, "affiliation" => $ask->affiliation]);
        else
            return new JsonResult(404, ["ok" => false, "user_error" => true]);
    }

    static function clickthrough(Contact $user, Qrequest $qreq) {
        global $Now;
        if ($qreq->accept
            && $qreq->clickthrough_id
            && ($hash = Filer::sha1_hash_as_text($qreq->clickthrough_id))) {
            $user->merge_and_save_data(["clickthrough" => [$hash => $Now]]);
            return ["ok" => true];
        } else if ($qreq->clickthrough_accept) {
            return new JsonResult(400, "Parameter error.");
        } else {
            return ["ok" => false];
        }
    }
}
