<?php
// pages/p_users.php -- HotCRP people listing/editing page
// Copyright (c) 2006-2025 Eddie Kohler; see LICENSE.

class Users_Page {
    /** @var Conf */
    public $conf;
    /** @var Contact */
    public $viewer;
    /** @var Qrequest */
    public $qreq;
    /** @var array<string,string|array{label:string}> */
    public $limits;
    /** @var list<int> */
    private $papersel;

    function __construct(Contact $viewer, Qrequest $qreq) {
        $this->conf = $viewer->conf;
        if ($viewer->contactId && $viewer->is_disabled()) {
            $viewer = Contact::make_email($viewer->conf, $viewer->email);
        }
        $this->viewer = $viewer;
        $this->qreq = $qreq;
        $this->papersel = SearchSelection::make($qreq)->selection();

        $this->limits = [];
        if ($viewer->can_view_pc()) {
            $this->limits["pc"] = "Program committee";
        }
        foreach ($this->conf->viewable_user_tags($viewer) as $t) {
            if ($t !== "pc")
                $this->limits["#{$t}"] = ["optgroup" => "PC tags", "label" => "#{$t} program committee"];
        }
        if ($viewer->isPC
            && $viewer->can_view_pc()) {
            $this->limits["admin"] = "System administrators";
            $this->limits["pcadmin"] = ["label" => "PC and system administrators", "exclude" => true];
        }
        if ($viewer->is_manager()
            || ($viewer->isPC && $this->conf->setting("viewrev") > 0)) {
            $this->limits["re"] = "All reviewers";
            $this->limits["ext"] = "External reviewers";
            $this->limits["extsub"] = "External reviewers who completed a review";
            $this->limits["extrev-not-accepted"] = "External reviewers with outstanding requests";
        }
        if ($viewer->isPC) {
            $this->limits["req"] = ["label" => "External reviewers you requested", "exclude" => !$viewer->is_requester()];
        }
        if ($viewer->is_manager()
            || ($viewer->isPC
                && $this->conf->submission_blindness() !== Conf::BLIND_ALWAYS)) {
            $this->limits["au"] = "Contact authors of submitted papers";
        }
        if ($viewer->is_manager()
            || ($viewer->isPC
                && $viewer->can_view_some_decision()
                && $viewer->can_view_some_authors())) {
            $this->limits["auacc"] = ["label" => "Contact authors of accepted papers", "exclude" => !$this->conf->has_any_accepted()];
        }
        if ($viewer->is_manager()
            || ($viewer->isPC
                && $viewer->can_view_some_decision()
                && $this->conf->submission_blindness() !== Conf::BLIND_ALWAYS)) {
            $this->limits["aurej"] = "Contact authors of rejected papers";
        }
        if ($viewer->is_manager()) {
            $this->limits["auuns"] = "Contact authors of non-submitted papers";
        }
        if ($viewer->privChair) {
            $this->limits["all"] = "Active users";
        }
    }


    /** @return bool */
    private function handle_nameemail() {
        $result = $this->conf->qe("select * from ContactInfo where contactId?a", $this->papersel);
        $users = [];
        while (($user = Contact::fetch($result, $this->conf))) {
            $users[] = $user;
        }
        Dbl::free($result);
        usort($users, $this->conf->user_comparator());

        $texts = [];
        $has_country = $has_orcid = false;
        foreach ($users as $u) {
            $texts[] = $line = [
                "given_name" => $u->firstName,
                "family_name" => $u->lastName,
                "email" => $u->email,
                "affiliation" => $u->affiliation,
                "country" => $u->country_code(),
                "orcid" => $u->decorated_orcid()
            ];
            $has_orcid = $has_orcid || $line["orcid"] !== "";
            $has_country = $has_country || $line["country"] !== "";
        }
        $header = ["given_name", "family_name", "email", "affiliation"];
        if ($has_country) {
            $header[] = "country";
        }
        if ($has_orcid) {
            $header[] = "orcid";
        }
        $this->conf->make_csvg("users")->select($header)->append($texts)->emit();
        return true;
    }


