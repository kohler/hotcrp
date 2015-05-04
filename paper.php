<?php
// paper.php -- HotCRP paper view and edit page
// HotCRP is Copyright (c) 2006-2015 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

$Error = array();
require_once("src/initweb.php");
require_once("src/papertable.php");
if ($Me->is_empty())
    $Me->escape();
if (@$_REQUEST["update"] && check_post() && !$Me->has_database_account()
    && $Me->can_start_paper())
    $Me = $Me->activate_database_account();
$useRequest = isset($_REQUEST["after_login"]);
foreach (array("emailNote", "reason") as $x)
    if (isset($_REQUEST[$x]) && $_REQUEST[$x] == "Optional explanation")
        unset($_REQUEST[$x]);
if (!isset($_REQUEST["p"]) && !isset($_REQUEST["paperId"])
    && preg_match(',\A(?:new|\d+)\z,i', Navigation::path_component(0))) {
    $_REQUEST["p"] = Navigation::path_component(0);
    if (!isset($_REQUEST["m"]) && ($x = Navigation::path_component(1)))
        $_REQUEST["m"] = $x;
    if (@$_REQUEST["m"] === "api" && !isset($_REQUEST["fn"])
        && ($x = Navigation::path_component(2)))
        $_REQUEST["fn"] = $x;
} else if (!Navigation::path() && @$_REQUEST["p"] && ctype_digit($_REQUEST["p"])
           && !check_post())
    go(selfHref());


// header
function confHeader() {
    global $newPaper, $prow, $paperTable, $Conf, $Error;
    if ($paperTable)
        $mode = $paperTable->mode;
    else
        $mode = "p";
    if (!$prow)
        $title = ($newPaper ? "New Paper" : "Paper View");
    else
        $title = "Paper #$prow->paperId";

    $Conf->header($title, "paper_" . ($mode == "edit" ? "edit" : "view"), actionBar($mode, $prow), false);
}

function errorMsgExit($msg) {
    global $Conf;
    if (@$_REQUEST["ajax"]) {
        $Conf->errorMsg($msg);
        $Conf->ajaxExit(array("ok" => false));
    } else {
        confHeader();
        $Conf->footerScript("shortcut().add()");
        $Conf->errorMsgExit($msg);
    }
}


// collect paper ID: either a number or "new"
$newPaper = (defval($_REQUEST, "p") == "new"
             || defval($_REQUEST, "paperId") == "new");


// general error messages
if (isset($_REQUEST["post"]) && $_REQUEST["post"] && !count($_POST))
    $Conf->errorMsg("It looks like you tried to upload a gigantic file, larger than I can accept. Any changes were lost.");


// grab paper row
function loadRows() {
    global $prow, $CurrentProw, $Error;
    $CurrentProw = $prow = PaperTable::paperRow($whyNot);
    if (!$prow)
        errorMsgExit(whyNotText($whyNot, "view"));
    if (isset($Error["paperId"]) && $Error["paperId"] != $prow->paperId)
        $Error = array();
}
$prow = null;
if (!$newPaper)
    loadRows();


// paper actions
function handle_api() {
    global $Conf, $Me, $prow;
    if (!check_post() || !@$_REQUEST["fn"])
        $Conf->ajaxExit(array("ok" => false));
    if (!$prow)
        $Conf->ajaxExit(array("ok" => false, "error" => "No such paper."));
    if ($_REQUEST["fn"] == "setdecision")
        $Conf->ajaxExit(PaperActions::set_decision($prow));
    else if ($_REQUEST["fn"] == "setlead")
        PaperActions::set_lead($prow, @$_REQUEST["lead"], $Me, true);
    else if ($_REQUEST["fn"] == "setshepherd")
        PaperActions::set_shepherd($prow, @$_REQUEST["shepherd"], $Me, true);
    else if ($_REQUEST["fn"] == "setmanager")
        PaperActions::set_manager($prow, @$_REQUEST["manager"], $Me, true);
    $Conf->ajaxExit(array("ok" => false, "error" => "Unknown action."));
}
if (@$_REQUEST["m"] === "api")
    handle_api();
