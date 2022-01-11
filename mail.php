<?php
// mail.php -- HotCRP mail tool
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

require_once("src/init.php");
$Qreq || initialize_request();
if (!$Me->is_manager() && !$Me->isPC) {
    $Me->escape();
}
if (isset($Qreq->recipients) && !isset($Qreq->to)) {
    $Qreq->to = $Qreq->recipients;
}
if (isset($Qreq->loadtmpl)
    || isset($Qreq->psearch)
    || (isset($Qreq->default) && $Qreq->defaultfn === "recheck")) {
    $Qreq->recheck = 1;
}

// load mail from log
if (isset($Qreq->mailid)
    && ctype_digit($Qreq->mailid)
    && $Me->privChair
    && !$Qreq->send) {
    $result = $Conf->qe_raw("select * from MailLog where mailId=" . $Qreq->mailid);
    if (($row = $result->fetch_object())) {
        foreach (["recipients", "q", "t", "cc", "subject"] as $field) {
            if (isset($row->$field) && !isset($Qreq[$field]))
                $Qreq[$field] = $row->$field;
        }
        if (isset($row->replyto) && !isset($Qreq["reply-to"])) {
            $Qreq["reply-to"] = $row->replyto;
        }
        if (isset($row->emailBody) && !isset($Qreq->body)) {
            $Qreq->body = $row->emailBody;
        }
        if (isset($row->recipients) && !isset($Qreq->to)) {
            $Qreq->to = $row->recipients;
        }
        if ($row->q) {
            $Qreq["plimit"] = 1;
        }
    }
}

// create options
$tOpt = [];
if ($Me->privChair) {
    $tOpt["s"] = "Submitted papers";
    if ($Conf->time_pc_view_decision(false) && $Conf->has_any_accepted()) {
        $tOpt["acc"] = "Accepted papers";
    }
    $tOpt["unsub"] = "Unsubmitted papers";
    $tOpt["all"] = "All papers";
}
if ($Me->privChair ? $Conf->has_any_manager() : $Me->is_manager()) {
    $tOpt["admin"] = "Papers you administer";
}
$tOpt["req"] = "Your review requests";
if (!isset($Qreq->t) || !isset($tOpt[$Qreq->t])) {
    $Qreq->t = key($tOpt);
}

// mailer options
if (isset($Qreq->cc) && $Me->is_manager()) {
    // XXX should only apply to papers you administer
    $Qreq->cc = simplify_whitespace($Qreq->cc);
} else {
    $Qreq->cc = $Conf->opt("emailCc") ?? "";
}

if (isset($Qreq["reply-to"]) && $Me->is_manager()) {
    // XXX should only apply to papers you administer
    $Qreq["reply-to"] = simplify_whitespace($Qreq["reply-to"]);
} else {
    $Qreq["reply-to"] = $Conf->opt("emailReplyTo") ?? "";
}

global $mailer_options;
$mailer_options = ["requester_contact" => $Me, "cc" => $Qreq->cc, "reply-to" => $Qreq["reply-to"]];
$null_mailer = new HotCRPMailer($Conf, null, array_merge(["width" => false], $mailer_options));

// template options
if (isset($Qreq->monreq)) {
    $Qreq->template = "myreviewremind";
}
if (isset($Qreq->template) && !isset($Qreq->check) && !isset($Qreq->default)) {
    $Qreq->loadtmpl = -1;
}

