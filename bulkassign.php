<?php
// bulkassign.php -- HotCRP bulk paper assignment page
// HotCRP is Copyright (c) 2006-2015 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

require_once("src/initweb.php");
require_once("src/papersearch.php");
require_once("src/assigners.php");
if (!$Me->privChair)
    $Me->escape();
if (check_post())
    header("X-Accel-Buffering: no");  // NGINX: do not hold on to file
$null_mailer = new HotCRPMailer(null, null, array("requester_contact" => $Me,
                                                  "other_contact" => $Me /* backwards compat */,
                                                  "reason" => "",
                                                  "width" => false));
$Error = $Warning = array();


function assignment_defaults() {
    $defaults = array("action" => @$_REQUEST["default_action"],
                      "round" => @$_REQUEST["rev_roundtag"]);
    if (@$_REQUEST["requestreview_notify"] && @$_REQUEST["requestreview_body"])
        $defaults["extrev_notify"] = array("subject" => @$_REQUEST["requestreview_subject"],
                                           "body" => @$_REQUEST["requestreview_body"]);
    if (trim($defaults["round"]) == "(None)")
        $defaults["round"] = null;
    return $defaults;
}

$csv_lineno = 0;
$csv_preparing = false;
function keep_browser_alive($assignset, $lineno, $line) {
    global $Conf, $csv_lineno, $csv_preparing;
    $csv_lineno = $lineno;
    if ($lineno >= 1000) {
        if (!$csv_preparing) {
            echo "<div id='foldmail' class='foldc fold2o'>",
                "<div class='fn fx2 merror'>Preparing assignments.<br /><span id='mailcount'></span></div>",
                "</div>";
            $csv_preparing = true;
        }
        if ($assignset->filename)
            $text = "<span class='lineno'>"
                . htmlspecialchars($assignset->filename) . ":$lineno:</span>";
        else
            $text = "<span class='lineno'>line $lineno:</span>";
        if ($line === false)
            $text .= " processing";
        else
            $text .= " <code>" . htmlspecialchars(join(",", $line)) . "</code>";
        $Conf->echoScript("\$\$('mailcount').innerHTML=\"$text\";");
        flush();
        while (@ob_end_flush());
            /* skip */;
    }
}

function finish_browser_alive() {
    global $Conf, $csv_preparing;
    if ($csv_preparing)
        $Conf->echoScript("fold('mail',null)");
}


if (isset($_REQUEST["saveassignment"]) && check_post()) {
    if (isset($_REQUEST["cancel"]))
        redirectSelf();
    else if (isset($_POST["file"])
             && @$_POST["assignment_size_estimate"] < 1000) {
        $assignset = new AssignmentSet($Me, false);
        $assignset->parse($_REQUEST["file"], @$_REQUEST["filename"],
                          assignment_defaults());
        if ($assignset->execute())
            redirectSelf();
    }
}


$abar = "<div class='vbar'><table class='vbar'><tr><td><table><tr>\n";
$abar .= actionTab("Automatic", hoturl("autoassign"), false);
$abar .= actionTab("Manual", hoturl("manualassign"), false);
$abar .= actionTab("Upload", hoturl("bulkassign"), true);
$abar .= "</tr></table></td>\n<td class='spanner'></td>\n<td class='gopaper nowrap'>" . goPaperForm() . "</td></tr></table></div>\n";


$Conf->header("Review Assignments", "bulkassign", $abar);


// Help list
echo "<div class='helpside'><div class='helpinside'>
Assignment methods:
<ul><li><a href='", hoturl("autoassign"), "'>Automatic</a></li>
 <li><a href='", hoturl("manualassign"), "'>Manual by PC member</a></li>
 <li><a href='", hoturl("assign"), "'>Manual by paper</a></li>
 <li><a href='", hoturl("bulkassign"), "' class='q'><strong>Upload</strong></a></li>
</ul>
<hr class='hr' />
Types of PC review:
<dl><dt>" . review_type_icon(REVIEW_PRIMARY) . " Primary</dt><dd>Mandatory, may not be delegated</dd>
  <dt>" . review_type_icon(REVIEW_SECONDARY) . " Secondary</dt><dd>Mandatory, may be delegated to external reviewers</dd>
  <dt>" . review_type_icon(REVIEW_PC) . " Optional</dt><dd>May be declined</dd></dl>
</div></div>";