if (isset($_REQUEST["setrevpref"]) && $prow && check_post()) {
    PaperActions::setReviewPreference($prow);
    loadRows();
}
if (isset($_REQUEST["setfollow"]) && $prow && check_post()) {
    PaperActions::set_follow($prow);
    loadRows();
}


// check paper action
if (isset($_REQUEST["checkformat"]) && $prow && $Conf->setting("sub_banal")) {
    $ajax = defval($_REQUEST, "ajax", 0);
    $cf = new CheckFormat();
    $dt = HotCRPDocument::parse_dtype(@$_REQUEST["dt"]);
    if ($dt === null)
        $dt = @$_REQUEST["final"] ? DTYPE_FINAL : DTYPE_SUBMISSION;
    if ($Conf->setting("sub_banal$dt"))
        $format = $Conf->setting_data("sub_banal$dt", "");
    else
        $format = $Conf->setting_data("sub_banal", "");
    $status = $cf->analyzePaper($prow->paperId, $dt, $format);

    // chairs get a hint message about multiple checking
    if ($Me->privChair) {
        $nbanal = $Conf->session("nbanal", 0) + 1;
        $Conf->save_session("nbanal", $nbanal);
        if ($nbanal >= 3 && $nbanal <= 6)
            $cf->msg("info", "To run the format checker for many papers, use Download &gt; Format check on the <a href='" . hoturl("search", "q=") . "'>search page</a>.");
    }

    $cf->reportMessages();
    if ($ajax)
        $Conf->ajaxExit(array("status" => $status), true);
}


// withdraw and revive actions
if (isset($_REQUEST["withdraw"]) && !$newPaper && check_post()) {
    if (!($whyNot = $Me->perm_withdraw_paper($prow))) {
        $reason = defval($_REQUEST, "reason", "");
        if ($reason == "" && $Me->privChair && defval($_REQUEST, "doemail") > 0)
            $reason = defval($_REQUEST, "emailNote", "");
        Dbl::qe("update Paper set timeWithdrawn=$Now, timeSubmitted=if(timeSubmitted>0,-100,0), withdrawReason=? where paperId=$prow->paperId", $reason != "" ? $reason : null);
        $result = Dbl::qe("update PaperReview set reviewNeedsSubmit=0 where paperId=$prow->paperId");
        $numreviews = $result ? $result->affected_rows : false;
        $Conf->updatePapersubSetting(false);
        loadRows();

        // email contact authors themselves
        if (!$Me->privChair || defval($_REQUEST, "doemail") > 0)
            HotCRPMailer::send_contacts(($prow->conflictType >= CONFLICT_AUTHOR ? "@authorwithdraw" : "@adminwithdraw"),
                                        $prow, array("reason" => $reason, "infoNames" => 1));

        // email reviewers
        if (($numreviews > 0 && $Conf->time_review_open())
            || $prow->num_reviews_assigned() > 0)
            HotCRPMailer::send_reviewers("@withdrawreviewer", $prow, array("reason" => $reason));

        // remove voting tags so people don't have phantom votes
        if (TagInfo::has_vote()) {
            $q = array();
            foreach (TagInfo::vote_tags() as $t => $v)
                $q[] = "tag='" . sqlq($t) . "' or tag like '%~" . sqlq_for_like($t) . "'";
            Dbl::qe_raw("delete from PaperTag where paperId=$prow->paperId and (" . join(" or ", $q) . ")");
        }

        $Me->log_activity("Withdrew", $prow->paperId);
        redirectSelf();
    } else
        $Conf->errorMsg(whyNotText($whyNot, "withdraw"));
}
if (isset($_REQUEST["revive"]) && !$newPaper && check_post()) {
    if (!($whyNot = $Me->perm_revive_paper($prow))) {
        Dbl::qe("update Paper set timeWithdrawn=0, timeSubmitted=if(timeSubmitted=-100,$Now,0), withdrawReason=null where paperId=$prow->paperId");
        Dbl::qe_raw("update PaperReview set reviewNeedsSubmit=1 where paperId=$prow->paperId and reviewSubmitted is null");
        Dbl::qe_raw("update PaperReview join PaperReview as Req on (Req.paperId=$prow->paperId and Req.requestedBy=PaperReview.contactId and Req.reviewType=" . REVIEW_EXTERNAL . ") set PaperReview.reviewNeedsSubmit=-1 where PaperReview.paperId=$prow->paperId and PaperReview.reviewSubmitted is null and PaperReview.reviewType=" . REVIEW_SECONDARY);
        Dbl::qe_raw("update PaperReview join PaperReview as Req on (Req.paperId=$prow->paperId and Req.requestedBy=PaperReview.contactId and Req.reviewType=" . REVIEW_EXTERNAL . " and Req.reviewSubmitted>0) set PaperReview.reviewNeedsSubmit=0 where PaperReview.paperId=$prow->paperId and PaperReview.reviewSubmitted is null and PaperReview.reviewType=" . REVIEW_SECONDARY);
        $Conf->updatePapersubSetting(true);
        loadRows();
        $Me->log_activity("Revived", $prow->paperId);
        redirectSelf();
    } else
        $Conf->errorMsg(whyNotText($whyNot, "revive"));
}


