<?php
// sa/sa_get_revpref.php -- HotCRP helper classes for search actions
// HotCRP is Copyright (c) 2006-2017 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

class GetRevpref_SearchAction extends SearchAction {
    private $extended;
    public function __construct($extended) {
        $this->extended = $extended;
    }
    function allow(Contact $user) {
        return $user->isPC;
    }
    function list_actions(Contact $user, $qreq, PaperList $pl, &$actions) {
        if (Navigation::page() === "reviewprefs")
            $actions[] = [$this->extended ? -99 : -100, $this->subname, null, $this->extended ? "Preference file with abstracts" : "Preference file"];
    }
    function run(Contact $user, $qreq, $ssel) {
        // maybe download preferences for someone else
        $Rev = $user;
        if (($cid = cvtint($qreq->reviewer)) > 0 && $user->privChair) {
            if (!($Rev = $user->conf->user_by_id($cid)))
                return Conf::msg_error("No such reviewer");
        }
        if (!$Rev->isPC)
            return self::EPERM;

        $result = $Rev->paper_result(["paperId" => $ssel->selection(), "topics" => 1, "reviewerPreference" => 1]);
        $texts = array();
        while (($prow = PaperInfo::fetch($result, $Rev))) {
            $item = ["paper" => $prow->paperId, "title" => $prow->title];
            if ($Rev->contactId != $user->contactId)
                $item["email"] = $Rev->email;
            if ($prow->conflictType > 0)
                $item["preference"] = "conflict";
            else
                $item["preference"] = unparse_preference($prow);
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
        $fields = array_merge(["paper", "title"], $Rev->contactId != $user->contactId ? ["email"] : [], ["preference"]);
        return new Csv_SearchResult("revprefs", $fields, $ssel->reorder($texts), true);
    }
}

class GetAllRevpref_SearchAction extends SearchAction {
    function allow(Contact $user) {
        return $user->is_manager();
    }
    function list_actions(Contact $user, $qreq, PaperList $pl, &$actions) {
        $actions[] = [2060, $this->subname, "Review assignments", "PC review preferences"];
    }
    function run(Contact $user, $qreq, $ssel) {
        $result = $user->paper_result(["paperId" => $ssel->selection(), "allReviewerPreference" => 1, "allConflictType" => 1, "topics" => 1]);
        $texts = array();
        $pcm = $user->conf->pc_members();
        $has_conflict = $has_expertise = $has_topic_score = false;
        while (($prow = PaperInfo::fetch($result, $user))) {
            if (!$user->can_administer($prow, true))
                continue;
            $conflicts = $prow->conflicts();
            foreach ($pcm as $cid => $p) {
                $pref = $prow->reviewer_preference($p);
                $cflt = get($conflicts, $cid);
                $tv = $prow->topicIds ? $prow->topic_interest_score($p) : 0;
                if ($pref || $cflt || $tv) {
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

SearchAction::register("get", "revpref", SiteLoader::API_GET | SiteLoader::API_PAPER, new GetRevpref_SearchAction(false));
SearchAction::register("get", "revprefx", SiteLoader::API_GET | SiteLoader::API_PAPER, new GetRevpref_SearchAction(true));
SearchAction::register("get", "allrevpref", SiteLoader::API_GET | SiteLoader::API_PAPER, new GetAllRevpref_SearchAction);
