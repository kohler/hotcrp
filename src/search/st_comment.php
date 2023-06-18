<?php
// search/st_comment.php -- HotCRP helper class for searching for papers
// Copyright (c) 2006-2023 Eddie Kohler; see LICENSE.

class Comment_SearchTerm extends SearchTerm {
    /** @var Contact */
    private $user;
    /** @var ContactCountMatcher */
    private $csm;
    /** @var ?TagSearchMatcher */
    private $tags;
    /** @var int */
    private $type_mask;
    /** @var int */
    private $type_value = 0;
    /** @var bool */
    private $only_author = false;
    /** @var ?int */
    private $commentRound;

    /** @param ?TagSearchMatcher $tags */
    function __construct(Contact $user, ContactCountMatcher $csm, $tags, $kwdef) {
        parent::__construct("cmt");
        $this->user = $user;
        $this->csm = $csm;
        $this->tags = $tags;
        $this->type_mask = CommentInfo::CT_DRAFT;
        if (!$kwdef->response || !$kwdef->comment) {
            $this->type_mask |= CommentInfo::CT_RESPONSE;
            $this->type_value |= $kwdef->comment ? 0 : CommentInfo::CT_RESPONSE;
        }
        if ($kwdef->draft) {
            $this->type_value |= CommentInfo::CT_DRAFT;
        }
        $this->only_author = $kwdef->only_author;
        $this->commentRound = $kwdef->round;
    }
    static function comment_factory($keyword, XtParams $xtp, $kwfj, $m) {
        $tword = str_replace("-", "", $m[1]);
        return (object) [
            "name" => $keyword,
            "parse_function" => "Comment_SearchTerm::parse",
            "response" => $tword === "any",
            "comment" => true,
            "round" => null,
            "draft" => false,
            "only_author" => $tword === "au" || $tword === "author",
            "has" => ">0"
        ];
    }
    /** @param array{string,string,string,string} $m */
    static function response_factory($keyword, XtParams $xtp, $kwfj, $m) {
        if ($m[2] === "") {
            $round = 0;
        } else {
            if ($m[2] !== "-" && str_ends_with($m[2], "-")) {
                $m[2] = substr($m[2], 0, -1);
            }
            $rrd = $xtp->conf->response_round($m[2]);
            if (!$rrd
                && $m[1] === ""
                && preg_match('/\A(draft-?)(.*)\z/si', $m[2], $mm)) {
                $m[1] = $mm[1];
                $m[2] = $mm[2];
                $rrd = $xtp->conf->response_round($m[2]);
            }
            if (!$rrd) {
                return null;
            }
            $round = $rrd->id;
        }
        if ($m[1] !== "" && $m[3] !== "") {
            return null;
        }
        return (object) [
            "name" => $keyword,
            "parse_function" => "Comment_SearchTerm::parse",
            "response" => true,
            "comment" => false,
            "round" => $round,
            "draft" => $m[1] !== "" || $m[3] !== "",
            "only_author" => false,
            "has" => ">0"
        ];
    }
    static function parse($word, SearchWord $sword, PaperSearch $srch) {
        $a = CountMatcher::unpack_search_comparison($sword->qword);
        if (($qr = SearchTerm::make_constant(CountMatcher::comparison_tautology($a[1], $a[2])))) {
            return $qr;
        }
        $tags = $contacts = null;
        if (str_starts_with($a[0], "#")
            && !$srch->conf->pc_tag_exists(substr($a[0], 1))) {
            $tags = new TagSearchMatcher($srch->user);
            $tags->add_check_tag(substr($a[0], 1), true);
            foreach ($tags->error_ftexts() as $e) {
                $srch->lwarning($sword, $e);
            }
        } else if ($a[0] !== "") {
            $contacts = $srch->matching_uids($a[0], $sword->quoted, false);
        }
        $compar = CountMatcher::unparse_comparison($a[1], $a[2]);
        $csm = new ContactCountMatcher($compar, $contacts);
        return new Comment_SearchTerm($srch->user, $csm, $tags, $sword->kwdef);
    }
    /** @return bool */
    private function test_tags_response() {
        $t = " response#0";
        foreach ($this->user->conf->response_rounds() as $rrd) {
            $t .= " " . $rrd->tag_name() . "#0";
        }
        return $this->tags->test($t);
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
            $where[] = "commentType>=" . CommentInfo::CTVIS_AUTHOR;
        }
        if ($this->commentRound) {
            $where[] = "commentRound=" . $this->commentRound;
        }
        if ($this->csm->has_contacts()) {
            $where[] = $this->csm->contact_match_sql("contactId");
        }
        if ($this->tags && !$this->tags->test_empty()) {
            if ($this->test_tags_response()) {
                $where[] = "(commentTags is not null or commentRound!=0)";
            } else {
                $where[] = "commentTags is not null"; // conservative
            }
        }
        if (($t = $sqi->try_add_table("Comments_", ["left join", "(select paperId, count(commentId) count from PaperComment" . ($where ? " where " . join(" and ", $where) : "") . " group by paperId)"]))) {
            return "coalesce({$t}.count,0)" . $this->csm->conservative_nonnegative_comparison();
        } else {
            return "true";
        }
    }
    function test(PaperInfo $row, $xinfo) {
        $textless = $this->type_mask === (CommentInfo::CT_DRAFT | CommentInfo::CT_RESPONSE);
        $n = 0;
        foreach ($row->viewable_comment_skeletons($this->user, $textless) as $crow) {
            if ($this->csm->test_contact($crow->contactId)
                && ($crow->commentType & $this->type_mask) == $this->type_value
                && (!$this->only_author || $crow->commentType >= CommentInfo::CTVIS_AUTHOR)
                && (!$this->tags || $this->tags->test((string) $crow->viewable_tags($this->user))))
                ++$n;
        }
        return $this->csm->test($n);
    }
}