// extract a “JSON” paper frm the posted form
function paper_json_clone($pj) {
    if (!$pj)
        return (object) array();
    $pj = clone $pj;
    if (@$pj->options)
        $pj->options = clone $pj->options;
    return $pj;
}

function request_to_json($opj, $action) {
    global $Conf, $Me;
    $pj = paper_json_clone($opj);

    // Title, abstract, collaborators
    foreach (array("title", "abstract", "collaborators") as $k)
        if (isset($_POST[$k]))
            $pj->$k = $_POST[$k];

    // Authors
    $authors = array();
    foreach ($_POST as $k => $v)
        if (preg_match('/^au(name|email|aff)(\d+)$/', $k, $m)
            && ($v = simplify_whitespace($v)) !== "") {
            $au = $authors[$m[2]] = (@$authors[$m[2]] ? : (object) array());
            $x = ($m[1] == "aff" ? "affiliation" : $m[1]);
            $au->$x = $v;
        }
    if (count($authors)) {
        ksort($authors, SORT_NUMERIC);
        $pj->authors = array_values($authors);
    }

    // Contacts
    if (@$_POST["setcontacts"] || @$_POST["has_contacts"])
        request_contacts_to_json($pj);
    else if (!$opj)
        $pj->contacts = (object) array($Me->email => true);

    // Status
    if ($action === "submit")
        $pj->submitted = true;
    else if ($action === "final")
        $pj->final_submitted = true;
    else
        $pj->submitted = false;

    // Paper upload
    if (fileUploaded($_FILES["paperUpload"])) {
        if ($action === "final")
            $pj->final = DocumentHelper::file_upload_json("paperUpload");
        else if ($action === "update" || $action === "submit")
            $pj->submission = DocumentHelper::file_upload_json("paperUpload");
    }

    // Blindness
    if ($action !== "final" && $Conf->subBlindOptional())
        $pj->nonblind = !@$_POST["blind"];

    // Topics
    if (@$_POST["has_topics"]) {
        $pj->topics = (object) array();
        foreach ($Conf->topic_map() as $tid => $tname)
            if (+@$_POST["top$tid"] > 0)
                $pj->topics->$tname = true;
    }

    // Options
    foreach (PaperOption::option_list() as $o)
        if (@$_POST["has_opt$o->id"])
            request_option_to_json($pj, $o, $action);

    // PC conflicts
    if ($Conf->setting("sub_pcconf")
        && ($action !== "final" || $Me->privChair)
        && @$_POST["has_pcconf"]) {
        $cmax = $Me->privChair ? CONFLICT_CHAIRMARK : CONFLICT_MAXAUTHORMARK;
        $pj->pc_conflicts = (object) array();
        foreach (pcMembers() as $pcid => $pc) {
            $ctype = cvtint(defval($_POST, "pcc$pcid", 0), 0);
            $ctype = max(min($ctype, $cmax), 0);
            if ($ctype) {
                $email = $pc->email;
                $pj->pc_conflicts->$email = Conflict::$type_names[$ctype];
            }
        }
    }

    return $pj;
}

