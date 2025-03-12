<?php
// pages/p_manageemail.php -- HotCRP email management
// Copyright (c) 2006-2025 Eddie Kohler; see LICENSE.

class ManageEmail_Page {
    /** @var Conf */
    public $conf;
    /** @var Contact */
    public $viewer;
    /** @var Contact */
    public $user;
    /** @var Qrequest */
    public $qreq;
    /** @var MessageSet */
    private $ms;

    function __construct(Contact $viewer, Qrequest $qreq) {
        $this->conf = $viewer->conf;
        $this->viewer = $this->user = $viewer;
        $this->qreq = $qreq;
        $this->ms = new MessageSet;
    }

    private function check_user() {
        $u = $this->qreq->u ?? "me";
        if ($u === ""
            || strcasecmp($u, "me") === 0
            || strcasecmp($u, $this->viewer->email) === 0) {
            return;
        }
        if (!validate_email($u)) {
            $this->print_error(JsonResult::make_parameter_error("u", "<0>Email required"));
            exit(0);
        }
        if (!$this->privChair
            && Contact::session_index_by_email($this->qreq, $u) < 0) {
            $this->print_error(JsonResult::make_permission_error("u", "<0>You can only manage email for your own accounts"));
            exit(0);
        }
        $this->user = $this->conf->user_by_email($u)
            ?? $this->conf->cdb_user_by_email($u);
        if (!$this->user) {
            $this->print_error(JsonResult::make_not_found_error("u", "<0>Account ‘{$u}’ not found"));
            exit(0);
        }
    }

    private function print_header($title = "Manage email") {
        $this->qreq->print_header($title, "manageemail", ["body_class" => "body-text"]);
    }

    private function print_error(JsonResut $jr) {
        http_response_code($jr->status ?? 400);
        $this->print_header();
        $this->conf->feedback_msg($jr->content->message_list);
        $this->qreq->print_footer();
    }

    private function handle_merge() {
        if (!$this->qreq->email) {
            $this->ms->error_at("email", "Enter the other account’s email address");
            return false;
        }
        if (!$this->qreq->password) {
            $this->ms->error_at("password", "Enter the other account’s password");
            return false;
        }
        if ($this->user->is_actas_user()) {
            $this->conf->error_msg("<0>You can’t merge accounts when acting as another user");
            return false;
        }

        $other = $this->conf->user_by_email($this->qreq->email)
            ?? $this->conf->cdb_user_by_email($this->qreq->email);
        if (!$other) {
            $this->ms->error_at("email", "<0>Account ‘{$this->qreq->email}’ not found; please check the email address");
            return false;
        }
        if (!$other->check_password($this->qreq->password)) {
            $this->ms->error_at("password", "<0>Incorrect password");
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


    private function print_start() {
        $this->print_header();
        $jr = ManageEmail_API::list($this->viewer, $this->user, null, $this->qreq);
        $actions = $jr->content["actions"];
        if (empty($actions)) {
            $this->conf->error_msg("<0>You have are no valid email management options for this account.");
        } else {
            if (in_array("transferreview", $actions)) {
                $this->print_start_transferreview();
            }
            if (in_array("link", $actions)) {
                $this->print_start_link();
            }
            if (in_array("unlink", $actions)) {
                $this->print_start_unlink();
            }
        }
        $this->qreq->print_footer();
    }

    private function print_start_transferreview() {
        echo '<div class="form-section">',
            '<h3>Transfer reviews</h3>',
            '<p>Use this option to transfer reviewing information',
            $this->user->isPC ? ", including PC membership," : "",
            ' from this account (<strong class="font-weight-semibold">', htmlspecialchars($this->user->email), '</strong>)',
            ' to another account.',
            $this->viewer->privChair ? "" : " You must be able to sign in to both accounts.",
            '</p>',
            Ht::link("Transfer reviews", $this->conf->selfurl($this->qreq, ["step" => "transferreview"]), ["class" => "btn btn-primary"]),
            '</div>';
    }

    private function print_start_link() {
        echo '<div class="form-section">',
            '<h3>Link accounts</h3>',
            '<p>Use this option to link accounts together. New review requests and email will be automatically redirected to the primary account you select. Papers authored by any of the linked accounts will also be accessible to the primary account.</p>',
            Ht::link("Link accounts", $this->conf->selfurl($this->qreq, ["step" => "link"]), ["class" => "btn btn-primary"]),
            '</div>';
    }

    private function print_start_unlink() {
        $pri = $this->user->similar_user_by_id($this->user->primaryContactId);
        echo '<div class="form-section">',
            '<h3>Unlink account</h3>',
            '<p>This account (<strong class="font-weight-semibold">', htmlspecialchars($this->user->email),
            '</strong>) is currently linked to primary account <strong class="font-weight-semibold">',
            htmlspecialchars($pri->email), '</strong>. Use this option to unlink it.</p>',
            Ht::link("Link accounts", $this->conf->selfurl($this->qreq, ["step" => "unlink"]), ["class" => "btn btn-primary"]),
            '</div>';
    }


    private function run_transferreview() {
        if ($this->qreq->step === "transferreview-prepare"
            && $this->qreq->valid_post()) {
        }

        $tok = TokenInfo::find_active($this->qreq->token, TokenInfo::MANAGEEMAIL, $this->conf);
        if (!$tok || $tok->contactId !== $this->viewer->contactId) {
            if (isset($this->qreq->token) && $this->qreq->step !== "transferreview") {            
                $this->conf->error_msg("<0>Your email management session has expired");
            }
            $tok = null;
        }

        if ($tok && $this->qreq->step !== "transferreview") {


        }

        $this->print_header("Transfer reviews");
        echo Ht::form($this->conf->hoturl("=manageemail", ["step" => "transferreview-execute"])),
            '<div class="form-outline-section">',
            '<div class="f-i">',
            '<label>Transfer reviews from</label>',
            '</div>',
            '<div class="f-i">',
            '<label>To</label>',
            '</div>',
            '</div>',
            '</form>';
        $this->qreq->print_footer();
    }


    private function print_link() {

    }


    private function print_unlink() {

    }


    private function run() {
        $step = $this->qreq->step ?? "start";
        $stepx = preg_replace('/-.*\z/', "", $step);
        if ($stepx === "start") {
            $this->print_start();
        } else if ($stepx === "transferreview") {
            $this->run_transferreview();
        } else if ($stepx === "link") {
            $this->print_link();
        } else if ($stepx === "unlink") {
            $this->print_unlink();
        } else {
            $this->print_error(JsonResult::make_not_found_error("step", "<0>Step not found"));
        }
    }

    static function go(Contact $user, Qrequest $qreq) {
        $pg = new ManageEmail_Page($user, $qreq);
        $pg->check_user();
        $pg->run();
    }
}