// paper selection
if (!isset($Qreq->q) || trim($Qreq->q) == "(All)") {
    $Qreq->q = "";
}
if (isset($Qreq->p) && !$Qreq->has_a("p")) {
    $Qreq->set_a("p", preg_split('/\s+/', $Qreq->p));
} else if (!isset($Qreq->p) && isset($Qreq->pap)) {
    $Qreq->set_a("p", $Qreq->has_a("pap") ? $Qreq->get_a("pap") : preg_split('/\s+/', $Qreq->pap));
}
// It's OK to just set $Qreq->p from the input without
// validation because MailRecipients filters internally
if (isset($Qreq->prevt) && isset($Qreq->prevq)) {
    if (!isset($Qreq->plimit)) {
        unset($Qreq->p);
    } else if (($Qreq->prevt !== $Qreq->t || $Qreq->prevq !== $Qreq->q)
               && !isset($Qreq->recheck)) {
        $Conf->warnMsg("You changed the paper search. Please review the paper list.");
        $Qreq->psearch = true;
    }
}
$papersel = null;
if ($Qreq->has_a("p") && !isset($Qreq->recheck)) {
    $papersel = [];
    foreach ($Qreq->get_a("p") as $p) {
        if (($p = cvtint($p)) > 0)
            $papersel[] = $p;
    }
    sort($papersel);
    $Qreq->q = join(" ", $papersel);
    $Qreq->plimit = 1;
} else if (isset($Qreq->plimit)) {
    $search = new PaperSearch($Me, ["t" => $Qreq->t, "q" => $Qreq->q]);
    $papersel = $search->paper_ids();
    sort($papersel);
} else {
    $Qreq->q = "";
}

// Load template if requested
if (isset($Qreq->loadtmpl)) {
    $t = $Qreq->template ?? "generic";
    $template = (array) $Conf->mail_template($t);
    if (((!isset($template["title"]) || $template["title"] === false)
         && !isset($template["allow_template"]))
        || (isset($template["allow_template"]) && $template["allow_template"] === false)) {
        $template = (array) $Conf->mail_template("generic");
    }
    if (!isset($Qreq->to) || $Qreq->loadtmpl != -1) {
        $Qreq->to = $template["default_recipients"] ?? "s";
    }
    if (isset($template["default_search_type"])) {
        $Qreq->t = $template["default_search_type"];
    }
    $Qreq->subject = $null_mailer->expand($template["subject"]);
    $Qreq->body = $null_mailer->expand($template["body"]);
}

// Clean subject and body
if (!isset($Qreq->subject)) {
    $t = $Conf->mail_template("generic");
    $Qreq->subject = $null_mailer->expand($t->subject, "subject");
}
$Qreq->subject = trim($Qreq->subject);
if (str_starts_with($Qreq->subject, "[{$Conf->short_name}] ")) {
    $Qreq->subject = substr($Qreq->subject, strlen($Conf->short_name) + 3);
}
if (!isset($Qreq->body)) {
    $t = $Conf->mail_template("generic");
    $Qreq->body = $null_mailer->expand($t->body, "body");
}


// Set recipients list, now that template is loaded
$recip = new MailRecipients($Me);
$recip->set_newrev_since($Qreq->newrev_since);
$recip->set_paper_ids($papersel);
$recip->set_recipients($Qreq->to);

// warn if no papers match
if (isset($papersel)
    && empty($papersel)
    && !isset($Qreq->recheck)
    && $recip->need_papers()) {
    Conf::msg_error("No papers match that search.");
    unset($papersel);
    unset($Qreq->check, $Qreq->send);
}


// Header
if (isset($Qreq->monreq)) {
    $Conf->header("Monitor external reviews", "mail");
} else {
    $Conf->header("Mail", "mail");
}


// Check or send
if (!$Qreq->recheck
    && !$Qreq->cancel
    && !$Qreq->again
    && !$recip->has_error()
    && $Qreq->valid_token()) {
    if ($Qreq->send && $Qreq->mailid && $Qreq->is_post()) {
        MailSender::send2($Me, $recip, $Qreq);
    } else if ($Qreq->send && $Qreq->is_post()) {
        MailSender::send1($Me, $recip, $Qreq);
    } else if ($Qreq->check || $Qreq->group || $Qreq->ungroup) {
        MailSender::check($Me, $recip, $Qreq);
    }
}


