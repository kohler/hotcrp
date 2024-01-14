<?php
// pages/p_assign.php -- HotCRP per-paper assignment/conflict management page
// Copyright (c) 2006-2023 Eddie Kohler; see LICENSE.

class Assign_Page {
    /** @var Conf */
    public $conf;
    /** @var Contact */
    public $user;
    /** @var Qrequest */
    public $qreq;
    /** @var PaperInfo */
    public $prow;
    /** @var PaperTable */
    public $pt;
    /** @var bool */
    public $allow_view_authors;
    /** @var MessageSet */
    private $ms;

    function __construct(Contact $user, Qrequest $qreq) {
        $this->conf = $user->conf;
        $this->user = $user;
        $this->qreq = $qreq;
        $this->ms = new MessageSet;
    }

    function error_exit(...$mls) {
        PaperTable::print_header($this->pt, $this->qreq, true);
        $this->conf->feedback_msg(...$mls);
        $this->qreq->print_footer();
        throw new PageCompletion;
    }

    function assign_load() {
        try {
            $pr = new PaperRequest($this->qreq, true);
            $this->prow = $this->conf->paper = $pr->prow;
            if (($whynot = $this->user->perm_request_review($this->prow, null, false))) {
                $this->pt = new PaperTable($this->user, $this->qreq, $this->prow);
                throw $whynot;
            }
        } catch (Redirection $redir) {
            assert(PaperRequest::simple_qreq($this->qreq));
            throw $redir;
        } catch (PermissionProblem $perm) {
            $this->error_exit(MessageItem::error("<5>" . $perm->unparse_html()));
        }
    }


    function handle_pc_update() {
        $reviewer = $this->qreq->reviewer;
        if (($rname = $this->conf->sanitize_round_name($this->qreq->rev_round)) === "") {
            $rname = "unnamed";
        }
        $round = CsvGenerator::quote(":" . (string) $rname);

        $confset = $this->conf->conflict_set();
        $acceptable_review_types = [];
        foreach ([0, REVIEW_PC, REVIEW_SECONDARY, REVIEW_PRIMARY, REVIEW_META] as $t) {
            $acceptable_review_types[] = (string) $t;
        }

        $prow = $this->prow;
        $t = ["paper,action,email,round,conflict\n"];
        foreach ($this->conf->pc_members() as $cid => $p) {
            if ($reviewer
                && strcasecmp($p->email, $reviewer) != 0
                && (string) $p->contactId !== $reviewer) {
                continue;
            }

            if (isset($this->qreq["assrev{$prow->paperId}u{$cid}"])) {
                $assignment = $this->qreq["assrev{$prow->paperId}u{$cid}"];
            } else if (isset($this->qreq["pcs{$cid}"])) {
                $assignment = $this->qreq["pcs{$cid}"];
            } else {
                continue;
            }

            if (in_array($assignment, $acceptable_review_types, true)) {
                $revtype = ReviewInfo::unparse_assigner_action((int) $assignment);
                $conftype = "off";
            } else if ($assignment === "-1") {
                $revtype = "clearreview";
                $conftype = "on";
            } else if (($type = ReviewInfo::parse_type($assignment, true))) {
                $revtype = ReviewInfo::unparse_assigner_action($type);
                $conftype = "off";
            } else if (($ct = $confset->parse_assignment($assignment, 0)) !== false) {
                $revtype = "clearreview";
                $conftype = $assignment;
            } else {
                continue;
            }

            $myround = $round;
            if (isset($this->qreq["rev_round{$prow->paperId}u{$cid}"])) {
                $x = $this->conf->sanitize_round_name($this->qreq["rev_round{$prow->paperId}u{$cid}"]);
                if ($x !== false) {
                    $myround = $x === "" ? "unnamed" : CsvGenerator::quote($x);
                }
            }

            $user = CsvGenerator::quote($p->email);
            $t[] = "{$prow->paperId},conflict,{$user},,{$conftype}\n";
            $t[] = "{$prow->paperId},{$revtype},{$user},{$myround}\n";
        }

        $aset = new AssignmentSet($this->user);
        $aset->set_override_conflicts(true);
        $aset->enable_papers($this->prow);
        $aset->parse(join("", $t));
        $ok = $aset->execute();
        if ($this->qreq->ajax) {
            json_exit($aset->json_result());
        } else {
            $ok && $aset->prepend_msg("<0>Assignments saved", MessageSet::SUCCESS);
            $this->conf->feedback_msg($aset->message_list());
            $ok && $this->conf->redirect_self($this->qreq);
        }
    }

