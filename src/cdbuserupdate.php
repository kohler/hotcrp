<?php
// cdbuserupdate.php -- HotCRP class to update local database <-> cdb
// Copyright (c) 2006-2026 Eddie Kohler; see LICENSE.

class CdbUserUpdate {
    /** @var Conf
     * @readonly */
    private $conf;
    /** @var int
     * @readonly */
    private $cdb_confid;
    /** @var list<int|string> */
    private $_up = [];

    function __construct(Conf $conf) {
        $this->conf = $conf;
        $this->cdb_confid = $conf->cdb_confid();
    }


    // export updates from local database to cdb
    /** @param 0|1|2|3 $type */
    function add(Contact $user, $type) {
        if ($type === Conf::CDB_UPDATE_PROFILE) {
            array_push($this->_up, $type, $user->contactDbId);
        } else if ($type === Conf::CDB_UPDATE_ROLES) {
            array_push($this->_up, $type, $user->contactId, $user->email);
        } else {
            array_push($this->_up, $type, $user->email);
        }
    }

    function __invoke() {
        $cdb = $this->conf->contactdb();
        if (empty($this->_up) || !$cdb || $this->cdb_confid < 0) {
            return;
        }

        // prefetch users, prepare queries
        $role_uids = $cu_uids = [];
        $ph_emails = $confirm_emails = [];
        for ($i = 0; $i !== count($this->_up); ) {
            if ($this->_up[$i] === Conf::CDB_UPDATE_PROFILE) {
                $cu_uids[] = $this->_up[$i + 1];
                $i += 2;
            } else if ($this->_up[$i] === Conf::CDB_UPDATE_ROLES) {
                $role_uids[] = $this->_up[$i + 1];
                $this->conf->prefetch_user_by_id($this->_up[$i + 1]);
                $this->conf->prefetch_cdb_user_by_email($this->_up[$i + 2]);
                $i += 3;
            } else if ($this->_up[$i] === Conf::CDB_UPDATE_PLACEHOLDER) {
                $ph_emails[] = $this->_up[$i + 1];
                $this->conf->prefetch_cdb_user_by_email($this->_up[$i + 1]);
                $i += 2;
            } else if ($this->_up[$i] === Conf::CDB_UPDATE_CONFIRMED) {
                $confirm_emails[] = $this->_up[$i + 1];
                $this->conf->prefetch_cdb_user_by_email($this->_up[$i + 1]);
                $i += 2;
            }
        }
        $this->_up = [];

        if (!empty($role_uids)) {
            Contact::update_cdb_roles_list($this->conf, $role_uids);
        }

        if (!empty($ph_emails)) {
            Dbl::qe($cdb, "update ContactInfo set cflags=cflags&~?
                where email?a and (cflags&?)!=0",
                Contact::CF_PLACEHOLDER, $ph_emails, Contact::CF_PLACEHOLDER);
        }

        if (!empty($confirm_emails)) {
            Dbl::qe($cdb, "update ContactInfo set cflags=cflags&~?
                where email?a and (cflags&?)!=0",
                Contact::CF_UNCONFIRMED, $confirm_emails, Contact::CF_UNCONFIRMED);
        }

        if (!empty($cu_uids)) {
            Dbl::qe($cdb, "insert into ConferenceUpdates (confid, user_update_at)
                select confid, ? from Roles where contactDbId?a and confid!=?
                on duplicate key update user_update_at=greatest(user_update_at,?)",
                Conf::$now, $cu_uids, $this->cdb_confid, Conf::$now);
        }

        if (!empty($role_uids) || !empty($ph_emails) || !empty($confirm_emails)) {
            $this->conf->invalidate_caches("cdb_users");
        }
    }


