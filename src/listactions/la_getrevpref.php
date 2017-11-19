<?php
// listactions/la_get_revpref.php -- HotCRP helper classes for list actions
// HotCRP is Copyright (c) 2006-2017 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

class GetRevpref_ListAction extends ListAction {
    private $extended;
    function __construct($fj) {
        $this->extended = $fj->name === "get/revprefx";
    }
    function allow(Contact $user) {
        return $user->isPC;
    }
    static function render_upload(PaperList $pl) {
        return ["<b>&nbsp;preference file:</b> &nbsp;"
                . "<input class=\"want-focus want-autosubmit\" type='file' name='uploadedFile' accept='text/plain' size='20' tabindex='6' data-autosubmit-type=\"uploadpref\" />&nbsp; "
                . Ht::submit("fn", "Go", ["value" => "uploadpref", "tabindex" => 6, "data-plist-submit-all" => 1])];
    }
    static function render_set(PaperList $pl) {
        return [Ht::entry("pref", "", array("class" => "want-focus want-autosubmit", "size" => 4, "tabindex" => 6, "data-autosubmit-type" => "setpref"))
                . " &nbsp;" . Ht::submit("fn", "Go", ["value" => "setpref", "tabindex" => 6])];
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
        $has_conflict = false;
        $result = $user->paper_result(["paperId" => $ssel->selection(), "topics" => 1, "reviewerPreference" => 1]);
        $texts = array();
        foreach (PaperInfo::fetch_all($result, $user) as $prow) {
            if ($not_me && !$user->allow_administer($prow))
                continue;
            $item = ["paper" => $prow->paperId, "title" => $prow->title];
            if ($not_me)
                $item["email"] = $Rev->email;
            $item["preference"] = unparse_preference($prow->reviewer_preference($Rev));
            if ($prow->has_conflict($Rev)) {
                $item["notes"] = "conflict";
                $has_conflict = true;
            }
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
        $fields = array_merge(["paper", "title"], $not_me ? ["email"] : [], ["preference"], $has_conflict ? ["notes"] : []);
        $title = "revprefs";
        if ($not_me)
            $title .= "-" . (preg_replace('/@.*|[^\w@.]/', "", $Rev->email) ? : "user");
        return new Csv_SearchResult($title, $fields, $ssel->reorder($texts), true);
    }
}