    function handle_requestreview() {
        $result = RequestReview_API::requestreview($this->user, $this->qreq, $this->prow);
        if ($result->content["ok"]) {
            assert(is_array($result->content["message_list"]));
            $this->conf->feedback_msg($result->content["message_list"]);
            $this->conf->redirect_self($this->qreq, ["email" => null, "firstName" => null, "lastName" => null, "affiliation" => null, "round" => null, "reason" => null, "override" => null]);
        } else {
            $emx = null;
            foreach ($result->content["message_list"] ?? [] as $mx) {
                '@phan-var-force MessageItem $mx';
                if ($mx->field === "email") {
                    $emx = $mx;
                } else if ($mx->field === "override" && $emx) {
                    $emx->message .= "<p>To request a review anyway, either retract the refusal or submit again with “Override” checked.</p>";
                }
            }
            $this->ms->append_list($result->content["message_list"] ?? []);
            $this->assign_load();
        }
    }

    function handle_denyreview() {
        $result = RequestReview_API::denyreview($this->user, $this->qreq, $this->prow);
        if ($result->content["ok"]) {
            $this->conf->success_msg("<0>Proposed reviewer denied");
            $this->conf->redirect_self($this->qreq, ["email" => null, "firstName" => null, "lastName" => null, "affiliation" => null, "round" => null, "reason" => null, "override" => null, "deny" => null, "denyreview" => null]);
        } else {
            $this->ms->append_list($result->content["message_list"] ?? []);
            $this->assign_load();
        }
    }

    function handle_retractreview() {
        $result = RequestReview_API::retractreview($this->user, $this->qreq, $this->prow);
        if ($result->content["ok"]) {
            if ($result->content["notified"]) {
                $this->conf->feedback_msg(
                    MessageItem::success("<0>Review retracted"),
                    MessageItem::inform("<0>The reviewer was notified that they do not need to complete their review.")
                );
            } else {
                $this->conf->success_msg("<0>Review request retracted");
            }
            $this->conf->redirect_self($this->qreq, ["email" => null, "firstName" => null, "lastName" => null, "affiliation" => null, "round" => null, "reason" => null, "override" => null, "retractreview" => null]);
        } else {
            $this->ms->append_list($result->content["message_list"] ?? []);
            $this->assign_load();
        }
    }

    function handle_undeclinereview() {
        $result = RequestReview_API::undeclinereview($this->user, $this->qreq, $this->prow);
        if ($result->content["ok"]) {
            $email = $this->qreq->email ? : "You";
            $this->conf->feedback_msg(
                MessageItem::success("<0>Review refusal removed"),
                MessageItem::inform("<0>{$email} may now be asked again to review this submission.")
            );
            $this->conf->redirect_self($this->qreq, ["email" => null, "firstName" => null, "lastName" => null, "affiliation" => null, "round" => null, "reason" => null, "override" => null, "undeclinereview" => null]);
        } else {
            $this->ms->append_list($result->content["message_list"] ?? []);
            $this->assign_load();
        }
    }

    function handle_request() {
        $qreq = $this->qreq;
        if (isset($qreq->update) && $qreq->valid_post()) {
            if ($this->user->allow_administer($this->prow)) {
                $this->handle_pc_update();
            } else if ($this->qreq->ajax) {
                json_exit(JsonResult::make_error(403, "<0>Only administrators can assign reviews"));
            }
        }
        if ((isset($qreq->requestreview) || isset($qreq->approvereview))
            && $qreq->valid_post()) {
            $this->handle_requestreview();
        }
        if ((isset($qreq->deny) || isset($qreq->denyreview))
            && $qreq->valid_post()) {
            $this->handle_denyreview();
        }
        if (isset($qreq->retractreview)
            && $qreq->valid_post()) {
            $this->handle_retractreview();
        }
        if (isset($qreq->undeclinereview)
            && $qreq->valid_post()) {
            $this->handle_undeclinereview();
        }
        $this->conf->feedback_msg($this->ms);
    }

