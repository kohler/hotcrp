<?php
// search/st_comment.php -- HotCRP helper class for searching for papers
// Copyright (c) 2006-2020 Eddie Kohler; see LICENSE.

class Comment_SearchTerm extends SearchTerm {
    /** @var Contact */
    private $user;
    /** @var ContactCountMatcher */
    private $csm;
    /** @var ?TagSearchMatcher */
    private $tags;
    /** @var int */
    private $type_mask = COMMENTTYPE_DRAFT;
    /** @var int */
    private $type_value = 0;
    /** @var bool */
    private $only_author = false;
    private $commentRound;

    /** @param ?TagSearchMatcher $tags */
    function __construct(Contact $user, ContactCountMatcher $csm, $tags, $kwdef) {
        parent::__construct("cmt");
        $this->user = $user;
        $this->csm = $csm;
        $this->tags = $tags;
        if (!$kwdef->response || !$kwdef->comment) {
            $this->type_mask |= COMMENTTYPE_RESPONSE;
            $this->type_value |= $kwdef->comment ? 0 : COMMENTTYPE_RESPONSE;
        }
        if ($kwdef->draft) {
            $this->type_value |= COMMENTTYPE_DRAFT;
        }
        $this->only_author = $kwdef->only_author;
        $this->commentRound = $kwdef->round;
    }
    static function comment_factory($keyword, Contact $user, $kwfj, $m) {
        $tword = str_replace("-", "", $m[1]);
        return (object) [
            "name" => $keyword,
            "parse_callback" => "Comment_SearchTerm::parse",
            "response" => $tword === "any",
            "comment" => true,
            "round" => null,
            "draft" => false,
            "only_author" => $tword === "au" || $tword === "author",
            "has" => ">0"
        ];
    }
    static function response_factory($keyword, Contact $user, $kwfj, $m) {
        $round = $user->conf->resp_round_number($m[2]);
        if ($round === false
            && $m[1] === ""
            && preg_match('/\A(draft-?)(.*)\z/i', $m[2], $mm)) {
            $m[1] = $mm[1];
            $m[2] = $mm[2];
            $round = $user->conf->resp_round_number($m[2]);
        }
        if ($round === false || ($m[1] && $m[3])) {
            return null;
        }
        return (object) [
            "name" => $keyword,
            "parse_callback" => "Comment_SearchTerm::parse",
            "response" => true,
            "comment" => false,
            "round" => $round,
            "draft" => $m[1] || $m[3],
            "only_author" => false,
            "has" => ">0"
        ];
    }
    static function parse($word, SearchWord $sword, PaperSearch $srch) {
        $m = PaperSearch::unpack_comparison($word, $sword->quoted);
        if (($qr = PaperSearch::check_tautology($m[1]))) {
            return $qr;
        }
        $tags = $contacts = null;
        if (str_starts_with($m[0], "#")
            && !$srch->conf->pc_tag_exists(substr($m[0], 1))) {
            $tags = new TagSearchMatcher($srch->user);
            $tags->add_check_tag(substr($m[0], 1), true);
            foreach ($tags->error_texts() as $e) {
                $srch->warn($e);
            }
        } else if ($m[0] !== "") {
            $contacts = $srch->matching_uids($m[0], $sword->quoted, false);
        }
        $csm = new ContactCountMatcher($m[1], $contacts);
        return new Comment_SearchTerm($srch->user, $csm, $tags, $sword->kwdef);
    }
    function sqlexpr(SearchQueryInfo $sqi) {
        if (!isset($sqi->columns["commentSkeletonInfo"])) {
            $sqi->add_column("commentSkeletonInfo", "coalesce((select group_concat(commentId, ';', contactId, ';', commentType, ';', commentRound, ';', coalesce(commentTags,'') separator '|') from PaperComment where paperId=Paper.paperId), '')");
        }

        $where = [];
        if ($this->type_mask) {
            $where[] = "(commentType&{$this->type_mask})={$this->type_value}";
        }
        if ($this->only_author) {
            $where[] = "commentType>=" . COMMENTTYPE_AUTHOR;
        }
        if ($this->commentRound) {
            $where[] = "commentRound=" . $this->commentRound;
        }
        if ($this->csm->has_contacts()) {
            $where[] = $this->csm->contact_match_sql("contactId");
        }
        if ($this->tags && !$this->tags->test_empty()) {
            $where[] = "commentTags is not null"; // conservative
        }
        $thistab = "Comments_" . count($sqi->tables);
        $sqi->add_table($thistab, ["left join", "(select paperId, count(commentId) count from PaperComment" . ($where ? " where " . join(" and ", $where) : "") . " group by paperId)"]);
        return "coalesce($thistab.count,0)" . $this->csm->conservative_nonnegative_countexpr();
    }
    function test(PaperInfo $row, $rrow) {
        $textless = $this->type_mask === (COMMENTTYPE_DRAFT | COMMENTTYPE_RESPONSE);
        $n = 0;
        foreach ($row->viewable_comment_skeletons($this->user, $textless) as $crow) {
            if ($this->csm->test_contact($crow->contactId)
                && ($crow->commentType & $this->type_mask) == $this->type_value
                && (!$this->only_author || $crow->commentType >= COMMENTTYPE_AUTHOR)
                && (!$this->tags || $this->tags->test((string) $crow->viewable_tags($this->user))))
                ++$n;
        }
        return $this->csm->test($n);
    }
}
