<?php
// mergecontacts.php -- HotCRP helper class for merging users
// HotCRP is Copyright (c) 2006-2017 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

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
    private function merge() {
        assert($this->oldu->contactId && $this->newu->contactId);

        $this->conf->q_raw("lock tables Paper write, ContactInfo write, PaperConflict write, ActionLog write, TopicInterest write, PaperComment write, PaperReview write, PaperReview as B write, PaperReviewPreference write, PaperReviewRefused write, ReviewRequest write, PaperWatch write, ReviewRating write");

        $this->merge1("Paper", "leadContactId");
        $this->merge1("Paper", "shepherdContactId");
        $this->merge1("Paper", "managerContactId");

        // paper authorship
        $result = $this->conf->qe_raw("select paperId, authorInformation from Paper where authorInformation like " . Dbl::utf8ci("'%\t" . sqlq_for_like($old_user->email) . "\t%'"));
        $q = $qv = [];
        while (($row = PaperInfo::fetch($result, null, $this->conf))) {
            foreach ($row->author_list() as $au)
                if (strcasecmp($au->email, $old_user->email) == 0)
                    $au->email = $new_user->email;
            $q[] = "update Paper set authorInformation=? where paperId=?";
            array_push($qv, $row->parse_author_list(), $row->paperId);
        }
        $mresult = Dbl::multi_qe_apply($this->conf->dblink, join(";", $q), $qv);
        $mresult->free_all();

        // ensure uniqueness in PaperConflict
        $result = $this->conf->qe("select paperId, conflictType from PaperConflict where contactId=?",
                                  $this->oldu->contactId);
        $qv = [];
        while (($row = edb_row($result)))
            $qv[] = [$row[0], $this->newu->contactId, $row[1]];
        if ($qv)
            $this->conf->qe("insert into PaperConflict (paperId, contactId, conflictType) values ?v on duplicate key update conflictType=greatest(conflictType, values(conflictType))", $qv);
        $this->conf->qe("delete from PaperConflict where contactId=?", $this->oldu->contactId);

        // merge user data: roles and tags
        if (($this->oldu->roles | $this->newu->roles) != $this->newu->roles) {
            $this->newu->roles |= $this->oldu->roles;
            $this->conf->qe("update ContactInfo set roles=? where contactId=?",
                            $this->newu->roles, $this->newu->contactId);
        }

        $old_tags = $this->newu->contactTags;
        if ($this->oldu->contactTags)
            foreach (TagInfo::split_unpack($this->oldu->contactTags) as $ti) {
                if ($this->newu->tag_value($ti[0]) === false) {
                    if ((string) $this->newu->contactTags === "")
                        $this->newu->contactTags = " ";
                    $this->newu->contactTags .= $ti[0] . "#" . $ti[1] . " ";
                }
            }
        if ($old_tags !== $this->newu->contactTags)
            $this->conf->qe("update ContactInfo set contactTags=? where contactId=?",
                            $this->newu->contactTags, $this->newu->contactId);

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

        // Remove the old contact record
        if (!$this->has_error
            && !$this->conf->q("delete from ContactInfo where contactId=?",
                               $this->oldu->contactId))
            $this->add_error($this->conf->db_error_html(true));

        $this->conf->qe_raw("unlock tables");
        if ($this->oldu->roles)
            $this->conf->invalidate_caches("pc");
    }

    function run() {
        // actually merge users or change email
        if ($this->oldu->contactId && $this->newu->contactId)
            $this->merge();
        else if ($this->oldu->contactId) {
            $user_status = new UserStatus(["send_email" => false]);
            $user_status->save($user_status->user_to_json($this->newu), $this->oldu);
            foreach ($user_status->errors() as $e)
                $this->add_error($e);
        }
        return !$this->has_error;
    }
}