    /** @param ReviewInfo|ReviewRequestInfo|ReviewRefusalInfo $rrow */
    private function print_reqrev_main($rrow, $namex, $time) {
        $rname = $rrow->status_title(true) . " (" . $rrow->status_description() . ")";
        if ($this->user->can_view_review($this->prow, $rrow)) {
            $rname = Ht::link($rname, $this->prow->reviewurl(["r" => $rrow->reviewId]));
        }
        echo $rname, ': ', $namex,
            '</div><div class="f-h"><ul class="x mb-0">';
        echo '<li>requested';
        if ($rrow->timeRequested) {
            echo ' ', $this->conf->unparse_time_relative((int) $rrow->timeRequested);
        }
        if ($rrow->requestedBy == $this->user->contactId) {
            echo " by you";
        } else if ($this->user->can_view_review_requester($this->prow, $rrow)) {
            echo " by ", $this->user->reviewer_html_for($rrow->requestedBy);
        }
        echo '</li>';
        if ($rrow->reviewStatus === ReviewInfo::RS_ACCEPTED) {
            echo '<li>accepted';
            if ($time) {
                echo ' ', $this->conf->unparse_time_relative($time);
            }
            echo '</li>';
        }
        echo '</ul></div>';
    }

    /** @param ReviewRequestInfo $rrow */
    private function print_reqrev_proposal($rrow, $namex, $rrowid) {
        echo "Review proposal: ", $namex, '</div><div class="f-h"><ul class="x mb-0">';
        if ($rrow->timeRequested
            || $this->user->can_view_review_requester($this->prow, $rrow)) {
            echo '<li>proposed';
            if ($rrow->timeRequested) {
                echo ' ', $this->conf->unparse_time_relative((int) $rrow->timeRequested);
            }
            if ($rrow->requestedBy == $this->user->contactId) {
                echo " by you";
            } else if ($this->user->can_view_review_requester($this->prow, $rrow)) {
                echo " by ", $this->user->reviewer_html_for($rrow->requestedBy);
            }
            echo '</li>';
        }
        $reason = $rrow->reason;
        if ($this->allow_view_authors
            && ($potconf = $this->prow->potential_conflict_html($rrowid, true))) {
            foreach ($potconf->messages as $ml) {
                echo '<li class="fx">', $potconf->render_ul_item(null, "possible conflict: ", $ml), '</li>';
            }
            $reason = $reason ? : "This reviewer appears to have a conflict with the submission authors.";
        }
        echo '</ul></div>';
        return $reason;
    }

    /** @param ReviewRefusalInfo $rrow */
    private function print_reqrev_denied($rrow, $namex) {
        echo "Declined request: ", $namex,
            '</div><div class="f-h fx"><ul class="x mb-0">';
        if ($rrow->timeRequested
            || $this->user->can_view_review_requester($this->prow, $rrow)) {
            echo '<li>requested';
            if ($rrow->timeRequested) {
                echo ' ', $this->conf->unparse_time_relative((int) $rrow->timeRequested);
            }
            if ($rrow->requestedBy == $this->user->contactId) {
                echo " by you";
            } else if ($this->user->can_view_review_requester($this->prow, $rrow)) {
                echo " by ", $this->user->reviewer_html_for($rrow->requestedBy);
            }
            echo '</li>';
        }
        echo '<li>declined';
        if ($rrow->timeRefused) {
            echo ' ', $this->conf->unparse_time_relative((int) $rrow->timeRefused);
        }
        if ($rrow->refusedBy
            && (!$rrow->contactId || $rrow->contactId != $rrow->refusedBy)) {
            if ($rrow->refusedBy == $this->user->contactId) {
                echo " by you";
            } else {
                echo " by ", $this->user->reviewer_html_for($rrow->refusedBy);
            }
        }
        echo '</li>';
        if ((string) $rrow->reason !== ""
            && $rrow->reason !== "request denied by chair") {
            echo '<li class="mb-0-last-child">', Ht::format0("reason: " . $rrow->reason), '</li>';
        }
        echo '</ul></div>';
    }