    /** @return bool */
    private function handle_pcinfo() {
        $result = $this->conf->qe("select * from ContactInfo where contactId?a", $this->papersel);
        $users = [];
        while (($user = Contact::fetch($result, $this->conf))) {
            $users[] = $user;
        }
        Dbl::free($result);
        usort($users, $this->conf->user_comparator());
        Contact::load_topic_interests($users);

        // NB This format is expected to be parsed by profile.php's bulk upload.
        $tagger = new Tagger($this->viewer);
        $people = [];
        $has_preferred_email = $has_tags = $has_topics =
            $has_phone = $has_country = $has_orcid = $has_disabled = false;
        $has = (object) [];
        foreach ($users as $user) {
            $row = [
                "given_name" => $user->firstName,
                "family_name" => $user->lastName,
                "email" => $user->email,
                "affiliation" => $user->affiliation,
                "orcid" => $user->decorated_orcid(),
                "country" => $user->country_code(),
                "phone" => $user->phone(),
                "disabled" => $user->is_disabled() ? "yes" : "",
                "collaborators" => rtrim($user->collaborators())
            ];
            $has_country = $has_country || $row["country"] !== "";
            $has_orcid = $has_orcid || $row["orcid"] !== "";
            $has_phone = $has_phone || ($row["phone"] ?? "") !== "";
            $has_disabled = $has_disabled || $user->is_disabled();
            if ($user->preferredEmail && $user->preferredEmail !== $user->email) {
                $row["preferred_email"] = $user->preferredEmail;
                $has_preferred_email = true;
            }
            if ($user->contactTags) {
                $row["tags"] = $tagger->unparse($user->contactTags);
                $has_tags = $has_tags || $row["tags"] !== "";
            }
            foreach ($user->topic_interest_map() as $t => $i) {
                $row["topic{$t}"] = $i;
                $has_topics = true;
            }
            $f = [];
            $dw = $user->defaultWatch;
            for ($b = 1; $b <= $dw; $b <<= 1) {
                if (($dw & $b) !== 0
                    && ($fb = UserStatus::unparse_follow_bit($b)))
                    $f[] = $fb;
            }
            $row["follow"] = empty($f) ? "none" : join(" ", $f);
            if ($user->roles & (Contact::ROLE_PC | Contact::ROLE_ADMIN | Contact::ROLE_CHAIR)) {
                $r = [];
                if ($user->roles & Contact::ROLE_CHAIR) {
                    $r[] = "chair";
                }
                if ($user->roles & Contact::ROLE_PC) {
                    $r[] = "pc";
                }
                if ($user->roles & Contact::ROLE_ADMIN) {
                    $r[] = "sysadmin";
                }
                $row["roles"] = join(" ", $r);
            } else {
                $row["roles"] = "";
            }
            $people[] = $row;
        }

        $header = ["given_name", "family_name", "email", "affiliation"];
        if ($has_orcid) {
            $header[] = "orcid";
        }
        if ($has_country) {
            $header[] = "country";
        }
        if ($has_phone) {
            $header[] = "phone";
        }
        if ($has_disabled) {
            $header[] = "disabled";
        }
        if ($has_preferred_email) {
            $header[] = "preferred_email";
        }
        $header[] = "roles";
        if ($has_tags) {
            $header[] = "tags";
        }
        $header[] = "collaborators";
        $header[] = "follow";
        $selection = $header;
        if ($has_topics) {
            foreach ($this->conf->topic_set() as $t => $tn) {
                $header[] = "topic: " . $tn;
                $selection[] = "topic{$t}";
            }
        }

        $this->conf->make_csvg("pcinfo")
            ->set_keys($selection)->set_header($header)
            ->append($people)->emit();
        return true;
    }


