<?php
// pages/p_manageemail.php -- HotCRP email management
// Copyright (c) 2006-2025 Eddie Kohler; see LICENSE.

class ManageEmailStep {
    /** @var int */
    public $index;
    /** @var string */
    public $name;
    /** @var string */
    public $header;
    /** @var ?string */
    public $next_label;
    /** @var ?string */
    public $next_class;
    /** @var bool */
    public $skip_back = false;

    function __construct($index, $name, $header) {
        $this->index = $index;
        $this->name = $name;
        $this->header = $header;
    }

    /** @param string $label
     * @param string $class
     * @return $this */
    function set_next($label, $class = "btn-primary") {
        $this->next_label = $label;
        $this->next_class = $class;
        return $this;
    }

    /** @param bool $x
     * @return $this */
    function set_skip_back($x) {
        $this->skip_back = $x;
        return $this;
    }
}

class ManageEmail_Page {
    /** @var Conf */
    public $conf;
    /** @var Contact */
    public $viewer;
    /** @var ?Contact */
    private $user;
    /** @var ?Contact */
    private $dstuser;
    /** @var bool */
    private $viewer_involved;
    /** @var bool */
    private $allow_any = false;
    /** @var Qrequest */
    public $qreq;
    /** @var MessageSet */
    private $ms;

    /** @var string */
    private $type;
    /** @var list<ManageEmailStep> */
    private $steps = [];
    /** @var ManageEmailStep */
    private $curstep;
    /** @var bool */
    private $done;
    /** @var ?TokenInfo */
    private $token;
    /** @var ?AuthenticationChecker */
    private $authchecker;

    function __construct(Contact $viewer, Qrequest $qreq) {
        $this->conf = $viewer->conf;
        $this->viewer = $this->user = $viewer;
        $this->qreq = $qreq;
        $this->ms = new MessageSet;
    }

    private function print_header($subtitle = null) {
        if ($subtitle === null) {
            $this->qreq->print_header("Manage email", "manageemail", ["body_class" => "body-text"]);
        } else {
            $title_div = "<div id=\"h-page\"><h1><a href=\""
                . $this->conf->hoturl("manageemail", ["u" => $this->user ? $this->user->email : null])
                . "\" class=\"q\">Manage email</a> <span class=\"pl-2 pr-2\">&#x2215;</span> <strong>{$subtitle}</strong></h1></div>";
            $this->qreq->print_header($subtitle, "manageemail", ["body_class" => "body-text", "title_div" => $title_div]);
        }
    }

    /** @return PageCompletion */
    private function print_error(JsonResult $jr) {
        http_response_code($jr->status ?? 400);
        $this->print_header();
        $this->conf->feedback_msg($jr->content["message_list"] ?? []);
        $this->qreq->print_footer();
        return new PageCompletion;
    }


    /** @return ManageEmailStep */
    private function register_step($name, $header) {
        $step = new ManageEmailStep(count($this->steps), $name, $header);
        $this->steps[] = $step;
        return $step;
    }

    /** @return ?ManageEmailStep */
    private function find_step($name) {
        foreach ($this->steps as $step) {
            if ($step->name === $name)
                return $step;
        }
        return null;
    }

    private function set_step($name) {
        $this->curstep = $this->find_step($name);
        if (!$this->curstep) {
            throw $this->print_error(JsonResult::make_not_found_error("step", "<0>Step not found"));
        }
    }

    /** @param int $delta
     * @return ?ManageEmailStep */
    private function delta_step($delta) {
        $i = $this->curstep->index + $delta;
        while ($i > 0 && $delta < 0 && $this->steps[$i]->skip_back) {
            --$i;
        }
        return $this->steps[$i] ?? null;
    }


    private function choose() {
        $this->print_header();
        $jr = (new ManageEmail_API($this->viewer, $this->qreq))
            ->set_user($this->user)
            ->list();
        $actions = $jr->content["actions"] ?? [];
        $nactions = 0;
        if (in_array("transferreview", $actions, true)) {
            $this->choose_transferreview();
            ++$nactions;
        }
        if (in_array("link", $actions, true)) {
            $this->choose_link();
            ++$nactions;
        }
        if (in_array("unlink", $actions, true)) {
            $this->choose_unlink();
            ++$nactions;
        }
        if ($nactions === 0) {
            $this->conf->error_msg("<0>You have no valid email management options for this account.");
        }
        $this->qreq->print_footer();
    }

    private function choose_transferreview() {
        echo '<div class="form-section">',
            '<h3>Transfer reviews</h3>';
        if ($this->viewer->privChair) {
            echo '<p>Use this option to transfer existing reviews and reviewing information, including PC membership, from one account to another.</p>';
        } else {
            echo '<p>Use this option to transfer existing reviews and reviewing information',
                $this->user->isPC ? ", including PC membership," : "",
                ' from this account (<strong class="sb">', htmlspecialchars($this->user->email), '</strong>)',
                ' to another account.',
                $this->viewer->privChair ? "" : " You must be able to sign in to both accounts.",
                '</p>';
        }
        echo Ht::link("Transfer reviews", $this->conf->selfurl($this->qreq, ["t" => "transferreview"]), ["class" => "btn btn-primary"]),
            '</div>';
    }

