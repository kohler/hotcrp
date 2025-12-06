<?php
// pages/p_bulkassign.php -- HotCRP bulk paper assignment page
// Copyright (c) 2006-2024 Eddie Kohler; see LICENSE.

class BulkAssign_Page {
    /** @var Conf */
    public $conf;
    /** @var Contact */
    public $user;
    /** @var Qrequest */
    public $qreq;
    /** @var bool */
    private $saving;
    /** @var AssignmentSet */
    private $aset;
    /** @var bool */
    private $progress_open = false;
    /** @var float */
    private $start_time = 0.0;
    /** @var ?int */
    private $progress_max;

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

    private function generic_progress($phase, $progv, $progmax, $landmark) {
        $time = microtime(true);
        if (!$this->start_time) {
            $this->start_time = $time;
        }
        if ($time - $this->start_time <= 1) {
            return;
        }
        if (!$this->progress_open) {
            echo '<div id="foldprogress" class="foldc fold2o">',
                '<div class="fn fx2 msg msg-warning minw-480">',
                  '<p class="feedback is-warning">',
                  $this->saving ? 'Applying assignments' : 'Preparing draft assignments',
                  '</p>',
                  '<p class="feedback is-inform" id="progress-info"></p>',
                  '<progress id="progress-meter"></progress>',
                '</div></div>';
            $this->progress_open = true;
        }
        if ($progmax === null) {
            $progpct = null;
        } else if ($progv >= $progmax) {
            $progpct = 100;
        } else {
            $progpct = 100 * $progv / $progmax;
        }
        if ($phase === AssignmentSet::PROGPHASE_PARSE) {
            $text = "<span class=\"lineno\">" . htmlspecialchars($landmark) . ":</span> Parsing assignment";
            if ($progpct !== null) {
                $text .= sprintf(" (%.0f%% done)", $progpct);
            }
        } else if ($phase === AssignmentSet::PROGPHASE_REALIZE) {
            $text = sprintf("Computing assignments (%.0f%% done)", $progpct);
        } else if ($phase === AssignmentSet::PROGPHASE_UNPARSE) {
            $text = "Rendering assignments";
        } else if ($phase === AssignmentSet::PROGPHASE_SAVE) {
            $text = sprintf("Saving assignments (%.0f%% done)", $progpct);
        } else {
            $text = "Preparing assignment";
            if ($progpct !== null) {
                $text .= sprintf(" (%.0f%% done)", $progpct);
            } else if ($landmark) {
                $text .= " at <span class=\"lineno\">" . htmlspecialchars($landmark) . "</span>";
            }
        }
        $js = "";
        if ($text !== null) {
            $js .= "document.getElementById('progress-info').innerHTML=" . json_encode_browser($text) . ";";
        }
        if ($progmax !== $this->progress_max) {
            if ($progmax === null) {
                $js .= "document.getElementById('progress-meter').removeAttribute('value');";
            } else {
                $js .= "document.getElementById('progress-meter').max=" . json_encode_browser($progmax) . ";";
            }
            $this->progress_max = $progmax;
        }
        if ($progmax !== null) {
            $js .= "document.getElementById('progress-meter').value=" . json_encode_browser($progv) . ";";
        }
        echo Ht::unstash_script($js ? : ";");
        flush();
        while (@ob_end_flush()) {
        }
    }

    function aset_progress(AssignmentSet $aset) {
        $this->generic_progress($aset->progress_phase(),
            $aset->progress_value(), $aset->progress_max(),
            $aset->landmark());
    }

    function finish_progress() {
        if ($this->progress_open) {
            echo Ht::unstash_script("hotcrp.fold('progress',null)");
        }
    }

    function complete_assignment($callback) {
        if (isset($this->qreq->data)) {
            $content = $this->qreq->data;
        } else if (isset($this->qreq->data_source)
                   && ($ds = $this->conf->docstore())) {
            $content = $ds->open_tempfile($this->qreq->data_source, "bulkassign-%s.csv");
        } else {
            $content = null;
        }
        if (!$content) {
            return false;
        }
        $ssel = SearchSelection::make($this->qreq, $this->user);
        $aset = new AssignmentSet($this->user);
        $aset->set_override_conflicts(true);
        if (isset($this->qreq->t)) {
            $aset->set_search_type($this->qreq->t);
        }
        if ($callback) {
            $aset->add_progress_function($callback);
        }
        $aset->enable_papers($ssel->selection());
        $this->saving = true;
        $aset->parse($content, $this->qreq->filename, $this->assignment_defaults());
        $aset->execute();
        $aset->feedback_msg(AssignmentSet::FEEDBACK_ASSIGN);
        return !$aset->has_error();
    }

