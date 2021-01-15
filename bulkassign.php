<?php
// bulkassign.php -- HotCRP bulk paper assignment page
// Copyright (c) 2006-2020 Eddie Kohler; see LICENSE.

require_once("src/initweb.php");
if (!$Me->is_manager()) {
    $Me->escape();
}
$null_mailer = new HotCRPMailer($Conf, null, [
    "requester_contact" => $Me,
    "other_contact" => $Me /* backwards compat */,
    "reason" => "", "width" => false
]);

$Qreq->rev_round = (string) $Conf->sanitize_round_name($Qreq->rev_round);
if ($Qreq->valid_post()) {
    header("X-Accel-Buffering: no");  // NGINX: do not hold on to file
}


function assignment_defaults($qreq) {
    $defaults = [];
    if (($action = $qreq->default_action) && $action !== "guess") {
        $defaults["action"] = $action;
    }
    $defaults["round"] = $qreq->rev_round;
    if ($qreq->requestreview_notify && $qreq->requestreview_body) {
        $defaults["extrev_notify"] = ["subject" => $qreq->requestreview_subject,
                                      "body" => $qreq->requestreview_body];
    }
    return $defaults;
}

$csv_preparing = false;
$csv_started = 0;
function keep_browser_alive(AssignmentSet $assignset, CsvRow $line = null) {
    global $Conf, $csv_preparing, $csv_started;
    $time = microtime(true);
    if (!$csv_started) {
        $csv_started = $time;
    } else if ($time - $csv_started > 1) {
        if (!$csv_preparing) {
            echo '<div id="foldmail" class="foldc fold2o">',
                '<div class="fn fx2 merror">Preparing assignments.<br><span id="mailcount"></span></div>',
                "</div>";
            $csv_preparing = true;
        }
        $text = '<span class="lineno">' . htmlspecialchars($assignset->landmark()) . ':</span>';
        if (!$line) {
            $text .= " processing";
        } else {
            $text .= " <code>" . htmlspecialchars(join(",", $line->as_list())) . "</code>";
        }
        echo Ht::unstash_script("document.getElementById('mailcount').innerHTML=" . json_encode_browser($text) . ";");
        flush();
        while (@ob_end_flush()) {
        }
    }
}

function finish_browser_alive() {
    global $csv_preparing;
    if ($csv_preparing) {
        echo Ht::unstash_script("hotcrp.fold('mail',null)");
    }
}

function complete_assignment($qreq, $callback) {
    global $Me;
    $SSel = SearchSelection::make($qreq, $Me);
    $assignset = new AssignmentSet($Me, true);
    if ($callback) {
        $assignset->add_progress_handler($callback);
    }
    $assignset->enable_papers($SSel->selection());
    $assignset->parse($qreq->file, $qreq->filename, assignment_defaults($qreq));
    return $assignset->execute(true);
}


// redirect if save cancelled
if (isset($Qreq->saveassignment) && isset($Qreq->cancel)) {
    unset($Qreq->saveassignment);
    $Conf->redirect_self($Qreq); // should not return
}

// perform quick assignments all at once
if (isset($Qreq->saveassignment)
    && $Qreq->valid_post()
    && isset($Qreq->file)
    && $Qreq->assignment_size_estimate < 1000
    && complete_assignment($Qreq, null)) {
    $Conf->redirect_self($Qreq);
}


$Conf->header("Assignments", "bulkassign", ["subtitle" => "Bulk update"]);
echo '<div class="psmode">',
    '<div class="papmode"><a href="', $Conf->hoturl("autoassign"), '">Automatic</a></div>',
    '<div class="papmode"><a href="', $Conf->hoturl("manualassign"), '">Manual</a></div>',
    '<div class="papmode"><a href="', $Conf->hoturl("conflictassign"), '">Conflicts</a></div>',
    '<div class="papmode active"><a href="', $Conf->hoturl("bulkassign"), '">Bulk update</a></div>',
    '</div><hr class="c" />';


