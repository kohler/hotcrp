<?php
// pages/p_mail.php -- HotCRP mail tool
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class Mail_Page {
    /** @var Conf */
    public $conf;
    /** @var Contact */
    public $viewer;
    /** @var Qrequest */
    public $qreq;
    /** @var array<string,string> */
    private $search_topt;
    /** @var MailRecipients */
    private $recip;
    /** @var bool */
    private $done = false;

    function __construct(Contact $viewer, Qrequest $qreq) {
        $this->conf = $viewer->conf;
        $this->viewer = $viewer;
        $this->qreq = $qreq;

        // set list of searchable paper collections
        if ($viewer->privChair) {
            $this->search_topt["s"] = PaperSearch::$search_type_names["s"];
            if ($this->conf->time_pc_view_decision(false)
                && $this->conf->has_any_accepted()) {
                $this->search_topt["acc"] = PaperSearch::$search_type_names["acc"];
            }
            $this->search_topt["unsub"] = "Unsubmitted";
            $this->search_topt["all"] = PaperSearch::$search_type_names["all"];
        }
        if ($viewer->privChair ? $this->conf->has_any_manager() : $viewer->is_manager()) {
            $this->search_topt["admin"] = PaperSearch::$search_type_names["admin"];
        }
        $this->search_topt["req"] = PaperSearch::$search_type_names["req"];

        $this->recip = new MailRecipients($viewer);
    }

    function clean_request() {
        $qreq = $this->qreq;
        if (isset($qreq->psearch)
            || (isset($qreq->default) && $qreq->defaultfn === "recheck")) {
            $qreq->recheck = "1";
        }
        if (isset($qreq->recipients) && !isset($qreq->to)) {
            $qreq->to = $qreq->recipients;
        }
        if (!isset($qreq->t) || !isset($this->search_topt[$qreq->t])) {
            $qreq->t = (array_keys($this->search_topt))[0];
        }
        if (isset($qreq->monreq)) {
            $qreq->template = "myreviewremind";
        }

        // load mail from log
        if (isset($qreq->mailid)
            && ctype_digit($qreq->mailid)
            && $this->viewer->privChair
            && !$qreq->send) {
            $row = $this->conf->fetch_first_object("select * from MailLog where mailId=" . $qreq->mailid);
            if ($row) {
                foreach (["recipients", "q", "t", "cc", "subject"] as $field) {
                    if (isset($row->$field) && !isset($qreq[$field]))
                        $qreq[$field] = $row->$field;
                }
                if (isset($row->replyto) && !isset($qreq["reply-to"])) {
                    $qreq["reply-to"] = $row->replyto;
                }
                if (isset($row->emailBody) && !isset($qreq->body)) {
                    $qreq->body = $row->emailBody;
                }
                if (isset($row->recipients) && !isset($qreq->to)) {
                    $qreq->to = $row->recipients;
                }
                if ($row->q) {
                    $qreq["plimit"] = 1;
                }
            }
        }

        // paper selection
        if (!isset($qreq->q) || trim($qreq->q) == "(All)") {
            $qreq->q = "";
        }
        if (isset($qreq->p) && !$qreq->has_a("p")) {
            $qreq->set_a("p", preg_split('/\s+/', $qreq->p));
        } else if (!isset($qreq->p) && isset($qreq->pap)) {
            $qreq->set_a("p", $qreq->has_a("pap") ? $qreq->get_a("pap") : preg_split('/\s+/', $qreq->pap));
        }
        // It's OK to just set $qreq->p from the input without
        // validation because MailRecipients filters internally
        if (isset($qreq->prevt) && isset($qreq->prevq)) {
            if (!isset($qreq->plimit)) {
                unset($qreq->p);
            } else if (($qreq->prevt !== $qreq->t || $qreq->prevq !== $qreq->q)
                       && !isset($qreq->recheck)) {
                $this->conf->warning_msg("<0>Please review the selected submissions now that you have changed the submission search");
                $qreq->recheck = "1";
            }
        }
        if ($qreq->has_a("p") && !isset($qreq->recheck)) {
            $papersel = [];
            foreach ($qreq->get_a("p") as $p) {
                if (($p = cvtint($p)) > 0)
                    $papersel[] = $p;
            }
            sort($papersel);
            $qreq->q = join(" ", $papersel);
            $qreq->plimit = 1;
        } else if (isset($qreq->plimit)) {
            $search = new PaperSearch($this->viewer, ["t" => $qreq->t, "q" => $qreq->q]);
            $papersel = $search->paper_ids();
            sort($papersel);
        } else {
            $qreq->q = "";
            $papersel = null;
        }

        // template
        $null_mailer = new HotCRPMailer($this->conf, null, [
            "requester_contact" => $this->viewer, "width" => false
        ]);
        if (isset($qreq->template) && !isset($qreq->check) && !isset($qreq->default)) {
            $t = $qreq->template ?? "generic";
            $template = (array) $this->conf->mail_template($t);
            if (((!isset($template["title"]) || $template["title"] === false)
                 && !isset($template["allow_template"]))
                || (isset($template["allow_template"]) && $template["allow_template"] === false)) {
                $template = (array) $this->conf->mail_template("generic");
            }
            if (!isset($qreq->to) || $qreq->loadtmpl != -1) {
                $qreq->to = $template["default_recipients"] ?? "s";
            }
            if (isset($template["default_search_type"])) {
                $qreq->t = $template["default_search_type"];
            }
            $qreq->subject = $null_mailer->expand($template["subject"]);
            $qreq->body = $null_mailer->expand($template["body"]);
        }

        // fields: subject, body, cc, reply-to
        if (!isset($qreq->subject)) {
            $tmpl = $this->conf->mail_template("generic");
            $qreq->subject = $null_mailer->expand($tmpl->subject, "subject");
        }
        $qreq->subject = trim($qreq->subject);
        if (str_starts_with($qreq->subject, "[{$this->conf->short_name}] ")) {
            $qreq->subject = substr($qreq->subject, strlen($this->conf->short_name) + 3);
        }
        if (!isset($qreq->body)) {
            $tmpl = $this->conf->mail_template("generic");
            $qreq->body = $null_mailer->expand($tmpl->body, "body");
        }
        if (isset($qreq->cc) && $this->viewer->is_manager()) {
            // XXX should only apply to papers you administer
            $qreq->cc = simplify_whitespace($qreq->cc);
        } else {
            $qreq->cc = $this->conf->opt("emailCc") ?? "";
        }
        if (isset($qreq["reply-to"]) && $this->viewer->is_manager()) {
            // XXX should only apply to papers you administer
            $qreq["reply-to"] = simplify_whitespace($qreq["reply-to"]);
        } else {
            $qreq["reply-to"] = $this->conf->opt("emailReplyTo") ?? "";
        }

        // set MailRecipients properties
        $this->recip->set_newrev_since($this->qreq->newrev_since);
        $this->recip->set_recipients($this->qreq->to);
        $this->recip->set_paper_ids($papersel);
    }

    private function request() {
        $this->clean_request();

        // warn if no papers match
        if ($this->recip->need_papers()
            && $this->recip->has_paper_ids()
            && empty($this->recip->paper_ids())
            && !isset($this->qreq->recheck)) {
            $this->conf->error_msg("<0>No papers match that search");
            $this->recip->set_paper_ids(null);
            unset($this->qreq->check, $this->qreq->send);
        }
    }

    function print_review_requests() {
        $plist = new PaperList("reqrevs", new PaperSearch($this->viewer, ["t" => "req", "q" => ""]));
        $plist->set_table_id_class("foldpl", "fullw");
        if ($plist->is_empty()) {
            $this->conf->warning_msg("<5>You have not requested any external reviews. " . Ht::link("Return home", $this->conf->hoturl("index")));
        } else {
            echo "<h2>Requested reviews</h2>\n\n";
            $plist->set_table_decor(PaperList::DECOR_HEADER | PaperList::DECOR_LIST);
            $plist->print_table_html();
            echo '<div class="info">';
            if ($plist->has("need_review")) {
                echo "Some of your requested external reviewers have not completed their reviews. To send them an email reminder, check the text below and then select “Prepare mail.” You’ll get a chance to review the emails and select specific reviewers to remind.";
            } else {
                echo 'All of your requested external reviewers have completed their reviews. ', Ht::link("Return home", $this->conf->hoturl("index"));
            }
            echo "</div>\n";
        }
        $this->done = !$plist->has("need_review");
    }

    function print_keyword_help() {
        echo '<div id="mailref">Keywords enclosed in percent signs, such as <code>%NAME%</code> or <code>%REVIEWDEADLINE%</code>, are expanded for each mail.  Use the following syntax:
<hr class="g">

<div class="ctable no-hmargin">
<dl class="ctelt">
<dt><code>%URL%</code></dt>
    <dd>Site URL.</dd>
<dt><code>%NUMSUBMITTED%</code></dt>
    <dd>Number of papers submitted.</dd>
<dt><code>%NUMACCEPTED%</code></dt>
    <dd>Number of papers accepted.</dd>
<dt><code>%NAME%</code></dt>
    <dd>Full name of recipient.</dd>
<dt><code>%FIRST%</code>, <code>%LAST%</code></dt>
    <dd>First and last names, if any, of recipient.</dd>
<dt><code>%EMAIL%</code></dt>
    <dd>Email address of recipient.</dd>
<dt><code>%REVIEWDEADLINE%</code></dt>
    <dd>Reviewing deadline appropriate for recipient.</dd>
</dl><dl class="ctelt">
<dt><code>%NUMBER%</code></dt>
    <dd>Paper number relevant for mail.</dd>
<dt><code>%TITLE%</code></dt>
    <dd>Paper title.</dd>
<dt><code>%TITLEHINT%</code></dt>
    <dd>First couple words of paper title (useful for mail subject).</dd>
<dt><code>%OPT(AUTHORS)%</code></dt>
    <dd>Paper authors (if recipient is allowed to see the authors).</dd>
';

        $opts = array_filter($this->conf->options()->normal(), function ($o) {
            return $o->search_keyword() !== false
                && $o->can_render(FieldRender::CFMAIL);
        });
        usort($opts, function ($a, $b) {
            if ($a->final !== $b->final) {
                return $a->final ? 1 : -1;
            } else {
                return PaperOption::compare($a, $b);
            }
        });
        if (!empty($opts)) {
            echo '<dt><code>%', htmlspecialchars($opts[0]->search_keyword()), '%</code></dt>
    <dd>Value of paper’s “', $opts[0]->title_html(), '” submission field.';
            if (count($opts) > 1) {
                echo ' Also ', join(", ", array_map(function ($o) {
                    return '<code>%' . htmlspecialchars($o->search_keyword()) . '%</code>';
                }, array_slice($opts, 1))), '.';
            }
            echo "</dd>\n<dt><code>%IF(", htmlspecialchars($opts[0]->search_keyword()), ')%...%ENDIF%</code></dt>
    <dd>Include text if paper has a “', $opts[0]->title_html(), "” submission field.</dd>\n";
        }
        echo '</dl><dl class="ctelt">
<dt><code>%REVIEWS%</code></dt>
    <dd>Pretty-printed paper reviews.</dd>
<dt><code>%COMMENTS%</code></dt>
    <dd>Pretty-printed paper comments, if any.</dd>
<dt><code>%COMMENTS(<i>tag</i>)%</code></dt>
    <dd>Comments tagged #<code><i>tag</i></code>, if any.</dd>
</dl><dl class="ctelt">
<dt><code>%IF(SHEPHERD)%...%ENDIF%</code></dt>
    <dd>Include text if a shepherd is assigned.</dd>
<dt><code>%SHEPHERD%</code></dt>
    <dd>Shepherd name and email, if any.</dd>
<dt><code>%SHEPHERDNAME%</code></dt>
    <dd>Shepherd name, if any.</dd>
<dt><code>%SHEPHERDEMAIL%</code></dt>
    <dd>Shepherd email, if any.</dd>
</dl><dl class="ctelt">
<dt><code>%IF(#<i>tag</i>)%...%ENDIF%</code></dt>
    <dd>Include text if paper has tag <code><i>tag</i></code>.</dd>
<dt><code>%TAGVALUE(<i>tag</i>)%</code></dt>
    <dd>Value of paper’s <code><i>tag</i></code>.</dd>
</dl>
</div></div>';
    }

    function print_template() {
        echo '<div class="aa" style="padding-left:8px">
  <strong>Template:</strong> &nbsp;';
        $tmpl = $tmploptions = [];
        foreach (array_keys($this->conf->mail_template_map()) as $tname) {
            if (($template = $this->conf->mail_template($tname))
                && (isset($template->title) && $template->title !== false)
                && (!isset($template->allow_template) || $template->allow_template)
                && ($this->viewer->privChair || ($template->allow_pc ?? false)))
                $tmpl[] = $template;
        }
        usort($tmpl, "Conf::xt_order_compare");
        foreach ($tmpl as $t) {
            $tmploptions[$t->name] = $t->title;
        }
        if (!isset($this->qreq->template)
            || !isset($tmploptions[$this->qreq->template])) {
            $this->qreq->template = "generic";
        }
        echo Ht::select("template", $tmploptions, $this->qreq->template, ["class" => "uich js-mail-populate-template"]),
            ' &nbsp;
 <span class="hint">Templates are mail texts tailored for common conference tasks.</span>
</div>';
    }

    function print_mail_log() {
        $result = $this->conf->qe_raw("select mailId, subject, emailBody from MailLog where fromNonChair=0 and status>=0 order by mailId desc limit 200");
        if ($result->num_rows) {
            echo '<div style="padding-top:12px;max-height:24em;overflow-y:auto">',
                "<strong>Recent mails:</strong>\n";
            $i = 1;
            while (($row = $result->fetch_object())) {
                echo '<div class="mhdd"><div style="position:relative;overflow:hidden">',
                    '<div style="position:absolute;white-space:nowrap"><span style="min-width:2em;text-align:right;display:inline-block" class="dim">', $i, '.</span> <a class="q" href="', $this->conf->hoturl("mail", "mailid=" . $row->mailId), '">', htmlspecialchars($row->subject), ' &ndash; <span class="dim">', htmlspecialchars(UnicodeHelper::utf8_prefix($row->emailBody, 100)), "</span></a></div>",
                    "<br></div></div>\n";
                ++$i;
            }
            echo "</div>\n\n";
        }
        Dbl::free($result);
    }

    function print_form() {
        echo Ht::form($this->conf->hoturl("=mail", "check=1")),
            Ht::hidden("defaultfn", ""),
            Ht::hidden_default_submit("default", 1);

        $this->print_template();

        echo '<div class="mail" style="float:left;margin:4px 1em 12px 0"><table id="foldpsel" class="fold8c fold9o fold10c">', "\n";

        // ** TO
        echo '<tr><td class="mhnp nw"><label for="to">To:</label></td><td class="mhdd">',
            $this->recip->selectors(),
            "<div class=\"g\"></div>\n";

        // paper selection
        echo '<table class="fx9"><tr>';
        if ($this->viewer->privChair) {
            echo '<td class="nw">',
                Ht::checkbox("plimit", 1, isset($this->qreq->plimit), ["id" => "plimit"]),
                "&nbsp;</td><td>", Ht::label("Choose papers", "plimit"),
                "<span class=\"fx8\">:&nbsp; ";
        } else {
            echo '<td class="nw">Papers: &nbsp;</td><td>',
                Ht::hidden("plimit", 1), '<span>';
        }
        echo Ht::entry("q", (string) $this->qreq->q, [
                "id" => "q", "placeholder" => "(All)", "spellcheck" => false,
                "class" => "papersearch need-suggest js-autosubmit", "size" => 36,
                "data-submit-fn" => "recheck"
            ]), " &nbsp;in&nbsp;";
        if (count($this->search_topt) == 1) {
            echo htmlspecialchars($this->search_topt[$this->qreq->t]);
        } else {
            echo " ", Ht::select("t", $this->search_topt, $this->qreq->t, ["id" => "t"]);
        }
        echo " &nbsp;", Ht::submit("psearch", "Search"), "</span>";
        if (isset($this->qreq->plimit)
            && !isset($this->qreq->monreq)
            && isset($this->qreq->recheck)) {
            $plist = new PaperList("reviewers", new PaperSearch($this->viewer, ["t" => $this->qreq->t, "q" => $this->qreq->q]));
            echo "<div class=\"fx8";
            if ($plist->is_empty()) {
                echo "\">No papers match that search.";
            } else {
                echo " g\">";
                $plist->print_table_html();
            }
            echo '</div>', Ht::hidden("prevt", $this->qreq->t),
                Ht::hidden("prevq", $this->qreq->q);
        }
        echo "</td></tr></table>\n";

        echo '<div class="fx10" style="margin-top:0.35em">';
        if (!$this->qreq->newrev_since
            && ($t = $this->conf->setting("pcrev_informtime"))) {
            $this->qreq->newrev_since = $this->conf->parseableTime($t, true);
        }
        echo 'Assignments since:&nbsp; ',
            Ht::entry("newrev_since", $this->qreq->newrev_since,
                      ["placeholder" => "(all)", "size" => 30, "class" => "js-autosubmit", "data-submit-fn" => "recheck"]),
            '</div>';

        echo '<div class="fx9 g"></div>';

        Ht::stash_script('function mail_recipients_fold(event) {
    var plimit = document.getElementById("plimit");
    hotcrp.foldup.call(this, null, {f: !!plimit && !plimit.checked, n: 8});
    var sopt = $(this).find("option[value=\'" + this.value + "\']");
    hotcrp.foldup.call(this, null, {f: sopt.hasClass("mail-want-no-papers"), n: 9});
    hotcrp.foldup.call(this, null, {f: !sopt.hasClass("mail-want-since"), n: 10});
}
$("#to, #plimit").on("change", mail_recipients_fold);
$(function () { $("#to").trigger("change"); })');

        echo "</td></tr>\n";

        // ** CC, REPLY-TO
        if ($this->viewer->is_manager()) {
            foreach (Mailer::$email_fields as $lcfield => $field)
                if ($lcfield !== "to" && $lcfield !== "bcc") {
                    echo "  <tr><td class=\"",
                        $this->recip->control_class($lcfield, "mhnp nw"),
                        "\"><label for=\"$lcfield\">$field:</label></td><td class=\"mhdp\">",
                        $this->recip->feedback_html_at($lcfield),
                        Ht::entry($lcfield, $this->qreq[$lcfield], ["size" => 64, "class" => $this->recip->control_class($lcfield, "text-monospace js-autosubmit"), "id" => $lcfield, "data-submit-fn" => "false"]),
                        ($lcfield == "reply-to" ? "<hr class=\"g\">" : ""),
                        "</td></tr>\n\n";
                }
        }

        // ** SUBJECT
        echo "  <tr><td class=\"mhnp nw\"><label for=\"subject\">Subject:</label></td><td class=\"mhdp\">",
            "<samp>[", htmlspecialchars($this->conf->short_name), "]&nbsp;</samp>",
            Ht::entry("subject", $this->qreq->subject, ["size" => 64, "class" => $this->recip->control_class("subject", "text-monospace js-autosubmit"), "id" => "subject", "data-submit-fn" => "false"]),
            "</td></tr>

 <tr><td></td><td class=\"mhb\">\n",
            Ht::textarea("body", $this->qreq->body, ["class" => "text-monospace", "rows" => 20, "cols" => 80, "spellcheck" => "true"]),
            "</td></tr>
</table></div>\n\n";

        if ($this->viewer->privChair) {
            $this->print_mail_log();
        }

        echo '<div class="aa c">',
            Ht::submit("Prepare mail", ["class" => "btn-primary"]), ' &nbsp; <span class="hint">You’ll be able to review the mails before they are sent.</span></div>';

        $this->print_keyword_help();
        echo '</form>';
    }

    static function go(Contact $user, Qrequest $qreq) {
        if (isset($qreq->cancel)) {
            $user->conf->redirect_self($qreq);
        } else if (!$user->is_manager() && !$user->isPC) {
            $user->escape();
        }

        $mp = new Mail_Page($user, $qreq);
        $mp->request();

        if (isset($qreq->monreq)) {
            $mp->conf->header("Monitor external reviews", "mail");
        } else {
            $mp->conf->header("Mail", "mail");
        }

        if (!$qreq->recheck
            && !$qreq->again
            && !$mp->recip->has_error()
            && $qreq->valid_token()) {
            if ($qreq->send && $qreq->mailid && $qreq->is_post()) {
                MailSender::send2($user, $mp->recip, $qreq);
            } else if ($qreq->send && $qreq->is_post()) {
                MailSender::send1($user, $mp->recip, $qreq);
            } else if ($qreq->check || $qreq->group || $qreq->ungroup) {
                MailSender::check($user, $mp->recip, $qreq);
            }
        }

        if (isset($qreq->monreq)) {
            $mp->print_review_requests();
        }
        if (!$mp->done) {
            $mp->print_form();
        }

        $mp->conf->footer();
    }
}