    private function choose_link() {
        echo '<div class="form-section">',
            '<h3>Link accounts</h3>',
            '<p>If you have multiple accounts, this option can define a primary account for site use. New review requests and new PC assignments will be redirected from linked accounts to the primary account. Papers authored by any of the linked accounts will be accessible to the primary account, and emails intended for authors will be delivered only to the primary account.</p>',
            Ht::link("Link accounts", $this->conf->selfurl($this->qreq, ["t" => "link"]), ["class" => "btn btn-primary"]),
            '</div>';
    }

    private function can_unlink() {
        if ($this->user->primaryContactId > 0) {
            return true;
        }
        $emails = Contact::session_emails($this->qreq);
        $this->conf->prefetch_users_by_email($emails);
        $this->conf->prefetch_cdb_users_by_email($emails);
        $any = null;
        foreach ($emails as $e) {
            if (($u = $this->conf->user_by_email($e))
                && ($u->primaryContactId > 0
                    || (($uu = $u->cdb_user()) && $uu->primaryContactId > 0))) {
                return true;
            }
        }
        return false;
    }

    private function choose_unlink() {
        if (!$this->can_unlink()) {
            return;
        }
        echo '<div class="form-section">',
            '<h3>Unlink accounts</h3>';
        if ($this->user->primaryContactId > 0) {
            if ($this->user->is_cdb_user()) {
                $pri = $this->conf->cdb_user_by_id($this->user->primaryContactId);
            } else {
                $pri = $this->conf->user_by_id($this->user->primaryContactId);
            }
            echo '<p>This account, <strong class="sb">', htmlspecialchars($this->user->email),
                '</strong>, is currently linked to primary account <strong class="sb">',
                htmlspecialchars($pri->email), '</strong>. Use this option to unlink it.</p>';
        } else {
            echo '<p>Use this option to unlink an account from its primary account.</p>';
        }
        echo Ht::link("Unlink accounts", $this->conf->selfurl($this->qreq, ["t" => "unlink", "u" => $this->user->email]), ["class" => "btn btn-primary"]),
            '</div>';
    }

    /** @return string */
    private function step_hoturl($arg = [], $flags = 0) {
        return $this->conf->hoturl("manageemail", array_merge([
            "t" => $this->type,
            "step" => $this->curstep->name,
            "mesess" => $this->token ? $this->token->salt : null
        ], $arg), $flags);
    }

    /** @param ManageEmailStep $step */
    private function print_enter_step($step) {
        $delta = $step->index - $this->curstep->index;
        echo '<fieldset class="wizard-accordion-step ',
            $delta < 0 ? "past" : ($delta === 0 ? "current" : "future"),
            '"><legend>';
        if ($delta < 0 && !$this->done) {
            echo '<a href="', $this->step_hoturl(["step" => $step->name]), '" class="q">';
        }
        echo '&#', 0x278A + $step->index, '; ', $step->header;
        if ($delta < 0 && !$this->done) {
            echo '</a>';
        }
        echo '</legend>';
    }

    private function print_leave_step() {
        echo '</fieldset>';
    }

    /** @return TokenInfo */
    private function create_token() {
        if ($this->token && $this->token->input("t") === $this->type) {
            return $this->token;
        }
        $this->token = ManageEmail_Token::prepare($this->viewer)
            ->set_input("t", $this->type)
            ->insert();
        if (!$this->token->stored()) {
            throw $this->print_error(JsonResult::make_parameter_error("mesess", "<0>Error opening email management session"));
        }
        return $this->token;
    }

    private function redirect_token() {
        if ($this->ms->has_message()) {
            $this->token->change_data("ms", $this->ms->message_list());
        }
        $this->token->update();
        $this->conf->redirect_hoturl("manageemail", [
            "t" => $this->token->input("t"),
            "step" => $this->token->data("step"),
            "mesess" => $this->token->salt
        ]);
    }

    private function parse_user($key, $ue) {
        if ($ue === null || $ue === "") {
            $this->ms->error_at($key, "<0>Entry required");
            return null;
        }
        $meapi = new ManageEmail_API($this->viewer, $this->qreq);
        $u = $meapi->find_user($ue, $key);
        if (($ec = $meapi->user_ec($u, $key))) {
            if ($ec === "signin") {
                $this->ms->error_at($key, "<0>You can only manage email for your own accounts");
            } else {
                $this->ms->append_list($meapi->message_list());
            }
            return null;
        }
        if (!$u->contactId && !$u->contactDbId) {
            $this->ms->error_at($key, "<0>Account not found");
            return null;
        }
        return $u;
    }

    /** @return ?AuthenticationChecker */
    private function find_failed_authchecker() {
        $acv = $this->viewer->authentication_checker($this->qreq, "manageemail");
        if (!$acv->test()) {
            return $acv;
        }
        if ($this->allow_any) {
            return null;
        }
        if ($this->user !== $this->viewer) {
            $ac = $this->user->authentication_checker($this->qreq, "manageemail");
            if (!$ac->test()) {
                return $ac;
            }
        }
        if ($this->dstuser && $this->dstuser !== $this->viewer) {
            $ac = $this->dstuser->authentication_checker($this->qreq, "manageemail");
            if (!$ac->test()) {
                return $ac;
            }
        }
        return null;
    }

