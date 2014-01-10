<?php
// bulkassign.php -- HotCRP bulk paper assignment page
// HotCRP is Copyright (c) 2006-2014 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

require_once("Code/header.inc");
require_once("src/papersearch.php");
require_once("src/assigners.php");
if ($Me->is_empty() || !$Me->privChair)
    $Me->escape();
$nullMailer = new Mailer(null, null, $Me);
$nullMailer->width = 10000000;
$Error = $Warning = array();


function assignment_defaults() {
    $defaults = array("action" => @$_REQUEST["default_action"],
                      "round" => @$_REQUEST["rev_roundtag"]);
    if (trim($defaults["round"]) == "(None)")
        $defaults["round"] = null;
    return $defaults;
}


if (isset($_REQUEST["saveassignment"]) && check_post()) {
    if (isset($_REQUEST["cancel"]))
        redirectSelf();
    else if (isset($_REQUEST["file"])) {
        $assignset = new AssignmentSet(false);
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
<dl><dt><img class='ass" . REVIEW_PRIMARY . "' src='images/_.gif' alt='Primary' /> Primary</dt><dd>Mandatory, may not be delegated</dd>
  <dt><img class='ass" . REVIEW_SECONDARY . "' src='images/_.gif' alt='Secondary' /> Secondary</dt><dd>Mandatory, may be delegated to external reviewers</dd>
  <dt><img class='ass" . REVIEW_PC . "' src='images/_.gif' alt='PC' /> Optional</dt><dd>May be declined</dd></dl>
</div></div>";


// upload review form action
if (isset($_REQUEST["upload"]) && fileUploaded($_FILES["uploadfile"])
    && check_post()) {
    if (($text = file_get_contents($_FILES["uploadfile"]["tmp_name"])) === false)
        $Conf->errorMsg("Internal error: cannot read file.");
    else {
        $assignset = new AssignmentSet(false);
        $defaults = assignment_defaults();
        $assignset->parse($text, $_FILES["uploadfile"]["name"], $defaults);
        if ($assignset->report_errors())
            /* do nothing */;
        else {
            echo '<h3>Proposed assignment</h3>';
            $Conf->infoMsg("If this assignment looks OK to you, select “Save assignment” to apply it. (You can always alter the assignment afterwards.)");
            $assignset->echo_unparse_display();

            echo '<div class="g"></div>',
                Ht::form(hoturl_post("bulkassign", "saveassignment=1")),
                '<div class="aahc"><div class="aa">',
                Ht::submit("Save assignment"),
                ' &nbsp;', Ht::submit("cancel", "Cancel"),
                Ht::hidden("default_action", $defaults["action"]),
                Ht::hidden("rev_roundtag", $defaults["round"]),
                Ht::hidden("file", $text),
                Ht::hidden("filename", $_FILES["uploadfile"]["name"]),
                '</div></div></form>', "\n";
            $Conf->footer();
            exit;
        }
    }
}


echo "<h2 style='margin-top:1em'>Upload assignments</h2>\n\n";

echo Ht::form(hoturl_post("bulkassign", "upload=1")), '<div class="inform">';

echo '<table><tr>',
    '<td colspan="2">Upload:&nbsp; ',
    '<input type="file" name="uploadfile" accept="text/plain,text/csv" size="30" /> ',
    Ht::submit("Go"),
    '</td></tr>',
    '<tr><td style="padding-left:2em"></td><td style="padding-top:0.5em;font-size:smaller">';

echo 'Default action:&nbsp; assign&nbsp; ',
    Ht::select("default_action", array("primary" => "primary reviews",
                                       "secondary" => "secondary reviews",
                                       "pcreview" => "optional PC reviews",
                                       "review" => "external reviews",
                                       "conflict" => "PC conflicts",
                                       "lead" => "discussion leads",
                                       "shepherd" => "shepherds"),
               defval($_REQUEST, "default_action", "primary"),
               array("id" => "tsel", "onchange" => "fold(\"email\",this.value!=\"review\")")),
    '<div class="g"></div>', "\n";

if (!isset($_REQUEST["rev_roundtag"]))
    $rev_roundtag = $Conf->setting_data("rev_roundtag");
else if (($rev_roundtag = $_REQUEST["rev_roundtag"]) == "(None)")
    $rev_roundtag = "";
if (isset($_REQUEST["email_requestreview"]))
    $t = $_REQUEST["email_requestreview"];
else {
    $t = $nullMailer->expandTemplate("requestreview");
    $t = $t["body"];
}
echo "<div id='foldemail' class='foldo'><table class='fx'>
<tr><td>", Ht::checkbox("email", 1, true), "&nbsp;</td>
<td>", Ht::label("Send email to external reviewers:"), "</td></tr>
<tr><td></td><td><textarea class='tt' name='email_requestreview' cols='80' rows='20'>", htmlspecialchars($t), "</textarea></td></tr></table>
<div class='fn";
if (isset($Error["rev_roundtag"]))
    echo " error";
echo "'>Default review round: &nbsp;",
    "<input id='rev_roundtag' class='textlite temptextoff' type='text' size='15' name='rev_roundtag' value=\"",
    htmlspecialchars($rev_roundtag ? $rev_roundtag : "(None)"),
    "\" />",
    " &nbsp;<a class='hint' href='", hoturl("help", "t=revround"), "'>What is this?</a></div></div>
</td></tr></table>
</div></form>

<div class='g'></div>

<p>Use this page to upload many assignments at once. The upload format is a
comma-separated file with one line per assignment. For example:</p>

<table style='width:60%'><tr><td><pre class='entryexample'>
paper,name,email
1,Alice Man,man@alice.org
10,,noname@anonymous.org
11,Manny Ramirez,slugger@manny.com
</pre></td></tr></table>

<p>Possible columns include “<code>paper</code>”, “<code>action</code>”,
“<code>email</code>”, “<code>first</code>”, “<code>last</code>”,
“<code>name</code>”, and “<code>round</code>”. The “<code>action</code>”
column, if given, is the assignment type for that row;
it should be one of ";
$anames = array();
foreach (Assigner::assigner_names() as $a)
    $anames[] = "“<code>$a</code>”";
echo commajoin($anames), ".</p>

<p>Primary, secondary, and optional PC reviews must be PC members, so for
those reviewer types you don't need a full name or email address, just some
substring that identifies the PC member uniquely. For example:</p>

<table style='width:60%'><tr><td><pre class='entryexample'>
paper,user
24,sylvia
1,Frank
100,feldmann
</pre></td></tr></table>\n";

$Conf->footerScript("mktemptext('rev_roundtag','(None)')");
$Conf->footerScript("fold('email',\$\$('tsel').value!=" . REVIEW_EXTERNAL . ")");
$Conf->footer();