// upload review form action
if (isset($Qreq->bulkentry) && trim($Qreq->bulkentry) === "Enter assignments") {
    unset($Qreq->bulkentry);
}
if (isset($Qreq->upload)
    && $Qreq->valid_post()
    && ($Qreq->bulkentry || $Qreq->has_file("bulk"))) {
    flush();
    while (@ob_end_flush()) {
        /* do nothing */
    }
    if ($Qreq->has_file("bulk")) {
        $text = $Qreq->file_contents("bulk");
        $filename = $Qreq->file_filename("bulk");
    } else {
        $text = $Qreq->bulkentry;
        $filename = "";
    }
    if ($text === false) {
        Conf::msg_error("Internal error: cannot read file.");
    } else {
        $assignset = new AssignmentSet($Me, true);
        $assignset->set_flags(AssignmentState::FLAG_CSV_CONTEXT);
        $assignset->add_progress_handler("keep_browser_alive");
        $defaults = assignment_defaults($Qreq);
        $text = convert_to_utf8($text);
        $assignset->parse($text, $filename, $defaults);
        finish_browser_alive();
        if ($assignset->has_error()) {
            $assignset->report_errors();
        } else if ($assignset->is_empty()) {
            $Conf->warnMsg("That assignment file makes no changes.");
        } else {
            $atype = $assignset->type_description();
            echo '<h3>Proposed ', $atype ? $atype . " " : "", 'assignment</h3>';
            $Conf->infoMsg("Select “Apply changes” if this looks OK. (You can always alter the assignment afterwards.)");

            $atypes = $assignset->assigned_types();
            $apids = $assignset->numjoin_assigned_pids(" ");
            echo Ht::form($Conf->hoturl_post("bulkassign", [
                    "saveassignment" => 1,
                    "assigntypes" => join(" ", $atypes),
                    "assignpids" => $apids
                ])),
                Ht::hidden("default_action", $defaults["action"] ?? "guess"),
                Ht::hidden("rev_round", $defaults["round"]),
                Ht::hidden("file", $text),
                Ht::hidden("assignment_size_estimate", max($assignset->assignment_count(), $assignset->request_count())),
                Ht::hidden("filename", $filename),
                Ht::hidden("requestreview_notify", $Qreq->requestreview_notify),
                Ht::hidden("requestreview_subject", $Qreq->requestreview_subject),
                Ht::hidden("requestreview_body", $Qreq->requestreview_body),
                Ht::hidden("bulkentry", $Qreq->bulkentry),

            $assignset->report_errors();
            $assignset->echo_unparse_display();

            echo Ht::actions([
                Ht::submit("Apply changes", ["class" => "btn-success"]),
                Ht::submit("cancel", "Cancel")
            ], ["class" => "aab aabig"]),
                "</form>\n";
            $Conf->footer();
            exit;
        }
    }
}

if (isset($Qreq->saveassignment)
    && $Qreq->valid_post()
    && isset($Qreq->file)
    && $Qreq->assignment_size_estimate >= 1000) {
    complete_assignment($Qreq, "keep_browser_alive");
    finish_browser_alive();
}


// Help list
echo '<div class="helpside"><div class="helpinside">
Assignment methods:
<ul><li><a href="', hoturl("autoassign"), '">Automatic</a></li>
 <li><a href="', hoturl("manualassign"), '">Manual by PC member</a></li>
 <li><a href="', hoturl("assign"), '">Manual by paper</a></li>
 <li><a href="', hoturl("conflictassign"), '">Potential conflicts</a></li>
 <li><a href="', hoturl("bulkassign"), '" class="q"><strong>Bulk update</strong></a></li>
</ul>
<hr class="hr">
<p>Types of PC review:</p>
<dl><dt>', review_type_icon(REVIEW_PRIMARY), ' Primary</dt><dd>Mandatory review</dd>
  <dt>', review_type_icon(REVIEW_SECONDARY), ' Secondary</dt><dd>May be delegated to external reviewers</dd>
  <dt>', review_type_icon(REVIEW_PC), ' Optional</dt><dd>May be declined</dd>
  <dt>', review_type_icon(REVIEW_META), ' Metareview</dt><dd>Can view all other reviews before completing their own</dd></dl>
</div></div>';


echo Ht::form($Conf->hoturl_post("bulkassign", "upload=1"));

// Upload
echo '<div class="lg"><div class="f-i" style="margin-top:1em">',
    Ht::textarea("bulkentry", (string) $Qreq->bulkentry,
                 ["rows" => 1, "cols" => 80, "placeholder" => "Enter assignments", "class" => "need-autogrow", "spellcheck" => "false"]),
    '</div>';