    /** @return bool */
    private function handle_modify() {
        $modifyfn = $this->qreq->modifyfn;
        unset($this->qreq->fn, $this->qreq->modifyfn);
        $ua = new UserActions($this->viewer);
        $list = $action = null;

        if ($modifyfn === "disable" || $modifyfn === "disableaccount") {
            $ua->disable($this->papersel);
            $list = "disabled";
            $action = new FmtArg("action", "disabled", 0);
        } else if ($modifyfn === "enable" || $modifyfn === "enableaccount") {
            $ua->enable($this->papersel);
            $list = "enabled";
            $action = new FmtArg("action", "enabled", 0);
        } else if ($modifyfn === "sendaccount") {
            $ua->send_account_info($this->papersel);
            if (!empty($ua->name_list("sent"))) {
                $ua->success($this->conf->_("<0>Sent account information mail to {:list}", $ua->name_list("sent")));
            }
            if (!empty($ua->name_list("skipped"))) {
                $ua->append_item(MessageItem::warning_note($this->conf->_("<0>Skipped disabled accounts {:list}", $ua->name_list("skipped"))));
            }
        } else if ($modifyfn === "add_pc" || $modifyfn === "remove_pc") {
            $ua->$modifyfn($this->papersel);
            $list = $modifyfn;
            $action = new FmtArg("action", $modifyfn === "add_pc" ? "added to PC" : "removed from PC", 0);
        } else {
            return false;
        }

        if ($list !== null) {
            if (!empty($ua->name_list($list))) {
                $ua->success($this->conf->_("<0>Accounts {:list} {action}", $ua->name_list($list), $action));
            } else if (!$ua->has_message()) {
                $ua->append_item(MessageItem::warning_note("<0>No changes"));
            }
            if ($list === "enabled" && !empty($ua->name_list("activated"))) {
                $ua->success($this->conf->_("<0>Accounts {:list} {action}", $ua->name_list("activated"), new FmtArg("action", "activated and notified", 0)));
            }
        }

        $this->conf->feedback_msg($ua);
        $this->conf->redirect_self($this->qreq);
        return true;
    }


    /** @return bool */
    private function handle_tags() {
        $tagfn = $this->qreq->tagfn;
        // XXX what about removing a tag with a specific value?

        // check tags
        $tagger = new Tagger($this->viewer);
        $t1 = [];
        $ms = new MessageSet;
        $flags = $tagfn === "d" ? Tagger::NOVALUE | Tagger::NOPRIVATE : Tagger::NOPRIVATE;
        foreach (preg_split('/[\s,;]+/', (string) $this->qreq->tag) as $t) {
            if ($t === "") {
                continue;
            } else if (!($t = $tagger->check($t, $flags))) {
                $ms->error_at(null, $tagger->error_ftext());
            } else if (!UserStatus::check_pc_tag(Tagger::tv_tag($t))) {
                $ms->error_at(null, $this->conf->_("<0>User tag ‘{}’ reserved", Tagger::tv_tag($t)));
            } else {
                $t1[] = $t;
            }
        }
        if ($ms->has_error()) {
            $ms->prepend_item(MessageItem::error("<0>Changes not saved; please correct these errors and try again"));
            $this->conf->feedback_msg($ms);
            return false;
        } else if (empty($t1)) {
            $ms->append_item(MessageItem::warning_note("<0>No changes"));
            $this->conf->feedback_msg($ms);
            return false;
        }

        // load affected users
        Conf::$no_invalidate_caches = true;
        $tests = ["contactId?a"];
        if ($tagfn === "s") {
            foreach ($t1 as $t) {
                list($tag, $index) = Tagger::unpack($t);
                $x = $this->conf->dblink->real_escape_string(Dbl::escape_like($tag));
                $tests[] = "contactTags like " . Dbl::utf8ci($this->conf->dblink, "'% {$x}#%'");
            }
        }
        $result = $this->conf->qe("select * from ContactInfo where " . join(" or ", $tests), $this->papersel);

        // make changes
        $us = new UserStatus($this->viewer);
        $changes = false;
        while (($u = Contact::fetch($result, $this->conf))) {
            $us->start_update();
            $us->set_user($u);
            foreach ($t1 as $t) {
                if ($tagfn === "s"
                    || $tagfn === "d") {
                    $us->jval->change_tags[] = "-" . Tagger::tv_tag($t);
                }
                if (($tagfn === "s" && in_array($u->contactId, $this->papersel, true))
                    || $tagfn === "a") {
                    $us->jval->change_tags[] = $t;
                }
            }
            $us->execute_update();
            $changes = $changes || !empty($us->diffs);
        }
        $result->close();

        // that’s it
        Conf::$no_invalidate_caches = false;
        $this->conf->invalidate_caches(["pc" => true]);

        // report
        if ($us->has_error()) {
            $ms->append_set($us);
            $this->conf->feedback_msg($ms);
            return false;
        }
        if ($changes) {
            $ms->prepend_item(MessageItem::success("<0>User tag changes saved"));
        } else {
            $ms->prepend_item(MessageItem::warning_note("<0>No changes"));
        }
        $this->conf->feedback_msg($ms);
        unset($this->qreq->fn, $this->qreq->tagfn);
        $this->conf->redirect_self($this->qreq);
        return true;
    }


