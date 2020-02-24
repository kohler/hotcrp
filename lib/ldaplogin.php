<?php
// ldaplogin.php -- HotCRP helper function for LDAP login
// Copyright (c) 2009-2020 Eddie Kohler; see LICENSE.

class LDAPLogin {
    static function ldap_login_info(Conf $conf, Qrequest $qreq) {
        if (!preg_match('/\A\s*(\S+)\s+(\d+\s+)?([^*]+)\*(.*?)\s*\z/s',
            $conf->opt("ldapLogin"), $m)) {
            return [
                "ok" => false, "ldap" => true, "internal" => true, "email" => true,
                "detail_html" => "Internal error: <code>" . htmlspecialchars($conf->opt("ldapLogin")) . "</code> syntax error; expected “<code><i>LDAP-URL</i> <i>distinguished-name</i></code>”, where <code><i>distinguished-name</i></code> contains a <code>*</code> character to be replaced by the user's email address.  Logins will fail until this error is fixed."
            ];
        }

        // connect to the LDAP server
        if ($m[2] == "") {
            $ldapc = @ldap_connect($m[1]);
        } else {
            $ldapc = @ldap_connect($m[1], (int) $m[2]);
        }
        if (!$ldapc) {
            return [
                "ok" => false, "ldap" => true, "internal" => true, "email" => true,
                "detail_html" => "Internal error: ldap_connect. Logins disabled until this error is fixed."
            ];
        }
        @ldap_set_option($ldapc, LDAP_OPT_PROTOCOL_VERSION, 3);

        $qemail = addcslashes((string) $qreq->email, ',=+<>#;\"');
        $dn = $m[3] . $qemail . $m[4];

        $success = @ldap_bind($ldapc, $dn, (string) $qreq->password);
        if (!$success && @ldap_errno($ldapc) == 2) {
            @ldap_set_option($ldapc, LDAP_OPT_PROTOCOL_VERSION, 2);
            $success = @ldap_bind($ldapc, $dn, (string) $qreq->password);
        }
        if (!$success) {
            return self::fail($conf, $qreq, $ldapc);
        }

        // use LDAP information to prepopulate the database with names
        $sr = @ldap_search($ldapc, $dn, "(cn=*)",
                           array("sn", "givenname", "cn", "mail", "telephonenumber"));
        if ($sr) {
            $e = @ldap_get_entries($ldapc, $sr);
            $e = ($e["count"] == 1 ? $e[0] : array());
            if (isset($e["cn"]) && $e["cn"]["count"] == 1) {
                list($qreq->firstName, $qreq->lastName) = Text::split_name($e["cn"][0]);
            }
            if (isset($e["sn"]) && $e["sn"]["count"] == 1) {
                $qreq->lastName = $e["sn"][0];
            }
            if (isset($e["givenname"]) && $e["givenname"]["count"] == 1) {
                $qreq->firstName = $e["givenname"][0];
            }
            if (isset($e["mail"]) && $e["mail"]["count"] == 1) {
                $qreq->preferredEmail = $e["mail"][0];
            }
            if (isset($e["telephonenumber"]) && $e["telephonenumber"]["count"] == 1) {
                $qreq->phone = $e["telephonenumber"][0];
            }
        }

        ldap_close($ldapc);
        return ["ok" => true];
    }

    static private function fail(Conf $conf, Qrequest $qreq, $ldapc) {
        // connection failed, report error
        $lerrno = ldap_errno($ldapc);
        $suffix = "";
        if ($lerrno != 49) {
            $suffix = "<br><span class='hint'>(LDAP error $lerrno: " . htmlspecialchars(ldap_err2str($lerrno)) . ")</span>";
        }

        if ($lerrno < 5) {
            return [
                "ok" => false, "ldap" => true, "internal" => true, "email" => true,
                "detail_html" => "LDAP protocol error. Logins will fail until this error is fixed.$suffix"
            ];
        } else if ((string) $qreq->password === "") {
            return [
                "ok" => false, "ldap" => true, "nopw" => true,
                "detail_html" => "Password missing." . ($lerrno == 53 ? "" : $suffix)
            ];
        } else {
            return [
                "ok" => false, "ldap" => true, "invalid" => true,
                "email" => true, "password" => true,
                "detail_html" => "Invalid credentials. Please use your LDAP username and password.$suffix"
            ];
        }
    }
}