function request_contacts_to_json($pj) {
    $pj->contacts = (object) array();
    foreach ($_POST as $k => $v)
        if (str_starts_with($k, "contact_")) {
            $email = html_id_decode(substr($k, 8));
            $pj->contacts->$email = true;
        } else if (str_starts_with($k, "newcontact_email")
                   && trim($v) !== ""
                   && trim($v) !== "Email") {
            $suffix = substr($k, strlen("newcontact_email"));
            $email = trim($_REQUEST[$k]);
            $name = defval($_REQUEST, "newcontact_name$suffix", "");
            if ($name === "Name")
                $name = "";
            $pj->contacts->$email = (object) array("email" => $email,
                                                   "name" => $name);
        }
}

function request_option_to_json($pj, $o, $action) {
    global $Conf, $Me;
    if (@$o->final && $action !== "final")
        return;
    $pj->options = @$pj->options ? : (object) array();

    $okey = $o->abbr;
    $oreq = "opt$o->id";
    if ($o->type == "checkbox")
        $pj->options->$okey = @($_POST[$oreq] > 0);
    else if ($o->type == "selector"
             || $o->type == "radio"
             || $o->type == "numeric") {
        $v = trim((string) @$_POST[$oreq]);
        if ($v !== "" && ctype_digit($v))
            $pj->options->$okey = (int) $v;
        else
            $pj->options->$okey = $v;
    } else if ($o->type == "text") {
        $pj->options->$okey = trim((string) @$_POST[$oreq]);
    } else if ($o->type == "attachments") {
        $attachments = defval($pj->options, $okey, array());
        $opfx = $oreq . "_";
        foreach ($_FILES as $k => $v)
            if (str_starts_with($k, $opfx))
                $attachments[] = DocumentHelper::file_upload_json($k);
        for ($i = 0; $i < count($attachments); ++$i)
            if (@$attachments[$i]->docid
                && @$_POST["remove_{$oreq}_{$attachments[$i]->docid}"]) {
                array_splice($attachments, $i, 1);
                --$i;
            }
        $pj->options->$okey = $attachments;
    } else if ($o->is_document()) {
        if (fileUploaded($_FILES[$oreq]))
            $pj->options->$okey = DocumentHelper::file_upload_json($oreq);
        else if (@$_POST["remove_$oreq"])
            unset($pj->options->$okey);
    } else
        unset($pj->options->$okey);
}

function request_differences($pj, $opj, $action) {
    global $Conf, $Me;
    if (!$opj)
        return array("new" => true);
    $diffs = array();
    foreach (array("title", "abstract", "collaborators") as $k)
        if ((string) @$pj->$k !== (string) @$opj->$k)
            $diffs[$k] = true;
    if (request_authors_differ($pj, $opj))
        $diffs["authors"] = true;
    if (json_encode(@$pj->topics ? : (object) array())
        !== json_encode(@$opj->topics ? : (object) array()))
        $diffs["topics"] = true;
    $pjopt = @$pj->options ? : (object) array();
    $opjopt = @$opj->options ? : (object) array();
    foreach (PaperOption::option_list() as $o) {
        $oabbr = $o->abbr;
        if (!@$pjopt->$oabbr != !@$opjopt->$oabbr
            || (@$pjopt->$oabbr
                && json_encode($pjopt->$oabbr) !== json_encode($opjopt->$oabbr))) {
            $diffs["options"] = true;
            break;
        }
    }
    if ($Conf->subBlindOptional() && !@$pj->nonblind !== !@$opj->nonblind)
        $diffs["anonymity"] = true;
    if (json_encode(@$pj->pc_conflicts) !== json_encode(@$opj->pc_conflicts))
        $diffs["PC conflicts"] = true;
    if (fileUploaded($_FILES["paperUpload"]))
        $diffs["submission"] = true;
    return $diffs;
}

