<?php
// cdbuserupdate.php -- HotCRP class to update local database from cdb
// Copyright (c) 2006-2024 Eddie Kohler; see LICENSE.

class CdbUserUpdate {
    /** @var Conf
     * @readonly */
    private $conf;
    /** @var int
     * @readonly */
    private $cdb_confid;
    /** @var int
     * @readonly */
    private $cdb_user_update_at = 0;
    /** @var list<int> */
    private $cids = [];
    /** @var list<string> */
    private $emails = [];
    /** @var bool */
    private $all = false;

    function __construct(Conf $conf) {
        $this->conf = $conf;
        $this->cdb_confid = $conf->cdb_confid();
        if ($this->cdb_confid <= 0) {
            return;
        }
        $this->cdb_user_update_at = Dbl::fetch_ivalue($this->conf->contactdb(),
            "select user_update_at from ConferenceUpdates where confid=?",
            $this->cdb_confid) ?? 0;
        if ($this->cdb_user_update_at === 0
            || $this->cdb_user_update_at > Conf::$now - 3) {
            $this->cdb_user_update_at = Conf::$now - 3;
        }
        if ($this->cdb_user_update_at <= ($conf->setting("__cdb_user_update_at") ?? 0)) {
            // nothing to do
            $this->cdb_confid = -1;
        }
    }

    /** @param int|string ...$ids
     * @return $this */
    function add(...$ids) {
        if ($this->cdb_confid <= 0) {
            return $this;
        }
        foreach ($ids as $id) {
            if (is_int($id)) {
                $this->cids[] = $id;
            } else {
                $this->emails[] = $id;
            }
        }
        return $this;
    }

    /** @return $this */
    function add_all() {
        return $this;
    }

    /** @param string ...$fields
     * @return bool */
    function needed(...$fields) {
        if ($this->cdb_confid <= 0) {
            return false;
        } else if (empty($this->cids) && empty($this->emails)) {
            return true;
        }

        $qf = $qv = $fc = [];
        if (!empty($this->cids)) {
            $qf[] = "contactId?a";
            $qv[] = $this->cids;
        }
        if (!empty($this->emails)) {
            $qf[] = "email?a";
            $qv[] = $this->emails;
        }
        if (empty($fields)) {
            $fields = ["firstName", "lastName", "affiliation", "country", "orcid", "collaborators"];
        }
        foreach ($fields as $f) {
            if ($f === "firstName" || $f === "lastName" || $f === "affiliation") {
                $fc[] = "{$f}=''";
            } else if ($f === "country" || $f === "orcid" || $f === "collaborators") {
                $fc[] = "coalesce({$f},'')=''";
            } else {
                throw new Exception("unknown Contact field {$f}");
            }
        }
        return $this->conf->fetch_ivalue("select exists (select * from ContactInfo
            where (" . join(" or ", $qf) . ") and (" . join(" or ", $fc) . "))",
            ...$qv) > 0;
    }

    /** @param string ...$fields */
    function check(...$fields) {
        if ($this->cdb_confid <= 0
            || (!$this->all && !$this->needed(...$fields))) {
            return;
        }

        $result = $this->conf->qe("select email, contactId, if(firstName='' and lastName='',1,0)|if(affiliation='',2,0)|if(coalesce(country,'')='',4,0)|if(coalesce(orcid,'')='',8,0)|if(coalesce(collaborators,'')='',16,0) x from ContactInfo having x!=0");
        $eflags = [];
        while (($row = $result->fetch_row())) {
            $eflags[strtolower($row[0])] = [(int) $row[1], (int) $row[2]];
        }
        $result->close();

        $old_time = $this->conf->setting("__cdb_user_update_at") ?? 0;
        $result = Dbl::qe($this->conf->contactdb(),
            "select email, firstName, lastName, affiliation, country, orcid, collaborators, updateTime
            from ContactInfo
            where contactDbId in (select contactDbId from Roles where confid=?)
            and updateTime>?",
            $this->cdb_confid, $old_time);
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