    private function print_user_selector($key) {
        $curval = $this->qreq[$key];
        if ($curval === "other") {
            $curval = $this->qreq["{$key}:entry"];
        } else if ($curval === null) {
            $curval = $this->token ? $this->token->data($key) ?? "" : "";
        }
        if ($curval === "" && $this->qreq->signedin) {
            $latest_use = null;
            foreach (Contact::session_emails($this->qreq) as $e) {
                $use = UserSecurityEvent::session_latest_signin_by_email($this->qreq->qsession(), $e);
                if ($use && (!$latest_use || $use->timestamp > $latest_use->timestamp)) {
                    $latest_use = $use;
                    $curval = $e;
                }
            }
        } else if ($curval === "" && $key === "u") {
            $curval = $this->viewer->email;
        }
        $selected = null;
        $opts = [];
        foreach (Contact::session_emails($this->qreq) as $e) {
            $opt = ["value" => $e];
            if ($key === "email"
                && $this->token
                && strcasecmp($this->token->data("u"), $e) === 0) {
                $opt["disabled"] = true;
            }
            if (strcasecmp($curval, $e) === 0) {
                $selected = $curval;
            }
            $opts[] = $opt;
        }
        $opts[] = ["value" => "new", "label" => "Sign in to another account…"];
        if ($curval === "new") {
            $selected = "new";
        }
        if ($this->allow_any) {
            $opts[] = ["value" => "other", "label" => "Other"];
            if ($selected === null && $curval !== "") {
                $selected = "other";
            }
        }
        echo $this->ms->feedback_html_at($key), '<div class="',
            $this->ms->control_class($key, "has-fold fold" . ($selected === "other" ? "o" : "c")),
            '" data-fold-values="other">';
        if ($this->allow_any) {
            echo Ht::select($key, $opts, $selected, ["class" => "uich js-foldup"]),
                Ht::entry("{$key}:entry", $selected === "other" ? $curval : "", ["placeholder" => "Email", "class" => "fx ml-2", "type" => "email", "size" => 36]);
        } else {
            echo Ht::select($key, $opts, $selected);
        }
        echo '</div>';
    }

    /** @param null|'fail' $what */
    private function print_step_actions($what = null) {
        echo '<div class="aab mt-4">';
        if ($what === "fail") {
            echo '<div class="aabut">', Ht::submit("Restart", ["name" => "back", "value" => "restart"]), '</div>';
        } else {
            echo '<div class="aabut">', Ht::submit($this->curstep->next_label ?? "Next →", ["class" => $this->curstep->next_class ?? "btn-primary", "name" => "next", "value" => 1]), '</div>';
            if ($this->curstep->index > 0) {
                echo '<div class="aabut">', Ht::submit("Back", ["name" => "back", "value" => 1]), '</div>';
            }
        }
        echo '</div>';
    }


    private function transferreview_print_step() {
        $srchemail = htmlspecialchars($this->user ? $this->user->email : "<unknown>");
        $dsthemail = htmlspecialchars($this->dstuser ? $this->dstuser->email : "<unknown>");
        if ($this->curstep->name === "start") {
            // maybe there's a user in the query; check it
            if (isset($this->qreq->u)
                && $this->qreq->is_get()
                && !$this->ms->has_message_at("u")) {
                $u = $this->qreq->u;
                if ($u === "other") {
                    $u = $this->qreq["u:entry"] ?? "";
                }
                if ($u !== "") {
                    $this->parse_user("u", $u);
                }
            }

            echo Ht::form($this->step_hoturl([], Conf::HOTURL_POST)),
                '<p>Select the account whose current reviews and/or PC status should be transferred.';
            if (!$this->viewer->privChair) {
                echo ' You must be signed in to all accounts involved in the transfer.';
            }
            echo '</p>';
            $this->print_user_selector("u");
            $this->print_step_actions();
            echo '</form>';
        } else if ($this->curstep->name === "dest") {
            $what = [];
            if ($this->user && $this->user->isPC) {
                $what[] = "PC status";
            }
            if (empty($what) || $this->user->has_review()) {
                $what[] = "reviews";
            }
            echo Ht::form($this->step_hoturl([], Conf::HOTURL_POST)),
                "<p>Select the account that should receive <strong class=\"sb\">{$srchemail}</strong>’s ",
                join(" and ", $what), ".";
            if (!$this->viewer->privChair) {
                echo ' You must be signed in to all accounts involved in the transfer.';
            }
            echo '</p>';
            $this->print_user_selector("email");
            $this->print_step_actions();
            echo '</form>';
        } else if ($this->curstep->name === "reauth") {
            assert(!!$this->authchecker);
            echo '<form id="f-reauth" class="ui-submit js-reauth" data-session-index="',
                Contact::session_index_by_email($this->qreq, $this->authchecker->user->email), '">';
            if ($this->viewer->privChair) {
                echo '<p>You must confirm your account to continue.</p>';
            } else {
                echo '<p>You must confirm all accounts involved in the transfer to continue.</p>';
            }
            $this->authchecker->set_actions_class("aax mt-4");
            $this->authchecker->add_actions(Ht::submit("Back", [
                "formaction" => $this->step_hoturl(["back" => 1], Conf::HOTURL_POST),
                "formmethod" => "post",
                "formnovalidate" => true
            ]));
            $this->authchecker->print();
            echo '</form>';
        } else if ($this->curstep->name === "confirm") {
            $me = (new ManageEmail_API($this->viewer, $this->qreq))
                ->set_user($this->user)
                ->set_dstuser($this->dstuser)
                ->set_dry_run(true);
            $jr = $me->transferreview();
            echo Ht::form($this->step_hoturl([], Conf::HOTURL_POST));
            if (!$jr->ok()) {
                echo "<p>You can’t transfer reviews from <strong class=\"sb\">{$srchemail}</strong> to <strong class=\"sb\">{$dsthemail}</strong>.</p>";
            }
            $this->conf->feedback_msg($jr->get("message_list") ?? []);
            if ($jr->ok()) {
                echo '<p>Confirming will transfer ',
                    commajoin($jr->get("change_list")),
                    " from <strong class=\"sb\">{$srchemail}</strong> to <strong class=\"sb\">{$dsthemail}</strong>.</p>";
                $this->print_step_actions();
            } else {
                $this->print_step_actions("fail");
            }
            echo '</form>';
        } else if ($this->curstep->name === "done") {
            echo Ht::form($this->conf->hoturl("manageemail"), ["method" => "get"]);
            $change_list = $this->token->data("change_list");
            if ($change_list === null) {
                echo '<p>Transfer failed.</p>';
            } else {
                $this->conf->success_msg($this->conf->_("<5>Successfully transferred {:list} from <strong class=\"sb\">{$srchemail}</sb> to <strong class=\"sb\">{$dsthemail}</strong>.",
                    new FmtArg(0, $change_list, 0)));
            }
            echo '<div class="aab mt-4"><div class="aabut">',
                Ht::submit("Start over", ["class" => "btn-primary"]),
                '</div></div></form>';
        }
    }

