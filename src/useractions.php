<?php
// useractions.php -- HotCRP helpers for user actions
// HotCRP is Copyright (c) 2008-2016 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

class UserActions {
    static private function modify_password_mail($where, $dopassword, $sendtype, $ids) {
        $j = (object) array("ok" => true);
        $result = Dbl::qe("select * from ContactInfo where $where and contactId ?a", $ids);
        while ($result && ($Acct = $result->fetch_object("Contact"))) {
            if ($dopassword)
                $Acct->change_password(null, null, Contact::CHANGE_PASSWORD_NO_CDB);
            if ($sendtype && !$Acct->disabled)
                $Acct->sendAccountInfo($sendtype, false);
            else if ($sendtype)
                $j->warnings[] = "Not sending mail to disabled account " . htmlspecialchars($Acct->email) . ".";
        }
        return $j;
    }

    static function disable($ids, $contact) {
        $result = Dbl::qe("update ContactInfo set disabled=1 where contactId ?a and contactId!=?", $ids, $contact->contactId);
        if ($result && $result->affected_rows)
            return (object) array("ok" => true);
        else if ($result)
            return (object) array("ok" => true, "warnings" => array("Those accounts were already disabled."));
        else
            return (object) array("error" => true);
    }

    static function enable($ids, $contact) {
        $result = Dbl::qe("update ContactInfo set disabled=1 where contactId ?a and password='' and contactId!=?", $ids, $contact->contactId);
        $result = Dbl::qe("update ContactInfo set disabled=0 where contactId ?a and contactId!=?", $ids, $contact->contactId);
        if ($result && $result->affected_rows)
            return self::modify_password_mail("password='' and contactId!=" . $contact->contactId, true, "create", $ids);
        else if ($result)
            return (object) array("ok" => true, "warnings" => array("Those accounts were already enabled."));
        else
            return (object) array("error" => true);
    }

    static function reset_password($ids, $contact) {
        global $Conf;
        return self::modify_password_mail("contactId!=" . $contact->contactId, true, false, $ids);
        $Conf->confirmMsg("Passwords reset. To send mail with the new passwords, <a href='" . hoturl_post("users", "modifygo=1&amp;modifytype=sendaccount&amp;pap[]=" . (is_array($ids) ? join("+", $ids) : $ids)) . "'>click here</a>.");
    }

    static function send_account_info($ids, $contact) {
        global $Conf;
        return self::modify_password_mail("true", false, "send", $ids);
        $Conf->confirmMsg("Account information sent.");
    }

    static function save_clickthrough($user) {
        global $Conf, $Now;
        $confirmed = false;
        if (@$_REQUEST["clickthrough_accept"]
            && @$_REQUEST["clickthrough_sha1"]) {
            $user->merge_and_save_data(array("clickthrough" => array($_REQUEST["clickthrough_sha1"] => $Now)));
            $confirmed = true;
        } else if (@$_REQUEST["clickthrough_decline"])
            Conf::msg_error("You can’t continue until you accept these terms.");
        if (@$_REQUEST["ajax"])
            $Conf->ajaxExit(array("ok" => $confirmed));
        redirectSelf();
    }

}