if (isset($Qreq->monreq)) {
    $plist = new PaperList("reqrevs", new PaperSearch($Me, ["t" => "req", "q" => ""]));
    $plist->set_table_id_class("foldpl", "pltable-fullw");
    if ($plist->is_empty()) {
        $Conf->infoMsg('You have not requested any external reviews.  <a href="' . $Conf->hoturl("index") . '">Return home</a>');
    } else {
        echo "<h2>Requested reviews</h2>\n\n";
        $plist->set_table_decor(PaperList::DECOR_HEADER | PaperList::DECOR_LIST);
        $plist->echo_table_html();
        echo '<div class="info">';
        if ($plist->has("need_review")) {
            echo "Some of your requested external reviewers have not completed their reviews.  To send them an email reminder, check the text below and then select &ldquo;Prepare mail.&rdquo;  You’ll get a chance to review the emails and select specific reviewers to remind.";
        } else {
            echo 'All of your requested external reviewers have completed their reviews.  <a href="', $Conf->hoturl("index"), '">Return home</a>';
        }
        echo "</div>\n";
    }
    if (!$plist->has("need_review")) {
        $Conf->footer();
        exit;
    }
}

echo Ht::form($Conf->hoturl("=mail", "check=1")),
    Ht::hidden("defaultfn", ""),
    Ht::hidden_default_submit("default", 1), '

<div class="aa" style="padding-left:8px">
  <strong>Template:</strong> &nbsp;';
$tmpl = $tmploptions = [];
foreach (array_keys($Conf->mail_template_map()) as $tname) {
    if (($template = $Conf->mail_template($tname))
        && (isset($template->title) && $template->title !== false)
        && (!isset($template->allow_template) || $template->allow_template)
        && ($Me->privChair || ($template->allow_pc ?? false)))
        $tmpl[] = $template;
}
usort($tmpl, "Conf::xt_order_compare");
foreach ($tmpl as $t) {
    $tmploptions[$t->name] = $t->title;
}
if (!isset($Qreq->template) || !isset($tmploptions[$Qreq->template])) {
    $Qreq->template = "generic";
}
echo Ht::select("template", $tmploptions, $Qreq->template, ["class" => "uich js-mail-populate-template"]),
    ' &nbsp;
 <span class="hint">Templates are mail texts tailored for common conference tasks.</span>
</div>

<div class="mail" style="float:left;margin:4px 1em 12px 0"><table id="foldpsel" class="fold8c fold9o fold10c">', "\n";

// ** TO
echo '<tr><td class="mhnp nw"><label for="to">To:</label></td><td class="mhdd">',
    $recip->selectors(),
    "<div class=\"g\"></div>\n";

// paper selection
echo '<table class="fx9"><tr>';
if ($Me->privChair) {
    echo '<td class="nw">',
        Ht::checkbox("plimit", 1, isset($Qreq->plimit), ["id" => "plimit"]),
        "&nbsp;</td><td>", Ht::label("Choose papers", "plimit"),
        "<span class=\"fx8\">:&nbsp; ";
} else {
    echo '<td class="nw">Papers: &nbsp;</td><td>',
        Ht::hidden("plimit", 1), '<span>';
}
echo Ht::entry("q", (string) $Qreq->q, [
        "id" => "q", "placeholder" => "(All)", "spellcheck" => false,
        "class" => "papersearch need-suggest js-autosubmit", "size" => 36,
        "data-submit-fn" => "recheck"
    ]), " &nbsp;in&nbsp;";