    /** @param ReviewInfo|ReviewRequestInfo|ReviewRefusalInfo $rrow */
    private function print_reqrev($rrow, $time) {
        echo '<div class="ctelt"><div class="ctelti has-fold';
        if ($rrow->reviewType === REVIEW_REQUEST
            && ($this->user->can_administer($this->prow)
                || $rrow->requestedBy == $this->user->contactId)) {
            echo ' foldo';
        } else {
            echo ' foldc';
        }
        echo '">';

        // create contact-like for identity
        $rrowid = $rrow->reviewer();

        // render name
        $actas = "";
        if (isset($rrow->contactId) && $rrow->contactId > 0) {
            $name = $this->user->reviewer_html_for($rrowid);
            if ($rrow->contactId !== $this->user->contactId
                && $this->user->privChair
                && $this->user->allow_administer($this->prow)) {
                $actas = ' ' . Ht::link(Ht::img("viewas.png", "[Act as]", ["title" => "Become user"]),
                    $this->prow->reviewurl(["actas" => $rrowid->email]));
            }
        } else {
            $name = Text::nameo_h($rrowid, NAME_P);
        }
        $fullname = $name;
        if ((string) $rrowid->affiliation !== "") {
            $fullname .= ' <span class="auaff">(' . htmlspecialchars($rrowid->affiliation) . ')</span>';
        }
        if ((string) $rrowid->firstName !== ""
            || (string) $rrowid->lastName !== "") {
            $fullname .= ' &lt;' . Ht::link(htmlspecialchars($rrowid->email), "mailto:" . $rrowid->email, ["class" => "q"]) . '&gt;';
        }

        $namex = "<span class=\"fn\">{$name}</span><span class=\"fx\">{$fullname}</span>{$actas}";
        if ($rrow->reviewType !== REVIEW_REFUSAL) {
            $namex .= ' ' . review_type_icon($rrowid->isPC ? REVIEW_PC : REVIEW_EXTERNAL, true);
        }
        if ($this->user->can_view_review_meta($this->prow, $rrow)) {
            $namex .= ReviewInfo::make_round_h($this->conf, $rrow->reviewRound);
        }

        // main render
        echo '<div class="ui js-foldup"><button type="button" class="q ui js-foldup">', expander(null, 0), '</button>';
        $reason = null;
        if ($rrow->reviewType >= 0) {
            $this->print_reqrev_main($rrow, $namex, $time);
        } else if ($rrow->reviewType === REVIEW_REQUEST) {
            $reason = $this->print_reqrev_proposal($rrow, $namex, $rrowid);
        } else {
            $this->print_reqrev_denied($rrow, $namex);
        }

        // render form
        if ($this->user->can_administer($this->prow)
            || ($rrow->reviewType !== REVIEW_REFUSAL
                && $this->user->contactId > 0
                && $rrow->requestedBy == $this->user->contactId)) {
            echo Ht::form($this->conf->hoturl("=assign", [
                    "p" => $this->prow->paperId, "action" => "managerequest",
                    "email" => $rrowid->email, "round" => $rrow->reviewRound
                ]), ["class" => "fx"]);
            if (!isset($rrow->contactId) || !$rrow->contactId) {
                echo Ht::hidden("firstName", $rrowid->firstName),
                    Ht::hidden("lastName", $rrowid->lastName),
                    Ht::hidden("affiliation", $rrowid->affiliation);
            }
            $buttons = [];
            if ($reason) {
                echo Ht::hidden("reason", $reason);
            }
            if ($rrow->reviewType === REVIEW_REQUEST
                && $this->user->can_administer($this->prow)) {
                echo Ht::hidden("override", 1);
                $buttons[] = Ht::submit("approvereview", "Approve proposal", ["class" => "btn-sm btn-success"]);
                $buttons[] = Ht::submit("denyreview", "Deny proposal", ["class" => "btn-sm ui js-deny-review-request"]); // XXX reason
            }
            if ($rrow->reviewType >= 0 && $rrow->reviewStatus > ReviewInfo::RS_ACCEPTED) {
                $buttons[] = Ht::submit("retractreview", "Retract review", ["class" => "btn-sm"]);
            } else if ($rrow->reviewType >= 0) {
                $buttons[] = Ht::submit("retractreview", "Retract review request", ["class" => "btn-sm"]);
            } else if ($rrow->reviewType === REVIEW_REQUEST
                       && $this->user->contactId > 0
                       && $rrow->requestedBy == $this->user->contactId) {
                $buttons[] = Ht::submit("retractreview", "Retract proposal", ["class" => "btn-sm"]);
            } else if ($rrow->reviewType === REVIEW_REFUSAL) {
                $buttons[] = Ht::submit("undeclinereview", "Remove declined request", ["class" => "btn-sm"]);
                $buttons[] = '<span class="hint">(allowing review to be reassigned)</span>';
            }
            if ($buttons) {
                echo '<div class="btnp">', join("", $buttons), '</div>';
            }
            echo '</form>';
        }

        echo '</div></div>';
    }