function request_authors_differ($pj, $opj) {
    if (!@$pj->authors != !@$opj->authors
        || count($pj->authors) != count($opj->authors))
        return true;
    for ($i = 0; $i < count($pj->authors); ++$i)
        if (@$pj->authors[$i]->email !== @$opj->authors[$i]->email
            || (string) @$pj->authors[$i]->affiliation !== (string) @$opj->authors[$i]->affiliation
            || Text::name_text($pj->authors[$i]) !== Text::name_text($opj->authors[$i]))
            return true;
    return false;
}

// send watch messages
function final_submit_watch_callback($prow, $minic) {
    if ($minic->can_view_paper($prow))
        HotCRPMailer::send_to($minic, "@finalsubmitnotify", $prow);
}

function update_paper($pj, $opj, $action, $diffs) {
    global $Conf, $Me, $Opt, $OK, $Error, $prow;
    // XXX lock tables

    $ps = new PaperStatus;
    $saved = $ps->save($pj, $opj);

    if (!$saved && !$prow && fileUploaded($_FILES["paperUpload"]))
        $ps->set_error_html("paper", "<strong>The submission you tried to upload was ignored.</strong>");
    if (!@$pj->collaborators && $Conf->setting("sub_collab")) {
        $field = ($Conf->setting("sub_pcconf") ? "Other conflicts" : "Potential conflicts");
        $ps->set_warning_html("collaborators", "Please enter the authors’ potential conflicts in the $field field. If none of the authors have potential conflicts, just enter “None”.");
    }

    $Error = $ps->error_fields();

    if (!$saved) {
        $emsg = $ps->error_html();
        $Conf->errorMsg("There were errors in saving your paper. Please fix them and try again." . (count($emsg) ? "<ul><li>" . join("</li><li>", $emsg) . "</li></ul>" : ""));
        return false;
    }

    // note differences in contacts
    $contacts = @$pj->contacts ? array_keys((array) $pj->contacts) : array();
    $ocontacts = $opj ? array_keys((array) $opj->contacts) : array();
    sort($contacts);
    sort($ocontacts);
    if (json_encode($contacts) !== json_encode($ocontacts))
        $diffs["contacts"] = true;

    // submit paper if no error so far
    $_REQUEST["paperId"] = $_GET["paperId"] = $pj->id;
    loadRows();
    if ($action === "final") {
        $submitkey = "timeFinalSubmitted";
        $storekey = "finalPaperStorageId";
    } else {
        $submitkey = "timeSubmitted";
        $storekey = "paperStorageId";
    }
    $wasSubmitted = $opj && @$opj->submitted;
    if (@$pj->submitted || $Conf->can_pc_see_all_submissions())
        $Conf->updatePapersubSetting(true);
    if ($wasSubmitted != @$pj->submitted)
        $diffs["submission"] = 1;

    // confirmation message
    if ($action == "final") {
        $actiontext = "Updated final version of";
        $template = "@submitfinalpaper";
    } else if (@$pj->submitted && !$wasSubmitted) {
        $actiontext = "Submitted";
        $template = "@submitpaper";
    } else if (!$opj) {
        $actiontext = "Registered new";
        $template = "@registerpaper";
    } else {
        $actiontext = "Updated";
        $template = "@updatepaper";
    }

    // additional information
    $notes = array();
    if ($action == "final") {
        if ($prow->$submitkey === null || $prow->$submitkey <= 0)
            $notes[] = "The final version has not yet been submitted.";
        $deadline = $Conf->printableTimeSetting("final_soft", "span");
        if ($deadline != "N/A" && $Conf->deadlinesAfter("final_soft"))
            $notes[] = "<strong>The deadline for submitting final versions was $deadline.</strong>";
        else if ($deadline != "N/A")
            $notes[] = "You have until $deadline to make further changes.";
    } else {
        if (@$pj->submitted)
            $notes[] = "You will receive email when reviews are available.";
        else if ($prow->size == 0 && !defval($Opt, "noPapers"))
            $notes[] = "The paper has not yet been uploaded.";
        else if ($Conf->setting("sub_freeze") > 0)
            $notes[] = "The paper has not yet been submitted.";
        else
            $notes[] = "The paper is marked as not ready for review.";
        $deadline = $Conf->printableTimeSetting("sub_update", "span");
        if ($deadline != "N/A" && ($prow->timeSubmitted <= 0 || $Conf->setting("sub_freeze") <= 0))
            $notes[] = "Further updates are allowed until $deadline.";
        $deadline = $Conf->printableTimeSetting("sub_sub", "span");
        if ($deadline != "N/A" && $prow->timeSubmitted <= 0)
            $notes[] = "<strong>If the paper "
                . ($Conf->setting("sub_freeze") > 0 ? "has not been submitted"
                   : "is not ready for review")
                . " by $deadline, it will not be considered.</strong>";
    }
    $notes = join(" ", $notes);

    $webnotes = "";
    if (count($ps->error_html()))
        $webnotes .= " <ul><li>" . join("</li><li>", $ps->error_html()) . "</li></ul>";

    if (!count($diffs)) {
        $Conf->warnMsg("There were no changes to paper #$prow->paperId. " . $notes . $webnotes);
        return true;
    }

    // HTML confirmation
    if ($prow->$submitkey > 0)
        $Conf->confirmMsg($actiontext . " paper #$prow->paperId. " . $notes . $webnotes);
    else
        $Conf->warnMsg($actiontext . " paper #$prow->paperId. " . $notes . $webnotes);

    // mail confirmation to all contact authors
    if (!$Me->privChair || defval($_REQUEST, "doemail") > 0) {
        $options = array("infoNames" => 1);
        if ($Me->privChair && $prow->conflictType < CONFLICT_AUTHOR)
            $options["adminupdate"] = true;
        if ($Me->privChair && isset($_REQUEST["emailNote"]))
            $options["reason"] = $_REQUEST["emailNote"];
        if ($notes !== "")
            $options["notes"] = preg_replace(",</?(?:span.*?|strong)>,", "", $notes) . "\n\n";
        HotCRPMailer::send_contacts($template, $prow, $options);
    }

    // other mail confirmations
    if ($action == "final" && $OK && !count($Error))
        genericWatch($prow, WATCHTYPE_FINAL_SUBMIT, "final_submit_watch_callback", $Me);

    $Me->log_activity($actiontext, $prow->paperId);
    return true;
}


