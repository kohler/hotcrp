<?php
// pages/p_mail.php -- HotCRP mail tool
// Copyright (c) 2006-2023 Eddie Kohler; see LICENSE.

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
            if ($this->conf->has_any_accepted()) {
                $this->search_topt["accepted"] = PaperSearch::$search_type_names["accepted"];
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
            || (isset($qreq->default) && $qreq->defaultfn === "psearch")) {
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
            $row = $this->conf->fetch_first_object("select * from MailLog where mailId=?", $qreq->mailid);
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
                    $qreq->plimit = 1;
                }
            }
        }

        // paper selection
        if (!isset($qreq->q) || strcasecmp(trim($qreq->q), "(All)") === 0) {
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
        $papersel = null;
        if ($qreq->has_plimit || $qreq->plimit) {
            if ($qreq->plimit) {
                $search = new PaperSearch($this->viewer, ["t" => $qreq->t, "q" => $qreq->q]);
                $papersel = $search->paper_ids();
                sort($papersel);
            }
        } else if ($qreq->has_a("p") && !isset($qreq->recheck)) {
            $papersel = [];
            foreach ($qreq->get_a("p") as $p) {
                if (($p = stoi($p) ?? -1) > 0)
                    $papersel[] = $p;
            }
            sort($papersel);
            $qreq->q = join(" ", $papersel);
            $qreq->plimit = 1;
        } else {
            $qreq->q = "";
            $papersel = null;
        }

        // template
        if (isset($qreq->template)
            && !isset($qreq->check)
            && !isset($qreq->send)
            && !isset($qreq->default)) {
            MailSender::load_template($qreq, $qreq->template, false);
        }
        MailSender::clean_request($qreq);

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
            $this->recip->error_at("q", "<0>No papers match that search");
            $this->recip->set_paper_ids(null);
            unset($this->qreq->check, $this->qreq->send);
        }
    }

    function print_review_requests() {
        $plist = new PaperList("reqrevs", new PaperSearch($this->viewer, ["t" => "req", "q" => ""]));
        $plist->set_table_id_class("foldpl", "fullw");
        $plist->set_view("sel", false, PaperList::VIEWORIGIN_MAX);
        if ($plist->is_empty()) {
            $this->conf->warning_msg("<5>You have not requested any external reviews. " . Ht::link("Return home", $this->conf->hoturl("index")));
        } else {
            echo "<h2>Requested reviews</h2>\n\n";
            $plist->set_table_decor(PaperList::DECOR_HEADER | PaperList::DECOR_LIST);
            $plist->print_table_html();
            echo '<div class="msg msg-info"><p>';
            if ($plist->has("need_review")) {
                echo "Some of your requested external reviewers have not completed their reviews. To send them an email reminder, check the text below and then select “Prepare mail.” You’ll get a chance to review the emails and select specific reviewers to remind.";
            } else {
                echo 'All of your requested external reviewers have completed their reviews. ', Ht::link("Return home", $this->conf->hoturl("index"));
            }
            echo "</p></div>\n";
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
                && $o->on_render_context(FieldRender::CFMAIL);
        });
        usort($opts, function ($a, $b) {
            if ($a->is_final() !== $b->is_final()) {
                return $a->is_final() ? 1 : -1;
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
        echo '<div class="mail-field mb-2">';
        if (isset($this->qreq->template)
            && ($tmpl = $this->conf->mail_template($this->qreq->template))) {
            echo '<label for="template-changer">Template:</label>',
                '<div class="mr-3">', htmlspecialchars($tmpl->title), '</div>',
                '<div>', Ht::hidden("template", $tmpl->name),
                    Ht::button("Change template", ["id" => "template-changer", "class" => "ui js-mail-set-template"]), '</div>';
        } else {
            echo '<div class="mr-2">', Ht::button("Load template", ["class" => "ui js-mail-set-template"]), '</div>',
                '<div class="small">Templates are mail texts tailored for common conference tasks.</div>';
        }
        echo '</div>';
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

    private function print_paper_selection() {
        if ($this->viewer->privChair) {
            echo '<div class="fx9 checki mt-1"><span class="checkc">',
                Ht::hidden("has_plimit", 1),
                Ht::checkbox("plimit", 1, !!$this->qreq->plimit, ["id" => "plimit", "class" => "uich js-mail-recipients"]),
                '</span>',
                '<label for="plimit">Choose papers<span class="fx8">:</span></label>';
        } else {
            echo '<div class="fx9">',
                Ht::hidden("has_plimit", 1),
                Ht::checkbox("plimit", 1, true, ["id" => "plimit", "class" => "hidden"]);
        }
        $plist = null;
        if ($this->qreq->recheck && $this->qreq->plimit) {
            $plist = new PaperList($this->qreq->t === "req" ? "reqrevs" : "reviewers",
                new PaperSearch($this->viewer, ["t" => $this->qreq->t, "q" => $this->qreq->q]));
            foreach ($plist->search->message_list() as $mi) {
                $this->recip->append_item_at("q", $mi);
            }
            if ($plist->is_empty()) {
                $this->recip->warning_at("q", "<0>No papers match that search.");
            }
        }
        echo '<div class="', $this->recip->control_class("q", "fx8 mt-1 d-flex"), '">';
        if (!$this->viewer->privChair) {
            echo '<label for="q" class="mr-2">Papers:</label>';
        }
        echo Ht::entry("q", (string) $this->qreq->q, [
                "placeholder" => "(All)", "spellcheck" => false,
                "class" => "papersearch need-suggest js-autosubmit",
                "size" => $this->viewer->privChair ? 36 : 32,
                "data-submit-fn" => "psearch"
            ]), '<div class="form-basic-search-in"> in ';
        if (count($this->search_topt) === 1) {
            echo htmlspecialchars($this->search_topt[$this->qreq->t]);
        } else {
            echo Ht::select("t", $this->search_topt, $this->qreq->t, ["id" => "t"]);
        }
        echo Ht::submit("psearch", "Search"), '</div></div>',
            $this->recip->feedback_html_at("q");
        if ($plist && !$plist->is_empty()) {
            echo '<div class="fx8 mt-2">';
            $plist->print_table_html();
            echo Ht::hidden("prevt", $this->qreq->t),
                Ht::hidden("prevq", $this->qreq->q),
                '</div>';
        }
        echo "</div>"; // <div class="fx9...
    }

    private function print_new_assignments_since() {
        if (!$this->qreq->newrev_since
            && ($t = $this->conf->setting("pcrev_informtime"))) {
            $this->qreq->newrev_since = $this->conf->parseableTime($t, true);
        }
        echo '<div class="', $this->recip->control_class("newrev_since", "mt-2 fx10"), '">',
            '<label>Assignment cutoff: ',
            Ht::entry("newrev_since", $this->qreq->newrev_since, [
                "placeholder" => "(All)", "size" => 30,
                "class" => "js-autosubmit ml-2", "data-submit-fn" => "psearch"
            ]), '</label>',
            $this->recip->feedback_html_at("newrev_since"),
            '</div>';
    }

    function print_form() {
        // default messages
        $nullm = MailSender::null_mailer($this->viewer);
        $defprefix = "[{$this->conf->short_name}] ";
        $templates = [];
        foreach ($this->recip->default_messages() as $dm) {
            if (($template = $this->conf->mail_template($dm))) {
                $s = $nullm->expand($template->subject, "subject");
                if (str_starts_with($s, $defprefix)) {
                    $s = substr($s, strlen($defprefix));
                }
                $b = $nullm->expand($template->body, "body");
                $templates[$dm] = ["subject" => $s, "body" => $b];
            }
        }
        $deftemplate = $templates[$this->recip->current_default_message()] ?? null;

        // form
        echo Ht::form($this->conf->hoturl("=mail", ["check" => 1, "monreq" => $this->qreq->monreq]), [
                "id" => "mailform",
                "data-default-messages" => json_encode_browser((object) $templates)
            ]),
            Ht::hidden("defaultfn", ""),
            Ht::hidden_default_submit("default", 1);

        $this->print_template();

        echo '<fieldset class="mail-editor main-width ',
            $this->recip->current_fold_classes($this->qreq),
            '" style="float:left;margin:4px 1em 1em 0" id="foldpsel">';

        // ** TO
        echo '<div class="mail-field mb-3">',
            '<label for="to">To:</label>',
            '<div class="flex-fill-0">',
            $this->recip->recipient_selector_html("to");
        if ($this->viewer->is_manager()) {
            $this->print_new_assignments_since();
        }
        $this->print_paper_selection();
        //Ht::stash_script('$(function(){$(".js-mail-recipients").first().change()})');
        echo "</div></div>\n";

        // ** CC, REPLY-TO
        if ($this->viewer->is_manager()) {
            foreach (Mailer::$email_fields as $lcfield => $field) {
                if ($lcfield !== "to" && $lcfield !== "bcc") {
                    echo '<div class="', $this->recip->control_class($lcfield, "mail-field small"), '">',
                        "<label class=\"position-ta-adjust\" for=\"{$lcfield}\">{$field}:</label>",
                        '<div class="flex-fill-0">',
                        $this->recip->feedback_html_at($lcfield),
                        Ht::textarea($lcfield, $this->qreq[$lcfield], [
                            "id" => $lcfield, "rows" => 1, "data-submit-fn" => "false",
                            "class" => $this->recip->control_class($lcfield, "js-autosubmit need-autogrow w-100")
                        ]), "</div></div>\n";
                }
            }
        }

        // ** SUBJECT
        echo '<div class="mail-field mt-3 mb-3">',
            '<label class="position-ta-adjust" for="subject">Subject:</label>',
            '<div class="position-ta-adjust pr-2">[', htmlspecialchars($this->conf->short_name), ']</div>',
            '<div class="flex-fill-0">',
            $this->recip->feedback_html_at("subject"),
            Ht::textarea("subject", $this->qreq->subject, [
                "id" => "subject", "rows" => 1, "data-submit-fn" => "false",
                "class" => $this->recip->control_class("subject", "js-autosubmit need-autogrow w-100"),
                "spellcheck" => true,
                "data-default-value" => $deftemplate["subject"] ?? ""
            ]), "</div></div>\n";

        // ** BODY
        echo Ht::textarea("body", $this->qreq->body, [
            "id" => "email-body", "rows" => 12, "cols" => 70,
            "class" => "w-100 need-autogrow",
            "spellcheck" => "true",
            "data-default-value" => $deftemplate["body"] ?? ""
        ]);

        echo "</fieldset>\n\n";

        if ($this->viewer->privChair) {
            $this->print_mail_log();
        }

        echo '<div class="aab aabig c mb-5">',
            '<div class="aabut">', Ht::submit("Prepare mail", ["class" => "btn-primary"]), '</div>',
            '<div class="aabut"><span class="hint">You’ll be able to review the mails before they are sent.</span></div>',
            '</div>';

        $this->print_keyword_help();
        echo '</form>';
    }

    static function go(Contact $user, Qrequest $qreq) {
        if (isset($qreq->cancel)) {
            $user->conf->redirect_self($qreq, $qreq->subset_as_array("monreq", "to", "has_plimit", "plimit", "q", "t", "cc", "reply-to", "subject", "body", "template"));
        }
        if (!$user->is_manager() && !$user->isPC) {
            $user->escape();
        }

        $mp = new Mail_Page($user, $qreq);
        $mp->request();

        if (isset($qreq->monreq)) {
            $qreq->print_header("Monitor external reviews", "mail");
        } else {
            $qreq->print_header("Mail", "mail");
        }

        if (!$qreq->recheck
            && !$qreq->again
            && !$mp->recip->has_error()
            && $qreq->valid_token()) {
            if ($qreq->send && $qreq->mailid && $qreq->is_post()) {
                MailSender::send2($mp->recip, $qreq);
            } else if ($qreq->send && $qreq->is_post()) {
                MailSender::send1($mp->recip, $qreq);
            } else if ($qreq->check || $qreq->group || $qreq->ungroup) {
                MailSender::check($mp->recip, $qreq);
            }
        }

        if (isset($qreq->monreq)) {
            $mp->print_review_requests();
        }
        if (!$mp->done) {
            $mp->print_form();
        }

        $qreq->print_footer();
    }
}