    private function post_set_user($key) {
        $email = $this->qreq[$key];
        if ($email === "other") {
            $email = $this->qreq["{$key}:entry"];
        }
        if ($email === "new") {
            if ($this->token) {
                $this->token->change_data($key, null)->update();
            }
            $redirect = $this->step_hoturl(["signedin" => 1], Conf::HOTURL_SERVERREL | Conf::HOTURL_RAW);
            throw new Redirection($this->conf->hoturl("signin", ["redirect" => $redirect]));
        }
        if (($user = $this->parse_user($key, $email))) {
            $this->create_token()
                ->change_data($key, $user->email)
                ->change_data("step", $this->delta_step(1)->name);
            $this->redirect_token();
        }
    }

    private function transferreview_post() {
        if ($this->curstep->name === "start") {
            $this->post_set_user("u");
            return;
        }
        if ($this->curstep->name === "dest") {
            $this->post_set_user("email");
            return;
        }
        if ($this->curstep->name === "confirm") {
            $me = (new ManageEmail_API($this->viewer, $this->qreq))
                ->set_user($this->user)
                ->set_dstuser($this->dstuser);
            $jr = $me->transferreview();
            $ml = $jr->get("message_list");
            if ($jr->ok()) {
                $this->token->change_data("change_list", $jr->get("change_list") ?? []);
            }
            $this->token->change_data("ms", $ml)
                ->change_data("step", $this->delta_step(1)->name);
            $this->redirect_token();
        }
    }

    private function transferreview_check() {
        if ($this->curstep->index > 0
            && $this->qreq->valid_post()
            && (friendly_boolean($this->qreq->back) || $this->qreq->back === "restart")) {
            if ($this->done) {
                $this->conf->redirect_hoturl("manageemail");
            }
            $step = $this->qreq->back === "restart" ? $this->steps[0] : $this->delta_step(-1);
            $this->token->change_data("step", $step->name);
            $this->redirect_token();
        }

        // check users in token
        if ($this->curstep->index >= 1
            && !($this->user = $this->parse_user("u", $this->token->data("u")))
            && !$this->done) {
            $this->token->change_data("step", "start");
            $this->redirect_token();
        }
        if ($this->curstep->index >= 2
            && !($this->dstuser = $this->parse_user("email", $this->token->data("email")))
            && !$this->done) {
            $this->token->change_data("step", "dest");
            $this->redirect_token();
        }
        $this->viewer_involved = $this->user === $this->viewer || $this->dstuser === $this->viewer;

        // if done, nothing more to do
        if ($this->done) {
            return;
        }

        // check for reauthentication
        if ($this->curstep->index >= 2) {
            $this->authchecker = $this->find_failed_authchecker();
            if ($this->authchecker && $this->curstep->index > 2) {
                $this->ms->error_at(null, "<0>Account confirmation required");
                $this->token->change_data("step", "reauth");
                $this->redirect_token();
            }
            if (!$this->authchecker && $this->curstep->index === 2) {
                $this->token->change_data("step", "confirm");
                $this->redirect_token();
            }
        }

        // check post, run dry-run
        if ($this->qreq->valid_post()
            && friendly_boolean($this->qreq->next)) {
            $this->transferreview_post();
        }
    }

    private function transferreview_jump_start() {
        $u = $this->parse_user("u", $this->qreq->u);
        $email = $this->parse_user("email", $this->qreq->email);
        $this->ms->clear_messages();
        if (!$u || !$email) {
            return;
        }
        $this->create_token()
            ->change_data("u", $this->qreq->u)
            ->change_data("email", $this->qreq->email)
            ->change_data("step", "reauth");
        $this->qreq->step = "reauth";
    }

