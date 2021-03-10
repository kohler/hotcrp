<?php
// offline.php -- HotCRP offline review management page
// Copyright (c) 2006-2021 Eddie Kohler; see LICENSE.

require_once("src/initweb.php");
if (!$Me->email) {
    $Me->escape();
}
$rf = $Conf->review_form();


// general error messages
if ($Qreq->post && $Qreq->post_empty())
    $Conf->post_missing_msg();


// download blank review form action
if (isset($Qreq->downloadForm)) {
    $Conf->make_csvg("review", CsvGenerator::TYPE_STRING)
        ->set_inline(false)
        ->add_string($rf->text_form_header(false) . $rf->text_form(null, null, $Me, null) . "\n")
        ->emit();
    exit;
}


// upload review form action
if (isset($Qreq->uploadForm)
    && $Qreq->has_file("uploadedFile")
    && $Qreq->valid_post()) {
    $tf = ReviewValues::make_text($rf, $Qreq->file_contents("uploadedFile"),
                        $Qreq->file_filename("uploadedFile"));
    while ($tf->parse_text($Qreq->override))
        $tf->check_and_save($Me, null, null);
    $tf->report();
    // Uploading forms may have completed the reviewer's task; recheck roles.
    Contact::update_rights();
} else if (isset($Qreq->uploadForm)) {
    Conf::msg_error("Choose a file first.");
}


function setTagIndexes(Contact $user, $qreq) {
    $filename = null;
    if (isset($qreq->upload) && $qreq->has_file("file")) {
        if (($text = $qreq->file_contents("file")) === false) {
            Conf::msg_error("Internal error: cannot read file.");
            return;
        }
        $filename = $qreq->file_filename("file");
    } else if (($text = $qreq->data)) {
        $filename = "";
    } else {
        Conf::msg_error("Choose a file first.");
        return;
    }

    $trp = new TagRankParser($user);
    $tagger = new Tagger($user);
    if ($qreq->tag && ($tag = $tagger->check(trim($qreq->tag), Tagger::NOVALUE))) {
        $trp->set_tag($tag);
    }
    $aset = $trp->parse_assignment_set($text, $filename);
    if (!$aset->execute()) {
        Conf::msg_error("Changes not saved. Please fix these errors and try again." . $aset->messages_div_html(true));
    } else {
        Conf::msg_confirm("Tags saved." . $aset->messages_div_html(true));
    }
}
if ((isset($Qreq->setvote) || isset($Qreq->setrank))
    && $Me->is_reviewer()
    && $Qreq->valid_post()) {
    setTagIndexes($Me, $Qreq);
}


$pastDeadline = !$Conf->time_review(null, $Me->isPC, true);

if (!$Conf->time_review_open() && !$Me->privChair) {
    Conf::msg_error("The site is not open for review.");
    $Conf->redirect();
}

$Conf->header("Offline reviewing", "offline");

if ($Me->is_reviewer()) {
    if (!$Conf->time_review_open())
        $Conf->infoMsg("The site is not open for review.");
    $Conf->infoMsg("Use this page to download a blank review form, or to upload review forms you’ve already filled out.");
    if (!$Me->can_clickthrough("review")) {
        echo '<div class="js-clickthrough-container">';
        PaperTable::echo_review_clickthrough();
        echo '</div>';
    }
} else {
    $Conf->infoMsg("You aren’t registered as a reviewer or PC member for this conference, but for your information, you may download the review form anyway.");
}


echo '<table id="offlineform">';

// Review forms
echo "<tr><td><h3>Download forms</h3>\n<div>";
if ($Me->is_reviewer()) {
    echo '<a href="', hoturl("search", "fn=get&amp;getfn=revform&amp;q=&amp;t=r&amp;p=all"), '">Your reviews</a><br>', "\n";
    if ($Me->has_outstanding_review())
        echo '<a href="', hoturl("search", "fn=get&amp;getfn=revform&amp;q=&amp;t=rout&amp;p=all"), '">Your incomplete reviews</a><br>', "\n";
    echo '<a href="', hoturl("offline", "downloadForm=1"), '">Blank form</a></div>
<hr class="g">
<span class="hint"><strong>Tip:</strong> Use <a href="', hoturl("search", "q="), '">Search</a> &gt; Download to choose individual papers.</span>', "\n";
} else
    echo '<a href="', hoturl("offline", "downloadForm=1"), '">Blank form</a></div>', "\n";
echo "</td>\n";
if ($Me->is_reviewer()) {
    $disabled = ($pastDeadline && !$Me->privChair ? " disabled" : "");
    echo "<td><h3>Upload filled-out forms</h3>\n",
        Ht::form($Conf->hoturl_post("offline", "uploadForm=1")),
        Ht::hidden("postnonempty", 1),
        '<input type="file" name="uploadedFile" accept="text/plain" size="30"', $disabled, '>&nbsp; ',
        Ht::submit("Go", array("disabled" => !!$disabled));
    if ($pastDeadline && $Me->privChair)
        echo "<br />", Ht::checkbox("override"), "&nbsp;", Ht::label("Override&nbsp;deadlines");
    echo '<br><span class="hint"><strong>Tip:</strong> You may upload a file containing several forms.</span>';
    echo "</form></td>\n";
}
echo "</tr>\n";


// Ranks
if ($Conf->setting("tag_rank") && $Me->is_reviewer()) {
    $ranktag = $Conf->setting_data("tag_rank");
    echo '<tr><td><hr class="g"></td></tr>', "\n",
        "<tr><td><h3>Download ranking file</h3>\n<div>";
    echo "<a href=\"", hoturl("search", "fn=get&amp;getfn=rank&amp;tag=%7E$ranktag&amp;q=&amp;t=r&amp;p=all"), "\">Your reviews</a>";
    if ($Me->isPC)
        echo "<br />\n<a href=\"", hoturl("search", "fn=get&amp;getfn=rank&amp;tag=%7E$ranktag&amp;q=&amp;t=s&amp;p=all"), "\">All submitted papers</a>";
    echo "</div></td>\n";

    $disabled = ($pastDeadline && !$Me->privChair ? " disabled" : "");
    echo "<td><h3>Upload ranking file</h3>\n",
        Ht::form($Conf->hoturl_post("offline", "setrank=1&amp;tag=%7E$ranktag")),
        Ht::hidden("upload", 1),
        '<input type="file" name="file" accept="text/plain" size="30"', $disabled, '>&nbsp; ',
        Ht::submit("Go", array("disabled" => !!$disabled));
    if ($pastDeadline && $Me->privChair)
        echo "<br>", Ht::checkbox("override"), "&nbsp;", Ht::label("Override&nbsp;deadlines");
    echo '<br><span class="hint"><strong>Tip:</strong> Use “<a href="', hoturl("search", "q=" . urlencode("editsort:#~$ranktag")), '">editsort:#~', $ranktag, '</a>” to drag and drop your ranking.</span>';
    echo '<br><span class="hint"><strong>Tip:</strong> “<a href="', hoturl("search", "q=order:%7E$ranktag"), '">order:~', $ranktag, '</a>” searches by your ranking.</span>';
    echo "</form></td>\n";
    echo "</tr>\n";
}

echo "</table>\n";


$Conf->footer();