    /** @return bool */
    private function handle_redisplay() {
        $this->qreq->unset_csession("uldisplay");
        $sv = [];
        foreach (ContactList::$folds as $key) {
            if (($x = friendly_boolean($this->qreq["show{$key}"])) !== null)
                $sv[] = "uldisplay.{$key}=" . ($x ? 0 : 1);
        }
        foreach ($this->conf->all_review_fields() as $f) {
            if (($x = friendly_boolean($this->qreq["show{$f->short_id}"])) !== null)
                $sv[] = "uldisplay.{$f->short_id}=" . ($x ? 0 : 1);
        }
        if (isset($this->qreq->scoresort)) {
            $sv[] = "ulscoresort=" . ScoreInfo::parse_score_sort($this->qreq->scoresort);
        }
        Session_API::change_session($this->qreq, join(" ", $sv));
        $this->conf->redirect_self($this->qreq);
        return true;
    }


    /** @return bool */
    private function handle_request() {
        $qreq = $this->qreq;
        if ($qreq->fn === "get"
            && $qreq->getfn === "nameemail") {
            return !empty($this->papersel)
                && $this->viewer->isPC
                && $this->handle_nameemail();
        }
        if ($qreq->fn === "get"
            && $qreq->getfn === "pcinfo") {
            return !empty($this->papersel)
                && $this->viewer->privChair
                && $this->handle_pcinfo();
        }
        if ($qreq->fn === "modify") {
            return $this->viewer->privChair
                && $qreq->valid_post()
                && !empty($this->papersel)
                && $this->handle_modify();
        }
        if ($qreq->fn === "tag") {
            return $this->viewer->privChair
                && $qreq->valid_post()
                && !empty($this->papersel)
                && in_array($qreq->tagfn, ["a", "d", "s"], true)
                && $this->handle_tags();
        }
        if ($qreq->redisplay) {
            return $this->handle_redisplay();
        }
    }