    private function transferreview() {
        // select step
        $this->register_step("start", "Select source account");
        $this->register_step("dest", "Select destination account");
        $this->register_step("reauth", "Authenticate accounts")->set_skip_back(true);
        $this->register_step("confirm", "Confirm")->set_next("Transfer", "btn-success");
        $this->register_step("done", "Done");
        if (!isset($this->qreq->step)
            && isset($this->qreq->u)
            && isset($this->qreq->email)
            && !$this->token) {
            $this->transferreview_jump_start();
        }
        $this->set_step($this->qreq->step ?? "start");
        $this->allow_any = $this->viewer->privChair;

        // determine step, synchronize step with token
        if ($this->token && ($tstep = $this->token->data("step"))) {
            $tstep = $this->find_step($tstep);
        } else {
            $tstep = $this->steps[0];
        }
        if (!$tstep || $this->curstep->index > $tstep->index) {
            $this->conf->error_msg("<0>Email management session not found");
            $this->conf->redirect_hoturl("manageemail");
        }

        // after completion, skip to end
        $this->done = $tstep->name === "done";
        if ($this->done && $this->curstep->name !== "done") {
            $this->token->change_data("step", "done");
            $this->redirect_token();
        }

        // if not complete, maybe change state
        $this->transferreview_check();

        // print
        $this->print_header("Transfer reviews");
        echo '<div class="wizard-accordion">';
        foreach ($this->steps as $step) {
            $this->print_enter_step($step);
            if ($step === $this->curstep) {
                $this->transferreview_print_step();
            }
            $this->print_leave_step();
        }
        echo '</div>';
        $this->qreq->print_footer();
    }


    private function link_print_step() {
        $prihemail = '<strong class="sb">' . htmlspecialchars($this->user ? $this->user->email : "<unknown>") . '</strong>';
        $sechemail = '<strong class="sb">' . htmlspecialchars($this->dstuser ? $this->dstuser->email : "<unknown>") . '</strong>';
        if ($this->curstep->name === "start") {
            // maybe there's a user in the query; check it
            if (($this->qreq->u ?? "") !== ""
                && $this->qreq->is_get()
                && !$this->ms->has_message_at("u")) {
                $this->parse_user("u", $this->qreq->u);
            }

            echo Ht::form($this->step_hoturl([], Conf::HOTURL_POST)),
                '<p>Select the <strong>primary</strong> account to link. This is the main account you’d like to use for email and reviewing. You must be signed in to the account.</p>';
            $this->print_user_selector("u");
            $this->print_step_actions();
            echo '</form>';
        } else if ($this->curstep->name === "secondary") {
            echo Ht::form($this->step_hoturl([], Conf::HOTURL_POST)),
                '<p>Select the <strong>secondary</strong> account to link. Review requests sent to this account will be redirected to the primary account, ', $prihemail, '. You must be signed in to the account.</p>';
            $this->print_user_selector("email");
            $this->print_step_actions();
            echo '</form>';
        } else if ($this->curstep->name === "reauth") {
            assert(!!$this->authchecker);
            echo '<form id="f-reauth" class="ui-submit js-reauth" data-session-index="',
                Contact::session_index_by_email($this->qreq, $this->authchecker->user->email), '">';
            echo '<p>You must confirm all accounts involved in the link to continue.</p>';
            $this->authchecker->set_actions_class("aax mt-4");
            $this->authchecker->add_actions(Ht::submit("Back", [
                "formaction" => $this->step_hoturl(["back" => 1], Conf::HOTURL_POST),
                "formmethod" => "post",
                "formnovalidate" => true
            ]));
            $this->authchecker->print();
            echo '</form>';
        } else if ($this->curstep->name === "confirm") {
            $me = (new ManageEmail_API($this->viewer, $this->qreq))
                ->set_user($this->dstuser)
                ->set_dstuser($this->user)
                ->set_dry_run(true);
            $jr = $me->link();
            echo Ht::form($this->step_hoturl([], Conf::HOTURL_POST));
            if (!$jr->ok()) {
                echo "<p>You can’t link accounts {$sechemail} and {$prihemail}.</p>";
            }
            $this->conf->feedback_msg($jr->get("message_list") ?? []);
            if ($jr->ok()) {
                echo '<p>Confirming will make ', $prihemail, ' the primary account for ', $sechemail, '.</p>';
                $this->link_print_confirm_options();
                $this->print_step_actions();
            } else {
                $this->print_step_actions("fail");
            }
            echo '</form>';
        } else if ($this->curstep->name === "done") {
            echo Ht::form($this->conf->hoturl("manageemail", ["t" => "link", "u" => $this->user->email]), ["method" => "get"]);
            $change_list = $this->token->data("change_list");
            if ($change_list === null) {
                echo '<p>Account link failed.</p>';
            } else {
                $this->link_print_success($prihemail, $sechemail);
            }
            echo '<div class="aab mt-4"><div class="aabut">',
                Ht::submit("Link another account", ["class" => "btn-primary"]),
                '</div></div></form>';
        }
    }

    private function link_print_confirm_options() {
        if (!$this->conf->contactdb()) {
            return;
        }
        $linktype = $this->qreq->linktype ?? $this->token->data("linktype") ?? "local";
        foreach (["local", "global", "all_sites"] as $type) {
            if ($type === "all_sites" && !$this->conf->opt("linkAccountsAllSitesFunction")) {
                continue;
            }
            echo '<label class="checki"><span class="checkc">',
                Ht::radio("linktype", $type, $linktype === $type),
                '</span>';
            $args = [new FmtArg("sec", $this->dstuser->email, 0), new FmtArg("pri", $this->user->email, 0)];
            if ($type === "local") {
                echo Ftext::as(5, $this->conf->_c("manageemail", "<0>Link accounts on this site only", ...$args));
            } else if ($type === "global") {
                echo Ftext::as(5, $this->conf->_c("manageemail", "<0>Link accounts on this site and future {affiliated-sites}", ...$args));
            } else {
                echo Ftext::as(5, $this->conf->_c("manageemail", "<5>Link accounts on <strong>all</strong> {affiliated-sites}", ...$args));
            }
            echo '</label>';
        }
    }

