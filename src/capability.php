<?php
// capability.php -- HotCRP capability management
// Copyright (c) 2006-2020 Eddie Kohler; see LICENSE.

class CapabilityManager {
    private $conf;
    private $cdb;
    private $dblink;

    function __construct(Conf $conf, $cdb) {
        $this->conf = $conf;
        $this->cdb = $cdb && $conf->contactdb();
        $this->dblink = $this->cdb ? $conf->contactdb() : $conf->dblink;
    }

    private function prefix() {
        return $this->cdb ? "U1" : "1";
    }

    function create(Contact $user, $capabilityType, $options = []) {
        $contactId = $this->cdb ? $user->contactdb_user()->contactDbId : $user->contactId;
        $paperId = get($options, "paperId", 0);
        $timeExpires = get($options, "timeExpires", time() + 259200);
        $data = get($options, "data");
        $ok = false;

        for ($tries = 0; !$ok && $tries < 4; ++$tries) {
            if (($salt = random_bytes(16)) !== false) {
                $result = Dbl::ql($this->dblink, "insert into Capability set capabilityType=?, contactId=?, paperId=?, timeExpires=?, salt=?, data=?", $capabilityType, $contactId, $paperId, $timeExpires, $salt, $data);
                $ok = $result && $result->affected_rows > 0;
            }
        }

        if ($ok) {
            return $this->prefix() . str_replace(["+", "/", "="], ["-a", "-b", ""], base64_encode($salt));
        } else {
            return false;
        }
    }

    function check($capabilityText) {
        if (!str_starts_with($capabilityText, $this->prefix())) {
            return false;
        }
        $value = base64_decode(str_replace(["-a", "-b"], ["+", "/"],
                                           substr($capabilityText, strlen($this->prefix()))));
        if (strlen($value) >= 2
            && ($result = Dbl::ql($this->dblink, "select * from Capability where salt=?", $value))
            && ($row = Dbl::fetch_first_object($result))
            && ($row->timeExpires == 0 || $row->timeExpires >= time())) {
            return $row;
        } else {
            return false;
        }
    }

    function delete($capdata) {
        assert(!$capdata || is_string($capdata->salt));
        if ($capdata) {
            Dbl::ql($this->dblink, "delete from Capability where salt=?", $capdata->salt);
        }
    }

    function user_by_capability_data($capdata) {
        if ($capdata && isset($capdata->contactId) && $capdata->contactId) {
            $method = $this->cdb ? "contactdb_user_by_id" : "user_by_id";
            return $this->conf->$method($capdata->contactId);
        } else {
            return null;
        }
    }


    static function capability_text($prow, $capType) {
        // A capability has the following representation (. is concatenation):
        //    capFormat . paperId . capType . hashPrefix
        // capFormat -- Character denoting format (currently 0).
        // paperId -- Decimal representation of paper number.
        // capType -- Capability type (e.g. "a" for author view).
        // To create hashPrefix, calculate a SHA-1 hash of:
        //    capFormat . paperId . capType . paperCapVersion . capKey
        // where paperCapVersion is a decimal representation of the paper's
        // capability version (usually 0, but could allow conference admins
        // to disable old capabilities paper-by-paper), and capKey
        // is a random string specific to the conference, stored in Settings
        // under cap_key (created in load_settings).  Then hashPrefix
        // is the base-64 encoding of the first 8 bytes of this hash, except
        // that "+" is re-encoded as "-", "/" is re-encoded as "_", and
        // trailing "="s are removed.
        //
        // Any user who knows the conference's cap_key can construct any
        // capability for any paper.  Longer term, one might set each paper's
        // capVersion to a random value; but the only way to get cap_key is
        // database access, which would give you all the capVersions anyway.

        $key = $prow->conf->setting_data("cap_key");
        if (!$key) {
            $key = base64_encode(random_bytes(16));
            if (!$key || !$prow->conf->save_setting("cap_key", 1, $key))
                return false;
        }
        $start = "0" . $prow->paperId . $capType;
        $hash = sha1($start . $prow->capVersion . $key, true);
        $suffix = str_replace(array("+", "/", "="), array("-", "_", ""),
                              base64_encode(substr($hash, 0, 8)));
        return $start . $suffix;
    }

    static function apply_hoturl_capability($name, $isadd) {
        if (Conf::$hoturl_defaults === null) {
            Conf::$hoturl_defaults = [];
        }
        $cap = urldecode(get(Conf::$hoturl_defaults, "cap", ""));
        $a = array_diff(explode(" ", $cap), [$name, ""]);
        if ($isadd) {
            $a[] = $name;
        }
        if (empty($a)) {
            unset(Conf::$hoturl_defaults["cap"]);
        } else {
            Conf::$hoturl_defaults["cap"] = urlencode(join(" ", $a));
        }
    }

    static function apply_old_author_view(Contact $user, $uf, $isadd) {
        if (($prow = $user->conf->fetch_paper(["paperId" => $uf->match_data[1]]))
            && ($uf->name === self::capability_text($prow, "a"))
            && !$user->conf->opt("disableCapabilities")) {
            $user->set_capability("@av{$prow->paperId}", $isadd ? true : null);
            if ($user->is_activated())
                self::apply_hoturl_capability($uf->name, $isadd);
        }
    }

    private static function make_review_acceptor($user, $at, $pid, $cid, $uf) {
        global $Now;
        if ($at && $at >= $Now - 2592000) {
            $user->set_capability("@ra$pid", $cid);
            if ($user->is_activated()) {
                ensure_session();
                self::apply_hoturl_capability($uf->name, $cid);
            }
        } else if ($cid && $cid != $user->contactId) {
            $user->conf->warnMsg("The review link you followed has expired. Sign in to the site to view or edit reviews.");
        }
    }

    static function apply_review_acceptor(Contact $user, $uf, $isadd) {
        global $Now;

        $result = $user->conf->qe("select * from PaperReview where reviewId=?", $uf->match_data[1]);
        $rrow = ReviewInfo::fetch($result, $user->conf);
        Dbl::free($result);
        if ($rrow && $rrow->acceptor_is($uf->match_data[2])) {
            self::make_review_acceptor($user, $rrow->acceptor()->at, $rrow->paperId, $isadd ? (int) $rrow->contactId : null, $uf);
            return;
        }

        $result = $user->conf->qe("select * from PaperReviewRefused where `data` is not null and timeRefused>=?", $Now - 604800);
        while (($refusal = $result->fetch_object())) {
            $data = json_decode($refusal->data);
            if ($data
                && isset($data->acceptor)
                && isset($data->acceptor->text)
                && $data->acceptor->text === $uf->match_data[2]) {
                self::make_review_acceptor($user, $data->acceptor->at, $refusal->paperId, $isadd ? (int) $refusal->contactId : null, $uf);
                Dbl::free($result);
                return;
            }
        }
        Dbl::free($result);

        $user->conf->warnMsg("The review link you followed is no longer active. Sign in to the site to view or edit reviews.");
    }
}
