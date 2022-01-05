<?php
// src/pages/p_offline.php -- HotCRP offline review management page
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
            Conf::msg_error("File required.");
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
                Conf::msg_error("Internal error: cannot read file.");
                return false;
            }
            $filename = $this->qreq->file_filename("file");
        } else if (($text = $this->qreq->data)) {
            $filename = "";
        } else {
            Conf::msg_error("File required.");
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
            Conf::msg_confirm("Tags saved." . $aset->messages_div_html(true));
            $this->conf->redirect_self($this->qreq);
            return true;
        } else {
            Conf::msg_error("Changes not saved. Please fix these errors and try again." . $aset->messages_div_html(true));
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

    function render() {
        $conf = $this->conf;

        $conf->header("Offline reviewing", "offline");

        $conf->infoMsg("Use this page to download a blank review form, or to upload review forms you’ve already filled out.");
        if (!$this->user->can_clickthrough("review")) {
            echo '<div class="js-clickthrough-container">';
            PaperTable::echo_review_clickthrough();
            echo '</div>';
        }


        echo '<table id="offlineform">';

        // Review forms
        echo "<tr><td><h3>Download forms</h3>\n<div>";
        echo '<a href="', $conf->hoturl("search", "fn=get&amp;getfn=revform&amp;q=&amp;t=r&amp;p=all"), '">Your reviews</a><br>', "\n";
        if ($this->user->has_outstanding_review()) {
            echo '<a href="', $conf->hoturl("search", "fn=get&amp;getfn=revform&amp;q=&amp;t=rout&amp;p=all"), '">Your incomplete reviews</a><br>', "\n";
        }
        echo '<a href="', $conf->hoturl("offline", "download=1"), '">Blank form</a></div>
<hr class="g">
<span class="hint"><strong>Tip:</strong> Use <a href="', $conf->hoturl("search", "q="), '">Search</a> &gt; Download to choose individual papers.</span>', "\n";
        echo "</td>\n";

        $pastDeadline = !$conf->time_review(null, $this->user->isPC, true);
        $dldisabled = $pastDeadline && !$this->user->privChair ? " disabled" : "";

        echo "<td><h3>Upload filled-out forms</h3>\n",
            Ht::form($conf->hoturl("=offline", "upload=1")),
            Ht::hidden("postnonempty", 1),
            '<input type="file" name="file" accept="text/plain" size="30"', $dldisabled, '>&nbsp; ',
            Ht::submit("Go", ["disabled" => !!$dldisabled]);
        if ($pastDeadline && $this->user->privChair) {
            echo '<label class="checki"><span class="checkc">', Ht::checkbox("override"), '</span>Override deadlines</label>';
        }
        echo '<br><span class="hint"><strong>Tip:</strong> You may upload a file containing several forms.</span>';
        echo "</form></td>\n";
        echo "</tr>\n";


        // Ranks
        if ($conf->setting("tag_rank")) {
            $ranktag = $conf->setting_data("tag_rank");
            echo '<tr><td><hr class="g"></td></tr>', "\n",
                "<tr><td><h3>Download ranking file</h3>\n<div>";
            echo "<a href=\"", $conf->hoturl("search", "fn=get&amp;getfn=rank&amp;tag=%7E$ranktag&amp;q=&amp;t=r&amp;p=all"), "\">Your reviews</a>";
            if ($this->user->isPC) {
                echo "<br />\n<a href=\"", $conf->hoturl("search", "fn=get&amp;getfn=rank&amp;tag=%7E$ranktag&amp;q=&amp;t=s&amp;p=all"), "\">All submitted papers</a>";
            }
            echo "</div></td>\n";

            echo "<td><h3>Upload ranking file</h3>\n",
                Ht::form($conf->hoturl("=offline", "setrank=1&amp;tag=%7E$ranktag")),
                Ht::hidden("upload", 1),
                '<input type="file" name="file" accept="text/plain" size="30"', $dldisabled, '>&nbsp; ',
                Ht::submit("Go", array("disabled" => !!$dldisabled));
            if ($pastDeadline && $this->user->privChair) {
            echo '<label class="checki"><span class="checkc">', Ht::checkbox("override"), '</span>Override deadlines</label>';
            }
            echo '<br><span class="hint"><strong>Tip:</strong> Use “<a href="', $conf->hoturl("search", "q=" . urlencode("editsort:#~$ranktag")), '">editsort:#~', $ranktag, '</a>” to drag and drop your ranking.</span>';
            echo '<br><span class="hint"><strong>Tip:</strong> “<a href="', $conf->hoturl("search", "q=order:%7E$ranktag"), '">order:~', $ranktag, '</a>” searches by your ranking.</span>';
            echo "</form></td>\n";
            echo "</tr>\n";
        }

        echo "</table>\n";

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
        $op->render();
    }
}
