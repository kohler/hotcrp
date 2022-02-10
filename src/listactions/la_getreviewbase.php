<?php
// listactions/la_getreviewbase.php -- HotCRP helper classes for list actions
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class GetReviewBase_ListAction extends ListAction {
    protected $isform;
    protected $iszip;
    protected $author_view;
    function __construct($isform, $iszip) {
        $this->isform = $isform;
        $this->iszip = $iszip;
    }
    /** @param list<array{string,string,?int}> $texts
     * @param MessageSet $ms */
    protected function finish(Contact $user, $texts, $ms) {
        if (empty($texts)) {
            $user->conf->feedback_msg(
                new MessageItem(null, "Nothing to download", MessageSet::MARKED_NOTE),
                $ms->message_list()
            );
            return;
        }

        if ($ms->has_error()) {
            $ms->prepend_msg($this->isform ? "<0>Some review forms are missing." : "<0>Some reviews are missing.", MessageSet::MARKED_NOTE);
        }

        $rfname = $this->author_view ? "aureview" : "review";
        if (!$this->iszip) {
            $rfname .= count($texts) === 1 ? $texts[0][0] : "s";
        }

        if ($this->isform) {
            $header = $user->conf->review_form()->text_form_header(count($texts) > 1 && !$this->iszip);
        } else {
            $header = "";
        }

        if (!$this->iszip) {
            $text = $header;
            if ($ms->has_message() && $this->isform) {
                foreach ($ms->message_list() as $mi) {
                    $text .= prefix_word_wrap("==-== ", $mi->message_as(0), "==-== ");
                }
                $text .= "\n";
            } else if ($ms->has_message()) {
                $text .= $ms->full_feedback_text() . "\n";
            }
            foreach ($texts as $pt) {
                $text .= $pt[1];
            }
            return $user->conf->make_csvg($rfname, CsvGenerator::TYPE_STRING)
                ->set_inline(false)->add_string($text);
        } else {
            $zip = new DocumentInfoSet($user->conf->download_prefix . "reviews.zip");
            foreach ($texts as $pt) {
                $zip->add_string_as($header . $pt[1], $user->conf->download_prefix . $rfname . $pt[0] . ".txt", null, $pt[2]);
            }
            foreach ($ms->message_list() as $mi) {
                $zip->message_set()->append_item($mi);
            }
            $zip->download();
            exit;
        }
    }
}