    /** @param Contact $pc
     * @param AssignmentCountSet $acs */
    private function print_pc_assignment($pc, $acs) {
        // first, name and assignment
        $ct = $this->prow->conflict_type($pc);
        $rrow = $this->prow->review_by_user($pc);
        if (Conflict::is_author($ct)) {
            $revtype = -2;
        } else {
            $revtype = $rrow ? $rrow->reviewType : 0;
        }
        $crevtype = $revtype;
        if ($crevtype == 0 && Conflict::is_conflicted($ct)) {
            $crevtype = -1;
        }
        $potconf = null;
        if ($this->allow_view_authors && $revtype != -2) {
            $potconf = $this->prow->potential_conflict_html($pc, !Conflict::is_conflicted($ct));
        }

        echo '<div class="ctelt">',
            '<div class="ctelti has-assignment has-fold foldc" data-pid="', $this->prow->paperId,
            '" data-uid="', $pc->contactId,
            '" data-review-type="', $revtype;
        if (Conflict::is_conflicted($ct)) {
            echo '" data-conflict-type="1';
        }
        if (!$revtype && $this->prow->review_refusals_by_user($pc)) {
            echo '" data-assignment-declined="1';
        }
        if ($rrow && $rrow->reviewRound && ($rn = $rrow->round_name())) {
            echo '" data-review-round="', htmlspecialchars($rn);
        }
        if ($rrow && $rrow->reviewStatus >= ReviewInfo::RS_DRAFTED) {
            echo '" data-review-in-progress="';
        }
        echo '"><div class="pctbname pctbname', $crevtype, ' ui js-assignment-fold">',
            '<button type="button" class="q ui js-assignment-fold">', expander(null, 0),
            $this->user->reviewer_html_for($pc), '</button>';
        if ($crevtype != 0) {
            echo review_type_icon($crevtype, $rrow && $rrow->reviewStatus < ReviewInfo::RS_ADOPTED, "ml-2"),
                $rrow ? $rrow->round_h() : "";
        }
        if ($revtype >= 0) {
            $pf = $this->prow->preference($pc);
            $tv = $pf->preference ? null : $this->prow->topic_interest_score($pc);
            if ($pf->exists() || $tv) {
                echo " ", $pf->unparse_span($tv);
            }
        }
        echo '</div>'; // .pctbname
        if ($potconf) {
            echo '<div class="need-tooltip" data-tooltip-class="gray" data-tooltip="', str_replace('"', '&quot;', PaperInfo::potential_conflict_tooltip_html($potconf)), '">', $potconf->announce, '</div>';
        }

        // then, number of reviews
        echo '<div class="pctbnrev">';
        $ac = $acs->get($pc->contactId);
        if ($ac->rev === 0) {
            echo "0 reviews";
        } else {
            echo '<a class="q" href="',
                $this->conf->hoturl("search", "q=re:" . urlencode($pc->email)), '">',
                plural($ac->rev, "review"), "</a>";
            if ($ac->pri && $ac->pri < $ac->rev) {
                echo '&nbsp; (<a class="q" href="',
                    $this->conf->hoturl("search", "q=pri:" . urlencode($pc->email)),
                    "\">{$ac->pri} primary</a>)";
            }
        }
        echo "</div></div></div>\n"; // .pctbnrev .ctelti .ctelt
    }