if ((@$_POST["update"] || @$_POST["submitfinal"])
    && check_post()) {
    // choose action
    $action = "update";
    if (@$_POST["submitfinal"] && !$newPaper)
        $action = "final";
    else if (@$_POST["submitpaper"]
             && (($prow && $prow->size > 0)
                 || fileUploaded($_FILES["paperUpload"])
                 || @$Opt["noPapers"]))
        $action = "submit";

    $ps = new PaperStatus;
    $opj = $prow ? $ps->row_to_json($prow, array("docids" => true)) : null;
    $pj = request_to_json($opj, $action);
    $diffs = request_differences($pj, $opj, $action);

    // check deadlines
    if ($newPaper)
        // we know that can_start_paper implies can_finalize_paper
        $whyNot = $Me->perm_start_paper();
    else if ($action == "final")
        $whyNot = $Me->perm_submit_final_paper($prow);
    else {
        $whyNot = $Me->perm_update_paper($prow);
        if ($whyNot && $action == "submit" && !count($diffs))
            $whyNot = $Me->perm_finalize_paper($prow);
    }

    // actually update
    if (!$whyNot) {
        if (update_paper($pj, $opj, $action, $diffs))
            redirectSelf(array("p" => $prow->paperId, "m" => "edit"));
    } else {
        if ($action == "final")
            $adescription = "submit final version for";
        else
            $adescription = $prow ? "update" : "register";
        $Conf->errorMsg(whyNotText($whyNot, $adescription));
    }

    // If we get here, we failed to update.
    // Use the request unless the request failed because updates
    // aren't allowed.
    $useRequest = !$whyNot || !$prow
        || !($action != "final" && !$Me->can_update_paper($prow)
             && $Me->can_finalize_paper($prow));
}