    private function link_print_success($prihemail, $sechemail) {
        $this->conf->success_msg("<5><strong class=\"sb\">{$prihemail}</strong> is now the primary account for <strong class=\"sb\">{$sechemail}</strong>.");
        if ($this->dstuser->is_reviewer()
            || $this->dstuser->has_outstanding_request()) {
            echo "<p>{$sechemail}’s existing reviews on this site have not been moved. If you would like to transfer them to {$prihemail}, use <a href=\"",
                $this->conf->hoturl("manageemail", ["t" => "transferreview", "u" => $this->dstuser->email, "email" => $this->user->email]),
                "\">“Transfer reviews”</a>.</p>";
        } else if ($this->token->data("linktype") === "all_sites"
                   && ($cdb = $this->conf->contactdb())
                   && ($seccdbu = $this->dstuser->cdb_user())
                   && Dbl::fetch_ivalue($cdb, "select exists (select * from Roles where contactDbId=? and (roles&?)!=0) from dual",
                            $seccdbu->contactDbId,
                            Contact::ROLE_PCLIKE | Contact::ROLE_REVIEWER)) {
            echo "<p>", Ftext::as(5, $this->conf->_c("manageemail", "<5>{sec}’s reviews on {affiliated-sites} have not been moved. If you would like to transfer them to {pri}, use “Transfer reviews” on the relevant sites.",
                new FmtArg("sec", $sechemail, 5),
                new FmtArg("pri", $prihemail, 5))), "</p>";
        }
    }

    private function link_post() {
        if ($this->curstep->name === "start") {
            $this->post_set_user("u");
            return;
        }
        if ($this->curstep->name === "secondary") {
            $this->post_set_user("email");
            return;
        }
        if ($this->curstep->name === "confirm") {
            $me = (new ManageEmail_API($this->viewer, $this->qreq))
                ->set_user($this->dstuser)
                ->set_dstuser($this->user);
            $linktype = "local";
            if ($this->qreq->linktype === "global") {
                $me->set_global(true);
                $linktype = "global";
            } else if ($this->qreq->linktype === "all_sites") {
                $me->set_global(true)->set_all_sites(true);
                $linktype = "all_sites";
            }
            $jr = $me->link();
            $ml = $jr->get("message_list");
            if ($jr->ok()) {
                $this->token->change_data("change_list", $jr->get("change_list") ?? []);
            }
            $this->token->change_data("ms", $ml)
                ->change_data("step", $this->delta_step(1)->name)
                ->change_data("linktype", $linktype);
            $this->redirect_token();
        }
    }

    private function link_check() {
        if ($this->curstep->index > 0
            && $this->qreq->valid_post()
            && (friendly_boolean($this->qreq->back) || $this->qreq->back === "restart")) {
            if ($this->done) {
                $this->conf->redirect_hoturl("manageemail");
            }
            $step = $this->qreq->back === "restart" ? $this->steps[0] : $this->delta_step(-1);
            $this->token->change_data("step", $step->name);
            $this->redirect_token();
        }

        // check users in token
        if ($this->curstep->index >= 1
            && !($this->user = $this->parse_user("u", $this->token->data("u")))
            && !$this->done) {
            $this->token->change_data("step", "start");
            $this->redirect_token();
        }
        if ($this->curstep->index >= 2
            && !($this->dstuser = $this->parse_user("email", $this->token->data("email")))
            && !$this->done) {
            $this->token->change_data("step", "secondary");
            $this->redirect_token();
        }
        $this->viewer_involved = $this->user === $this->viewer || $this->dstuser === $this->viewer;

        // if done, nothing more to do
        if ($this->done) {
            return;
        }

        // check for reauthentication
        if ($this->curstep->index >= 2) {
            $this->authchecker = $this->find_failed_authchecker();
            if ($this->authchecker && $this->curstep->index > 2) {
                $this->ms->error_at(null, "<0>Account confirmation required");
                $this->token->change_data("step", "reauth");
                $this->redirect_token();
            }
            if (!$this->authchecker && $this->curstep->index === 2) {
                $this->token->change_data("step", "confirm");
                $this->redirect_token();
            }
        }

        // check post, run dry-run
        if ($this->qreq->valid_post()
            && friendly_boolean($this->qreq->next)) {
            $this->link_post();
        }
    }

