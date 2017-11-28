<?php
// api_user.php -- HotCRP user-related API calls
// HotCRP is Copyright (c) 2008-2017 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

class User_API {
    static function whoami(Contact $user, Qrequest $qreq) {
        return ["ok" => true, "email" => $user->email];
    }
}