if (count($tOpt) == 1) {
    echo htmlspecialchars($tOpt[$Qreq->t]);
} else {
    echo " ", Ht::select("t", $tOpt, $Qreq->t, ["id" => "t"]);
}
echo " &nbsp;", Ht::submit("psearch", "Search");
echo "</span>";
if (isset($Qreq->plimit)
    && !isset($Qreq->monreq)
    && isset($Qreq->recheck)) {
    $plist = new PaperList("reviewers", new PaperSearch($Me, ["t" => $Qreq->t, "q" => $Qreq->q]));
    echo "<div class=\"fx8";
    if ($plist->is_empty()) {
        echo "\">No papers match that search.";
    } else {
        echo " g\">";
        $plist->echo_table_html();
    }
    echo '</div>', Ht::hidden("prevt", $Qreq->t),
        Ht::hidden("prevq", $Qreq->q);
}
echo "</td></tr></table>\n";

echo '<div class="fx10" style="margin-top:0.35em">';
if (!$Qreq->newrev_since && ($t = $Conf->setting("pcrev_informtime"))) {
    $Qreq->newrev_since = $Conf->parseableTime($t, true);
}
echo 'Assignments since:&nbsp; ',
    Ht::entry("newrev_since", $Qreq->newrev_since,
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
if ($Me->is_manager()) {
    foreach (Mailer::$email_fields as $lcfield => $field)
        if ($lcfield !== "to" && $lcfield !== "bcc") {
            echo "  <tr><td class=\"",
                $recip->control_class($lcfield, "mhnp nw"),
                "\"><label for=\"$lcfield\">$field:</label></td><td class=\"mhdp\">",
                $recip->feedback_html_at($lcfield),
                Ht::entry($lcfield, $Qreq[$lcfield], ["size" => 64, "class" => $recip->control_class($lcfield, "text-monospace js-autosubmit"), "id" => $lcfield, "data-submit-fn" => "false"]),
                ($lcfield == "reply-to" ? "<hr class=\"g\">" : ""),
                "</td></tr>\n\n";
        }
}

// ** SUBJECT
echo "  <tr><td class=\"mhnp nw\"><label for=\"subject\">Subject:</label></td><td class=\"mhdp\">",
    "<samp>[", htmlspecialchars($Conf->short_name), "]&nbsp;</samp>",
    Ht::entry("subject", $Qreq->subject, ["size" => 64, "class" => $recip->control_class("subject", "text-monospace js-autosubmit"), "id" => "subject", "data-submit-fn" => "false"]),
    "</td></tr>

 <tr><td></td><td class=\"mhb\">\n",
    Ht::textarea("body", $Qreq->body,
            ["class" => "text-monospace", "rows" => 20, "cols" => 80, "spellcheck" => "true"]),
    "</td></tr>
</table></div>\n\n";


if ($Me->privChair) {
    $result = $Conf->qe_raw("select mailId, subject, emailBody from MailLog where fromNonChair=0 and status>=0 order by mailId desc limit 200");
    if ($result->num_rows) {
        echo '<div style="padding-top:12px;max-height:24em;overflow-y:auto">',
            "<strong>Recent mails:</strong>\n";
        $i = 1;
        while (($row = $result->fetch_object())) {
            echo '<div class="mhdd"><div style="position:relative;overflow:hidden">',
                '<div style="position:absolute;white-space:nowrap"><span style="min-width:2em;text-align:right;display:inline-block" class="dim">', $i, '.</span> <a class="q" href="', $Conf->hoturl("mail", "mailid=" . $row->mailId), '">', htmlspecialchars($row->subject), ' &ndash; <span class="dim">', htmlspecialchars(UnicodeHelper::utf8_prefix($row->emailBody, 100)), "</span></a></div>",
                "<br></div></div>\n";
            ++$i;
        }
        echo "</div>\n\n";
    }
}


echo '<div class="aa c">',
    Ht::submit("Prepare mail", ["class" => "btn-primary"]), ' &nbsp; <span class="hint">You’ll be able to review the mails before they are sent.</span>
</div>
';

function echo_mail_keyword_help() {
    global $Conf;
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

    $opts = array_filter($Conf->options()->normal(), function ($o) {
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
</div></div>
';
}


echo_mail_keyword_help();
echo '</form>';
$Conf->footer();