    private function link() {
        // select step
        $this->register_step("start", "Select primary account");
        $this->register_step("secondary", "Add linked account");
        $this->register_step("reauth", "Authenticate accounts")->set_skip_back(true);
        $this->register_step("confirm", "Confirm")->set_next("Link accounts", "btn-success");
        $this->register_step("done", "Done");
        $this->set_step($this->qreq->step ?? "start");

        // determine step, synchronize step with token
        if ($this->token && ($tstep = $this->token->data("step"))) {
            $tstep = $this->find_step($tstep);
        } else {
            $tstep = $this->steps[0];
        }
        if (!$tstep || $this->curstep->index > $tstep->index) {
            $this->conf->error_msg("<0>Email management session not found");
            $this->conf->redirect_hoturl("manageemail");
        }

        // after completion, skip to end
        $this->done = $tstep->name === "done";
        if ($this->done && $this->curstep->name !== "done") {
            $this->token->change_data("step", "done");
            $this->redirect_token();
        }

        // if not complete, maybe change state
        $this->link_check();

        // print
        $this->print_header("Link accounts");
        echo '<div class="wizard-accordion">';
        foreach ($this->steps as $step) {
            $this->print_enter_step($step);
            if ($step === $this->curstep) {
                $this->link_print_step();
            }
            $this->print_leave_step();
        }
        echo '</div>';
        $this->qreq->print_footer();
    }


    private function unlink_print_step() {
        $prihemail = htmlspecialchars($this->user ? $this->user->email : "<unknown>");
        if ($this->curstep->name === "start") {
            // maybe there's a user in the query; check it
            if (($this->qreq->u ?? "") !== ""
                && $this->qreq->is_get()
                && !$this->ms->has_message_at("u")) {
                $this->parse_user("u", $this->qreq->u);
            }

            echo Ht::form($this->step_hoturl([], Conf::HOTURL_POST)),
                '<p>Select the account you’d like to unlink from other accounts. You must be signed in to the account you want to unlink.</p>';
            $this->print_user_selector("u");
            $this->print_step_actions();
            echo '</form>';
        } else if ($this->curstep->name === "reauth") {
            assert(!!$this->authchecker);
            echo '<form id="f-reauth" class="ui-submit js-reauth" data-session-index="',
                Contact::session_index_by_email($this->qreq, $this->authchecker->user->email), '">';
            echo '<p>You must confirm this account to continue.</p>';
            $this->authchecker->set_actions_class("aax mt-4");
            $this->authchecker->add_actions(Ht::submit("Back", [
                "formaction" => $this->step_hoturl(["back" => 1], Conf::HOTURL_POST),
                "formmethod" => "post",
                "formnovalidate" => true
            ]));
            $this->authchecker->print();
            echo '</form>';
        } else if ($this->curstep->name === "confirm") {
            $me = (new ManageEmail_API($this->viewer, $this->qreq))
                ->set_user($this->user)
                ->set_dry_run(true);
            $jr = $me->unlink();
            echo Ht::form($this->step_hoturl([], Conf::HOTURL_POST));
            if (!$jr->ok()) {
                echo "<p>You can’t unlink account <strong class=\"sb\">{$prihemail}</strong>.</p>";
            }
            $this->conf->feedback_msg($jr->get("message_list") ?? []);
            if ($jr->ok()) {
                echo '<p>Confirming will unlink <strong class="sb">', $prihemail, '</strong> from all other accounts.</p>';
                $this->unlink_print_confirm_options();
                $this->print_step_actions();
            } else {
                $this->print_step_actions("fail");
            }
            echo '</form>';
        } else if ($this->curstep->name === "done") {
            echo Ht::form($this->conf->hoturl("manageemail"), ["method" => "get"]);
            $change_list = $this->token->data("change_list");
            if ($change_list === null) {
                echo '<p>Account unlink failed.</p>';
            } else {
                $this->conf->success_msg("<5><strong class=\"sb\">{$prihemail}</strong> has been unlinked from other accounts.");
            }
            echo '<div class="aab mt-4"><div class="aabut">',
                Ht::submit("Start over", ["class" => "btn-primary"]),
                '</div></div></form>';
        }
    }

    private function unlink_print_confirm_options() {
        if (!$this->conf->contactdb()) {
            return;
        }
        $linktype = $this->qreq->linktype ?? $this->token->data("linktype") ?? "local";
        $types = [];
        if ($this->user->primaryContactId > 0) {
            $types[] = "local";
        }
        $cdbu = $this->user->cdb_user();
        if ($cdbu && $cdbu->primaryContactId > 0) {
            $types[] = "global";
        }
        if ($this->conf->opt("linkAccountsAllSitesFunction")) {
            $types[] = "all_sites";
        }
        if ($types === ["local"]) {
            return;
        }
        $args = [new FmtArg("sec", $this->user->email, 0)];
        foreach ($types as $type) {
            echo '<label class="checki"><span class="checkc">',
                Ht::radio("linktype", $type, $linktype === $type),
                '</span>';
            if ($type === "local") {
                echo Ftext::as(5, $this->conf->_c("manageemail", "<0>Unlink account on this site only", ...$args));
            } else if ($type === "global") {
                echo Ftext::as(5, $this->conf->_c("manageemail", "<0>Unlink account on this site and future {affiliated-sites}", ...$args));
            } else {
                echo Ftext::as(5, $this->conf->_c("manageemail", "<5>Unlink account on <strong>all</strong> {affiliated-sites}", ...$args));
            }
            echo '</label>';
        }
    }

