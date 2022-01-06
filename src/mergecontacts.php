<?php
// mergecontacts.php -- HotCRP helper class for merging users
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class MergeContacts extends MessageSet {
    /** @var Conf */
    private $conf;
    /** @var Contact */
    public $oldu;
    /** @var Contact */
    public $newu;

    /** @param Contact $oldu
     * @param Contact $newu */
    function __construct($oldu, $newu) {
        assert($oldu->conf === $newu->conf);
        assert($oldu->contactId || $newu->contactId);
        $this->conf = $oldu->conf;
        $this->oldu = $oldu;
        $this->newu = $newu;
        $this->set_want_ftext(true, 5);
    }

    private function add_error($msg) {
        $this->error_at("merge", $msg);
        error_log($msg);
        error_log(debug_string_backtrace());
    }
    private function q($q, ...$args) {
        $result = $this->conf->q_apply($q, $args);
        if ($result->errno) {
            $this->add_error("<5>" . $this->conf->db_error_html(true));
        }
    }
    private function qx($q, ...$args) {
        $result = Dbl::qx_apply($this->conf->dblink, $q, $args);
        if ($result->errno) {
            $this->add_error("<5>" . $this->conf->db_error_html(true));
        }
    }
    private function merge1($table, $idfield) {
        $this->q("update $table set $idfield=? where $idfield=?",
                 $this->newu->contactId, $this->oldu->contactId);
    }
    private function merge1_ignore($table, $idfield) {
        $this->qx("update ignore $table set $idfield=? where $idfield=?",
                  $this->newu->contactId, $this->oldu->contactId);
        $this->q("delete from $table where $idfield=?", $this->oldu->contactId);
    }
    private function replace_contact_string($k) {
        return (string) $this->oldu->prop($k) !== ""
            && (string) $this->newu->prop($k) === "";
    }
    private function basic_user_json() {
        $cj = (object) ["email" => $this->newu->email];

        foreach (["firstName", "lastName", "affiliation", "country",
                  "collaborators", "phone"] as $k) {
            if ($this->replace_contact_string($k))
                $cj->$k = $this->oldu->prop($k);
        }

        if (($old_data = $this->oldu->data())) {
            $cj->data = (object) [];
            $new_data = $this->newu->data();
            foreach ($old_data as $k => $v) {
                if (!isset($new_data->$k))
                    $cj->data->$k = $v;
            }
        }

        return $cj;
    }
    private function merge() {
        assert($this->oldu->contactId && $this->newu->contactId);

        // Paper and PaperConflict
        $this->conf->q_raw("lock tables Paper write, PaperConflict write");
        $this->merge1("Paper", "leadContactId");
        $this->merge1("Paper", "shepherdContactId");
        $this->merge1("Paper", "managerContactId");

        $result = $this->conf->qe("select paperId, contactId, conflictType from PaperConflict where contactId=? or contactId=?", $this->oldu->contactId, $this->newu->contactId);
        $pold = $pnew = [];
        while (($row = $result->fetch_row())) {
            $pid = (int) $row[0];
            if ($row[1] == $this->oldu->contactId) {
                $pold[$pid] = (int) $row[2];
                $pnew[$pid] = $pnew[$pid] ?? 0;
            } else {
                $pnew[$pid] = (int) $row[2];
            }
        }
        Dbl::free($result);

        $qv = [];
        foreach ($pold as $pid => $ctype) {
            if (($pnew[$pid] = Conflict::merge($pnew[$pid], $pold[$pid]))) {
                $qv[] = [$pid, $this->newu->contactId, $pnew[$pid]];
            }
        }
        if (!empty($qv)) {
            $this->q("insert into PaperConflict (paperId,contactId,conflictType) values ?v ?U on duplicate key update conflictType=?U(conflictType)", $qv);
        }
        $this->q("delete from PaperConflict where contactId=?", $this->oldu->contactId);
        $this->conf->q_raw("unlock tables");

        // TopicInterest, PaperReviewPreference, PaperWatch
        $this->conf->q_raw("lock tables TopicInterest write, PaperReviewPreference write, PaperWatch write");
        $this->merge1_ignore("TopicInterest", "contactId");
        $this->merge1_ignore("PaperReviewPreference", "contactId");
        $this->merge1_ignore("PaperWatch", "contactId");
        $this->conf->q_raw("unlock tables");

        // PaperReview
        $this->conf->q_raw("lock tables PaperReview write");
        $this->merge1_ignore("PaperReview", "contactId");
        $this->merge1("PaperReview", "requestedBy");
        $this->conf->q_raw("unlock tables");

        // PaperComment
        $this->conf->q_raw("lock tables PaperComment write");
        $this->merge1("PaperComment", "contactId");
        $this->conf->q_raw("unlock tables");

        // PaperReviewRefused, ReviewRating, ReviewRequest
        $this->conf->q_raw("lock tables PaperReviewRefused write, ReviewRating write, ReviewRequest write");
        $this->merge1("PaperReviewRefused", "contactId");
        $this->merge1("PaperReviewRefused", "requestedBy");
        $this->merge1("PaperReviewRefused", "refusedBy");
        $this->merge1_ignore("ReviewRating", "contactId");
        $this->merge1("ReviewRequest", "requestedBy");
        $this->conf->qe_raw("unlock tables");

        // PaperTag, TagAnno
        if ($this->oldu->roles & Contact::ROLE_PCLIKE) {
            $oldpfxlen = strlen((string) $this->oldu->contactId) + 2;
            $this->conf->qe_raw("lock tables PaperTag write, PaperTagAnno write");
            $this->qx("update ignore PaperTag set tag=concat('" . $this->newu->contactId . "~',substring(tag," . $oldpfxlen . ")) where tag like '" . $this->oldu->contactId . "~%'");
            $this->q("delete from PaperTag where tag like '" . $this->oldu->contactId . "~%'");
            $this->qx("update ignore PaperTagAnno set tag=concat('" . $this->newu->contactId . "~',substring(tag," . $oldpfxlen . ")) where tag like '" . $this->oldu->contactId . "~%'");
            $this->q("delete from PaperTagAnno where tag like '" . $this->oldu->contactId . "~%'");
            $this->conf->qe_raw("unlock tables");
        }

        // ActionLog, Formula
        $this->conf->q_raw("lock tables ActionLog write, Formula write");
        $this->merge1("ActionLog", "contactId");
        $this->merge1("ActionLog", "destContactId");
        $this->merge1("Formula", "createdBy");
        $this->conf->q_raw("unlock tables");

        Contact::update_rights();

        // merge user data via Contact::save_json
        $cj = $this->basic_user_json();
        if (($this->oldu->roles | $this->newu->roles) != $this->newu->roles) {
            $cj->roles = UserStatus::unparse_roles_json($this->oldu->roles | $this->newu->roles);
        }
        $cj->tags = [];
        foreach (Tagger::split_unpack($this->newu->contactTags) as $ti) {
            $cj->tags[] = $ti[0] . "#" . ($ti[1] ?? 0);
        }
        foreach (Tagger::split_unpack($this->oldu->contactTags) as $ti) {
            if ($this->newu->tag_value($ti[0]) === null) {
                $cj->tags[] = $ti[0] . "#" . ($ti[1] ?? 0);
            }
        }
        $us = new UserStatus($this->conf->root_user());
        $us->save($cj, $this->newu);

        // remove the old contact record
        if (!$this->has_error()) {
            $this->conf->q("insert into DeletedContactInfo set contactId=?, firstName=?, lastName=?, unaccentedName=?, email=?, affiliation=?", $this->oldu->contactId, $this->oldu->firstName, $this->oldu->lastName, $this->oldu->unaccentedName, $this->oldu->email, $this->oldu->affiliation);
            $result = $this->conf->q("delete from ContactInfo where contactId=?", $this->oldu->contactId);
            if ($result->errno) {
                $this->add_error("<5>" . $this->conf->db_error_html(true));
            }
        }

        // update automatic tags
        $this->conf->update_automatic_tags();
    }

    function run() {
        // actually merge users or change email
        if ($this->oldu->contactId && $this->newu->contactId) {
            // both users in database
            $this->merge();
        } else {
            $user_status = new UserStatus($this->oldu);
            if ($this->oldu->contactId) {
                // new user in contactdb, old user in database
                $user_status->user = $this->newu;
                $user_status->save($user_status->user_json(), $this->oldu);
            } else {
                // old user in contactdb, new user in database
                $user_status->save($this->basic_user_json(), $this->newu);
            }
            $this->append_set($user_status);
        }
        return !$this->has_error();
    }
}