    private function print_query_form(ContactList $pl) {
        echo '<div class="tlcontainer mb-3">';

        echo '<div class="tld is-tla active" id="default" role="tabpanel" aria-labelledby="k-default-tab">',
            Ht::form($this->conf->hoturl("users"), ["method" => "get"]);
        if (isset($this->qreq->sort)) {
            echo Ht::hidden("sort", $this->qreq->sort);
        }
        echo Ht::select("t", $this->limits, $this->qreq->t, ["class" => "want-focus uich js-users-selection"]),
            " &nbsp;", Ht::submit("Go"), "</form></div>";

        // Display options
        echo '<div class="tld is-tla" id="view" role="tabpanel" aria-labelledby="k-view-tab">',
            Ht::form($this->conf->hoturl("users"), ["method" => "get"]);
        foreach (["t", "sort"] as $x) {
            if (isset($this->qreq[$x]))
                echo Ht::hidden($x, $this->qreq[$x]);
        }

        echo '<table><tr><td><strong>Show:</strong> &nbsp;</td>
      <td class="pad">';
        foreach (["tags" => "Tags",
                  "aff" => "Affiliations",
                  "collab" => "Collaborators",
                  "topics" => "Topics",
                  "orcid" => "ORCID iD",
                  "country" => "Country"] as $fold => $text) {
            if (($pl->have_folds[$fold] ?? null) !== null) {
                $k = array_search($fold, ContactList::$folds) + 1;
                echo Ht::checkbox("show{$fold}", 1, $pl->have_folds[$fold],
                                  ["data-fold-target" => "foldul#{$k}", "class" => "uich js-foldup"]),
                    "&nbsp;", Ht::label($text), "<br />\n";
            }
        }
        echo "</td>";

        $viewable_fields = [];
        $revViewScore = $this->viewer->permissive_view_score_bound();
        foreach ($this->conf->all_review_fields() as $f) {
            if ($f->view_score > $revViewScore
                && $f->main_storage
                && $f instanceof Discrete_ReviewField)
                $viewable_fields[] = $f;
        }
        if (!empty($viewable_fields)) {
            echo '<td class="pad">';
            $uldisplay = ContactList::uldisplay($this->qreq);
            foreach ($viewable_fields as $f) {
                $checked = strpos($uldisplay, " {$f->short_id} ") !== false;
                echo '<label class="checki"><span class="checkc">',
                    Ht::checkbox("show{$f->short_id}", 1, $checked),
                    '</span>', $f->name_html, '</label>';
            }
            echo "</td>";
        }

        echo "<td>", Ht::submit("redisplay", "Redisplay"), "</td></tr>\n";

        if (!empty($viewable_fields)) {
            $ss = [];
            foreach (ScoreInfo::score_sort_selector_options() as $k => $v) {
                if (in_array($k, ["average", "variance", "maxmin"], true))
                    $ss[$k] = $v;
            }
            echo '<tr><td colspan="3"><hr class="g"><b>Sort scores by:</b> &nbsp;',
                Ht::select("scoresort", $ss, ScoreInfo::parse_score_sort($this->qreq->csession("ulscoresort") ?? "average")),
                "</td></tr>";
        }
        echo "</table></form></div>";

        // Tab selectors
        echo '<div class="tllx" role="tablist">',
            '<div class="tll active" role="tab" id="k-default-tab" aria-controls="default" aria-selected="true"><a class="ui tla" href="">User selection</a></div>',
            '<div class="tll" role="tab" id="k-view-tab" aria-controls="view" aria-selected="false"><a class="ui tla" href="#view">View options</a></div>',
            '</div></div>', "\n\n";
    }


    private function print_pre_list_links(...$msgs) {
        echo '<div class="msg demargin remargin-left remargin-right">',
            '<div class="mx-auto"><ul class="inline">';
        foreach ($msgs as $m) {
            echo '<li>', $m, '</li>';
        }
        echo '</ul></div></div>';
    }