// upload review form action
if (isset($_REQUEST["upload"]) && fileUploaded($_FILES["uploadfile"])
    && check_post()) {
    flush();
    while (@ob_end_flush())
        /* do nothing */;
    if (($text = file_get_contents($_FILES["uploadfile"]["tmp_name"])) === false)
        $Conf->errorMsg("Internal error: cannot read file.");
    else {
        $assignset = new AssignmentSet($Me, false);
        $defaults = assignment_defaults();
        $assignset->parse($text, $_FILES["uploadfile"]["name"], $defaults, "keep_browser_alive");
        finish_browser_alive();
        if ($assignset->report_errors())
            /* do nothing */;
        else if ($assignset->is_empty())
            $Conf->warnMsg("That assignment file makes no changes.");
        else {
            echo '<h3>Proposed assignment</h3>';
            $Conf->infoMsg("If this assignment looks OK to you, select “Save assignment” to apply it. (You can always alter the assignment afterwards.)");
            $assignset->echo_unparse_display();

            list($atypes, $apids) = $assignset->types_and_papers(true);
            echo '<div class="g"></div>',
                Ht::form(hoturl_post("bulkassign",
                                     array("saveassignment" => 1,
                                           "assigntypes" => join(" ", $atypes),
                                           "assignpids" => join(" ", $apids)))),
                '<div class="aahc"><div class="aa">',
                Ht::submit("Save assignment"),
                ' &nbsp;', Ht::submit("cancel", "Cancel"),
                Ht::hidden("default_action", $defaults["action"]),
                Ht::hidden("rev_roundtag", $defaults["round"]),
                Ht::hidden("file", $text),
                Ht::hidden("assignment_size_estimate", $csv_lineno),
                Ht::hidden("filename", $_FILES["uploadfile"]["name"]),
                Ht::hidden("requestreview_notify", @$_REQUEST["requestreview_notify"]),
                Ht::hidden("requestreview_subject", @$_REQUEST["requestreview_subject"]),
                Ht::hidden("requestreview_body", @$_REQUEST["requestreview_body"]),
                '</div></div></form>', "\n";
            $Conf->footer();
            exit;
        }
    }
}

if (isset($_REQUEST["saveassignment"]) && check_post()
    && isset($_POST["file"]) && @$_POST["assignment_size_estimate"] >= 1000) {
    $assignset = new AssignmentSet($Me, false);
    $assignset->parse($_POST["file"], @$_POST["filename"],
                      assignment_defaults(), "keep_browser_alive");
    $assignset->execute();
    finish_browser_alive();
}


echo "<h2 style='margin-top:1em'>Upload assignments</h2>\n\n";

echo Ht::form_div(hoturl_post("bulkassign", "upload=1"));

// Upload
echo '<input type="file" name="uploadfile" accept="text/plain,text/csv" size="30" />',
    '<div style="margin:0.5em 0">';

echo 'By default, assign&nbsp; ',
    Ht::select("default_action", array("primary" => "primary reviews",
                                       "secondary" => "secondary reviews",
                                       "pcreview" => "optional PC reviews",
                                       "review" => "external reviews",
                                       "conflict" => "PC conflicts",
                                       "lead" => "discussion leads",
                                       "shepherd" => "shepherds",
                                       "tag" => "add tags",
                                       "settag" => "replace tags",
                                       "preference" => "reviewer preferences"),
               defval($_REQUEST, "default_action", "primary"),
               array("id" => "tsel", "onchange" => "fold(\"email\",this.value!=\"review\")")),
    '<div class="g"></div>', "\n";

if (!isset($_REQUEST["rev_roundtag"]))
    $rev_roundtag = $Conf->setting_data("rev_roundtag");
else if (($rev_roundtag = $_REQUEST["rev_roundtag"]) == "(None)")
    $rev_roundtag = "";
$requestreview_template = $null_mailer->expand_template("requestreview");
echo Ht::hidden("requestreview_subject", $requestreview_template["subject"]);
if (isset($_REQUEST["requestreview_body"]))
    $t = $_REQUEST["requestreview_body"];
else
    $t = $requestreview_template["body"];
echo "<div id='foldemail' class='foldo'><table class='fx'>
<tr><td>", Ht::checkbox("requestreview_notify", 1, true), "&nbsp;</td>
<td>", Ht::label("Send email to external reviewers:"), "</td></tr>
<tr><td></td><td>",
    Ht::textarea("requestreview_body", $t, array("class" => "tt", "cols" => 80, "rows" => 20)),
    "</td></tr></table></div>\n";