if (isset($_POST["updatecontacts"]) && check_post() && $prow) {
    if ($Me->can_administer($prow) || $Me->actAuthorView($prow)) {
        $ps = new PaperStatus;
        $opj = $ps->row_to_json($prow, array("docids" => true));
        $pj = paper_json_clone($opj);
        request_contacts_to_json($pj);
        if ($ps->save($pj, $opj))
            redirectSelf();
        else {
            $Conf->errorMsg("<ul><li>" . join("</li><li>", $ps->error_html()) . "</li></ul>");
            $Error = $ps->error_fields();
        }
    } else
        $Conf->errorMsg(whyNotText(array("permission" => 1), "update contacts for"));

    // use request?
    $useRequest = true;
}


// delete action
if (isset($_REQUEST["delete"]) && check_post()) {
    if ($newPaper)
        $Conf->confirmMsg("Paper deleted.");
    else if (!$Me->privChair)
        $Conf->errorMsg("Only the program chairs can permanently delete papers. Authors can withdraw papers, which is effectively the same.");
    else {
        // mail first, before contact info goes away
        if (!$Me->privChair || defval($_REQUEST, "doemail") > 0)
            HotCRPMailer::send_contacts("@deletepaper", $prow, array("reason" => defval($_REQUEST, "emailNote", ""), "infoNames" => 1));
        // XXX email self?

        $error = false;
        $tables = array('Paper', 'PaperStorage', 'PaperComment', 'PaperConflict', 'PaperReview', 'PaperReviewArchive', 'PaperReviewPreference', 'PaperTopic', 'PaperTag', "PaperOption");
        foreach ($tables as $table) {
            $result = Dbl::qe_raw("delete from $table where paperId=$prow->paperId");
            $error |= ($result == false);
        }
        if (!$error) {
            $Conf->confirmMsg("Paper #$prow->paperId deleted.");
            $Conf->updatePapersubSetting(false);
            if ($prow->outcome > 0)
                $Conf->updatePaperaccSetting(false);
            $Me->log_activity("Deleted", $prow->paperId);
        }

        $prow = null;
        errorMsgExit("");
    }
}


// paper actions
if (isset($_REQUEST["settags"]) && check_post()) {
    PaperActions::setTags($prow);
    loadRows();
}
if (isset($_REQUEST["tagreport"]) && check_post()) {
    $treport = PaperActions::tag_report($prow);
    if (count($treport->warnings))
        $Conf->warnMsg(join("<br>", $treport->warnings));
    if (count($treport->messages))
        $Conf->infoMsg(join("<br>", $treport->messages));
    $Conf->ajaxExit(array("ok" => $treport->ok), true);
}


// correct modes
$paperTable = new PaperTable($prow);
$paperTable->resolveComments();
if ($paperTable->can_view_reviews() || $paperTable->mode == "re") {
    $paperTable->resolveReview();
    $paperTable->fixReviewMode();
}


// page header
confHeader();


// prepare paper table
if ($paperTable->mode == "edit") {
    $editable = $newPaper || $Me->can_update_paper($prow, true);
    if ($prow && $prow->outcome > 0 && $Conf->collectFinalPapers()
        && (($Conf->timeAuthorViewDecision() && $Conf->timeSubmitFinalPaper())
            || $Me->allow_administer($prow)))
        $editable = "f";
} else
    $editable = false;

if (@$Error["author"])
    $Error["authorInformation"] = true;

$paperTable->initialize($editable, $editable && $useRequest);

// produce paper table
$paperTable->paptabBegin();

if ($paperTable->mode === "re")
    $paperTable->paptabEndWithEditableReview();
else if ($paperTable->can_view_reviews())
    $paperTable->paptabEndWithReviews();
else
    $paperTable->paptabEndWithReviewMessage();

if ($paperTable->mode != "edit")
    $paperTable->paptabComments();

$Conf->footer();