    function print() {
        $prow = $this->prow;
        $user = $this->user;
        $this->pt = new PaperTable($user, $this->qreq, $prow);
        $this->pt->resolve_review(false);
        $this->allow_view_authors = $user->allow_view_authors($prow);
        PaperTable::print_header($this->pt, $this->qreq);

        // begin form and table
        $this->pt->print_paper_info();

        // reviewer information
        $t = $this->pt->review_table();
        if ($t !== "") {
            echo '<div class="pcard revcard">',
                '<h2 class="revcard-head" id="current-reviews">Current reviews</h2>',
                '<div class="revpcard-body">', $t, '</div></div>';
        }

        // requested reviews
        $requests = [];
        foreach ($this->pt->all_reviews() as $rrow) {
            if ($rrow->reviewType < REVIEW_SECONDARY
                && $rrow->reviewStatus < ReviewInfo::RS_DRAFTED
                && $user->can_view_review_identity($prow, $rrow)
                && ($user->can_administer($prow) || $rrow->requestedBy == $user->contactId)) {
                $requests[] = [0, max((int) $rrow->timeRequestNotified, (int) $rrow->timeRequested), count($requests), $rrow];
            }
        }
        foreach ($prow->review_requests() as $rrow) {
            if ($user->can_view_review_identity($prow, $rrow)) {
                $requests[] = [1, (int) $rrow->timeRequested, count($requests), $rrow];
            }
        }
        foreach ($prow->review_refusals() as $rrow) {
            if ($user->can_view_review_identity($prow, $rrow)) {
                $requests[] = [2, (int) $rrow->timeRefused, count($requests), $rrow];
            }
        }
        usort($requests, function ($a, $b) {
            return $a[0] <=> $b[0] ? : ($a[1] <=> $b[1] ? : $a[2] <=> $b[2]);
        });

        if (!empty($requests)) {
            echo '<div class="pcard revcard">',
                '<h2 class="revcard-head" id="review-requests">Review requests</h2>',
                '<div class="revcard-body"><div class="ctable-wide">';
            foreach ($requests as $req) {
                $this->print_reqrev($req[3], $req[1]);
            }
            echo '</div></div></div>';
        }

        // PC assignments
        if ($user->can_administer($prow)) {
            $acs = AssignmentCountSet::load($user, AssignmentCountSet::HAS_REVIEW);

            // PC conflicts row
            echo '<div class="pcard revcard">',
                '<h2 class="revcard-head" id="pc-assignments">PC assignments</h2>',
                '<div class="revcard-body">',
                Ht::form($this->conf->hoturl("=assign", "p=$prow->paperId"), [
                    "id" => "f-pc-assignments",
                    "class" => "need-unload-protection need-diff-check",
                    "data-differs-toggle" => "paper-alert"
                ]);
            Ht::stash_script('$(hotcrp.load_editable_pc_assignments)');

            if ($this->conf->has_topics()) {
                echo "<p>Review preferences display as “P#”, topic scores as “T#”.</p>";
            } else {
                echo "<p>Review preferences display as “P#”.</p>";
            }

            echo '<div class="pc-ctable has-assignment-set need-assignment-change"';
            $rev_rounds = array_keys($this->conf->round_selector_options(false));
            echo ' data-review-rounds="', htmlspecialchars(json_encode($rev_rounds)), '"',
                ' data-default-review-round="', htmlspecialchars($this->conf->assignment_round_option(false)), '">';

            $this->conf->ensure_cached_user_collaborators();
            foreach ($this->conf->pc_members() as $pc) {
                if ($pc->can_accept_review_assignment_ignore_conflict($prow)) {
                    $this->print_pc_assignment($pc, $acs);
                }
            }

            echo "</div>\n",
                '<div class="aab">',
                '<div class="aabut">', Ht::submit("update", "Save assignments", ["class" => "btn-primary"]), '</div>',
                '<div class="aabut">', Ht::submit("cancel", "Cancel"), '</div>',
                '<div id="assresult" class="aabut"></div>',
                '</div></form></div></div>';
        }


        // add external reviewers
        $req = "Request external review";
        if (!$user->allow_administer($prow) && $this->conf->setting("extrev_chairreq")) {
            $req = "Propose external review";
        }
        echo '<div class="pcard revcard">',
            Ht::form($this->conf->hoturl("=assign", "p=$prow->paperId"), ["novalidate" => true]),
            "<h2 class=\"revcard-head\" id=\"external-reviews\">", $req, "</h2><div class=\"revcard-body\">";

        echo '<p class="w-text">', $this->conf->_i("external_review_request_description");
        if ($user->allow_administer($prow)) {
            echo "\nTo create an anonymous review with a review token, leave Name and Email blank.";
        }
        echo '</p>';

        if (($rrow = $prow->review_by_user($this->user))
            && $rrow->reviewType == REVIEW_SECONDARY
            && ($round_name = $this->conf->round_name($rrow->reviewRound))) {
            echo Ht::hidden("round", $round_name);
        }
        $email_class = "fullw";
        if ($this->user->can_lookup_user()) {
            $email_class .= " uii js-email-populate";
            if ($this->allow_view_authors) {
                $email_class .= " want-potential-conflict";
            }
        }
        echo '<div class="w-text g">',
            '<div class="', $this->ms->control_class("email", "f-i"), '">',
            Ht::label("Email", "revreq_email"),
            $this->ms->feedback_html_at("email"),
            Ht::entry("email", (string) $this->qreq->email, ["id" => "revreq_email", "size" => 52, "class" => $email_class, "autocomplete" => "off", "type" => "email"]),
            '</div>',
            '<div class="f-mcol">',
            '<div class="', $this->ms->control_class("firstName", "f-i"), '">',
            Ht::label("First name (given name)", "revreq_firstName"),
            $this->ms->feedback_html_at("firstName"),
            Ht::entry("firstName", (string) $this->qreq->firstName, ["id" => "revreq_firstName", "size" => 24, "class" => "fullw", "autocomplete" => "off"]),
            '</div><div class="', $this->ms->control_class("lastName", "f-i"), '">',
            Ht::label("Last name (family name)", "revreq_lastName"),
            $this->ms->feedback_html_at("lastName"),
            Ht::entry("lastName", (string) $this->qreq->lastName, ["id" => "revreq_lastName", "size" => 24, "class" => "fullw", "autocomplete" => "off"]),
            '</div></div>',
            '<div class="', $this->ms->control_class("affiliation", "f-i"), '">',
            Ht::label("Affiliation", "revreq_affiliation"),
            $this->ms->feedback_html_at("affiliation"),
            Ht::entry("affiliation", (string) $this->qreq->affiliation, ["id" => "revreq_affiliation", "size" => 52, "class" => "fullw", "autocomplete" => "off"]),
            '</div>';
        if ($this->allow_view_authors) {
            echo '<div class="potential-conflict-container hidden f-i"><label>Potential conflict</label><div class="potential-conflict"></div></div>';
        }

        // reason area
        $null_mailer = new HotCRPMailer($this->conf);
        $reqbody = $null_mailer->expand_template("requestreview");
        if ($reqbody && strpos($reqbody["body"], "%REASON%") !== false) {
            echo '<div class="f-i">',
                Ht::label('Note to reviewer <span class="n">(optional)</span>', "revreq_reason"),
                Ht::textarea("reason", $this->qreq->reason,
                        ["class" => "need-autogrow fullw", "rows" => 2, "cols" => 60, "spellcheck" => "true", "id" => "revreq_reason"]),
                "</div>\n\n";
        }

        if ($user->can_administer($prow)) {
            echo '<label class="', $this->ms->control_class("override", "checki"), '"><span class="checkc">',
                Ht::checkbox("override"),
                ' </span>Override declined requests</label>';
        }

        echo '<div class="aab">',
            '<div class="aabut aabutsp">', Ht::submit("requestreview", "Request review", ["class" => "btn-primary"]), '</div>',
            '<div class="aabut"><button type="button" class="link ulh ui js-request-review-preview-email">Preview request email</button></div>',
            "</div>\n\n";

        echo "</div></div></form></div></article>\n";

        $this->qreq->print_footer();
    }

    static function go(Contact $user, Qrequest $qreq) {
        if (!$user->email) {
            $user->escape();
        }
        $user->add_overrides(Contact::OVERRIDE_CONFLICT);
        $ap = new Assign_Page($user, $qreq);
        $ap->assign_load();
        $ap->handle_request();
        $ap->print();
    }
}
