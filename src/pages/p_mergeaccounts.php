<?php
// pages/p_mergeaccounts.php -- HotCRP account merging page
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class MergeAccounts_Page {
    /** @var Conf */
    public $conf;
    /** @var Contact */
    public $user;
    /** @var Qrequest */
    public $qreq;

    function __construct(Contact $user, Qrequest $qreq) {
        $this->conf = $user->conf;
        $this->user = $user;
        $this->qreq = $qreq;
    }

    private function handle_merge() {
        if (!$this->qreq->email) {
            Ht::error_at("email", "Enter the other account’s email address");
            return false;
        }
        if (!$this->qreq->password) {
            Ht::error_at("password", "Enter the other account’s password");
            return false;
        }
        if ($this->user->is_actas_user()) {
            $this->conf->error_msg("<0>You can’t merge accounts when acting as another user");
            return false;
        }

        $other = $this->conf->user_by_email($this->qreq->email)
            ?? $this->conf->cdb_user_by_email($this->qreq->email);
        if (!$other) {
            Ht::error_at("email", "<0>Account ‘{$this->qreq->email}’ not found; please check the email address");
            return false;
        }
        if (!$other->check_password($this->qreq->password)) {
            Ht::error_at("password", "<0>Incorrect password");
            return false;
        }
        if (!$this->user->contactId && !$other->contactId) {
            $this->conf->warning_msg("<0>Neither of those accounts has any data associated with this conference");
            return false;
        }
        if ($other->contactId && $other->contactId === $this->user->contactId) {
            $this->conf->feedback_msg([new MessageItem(null, "<0>Accounts already merged", MessageSet::SUCCESS)]);
            $this->conf->redirect();
            return true;
        }
        if ($this->user->data("locked")) {
            $this->conf->error_msg("<0>Account ‘{$this->user->email}’ is locked and cannot be merged");
            return false;
        }
        if ($other->data("locked")) {
            $this->conf->error_msg("<0>Account ‘{$other->email}’ is locked and cannot be merged");
            return false;
        }

        // determine old & new users
        if ($this->qreq->prefer) {
            $merger = new MergeContacts($this->user, $other);
        } else {
            $merger = new MergeContacts($other, $this->user);
        }

        // send mail at start of process
        HotCRPMailer::send_to($merger->oldu, "@mergeaccount",
                              ["cc" => Text::nameo($merger->newu, NAME_MAILQUOTE|NAME_E),
                               "other_contact" => $merger->newu]);

        // actually merge users or change email
        $merger->run();
        if (!$merger->has_error()) {
            $merger->prepend_msg("<0>Merged account {$merger->oldu->email}", MessageSet::SUCCESS);
            $merger->newu->log_activity("Account merged " . $merger->oldu->email);
        } else {
            $merger->newu->log_activity("Account merged " . $merger->oldu->email . " with errors");
        }
        $this->conf->feedback_msg($merger);
        $this->conf->redirect();
        return true;
    }


    private function print() {
        $this->conf->header("Merge accounts", "mergeaccounts");

        echo '<div class="w-text"><p>',
            'You may have multiple accounts registered; perhaps you were asked to review
papers using different email addresses. This form will allow you to transfer information
between accounts, including authorship, reviews, and PC status. (Note that the
transfer will only affect information currently stored in this conference.)</p></div>';

        echo Ht::form($this->conf->hoturl("=mergeaccounts")),
            '<div class="', Ht::control_class("email", "f-i"), '">',
            Ht::label("Other email", "merge_email"),
            Ht::feedback_html_at("email"),
            Ht::entry("email", (string) $this->qreq->email,
                      ["size" => 36, "id" => "merge_email", "autocomplete" => "username"]),
            '</div>
    <div class="', Ht::control_class("password", "f-i fx"), '">',
            Ht::label("Other password", "merge_password"),
            Ht::feedback_html_at("password"),
            Ht::password("password", "",
                         ["size" => 36, "id" => "merge_password", "autocomplete" => "current-password"]),
        '</div>
    <div class="f-i">',
        '<div class="checki"><label><span class="checkc">',
            Ht::radio("prefer", 0, true), '</span>',
            "Keep my current account (", htmlspecialchars($this->user->email), ")</label></div>",
            '<div class="checki"><label><span class="checkc">',
            Ht::radio("prefer", 1), '</span>',
            "Keep the account named above and delete my current account</label></div>",
            '</div>',
            Ht::actions([Ht::submit("merge", "Merge accounts", ["class" => "btn-primary"])]),
            '</form>';

        $this->conf->footer();
    }

    static function go(Contact $user, Qrequest $qreq) {
        if (!$user->has_account_here()) {
            $user->escape();
        }
        $map = new MergeAccounts_Page($user, $qreq);
        if ($qreq->merge
            && $qreq->valid_post()
            && $map->handle_merge()) {
            return;
        }
        $map->print();
    }
}
