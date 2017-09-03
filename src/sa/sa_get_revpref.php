<?php
// sa/sa_get_revpref.php -- HotCRP helper classes for search actions
// HotCRP is Copyright (c) 2006-2017 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

class GetRevpref_SearchAction extends SearchAction {
    private $extended;
    function __construct($fj) {
        $this->extended = $fj->name === "get/revprefx";
    }
    function allow(Contact $user) {
        return $user->isPC;
    }
    static function render_upload(PaperList $pl) {
        return ["uploadpref", "Upload", "<b>&nbsp;preference file:</b> &nbsp;"
                . "<input class=\"want-focus\" type='file' name='uploadedFile' accept='text/plain' size='20' tabindex='6' onfocus='autosub(\"uploadpref\",this)' />&nbsp; "
                . Ht::submit("fn", "Go", ["value" => "uploadpref", "tabindex" => 6, "onclick" => "return plist_submit.call(this)", "data-plist-submit-all" => 1])];
    }
    static function render_set(PaperList $pl) {
        return ["setpref", "Set preferences", "<b>:</b> &nbsp;"
                . Ht::entry("pref", "", array("class" => "want-focus", "size" => 4, "tabindex" => 6, "onfocus" => 'autosub("setpref",this)'))
                . " &nbsp;" . Ht::submit("fn", "Go", ["value" => "setpref", "tabindex" => 6, "onclick" => "return plist_submit.call(this)"])];
    }
    function run(Contact $user, $qreq, $ssel) {
        // maybe download preferences for someone else
        $Rev = $user;
        if ($qreq->reviewer) {
            $Rev = null;
            foreach ($user->conf->pc_members() as $pcm)
                if (strcasecmp($pcm->email, $qreq->reviewer) == 0
                    || (string) $pcm->contactId === $qreq->reviewer) {
                    $Rev = $pcm;
                    break;
                }
            if (!$Rev)
                return Conf::msg_error("No such reviewer");
        }
        if (!$Rev->isPC)
            return self::EPERM;

        $not_me = $user->contactId !== $Rev->contactId;
        $result = $user->paper_result(["paperId" => $ssel->selection(), "topics" => 1, "reviewerPreference" => 1]);
        $texts = array();
        foreach (PaperInfo::fetch_all($result, $user) as $prow) {
            if ($not_me && !$user->allow_administer($prow))
                continue;
            $item = ["paper" => $prow->paperId, "title" => $prow->title];
            if ($not_me)
                $item["email"] = $Rev->email;
            if ($not_me ? $prow->has_conflict($Rev) : $prow->conflictType > 0)
                $item["preference"] = "conflict";
            else
                $item["preference"] = unparse_preference($prow->reviewer_preference($Rev));
            if ($this->extended) {
                $x = "";
                if ($Rev->can_view_authors($prow, false))
                    $x .= prefix_word_wrap(" Authors: ", $prow->pretty_text_author_list(), "          ");
                $x .= prefix_word_wrap("Abstract: ", rtrim($prow->abstract), "          ");
                if ($prow->topicIds != "")
                    $x .= prefix_word_wrap("  Topics: ", $prow->unparse_topics_text(), "          ");
                $item["__postcomment__"] = $x;
            }
            $texts[$prow->paperId][] = $item;
        }
        $fields = array_merge(["paper", "title"], $not_me ? ["email"] : [], ["preference"]);
        $title = "revprefs";
        if ($not_me)
            $title .= "-" . (preg_replace('/@.*|[^\w@.]/', "", $Rev->email) ? : "user");
        return new Csv_SearchResult($title, $fields, $ssel->reorder($texts), true);
    }
}

class GetAllRevpref_SearchAction extends SearchAction {
    function allow(Contact $user) {
        return $user->is_manager();
    }
    function run(Contact $user, $qreq, $ssel) {
        $result = $user->paper_result(["paperId" => $ssel->selection(), "allReviewerPreference" => 1, "allConflictType" => 1, "topics" => 1]);
        $texts = array();
        $pcm = $user->conf->pc_members();
        $has_conflict = $has_expertise = $has_topic_score = false;
        foreach (PaperInfo::fetch_all($result, $user) as $prow) {
            if (!$user->can_administer($prow, true))
                continue;
            $conflicts = $prow->conflicts();
            foreach ($pcm as $cid => $p) {
                $pref = $prow->reviewer_preference($p);
                $cflt = get($conflicts, $cid);
                $tv = $prow->topicIds ? $prow->topic_interest_score($p) : 0;
                if ($pref[0] !== 0 || $pref[1] !== null || $cflt || $tv) {
                    $texts[$prow->paperId][] = array("paper" => $prow->paperId, "title" => $prow->title, "first" => $p->firstName, "last" => $p->lastName, "email" => $p->email,
                                "preference" => $pref[0] ? : "",
                                "expertise" => unparse_expertise($pref[1]),
                                "topic_score" => $tv ? : "",
                                "conflict" => ($cflt ? "conflict" : ""));
                    $has_conflict = $has_conflict || $cflt;
                    $has_expertise = $has_expertise || $pref[1] !== null;
                    $has_topic_score = $has_topic_score || $tv;
                }
            }
        }

        $headers = array("paper", "title", "first", "last", "email", "preference");
        if ($has_expertise)
            $headers[] = "expertise";
        if ($has_topic_score)
            $headers[] = "topic_score";
        if ($has_conflict)
            $headers[] = "conflict";
        return new Csv_SearchResult("allprefs", $headers, $ssel->reorder($texts), true);
    }
}