    /** @return bool */
    private function handle_upload() {
        flush();
        while (@ob_end_flush()) {
        }

        if (($qf = $this->qreq->file("file"))) {
            $qf = $qf->content_or_docstore("bulkassign-%s.csv", $this->conf);
        } else {
            $qf = QrequestFile::make_string($this->qreq->data, "");
        }
        if (!$qf) {
            $this->conf->error_msg("<0>Uploaded file too big to process");
            return false;
        }
        $qf->convert_to_utf8();

        $aset = new AssignmentSet($this->user);
        $aset->set_override_conflicts(true);
        if ($this->qreq->t) {
            $aset->set_search_type($this->qreq->t);
        }
        $aset->set_csv_context(true);
        $aset->add_progress_function([$this, "aset_progress"]);
        $defaults = $this->assignment_defaults();
        $this->saving = false;
        $aset->parse($qf->stream ?? $qf->content, $qf->name, $defaults);

        if ($aset->has_error() || $aset->is_empty()) {
            $this->finish_progress();
            $aset->feedback_msg(AssignmentSet::FEEDBACK_ASSIGN);
            return false;
        }

        $atype = $aset->type_description();
        echo '<h3>Proposed ', $atype ? $atype . " " : "", 'assignment</h3>';
        $this->conf->feedback_msg(
            MessageItem::marked_note("<0>Select “Apply changes” to make the checked assignments.")
        );

        $atypes = $aset->assigned_types();
        $apids = $aset->numjoin_assigned_pids(" ");
        if (strlen($apids) > 200) {
            $apids = SessionList::encode_ids($aset->assigned_pids());
        }
        if (strlen($apids) > 400) {
            $apids = "[many]";
        }
        echo Ht::form($this->conf->hoturl("=bulkassign", [
                "saveassignment" => 1,
                "assigntypes" => join(" ", $atypes),
                "assignpids" => $apids,
                "XDEBUG_TRIGGER" => $this->qreq->XDEBUG_TRIGGER
            ]), ["class" => "ui-submit js-selector-summary"]),
            Ht::hidden("default_action", $defaults["action"] ?? "guess"),
            Ht::hidden("rev_round", $defaults["round"]);
        if (is_string($qf->content)) {
            echo Ht::hidden("data", $qf->content);
        } else {
            echo Ht::hidden("data_source", $qf->docstore_tmp_name);
        }
        echo Ht::hidden("filename", $qf->name),
            Ht::hidden("assignment_size_estimate", max($aset->assignment_count(), $aset->request_count())),
            Ht::hidden("requestreview_notify", $this->qreq->requestreview_notify),
            Ht::hidden("requestreview_subject", $this->qreq->requestreview_subject),
            Ht::hidden("requestreview_body", $this->qreq->requestreview_body);

        $aset->feedback_msg(AssignmentSet::FEEDBACK_ASSIGN);
        $aset->print_unparse_display();
        $this->finish_progress();

        echo Ht::actions([
            Ht::submit("Apply changes", ["class" => "btn-success"]),
            Ht::submit("cancel", "Cancel")
        ], ["class" => "aab aabig"]), "</form>\n";
        $this->qreq->print_footer();
        return true;
    }

    function print_instructions() {
        echo "<section class=\"mt-7\">
<h3><a class=\"ulh\" href=\"", $this->conf->hoturl("help", ["t" => "bulkassign"]), "\">Instructions</a></h3>

<p class=\"w-text\">Upload a CSV (comma-separated value) to prepare an
assignment. The first line of the CSV is a header defining the meaning of each
column; every other line defines an assignment to be performed. The mandatory
<code>action</code> column sets the kind of assignment. HotCRP will display
the consequences of the requested assignment for confirmation and approval.
Supported actions include:</p>";

        BulkAssign_HelpTopic::print_actions($this->user);

        echo "<p class=\"w-text mt-3\">For example, this file clears existing R1 review assignments for papers
tagged #redo, then assigns two primary reviews for submission #1 and one
secondary review for submission #2:</p>

<pre class=\"sample\">paper,action,email,round
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
            unset($qreq->saveassignment, $qreq->p, $qreq->pap);
            $conf->redirect_self($qreq); // should not return
            return;
        }

        // perform quick assignments all at once
        if (isset($qreq->saveassignment)
            && $qreq->valid_post()
            && isset($qreq->data)
            && $qreq->assignment_size_estimate < 1000
            && $this->complete_assignment(null)) {
            unset($qreq->saveassignment, $qreq->p, $qreq->pap);
            $conf->redirect_self($qreq);
            return;
        }


