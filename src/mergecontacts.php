<?php
// mergecontacts.php -- HotCRP helper class for merging users
// Copyright (c) 2006-2018 Eddie Kohler; see LICENSE.

class MergeContacts extends MessageSet {
    private $conf;
    public $oldu;
    public $newu;

    function __construct($oldu, $newu) {
        assert($oldu->conf === $newu->conf);
        assert($oldu->contactId || $newu->contactId);
        $this->conf = $oldu->conf;
        $this->oldu = $oldu;
        $this->newu = $newu;
    }

    private function add_error($msg) {
        $this->error_at("merge", $msg);
    }
    private function merge1($table, $idfield) {
        if (!$this->conf->q("update $table set $idfield=? where $idfield=?",
                            $this->newu->contactId, $this->oldu->contactId))
            $this->add_error($this->conf->db_error_html(true));
    }
    private function merge1_ignore($table, $idfield) {
        if (!$this->conf->q("update ignore $table set $idfield=? where $idfield=?",
                            $this->newu->contactId, $this->oldu->contactId)
            && !$this->conf->q("delete from $table where $idfield=?",
                               $this->oldu->contactId))
            $this->add_error($this->conf->db_error_html(true));
    }
    private function replace_contact_string($k) {
        return (string) $this->oldu->$k !== "" && (string) $this->newu->$k === "";
    }
    private function basic_user_json() {
        $cj = (object) ["email" => $this->newu->email];

        foreach (["firstName", "lastName", "affiliation", "country",
                  "collaborators", "phone"] as $k)
            if ($this->replace_contact_string($k))
                $cj->$k = $this->oldu->$k;

        if (($old_data = $this->oldu->data())) {
            $cj->data = (object) [];
            $new_data = $this->newu->data();
            foreach ($old_data as $k => $v)
                if (!isset($new_data->$k))
                    $cj->data->$k = $v;
        }

        return $cj;
    }
    private function merge() {
        assert($this->oldu->contactId && $this->newu->contactId);

        $this->conf->q_raw("lock tables Paper write, ContactInfo write, PaperConflict write, ActionLog write, TopicInterest write, PaperComment write, PaperReview write, PaperReview as B write, PaperReviewPreference write, PaperReviewRefused write, ReviewRequest write, PaperWatch write, ReviewRating write");

        $this->merge1("Paper", "leadContactId");
        $this->merge1("Paper", "shepherdContactId");
        $this->merge1("Paper", "managerContactId");

        // paper authorship
        $result = $this->conf->qe_raw("select paperId, authorInformation from Paper where authorInformation like " . Dbl::utf8ci("'%\t" . sqlq_for_like($this->oldu->email) . "\t%'"));
        $q = $qv = [];
        while (($row = PaperInfo::fetch($result, null, $this->conf))) {
            foreach ($row->author_list() as $au)
                if (strcasecmp($au->email, $this->oldu->email) == 0)
                    $au->email = $this->newu->email;
            $q[] = "update Paper set authorInformation=? where paperId=?";
            array_push($qv, $row->parse_author_list(), $row->paperId);
        }
        if (!empty($q)) {
            $mresult = Dbl::multi_qe_apply($this->conf->dblink, join(";", $q), $qv);
            $mresult->free_all();
        }

        // ensure uniqueness in PaperConflict
        $result = $this->conf->qe("select paperId, conflictType from PaperConflict where contactId=?", $this->oldu->contactId);
        $qv = [];
        while (($row = edb_row($result)))
            $qv[] = [$row[0], $this->newu->contactId, $row[1]];
        if ($qv)
            $this->conf->qe("insert into PaperConflict (paperId, contactId, conflictType) values ?v on duplicate key update conflictType=greatest(conflictType, values(conflictType))", $qv);
        $this->conf->qe("delete from PaperConflict where contactId=?", $this->oldu->contactId);

        // merge more things
        $this->merge1("ActionLog", "contactId");
        $this->merge1_ignore("TopicInterest", "contactId");
        $this->merge1("PaperComment", "contactId");

        // archive duplicate reviews
        $this->merge1_ignore("PaperReview", "contactId");
        $this->merge1("PaperReview", "requestedBy");
        $this->merge1_ignore("PaperReviewPreference", "contactId");
        $this->merge1("PaperReviewRefused", "contactId");
        $this->merge1("PaperReviewRefused", "requestedBy");
        $this->merge1("ReviewRequest", "requestedBy");
        $this->merge1_ignore("PaperWatch", "contactId");
        $this->merge1_ignore("ReviewRating", "contactId");

        $this->conf->qe_raw("unlock tables");
        Contact::update_rights();

        // merge user data via Contact::save_json
        $cj = $this->basic_user_json();

        if (($this->oldu->roles | $this->newu->roles) != $this->newu->roles)
            $cj->roles = UserStatus::unparse_roles_json($this->oldu->roles | $this->newu->roles);

        $cj->tags = [];
        foreach (TagInfo::split_unpack($this->newu->contactTags) as $ti)
            $cj->tags[] = $ti[0] . "#" . ($ti[1] ? : 0);
        foreach (TagInfo::split_unpack($this->oldu->contactTags) as $ti)
            if ($this->newu->tag_value($ti[0]) === false)
                $cj->tags[] = $ti[0] . "#" . ($ti[1] ? : 0);

        $us = new UserStatus($this->conf->site_contact(), ["send_email" => false]);
        $us->save($cj, $this->newu);

        // Remove the old contact record
        if (!$this->has_error()) {
            $this->conf->q("insert into DeletedContactInfo set contactId=?, firstName=?, lastName=?, unaccentedName=?, email=?", $this->oldu->contactId, $this->oldu->firstName, $this->oldu->lastName, $this->oldu->unaccentedName, $this->oldu->email);
            if (!$this->conf->q("delete from ContactInfo where contactId=?", $this->oldu->contactId))
                $this->add_error($this->conf->db_error_html(true));
        }
    }

    function run() {
        // actually merge users or change email
        if ($this->oldu->contactId && $this->newu->contactId)
            // both users in database
            $this->merge();
        else {
            $user_status = new UserStatus($this->oldu, ["send_email" => false]);
            if ($this->oldu->contactId) {
                // new user in contactdb, old user in database
                $user_status->user = $this->newu;
                $user_status->save($user_status->user_json(), $this->oldu);
            } else {
                // old user in contactdb, new user in database
                $user_status->save($this->basic_user_json(), $this->newu);
            }
            foreach ($user_status->errors() as $e)
                $this->add_error($e);
        }
        return !$this->has_error();
    }
}
