<?php
// api_alltags.php -- HotCRP tag completion API call
// Copyright (c) 2008-2023 Eddie Kohler; see LICENSE.

class AllTags_API {
    static function run(Contact $user) {
        if (!$user->isPC) {
            return ["ok" => false, "error" => "Permission error", "tags" => []];
        } else if ($user->conf->check_track_view_sensitivity()
                   || (!$user->conf->tag_seeall
                       && ($user->privChair
                           ? $user->conf->has_any_manager()
                           : $user->is_manager()
                             || $user->conf->check_track_sensitivity(Track::HIDDENTAG)))
                   || ($user->can_view_some_incomplete()
                       && !$user->can_view_all_incomplete())) {
            return self::hard_alltags_api($user);
        } else {
            return self::easy_alltags_api($user);
        }
    }

    /** @param string $tag
     * @return ?string */
    static private function strip($tag, Contact $user, PaperInfo $prow = null) {
        $twiddle = strpos($tag, "~");
        if ($twiddle === false
            || ($twiddle === 0
                && $tag[1] === "~"
                && ($prow ? $user->allow_administer($prow) : $user->privChair))) {
            return $tag;
        } else if ($twiddle > 0
                   && substr($tag, 0, $twiddle) == $user->contactId) {
            return substr($tag, $twiddle);
        } else {
            return null;
        }
    }

    static private function easy_alltags_api(Contact $user) {
        $q = "select distinct tag from PaperTag join Paper using (paperId)";
        $qwhere = [];
        if ($user->can_view_all_incomplete()) {
            $qwhere[] = "timeWithdrawn<=0";
        } else {
            $qwhere[] = "timeSubmitted>0";
        }
        if (!$user->privChair && !$user->conf->tag_seeall) {
            $q .= " left join PaperConflict on (PaperConflict.paperId=Paper.paperId and PaperConflict.contactId={$user->contactId})";
            $qwhere[] = "coalesce(conflictType,0)<=" . CONFLICT_MAXUNCONFLICTED;
        }
        $dt = $user->conf->tags();
        $hidden = !$user->privChair && $dt->has(TagInfo::TF_HIDDEN);

        $tags = [];
        $result = $user->conf->qe($q . " where " . join(" and ", $qwhere));
        while ($result && ($row = $result->fetch_row())) {
            if (($tag = self::strip($row[0], $user))
                && (!$hidden || !$dt->is_hidden($tag))) {
                $tags[] = $tag;
            }
        }
        Dbl::free($result);

        return self::finish_alltags_api($tags, $dt, $user);
    }

    static private function hard_alltags_api(Contact $user) {
        $args = ["minimal" => true, "tags" => "require"];
        if ($user->can_view_some_incomplete()) {
            $args["active"] = true;
        } else {
            $args["finalized"] = true;
        }
        $tags = [];
        foreach ($user->paper_set($args) as $prow) {
            if ($user->can_view_paper($prow)) {
                foreach (Tagger::split_unpack($prow->all_tags_text()) as $ti) {
                    $lt = strtolower($ti[0]);
                    if (!isset($tags[$lt])
                        && ($tag = self::strip($ti[0], $user, $prow))
                        && $user->can_view_tag($prow, $tag)) {
                        $tags[$lt] = $tag;
                    }
                }
            }
        }
        return self::finish_alltags_api(array_values($tags), $user->conf->tags(), $user);
    }

    static private function finish_alltags_api($tags, TagMap $dt, Contact $user) {
        $tags = $dt->sort_array($tags);
        $j = ["ok" => true, "tags" => $tags];
        if ($dt->has(TagInfo::TF_AUTOMATIC | ($user->privChair ? TagInfo::TF_SITEWIDE : TagInfo::TF_READONLY))) {
            $readonly = $sitewide = [];
            foreach ($tags as $tag) {
                if (($tag[0] !== "~" || $tag[1] === "~")
                    && ($ti = $dt->find($tag))) {
                    if ($ti->is(TagInfo::TF_AUTOMATIC)
                        || (!$user->privChair && $ti->is(TagInfo::TF_READONLY))) {
                        $readonly[strtolower($tag)] = true;
                    }
                    if ($user->privChair && $ti->is(TagInfo::TF_SITEWIDE)) {
                        $sitewide[strtolower($tag)] = true;
                    }
                }
            }
            if (!empty($readonly)) {
                $j["readonly_tagmap"] = $readonly;
            }
            if (!empty($sitewide)) {
                $j["sitewide_tagmap"] = $sitewide;
            }
        }
        return $j;
    }
}
