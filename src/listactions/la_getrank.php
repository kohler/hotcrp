<?php
// listactions/la_getrank.php -- HotCRP helper classes for list actions
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class GetRank_ListAction extends ListAction {
    function allow(Contact $user, Qrequest $qreq) {
        return $user->conf->setting("tag_rank") && $user->is_reviewer();
    }
    function run(Contact $user, Qrequest $qreq, SearchSelection $ssel) {
        $settingrank = $user->conf->setting("tag_rank") && $qreq->tag == "~" . $user->conf->setting_data("tag_rank");
        if (!$user->isPC && !($user->is_reviewer() && $settingrank)) {
            return self::eperm();
        }
        $tagger = new Tagger($user);
        if (($tag = $tagger->check($qreq->tag, Tagger::NOVALUE | Tagger::NOCHAIR))) {
            $real = $null = "";
            $pset = $ssel->paper_set($user, ["tags" => true]);
            $pset->sort_by(function ($p1, $p2) use ($tag) {
                $tv1 = $p1->tag_value($tag);
                $tv2 = $p2->tag_value($tag);
                if ($tv1 === null && $tv2 === null) {
                    return $p1->paperId - $p2->paperId;
                } else if ($tv1 === null || $tv2 === null) {
                    return $tv1 === null ? 1 : -1;
                } else if ($tv1 != $tv2) {
                    return $tv1 < $tv2 ? -1 : 1;
                } else {
                    return $p1->paperId - $p2->paperId;
                }
            });
            $lastIndex = null;
            foreach ($pset as $prow) {
                if ($user->can_edit_tag($prow, $tag, null, 1)) {
                    $csvt = CsvGenerator::quote($prow->title);
                    $tv = $prow->tag_value($tag);
                    $tail = ",$prow->paperId,$csvt\n";
                    if ($tv === null || $lastIndex === null) {
                        $delta = $tv;
                    } else {
                        $delta = $tv - $lastIndex;
                    }
                    if ($tv === null) {
                        $null .= "X" . $tail;
                    } else if ($delta == 1) {
                        $real .= $tail;
                    } else if ($delta == 0) {
                        $real .= "=" . $tail;
                    } else if ($delta == 2 || $delta == 3 || $delta == 4 || $delta == 5) {
                        $real .= str_repeat(">", $delta) . $tail;
                    } else {
                        $real .= $tv . $tail;
                    }
                    $lastIndex = $tv;
                }
            }
            return $user->conf->make_csvg("rank", CsvGenerator::TYPE_STRING)
                ->set_inline(false)
                ->add_string("action,paper,title
tag," . CsvGenerator::quote(trim($qreq->tag)) . "

# Edit the rank order by rearranging the following lines.

# The first line has the highest rank. Lines starting with \"#\" are
# ignored. Unranked papers appear at the end in lines starting with
# \"X\", sorted by overall merit. Create a rank by removing the \"X\"s and
# rearranging the lines. A line starting with \"=\" marks a paper with the
# same rank as the preceding paper. Lines starting with \">>\", \">>>\",
# and so forth indicate rank gaps between papers. When you are done,
# upload the file here:\n"
                    . "# " . $user->conf->hoturl_raw("offline", null, Conf::HOTURL_ABSOLUTE) . "\n\n"
                    . $real . ($real === "" ? "" : "\n") . $null);
        } else {
            return MessageItem::error("<5>" . $tagger->error_html());
        }
    }
}