if (count($Conf->round_list()) > 1 || $rev_roundtag)
    echo Ht::hidden("rev_roundtag", $rev_roundtag),
        'Current review round: &nbsp;', htmlspecialchars($rev_roundtag ? : "(no name)"),
        ' &nbsp;<span class="barsep">|</span>&nbsp; <a href="', hoturl("settings", "group=reviews#rounds"), '">Configure rounds</a>';

echo '<div class="g"></div>', Ht::submit("Upload"), "</div>";

echo '<div style="margin-top:1.5em"><a href="', hoturl_post("search", "t=s&q=&get=pcassignments&p=all"), '">Download current PC assignments</a></div>';

echo "</div></form>

<hr style='margin-top:1em' />

<h3>Instructions</h3>

<p>Upload a comma-separated value file to assign reviews, conflicts, leads,
shepherds, and tags. You’ll be given a chance to review the assignments before
they are applied.</p>

<p>A simple example:</p>

<pre class='entryexample'>paper,assignment,email
1,primary,man@alice.org
2,secondary,slugger@manny.com
1,primary,slugger@manny.com</pre>

<p>This assigns PC members man@alice.org and slugger@manny.com as primary
reviewers for paper #1, and PC member slugger@manny.com as a secondary
reviewer for paper #2. Errors will be reported if those users aren’t PC
members, or if they have conflicts with their assigned papers.</p>

<p>A more complex example:</p>

<pre class='entryexample'>paper,assignment,email,round
all,clearreview,all,R2
1,primary,man@alice.org,R2
10,primary,slugger@manny.com,R2
#manny OR #ramirez,primary,slugger@manny.com,R2</pre>

<p>The first assignment line clears all review assignments in
round R2. (Review assignments in other rounds are left alone.) The next
lines assign man@alice.org as a primary reviewer for paper #1, and slugger@manny.com
as a primary reviewer for paper #10. The last line assigns slugger@manny.com
as a primary reviewer for all papers tagged #manny or #ramirez.</p>

<p>HotCRP parses each assignment file line by line, but commits the
file as a unit. If file makes no overall changes to the current
state, the upload process does nothing. For instance, if a file
removes an active assignment and then restores it, the assignment is left alone.</p>

<p>Assignment types are:</p>

<dl>
<dt><code>primary</code>, <code>secondary</code>, <code>pcreview</code></dt>
<dd>Assign a primary, secondary, or optional PC review. The <code>email</code>,
<code>name</code>, and/or <code>user</code> columns locate the user.
It’s an error if a user doesn’t correspond to a PC member.
The optional <code>round</code> column sets the review round.</dd>

<dt><code>review</code></dt>
<dd>Assign an external review (or an optional PC review, if the user is a PC member).
The <code>email</code> and/or <code>name</code> columns locate the user.
(<code>first</code> and <code>last</code> columns may be used in place of <code>name</code>.)
If the user doesn’t have an account, one will be created.
The optional <code>round</code> column sets the review round.</dd>

<dt><code>clearreview</code></dt>
<dd>Clear an existing review assignment. The <code>email</code> and/or
<code>name</code> columns locate the user. The optional <code>round</code>
column sets the review round; only matching assignments are cleared.
Note that clearing an assignment doesn’t remove reviews that are already
submitted (though clearing a primary or secondary assignment will change any associated
review into a PC review).</dd>

<dt><code>lead</code></dt>
<dd>Set the discussion lead. The <code>email</code>, <code>name</code>,
and/or <code>user</code> columns locate the PC user. To clear the discussion lead,
use email <code>none</code> or assignment type <code>clearlead</code>.</dd>

<dt><code>shepherd</code></dt>
<dd>Set the shepherd. The <code>email</code>, <code>name</code>,
and/or <code>user</code> columns locate the PC user. To clear the shepherd,
use email <code>none</code> or assignment type <code>clearshepherd</code>.</dd>

<dt><code>conflict</code></dt>
<dd>Mark a PC conflict. The <code>email</code>, <code>name</code>,
and/or <code>user</code> columns locate the PC user. To clear a conflict,
use assignment type <code>clearconflict</code>.</dd>

<dt><code>tag</code></dt>
<dd>Add a tag. The <code>tag</code> column names the tag and the optional
<code>value</code> column sets the tag value.
To clear a tag, use assignment type <code>cleartag</code> or value <code>none</code>.</dd>

<dt><code>preference</code></dt>
<dd>Set reviewer preference and expertise. The <code>preference</code> column
gives the preference value.</dd>
</dl>\n";

$Conf->footerScript("fold('email',\$\$('tsel').value!='review')");
$Conf->footer();
