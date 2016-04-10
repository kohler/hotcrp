<?php
// sa/sa_get_revpref.php -- HotCRP helper classes for search actions
// HotCRP is Copyright (c) 2006-2016 Eddie Kohler and Regents of the UC
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
        global $Conf, $Opt;
        // maybe download preferences for someone else
        $Rev = $user;
        if (($cid = cvtint($qreq->reviewer)) > 0 && $user->privChair) {
            if (!($Rev = Contact::find_by_id($cid)))
                return Conf::msg_error("No such reviewer");
        }
        if (!$Rev->isPC)
            return self::EPERM;

        $q = $Conf->paperQuery($Rev, array("paperId" => $ssel->selection(), "topics" => 1, "reviewerPreference" => 1));
        $result = Dbl::qe_raw($q);
        $texts = array();
        while (($prow = PaperInfo::fetch($result, $Rev))) {
            $t = $prow->paperId . "," . CsvGenerator::quote($prow->title);
            if ($prow->conflictType > 0)
                $t .= ",conflict";
            else
                $t .= "," . unparse_preference($prow);
            $t .= "\n";
            if ($this->extended) {
                if ($Rev->can_view_authors($prow, false))
                    $t .= prefix_word_wrap("#  Authors: ", $prow->pretty_text_author_list(), "#           ");
                $t .= prefix_word_wrap("# Abstract: ", rtrim($prow->abstract), "#           ");
                if ($prow->topicIds != "")
                    $t .= prefix_word_wrap("#   Topics: ", $prow->unparse_topics_text(), "#           ");
                $t .= "\n";
            }
            defappend($texts[$prow->paperId], $t);
        }

        downloadCSV(join("", $ssel->reorder($texts)), ["paper", "title", "preference"], "revprefs");
    }
}

class GetAllRevpref_SearchAction extends SearchAction {
    function allow(Contact $user) {
        return $user->is_manager();
    }
    function list_actions(Contact $user, $qreq, PaperList $pl, &$actions) {
        $actions[] = [360, $this->subname, "Review assignments", "PC review preferences"];
    }
    function run(Contact $user, $qreq, $ssel) {
        global $Conf, $Opt;
        $q = $Conf->paperQuery($user, array("paperId" => $ssel->selection(), "allReviewerPreference" => 1, "allConflictType" => 1, "topics" => 1));
        $result = Dbl::qe_raw($q);
        $texts = array();
        $pcm = pcMembers();
        $has_conflict = $has_expertise = $has_topic_score = false;
        while (($prow = PaperInfo::fetch($result, $user))) {
            if (!$user->can_administer($prow, true))
                continue;
            $conflicts = $prow->conflicts();
            foreach ($pcm as $cid => $p) {
                $pref = $prow->reviewer_preference($p);
                $conf = get($conflicts, $cid);
                $tv = $prow->topicIds ? $prow->topic_interest_score($p) : 0;
                if ($pref || $conf || $tv) {
                    $texts[$prow->paperId][] = array("paper" => $prow->paperId, "title" => $prow->title, "first" => $p->firstName, "last" => $p->lastName, "email" => $p->email,
                                "preference" => $pref[0] ? : "",
                                "expertise" => unparse_expertise($pref[1]),
                                "topic_score" => $tv ? : "",
                                "conflict" => ($conf ? "conflict" : ""));
                    $has_conflict = $has_conflict || $conf;
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
        downloadCSV($ssel->reorder($texts), $headers, "allprefs", ["selection" => true]);
    }
}

SearchActions::register("get", "revpref", SiteLoader::API_GET | SiteLoader::API_PAPER, new GetRevpref_SearchAction(false));
SearchActions::register("get", "revprefx", SiteLoader::API_GET | SiteLoader::API_PAPER, new GetRevpref_SearchAction(true));
SearchActions::register("get", "allrevpref", SiteLoader::API_GET | SiteLoader::API_PAPER, new GetAllRevpref_SearchAction);