        // header
        $qreq->print_header("Assignments", "bulkassign", ["subtitle" => "Bulk update"]);
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
            && ((isset($qreq->data) && $qreq->assignment_size_estimate >= 1000)
                || isset($qreq->data_source))) {
            $this->complete_assignment([$this, "aset_progress"]);
            $this->finish_progress();
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
<dl class="bsp"><dt>', review_type_icon(REVIEW_PRIMARY), ' Primary</dt><dd>Mandatory review</dd>
  <dt>', review_type_icon(REVIEW_SECONDARY), ' Secondary</dt><dd>May be delegated to external reviewers</dd>
  <dt>', review_type_icon(REVIEW_PC), ' Optional</dt><dd>May be declined</dd>
  <dt>', review_type_icon(REVIEW_META), ' Metareview</dt><dd>Can view all other reviews before completing their own</dd></dl>
</div></div>';


        // Form
        echo Ht::form($conf->hoturl("=bulkassign", [
            "upload" => 1,
            "XDEBUG_TRIGGER" => $qreq->XDEBUG_TRIGGER
        ]));

        // Upload
        echo '<div class="f-i mt-3">',
            Ht::textarea("data", (string) $qreq->data,
                         ["rows" => 1, "cols" => 80, "placeholder" => "Enter CSV assignments with header", "class" => "need-autogrow", "spellcheck" => "false", "id" => "k-bulkassign-entry"]),
            '</div>';

        echo '<div class="mb-3"><strong>OR</strong> &nbsp;',
            '<input type="file" name="file" accept="text/plain,text/csv" size="30"></div>';

        $limits = PaperSearch::viewable_limits($this->user);
        echo '<p class="mt-5 mb-2"><label>Paper collection: ',
            PaperSearch::limit_selector($this->conf, $limits, in_array("all", $limits, true) ? "all" : PaperSearch::default_limit($this->user, $limits), ["class" => "ml-1"]),
            '</label></p>';

        echo '<div id="foldoptions" class="mb-5 foldc fold2c fold3c">',
            '<label>Action: ',
            Ht::select("default_action", [
                ["value" => "guess", "label" => "Any", "data-csv-header" => "Enter CSV assignments with header"],
                ["value" => "primary", "label" => "Assign primary reviews", "data-csv-header" => "paper,user"],
                ["value" => "secondary", "label" => "Assign secondary reviews", "data-csv-header" => "paper,user"],
                ["value" => "optionalreview", "label" => "Assign optional PC reviews", "data-csv-header" => "paper,user"],
                ["value" => "metareview", "label" => "Assign metareviews", "data-csv-header" => "paper,user"],
                ["value" => "review", "label" => "Assign external reviews", "data-csv-header" => "paper,user"],
                ["value" => "conflict", "label" => "Assign PC conflicts", "data-csv-header" => "paper,user"],
                ["value" => "lead", "label" => "Assign discussion leads", "data-csv-header" => "paper,user"],
                ["value" => "shepherd", "label" => "Assign shepherds", "data-csv-header" => "paper,user"],
                ["value" => "settag", "label" => "Set tags", "data-csv-header" => "paper,tags"],
                ["value" => "preference", "label" => "Set reviewer preferences", "data-csv-header" => "paper,user,preference"]
            ], $qreq->default_action ?? "guess",
               ["id" => "k-action", "class" => "ml-1 uich js-bulkassign-action"]),
            '</label> ';

        Ht::stash_script('$(function(){$("#k-action").trigger("change")})');

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
            echo '<div class="fx checki mt-2"><label><span class="checkc">',
                Ht::checkbox("requestreview_notify", 1, true),
                "</span>Send email to external reviewers:</label><br>",
                Ht::textarea("requestreview_body", $t, ["cols" => 80, "rows" => 20, "spellcheck" => "true", "class" => "text-monospace need-autogrow"]),
                "</div>\n";
        }
        echo "</div>";

        echo Ht::submit("Prepare assignments", ["class" => "btn-primary"]),
            " &nbsp; <span class=\"hint\">You’ll be able to check the assignments before they are saved.</span>\n";

        echo '<div class="mt-4"><a href="', $conf->hoturl("=search", ["fn" => "get", "getfn" => "pcassignments", "t" => "alladmin", "q" => "", "p" => "all"]), '">Download current PC review assignments</a></div>';

        echo "</form>\n\n";

        $this->print_instructions();

        Ht::stash_script('$("#k-action").trigger("change")');
        $qreq->print_footer();
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