echo '<div class="g"><strong>OR</strong> &nbsp;',
    '<input type="file" name="bulk" accept="text/plain,text/csv" size="30"></div></div>';

echo '<div id="foldoptions" class="lg foldc fold2c fold3c"><label>',
    'Default action:&nbsp; ',
    Ht::select("default_action", array("guess" => "guess from input",
                                       "primary" => "assign primary reviews",
                                       "secondary" => "assign secondary reviews",
                                       "pcreview" => "assign optional PC reviews",
                                       "metareview" => "assign metareviews",
                                       "review" => "assign external reviews",
                                       "conflict" => "assign PC conflicts",
                                       "lead" => "assign discussion leads",
                                       "shepherd" => "assign shepherds",
                                       "settag" => "set tags",
                                       "preference" => "set reviewer preferences"),
               $Qreq->get("default_action", "guess"),
               ["id" => "tsel"]),
    '</label>';
Ht::stash_script('$(function(){
$("#tsel").on("change",function(){
hotcrp.foldup.call(this,null,{f:this.value!=="review"});
hotcrp.foldup.call(this,null,{f:!/^(?:primary|secondary|(?:pc|meta)?review)$/.test(this.value),n:2});
}).trigger("change")})');
$rev_rounds = $Conf->round_selector_options(null);
$expected_round = $Qreq->rev_round ? : $Conf->assignment_round_option(false);
if (count($rev_rounds) > 1)
    echo '<span class="fx2">&nbsp; in round &nbsp;',
        Ht::select("rev_round", $rev_rounds, $expected_round),
        '</span>';
else if ($expected_round !== "unnamed")
    echo '<span class="fx2">&nbsp; in round ', $expected_round, '</span>';
echo '<div class="g"></div>', "\n";

if (($requestreview_template = $null_mailer->expand_template("requestreview"))) {
    echo Ht::hidden("requestreview_subject", $requestreview_template["subject"]);
    if (isset($Qreq->requestreview_body))
        $t = $Qreq->requestreview_body;
    else
        $t = $requestreview_template["body"];
    echo "<table class=\"fx\"><tr><td>",
        Ht::checkbox("requestreview_notify", 1, true),
        "&nbsp;</td><td>", Ht::label("Send email to external reviewers:"), "</td></tr>
    <tr><td></td><td>",
        Ht::textarea("requestreview_body", $t, array("cols" => 80, "rows" => 20, "spellcheck" => "true", "class" => "text-monospace need-autogrow")),
        "</td></tr></table>\n";
}

echo '<div class="lg"></div>', Ht::submit("Prepare assignments", ["class" => "btn-primary"]),
    " &nbsp; <span class=\"hint\">You’ll be able to check the assignments before they are saved.</span></div>\n";

echo '<div style="margin-top:1.5em"><a href="', $Conf->hoturl_post("search", "fn=get&amp;getfn=pcassignments&amp;t=manager&amp;q=&amp;p=all"), '">Download current PC review assignments</a></div>';

echo "</form>

<section class=\"mt-7\">
<h3><a class=\"x\" href=\"", $Conf->hoturl("help", ["t" => "bulkassign"]), "\">Instructions</a></h3>

<p class=\"w-text\">Upload a CSV (comma-separated value file) to prepare an assignment; HotCRP
will display the consequences of the requested assignment for confirmation and
approval. The <code>action</code> field determines the assignment to be
performed. Supported actions include:</p>";

BulkAssign_HelpTopic::echo_actions($Me);

echo "<p class=\"w-text\">For example, this file clears existing R1 review assignments for papers
tagged #redo, then assigns two primary reviews for submission #1 and one
secondary review for submission #2:</p>

<pre class=\"entryexample\">paper,action,email,round
#redo,clearreview,all,R1
1,primary,man@alice.org
2,secondary,slugger@manny.com
1,primary,slugger@manny.com</pre>

<p class=\"w-text\"><a href=\"", $Conf->hoturl("help", ["t" => "bulkassign"]), "\"><strong>Detailed instructions</strong></a></p>
</section>\n";

Ht::stash_script('$("#tsel").trigger("change")');
$Conf->footer();
