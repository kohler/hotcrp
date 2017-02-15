<?php
// ldaplogin.php -- HotCRP helper function for LDAP login
// HotCRP is Copyright (c) 2009-2017 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

function ldapLoginBindFailure($ldapc) {
    global $Conf, $email_class, $password_class;

    // connection failed, report error
    $lerrno = ldap_errno($ldapc);
    $suffix = "";
    if ($lerrno != 49)
        $suffix = "<br /><span class='hint'>(LDAP error $lerrno: " . htmlspecialchars(ldap_err2str($lerrno)) . ")</span>";

    if ($lerrno < 5)
        return Conf::msg_error("LDAP protocol error.  Logins will fail until this error is fixed.$suffix");
    else if (req_s("password") == "") {
        $password_class = " error";
        if ($lerrno == 53)
            $suffix = "";
        return Conf::msg_error("Enter your LDAP password.$suffix");
    } else {
        $email_class = $password_class = " error";
        return Conf::msg_error("Those credentials are invalid.  Please use your LDAP username and password.$suffix");
    }
}

function ldapLoginAction() {
    global $Conf;

    if (!preg_match('/\A\s*(\S+)\s+(\d+\s+)?([^*]+)\*(.*?)\s*\z/s', opt("ldapLogin"), $m))
        return Conf::msg_error("Internal error: <code>" . htmlspecialchars(opt("ldapLogin")) . "</code> syntax error; expected &ldquo;<code><i>LDAP-URL</i> <i>distinguished-name</i></code>&rdquo;, where <code><i>distinguished-name</i></code> contains a <code>*</code> character to be replaced by the user's email address.  Logins will fail until this error is fixed.");

    // connect to the LDAP server
    if ($m[2] == "")
        $ldapc = @ldap_connect($m[1]);
    else
        $ldapc = @ldap_connect($m[1], (int) $m[2]);
    if (!$ldapc)
        return Conf::msg_error("Internal error: ldap_connect.  Logins disabled until this error is fixed.");
    @ldap_set_option($ldapc, LDAP_OPT_PROTOCOL_VERSION, 3);

    $qemail = addcslashes(req_s("email"), ',=+<>#;\"');
    $dn = $m[3] . $qemail . $m[4];

    $success = @ldap_bind($ldapc, $dn, req_s("password"));
    if (!$success && @ldap_errno($ldapc) == 2) {
        @ldap_set_option($ldapc, LDAP_OPT_PROTOCOL_VERSION, 2);
        $success = @ldap_bind($ldapc, $dn, req_s("password"));
    }
    if (!$success)
        return ldapLoginBindFailure($ldapc);

    // use LDAP information to prepopulate the database with names
    $sr = @ldap_search($ldapc, $dn, "(cn=*)",
                       array("sn", "givenname", "cn", "mail", "telephonenumber"));
    if ($sr) {
        $e = @ldap_get_entries($ldapc, $sr);
        $e = ($e["count"] == 1 ? $e[0] : array());
        if (isset($e["cn"]) && $e["cn"]["count"] == 1)
            list($_REQUEST["firstName"], $_REQUEST["lastName"]) = Text::split_name($e["cn"][0]);
        if (isset($e["sn"]) && $e["sn"]["count"] == 1)
            $_REQUEST["lastName"] = $e["sn"][0];
        if (isset($e["givenname"]) && $e["givenname"]["count"] == 1)
            $_REQUEST["firstName"] = $e["givenname"][0];
        if (isset($e["mail"]) && $e["mail"]["count"] == 1)
            $_REQUEST["preferredEmail"] = $e["mail"][0];
        if (isset($e["telephonenumber"]) && $e["telephonenumber"]["count"] == 1)
            $_REQUEST["voicePhoneNumber"] = $e["telephonenumber"][0];
    }

    ldap_close($ldapc);
    return true;
}
