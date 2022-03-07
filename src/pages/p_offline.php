<?php
// pages/p_offline.php -- HotCRP offline review management page
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class Offline_Page {
    /** @var Conf */
    public $conf;
    /** @var Contact */
    public $user;
    /** @var Qrequest */
    public $qreq;

    function __construct(Contact $user, Qrequest $qreq) {
        $this->conf = $user->conf;
        $this->user = $user;
        $this->qreq = $qreq;
    }

    function handle_download() {
        $rf = $this->conf->review_form();
        $this->conf->make_csvg("review", CsvGenerator::TYPE_STRING)
            ->set_inline(false)
            ->add_string($rf->text_form_header(false)
                . $rf->text_form(null, null, $this->user, null) . "\n")
            ->emit();
    }

    /** @return bool */
    function handle_upload() {
        if (!$this->qreq->has_file("file")) {
            $this->conf->error_msg("<0>File upload required");
            Ht::error_at("file");
            return false;
        }
        $rf = $this->conf->review_form();
        $tf = ReviewValues::make_text($rf, $this->qreq->file_contents("file"),
                                      $this->qreq->file_filename("file"));
        while ($tf->parse_text($this->qreq->override)) {
            $tf->check_and_save($this->user, null, null);
        }
        $tf->report();
        $this->conf->redirect_self($this->qreq);
        return true;
    }

    /** @return bool */
    function handle_tag_indexes() {
        $filename = null;
        if ($this->qreq->upload && $this->qreq->has_file("file")) {
            if (($text = $this->qreq->file_contents("file")) === false) {
                $this->conf->error_msg("<0>Internal error: cannot read uploaded file");
                return false;
            }
            $filename = $this->qreq->file_filename("file");
        } else if (($text = $this->qreq->data)) {
            $filename = "";
        } else {
            $this->conf->error_msg("<0>File upload required");
            Ht::error_at("file");
            return false;
        }

        $trp = new TagRankParser($this->user);
        $tagger = new Tagger($this->user);
        if ($this->qreq->tag
            && ($tag = $tagger->check(trim($this->qreq->tag), Tagger::NOVALUE))) {
            $trp->set_tag($tag);
        }
        $aset = $trp->parse_assignment_set($text, $filename);
        if ($aset->execute()) {
            $aset->prepend_msg("<0>Tag changes saved", MessageSet::SUCCESS);
            $this->conf->feedback_msg($aset->message_list());
            $this->conf->redirect_self($this->qreq);
            return true;
        } else {
            $aset->prepend_msg("<0>Changes not saved; please correct these errors and try again", MessageSet::ERROR);
            $this->conf->feedback_msg($aset->message_list());
            return false;
        }
    }

    function handle_request() {
        if ($this->qreq->download || $this->qreq->downloadForm /* XXX */) {
            $this->handle_download();
            return true;
        }
        if (($this->qreq->upload || $this->qreq->uploadForm /* XXX */)
            && $this->qreq->valid_post()) {
            return $this->handle_upload();
        }
        if (($this->qreq->setvote || $this->qreq->setrank)
            && $this->qreq->valid_post()
            && $this->user->is_reviewer()) {
            return $this->handle_tag_indexes();
        }
    }

    function print() {
        $conf = $this->conf;
        $conf->header("Offline reviewing", "offline");

        echo '<p>Use this page to download review forms, or to upload review forms you’ve already filled out.</p>';
        if (!$this->user->can_clickthrough("review")) {
            echo '<div class="js-clickthrough-container">';
            PaperTable::print_review_clickthrough();
            echo '</div>';
        }

        // Review forms
        echo '<div class="f-eqcol">';
        echo '<fieldset class="f-i"><legend>Download forms</legend>',
            '<ul class="x mb-2">',
            '<li><a href="', $conf->hoturl("search", "fn=get&amp;getfn=revform&amp;q=&amp;t=r&amp;p=all"), '">Your reviews</a></li>';
        if ($this->user->has_outstanding_review()) {
            echo '<li><a href="', $conf->hoturl("search", "fn=get&amp;getfn=revform&amp;q=&amp;t=rout&amp;p=all"), '">Your incomplete reviews</a></li>';
        }
        echo '<li><a href="', $conf->hoturl("offline", "download=1"), '">Blank form</a></li>',
            '</ul>
<div class="f-h"><strong>Tip:</strong> Use <a href="', $conf->hoturl("search", "q="), '">Search</a> &gt; Download to choose individual papers.</div>',
            "</fieldset>";

        $pastDeadline = !$conf->time_review(null, $this->user->isPC, true);
        $dldisabled = $pastDeadline && !$this->user->privChair ? " disabled" : "";

        echo '<fieldset class="f-i" form="offlineform"><legend><label for="uploader">Upload filled-out forms</label></legend>',
            Ht::form($conf->hoturl("=offline", "upload=1"), ["id" => "offlineform"]),
            Ht::hidden("postnonempty", 1),
            '<input id="uploader" type="file" name="file" accept="text/plain" size="30"', $dldisabled, '>&nbsp; ',
            Ht::submit("Go", ["disabled" => !!$dldisabled]);
        if ($pastDeadline && $this->user->privChair) {
            echo '<label class="checki"><span class="checkc">', Ht::checkbox("override"), '</span>Override deadlines</label>';
        }
        echo '<div class="f-h"><strong>Tip:</strong> You may upload a file containing several forms.</div>';
        echo "</form></fieldset></div>";

        // Ranks
        if ($conf->setting("tag_rank")) {
            $ranktag = $conf->setting_data("tag_rank");
            echo '<div class="f-eqcol">',
                '<fieldset class="f-i"><legend>Download ranking file</legend>',
                '<ul class="x mb-2">',
                '<li><a href="', $conf->hoturl("search", "fn=get&amp;getfn=rank&amp;tag=%7E{$ranktag}&amp;q=&amp;t=r&amp;p=all"), '">Your reviews</a></li>';
            if ($this->user->isPC) {
                echo "<li><a href=\"", $conf->hoturl("search", "fn=get&amp;getfn=rank&amp;tag=%7E$ranktag&amp;q=&amp;t=s&amp;p=all"), "\">All submitted papers</a></li>";
            }
            echo '</ul></fieldset>', "\n";

            echo '<fieldset class="f-i" form="upload', $ranktag, 'form"><legend><label for="rank', $ranktag, 'uploader">Upload ranking file</label></legend>',
                Ht::form($conf->hoturl("=offline", "setrank=1&amp;tag=%7E$ranktag"), ["id" => "upload{$ranktag}form"]),
                Ht::hidden("upload", 1),
                '<input id="rank', $ranktag, 'uploader" type="file" name="file" accept="text/plain" size="30"', $dldisabled, '>&nbsp; ',
                Ht::submit("Go", array("disabled" => !!$dldisabled));
            if ($pastDeadline && $this->user->privChair) {
                echo '<label class="checki"><span class="checkc">', Ht::checkbox("override"), '</span>Override deadlines</label>';
            }
            echo '<div class="f-h"><strong>Tip:</strong> Search “<a href="', $conf->hoturl("search", "q=" . urlencode("editsort:#~$ranktag")), '">editsort:#~', $ranktag, '</a>” to drag and drop your ranking.</span><br>',
                '“<a href="', $conf->hoturl("search", "q=order:%23%7E$ranktag"), '">order:#~', $ranktag, '</a>” searches by your ranking.</div>',
                '</form></fieldset></div>';
        }

        $conf->footer();
    }

    static function go(Contact $user, Qrequest $qreq) {
        if (!$user->email) {
            $user->escape();
        } else if (!$user->is_reviewer()) {
            Multiconference::fail(403, ["title" => "Offline reviewing"], "You aren’t registered as a reviewer or PC member for this conference.");
        } else if (!$user->conf->time_review_open() && !$user->privChair) {
            Multiconference::fail(403, ["title" => "Offline reviewing"], "The site is not open for review.");
        }

        if ($qreq->post && $qreq->post_empty()) {
            $user->conf->post_missing_msg();
        }
        $op = new Offline_Page($user, $qreq);
        if ($op->handle_request()) {
            return;
        }
        $op->print();
    }
}
