<?php
// listactions/la_getrank.php -- HotCRP helper classes for list actions
// Copyright (c) 2006-2020 Eddie Kohler; see LICENSE.

class GetRank_ListAction extends ListAction {
    function allow(Contact $user, Qrequest $qreq) {
        return $user->conf->setting("tag_rank") && $user->is_reviewer();
    }
    function run(Contact $user, $qreq, $ssel) {
        $settingrank = $user->conf->setting("tag_rank") && $qreq->tag == "~" . $user->conf->setting_data("tag_rank");
        if (!$user->isPC && !($user->is_reviewer() && $settingrank))
            return self::EPERM;
        $tagger = new Tagger($user);
        if (($tag = $tagger->check($qreq->tag, Tagger::NOVALUE | Tagger::NOCHAIR))) {
            $real = "";
            $null = "\n";
            foreach ($user->paper_set($ssel, ["tagIndex" => $tag, "order" => "order by tagIndex, PaperReview.overAllMerit desc, Paper.paperId"]) as $prow)
                if ($user->can_change_tag($prow, $tag, null, 1)) {
                    $csvt = CsvGenerator::quote($prow->title);
                    if ($prow->tagIndex === null)
                        $null .= "X,$prow->paperId,$csvt\n";
                    else if ($real === "" || $lastIndex == $prow->tagIndex - 1)
                        $real .= ",$prow->paperId,$csvt\n";
                    else if ($lastIndex == $prow->tagIndex)
                        $real .= "=,$prow->paperId,$csvt\n";
                    else
                        $real .= str_pad("", min($prow->tagIndex - $lastIndex, 5), ">") . ",$prow->paperId,$csvt\n";
                    $lastIndex = $prow->tagIndex;
                }
            $text = "# Edit the rank order by rearranging this file's lines.

# The first line has the highest rank. Lines starting with \"#\" are
# ignored. Unranked papers appear at the end in lines starting with
# \"X\", sorted by overall merit. Create a rank by removing the \"X\"s and
# rearranging the lines. A line starting with \"=\" marks a paper with the
# same rank as the preceding paper. Lines starting with \">>\", \">>>\",
# and so forth indicate rank gaps between papers. When you are done,
# upload the file at\n"
                . "#   " . $user->conf->hoturl_absolute("offline", null, Conf::HOTURL_RAW) . "\n\n"
                . "Tag: " . trim($qreq->tag) . "\n"
                . "\n"
                . $real . $null;
            downloadText($text, "rank");
        } else
            Conf::msg_error($tagger->error_html);
    }
}
