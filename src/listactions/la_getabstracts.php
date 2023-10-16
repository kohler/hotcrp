<?php
// listactions/la_getabstracts.php -- HotCRP helper classes for list actions
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class GetAbstracts_ListAction extends ListAction {
    const WIDTH = 96;
    /** @param FieldRender $fr
     * @param PaperInfo $prow
     * @param Contact $user
     * @param PaperOption $o */
    private static function render_abstract($fr, $prow, $user, $o) {
        $fr->value = $prow->abstract();
        $fr->value_format = $prow->abstract_format();
    }
    /** @param FieldRender $fr
     * @param PaperInfo $prow
     * @param Contact $user
     * @param PaperOption $o */
    private static function render_authors($fr, $prow, $user, $o) {
        if ($user->can_view_authors($prow)
            && ($alist = $prow->author_list())) {
            $fr->title = $o->title(new FmtArg("count", count($alist)));
            $fr->set_text("");
            foreach ($alist as $i => $au) {
                $marker = ($i || count($alist) > 1 ? ($i + 1) . ". " : "");
                $fr->value .= prefix_word_wrap($marker, $au->name(NAME_E|NAME_A), strlen($marker), self::WIDTH);
            }
        }
    }
    /** @param FieldRender $fr
     * @param PaperInfo $prow
     * @param Contact $user
     * @param PaperOption $o */
    private static function render_topics($fr, $prow, $user, $o) {
        if (($tlist = $prow->topic_map())) {
            $fr->title = $o->title(new FmtArg("count", count($tlist)));
            $fr->set_text("");
            foreach ($tlist as $t) {
                $fr->value .= prefix_word_wrap("* ", $t, 2, self::WIDTH);
            }
        }
    }
    static function render(PaperInfo $prow, Contact $user) {
        $n = prefix_word_wrap("", "Submission #{$prow->paperId}: {$prow->title}", 0, self::WIDTH);
        $text = $n . str_repeat("=", min(self::WIDTH, strlen($n) - 1)) . "\n\n";

        $fr = new FieldRender(FieldRender::CFTEXT, $user);
        foreach ($user->conf->options()->page_fields($prow) as $o) {
            if (($o->id <= 0 || $user->allow_view_option($prow, $o))
                && $o->on_page()) {
                $fr->clear();
                if ($o->id === -1004) {
                    self::render_abstract($fr, $prow, $user, $o);
                } else if ($o->id === -1001) {
                    self::render_authors($fr, $prow, $user, $o);
                } else if ($o->id === -1005) {
                    self::render_topics($fr, $prow, $user, $o);
                } else if ($o->id > 0
                           && ($ov = $prow->option($o))) {
                    $o->render($fr, $ov);
                }
                if (!$fr->is_empty()) {
                    if ($fr->title === null) {
                        $fr->title = $o->title();
                    }
                    $title = prefix_word_wrap("", $fr->title, 0, self::WIDTH);
                    $text .= $title
                        . str_repeat("-", min(self::WIDTH, strlen($title) - 1))
                        . "\n" . rtrim($fr->value) . "\n\n";
                }
            }
        }

        return $text . "\n";
    }
    function run(Contact $user, Qrequest $qreq, SearchSelection $ssel) {
        $texts = [];
        $lastpid = null;
        $ml = [];
        foreach ($ssel->paper_set($user, ["topics" => 1]) as $prow) {
            if (($whyNot = $user->perm_view_paper($prow))) {
                array_push($ml, ...$whyNot->message_list(null, 2));
            } else {
                $texts[] = $this->render($prow, $user);
                $lastpid = $prow->paperId;
            }
        }
        if (!empty($ml)) {
            $user->conf->feedback_msg($ml);
        }
        if (!empty($texts)) {
            $filename = "abstract" . (count($texts) === 1 ? $lastpid : "s");
            return $user->conf->make_csvg($filename, CsvGenerator::TYPE_STRING)
                ->add_string(join("", $texts));
        }
    }
}
