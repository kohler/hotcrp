<?php
// pages/p_bulkassign.php -- HotCRP bulk paper assignment page
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class BulkAssign_Page {
    /** @var Conf */
    public $conf;
    /** @var Contact */
    public $user;
    /** @var Qrequest */
    public $qreq;
    /** @var AssignmentSet */
    private $aset;
    /** @var bool */
    private $csv_preparing = false;
    /** @var float */
    private $csv_started = 0.0;

    function __construct(Contact $user, Qrequest $qreq) {
        $this->conf = $user->conf;
        $this->user = $user;
        $this->qreq = $qreq;
    }

    private function assignment_defaults() {
        $defaults = [];
        if (($action = $this->qreq->default_action) && $action !== "guess") {
            $defaults["action"] = $action;
        }
        $defaults["round"] = $this->qreq->rev_round;
        if ($this->qreq->requestreview_notify && $this->qreq->requestreview_body) {
            $defaults["extrev_notify"] = [
                "subject" => $this->qreq->requestreview_subject,
                "body" => $this->qreq->requestreview_body
            ];
        }
        return $defaults;
    }

    function keep_browser_alive(AssignmentSet $aset, CsvRow $line = null) {
        $time = microtime(true);
        if (!$this->csv_started) {
            $this->csv_started = $time;
        } else if ($time - $this->csv_started > 1) {
            if (!$this->csv_preparing) {
                echo '<div id="foldmail" class="foldc fold2o">',
                    '<div class="fn fx2 merror">Preparing assignments.<br><span id="mailcount"></span></div>',
                    "</div>";
                $this->csv_preparing = true;
            }
            $text = '<span class="lineno">' . htmlspecialchars($aset->landmark()) . ':</span>';
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
        if ($this->csv_preparing) {
            echo Ht::unstash_script("hotcrp.fold('mail',null)");
        }
    }

    function complete_assignment($callback) {
        $ssel = SearchSelection::make($this->qreq, $this->user);
        $aset = new AssignmentSet($this->user, true);
        if ($callback) {
            $aset->add_progress_handler($callback);
        }
        $aset->enable_papers($ssel->selection());
        $aset->parse($this->qreq->data, $this->qreq->filename, $this->assignment_defaults());
        return $aset->execute(true);
    }

    /** @return bool */
    private function handle_upload() {
        flush();
        while (@ob_end_flush()) {
        }

        if ($this->qreq->has_file("file")) {
            $text = $this->qreq->file_contents("file");
            $filename = $this->qreq->file_filename("file");
        } else {
            $text = $this->qreq->data;
            $filename = "";
        }
        if ($text === false) {
            $this->conf->error_msg("<0>Internal error: could not read uploaded file");
            return false;
        }

        $aset = new AssignmentSet($this->user, true);
        $aset->set_flags(AssignmentState::FLAG_CSV_CONTEXT);
        $aset->add_progress_handler([$this, "keep_browser_alive"]);
        $defaults = $this->assignment_defaults();
        $text = convert_to_utf8($text);
        $aset->parse($text, $filename, $defaults);
        $this->finish_browser_alive();

        if ($aset->has_error() || $aset->is_empty()) {
            $aset->report_errors();
            return false;
        }

        $atype = $aset->type_description();
        echo '<h3>Proposed ', $atype ? $atype . " " : "", 'assignment</h3>';
        $this->conf->feedback_msg(
            new MessageItem(null, "Select “Apply changes” to make the checked assignments.", MessageSet::MARKED_NOTE)
        );

        $atypes = $aset->assigned_types();
        $apids = $aset->numjoin_assigned_pids(" ");
        echo Ht::form($this->conf->hoturl("=bulkassign", [
                "saveassignment" => 1,
                "assigntypes" => join(" ", $atypes),
                "assignpids" => $apids
            ])),
            Ht::hidden("default_action", $defaults["action"] ?? "guess"),
            Ht::hidden("rev_round", $defaults["round"]),
            Ht::hidden("data", $text),
            Ht::hidden("filename", $filename),
            Ht::hidden("assignment_size_estimate", max($aset->assignment_count(), $aset->request_count())),
            Ht::hidden("requestreview_notify", $this->qreq->requestreview_notify),
            Ht::hidden("requestreview_subject", $this->qreq->requestreview_subject),
            Ht::hidden("requestreview_body", $this->qreq->requestreview_body);

        $aset->report_errors();
        $aset->print_unparse_display();

        echo Ht::actions([
            Ht::submit("Apply changes", ["class" => "btn-success"]),
            Ht::submit("cancel", "Cancel")
        ], ["class" => "aab aabig"]),
            "</form>\n";
        $this->conf->footer();
        return true;
    }

    function print_instructions() {
        echo "<section class=\"mt-7\">
<h3><a class=\"ulh\" href=\"", $this->conf->hoturl("help", ["t" => "bulkassign"]), "\">Instructions</a></h3>

<p class=\"w-text\">Upload a CSV (comma-separated value file) to prepare an assignment; HotCRP
will display the consequences of the requested assignment for confirmation and
approval. The <code>action</code> field determines the assignment to be
performed. Supported actions include:</p>";

        BulkAssign_HelpTopic::print_actions($this->user);

        echo "<p class=\"w-text\">For example, this file clears existing R1 review assignments for papers
tagged #redo, then assigns two primary reviews for submission #1 and one
secondary review for submission #2:</p>

<pre class=\"entryexample\">paper,action,email,round
#redo,clearreview,all,R1
1,primary,man@alice.org
2,secondary,slugger@manny.com
1,primary,slugger@manny.com</pre>

<p class=\"w-text\"><a href=\"", $this->conf->hoturl("help", ["t" => "bulkassign"]), "\"><strong>Detailed instructions</strong></a></p>
</section>\n";
    }

    function print() {
        $conf = $this->conf;
        $qreq = $this->qreq;
        $qreq->rev_round = (string) $conf->sanitize_round_name($qreq->rev_round);

        // redirect if save cancelled
        if (isset($qreq->saveassignment)
            && isset($qreq->cancel)) {
            unset($qreq->saveassignment);
            $conf->redirect_self($qreq); // should not return
            return;
        }

        // perform quick assignments all at once
        if (isset($qreq->saveassignment)
            && $qreq->valid_post()
            && isset($qreq->data)
            && $qreq->assignment_size_estimate < 1000
            && $this->complete_assignment(null)) {
            $conf->redirect_self($qreq);
            return;
        }


        // header
        $conf->header("Assignments", "bulkassign", ["subtitle" => "Bulk update"]);
        echo '<nav class="papmodes mb-5 clearfix"><ul>',
            '<li class="papmode"><a href="', $conf->hoturl("autoassign"), '">Automatic</a></li>',
            '<li class="papmode"><a href="', $conf->hoturl("manualassign"), '">Manual</a></li>',
            '<li class="papmode"><a href="', $conf->hoturl("conflictassign"), '">Conflicts</a></li>',
            '<li class="papmode active"><a href="', $conf->hoturl("bulkassign"), '">Bulk update</a></li>',
            '</ul></nav>';


        // upload review form action
        if (isset($qreq->data)
            && trim($qreq->data) === "Enter assignments") {
            unset($qreq->data);
        }
        if (isset($qreq->upload)
            && $qreq->valid_post()
            && ($qreq->data || $qreq->has_file("file"))
            && $this->handle_upload()) {
            return;
        }
        if (isset($qreq->saveassignment)
            && $qreq->valid_post()
            && isset($qreq->data)
            && $qreq->assignment_size_estimate >= 1000) {
            $this->complete_assignment([$this, "keep_browser_alive"]);
            $this->finish_browser_alive();
        }


        // Help list
        echo '<div class="helpside"><div class="helpinside">
Assignment methods:
<ul><li><a href="', $conf->hoturl("autoassign"), '">Automatic</a></li>
 <li><a href="', $conf->hoturl("manualassign"), '">Manual by PC member</a></li>
 <li><a href="', $conf->hoturl("assign"), '">Manual by paper</a></li>
 <li><a href="', $conf->hoturl("conflictassign"), '">Potential conflicts</a></li>
 <li><a href="', $conf->hoturl("bulkassign"), '" class="q"><strong>Bulk update</strong></a></li>
</ul>
<hr>
<p>Types of PC review:</p>
<dl><dt>', review_type_icon(REVIEW_PRIMARY), ' Primary</dt><dd>Mandatory review</dd>
  <dt>', review_type_icon(REVIEW_SECONDARY), ' Secondary</dt><dd>May be delegated to external reviewers</dd>
  <dt>', review_type_icon(REVIEW_PC), ' Optional</dt><dd>May be declined</dd>
  <dt>', review_type_icon(REVIEW_META), ' Metareview</dt><dd>Can view all other reviews before completing their own</dd></dl>
</div></div>';


        // Form
        echo Ht::form($conf->hoturl("=bulkassign", "upload=1"));

        // Upload
        echo '<div class="f-i mt-3">',
            Ht::textarea("data", (string) $qreq->data,
                         ["rows" => 1, "cols" => 80, "placeholder" => "Enter assignments", "class" => "need-autogrow", "spellcheck" => "false"]),
            '</div>';

        echo '<div class="mb-3"><strong>OR</strong> &nbsp;',
            '<input type="file" name="file" accept="text/plain,text/csv" size="30"></div>';

        echo '<div id="foldoptions" class="mb-5 foldc fold2c fold3c"><label>',
            'Default action:&nbsp; ',
            Ht::select("default_action", ["guess" => "guess from input",
                                          "primary" => "assign primary reviews",
                                          "secondary" => "assign secondary reviews",
                                          "optionalreview" => "assign optional PC reviews",
                                          "metareview" => "assign metareviews",
                                          "review" => "assign external reviews",
                                          "conflict" => "assign PC conflicts",
                                          "lead" => "assign discussion leads",
                                          "shepherd" => "assign shepherds",
                                          "settag" => "set tags",
                                          "preference" => "set reviewer preferences"],
                       $qreq->default_action ?? "guess",
                       ["id" => "tsel"]),
            '</label>';
        Ht::stash_script('$(function(){
$("#tsel").on("change",function(){
hotcrp.foldup.call(this,null,{f:this.value!=="review"});
hotcrp.foldup.call(this,null,{f:!/^(?:primary|secondary|(?:pc|meta)?review)$/.test(this.value),n:2});
}).trigger("change")})');

        $rev_rounds = $conf->round_selector_options(null);
        $expected_round = $qreq->rev_round ? : $conf->assignment_round_option(false);
        if (count($rev_rounds) > 1) {
            echo '<span class="fx2">&nbsp; in round &nbsp;',
                Ht::select("rev_round", $rev_rounds, $expected_round),
                '</span>';
        } else if ($expected_round !== "unnamed") {
            echo '<span class="fx2">&nbsp; in round ', $expected_round, '</span>';
        }

        $null_mailer = new HotCRPMailer($conf, null, [
            "requester_contact" => $this->user,
            "reason" => "", "width" => false
        ]);
        if (($requestreview_template = $null_mailer->expand_template("requestreview"))) {
            echo Ht::hidden("requestreview_subject", $requestreview_template["subject"]);
            if (isset($qreq->requestreview_body)) {
                $t = $qreq->requestreview_body;
            } else {
                $t = $requestreview_template["body"];
            }
            echo '<div class="fx checki"><label><span class="checkc">',
                Ht::checkbox("requestreview_notify", 1, true),
                "</span>Send email to external reviewers:</label><br>",
                Ht::textarea("requestreview_body", $t, ["cols" => 80, "rows" => 20, "spellcheck" => "true", "class" => "text-monospace need-autogrow"]),
                "</div>\n";
        }
        echo "</div>";

        echo Ht::submit("Prepare assignments", ["class" => "btn-primary"]),
            " &nbsp; <span class=\"hint\">You’ll be able to check the assignments before they are saved.</span>\n";

        echo '<div class="mt-4"><a href="', $conf->hoturl("=search", "fn=get&amp;getfn=pcassignments&amp;t=manager&amp;q=&amp;p=all"), '">Download current PC review assignments</a></div>';

        echo "</form>\n\n";

        $this->print_instructions();

        Ht::stash_script('$("#tsel").trigger("change")');
        $conf->footer();
    }

    static function go(Contact $user, Qrequest $qreq) {
        if ($user->is_manager()) {
            if ($qreq->valid_post()) {
                header("X-Accel-Buffering: no"); // NGINX: do not buffer this output
            }
            (new BulkAssign_Page($user, $qreq))->print();
        } else {
            $user->escape();
        }
    }
}