    private function print() {
        if ($this->qreq->t === "pc") {
            $title = "Program committee";
        } else if (str_starts_with($this->qreq->t, "#")) {
            $title = "#" . substr($this->qreq->t, 1) . " program committee";
        } else {
            $title = "Users";
        }
        $this->qreq->print_header($title, "users", [
            "action_bar" => QuicklinksRenderer::make($this->qreq, "account")
        ]);


        $limit_title = $this->limits[$this->qreq->t];
        if (!is_string($limit_title)) {
            $limit_title = $limit_title["label"];
        }

        $pl = new ContactList($this->viewer, true, $this->qreq);
        $pl_text = $pl->table_html($this->qreq->t,
            $this->conf->hoturl("users", ["t" => $this->qreq->t]),
            $limit_title, 'uldisplay.');

        echo '<hr class="g">';
        if (count($this->limits) > 1) {
            $this->print_query_form($pl);
        }

        if ($this->viewer->privChair) {
            if ($this->qreq->t === "pc") {
                $this->print_pre_list_links('<a href="' . $this->conf->hoturl("profile", "u=new&amp;role=pc") . '" class="btn">Add accounts</a>',
                    'Select a user to edit their profile or remove them from the PC.');
            } else if (str_starts_with($this->qreq->t, "#")) {
                $this->print_pre_list_links('<a href="' . $this->conf->hoturl("profile", ["u" => "new", "role" => "pc", "tags" => substr($this->qreq->t, 1)]) . '" class="btn">Add accounts</a>',
                    'Select a user to edit their profile or remove them from the PC.');
            } else if ($this->qreq->t === "all") {
                $this->print_pre_list_links('<a href="' . $this->conf->hoturl("profile", "u=new") . '" class="btn">Add accounts</a>',
                    'Select a user to edit their profile.',
                    'Select ' . Ht::img("viewas.png", "[Act as]") . ' to view the site as that user.');
            }
        }

        if ($pl->has("sel")) {
            echo Ht::form($this->conf->hoturl("=users", ["t" => $this->qreq->t]),
                    ["class" => "ui-submit js-submit-list"]),
                Ht::hidden("defaultfn", ""),
                Ht::hidden_default_submit("default", 1),
                isset($this->qreq->sort) ? Ht::hidden("sort", $this->qreq->sort) : "",
                Ht::unstash(),
                $pl_text,
                "</form>";
        } else {
            echo Ht::unstash(), $pl_text;
        }

        $this->qreq->print_footer();
    }


    static function go(Contact $viewer, Qrequest $qreq) {
        $up = new Users_Page($viewer, $qreq);

        // check list type
        if (empty($up->limits)) {
            Multiconference::fail($qreq, 403, ["title" => "Users"], "<0>You can’t list users for this site");
            return;
        }
        if (!isset($qreq->t) && $qreq->path_component(0)) {
            $qreq->t = $qreq->path_component(0);
        }
        if (isset($qreq->t) && !isset($up->limits[$qreq->t])) {
            if (str_starts_with($qreq->t, "pc:")
                && isset($up->limits["#" . substr($qreq->t, 3)])) {
                $qreq->t = "#" . substr($qreq->t, 3);
            } else if (isset($up->limits["#" . $qreq->t])) {
                $qreq->t = "#" . $qreq->t;
            } else if ($qreq->t === "#pc") {
                $qreq->t = "pc";
            } else if ($qreq->t === "pcadminx" && isset($up->limits["pcadmin"])) {
                $qreq->t = "pcadmin";
            }
        }
        if (!isset($qreq->t)) {
            reset($up->limits);
            $qreq->t = key($up->limits);
        }
        if (!isset($up->limits[$qreq->t])) {
            Multiconference::fail($qreq, 403, ["title" => "Users"], "<0>User list not found");
            return;
        }

        // update from contactdb
        (new CdbUserUpdate($viewer->conf))->check();

        // handle request
        if (isset($qreq["default"]) && $qreq->defaultfn) {
            $qreq->fn = $qreq->defaultfn;
        }
        if ($qreq->fn && ($p = strpos($qreq->fn, "/")) !== false) {
            $qreq[substr($qreq->fn, 0, $p) . "fn"] = substr($qreq->fn, $p + 1);
            $qreq->fn = substr($qreq->fn, 0, $p);
        }
        if ($up->handle_request()) {
            return;
        }

        // render
        $up->print();
    }
}