    private function unlink_post() {
        if ($this->curstep->name === "start") {
            $this->post_set_user("u");
            return;
        }
        if ($this->curstep->name === "confirm") {
            $me = (new ManageEmail_API($this->viewer, $this->qreq))
                ->set_user($this->user);
            if ($this->qreq->linktype === "global") {
                $me->set_global(true);
            } else if ($this->qreq->linktype === "all_sites") {
                $me->set_global(true)->set_all_sites(true);
            }
            $jr = $me->unlink();
            $ml = $jr->get("message_list");
            if ($jr->ok()) {
                $this->token->change_data("change_list", $jr->get("change_list") ?? []);
            }
            $this->token->change_data("ms", $ml)
                ->change_data("step", $this->delta_step(1)->name);
            $this->redirect_token();
        }
    }

    private function unlink_check() {
        if ($this->curstep->index > 0
            && $this->qreq->valid_post()
            && (friendly_boolean($this->qreq->back) || $this->qreq->back === "restart")) {
            if ($this->done) {
                $this->conf->redirect_hoturl("manageemail");
            }
            $step = $this->qreq->back === "restart" ? $this->steps[0] : $this->delta_step(-1);
            $this->token->change_data("step", $step->name);
            $this->redirect_token();
        }

        // check users in token
        if ($this->curstep->index >= 1
            && !($this->user = $this->parse_user("u", $this->token->data("u")))
            && !$this->done) {
            $this->token->change_data("step", "start");
            $this->redirect_token();
        }
        $this->viewer_involved = $this->user === $this->viewer;

        // if done, nothing more to do
        if ($this->done) {
            return;
        }

        // check for reauthentication
        if ($this->curstep->index >= 1) {
            $this->authchecker = $this->find_failed_authchecker();
            if ($this->authchecker && $this->curstep->index > 1) {
                $this->ms->error_at(null, "<0>Account confirmation required");
                $this->token->change_data("step", "reauth");
                $this->redirect_token();
            }
            if (!$this->authchecker && $this->curstep->index === 1) {
                $this->token->change_data("step", "confirm");
                $this->redirect_token();
            }
        }

        // check post, run dry-run
        if ($this->qreq->valid_post()
            && friendly_boolean($this->qreq->next)) {
            $this->unlink_post();
        }
    }

    private function unlink() {
        // select step
        $this->register_step("start", "Select account to unlink");
        $this->register_step("reauth", "Authenticate accounts")->set_skip_back(true);
        $this->register_step("confirm", "Confirm")->set_next("Unlink account", "btn-success");
        $this->register_step("done", "Done");
        $this->set_step($this->qreq->step ?? "start");

        // determine step, synchronize step with token
        if ($this->token && ($tstep = $this->token->data("step"))) {
            $tstep = $this->find_step($tstep);
        } else {
            $tstep = $this->steps[0];
        }
        if (!$tstep || $this->curstep->index > $tstep->index) {
            $this->conf->error_msg("<0>Email management session not found");
            $this->conf->redirect_hoturl("manageemail");
        }

        // after completion, skip to end
        $this->done = $tstep->name === "done";
        if ($this->done && $this->curstep->name !== "done") {
            $this->token->change_data("step", "done");
            $this->redirect_token();
        }

        // if not complete, maybe change state
        $this->unlink_check();

        // print
        $this->print_header("Unlink accounts");
        echo '<div class="wizard-accordion">';
        foreach ($this->steps as $step) {
            $this->print_enter_step($step);
            if ($step === $this->curstep) {
                $this->unlink_print_step();
            }
            $this->print_leave_step();
        }
        echo '</div>';
        $this->qreq->print_footer();
    }


    private function run() {
        if (friendly_boolean($this->qreq->cancel)) {
            unset($this->qreq->t, $this->qreq->step, $this->qreq->mesess, $this->qreq->u);
            if ($this->qreq->is_post()) {
                $this->ms->append_item(MessageItem::warning_note("<0>Operation canceled"));
                $this->conf->redirect_self($this->qreq);
            }
        }
        $this->type = $this->qreq->t ?? "";

        $this->token = TokenInfo::find($this->qreq->mesess, $this->conf);
        if (!$this->token
            || !$this->token->is_active(TokenInfo::MANAGEEMAIL)
            || $this->token->contactId !== $this->viewer->contactId) {
            $this->ms->error_at("mesession", "<0>Email management session expired");
            $this->token = null;
            unset($this->qreq->step, $this->qreq->mesess);
        }
        if ($this->token && $this->token->input("t") !== $this->type) {
            $this->token = null;
            unset($this->qreq->mesess);
        }
        if ($this->token && ($ms = $this->token->data("ms"))) {
            foreach ($ms as $mij) {
                $this->ms->append_item(MessageItem::from_json($mij));
            }
            $this->token->change_data("ms", null)->update();
        }

        if ($this->type === "transferreview") {
            $this->transferreview();
        } else if ($this->type === "link") {
            $this->link();
        } else if ($this->type === "unlink") {
            $this->unlink();
        } else {
            $this->choose();
        }
    }

    static function go(Contact $user, Qrequest $qreq) {
        $pg = new ManageEmail_Page($user, $qreq);
        $pg->run();
    }

    static function go_merge(Contact $user, Qrequest $qreq) {
        $user->conf->redirect_hoturl("manageemail", ["t" => "link"]);
    }

    static function go_changeemail(Contact $user, Qrequest $qreq) {
        $user->conf->feedback_msg(
            MessageItem::error("<0>Email address changes are not supported"),
            MessageItem::inform("<0>Use ‘Manage email’ to link accounts or transfer reviews.")
        );
        $user->conf->redirect_hoturl("manageemail", ["t" => null]);
    }
}
