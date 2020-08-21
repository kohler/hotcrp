<?php
// listactions/la_getreviewbase.php -- HotCRP helper classes for list actions
// Copyright (c) 2006-2020 Eddie Kohler; see LICENSE.

class GetReviewBase_ListAction extends ListAction {
    protected $isform;
    protected $iszip;
    protected $author_view;
    function __construct($isform, $iszip) {
        $this->isform = $isform;
        $this->iszip = $iszip;
    }
    /** @param list<array{string,string,?int}> $texts */
    protected function finish(Contact $user, $texts, $errors) {
        uksort($errors, "strnatcmp");

        if (empty($texts)) {
            if (empty($errors)) {
                Conf::msg_error("No papers selected.");
            } else {
                $errors = array_map("htmlspecialchars", array_keys($errors));
                Conf::msg_error(join("<br>", $errors) . "<br>Nothing to download.");
            }
            return;
        }

        $warnings = array();
        $nerrors = 0;
        foreach ($errors as $ee => $iserror) {
            $warnings[] = $ee;
            if ($iserror) {
                $nerrors++;
            }
        }
        if ($nerrors) {
            array_unshift($warnings, "Some " . ($this->isform ? "review forms" : "reviews") . " are missing:");
        }

        $rfname = $this->author_view ? "aureview" : "review";
        if (!$this->iszip) {
            $rfname .= count($texts) === 1 ? $texts[0][0] : "s";
        }

        if ($this->isform) {
            $header = $user->conf->review_form()->textFormHeader(count($texts) > 1 && !$this->iszip);
        } else {
            $header = "";
        }

        if (!$this->iszip) {
            $text = $header;
            if (!empty($warnings) && $this->isform) {
                foreach ($warnings as $w) {
                    $text .= prefix_word_wrap("==-== ", $w, "==-== ");
                }
                $text .= "\n";
            } else if (!empty($warnings)) {
                $text .= join("\n", $warnings) . "\n\n";
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
            foreach ($warnings as $w) {
                $zip->add_error_html($w);
            }
            $zip->download();
            exit;
        }
    }
}
