<?php
// listactions/la_getabstracts.php -- HotCRP helper classes for list actions
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class GetAbstracts_ListAction extends ListAction {
    const WIDTH = 96;

    static function render(PaperInfo $prow, Contact $user) {
        $n = prefix_word_wrap("", "Submission #{$prow->paperId}: {$prow->title}", 0, self::WIDTH);
        $text = $n . str_repeat("=", min(self::WIDTH, strlen($n) - 1)) . "\n\n";

        $ctx = (new RenderContext(FieldRender::CFTEXT | FieldRender::CFDECORATE, $user))
            ->set_width(self::WIDTH);
        foreach ($user->conf->options()->page_fields($prow) as $o) {
            if (!$o->on_page()
                || !$user->allow_view_option($prow, $o)
                || // of intrinsics, only author, abstract, topics
                   ($o->id <= 0 && $o->id !== -1001 && $o->id !== -1004 && $o->id !== -1005)
                || $o->has_document()
                || !($ov = $prow->option($o))
                || !$o->value_present($ov)
                || ($t = $o->text($ctx, $ov) ?? "") === "") {
                continue;
            }
            $vt = $o->value_title($ov);
            $title = prefix_word_wrap("", $vt, 0, self::WIDTH);
            $text .= $title
                . str_repeat("-", min(self::WIDTH, grapheme_strlen($vt)))
                . "\n" . rtrim($t) . "\n\n";
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