    // import nonempty properties from cdb to local database
    function import_empty_props() {
        if ($this->cdb_confid <= 0) {
            return;
        }
        $cdb_user_update_at = Dbl::fetch_ivalue($this->conf->contactdb(),
            "select user_update_at from ConferenceUpdates where confid=?",
            $this->cdb_confid) ?? 0;
        if ($cdb_user_update_at === 0
            || $cdb_user_update_at > Conf::$now - 3) {
            $cdb_user_update_at = Conf::$now - 3;
        }
        $my_user_update_at = $this->conf->setting("__cdb_user_update_at") ?? 0;
        if ($cdb_user_update_at <= $my_user_update_at) {
            // nothing to do
            return;
        }

        $result = $this->conf->qe("select email, contactId, if(firstName='' and lastName='',1,0)|if(affiliation='',2,0)|if(coalesce(country,'')='',4,0)|if(coalesce(orcid,'')='',8,0)|if(coalesce(collaborators,'')='',16,0) x
            from ContactInfo
            having x!=0");
        $eflags = [];
        while (($row = $result->fetch_row())) {
            $eflags[strtolower($row[0])] = [(int) $row[1], (int) $row[2]];
        }
        $result->close();

        $result = Dbl::qe($this->conf->contactdb(),
            "select email, firstName, lastName, affiliation, country, orcid, collaborators, updateTime
            from ContactInfo
            where contactDbId in (select contactDbId from Roles where confid=?)
            and updateTime>?",
            $this->cdb_confid, $my_user_update_at);
        $updatef = Dbl::make_multi_qe_stager($this->conf->dblink);
        $ids = $need_unaccented_ids = [];
        while (($row = $result->fetch_object())) {
            if (($idf = $eflags[strtolower($row->email)] ?? null) === null) {
                continue;
            }
            $changed = 0;
            if ((($row->firstName ?? "") !== "" || ($row->lastName ?? "") !== "")
                && ($idf[1] & 1) !== 0) {
                $updatef("update ContactInfo set firstName=?, lastName=?, updateTime=greatest(updateTime,?) where contactId=? and firstName='' and lastName=''",
                    $row->firstName, $row->lastName, (int) $row->updateTime, $idf[0]);
                $changed |= 2;
            }
            if (($row->affiliation ?? "") !== ""
                && ($idf[1] & 2) !== 0) {
                $updatef("update ContactInfo set affiliation=?, updateTime=greatest(updateTime,?) where contactId=? and affiliation=''",
                    $row->affiliation, (int) $row->updateTime, $idf[0]);
                $changed |= 2;
            }
            foreach (["country" => 4, "orcid" => 8, "collaborators" => 16] as $field => $flag) {
                if (($row->$field ?? "") === "" || ($idf[1] & $flag) === 0) {
                    continue;
                }
                $updatef("update ContactInfo set {$field}=?, updateTime=greatest(updateTime,?) where contactId=? and coalesce({$field},'')=''",
                    $row->$field, (int) $row->updateTime, $idf[0]);
                $changed |= 1;
            }
            if ($changed !== 0) {
                $ids[] = $idf[0];
            }
            if ($changed > 1) {
                $need_unaccented_ids[] = $idf[0];
            }
        }
        $updatef(null);
        $result->close();

        if (!empty($need_unaccented_ids)) {
            $this->update_unaccented_names($need_unaccented_ids);
        }
        foreach ($ids as $id) {
            $this->conf->invalidate_user_by_id($id);
        }
        $this->conf->save_setting("__cdb_user_update_at", Conf::$now - 3);
    }

    /** @param list<int> $ids */
    private function update_unaccented_names($ids) {
        $result = $this->conf->qe("select contactId, firstName, lastName, affiliation from ContactInfo where contactId?a", $ids);
        $updatef = Dbl::make_multi_qe_stager($this->conf->dblink);
        while (($row = $result->fetch_row())) {
            $n = Contact::make_db_searchable_name($row[1], $row[2], $row[3]);
            $updatef("update ContactInfo set unaccentedName=? where contactId=? and firstName=? and lastName=? and affiliation=?",
                $n, (int) $row[0], $row[1], $row[2], $row[3]);
        }
        $updatef(null);
        $result->close();
    }
}
