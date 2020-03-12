<?php
// api_alltags.php -- HotCRP tag completion API call
// Copyright (c) 2008-2020 Eddie Kohler; see LICENSE.

class AllTags_API {
    static function run(Contact $user) {
        if (!$user->isPC) {
            return ["ok" => false, "error" => "Permission error.", "tags" => []];
        } else if ($user->conf->check_track_view_sensitivity()
                   || (!$user->conf->tag_seeall
                       && ($user->privChair
                           ? $user->conf->has_any_manager()
                           : $user->is_manager()
                             || $user->conf->check_track_sensitivity(Track::HIDDENTAG)))) {
            return self::hard_alltags_api($user);
        } else {
            return self::easy_alltags_api($user);
        }
    }

    static private function strip($tag, Contact $user, PaperInfo $prow = null) {
        $twiddle = strpos($tag, "~");
        if ($twiddle === false
            || ($twiddle === 0 && $tag[1] === "~" && $user->allow_administer($prow))) {
            return $tag;
        } else if ($twiddle > 0 && substr($tag, 0, $twiddle) == $user->contactId) {
            return substr($tag, $twiddle);
        } else {
            return false;
        }
    }

    static private function easy_alltags_api(Contact $user) {
        $dt = $user->conf->tags();
        $hidden = false;
        $q = "select distinct tag from PaperTag join Paper using (paperId)";
        $qwhere = ["timeSubmitted>0"];
        if (!$user->privChair) {
            if (!$user->conf->tag_seeall) {
                $q .= " left join PaperConflict on (PaperConflict.paperId=Paper.paperId and PaperConflict.contactId={$user->contactId})";
                $qwhere[] = "coalesce(conflictType,0)<=0";
            }
            $hidden = $dt->has_hidden;
        }
        $tags = [];
        $result = $user->conf->qe($q . " where " . join(" and ", $qwhere));
        while ($result && ($row = $result->fetch_row())) {
            if (($tag = self::strip($row[0], $user))
                && (!$hidden || !$dt->is_hidden($tag))) {
                $tags[] = $tag;
            }
        }
        Dbl::free($result);
        return ["ok" => true, "tags" => $dt->sort($tags)];
    }

    static private function hard_alltags_api(Contact $user) {
        $tags = [];
        foreach ($user->paper_set(["minimal" => true, "finalized" => true, "tags" => "require"]) as $prow) {
            if ($user->can_view_paper($prow)) {
                foreach (TagInfo::split_unpack($prow->all_tags_text()) as $ti) {
                    $lt = strtolower($ti[0]);
                    if (!isset($tags[$lt])
                        && ($tag = self::strip($ti[0], $user, $prow))
                        && $user->can_view_tag($prow, $tag)) {
                        $tags[$lt] = $tag;
                    }
                }
            }
        }
        return ["ok" => true, "tags" => $user->conf->tags()->sort(array_values($tags))];
    }
}
