<?php
// api_user.php -- HotCRP user-related API calls
// Copyright (c) 2008-2018 Eddie Kohler; see LICENSE.

class User_API {
    static function whoami(Contact $user, Qrequest $qreq) {
        return ["ok" => true, "email" => $user->email];
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
