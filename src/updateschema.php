<?php
// updateschema.php -- HotCRP function for updating old schemata
// Copyright (c) 2006-2023 Eddie Kohler; see LICENSE.

class UpdateSchema {
    /** @var Conf */
    private $conf;
    /** @var bool */
    private $need_run = false;

    /** @param Conf $conf */
    function __construct($conf) {
        $this->conf = $conf;
    }

    private function save_options_setting($options_data) {
        if (empty($options_data)) {
            $this->conf->save_setting("options", null);
        } else {
            $osv = max($this->conf->setting("options") ?? 0, 1);
            $this->conf->save_setting("options", $osv, $options_data);
        }
    }

    private function v1_options_setting($options_data) {
        $options_array = [];
        foreach (get_object_vars($options_data) as $k => $v) {
            if (is_object($v)
                && isset($v->type)
                && is_string($v->type)
                && is_numeric($k)
                && (!isset($v->id) || $v->id == $k)) {
                $v->id = (int) $k;
                $options_array[] = $v;
            } else {
                return false;
            }
        }
        $this->save_options_setting($options_array);
        return $options_array;
    }

    private function v65_create_review_form() {
        $result = $this->conf->ql("select * from ReviewFormField where fieldName!='outcome'");
        if (Dbl::is_error($result)) {
            return false;
        }
        $rfj = (object) [];
        while ($result && ($row = $result->fetch_object())) {
            $field = (object) [];
            $field->name = $row->shortName;
            if (trim($row->description) != "") {
                $field->description = trim($row->description);
            }
            if ($row->sortOrder >= 0) {
                $field->position = $row->sortOrder + 1;
            }
            if ($row->rows > 3) {
                $field->display_space = (int) $row->rows;
            }
            $field->view_score = (int) $row->authorView;
            if (in_array($row->fieldName, ["overAllMerit", "technicalMerit", "novelty",
                                    "grammar", "reviewerQualification", "potential",
                                    "fixability", "interestToCommunity", "longevity",
                                    "likelyPresentation", "suitableForShort"])) {
                $field->options = [];
                if ((int) $row->levelChar > 1) {
                    $field->option_letter = (int) $row->levelChar;
                }
            }
            $fname = $row->fieldName;
            $rfj->$fname = $field;
        }

        $result = $this->conf->ql("select * from ReviewFormOptions where fieldName!='outcome' order by level asc");
        if (Dbl::is_error($result)) {
            return false;
        }
        while (($row = $result->fetch_object())) {
            $fname = $row->fieldName;
            if (isset($rfj->$fname) && isset($rfj->$fname->options)) {
                $rfj->$fname->options[$row->level - 1] = $row->description;
            }
        }

        $this->conf->save_setting("review_form", 1, $rfj);
        return true;
    }

    private function v67_create_options() {
        $result = $this->conf->ql("select * from OptionType");
        if (Dbl::is_error($result)) {
            return false;
        }
        $opsj = [];
        while (($row = $result->fetch_object())) {
            // backward compatibility with old schema versions
            if (!isset($row->optionValues)) {
                $row->optionValues = "";
            }
            if (!isset($row->type) && $row->optionValues == "\x7Fi") {
                $row->type = 2;
            } else if (!isset($row->type)) {
                $row->type = ($row->optionValues ? 1 : 0);
            }

            $opj = (object) [];
            $opj->id = $row->optionId;
            $opj->name = $row->optionName;

            if (trim($row->description) != "") {
                $opj->description = trim($row->description);
            }

            if ($row->pcView == 2) {
                $opj->view_type = "nonblind";
            } else if ($row->pcView == 0) {
                $opj->view_type = "admin";
            }

            $opj->position = (int) $row->sortOrder;
            if ($row->displayType == 1) {
                $opj->highlight = true;
            } else if ($row->displayType == 2) {
                $opj->near_submission = true;
            }

            switch ($row->type) {
            case 0:
                $opj->type = "checkbox";
                break;
            case 1:
                $opj->type = "selector";
                $opj->selector = explode("\n", rtrim($row->optionValues));
                break;
            case 2:
                $opj->type = "numeric";
                break;
            case 3:
                $opj->type = "text";
                $opj->display_space = 1;
                break;
            case 4:
                $opj->type = "pdf";
                break;
            case 5:
                $opj->type = "slides";
                break;
            case 6:
                $opj->type = "video";
                break;
            case 7:
                $opj->type = "radio";
                $opj->selector = explode("\n", rtrim($row->optionValues));
                break;
            case 8:
                $opj->type = "text";
                $opj->display_space = 5;
                break;
            case 9:
                $opj->type = "attachments";
                break;
            case 100:
                $opj->type = "pdf";
                $opj->final = true;
                break;
            case 101:
                $opj->type = "slides";
                $opj->final = true;
                break;
            case 102:
                $opj->type = "video";
                $opj->final = true;
                break;
            }

            $opsj[] = $opj;
        }

        $this->save_options_setting($opsj);
        return true;
    }

    private function v86_transfer_address() {
        $result = $this->conf->ql("select * from ContactAddress");
        while (($row = $result->fetch_object())) {
            if (($c = $this->conf->user_by_id($row->contactId))) {
                $x = (object) [];
                if ($row->addressLine1 || $row->addressLine2) {
                    $x->address = [];
                }
                foreach (["addressLine1", "addressLine2"] as $k) {
                    if ($row->$k)
                        $x->address[] = $row->$k;
                }
                foreach (["city" => "city", "state" => "state",
                          "zipCode" => "zip", "country" => "country"] as $k => $v) {
                    if ($row->$k)
                        $x->$v = $row->$k;
                }
                $c->merge_and_save_data($x);
            }
        }
        return true;
    }

    private function v90_unaccented_name() {
        if (!$this->conf->ql_ok("alter table ContactInfo add `unaccentedName` varchar(120) NOT NULL DEFAULT ''")) {
            return false;
        }

        $result = $this->conf->ql("select contactId, firstName, lastName from ContactInfo");
        if (Dbl::is_error($result)) {
            return false;
        }

        $qs = $qv = [];
        while (($x = $result->fetch_row())) {
            $qs[] = "update ContactInfo set unaccentedName=? where contactId=$x[0]";
            $qv[] = Text::name($x[1], $x[2], "", NAME_U);
        }
        Dbl::free($result);

        $q = Dbl::format_query_apply($this->conf->dblink, join(";", $qs), $qv);
        if (!$this->conf->dblink->multi_query($q)) {
            return false;
        }
        do {
            if (($result = $this->conf->dblink->store_result())) {
                $result->free();
            }
        } while ($this->conf->dblink->more_results() && $this->conf->dblink->next_result());
        return true;
    }

    private function v108_repair_comment_ordinals() {
        $ctdraftresp = 5; /* CT_DRAFT | CT_RESPONSE */
        $ctpc = 0x10000; /* CT_PCONLY */
        $ctau = 0x30000; /* CT_AUTHOR */

        // repair missing comment ordinals; reset incorrect `ordinal`s for
        // author-visible comments
        $result = $this->conf->ql_ok("select paperId, commentId from PaperComment where ordinal=0 and (commentType&{$ctdraftresp})=0 and commentType>={$ctpc} and commentType<{$ctau} order by commentId");
        while (($row = $result->fetch_row())) {
            $this->conf->ql_ok("update PaperComment,
(select coalesce(count(commentId),0) commentCount from Paper
    left join PaperComment on (PaperComment.paperId=Paper.paperId and (commentType&{$ctdraftresp})=0 and commentType>={$ctpc} and commentType<{$ctau} and commentId<{$row[1]})
    where Paper.paperId={$row[0]} group by Paper.paperId) t
set ordinal=(t.commentCount+1) where commentId={$row[1]}");
        }

        $result = $this->conf->ql_ok("select paperId, commentId from PaperComment where ordinal=0 and (commentType&{$ctdraftresp})=0 and commentType>={$ctau} order by commentId");
        while (($row = $result->fetch_row())) {
            $this->conf->ql_ok("update PaperComment,
(select coalesce(count(commentId),0) commentCount from Paper
    left join PaperComment on (PaperComment.paperId=Paper.paperId and (commentType&{$ctdraftresp})=0 and commentType>={$ctau} and commentId<{$row[1]})
    where Paper.paperId={$row[0]} group by Paper.paperId) t
set authorOrdinal=(t.commentCount+1) where commentId={$row[1]}");
        }

        $result = $this->conf->ql_ok("select paperId, commentId from PaperComment where ordinal=authorOrdinal and (commentType&{$ctdraftresp})=0 and commentType>={$ctau} order by commentId");
        while (($row = $result->fetch_row())) {
            $this->conf->ql_ok("update PaperComment,
(select coalesce(max(ordinal),0) maxOrdinal from Paper
    left join PaperComment on (PaperComment.paperId=Paper.paperId and (commentType&{$ctdraftresp})=0 and commentType>={$ctpc} and commentType<{$ctau} and commentId<{$row[1]})
    where Paper.paperId={$row[0]} group by Paper.paperId) t
set ordinal=(t.maxOrdinal+1) where commentId={$row[1]}");
        }

        $this->conf->update_schema_version(108);
    }

    private function v111_transfer_country() {
        $result = $this->conf->ql("select contactId, data from ContactInfo where `data` is not null and `data`!='{}'");
        while (($x = $result->fetch_object())) {
            $d = json_decode($x->data);
            if ($d && isset($d->country) && $d->country !== "") {
                $this->conf->ql("update ContactInfo set country=? where contactId=?", $d->country, $x->contactId);
            }
        }
        return true;
    }

    private function v112_review_word_counts() {
        $rf = new ReviewForm($this->conf, $this->conf->review_form_json());
        do {
            $n = 0;
            $result = $this->conf->ql("select * from PaperReview where reviewWordCount is null limit 32");
            $cleanf = Dbl::make_multi_ql_stager($this->conf->dblink);
            while (($rrow = $result->fetch_object())) {
                $cleanf("update PaperReview set reviewWordCount=? where paperId=? and reviewId=?", $rf->word_count($rrow), $rrow->paperId, $rrow->reviewId);
                ++$n;
            }
            Dbl::free($result);
            $cleanf(null);
        } while ($n === 32);
    }

    private function v129_bad_comment_timeDisplayed() {
        $badids = Dbl::fetch_first_columns($this->conf->dblink, "select a.commentId from PaperComment a join PaperComment b where a.paperId=b.paperId and a.commentId<b.commentId and a.timeDisplayed>b.timeDisplayed");
        return !count($badids) || $this->conf->ql_ok("update PaperComment set timeDisplayed=0 where commentId ?a", $badids);
    }

    private function drop_keys_if_exist($table, $key) {
        $indexes = Dbl::fetch_first_columns($this->conf->dblink, "select distinct index_name from information_schema.statistics where table_schema=database() and `table_name`='$table'");
        $drops = [];
        foreach (is_array($key) ? $key : [$key] as $k) {
            if (in_array($k, $indexes))
                $drops[] = ($k === "PRIMARY" ? "drop primary key" : "drop key `$k`");
        }
        if (count($drops)) {
            return $this->conf->ql_ok("alter table `$table` " . join(", ", $drops));
        } else {
            return true;
        }
    }

    /** @param string $table
     * @param string $column
     * @return ?int */
    private function check_column_exists($table, $column) {
        return Dbl::fetch_ivalue($this->conf->dblink, "select exists (select * from information_schema.columns where table_schema=database() and `table_name`='{$table}' and `column_name`='{$column}') from dual");
    }

    private function v154_mimetype_extensions() {
        $result = $this->conf->ql("select * from Mimetype where extension is null");
        if (Dbl::is_error($result)) {
            return false;
        }
        $qv = [];
        while (($row = $result->fetch_object())) {
            if (($extension = Mimetype::extension($row->mimetype)))
                $qv[] = [$row->mimetypeid, $row->mimetype, $extension];
        }
        Dbl::free($result);
        return empty($qv) || $this->conf->ql_ok("insert into Mimetype (mimetypeid, mimetype, extension) values ?v ?U on duplicate key update extension=?U(extension)", $qv);
    }

    private function v174_paper_review_tfields() {
        return $this->conf->ql_ok("alter table PaperReview add `tfields` longblob")
            && $this->conf->ql_ok("alter table PaperReview add `sfields` varbinary(2048) DEFAULT NULL");
    }

    /** @var array<non-empty-string,non-empty-string>
     * @readonly */
    static private $v175_text_field_map = [
        "paperSummary" => "t01", "commentsToAuthor" => "t02",
        "commentsToPC" => "t03", "commentsToAddress" => "t04",
        "weaknessOfPaper" => "t05", "strengthOfPaper" => "t06",
        "textField7" => "t07", "textField8" => "t08"
    ];

    private function v175_paper_review_null_main_fields() {
        $cleanf = Dbl::make_multi_ql_stager($this->conf->dblink);
        $result = $this->conf->ql("select * from PaperReview");
        while (($row = $result->fetch_assoc())) {
            $tfields = json_decode($row["tfields"] ?? "{}", true);
            foreach ($row as $k => $v) {
                if ($v !== null) {
                    $k = self::$v175_text_field_map[$k] ?? $k;
                    if (strlen($k) === 3
                        && $k[0] === "t"
                        && ctype_digit(substr($k, 1))
                        && !isset($tfields[$k])) {
                        $tfields[$k] = $v;
                    }
                }
            }
            if (!empty($tfields)) {
                $cleanf("update PaperReview set `tfields`=? where paperId=? and reviewId=?", json_encode_db($tfields), $row["paperId"], $row["reviewId"]);
            }
        }
        Dbl::free($result);
        $cleanf(null);
        $kf = array_map(function ($k) { return "$k=null"; }, array_keys(self::$v175_text_field_map));
        return $this->conf->ql_ok("update PaperReview set " . join(", ", $kf));
    }

    private function v176_paper_review_drop_main_fields() {
        $kf = array_map(function ($k) { return "$k is not null"; }, array_keys(self::$v175_text_field_map));
        if (!$this->conf->ql_ok("lock tables PaperReview write")) {
            return false;
        }
        $result = $this->conf->ql("select * from PaperReview where " . join(" or ", $kf));
        $rrow = $result->fetch_object();
        Dbl::free($result);
        if ($rrow) {
            error_log("{$this->conf->dbname}: #{$rrow->paperId}/{$rrow->reviewId}: nonnull main field cancels schema upgrade");
            $ok = false;
        } else {
            $ok = true;
            foreach (self::$v175_text_field_map as $kmain => $kjson) {
                $ok = $ok && $this->conf->ql_ok("alter table PaperReview drop column `$kmain`");
            }
        }
        $this->conf->ql("unlock tables");
        return $ok;
    }

    private function v189_split_review_request_name() {
        if (!$this->conf->ql_ok("alter table ReviewRequest add `firstName` varbinary(120) DEFAULT NULL")
            || !$this->conf->ql_ok("alter table ReviewRequest add `lastName` varbinary(120) DEFAULT NULL")
            || !$this->conf->ql_ok("lock tables ReviewRequest write")) {
            return false;
        }
        $result = $this->conf->ql("select * from ReviewRequest");
        $cleanf = Dbl::make_multi_ql_stager($this->conf->dblink);
        while (($row = $result->fetch_object())) {
            list($first, $last) = Text::split_name($row->name);
            $cleanf("update ReviewRequest set firstName=?, lastName=? where paperId=? and email=?", (string) $first === "" ? null : $first,
                       (string) $last === "" ? null : $last,
                       $row->paperId, $row->email);
        }
        Dbl::free($result);
        $cleanf(null);
        $this->conf->ql_ok("unlock tables");
        return $this->conf->ql_ok("alter table ReviewRequest drop column `name`");
    }

    private function v192_missing_sha1() {
        $result = $this->conf->ql("select * from PaperStorage where sha1='' and paper is not null and paper!='' and paperStorageId>1");
        $cleanf = Dbl::make_multi_ql_stager($this->conf->dblink);
        while (($doc = DocumentInfo::fetch($result, $this->conf))) {
            /* XXX relies on DocumentInfo understanding v191 schema */
            $hash = $doc->content_binary_hash();
            $cleanf("update PaperStorage set sha1=? where paperId=? and paperStorageId=?", $hash, $doc->paperId, $doc->paperStorageId);
            if ($doc->documentType == 0 /* DTYPE_SUBMISSION */) {
                $cleanf("update Paper set sha1=? where paperId=? and paperStorageId=? and finalPaperStorageId<=0", $hash, $doc->paperId, $doc->paperStorageId);
            } else if ($doc->documentType == -1 /* DTYPE_FINAL */) {
                $cleanf("update Paper set sha1=? where paperId=? and finalPaperStorageId=?", $hash, $doc->paperId, $doc->paperStorageId);
            }
        }
        Dbl::free($result);
        $cleanf(null);
    }

    private function v199_selector_options() {
        $oids = [];
        foreach ($this->conf->options()->universal() as $opt) {
            if ($opt instanceof Selector_PaperOption) {
                $oids[] = $opt->id;
            }
        }
        return empty($oids)
            || $this->conf->ql_ok("update PaperOption set value=value+1 where optionId?a", $oids);
    }

    private function v200_missing_review_ordinals() {
        $pids = [];
        $result = $this->conf->qe("select distinct paperId from PaperReview where reviewSubmitted>0 and reviewAuthorModified>0 and reviewOrdinal=0");
        while (($row = $result->fetch_row())) {
            $pids[] = (int) $row[0];
        }
        Dbl::free($result);
        if (empty($pids)) {
            return true;
        }
        $rf = $this->conf->review_form();
        foreach ($this->conf->paper_set(["paperId" => $pids, "tags" => true]) as $prow) {
            $prow->ensure_full_reviews();
            $next_ordinal = $next_displayed = 0;
            $update_rrows = [];
            foreach ($prow->all_reviews() as $rrow) {
                $next_ordinal = max($next_ordinal, $rrow->reviewOrdinal);
                if ($rrow->reviewOrdinal > 0) {
                    $next_displayed = max($next_displayed, $rrow->timeDisplayed);
                }
                if ($rrow->reviewSubmitted > 0
                    && $rrow->reviewModified > 0
                    && $rrow->reviewOrdinal == 0
                    && $rf->nonempty_view_score($rrow) >= VIEWSCORE_AUTHORDEC) {
                    $update_rrows[] = $rrow;
                }
            }
            assert(count($update_rrows) <= 1);
            if ($update_rrows) {
                $rrow = $update_rrows[0];
                $new_displayed = max($rrow->timeDisplayed, $next_displayed + 1);
                $this->conf->qe("update PaperReview set reviewOrdinal=?, timeDisplayed=? where paperId=? and reviewId=?", $next_ordinal + 1, $new_displayed, $prow->paperId, $rrow->reviewId);
            }
        }
        return true;
    }

    private function v224_set_review_time_displayed() {
        $pids = [];
        $result = $this->conf->qe("select distinct paperId from PaperReview where (reviewSubmitted is not null or reviewOrdinal!=0) and timeDisplayed=0");
        while (($row = $result->fetch_row())) {
            $pids[] = (int) $row[0];
        }
        Dbl::free($result);
        if (empty($pids)) {
            return true;
        }

        $cleanf = Dbl::make_multi_ql_stager($this->conf->dblink);
        foreach ($this->conf->paper_set(["paperId" => $pids]) as $prow) {
            $rrows = array_values(array_filter($prow->all_reviews(), function ($r) {
                return $r->reviewSubmitted || $r->reviewOrdinal;
            }));
            usort($rrows, function ($a, $b) {
                if ($a->timeDisplayed && $b->timeDisplayed
                    && $a->timeDisplayed != $b->timeDisplayed) {
                    return $a->timeDisplayed < $b->timeDisplayed ? -1 : 1;
                } else if ($a->reviewOrdinal && $b->reviewOrdinal) {
                    return $a->reviewOrdinal < $b->reviewOrdinal ? -1 : 1;
                } else if ($a->reviewSubmitted != $b->reviewSubmitted) {
                    if ($a->reviewSubmitted != 0 && $b->reviewSubmitted != 0) {
                        return $a->reviewSubmitted < $b->reviewSubmitted ? -1 : 1;
                    } else {
                        return $a->reviewSubmitted != 0 ? -1 : 1;
                    }
                } else {
                    return $a->reviewId < $b->reviewId ? -1 : 1;
                }
            });

            $rt = array_map(function ($r) {
                return +$r->timeDisplayed ? : +$r->reviewSubmitted ? : +$r->reviewModified;
            }, $rrows);
            $last = 0;
            foreach ($rrows as $i => $rrow) {
                if (!$rrow->timeDisplayed) {
                    $t = max($rt[$i], $last);
                    for ($j = $i + 1; $j < count($rrows); ++$j) {
                        $t = min($t, $rt[$j]);
                    }
                    $cleanf("update PaperReview set timeDisplayed=? where paperId=? and reviewId=?", $t, $prow->paperId, $rrow->reviewId);
                    $rrow->timeDisplayed = $t;
                }
                $last = +$rrow->timeDisplayed;
            }
        }
        $cleanf(null);
        return true;
    }

    private function v228_add_comment_tag_values($response_only) {
        if (!$this->conf->ql_ok("lock tables PaperComment write")) {
            return false;
        }
        $result = $this->conf->ql("select distinct commentTags from PaperComment where commentTags is not null" . ($response_only ? " and commentTags like '%response'" : ""));
        $ok = true;
        while (($row = $result->fetch_row())) {
            $rev = preg_replace('/( [^#\s]+)(?= |\z)/', "\$1#0", $row[0]);
            $ok = $ok && $this->conf->ql_ok("update PaperComment set commentTags=? where commentTags=?", $rev, $row[0]);
        }
        Dbl::free($result);
        $this->conf->ql_ok("unlock tables");
        return $ok;
    }

    private function v243_simplify_user_whitespace() {
        $cleanf = Dbl::make_multi_ql_stager($this->conf->dblink);
        $regex = Dbl::utf8($this->conf->dblink, "'  |[\\n\\r\\t]'");
        $result = $this->conf->ql_ok("select contactId, firstName, lastName, affiliation from ContactInfo where firstName regexp $regex or lastName regexp $regex or affiliation regexp $regex");
        while (($row = $result->fetch_object())) {
            $cleanf("update ContactInfo set firstName=?, lastName=?, affiliation=? where contactId=?",
                simplify_whitespace($row->firstName), simplify_whitespace($row->lastName),
                simplify_whitespace($row->affiliation), $row->contactId);
        }
        $cleanf(null);
        Dbl::free($result);
        return true;
    }

    private function v248_options_setting($options_array) {
        foreach ($options_array as $v) {
            if (is_object($v)) {
                if ($v->near_submission ?? false) {
                    $v->display = "submission";
                    unset($v->near_submission);
                } else if (($v->highlight ?? false)
                           || ($v->display ?? null) === "default") {
                    $v->display = "prominent";
                    unset($v->prominent);
                } else if (($v->display ?? null) === false) {
                    $v->display = "none";
                }
                if (!isset($v->page_position) && isset($v->display_position)) {
                    $v->page_position = $v->display_position;
                    unset($v->display_position);
                }
            }
        }
        $this->save_options_setting($options_array);
        return $options_array;
    }

    private function v251_change_default_charset() {
        foreach (["ActionLog", "Capability", "ContactInfo", "DeletedContactInfo",
            "DocumentLink", "FilteredDocument", "Formula", "Invitation",
            "InvitationLog", "MailLog", "Paper", "PaperComment",
            "PaperConflict", "PaperOption", "PaperReview", "PaperReviewPreference",
            "PaperReviewRefused", "PaperStorage", "PaperTag", "PaperTagAnno",
            "PaperTopic", "PaperWatch", "ReviewRating", "ReviewRequest",
            "Settings", "TopicArea", "TopicInterest"] as $t) {
            if (!$this->conf->ql("alter table {$t} character set utf8mb4"))
                return false;
        }
        return true;
    }

    private function v252_change_column_default_charset() {
        return $this->conf->ql("alter table ContactInfo
                modify `email` varchar(120) CHARACTER SET utf8mb4 NOT NULL,
                modify `preferredEmail` varchar(120) CHARACTER SET utf8mb4 DEFAULT NULL")
            && $this->conf->ql("alter table DeletedContactInfo
                modify `email` varchar(120) CHARACTER SET utf8mb4 NOT NULL")
            && $this->conf->ql("alter table Formula
                modify `name` varchar(200) CHARACTER SET utf8mb4 NOT NULL")
            && $this->conf->ql("alter table Invitation
                modify `email` varchar(120) CHARACTER SET utf8mb4 NOT NULL")
            && $this->conf->ql("alter table PaperReviewRefused
                modify `email` varchar(120) CHARACTER SET utf8mb4 NOT NULL")
            && $this->conf->ql("alter table PaperTag
                modify `tag` varchar(80) CHARACTER SET utf8mb4 NOT NULL")
            && $this->conf->ql("alter table PaperTagAnno
                modify `tag` varchar(80) CHARACTER SET utf8mb4 NOT NULL")
            && $this->conf->ql("alter table ReviewRequest
                modify `email` varchar(120) CHARACTER SET utf8mb4 NOT NULL");
    }

    private function v256_tokenize_review_acceptors() {
        $result = $this->conf->ql("select paperId, reviewId, contactId, `data`, reviewModified from PaperReview where `data` is not null union select paperId, refusedReviewId, contactId, `data`, timeRefused from PaperReviewRefused where `data` is not null and refusedReviewId is not null");
        $qv = [];
        while (($row = $result->fetch_row())) {
            if (($jdata = json_decode($row[3]))
                && is_object($jdata)
                && ($acceptor = $jdata->acceptor ?? null)
                && is_object($acceptor)
                && is_string($acceptor->text ?? null)) {
                $at = $acceptor->at ?? 0;
                $qv[] = [
                    5 /* REVIEWACCEPT */, $row[2], $row[0], $row[1],
                    $at,
                    $row[4] > 1 ? $row[4] : 0,
                    $at ? $at + 2592000 : Conf::$now - 2592000,
                    $at ? $at + 5184000 : Conf::$now + 2592000,
                    "hcra{$row[1]}{$acceptor->text}", null
                ];
            }
        }
        Dbl::free($result);
        if (!empty($qv)) {
            $result = $this->conf->ql("insert into Capability (capabilityType, contactId, paperId, otherId, timeCreated, timeUsed, timeInvalid, timeExpires, salt, data) values ?v", $qv);
            return !Dbl::is_error($result);
        } else {
            return true;
        }
    }

    private function v257_update_response_settings() {
        $jrl = [];
        $need = false;
        $resp_rounds = $this->conf->setting_data("resp_rounds") ?? "1";
        foreach (explode(" ", $resp_rounds) as $i => $rname) {
            $jr = [];
            $rname !== "1" && ($jr["name"] = $rname);
            $isuf = $i ? "_{$i}" : "";
            $open = $this->conf->setting("resp_open{$isuf}") ?? 0;
            $open > 0 && ($jr["open"] = $open);
            $done = $this->conf->setting("resp_done{$isuf}") ?? 0;
            $done > 0 && ($jr["done"] = $done);
            $grace = $this->conf->setting("resp_grace{$isuf}") ?? 0;
            $grace > 0 && ($jr["grace"] = $grace);
            $words = $this->conf->setting("resp_words{$isuf}") ?? 500;
            $words !== 500 && ($jr["words"] = $words);
            $search = $this->conf->setting_data("resp_search{$isuf}");
            $search !== null && ($jr["condition"] = $search);
            $instrux = $this->conf->setting_data("msg.resp_instrux_{$i}");
            $instrux !== null && ($jr["instructions"] = $instrux);
            $jrl[] = $jr;
            $need = $need || !empty($jr);
        }
        if ($need) {
            $this->conf->save_setting("responses", 1, json_encode_db($jrl));
        }
        return true;
    }

    /** @param string $review_form_data
     * @return ?string */
    function v258_review_form_setting($review_form_data) {
        $rfj = json_decode($review_form_data);
        if (!is_array($rfj) && !is_object($rfj)) {
            error_log("{$this->conf->dbname}: review_form not JSON");
            return null;
        }
        if (is_object($rfj)) {
            foreach ((array) $rfj as $key => $fj) {
                if (!isset($fj->id))
                    $fj->id = $key;
            }
            $rfj = array_values((array) $rfj);
        }
        $text_fields = [
            "paperSummary", "commentsToAuthor", "commentsToPC", "commentsToAddress",
            "weaknessOfPaper", "strengthOfPaper", "textField7", "textField8"
        ];
        $score_fields = [
            "overAllMerit", "reviewerQualification", "novelty", "technicalMerit",
            "interestToCommunity", "longevity", "grammar", "likelyPresentation",
            "suitableForShort", "potential", "fixability"
        ];
        foreach ($rfj as $fj) {
            $new_id = null;
            if (is_string($fj->id) && $fj->id !== "") {
                if (strlen($fj->id) === 3
                    && ($fj->id[0] === "s" || $fj->id[0] === "t")
                    && ctype_digit(substr($fj->id, 1))) {
                    $new_id = $fj->id;
                } else if (($i = array_search($fj->id, $score_fields, true)) !== false) {
                    $new_id = sprintf("s%02d", $i + 1);
                } else if (($i = array_search($fj->id, $text_fields, true)) !== false) {
                    $new_id = sprintf("t%02d", $i + 1);
                }
            }
            if (!$new_id) {
                error_log("{$this->conf->dbname}: review_form.{$fj->id} not found");
                return null;
            }
            $fj->id = $new_id;
            if (!isset($fj->visibility) && isset($fj->view_score)) {
                if ($fj->view_score === -2) {
                    $fj->visibility = "secret";
                } else if ($fj->view_score === -1) {
                    $fj->visibility = "admin";
                } else if ($fj->view_score === 0) {
                    $fj->visibility = "pc";
                } else if ($fj->view_score === 1 || $fj->view_score === "author") {
                    $fj->visibility = "au";
                } else if ($fj->view_score === "authordec") {
                    $fj->visibility = "audec";
                } else if (in_array($fj->view_score, ["secret", "admin", "pc", "audec", "au"])) {
                    $fj->visibility = $fj->view_score;
                } else {
                    error_log("{$this->conf->dbname}: review_form.{$fj->id}.view_score not found");
                    return null;
                }
            }
            unset($fj->view_score);
            if ($new_id[0] === "s" && !isset($fj->scheme)) {
                $sv = $fj->option_class_prefix ?? "sv";
                if (str_starts_with($sv, "sv-")) {
                    $sv = substr($sv, 3);
                }
                if ($sv === "blpu" || $sv === "publ") {
                    $sv = $sv[0] === "b" ? "bupu" : "pubu";
                }
                if (isset($fj->option_letter)) {
                    $svs = ["sv", "svr", "bupu", "pubu", "viridis", "viridisr"];
                    if (($i = array_search($sv, $svs)) !== false) {
                        $sv = $svs[$i ^ 1];
                    } else {
                        error_log("{$this->conf->dbname}: review_form.{$fj->id}.option_class_prefix not found");
                    }
                }
                if ($sv !== "sv") {
                    $fj->scheme = $sv;
                }
            }
            unset($fj->option_class_prefix);
            if ($new_id[0] === "s" && !isset($fj->required) && isset($fj->allow_empty)) {
                $fj->required = !$fj->allow_empty;
            }
            unset($fj->allow_empty);
            if (!isset($fj->order) && isset($fj->position)) {
                $fj->order = $fj->position;
            }
            unset($fj->position);
            if (($fj->round_mask ?? null) === 0) {
                unset($fj->round_mask);
            }
            if (($fj->options ?? null) === []) {
                unset($fj->options);
            }
        }
        return json_encode_db($rfj);
    }

    /** @param string $table
     * @return true */
    private function v259_add_affiliation_to_unaccented_name($table) {
        $this->conf->qe("lock tables {$table} write");
        $users = [];
        $result = $this->conf->qe("select * from {$table}");
        while (($u = Contact::fetch($result, $this->conf))) {
            $users[] = $u;
        }
        Dbl::free($result);

        $cleanf = Dbl::make_multi_ql_stager($this->conf->dblink);
        foreach ($users as $u) {
            $cleanf("update {$table} set unaccentedName=? where contactId=?", strtolower(UnicodeHelper::deaccent($u->searchable_name())), $u->contactId);
        }
        $cleanf(null);
        $this->conf->qe("unlock tables");
        return true;
    }

    /** @return bool */
    private function v260_paperreview_fields() {
        if ($this->check_column_exists("PaperReview", "reviewFormat")
            && !$this->conf->ql_ok("alter table PaperReview drop column `reviewFormat`")) {
            return false;
        }
        if (!$this->check_column_exists("ContactInfo", "cdbRoles")
            && !$this->conf->ql_ok("alter table ContactInfo add `cdbRoles` tinyint(1) NOT NULL DEFAULT 0")) {
            return false;
        }
        foreach (["overAllMerit", "reviewerQualification", "novelty", "technicalMerit",
                  "interestToCommunity", "longevity", "grammar", "likelyPresentation",
                  "suitableForShort", "potential", "fixability"] as $i => $f) {
            $nfn = sprintf("s%02d", $i + 1);
            $ofn = $this->check_column_exists("PaperReview", $f) ? $f : $nfn;
            if (!$this->conf->ql_ok("alter table PaperReview change `{$ofn}` `{$nfn}` smallint(1) NOT NULL DEFAULT 0")) {
                return false;
            }
        }
        return true;
    }

    /** @return bool */
    private function v266_contact_counter() {
        Dbl::qx($this->conf->dblink, "DROP TABLE IF EXISTS `ContactCounter`");
        return !!$this->conf->ql_ok("CREATE TABLE `ContactCounter` (
  `contactId` int(11) NOT NULL,
  `apiCount` bigint(11) NOT NULL DEFAULT '0',
  `apiLimit` bigint(11) NOT NULL DEFAULT '0',
  `apiRefreshMtime` bigint(11) NOT NULL DEFAULT '0',
  `apiRefreshWindow` int(11) NOT NULL DEFAULT '0',
  `apiRefreshAmount` int(11) NOT NULL DEFAULT '0',
  `apiLimit2` bigint(11) NOT NULL DEFAULT '0',
  `apiRefreshMtime2` bigint(11) NOT NULL DEFAULT '0',
  `apiRefreshWindow2` int(11) NOT NULL DEFAULT '0',
  `apiRefreshAmount2` int(11) NOT NULL DEFAULT '0',
  PRIMARY KEY (`contactId`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    /** @return bool */
    private function v267_paper_review_history() {
        Dbl::qx($this->conf->dblink, "DROP TABLE IF EXISTS `PaperReviewHistory`");
        return $this->conf->ql_ok("CREATE TABLE `PaperReviewHistory` (
  `paperId` int(11) NOT NULL,
  `reviewId` int(11) NOT NULL,
  `reviewTime` bigint(11) NOT NULL,
  `contactId` int(11) NOT NULL,
  `reviewRound` int(1) NOT NULL,
  `reviewOrdinal` int(1) NOT NULL,
  `reviewType` tinyint(1) NOT NULL,
  `reviewBlind` tinyint(1) NOT NULL,
  `reviewModified` bigint(11) NOT NULL,
  `reviewSubmitted` bigint(1) NOT NULL,
  `timeDisplayed` bigint(11) NOT NULL,
  `reviewAuthorSeen` bigint(1) NOT NULL,
  `reviewAuthorModified` bigint(1) DEFAULT NULL,
  `reviewNotified` bigint(1) DEFAULT NULL,
  `reviewAuthorNotified` bigint(11) NOT NULL DEFAULT 0,
  `reviewEditVersion` int(1) NOT NULL DEFAULT 0,
  `revdelta` longblob DEFAULT NULL,

  PRIMARY KEY (`paperId`,`reviewId`,`reviewTime`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4")
            && $this->conf->ql_ok("alter table PaperReview add `reviewTime` bigint(11) NOT NULL DEFAULT 0");
    }

    /** @return bool */
    private function v271_action_log_paper_actions() {
        $result = $this->conf->ql_ok("update ActionLog
            set action=(case action when 'Updated' then 'Paper saved' when 'Updated final' then 'Paper saved final' when 'Registered' then 'Paper started' when 'Registered new' then 'Paper started' when 'Submitted' then 'Paper submitted' when 'Submitted final copy for' then 'Paper saved final' else action end)
            where paperId>0 and (action='Updated' or action='Updated final' or action='Registered' or action='Registered new' or action='Submitted' or action='Submitted final copy for')
            limit 10000");
        if (!$result) {
            return false;
        } else if ($result->affected_rows === 10000) {
            $this->need_run = true;
            return false;
        }

        $result = $this->conf->ql_ok("update ActionLog
            set action=concat('Paper edited final: ', substring(action from 15))
            where paperId>0 and left(action, 14)='Updated final '
            limit 10000");
        if (!$result) {
            return false;
        } else if ($result->affected_rows === 10000) {
            $this->need_run = true;
            return false;
        }

        $result = $this->conf->ql_ok("update ActionLog
            set action=concat('Paper edited: ', substring(action from 9))
            where paperId>0 and left(action, 8)='Updated ' and left(action, 15)!='Updated review ' and left(action, 21)!='Updated draft review '
            limit 10000");
        if (!$result) {
            return false;
        } else if ($result->affected_rows === 10000) {
            $this->need_run = true;
            return false;
        }

        $result = $this->conf->ql_ok("update ActionLog
            set action=concat('Paper started: ', substring(action from 12))
            where paperId>0 and left(action, 11)='Registered '
            limit 10000");
        if (!$result) {
            return false;
        } else if ($result->affected_rows === 10000) {
            $this->need_run = true;
            return false;
        }

        $result = $this->conf->ql_ok("update ActionLog
            set action=concat('Paper submitted: ', substring(action from 11))
            where paperId>0 and left(action, 10)='Submitted ' and left(action, 17)!='Submitted review ' and left(action, 17)!='Submitted ACM TOC'
            limit 10000");
        if (!$result) {
            return false;
        } else if ($result->affected_rows === 10000) {
            $this->need_run = true;
            return false;
        }

        return true;
    }

    /** @return bool */
    private function v277_update_named_searches() {
        $sjs = $names = [];
        $result = $this->conf->ql("select name, data from Settings where name like 'ss:%'");
        if (!$result) {
            return false;
        }
        while (($row = $result->fetch_row())) {
            $names[] = $row[0];
            if (($jx = json_decode($row[1] ?? "null")) && is_object($jx)) {
                $j = (object) ["name" => substr($row[0], 3)];
                foreach ((array) $jx as $k => $v) {
                    if ($k !== "name")
                        $j->$k = $v;
                }
                $sjs[] = $j;
            }
        }
        Dbl::free($result);
        return empty($names)
            || ($this->conf->ql_ok("delete from Settings where name?a", $names)
                && (empty($sjs)
                    || $this->conf->save_setting("named_searches", 1, json_encode_db($sjs))));
    }

    private function v278_options_setting($options_array) {
        $diff = false;
        foreach ($options_array as $v) {
            if (is_object($v)) {
                if (($v->type ?? null) === "selector") {
                    $v->type = "dropdown";
                    $diff = true;
                }
                if (isset($v->selector)
                    && in_array($v->type ?? "", ["dropdown", "radio"])) {
                    $v->values = $v->values ?? $v->selector;
                    unset($v->selector);
                    $diff = true;
                }
                if (isset($v->view_type)) {
                    $v->visibility = $v->visibility ?? $v->view_type;
                    unset($v->view_type);
                    $diff = true;
                }
                if (isset($v->position)) {
                    $v->order = $v->order ?? $v->position;
                    unset($v->position);
                    $diff = true;
                }
                if (isset($v->form_position)) {
                    $v->form_order = $v->form_order ?? $v->form_position;
                    unset($v->form_position);
                    $diff = true;
                }
                if (isset($v->page_position) || isset($v->display_position)) {
                    $v->page_order = $v->page_order ?? $v->page_position ?? $v->display_position;
                    unset($v->form_position, $v->display_position);
                    $diff = true;
                }
                if (isset($v->edit_condition)) {
                    if (!property_exists($v, "exists_if")) {
                        $v->exists_if = $v->edit_condition;
                    }
                    unset($v->edit_condition);
                    $diff = true;
                }
            }
        }
        if ($diff) {
            $this->save_options_setting($options_array);
        }
        return $options_array;
    }

    private function v279_options_setting($options_array) {
        $diff = false;
        foreach ($options_array as $v) {
            if (!is_object($v)) {
                error_log("{$this->conf->dbname}: options update failure");
                return;
            }
            $id = $v->id ?? null;
            if (is_string($id) && ctype_digit($id)) {
                $id = $v->id = intval($id);
                $diff = true;
            }
            if (!is_int($id)) {
                error_log("{$this->conf->dbname}: options update failure");
            }
            if (($v->visibility ?? null) === "rev") {
                unset($v->visibility);
                $diff = true;
            }
            $disp = $v->display ?? null;
            if ($disp === "submission") {
                $v->display = "top";
                $diff = true;
            } else if ($disp === "prominent") {
                $v->display = "right";
                $diff = true;
            } else if ($disp === "topics") {
                $v->display = "rest";
                $diff = true;
            }
        }
        usort($options_array, function ($a, $b) {
            return ($a->order ?? 0) <=> ($b->order ?? 0)
                ? : ($a->id ?? 0) <=> ($b->id ?? 0);
        });
        foreach ($options_array as $i => $v) {
            if (is_object($v)
                && ($v->order ?? 0) !== $i + 1) {
                $v->order = $i + 1;
                $diff = true;
            }
        }
        if ($diff) {
            $this->save_options_setting($options_array);
        }
        return $options_array;
    }

    private function v280_filter_download_log() {
        $result = $this->conf->ql("select * from ActionLog where (action='Download paper' or action='Download final' or action='Download submission') and paperId is not null order by logId");
        $byp = [];
        $dels = [];
        while (($row = $result->fetch_object())) {
            $pid = (int) $row->paperId;
            if (($xrow = $byp[$pid] ?? null)
                && $row->ipaddr === $xrow->ipaddr
                && $row->action === $xrow->action
                && $row->contactId === $xrow->contactId
                && $row->destContactId === $xrow->destContactId
                && $row->trueContactId === $xrow->trueContactId
                && $row->timestamp < $xrow->timestamp + 3600) {
                $dels[] = (int) $row->logId;
            } else {
                $byp[$pid] = $row;
            }
        }
        $result->close();
        if (!empty($dels)) {
            $this->conf->ql("delete from ActionLog where logId?a", $dels);
        }
    }

    private function v281_update_response_rounds() {
        $respv = $this->conf->setting("responses");
        $jresp = json_decode($this->conf->setting_data("responses") ?? "[]");
        $njresp = [];
        foreach ($jresp ?? [] as $i => $rrj) {
            if (isset($rrj->words)) {
                $rrj->wl = $rrj->words;
            }
            if (isset($rrj->truncate)) {
                $rrj->wl = $rrj->hwl = $rrj->wl ?? 500;
            }
            unset($rrj->words, $rrj->truncate);
            $njresp[] = $rrj;
        }
        if (!empty($njresp)) {
            $this->conf->save_setting("responses", $respv, json_encode_db($njresp));
        }
    }

    private function v282_update_viewrev() {
        $conf = $this->conf;

        $sv = $conf->setting("extrev_seerev") ?? 0;
        $conf->save_setting("viewrev_ext", $sv <= 0 ? -1 : null);
        $conf->save_setting("extrev_seerev", null);

        $sv = $conf->setting("extrev_seerevid") ?? 0;
        $conf->save_setting("viewrevid_ext", $sv <= 0 ? -1 : ($sv === 1 ? null : 1));
        $conf->save_setting("extrev_seerevid", null);

        $sv = $conf->setting("pc_seeblindrev") ?? 0;
        $conf->save_setting("viewrevid", $sv <= 0 ? 1 : null);
        $conf->save_setting("pc_seeblindrev", null);

        $sv = $conf->setting("pc_seeallrev") ?? 0;
        if ($sv === 2) {
            $conf->save_setting("viewrevid", null);
            $sv = 1;
        }
        $conf->save_setting("viewrev", $sv === 0 ? null : $sv);
        $conf->save_setting("pc_seeallrev", null);

        if (($t = $conf->setting_data("round_settings"))
            && ($j = json_decode($t))
            && is_array($j)) {
            foreach ($j as $x) {
                if (is_object($x)) {
                    if (isset($x->pc_seeallrev)) {
                        $x->viewrev = $x->pc_seeallrev;
                    }
                    if (isset($x->pc_seeblindrev)) {
                        $x->viewrevid = $x->pc_seeblindrev <= 0 ? 1 : 0;
                    }
                    if (isset($x->extrev_seerev)) {
                        $x->viewrev_ext = $x->extrev_seerev <= 0 ? -1 : 0;
                    }
                    if (isset($x->extrev_seerevid)) {
                        $sv = $x->extrev_seerevid;
                        $x->viewrevid_ext = $sv <= 0 ? -1 : ($sv === 1 ? 0 : 1);
                    }
                    unset($x->pc_seeallrev, $x->pc_seeblindrev, $x->extrev_seerev, $x->extrev_seerevid);
                }
            }
            $conf->save_setting("round_settings", 1, json_encode_db($j));
        }

        $conf->save_setting("__extrev_seerev_v282", 1);
    }

    private function v283_ensure_rev_roundtag() {
        $t1 = $this->conf->setting_data("rev_roundtag") ?? "";
        $t2 = $this->conf->setting_data("extrev_roundtag") ?? "";
        $tl = $tlx = trim($this->conf->setting_data("tag_rounds") ?? "");
        if ($t1 !== "" && strcasecmp($t1, "unnamed") !== 0 && stripos(" {$tlx} ", $t1) === false) {
            $tlx = $tlx === "" ? $t1 : "{$tlx} {$t1}";
        }
        if ($t2 !== "" && strcasecmp($t2, "unnamed") !== 0 && stripos(" {$tlx} ", $t2) === false) {
            $tlx = $tlx === "" ? $t2 : "{$tlx} {$t2}";
        }
        if ($tlx !== $tl) {
            $this->conf->save_setting("tag_rounds", 1, $tlx);
        }
    }

    /** @return bool */
    function run() {
        $conf = $this->conf;

        // avoid error message about timezone, set to $Opt
        // (which might be overridden by database values later)
        if (function_exists("date_default_timezone_set") && $conf->opt("timezone")) {
            date_default_timezone_set($conf->opt("timezone"));
        }

        // obtain lock on settings, reload them in case of concurrent operation
        while (($result = $conf->ql_ok("insert into Settings set name='__schema_lock', value=1 on duplicate key update value=1"))
               && $result->affected_rows == 0) {
            time_nanosleep(0, 200000000);
        }
        $conf->__load_settings();
        $old_conf_g = Conf::$main;
        Conf::$main = $conf;

        if (!$conf->opt("__quietUpdateSchema")) {
            error_log($conf->dbname . ": updating schema from version " . $conf->sversion);
        }

        // change `options` into an array, not an associative array
        // (must do this early because PaperOptionList depends on that format)
        $options_data = $conf->setting_json("options");
        if (is_object($options_data)) {
            $options_data = $this->v1_options_setting($options_data);
        }
        if (is_array($options_data) && $conf->sversion <= 247) {
            $options_data = $this->v248_options_setting($options_data);
        }
        if (is_array($options_data) && $conf->sversion <= 277) {
            $options_data = $this->v278_options_setting($options_data);
        }
        if (is_array($options_data) && $conf->sversion <= 278) {
            $options_data = $this->v279_options_setting($options_data);
        }

        // update `review_form`
        if ($conf->sversion <= 257
            && !$conf->setting("__review_form_v258")
            && ($rfd = $conf->setting_data("review_form"))
            && ($nrfd = $this->v258_review_form_setting($rfd)) !== null) {
            $conf->save_setting("__review_form_v258", 1, $rfd);
            $conf->save_setting("review_form", 1, $nrfd);
        }

        // update commentRound
        if ($conf->sversion >= 106
            && $conf->sversion <= 260
            && !$conf->setting("__response_round_v261")
            && $conf->ql_ok("update PaperComment set commentRound=commentRound+1 where (commentType&4)!=0") /* CT_RESPONSE */) {
            $conf->save_setting("__response_round_v261", 1);
        }

        // update reviewViewScore
        if ($conf->sversion >= 226
            && $conf->sversion <= 264
            && !$conf->setting("__review_view_score_v264")
            && $conf->ql_ok("update PaperReview set reviewViewScore=reviewViewScore+1 where reviewViewScore>=0") /* old VIEWSCORE_PC becomes VIEWSCORE_REVIEWER */) {
            $conf->save_setting("__review_view_score_v264", 1);
        }

        // update seedec
        if ($conf->sversion <= 269) {
            $sd = $conf->setting("seedec");
            if ($sd === null && $conf->setting("rev_seedec")) {
                $sd = 1; /* SEEDEC_REV */
            }
            if ($sd === 2) {
                $conf->save_setting("seedec", 1);
                $conf->save_setting("au_seedec", 2);
            }
            $conf->save_setting("rev_seedec", null);
        }

        // update extrev_view => extrev_seerev, extrev_seerevid
        if ($conf->sversion <= 273
            && ($sd = $conf->setting("extrev_view")) !== null) {
            if ($sd >= 1) {
                $conf->save_setting("extrev_seerev", 1);
            }
            if ($sd >= 2) {
                $conf->save_setting("extrev_seerevid", 1);
            }
            $conf->save_setting("extrev_view", null);
        }

        // remove has_permtag
        if ($conf->sversion <= 274
            && $conf->setting("has_permtag")) {
            $conf->save_setting("has_permtag", null);
        }

        // update saved searches
        if ($conf->sversion <= 276) {
            $this->v277_update_named_searches();
        }

        // update extrev_seerev => view_rev_ext
        if ($conf->sversion <= 281
            && !$conf->setting("__extrev_seerev_v282")) {
            $this->v282_update_viewrev();
        }

        if ($conf->sversion === 6
            && $conf->ql_ok("alter table ReviewRequest add `reason` text")) {
            $conf->update_schema_version(7);
        }
        if ($conf->sversion === 7
            && $conf->ql_ok("alter table PaperReview add `textField7` mediumtext NOT NULL")
            && $conf->ql_ok("alter table PaperReview add `textField8` mediumtext NOT NULL")
            && $conf->ql_ok("insert into ReviewFormField set fieldName='textField7', shortName='Additional text field'")
            && $conf->ql_ok("insert into ReviewFormField set fieldName='textField8', shortName='Additional text field'")) {
            $conf->update_schema_version(8);
        }
        if ($conf->sversion === 8
            && $conf->ql_ok("alter table ReviewFormField add `levelChar` tinyint(1) NOT NULL default '0'")
            && $conf->ql_ok("alter table PaperReviewArchive add `textField7` mediumtext NOT NULL")
            && $conf->ql_ok("alter table PaperReviewArchive add `textField8` mediumtext NOT NULL")) {
            $conf->update_schema_version(9);
        }
        if ($conf->sversion === 9
            && $conf->ql_ok("alter table Paper add `sha1` varbinary(20) NOT NULL default ''")) {
            $conf->update_schema_version(10);
        }
        if ($conf->sversion === 10
            && $conf->ql_ok("alter table PaperReview add `reviewRound` tinyint(1) NOT NULL default '0'")
            && $conf->ql_ok("alter table PaperReviewArchive add `reviewRound` tinyint(1) NOT NULL default '0'")
            && $conf->ql_ok("alter table PaperReview add key `reviewRound` (`reviewRound`)")
            && $conf->update_schema_version(11)) {
            if (count($conf->round_list()) > 1) {
                // update review rounds (XXX locking)
                $result = $conf->ql_ok("select paperId, tag from PaperTag where tag like '%~%'");
                $rrs = [];
                while (($row = $result->fetch_row())) {
                    list($contact, $round) = explode("~", $row[1]);
                    if (($round = array_search($round, $conf->round_list()))) {
                        if (!isset($rrs[$round]))
                            $rrs[$round] = [];
                        $rrs[$round][] = "(contactId=$contact and paperId=$row[0])";
                    }
                }
                foreach ($rrs as $round => $pairs) {
                    $q = "update PaperReview set reviewRound=$round where " . join(" or ", $pairs);
                    $conf->ql_ok($q);
                }
                $x = trim(preg_replace('/(\S+)\s*/', "tag like '%~\$1' or ", $conf->setting_data("tag_rounds")));
                $conf->ql_ok("delete from PaperTag where " . substr($x, 0, strlen($x) - 3));
            }
        }
        if ($conf->sversion === 11) {
            Dbl::qx($conf->dblink, "DROP TABLE IF EXISTS `ReviewRating`");
            if ($conf->ql_ok("create table `ReviewRating` (
      `reviewId` int(11) NOT NULL,
      `contactId` int(11) NOT NULL,
      `rating` tinyint(1) NOT NULL default '0',
      UNIQUE KEY `reviewContact` (`reviewId`,`contactId`),
      UNIQUE KEY `reviewContactRating` (`reviewId`,`contactId`,`rating`)
    ) ENGINE=MyISAM DEFAULT CHARSET=utf8")) {
                $conf->update_schema_version(12);
            }
        }
        if ($conf->sversion === 12
            && $conf->ql_ok("alter table PaperReview add `reviewToken` int(11) NOT NULL default '0'")) {
            $conf->update_schema_version(13);
        }
        if ($conf->sversion === 13
            && $conf->ql_ok("alter table OptionType add `optionValues` text NOT NULL default ''")) {
            $conf->update_schema_version(14);
        }
        if ($conf->sversion === 14) {
            $conf->update_rev_tokens_setting(0);
            $conf->update_schema_version(15);
        }
        if ($conf->sversion === 15) {
            // It's OK if this fails!  Update 11 added reviewRound to
            // PaperReviewArchive (so old databases have the column), but I forgot
            // to upgrade schema.sql (so new databases lack the column).
            $old_nerrors = Dbl::$nerrors;
            $conf->ql_ok("alter table PaperReviewArchive add `reviewRound` tinyint(1) NOT NULL default '0'");
            Dbl::$nerrors = $old_nerrors;
            $conf->update_schema_version(16);
        }
        if ($conf->sversion === 16
            && $conf->ql_ok("alter table PaperReview add `reviewEditVersion` int(1) NOT NULL default '0'")) {
            $conf->update_schema_version(17);
        }
        if ($conf->sversion === 17
            && $conf->ql_ok("alter table PaperReviewPreference add key `paperId` (`paperId`)")) {
            $conf->update_schema_version(18);
        }
        if ($conf->sversion === 18
            && $conf->ql_ok("alter table PaperComment add `replyTo` int(11) NOT NULL")) {
            $conf->update_schema_version(19);
        }
        if ($conf->sversion === 19) {
            Dbl::qx($conf->dblink, "DROP TABLE IF EXISTS `PaperRank`");
            $conf->update_schema_version(20);
        }
        if ($conf->sversion === 20
            && $conf->ql_ok("alter table PaperComment add `timeNotified` int(11) NOT NULL default '0'")) {
            $conf->update_schema_version(21);
        }
        if ($conf->sversion === 21
            && $conf->ql_ok("update PaperConflict set conflictType=8 where conflictType=3")) {
            $conf->update_schema_version(22);
        }
        if ($conf->sversion === 22
            && $conf->ql_ok("insert into ChairAssistant (contactId) select contactId from Chair on duplicate key update ChairAssistant.contactId=ChairAssistant.contactId")
            && $conf->ql_ok("update ContactInfo set roles=roles+2 where roles=5")) {
            $conf->update_schema_version(23);
        }
        if ($conf->sversion === 23) {
            $conf->update_schema_version(24);
        }
        if ($conf->sversion === 24
            && $conf->ql_ok("alter table ContactInfo add `preferredEmail` varchar(120)")) {
            $conf->update_schema_version(25);
        }
        if ($conf->sversion === 25) {
            if (($fd = $conf->settings["final_done"]) > 0
                && !isset($conf->settings["final_soft"])
                && $conf->ql_ok("insert into Settings (name, value) values ('final_soft', ?) on duplicate key update value=?", $fd, $fd)) {
                $conf->settings["final_soft"] = $fd;
            }
            $conf->update_schema_version(26);
        }
        if ($conf->sversion === 26
            && $conf->ql_ok("alter table PaperOption add `data` text")
            && $conf->ql_ok("alter table OptionType add `type` tinyint(1) NOT NULL default '0'")
            && $conf->ql_ok("update OptionType set type=2 where optionValues='\x7Fi'")
            && $conf->ql_ok("update OptionType set type=1 where type=0 and optionValues!=''")) {
            $conf->update_schema_version(27);
        }
        if ($conf->sversion === 27
            && $conf->ql_ok("alter table PaperStorage add `sha1` varbinary(20) NOT NULL default ''")
            && $conf->ql_ok("alter table PaperStorage add `documentType` int(3) NOT NULL default '0'")
            && $conf->ql_ok("update PaperStorage s, Paper p set s.sha1=p.sha1 where s.paperStorageId=p.paperStorageId and p.finalPaperStorageId=0 and s.paperStorageId>0")
            && $conf->ql_ok("update PaperStorage s, Paper p set s.sha1=p.sha1, s.documentType=" . DTYPE_FINAL . " where s.paperStorageId=p.finalPaperStorageId and s.paperStorageId>0")) {
            $conf->update_schema_version(28);
        }
        if ($conf->sversion === 28
            && $conf->ql_ok("alter table OptionType add `sortOrder` tinyint(1) NOT NULL default '0'")) {
            $conf->update_schema_version(29);
        }
        if ($conf->sversion === 29
            && $conf->ql_ok("delete from Settings where name='pldisplay_default'")) {
            $conf->update_schema_version(30);
        }
        if ($conf->sversion === 30) {
            Dbl::qx($conf->dblink, "DROP TABLE IF EXISTS `Formula`");
            if ($conf->ql_ok("CREATE TABLE `Formula` (
      `formulaId` int(11) NOT NULL auto_increment,
      `name` varchar(200) NOT NULL,
      `heading` varchar(200) NOT NULL default '',
      `headingTitle` text NOT NULL default '',
      `expression` text NOT NULL,
      `authorView` tinyint(1) NOT NULL default '1',
      PRIMARY KEY  (`formulaId`),
      UNIQUE KEY `formulaId` (`formulaId`),
      UNIQUE KEY `name` (`name`)
    ) ENGINE=MyISAM DEFAULT CHARSET=utf8")) {
                $conf->update_schema_version(31);
            }
        }
        if ($conf->sversion === 31
            && $conf->ql_ok("alter table Formula add `createdBy` int(11) NOT NULL default '0'")
            && $conf->ql_ok("alter table Formula add `timeModified` int(11) NOT NULL default '0'")
            && $conf->ql_ok("alter table Formula drop index `name`")) {
            $conf->update_schema_version(32);
        }
        if ($conf->sversion === 32
            && $conf->ql_ok("alter table PaperComment add key `timeModified` (`timeModified`)")) {
            $conf->update_schema_version(33);
        }
        if ($conf->sversion === 33
            && $conf->ql_ok("alter table PaperComment add `paperStorageId` int(11) NOT NULL default '0'")) {
            $conf->update_schema_version(34);
        }
        if ($conf->sversion === 34
            && $conf->ql_ok("alter table ContactInfo add `contactTags` text")) {
            $conf->update_schema_version(35);
        }
        if ($conf->sversion === 35
            && $conf->ql_ok("alter table ContactInfo modify `defaultWatch` int(11) NOT NULL default '2'")
            && $conf->ql_ok("alter table PaperWatch modify `watch` int(11) NOT NULL default '0'")) {
            $conf->update_schema_version(36);
        }
        if ($conf->sversion === 36
            && $conf->ql_ok("alter table PaperReview add `reviewNotified` int(1) default NULL")
            && $conf->ql_ok("alter table PaperReviewArchive add `reviewNotified` int(1) default NULL")) {
            $conf->update_schema_version(37);
        }
        if ($conf->sversion === 37
            && $conf->ql_ok("alter table OptionType add `displayType` tinyint(1) NOT NULL default '0'")) {
            $conf->update_schema_version(38);
        }
        if ($conf->sversion === 38
            && $conf->ql_ok("update PaperComment set forReviewers=1 where forReviewers=-1")) {
            $conf->update_schema_version(39);
        }
        if ($conf->sversion === 39) {
            Dbl::qx($conf->dblink, "DROP TABLE IF EXISTS `MailLog`");
            if ($conf->ql_ok("CREATE TABLE `MailLog` (
      `mailId` int(11) NOT NULL auto_increment,
      `recipients` varchar(200) NOT NULL,
      `paperIds` text,
      `cc` text,
      `replyto` text,
      `subject` text,
      `emailBody` text,
      PRIMARY KEY  (`mailId`)
    ) ENGINE=MyISAM DEFAULT CHARSET=utf8")) {
                $conf->update_schema_version(40);
            }
        }
        if ($conf->sversion === 40
            && $conf->ql_ok("alter table Paper add `capVersion` int(1) NOT NULL default '0'")) {
            $conf->update_schema_version(41);
        }
        if ($conf->sversion === 41
            && $conf->ql_ok("alter table Paper modify `mimetype` varchar(80) NOT NULL default ''")
            && $conf->ql_ok("alter table PaperStorage modify `mimetype` varchar(80) NOT NULL default ''")) {
            $conf->update_schema_version(42);
        }
        if ($conf->sversion === 42
            && $conf->ql_ok("alter table PaperComment add `ordinal` int(11) NOT NULL default '0'")) {
            $conf->update_schema_version(43);
        }
        if ($conf->sversion === 42
            && ($result = $conf->ql_ok("describe PaperComment `ordinal`"))
            && ($o = $result->fetch_object())
            && substr($o->Type, 0, 3) == "int"
            && (!$o->Null || $o->Null == "NO")
            && (!$o->Default || $o->Default == "0")) {
            $conf->update_schema_version(43);
        }
        if ($conf->sversion === 43
            && $conf->ql_ok("alter table Paper add `withdrawReason` text")) {
            $conf->update_schema_version(44);
        }
        if ($conf->sversion === 44
            && $conf->ql_ok("alter table PaperStorage add `filename` varchar(255)")) {
            $conf->update_schema_version(45);
        }
        if ($conf->sversion === 45
            && $conf->ql_ok("alter table PaperReview add `timeRequested` int(11) NOT NULL DEFAULT '0'")
            && $conf->ql_ok("alter table PaperReview add `timeRequestNotified` int(11) NOT NULL DEFAULT '0'")
            && $conf->ql_ok("alter table PaperReviewArchive add `timeRequested` int(11) NOT NULL DEFAULT '0'")
            && $conf->ql_ok("alter table PaperReviewArchive add `timeRequestNotified` int(11) NOT NULL DEFAULT '0'")
            && $conf->ql_ok("alter table PaperReview drop column `requestedOn`")
            && $conf->ql_ok("alter table PaperReviewArchive drop column `requestedOn`")) {
            $conf->update_schema_version(46);
        }
        if ($conf->sversion === 46
            && $conf->ql_ok("alter table ContactInfo add `disabled` tinyint(1) NOT NULL DEFAULT '0'")) {
            $conf->update_schema_version(47);
        }
        if ($conf->sversion === 47
            && $conf->ql_ok("delete from ti using TopicInterest ti left join TopicArea ta using (topicId) where ta.topicId is null")) {
            $conf->update_schema_version(48);
        }
        if ($conf->sversion === 48
            && $conf->ql_ok("alter table PaperReview add `reviewAuthorNotified` int(11) NOT NULL DEFAULT '0'")
            && $conf->ql_ok("alter table PaperReviewArchive add `reviewAuthorNotified` int(11) NOT NULL DEFAULT '0'")
            && $conf->ql_ok("alter table PaperReviewArchive add `reviewToken` int(11) NOT NULL DEFAULT '0'")) {
            $conf->update_schema_version(49);
        }
        if ($conf->sversion === 49
            && $conf->ql_ok("alter table PaperOption drop index `paperOption`")
            && $conf->ql_ok("alter table PaperOption add index `paperOption` (`paperId`,`optionId`,`value`)")) {
            $conf->update_schema_version(50);
        }
        if ($conf->sversion === 50
            && $conf->ql_ok("alter table Paper add `managerContactId` int(11) NOT NULL DEFAULT '0'")) {
            $conf->update_schema_version(51);
        }
        if ($conf->sversion === 51
            && $conf->ql_ok("alter table Paper drop column `numComments`")
            && $conf->ql_ok("alter table Paper drop column `numAuthorComments`")) {
            $conf->update_schema_version(52);
        }
        // Due to a bug in the schema updater, some databases might have
        // sversion>=53 but no `PaperComment.commentType` column. Fix them.
        if (($conf->sversion === 52
             || ($conf->sversion >= 53
                 && ($result = $conf->ql_ok("show columns from PaperComment like 'commentType'"))
                 && !Dbl::is_error($result)
                 && $result->num_rows == 0))
            && $conf->ql_ok("lock tables PaperComment write, Settings write")
            && $conf->ql_ok("alter table PaperComment add `commentType` int(11) NOT NULL DEFAULT '0'")) {
            $new_sversion = max($conf->sversion, 53);
            $result = $conf->ql_ok("show columns from PaperComment like 'forAuthors'");
            if (Dbl::is_error($result)
                || $result->num_rows == 0
                || ($conf->ql_ok("update PaperComment set commentType=0x30004 where forAuthors=2") /* CT_AUTHOR|CT_RESPONSE */
                    && $conf->ql_ok("update PaperComment set commentType=commentType|1 where forAuthors=2 and forReviewers=0") /* CT_DRAFT */
                    && $conf->ql_ok("update PaperComment set commentType=0 where forAuthors=0 and forReviewers=2") /* CT_ADMINONLY */
                    && $conf->ql_ok("update PaperComment set commentType=0x10000 where forAuthors=0 and forReviewers=0") /* CT_PC */
                    && $conf->ql_ok("update PaperComment set commentType=0x20000 where forAuthors=0 and forReviewers=1") /* CT_REVIEWER */
                    && $conf->ql_ok("update PaperComment set commentType=0x30000 where forAuthors!=0 and forAuthors!=2") /* CT_AUTHOR */
                    && $conf->ql_ok("update PaperComment set commentType=commentType|2 where blind=1") /* CT_BLIND */)) {
                $conf->update_schema_version($new_sversion);
            }
        }
        if ($conf->sversion < 53) {
            Dbl::qx_raw($conf->dblink, "alter table PaperComment drop column `commentType`");
        }
        $conf->ql_ok("unlock tables");
        if ($conf->sversion === 53
            && $conf->ql_ok("alter table PaperComment drop column `forReviewers`")
            && $conf->ql_ok("alter table PaperComment drop column `forAuthors`")
            && $conf->ql_ok("alter table PaperComment drop column `blind`")) {
            $conf->update_schema_version(54);
        }
        if ($conf->sversion === 54
            && $conf->ql_ok("alter table PaperStorage add `infoJson` varchar(255) DEFAULT NULL")) {
            $conf->update_schema_version(55);
        }
        if ($conf->sversion === 55
            && $conf->ql_ok("alter table ContactInfo modify `password` varbinary(2048) NOT NULL")) {
            $conf->update_schema_version(56);
        }
        if ($conf->sversion === 56
            && $conf->ql_ok("alter table Settings modify `data` blob")) {
            $conf->update_schema_version(57);
        }
        if ($conf->sversion === 57) {
            Dbl::qx($conf->dblink, "DROP TABLE IF EXISTS `Capability`");
            Dbl::qx($conf->dblink, "DROP TABLE IF EXISTS `CapabilityMap`");
            if ($conf->ql_ok("CREATE TABLE `Capability` (
      `capabilityId` int(11) NOT NULL AUTO_INCREMENT,
      `capabilityType` int(11) NOT NULL,
      `contactId` int(11) NOT NULL,
      `paperId` int(11) NOT NULL,
      `timeExpires` int(11) NOT NULL,
      `salt` varbinary(255) NOT NULL,
      `data` blob,
      PRIMARY KEY (`capabilityId`),
      UNIQUE KEY `capabilityId` (`capabilityId`)
    ) ENGINE=MyISAM DEFAULT CHARSET=utf8")
                && $conf->ql_ok("CREATE TABLE `CapabilityMap` (
      `capabilityValue` varbinary(255) NOT NULL,
      `capabilityId` int(11) NOT NULL,
      `timeExpires` int(11) NOT NULL,
      PRIMARY KEY (`capabilityValue`),
      UNIQUE KEY `capabilityValue` (`capabilityValue`)
    ) ENGINE=MyISAM DEFAULT CHARSET=utf8")) {
                $conf->update_schema_version(58);
            }
        }
        if ($conf->sversion === 58
            && $conf->ql_ok("alter table PaperReview modify `paperSummary` mediumtext DEFAULT NULL")
            && $conf->ql_ok("alter table PaperReview modify `commentsToAuthor` mediumtext DEFAULT NULL")
            && $conf->ql_ok("alter table PaperReview modify `commentsToPC` mediumtext DEFAULT NULL")
            && $conf->ql_ok("alter table PaperReview modify `commentsToAddress` mediumtext DEFAULT NULL")
            && $conf->ql_ok("alter table PaperReview modify `weaknessOfPaper` mediumtext DEFAULT NULL")
            && $conf->ql_ok("alter table PaperReview modify `strengthOfPaper` mediumtext DEFAULT NULL")
            && $conf->ql_ok("alter table PaperReview modify `textField7` mediumtext DEFAULT NULL")
            && $conf->ql_ok("alter table PaperReview modify `textField8` mediumtext DEFAULT NULL")
            && $conf->ql_ok("alter table PaperReviewArchive modify `paperSummary` mediumtext DEFAULT NULL")
            && $conf->ql_ok("alter table PaperReviewArchive modify `commentsToAuthor` mediumtext DEFAULT NULL")
            && $conf->ql_ok("alter table PaperReviewArchive modify `commentsToPC` mediumtext DEFAULT NULL")
            && $conf->ql_ok("alter table PaperReviewArchive modify `commentsToAddress` mediumtext DEFAULT NULL")
            && $conf->ql_ok("alter table PaperReviewArchive modify `weaknessOfPaper` mediumtext DEFAULT NULL")
            && $conf->ql_ok("alter table PaperReviewArchive modify `strengthOfPaper` mediumtext DEFAULT NULL")
            && $conf->ql_ok("alter table PaperReviewArchive modify `textField7` mediumtext DEFAULT NULL")
            && $conf->ql_ok("alter table PaperReviewArchive modify `textField8` mediumtext DEFAULT NULL")) {
            $conf->update_schema_version(59);
        }
        if ($conf->sversion === 59
            && $conf->ql_ok("alter table ActionLog modify `action` varbinary(4096) NOT NULL")
            && $conf->ql_ok("alter table ContactInfo modify `note` varbinary(4096) DEFAULT NULL")
            && $conf->ql_ok("alter table ContactInfo modify `collaborators` varbinary(32767) DEFAULT NULL")
            && $conf->ql_ok("alter table ContactInfo modify `contactTags` varbinary(4096) DEFAULT NULL")
            && $conf->ql_ok("alter table Formula modify `headingTitle` varbinary(4096) NOT NULL")
            && $conf->ql_ok("alter table Formula modify `expression` varbinary(4096) NOT NULL")
            && $conf->ql_ok("alter table OptionType modify `description` varbinary(8192) DEFAULT NULL")
            && $conf->ql_ok("alter table OptionType modify `optionValues` varbinary(8192) NOT NULL")
            && $conf->ql_ok("alter table PaperReviewRefused modify `reason` varbinary(32767) DEFAULT NULL")
            && $conf->ql_ok("alter table ReviewFormField modify `description` varbinary(32767) DEFAULT NULL")
            && $conf->ql_ok("alter table ReviewFormOptions modify `description` varbinary(32767) DEFAULT NULL")
            && $conf->ql_ok("alter table ReviewRequest modify `reason` varbinary(32767) DEFAULT NULL")
            && $conf->ql_ok("alter table Settings modify `data` varbinary(32767) DEFAULT NULL")
            && $conf->ql_ok("alter table ContactAddress modify `addressLine1` varchar(2048) NOT NULL")
            && $conf->ql_ok("alter table ContactAddress modify `addressLine2` varchar(2048) NOT NULL")
            && $conf->ql_ok("alter table ContactAddress modify `city` varchar(2048) NOT NULL")
            && $conf->ql_ok("alter table ContactAddress modify `state` varchar(2048) NOT NULL")
            && $conf->ql_ok("alter table ContactAddress modify `zipCode` varchar(2048) NOT NULL")
            && $conf->ql_ok("alter table ContactAddress modify `country` varchar(2048) NOT NULL")
            && $conf->ql_ok("alter table PaperTopic modify `topicId` int(11) NOT NULL")
            && $conf->ql_ok("alter table PaperTopic modify `paperId` int(11) NOT NULL")) {
            Dbl::qx($conf->dblink, "drop table if exists ChairTag");
            $conf->update_schema_version(60);
        }
        if ($conf->sversion === 60) {
            foreach (["conflictdef", "home"] as $k) {
                if ($conf->has_setting("{$k}msg")) {
                    $conf->save_setting("msg.$k", 1, $conf->setting_data("{$k}msg"));
                    $conf->save_setting("{$k}msg", null);
                }
            }
            $conf->update_schema_version(61);
        }
        if ($conf->sversion === 61
            && $conf->ql_ok("alter table Capability modify `data` varbinary(4096) DEFAULT NULL")) {
            $conf->update_schema_version(62);
        }
        if (!isset($conf->settings["outcome_map"])
            && $conf->sversion < 65) {
            $ojson = [];
            $result = $conf->ql_ok("select * from ReviewFormOptions where fieldName='outcome'");
            while ($result && ($row = $result->fetch_object())) {
                $ojson[$row->level] = $row->description;
            }
            $conf->save_setting("outcome_map", 1, $ojson);
        }
        if ($conf->sversion === 62
            && isset($conf->settings["outcome_map"])) {
            $conf->update_schema_version(63);
        }
        if (!isset($conf->settings["review_form"])
            && $conf->sversion < 65) {
            $this->v65_create_review_form();
        }
        if ($conf->sversion === 63
            && isset($conf->settings["review_form"])) {
            $conf->update_schema_version(64);
        }
        if ($conf->sversion === 64) {
            Dbl::qx($conf->dblink, "drop table if exists `ReviewFormField`");
            Dbl::qx($conf->dblink, "drop table if exists `ReviewFormOptions`");
            $conf->update_schema_version(65);
        }
        if (!isset($conf->settings["options"])
            && $conf->sversion < 67) {
            $this->v67_create_options();
        }
        if ($conf->sversion === 65
            && isset($conf->settings["options"])) {
            $conf->update_schema_version(66);
        }
        if ($conf->sversion === 66) {
            Dbl::qx($conf->dblink, "drop table if exists `OptionType`");
            $conf->update_schema_version(67);
        }
        if ($conf->sversion === 67
            && $conf->ql_ok("alter table PaperComment modify `comment` varbinary(32767) DEFAULT NULL")
            && $conf->ql_ok("alter table PaperComment add `commentTags` varbinary(1024) DEFAULT NULL")) {
            $conf->update_schema_version(68);
        }
        if ($conf->sversion === 68
            && $conf->ql_ok("alter table PaperReviewPreference add `expertise` int(4) DEFAULT NULL")) {
            $conf->update_schema_version(69);
        }
        if ($conf->sversion === 69
            && $conf->ql_ok("alter table Paper drop column `pcPaper`")) {
            $conf->update_schema_version(70);
        }
        if ($conf->sversion === 70
            && $conf->ql_ok("alter table ContactInfo modify `voicePhoneNumber` varbinary(256) DEFAULT NULL")
            && $conf->ql_ok("alter table ContactInfo modify `faxPhoneNumber` varbinary(256) DEFAULT NULL")
            && $conf->ql_ok("alter table ContactInfo modify `collaborators` varbinary(8192) DEFAULT NULL")
            && $conf->ql_ok("alter table ContactInfo drop column `note`")
            && $conf->ql_ok("alter table ContactInfo add `data` varbinary(32767) DEFAULT NULL")) {
            $conf->update_schema_version(71);
        }
        if ($conf->sversion === 71
            && $conf->ql_ok("alter table Settings modify `name` varbinary(256) DEFAULT NULL")
            && $conf->ql_ok("update Settings set name=rtrim(name)")) {
            $conf->update_schema_version(72);
        }
        if ($conf->sversion === 72
            && $conf->ql_ok("update TopicInterest set interest=-2 where interest=0")
            && $conf->ql_ok("update TopicInterest set interest=4 where interest=2")
            && $conf->ql_ok("delete from TopicInterest where interest=1")) {
            $conf->update_schema_version(73);
        }
        if ($conf->sversion === 73
            && $conf->ql_ok("alter table PaperStorage add `size` bigint(11) DEFAULT NULL")
            && $conf->ql_ok("update PaperStorage set `size`=length(paper) where paper is not null")) {
            $conf->update_schema_version(74);
        }
        if ($conf->sversion === 74
            && $conf->ql_ok("alter table ContactInfo drop column `visits`")) {
            $conf->update_schema_version(75);
        }
        if ($conf->sversion === 75) {
            foreach (["capability_gc", "s3_scope", "s3_signing_key"] as $k) {
                if ($conf->setting($k)) {
                    $conf->save_setting("__" . $k, $conf->setting($k), $conf->setting_data($k));
                    $conf->save_setting($k, null);
                }
            }
            $conf->update_schema_version(76);
        }
        if ($conf->sversion === 76
            && $conf->ql_ok("update PaperReviewPreference set expertise=-expertise")) {
            $conf->update_schema_version(77);
        }
        if ($conf->sversion === 77
            && $conf->ql_ok("alter table MailLog add `q` varchar(4096)")) {
            $conf->update_schema_version(78);
        }
        if ($conf->sversion === 78
            && $conf->ql_ok("alter table MailLog add `t` varchar(200)")) {
            $conf->update_schema_version(79);
        }
        if ($conf->sversion === 79
            && $conf->ql_ok("alter table ContactInfo add `passwordTime` int(11) NOT NULL DEFAULT '0'")) {
            $conf->update_schema_version(80);
        }
        if ($conf->sversion === 80
            && $conf->ql_ok("alter table PaperReview modify `reviewRound` int(11) NOT NULL DEFAULT '0'")
            && $conf->ql_ok("alter table PaperReviewArchive modify `reviewRound` int(11) NOT NULL DEFAULT '0'")) {
            $conf->update_schema_version(81);
        }
        if ($conf->sversion === 81
            && $conf->ql_ok("alter table PaperStorage add `filterType` int(3) DEFAULT NULL")
            && $conf->ql_ok("alter table PaperStorage add `originalStorageId` int(11) DEFAULT NULL")) {
            $conf->update_schema_version(82);
        }
        if ($conf->sversion === 82
            && $conf->ql_ok("update Settings set name='msg.resp_instrux' where name='msg.responseinstructions'")) {
            $conf->update_schema_version(83);
        }
        if ($conf->sversion === 83
            && $conf->ql_ok("alter table PaperComment add `commentRound` int(11) NOT NULL DEFAULT '0'")) {
            $conf->update_schema_version(84);
        }
        if ($conf->sversion === 84
            && $conf->ql_ok("insert ignore into Settings (name, value) select 'resp_active', value from Settings where name='resp_open'")) {
            $conf->update_schema_version(85);
        }
        if ($conf->sversion === 85) {
            Dbl::qx($conf->dblink, "DROP TABLE IF EXISTS `PCMember`");
            Dbl::qx($conf->dblink, "DROP TABLE IF EXISTS `ChairAssistant`");
            Dbl::qx($conf->dblink, "DROP TABLE IF EXISTS `Chair`");
            $conf->update_schema_version(86);
        }
        if ($conf->sversion === 86
            && $this->v86_transfer_address()) {
            $conf->update_schema_version(87);
        }
        if ($conf->sversion === 87) {
            Dbl::qx($conf->dblink, "DROP TABLE IF EXISTS `ContactAddress`");
            $conf->update_schema_version(88);
        }
        if ($conf->sversion === 88
            && $conf->ql_ok("alter table ContactInfo drop key `name`")
            && $conf->ql_ok("alter table ContactInfo drop key `affiliation`")
            && $conf->ql_ok("alter table ContactInfo drop key `email_3`")
            && $conf->ql_ok("alter table ContactInfo drop key `firstName_2`")
            && $conf->ql_ok("alter table ContactInfo drop key `lastName`")) {
            $conf->update_schema_version(89);
        }
        if ($conf->sversion === 89
            && $this->v90_unaccented_name()) {
            $conf->update_schema_version(90);
        }
        if ($conf->sversion === 90
            && $conf->ql_ok("alter table PaperReview add `reviewAuthorSeen` int(11) DEFAULT NULL")) {
            $conf->update_schema_version(91);
        }
        if ($conf->sversion === 91
            && $conf->ql_ok("alter table PaperReviewArchive add `reviewAuthorSeen` int(11) DEFAULT NULL")) {
            $conf->update_schema_version(92);
        }
        if ($conf->sversion === 92
            && $conf->ql_ok("alter table Paper drop key `titleAbstractText`")
            && $conf->ql_ok("alter table Paper drop key `allText`")
            && $conf->ql_ok("alter table Paper drop key `authorText`")
            && $conf->ql_ok("alter table Paper modify `authorInformation` varbinary(8192) DEFAULT NULL")
            && $conf->ql_ok("alter table Paper modify `abstract` varbinary(16384) DEFAULT NULL")
            && $conf->ql_ok("alter table Paper modify `collaborators` varbinary(8192) DEFAULT NULL")
            && $conf->ql_ok("alter table Paper modify `withdrawReason` varbinary(1024) DEFAULT NULL")) {
            $conf->update_schema_version(93);
        }
        if ($conf->sversion === 93
            && $conf->ql_ok("alter table TopicArea modify `topicName` varchar(200) DEFAULT NULL")) {
            $conf->update_schema_version(94);
        }
        if ($conf->sversion === 94
            && $conf->ql_ok("alter table PaperOption modify `data` varbinary(32768) DEFAULT NULL")) {
            foreach ($conf->options() as $xopt) {
                if ($xopt->type === "text")
                    $conf->ql_ok("delete from PaperOption where optionId={$xopt->id} and data=''");
            }
            $conf->update_schema_version(95);
        }
        if ($conf->sversion === 95
            && $conf->ql_ok("alter table Capability add unique key `salt` (`salt`)")
            && $conf->ql_ok("update Capability join CapabilityMap using (capabilityId) set Capability.salt=CapabilityMap.capabilityValue")) {
            Dbl::qx($conf->dblink, "drop table if exists `CapabilityMap`");
            $conf->update_schema_version(96);
        }
        if ($conf->sversion === 96
            && $conf->ql_ok("alter table ContactInfo add `passwordIsCdb` tinyint(1) NOT NULL DEFAULT '0'")) {
            $conf->update_schema_version(97);
        }
        if ($conf->sversion === 97
            && $conf->ql_ok("alter table PaperReview add `reviewWordCount` int(11) DEFAULT NULL")
            && $conf->ql_ok("alter table PaperReviewArchive add `reviewWordCount` int(11)  DEFAULT NULL")
            && $conf->ql_ok("alter table PaperReviewArchive drop key `reviewId`")
            && $conf->ql_ok("alter table PaperReviewArchive drop key `contactPaper`")
            && $conf->ql_ok("alter table PaperReviewArchive drop key `reviewSubmitted`")
            && $conf->ql_ok("alter table PaperReviewArchive drop key `reviewNeedsSubmit`")
            && $conf->ql_ok("alter table PaperReviewArchive drop key `reviewType`")
            && $conf->ql_ok("alter table PaperReviewArchive drop key `requestedBy`")) {
            $conf->update_schema_version(98);
        }
        if ($conf->sversion === 98) {
            $this->v112_review_word_counts();
            $conf->update_schema_version(99);
        }
        if ($conf->sversion === 99
            && $conf->ql_ok("alter table ContactInfo ENGINE=InnoDB")
            && $conf->ql_ok("alter table Paper ENGINE=InnoDB")
            && $conf->ql_ok("alter table PaperComment ENGINE=InnoDB")
            && $conf->ql_ok("alter table PaperConflict ENGINE=InnoDB")
            && $conf->ql_ok("alter table PaperOption ENGINE=InnoDB")
            && $conf->ql_ok("alter table PaperReview ENGINE=InnoDB")
            && $conf->ql_ok("alter table PaperStorage ENGINE=InnoDB")
            && $conf->ql_ok("alter table PaperTag ENGINE=InnoDB")
            && $conf->ql_ok("alter table PaperTopic ENGINE=InnoDB")
            && $conf->ql_ok("alter table Settings ENGINE=InnoDB")) {
            $conf->update_schema_version(100);
        }
        if ($conf->sversion === 100
            && $conf->ql_ok("alter table ActionLog ENGINE=InnoDB")
            && $conf->ql_ok("alter table Capability ENGINE=InnoDB")
            && $conf->ql_ok("alter table Formula ENGINE=InnoDB")
            && $conf->ql_ok("alter table MailLog ENGINE=InnoDB")
            && $conf->ql_ok("alter table PaperReviewArchive ENGINE=InnoDB")
            && $conf->ql_ok("alter table PaperReviewPreference ENGINE=InnoDB")
            && $conf->ql_ok("alter table PaperReviewRefused ENGINE=InnoDB")
            && $conf->ql_ok("alter table PaperWatch ENGINE=InnoDB")
            && $conf->ql_ok("alter table ReviewRating ENGINE=InnoDB")
            && $conf->ql_ok("alter table ReviewRequest ENGINE=InnoDB")
            && $conf->ql_ok("alter table TopicArea ENGINE=InnoDB")
            && $conf->ql_ok("alter table TopicInterest ENGINE=InnoDB")) {
            $conf->update_schema_version(101);
        }
        if ($conf->sversion === 101
            && $conf->ql_ok("alter table ActionLog modify `ipaddr` varbinary(32) DEFAULT NULL")
            && $conf->ql_ok("alter table MailLog modify `recipients` varbinary(200) NOT NULL")
            && $conf->ql_ok("alter table MailLog modify `q` varbinary(4096) DEFAULT NULL")
            && $conf->ql_ok("alter table MailLog modify `t` varbinary(200) DEFAULT NULL")
            && $conf->ql_ok("alter table Paper modify `mimetype` varbinary(80) NOT NULL DEFAULT ''")
            && $conf->ql_ok("alter table PaperStorage modify `mimetype` varbinary(80) NOT NULL DEFAULT ''")
            && $conf->ql_ok("alter table PaperStorage modify `filename` varbinary(255) DEFAULT NULL")
            && $conf->ql_ok("alter table PaperStorage modify `infoJson` varbinary(8192) DEFAULT NULL")) {
            $conf->update_schema_version(102);
        }
        if ($conf->sversion === 102
            && $conf->ql_ok("alter table PaperReview modify `paperSummary` mediumblob")
            && $conf->ql_ok("alter table PaperReview modify `commentsToAuthor` mediumblob")
            && $conf->ql_ok("alter table PaperReview modify `commentsToPC` mediumblob")
            && $conf->ql_ok("alter table PaperReview modify `commentsToAddress` mediumblob")
            && $conf->ql_ok("alter table PaperReview modify `weaknessOfPaper` mediumblob")
            && $conf->ql_ok("alter table PaperReview modify `strengthOfPaper` mediumblob")
            && $conf->ql_ok("alter table PaperReview modify `textField7` mediumblob")
            && $conf->ql_ok("alter table PaperReview modify `textField8` mediumblob")
            && $conf->ql_ok("alter table PaperReviewArchive modify `paperSummary` mediumblob")
            && $conf->ql_ok("alter table PaperReviewArchive modify `commentsToAuthor` mediumblob")
            && $conf->ql_ok("alter table PaperReviewArchive modify `commentsToPC` mediumblob")
            && $conf->ql_ok("alter table PaperReviewArchive modify `commentsToAddress` mediumblob")
            && $conf->ql_ok("alter table PaperReviewArchive modify `weaknessOfPaper` mediumblob")
            && $conf->ql_ok("alter table PaperReviewArchive modify `strengthOfPaper` mediumblob")
            && $conf->ql_ok("alter table PaperReviewArchive modify `textField7` mediumblob")
            && $conf->ql_ok("alter table PaperReviewArchive modify `textField8` mediumblob")) {
            $conf->update_schema_version(103);
        }
        if ($conf->sversion === 103
            && $conf->ql_ok("alter table Paper modify `title` varbinary(256) DEFAULT NULL")
            && $conf->ql_ok("alter table Paper drop key `title`")) {
            $conf->update_schema_version(104);
        }
        if ($conf->sversion === 104
            && $conf->ql_ok("alter table PaperReview add `reviewFormat` tinyint(1) DEFAULT NULL")
            && $conf->ql_ok("alter table PaperReviewArchive add `reviewFormat` tinyint(1) DEFAULT NULL")) {
            $conf->update_schema_version(105);
        }
        if ($conf->sversion === 105
            && $conf->ql_ok("alter table PaperComment add `commentFormat` tinyint(1) DEFAULT NULL")) {
            $conf->update_schema_version(106);
        }
        if ($conf->sversion === 106
            && $conf->ql_ok("alter table PaperComment add `authorOrdinal` int(11) NOT NULL default '0'")
            && $conf->ql_ok("update PaperComment set authorOrdinal=ordinal where commentType>=0x30000" /* CT_AUTHOR */)) {
            $conf->update_schema_version(107);
        }
        if ($conf->sversion === 107) {
            $this->v108_repair_comment_ordinals();
        }

        // contact tags format change
        if ($conf->sversion === 108
            && $conf->ql_ok("update ContactInfo set contactTags=substr(replace(contactTags, ' ', '#0 ') from 3)")
            && $conf->ql_ok("update ContactInfo set contactTags=replace(contactTags, '#0#0 ', '#0 ')")) {
            $conf->update_schema_version(109);
        }
        if ($conf->sversion === 109
            && $conf->ql_ok("alter table PaperTag modify `tagIndex` float NOT NULL DEFAULT '0'")) {
            $conf->update_schema_version(110);
        }
        if ($conf->sversion === 110
            && $conf->ql_ok("alter table ContactInfo drop `faxPhoneNumber`")
            && $conf->ql_ok("alter table ContactInfo add `country` varbinary(256) default null")
            && $this->v111_transfer_country()) {
            $conf->update_schema_version(111);
        }
        if ($conf->sversion === 111) {
            $this->v112_review_word_counts();
            $conf->update_schema_version(112);
        }
        if ($conf->sversion === 112
            && $conf->ql_ok("alter table ContactInfo add `passwordUseTime` int(11) NOT NULL DEFAULT '0'")
            && $conf->ql_ok("alter table ContactInfo add `updateTime` int(11) NOT NULL DEFAULT '0'")
            && $conf->ql_ok("update ContactInfo set passwordUseTime=lastLogin where passwordUseTime=0")) {
            $conf->update_schema_version(113);
        }
        if ($conf->sversion === 113) {
            Dbl::qx($conf->dblink, "drop table if exists `PaperReviewArchive`");
            $conf->update_schema_version(114);
        }
        if ($conf->sversion === 114
            && $conf->ql_ok("alter table PaperReview add `timeDisplayed` int(11) NOT NULL DEFAULT '0'")
            && $conf->ql_ok("alter table PaperComment add `timeDisplayed` int(11) NOT NULL DEFAULT '0'")) {
            $conf->update_schema_version(115);
        }
        if ($conf->sversion === 115
            && $conf->ql_ok("alter table Formula drop column `authorView`")) {
            $conf->update_schema_version(116);
        }
        if ($conf->sversion === 116
            && $conf->ql_ok("alter table PaperComment add `commentOverflow` longblob DEFAULT NULL")) {
            $conf->update_schema_version(117);
        }
        if ($conf->sversion === 117
            && $this->drop_keys_if_exist("PaperTopic", ["paperTopic", "PRIMARY"])
            && $conf->ql_ok("alter table PaperTopic add primary key (`paperId`,`topicId`)")
            && $this->drop_keys_if_exist("TopicInterest", ["contactTopic", "PRIMARY"])
            && $conf->ql_ok("alter table TopicInterest add primary key (`contactId`,`topicId`)")) {
            $conf->update_schema_version(118);
        }
        if ($conf->sversion === 118
            && $this->drop_keys_if_exist("PaperTag", ["paperTag", "PRIMARY"])
            && $conf->ql_ok("alter table PaperTag add primary key (`paperId`,`tag`)")
            && $this->drop_keys_if_exist("PaperReviewPreference", ["paperId", "PRIMARY"])
            && $conf->ql_ok("alter table PaperReviewPreference add primary key (`paperId`,`contactId`)")
            && $this->drop_keys_if_exist("PaperConflict", ["contactPaper", "contactPaperConflict", "PRIMARY"])
            && $conf->ql_ok("alter table PaperConflict add primary key (`contactId`,`paperId`)")
            && $conf->ql_ok("alter table MailLog modify `paperIds` blob")
            && $conf->ql_ok("alter table MailLog modify `cc` blob")
            && $conf->ql_ok("alter table MailLog modify `replyto` blob")
            && $conf->ql_ok("alter table MailLog modify `subject` blob")
            && $conf->ql_ok("alter table MailLog modify `emailBody` blob")) {
            $conf->update_schema_version(119);
        }
        if ($conf->sversion === 119
            && $this->drop_keys_if_exist("PaperWatch", ["contactPaper", "contactPaperWatch", "PRIMARY"])
            && $conf->ql_ok("alter table PaperWatch add primary key (`paperId`,`contactId`)")) {
            $conf->update_schema_version(120);
        }
        if ($conf->sversion === 120
            && $conf->ql_ok("alter table Paper add `paperFormat` tinyint(1) DEFAULT NULL")) {
            $conf->update_schema_version(121);
        }
        if ($conf->sversion === 121
            && $conf->ql_ok("update PaperReview r, Paper p set r.reviewNeedsSubmit=1 where p.paperId=r.paperId and p.timeSubmitted<=0 and r.reviewSubmitted is null")
            && $conf->ql_ok("update PaperReview r, Paper p, PaperReview rq set r.reviewNeedsSubmit=0 where p.paperId=r.paperId and p.paperId=rq.paperId and p.timeSubmitted<=0 and r.reviewType=" . REVIEW_SECONDARY . " and r.contactId=rq.requestedBy and rq.reviewType<" . REVIEW_SECONDARY . " and rq.reviewSubmitted is not null")
            && $conf->ql_ok("update PaperReview r, Paper p, PaperReview rq set r.reviewNeedsSubmit=-1 where p.paperId=r.paperId and p.paperId=rq.paperId and p.timeSubmitted<=0 and r.reviewType=" . REVIEW_SECONDARY . " and r.contactId=rq.requestedBy and rq.reviewType<" . REVIEW_SECONDARY . " and r.reviewNeedsSubmit=0")) {
            $conf->update_schema_version(122);
        }
        if ($conf->sversion === 122
            && $conf->ql_ok("alter table ReviewRequest add `reviewRound` int(1) DEFAULT NULL")) {
            $conf->update_schema_version(123);
        }
        if ($conf->sversion === 123
            && $conf->ql_ok("update ContactInfo set disabled=1 where password='' and email regexp " . Dbl::utf8ci($conf->dblink, "'^anonymous[0-9]*\$'"))) {
            $conf->update_schema_version(124);
        }
        if ($conf->sversion === 124
            && $conf->ql_ok("update ContactInfo set password='' where password='*' or passwordIsCdb")) {
            $conf->update_schema_version(125);
        }
        if ($conf->sversion === 125
            && $conf->ql_ok("alter table ContactInfo drop column `passwordIsCdb`")) {
            $conf->update_schema_version(126);
        }
        if ($conf->sversion === 126
            && $conf->ql_ok("update ContactInfo set disabled=1, password='' where email regexp " . Dbl::utf8ci($conf->dblink, "'^anonymous[0-9]*\$'"))) {
            $conf->update_schema_version(127);
        }
        if ($conf->sversion === 127
            && $conf->ql_ok("update PaperReview set reviewWordCount=null")) {
            $conf->update_schema_version(128);
        }
        if ($conf->sversion === 128
            && $this->v129_bad_comment_timeDisplayed()) {
            $conf->update_schema_version(129);
        }
        if ($conf->sversion === 129
            && $conf->ql_ok("update PaperComment set timeDisplayed=1 where timeDisplayed=0 and timeNotified>0")) {
            $conf->update_schema_version(130);
        }
        if ($conf->sversion === 130) {
            Dbl::qx($conf->dblink, "DROP TABLE IF EXISTS `PaperTagAnno`");
            if ($conf->ql_ok("CREATE TABLE `PaperTagAnno` (
      `tag` varchar(40) NOT NULL,   # see TAG_MAXLEN in header.php
      `annoId` int(11) NOT NULL,
      `tagIndex` float NOT NULL DEFAULT '0',
      `heading` varbinary(8192) DEFAULT NULL,
      `annoFormat` tinyint(1) DEFAULT NULL,
      `infoJson` varbinary(32768) DEFAULT NULL,
      PRIMARY KEY (`tag`,`annoId`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8")) {
                $conf->update_schema_version(131);
            }
        }
        if ($conf->sversion === 131
            && $conf->ql_ok("alter table PaperStorage modify `infoJson` varbinary(32768) DEFAULT NULL")) {
            $conf->update_schema_version(132);
        }
        if ($conf->sversion === 132) {
            Dbl::qx($conf->dblink, "DROP TABLE IF EXISTS `Mimetype`");
            if ($conf->ql_ok("CREATE TABLE `Mimetype` (
      `mimetypeid` int(11) NOT NULL,
      `mimetype` varbinary(200) NOT NULL,
      `extension` varbinary(10) DEFAULT NULL,
      `description` varbinary(200) DEFAULT NULL,
      `inline` tinyint(1) NOT NULL DEFAULT '0',
      PRIMARY KEY (`mimetypeid`),
      UNIQUE KEY `mimetypeid` (`mimetypeid`),
      UNIQUE KEY `mimetype` (`mimetype`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8")) {
                $conf->update_schema_version(133);
            }
        }
        if ($conf->sversion === 133) {
            $conf->update_schema_version(134);
        }
        if ($conf->sversion === 134) {
            foreach (Dbl::fetch_first_columns($conf->dblink, "select distinct mimetype from PaperStorage") as $mt) {
                Mimetype::lookup($mt);
            }
            if (!Dbl::has_error()) {
                $conf->update_schema_version(135);
            }
        }
        if ($conf->sversion === 135
            && $conf->ql_ok("alter table PaperStorage add `mimetypeid` int(11) NOT NULL DEFAULT '0'")
            && $conf->ql_ok("update PaperStorage, Mimetype set PaperStorage.mimetypeid=Mimetype.mimetypeid where PaperStorage.mimetype=Mimetype.mimetype")) {
            $conf->update_schema_version(136);
        }
        if ($conf->sversion === 136
            && $conf->ql_ok("alter table PaperStorage drop key `paperId`")
            && $conf->ql_ok("alter table PaperStorage drop key `mimetype`")
            && $conf->ql_ok("alter table PaperStorage add key `byPaper` (`paperId`,`documentType`,`timestamp`,`paperStorageId`)")) {
            $conf->update_schema_version(137);
        }
        if ($conf->sversion === 137) {
            $conf->update_schema_version(138);
        }
        if ($conf->sversion === 138 || $conf->sversion === 139) {
            Dbl::qx($conf->dblink, "DROP TABLE IF EXISTS `FilteredDocument`");
            if ($conf->ql_ok("CREATE TABLE `FilteredDocument` (
      `inDocId` int(11) NOT NULL,
      `filterType` int(11) NOT NULL,
      `outDocId` int(11) NOT NULL,
      `createdAt` int(11) NOT NULL,
      PRIMARY KEY (`inDocId`,`filterType`),
      UNIQUE KEY `inDocFilter` (`inDocId`,`filterType`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8")) {
                $conf->update_schema_version(140);
            }
        }
        if ($conf->sversion === 140
            && $conf->ql_ok("update Paper p join PaperStorage ps on (p.paperStorageId>1 and p.finalPaperStorageId<=0 and p.paperStorageId=ps.paperStorageId) set p.sha1=ps.sha1, p.timestamp=ps.timestamp, p.mimetype=ps.mimetype, p.size=ps.size where p.sha1!=ps.sha1 or p.timestamp!=ps.timestamp or p.mimetype!=ps.mimetype or p.size!=ps.size")
            && $conf->ql_ok("update Paper p join PaperStorage ps on (p.finalPaperStorageId>0 and p.finalPaperStorageId=ps.paperStorageId) set p.sha1=ps.sha1, p.timestamp=ps.timestamp, p.mimetype=ps.mimetype, p.size=ps.size where p.sha1!=ps.sha1 or p.timestamp!=ps.timestamp or p.mimetype!=ps.mimetype or p.size!=ps.size")) {
            $conf->update_schema_version(141);
        }
        if ($conf->sversion === 141
            && $conf->ql_ok("delete from Settings where name='pc'")) {
            $conf->update_schema_version(142);
        }
        if ($conf->sversion === 142
            && $conf->ql_ok("alter table PaperReview add `reviewAuthorModified` int(1) DEFAULT NULL")) {
            $conf->update_schema_version(143);
        }
        if ($conf->sversion === 143
            && $conf->ql_ok("alter table PaperReview add `timeApprovalRequested` int(11) NOT NULL DEFAULT '0'")) {
            $conf->update_schema_version(144);
        }
        if ($conf->sversion === 144
            && $conf->ql_ok("alter table Paper add `pdfFormatStatus` int(11) NOT NULL DEFAULT '0'")) {
            $conf->update_schema_version(145);
        }
        if ($conf->sversion === 145
            && $conf->ql_ok("alter table MailLog add `fromNonChair` tinyint(1) NOT NULL DEFAULT '0'")) {
            $conf->update_schema_version(146);
        }
        if ($conf->sversion === 146
            && $conf->ql_ok("alter table Paper add `timeModified` int(11) NOT NULL DEFAULT '0'")) {
            $conf->update_schema_version(147);
        }
        if ($conf->sversion === 147
            && $conf->ql_ok("alter table Capability change `capabilityId` `capabilityId` int(11) NOT NULL")
            && $this->drop_keys_if_exist("Capability", ["capabilityId", "PRIMARY"])
            && $conf->ql_ok("alter table Capability add primary key (`salt`)")
            && $conf->ql_ok("alter table Capability drop column `capabilityId`")) {
            $conf->update_schema_version(148);
        }
        if ($conf->sversion === 148
            && $conf->ql_ok("alter table ReviewRating add `paperId` int(11) NOT NULL DEFAULT '0'")
            && $conf->ql_ok("update ReviewRating join PaperReview using (reviewId) set ReviewRating.paperId=PaperReview.paperId")
            && $conf->ql_ok("alter table ReviewRating change `paperId` `paperId` int(11) NOT NULL")
            && $this->drop_keys_if_exist("ReviewRating", ["reviewContact", "reviewContactRating"])
            && $conf->ql_ok("alter table ReviewRating add primary key (`paperId`,`reviewId`,`contactId`)")) {
            $conf->update_schema_version(149);
        }
        if ($conf->sversion === 149
            && $this->drop_keys_if_exist("PaperReview", ["PRIMARY"])
            && $conf->ql_ok("alter table PaperReview add primary key (`paperId`,`reviewId`)")) {
            $conf->update_schema_version(150);
        }
        if ($conf->sversion === 150
            && $this->drop_keys_if_exist("PaperComment", ["PRIMARY"])
            && $conf->ql_ok("alter table PaperComment add primary key (`paperId`,`commentId`)")
            && $this->drop_keys_if_exist("PaperStorage", ["PRIMARY"])
            && $conf->ql_ok("alter table PaperStorage add primary key (`paperId`,`paperStorageId`)")) {
            $conf->update_schema_version(151);
        }
        if ($conf->sversion === 151
            && $this->drop_keys_if_exist("ContactInfo", ["rolesCid", "rolesContactId", "contactIdRoles"])
            && $conf->ql_ok("alter table ContactInfo add key `rolesContactId` (`roles`,`contactId`)")) {
            $conf->update_schema_version(152);
        }
        if ($conf->sversion === 152
            && $this->drop_keys_if_exist("PaperReview", ["reviewSubmitted"])
            && $this->drop_keys_if_exist("PaperComment", ["timeModified", "paperId", "contactPaper"])
            && $conf->ql_ok("alter table PaperComment add key `timeModifiedContact` (`timeModified`,`contactId`)")
            && $conf->ql_ok("alter table PaperReview add key `reviewSubmittedContact` (`reviewSubmitted`,`contactId`)")) {
            $conf->update_schema_version(153);
        }
        if ($conf->sversion === 153
            && $this->v154_mimetype_extensions()) {
            $conf->update_schema_version(154);
        }
        if ($conf->sversion === 154) {
            if ($conf->fetch_value("select tag from PaperTag where tag like ':%:' limit 1")) {
                $conf->save_setting("has_colontag", 1);
            }
            $conf->update_schema_version(155);
        }
        if ($conf->sversion === 155) {
            if ($conf->fetch_value("select tag from PaperTag where tag like '%:' limit 1")) {
                $conf->save_setting("has_colontag", 1);
            }
            $conf->update_schema_version(156);
        }
        if ($conf->sversion === 156
            && $conf->ql_ok("delete from TopicInterest where interest is null")
            && $conf->ql_ok("alter table TopicInterest change `interest` `interest` int(1) NOT NULL")
            && $conf->ql_ok("update TopicInterest set interest=1 where interest=2")
            && $conf->ql_ok("update TopicInterest set interest=2 where interest=4")
            && $conf->ql_ok("delete from TopicInterest where interest=0")) {
            $conf->update_schema_version(157);
        }
        if ($conf->sversion === 157
            && $conf->ql_ok("alter table PaperOption drop key `paperOption`")
            && $conf->ql_ok("alter table PaperOption add primary key (`paperId`,`optionId`,`value`)")
            && $conf->ql_ok("alter table PaperOption change `data` `data` varbinary(32767) DEFAULT NULL")
            && $conf->ql_ok("alter table PaperOption add `dataOverflow` longblob DEFAULT NULL")) {
            $conf->update_schema_version(158);
        }
        if ($conf->sversion === 158
            && $conf->ql_ok("alter table ContactInfo drop key `rolesContactId`")
            && $conf->ql_ok("alter table ContactInfo add unique key `rolesContactId` (`roles`,`contactId`)")) {
            $conf->update_schema_version(159);
        }
        if ($conf->sversion === 159
            && $conf->ql_ok("alter table ActionLog drop key `logId`")
            && $conf->ql_ok("alter table Capability drop key `salt`")
            && $conf->ql_ok("alter table ContactInfo drop key `contactId`")
            && $conf->ql_ok("alter table FilteredDocument drop key `inDocFilter`")
            && $conf->ql_ok("alter table Formula drop key `formulaId`")
            && $conf->ql_ok("alter table Mimetype drop key `mimetypeid`")
            && $conf->ql_ok("alter table Paper drop key `paperId`")
            && $conf->ql_ok("alter table TopicArea drop key `topicId`")) {
            $conf->update_schema_version(160);
        }
        if ($conf->sversion === 160
            && $conf->ql_ok("alter table Paper change `sha1` `sha1` varbinary(64) NOT NULL DEFAULT ''")
            && $conf->ql_ok("alter table PaperStorage change `sha1` `sha1` varbinary(64) NOT NULL DEFAULT ''")) {
            $conf->update_schema_version(161);
        }
        if ($conf->sversion === 161
            && $conf->ql_ok("alter table PaperTag change `tag` `tag` varbinary(80) NOT NULL")
            && $conf->ql_ok("alter table PaperTagAnno change `tag` `tag` varbinary(80) NOT NULL")) {
            $conf->update_schema_version(162);
        }
        if ($conf->sversion === 162
            && $conf->ql_ok("alter table PaperTag change `tag` `tag` varchar(80) NOT NULL")
            && $conf->ql_ok("alter table PaperTagAnno change `tag` `tag` varchar(80) NOT NULL")) {
            $conf->update_schema_version(163);
        }
        if ($conf->sversion === 163
            && $conf->ql_ok("alter table Capability change `timeExpires` `timeExpires` bigint(11) NOT NULL")
            && $conf->ql_ok("alter table ContactInfo change `passwordTime` `passwordTime` bigint(11) NOT NULL DEFAULT '0'")
            && $conf->ql_ok("alter table ContactInfo change `passwordUseTime` `passwordUseTime` bigint(11) NOT NULL DEFAULT '0'")
            && $conf->ql_ok("alter table ContactInfo change `creationTime` `creationTime` bigint(11) NOT NULL DEFAULT '0'")
            && $conf->ql_ok("alter table ContactInfo change `updateTime` `updateTime` bigint(11) NOT NULL DEFAULT '0'")
            && $conf->ql_ok("alter table ContactInfo change `lastLogin` `lastLogin` bigint(11) NOT NULL DEFAULT '0'")
            && $conf->ql_ok("alter table FilteredDocument change `createdAt` `createdAt` bigint(11) NOT NULL")
            && $conf->ql_ok("alter table Formula change `timeModified` `timeModified` bigint(11) NOT NULL DEFAULT '0'")
            && $conf->ql_ok("alter table Paper change `timeSubmitted` `timeSubmitted` bigint(11) NOT NULL DEFAULT '0'")
            && $conf->ql_ok("alter table Paper change `timeWithdrawn` `timeWithdrawn` bigint(11) NOT NULL DEFAULT '0'")
            && $conf->ql_ok("alter table Paper change `timeFinalSubmitted` `timeFinalSubmitted` bigint(11) NOT NULL DEFAULT '0'")
            && $conf->ql_ok("alter table Paper change `timeModified` `timeModified` bigint(11) NOT NULL DEFAULT '0'")
            && $conf->ql_ok("alter table Paper change `timestamp` `timestamp` bigint(11) NOT NULL DEFAULT '0'")
            && $conf->ql_ok("alter table Paper change `pdfFormatStatus` `pdfFormatStatus` bigint(11) NOT NULL DEFAULT '0'")
            && $conf->ql_ok("alter table PaperComment change `timeModified` `timeModified` bigint(11) NOT NULL")
            && $conf->ql_ok("alter table PaperComment change `timeNotified` `timeNotified` bigint(11) NOT NULL DEFAULT '0'")
            && $conf->ql_ok("alter table PaperComment change `timeDisplayed` `timeDisplayed` bigint(11) NOT NULL DEFAULT '0'")
            && $conf->ql_ok("alter table PaperOption change `value` `value` bigint(11) NOT NULL DEFAULT '0'")
            && $conf->ql_ok("alter table PaperReview change `timeRequested` `timeRequested` bigint(11) NOT NULL DEFAULT '0'")
            && $conf->ql_ok("alter table PaperReview change `timeRequestNotified` `timeRequestNotified` bigint(11) NOT NULL DEFAULT '0'")
            && $conf->ql_ok("alter table PaperReview change `reviewModified` `reviewModified` bigint(1) DEFAULT NULL")
            && $conf->ql_ok("alter table PaperReview change `reviewAuthorModified` `reviewAuthorModified` bigint(1) DEFAULT NULL")
            && $conf->ql_ok("alter table PaperReview change `reviewSubmitted` `reviewSubmitted` bigint(1) DEFAULT NULL")
            && $conf->ql_ok("alter table PaperReview change `reviewNotified` `reviewNotified` bigint(1) DEFAULT NULL")
            && $conf->ql_ok("alter table PaperReview change `reviewAuthorNotified` `reviewAuthorNotified` bigint(11) NOT NULL DEFAULT '0'")
            && $conf->ql_ok("alter table PaperReview change `reviewAuthorSeen` `reviewAuthorSeen` bigint(1) DEFAULT NULL")
            && $conf->ql_ok("alter table PaperReview change `timeDisplayed` `timeDisplayed` bigint(11) NOT NULL DEFAULT '0'")
            && $conf->ql_ok("alter table PaperReview change `timeApprovalRequested` `timeApprovalRequested` bigint(11) NOT NULL DEFAULT '0'")
            && $conf->ql_ok("alter table PaperStorage change `timestamp` `timestamp` bigint(11) NOT NULL")
            && $conf->ql_ok("alter table Settings change `value` `value` bigint(11) NOT NULL")) {
            $conf->update_schema_version(164);
        }
        if ($conf->sversion === 164
            && $conf->ql_ok("alter table Paper change `title` `title` varbinary(512) DEFAULT NULL")) {
            $conf->update_schema_version(165);
        }
        if ($conf->sversion === 165
            && $conf->ql_ok("alter table TopicArea drop key `topicName`")
            && $conf->ql_ok("alter table TopicArea change `topicName` `topicName` varbinary(1024) DEFAULT NULL")) {
            $conf->update_schema_version(166);
        }
        if ($conf->sversion === 166
            && $conf->ql_ok("alter table PaperReviewPreference drop key `contactPaper`")) {
            $conf->update_schema_version(167);
        }
        if ($conf->sversion === 167
            && $conf->ql_ok("update PaperReview set reviewOrdinal=0 where reviewOrdinal is null")
            && $conf->ql_ok("alter table PaperReview change `reviewOrdinal` `reviewOrdinal` int(1) NOT NULL DEFAULT '0'")) {
            $conf->update_schema_version(168);
        }
        if ($conf->sversion === 168
            && $conf->ql_ok("update PaperReview set reviewModified=0 where reviewModified is null")
            && $conf->ql_ok("alter table PaperReview change `reviewModified` `reviewModified` bigint(1) NOT NULL DEFAULT '0'")) {
            $conf->update_schema_version(169);
        }
        if ($conf->sversion === 169) {
            if ($conf->fetch_ivalue("select exists (select * from TopicArea)")) {
                $conf->save_setting("has_topics", 1);
            }
            $conf->update_schema_version(170);
        }
        if ($conf->sversion === 170
            && $conf->ql_ok("alter table ActionLog drop key `contactId`")
            && $conf->ql_ok("alter table ActionLog drop key `paperId`")
            && $conf->ql_ok("alter table ActionLog add `destContactId` int(11) NOT NULL DEFAULT '0'")) {
            $conf->update_schema_version(171);
        }
        if ($conf->sversion === 171) {
            Dbl::qx($conf->dblink, "DROP TABLE IF EXISTS `DeletedContactInfo`");
            if ($conf->ql_ok("CREATE TABLE `DeletedContactInfo` (
      `contactId` int(11) NOT NULL,
      `firstName` varchar(60) NOT NULL,
      `lastName` varchar(60) NOT NULL,
      `email` varchar(120) NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8")) {
                $conf->update_schema_version(172);
            }
        }
        if ($conf->sversion === 172
            && $conf->ql_ok("alter table DeletedContactInfo add `unaccentedName` varchar(120) NOT NULL DEFAULT ''")
            && $conf->ql_ok("alter table DeletedContactInfo change `unaccentedName` `unaccentedName` varchar(120) NOT NULL")) {
            $conf->update_schema_version(173);
        }
        if ($conf->sversion === 173
            && $this->v174_paper_review_tfields()) {
            $conf->update_schema_version(174);
        }
        if ($conf->sversion === 174
            && $this->v175_paper_review_null_main_fields()) {
            $conf->update_schema_version(175);
        }
        if ($conf->sversion === 175
            && $this->v176_paper_review_drop_main_fields()) {
            $conf->update_schema_version(176);
        }
        if ($conf->sversion === 176) {
            if (($x = $conf->setting_data("scoresort_default"))) {
                $conf->save_setting("scoresort_default", null);
                $conf->save_setting("opt.defaultScoreSort", 1, $x);
            }
            $conf->update_schema_version(177);
        }
        if ($conf->sversion === 177) {
            set_time_limit(300); // might take a while
            if (!$this->check_column_exists("PaperStorage", "mimetypeid")
                || $conf->ql_ok("alter table PaperStorage drop `mimetypeid`")) {
                Dbl::qx($conf->dblink, "drop table if exists `Mimetype`");
                $conf->update_schema_version(178);
            }
        }
        if ($conf->sversion === 178
            && $conf->ql_ok("delete from Settings where name='papersub'")) {
            $conf->update_papersub_setting(0);
            $conf->update_schema_version(179);
        }
        if ($conf->sversion === 179
            && $conf->ql_ok("alter table ContactInfo change `affiliation` `affiliation` varbinary(2048) NOT NULL DEFAULT ''")
            && $conf->ql_ok("alter table ContactInfo change `voicePhoneNumber` `voicePhoneNumber` varbinary(256) DEFAULT NULL")) {
            $conf->update_schema_version(180);
        }
        if ($conf->sversion === 180
            && $conf->ql_ok("alter table ActionLog change `ipaddr` `ipaddr` varbinary(39) DEFAULT NULL")) {
            $conf->update_schema_version(181);
        }
        if ($conf->sversion === 181
            && $conf->ql_ok("alter table ContactInfo change `firstName` `firstName` varbinary(120) NOT NULL DEFAULT ''")
            && $conf->ql_ok("alter table ContactInfo change `lastName` `lastName` varbinary(120) NOT NULL DEFAULT ''")
            && $conf->ql_ok("alter table ContactInfo change `unaccentedName` `unaccentedName` varbinary(240) NOT NULL DEFAULT ''")
            && $conf->ql_ok("alter table DeletedContactInfo change `firstName` `firstName` varbinary(120) NOT NULL")
            && $conf->ql_ok("alter table DeletedContactInfo change `lastName` `lastName` varbinary(120) NOT NULL")
            && $conf->ql_ok("alter table DeletedContactInfo change `unaccentedName` `unaccentedName` varbinary(240) NOT NULL")) {
            $conf->update_schema_version(182);
        }
        if ($conf->sversion === 182
            && $conf->ql_ok("alter table ContactInfo add `birthday` int(11) DEFAULT NULL")
            && $conf->ql_ok("alter table ContactInfo add `gender` varbinary(24) DEFAULT NULL")) {
            $conf->update_schema_version(183);
        }
        if ($conf->sversion === 183
            // good=1,1; too short=0,4; too vague=-1,8; too narrow=-4,16;
            // not constructive=-2,32; not correct=-3,64
            && $conf->ql_ok("update ReviewRating set rating=case rating when 0 then 4 when -1 then 8 when -4 then 16 when -2 then 32 when -3 then 64 else if(rating<0,2,1) end")) {
            $conf->update_schema_version(184);
        }
        if ($conf->sversion === 184
            && $conf->ql_ok("alter table PaperReview drop key `reviewSubmittedContact`")) {
            $conf->update_schema_version(185);
        }
        if ($conf->sversion === 185
            && $conf->ql_ok("alter table ContactInfo change `voicePhoneNumber` `phone` varbinary(64) DEFAULT NULL")) {
            $conf->update_schema_version(186);
        }
        if ($conf->sversion === 186
            && $conf->ql_ok("alter table PaperReviewRefused add primary key (`paperId`,`contactId`)")
            && $conf->ql_ok("alter table PaperReviewRefused drop key `paperId`")
            && $conf->ql_ok("alter table PaperReviewRefused drop key `contactId`")
            && $conf->ql_ok("alter table PaperReviewRefused drop key `requestedBy`")) {
            $conf->update_schema_version(187);
        }
        if ($conf->sversion === 187
            && $conf->ql_ok("alter table ReviewRequest change `email` `email` varchar(120) NOT NULL")
            && $conf->ql_ok("alter table ReviewRequest add primary key (`paperId`,`email`)")
            && $conf->ql_ok("alter table ReviewRequest drop key `paperEmail`")
            && $conf->ql_ok("alter table ReviewRequest drop key `paperId`")
            && $conf->ql_ok("alter table ReviewRequest drop key `requestedBy`")) {
            $conf->update_schema_version(188);
        }
        if ($conf->sversion === 188
            && $this->v189_split_review_request_name()) {
            $conf->update_schema_version(189);
        }
        if ($conf->sversion === 189
            && $conf->ql_ok("alter table ReviewRequest add `affiliation` varbinary(2048) DEFAULT NULL")) {
            $conf->update_schema_version(190);
        }
        if ($conf->sversion === 190) {
            if ($conf->setting("rev_notifychair") > 0) {
                $conf->ql_ok("update ContactInfo set defaultWatch=defaultWatch|" . Contact::WATCH_REVIEW_ALL . " where roles!=0 and (roles&" . Contact::ROLE_CHAIR . ")!=0");
                $conf->ql_ok("delete from Settings where name=?", "rev_notifychair");
            }
            $conf->update_schema_version(191);
        }
        if ($conf->sversion === 191) {
            $this->v192_missing_sha1();
            $conf->update_schema_version(192);
        }
        if ($conf->sversion === 192
            && $conf->ql_ok("alter table PaperStorage drop key `byPaper`")) {
            $conf->update_schema_version(193);
        }
        if ($conf->sversion === 193
            && $conf->ql_ok("alter table Settings change `name` `name` varbinary(256) NOT NULL")
            && $conf->ql_ok("alter table Settings add primary key (`name`)")
            && $conf->ql_ok("alter table Settings drop key `name`")) {
            $conf->update_schema_version(194);
        }
        if ($conf->sversion === 194
            && $conf->ql_ok("alter table ContactInfo drop key `rolesContactId`")
            && $conf->ql_ok("alter table ContactInfo add key `roles` (`roles`)")
            && $conf->ql_ok("alter table ContactInfo drop key `fullName`")
            && $conf->ql_ok("alter table PaperReview drop key `contactPaper`")
            && $conf->ql_ok("alter table PaperReview add key `contactId` (`contactId`)")
            && $conf->ql_ok("alter table PaperReview drop key `reviewNeedsSubmit`")
            && $conf->ql_ok("alter table PaperReview drop key `paperId`")) {
            $conf->update_schema_version(195);
        }
        if ($conf->sversion === 195) {
            set_time_limit(300);
            if ($conf->ql_ok("alter table PaperStorage add `inactive` tinyint(1) NOT NULL DEFAULT '0'")
                && $conf->ql_ok("update PaperStorage set inactive=1")
                && $conf->ql_ok("update PaperStorage join Paper on (Paper.paperId=PaperStorage.paperId and Paper.paperStorageId=PaperStorage.paperStorageId) set PaperStorage.inactive=0")
                && $conf->ql_ok("update PaperStorage join Paper on (Paper.paperId=PaperStorage.paperId and Paper.finalPaperStorageId=PaperStorage.paperStorageId) set PaperStorage.inactive=0")
                && $conf->ql_ok("update PaperStorage join PaperOption on (PaperOption.paperId=PaperStorage.paperId and PaperOption.value=PaperStorage.paperStorageId) set PaperStorage.inactive=0")) {
                $conf->update_schema_version(196);
            }
        }
        if ($conf->sversion === 196) {
            Dbl::qx($conf->dblink, "drop table if exists `DocumentLink`");
            if ($conf->ql_ok("create table `DocumentLink` (
      `paperId` int(11) NOT NULL,
      `linkId` int(11) NOT NULL,
      `linkType` int(11) NOT NULL,
      `documentId` int(11) NOT NULL,
      PRIMARY KEY (`paperId`,`linkId`,`linkType`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8")) {
                $conf->update_schema_version(197);
            }
        }
        if ($conf->sversion === 197
            && $conf->ql_ok("alter table PaperConflict add key `paperId` (`paperId`)")) {
            $conf->update_schema_version(198);
        }
        if ($conf->sversion === 198
            && $this->v199_selector_options()) {
            $conf->update_schema_version(199);
        }
        if ($conf->sversion === 199
            && $this->v200_missing_review_ordinals()) {
            $conf->update_schema_version(200);
        }
        if ($conf->sversion === 200
            && $conf->ql_ok("alter table ActionLog change `destContactId` `destContactId` int(11) DEFAULT NULL")
            && $conf->ql_ok("update ActionLog set destContactId=null where destContactId=0 or destContactId=contactId")
            && $conf->ql_ok("alter table ActionLog add `trueContactId` int(11) DEFAULT NULL")) {
            $conf->update_schema_version(201);
        }
        if ($conf->sversion === 201
            && $conf->ql_ok("alter table PaperReviewRefused add `timeRequested` bigint(11) DEFAULT NULL")
            && $conf->ql_ok("alter table PaperReviewRefused add `refusedBy` int(11) DEFAULT NULL")) {
            $conf->update_schema_version(202);
        }
        if ($conf->sversion === 202
            && $conf->ql_ok("alter table ReviewRequest add `timeRequested` bigint(11) DEFAULT NULL")) {
            $conf->update_schema_version(203);
        }
        if ($conf->sversion === 203
            && $conf->ql_ok("alter table PaperReviewRefused add `timeRefused` bigint(11) DEFAULT NULL")) {
            $conf->update_schema_version(204);
        }
        if ($conf->sversion === 204
            && $conf->ql_ok("alter table PaperReviewRefused add `email` varchar(120) DEFAULT NULL")
            && $conf->ql_ok("update PaperReviewRefused join ContactInfo using (contactId) set PaperReviewRefused.email=ContactInfo.email")
            && $conf->ql_ok("delete from PaperReviewRefused where email is null")
            && $conf->ql_ok("alter table PaperReviewRefused change `email` `email` varchar(120) NOT NULL")
            && $conf->ql_ok("alter table PaperReviewRefused drop primary key")
            && $conf->ql_ok("alter table PaperReviewRefused add primary key (`paperId`,`email`)")) {
            $conf->update_schema_version(205);
        }
        if ($conf->sversion === 205
            && $conf->ql_ok("alter table PaperReviewRefused add `reviewRound` int(1) DEFAULT NULL")) {
            $conf->update_schema_version(206);
        }
        if ($conf->sversion === 206
            && $conf->ql_ok("alter table PaperReviewRefused add `firstName` varbinary(120) DEFAULT NULL")
            && $conf->ql_ok("alter table PaperReviewRefused add `lastName` varbinary(120) DEFAULT NULL")
            && $conf->ql_ok("alter table PaperReviewRefused add `affiliation` varbinary(2048) DEFAULT NULL")) {
            $conf->update_schema_version(207);
        }
        if ($conf->sversion === 207
            && $conf->ql_ok("alter table ActionLog add `timestamp` bigint(11) DEFAULT NULL")
            && $conf->ql_ok("update ActionLog set timestamp=unix_timestamp(time) where timestamp IS NULL")) {
            $conf->update_schema_version(208);
        }
        if ($conf->sversion === 208
            && $conf->ql_ok("update ActionLog set timestamp=unix_timestamp(time) where timestamp IS NULL")
            && $conf->ql_ok("alter table ActionLog change `timestamp` `timestamp` bigint(11) NOT NULL")
            && $conf->ql_ok("alter table ActionLog drop `time`")
            && $conf->ql_ok("alter table ActionLog add `data` varbinary(8192) DEFAULT NULL")) {
            $conf->update_schema_version(209);
        }
        if ($conf->sversion === 209) {
            $conf->ql_ok("update Settings set name=concat('opt.', substr(name, 5)) where name like 'ova.%'");
            $conf->update_schema_version(210);
        }
        if ($conf->sversion === 210) {
            $conf->ql_ok("update Settings set data=replace(data, '#', '') where name='tracks'");
            $conf->update_schema_version(211);
        }
        if ($conf->sversion === 211) {
            $conf->ql_ok("update Settings set name='msg.resp_instrux_0' where name='msg.resp_instrux'");
            $conf->update_schema_version(212);
        }
        if ($conf->sversion === 212
            && $conf->ql_ok("update PaperConflict set conflictType=(64 + conflictType - 9) where conflictType>=9 and conflictType<64")) {
            $conf->update_schema_version(213);
        }
        if ($conf->sversion === 213) {
            // "options" JSON already updated to array
            $conf->update_schema_version(214);
        }
        if ($conf->sversion === 214
            && $conf->ql_ok("alter table PaperReview add `data` varbinary(8192) DEFAULT NULL")) {
            $conf->update_schema_version(215);
        }
        if ($conf->sversion === 215
            && $conf->ql_ok("alter table PaperReviewRefused add `data` varbinary(8192) DEFAULT NULL")) {
            $conf->update_schema_version(216);
        }
        if ($conf->sversion === 216
            && $conf->ql_ok("alter table PaperReviewRefused add `reviewType` tinyint(1) NOT NULL DEFAULT '0'")) {
            $conf->update_schema_version(217);
        }
        if ($conf->sversion === 217) {
            if ($conf->setting("extrev_approve")
                && $conf->setting("pcrev_editdelegate")) {
                $conf->ql_ok("delete from Settings where name='extrev_approve'");
                $conf->save_setting("pcrev_editdelegate", 2);
            }
            $conf->update_schema_version(218);
        }
        if ($conf->sversion === 218) {
            if (($mb = $conf->setting_data("mailbody_requestreview"))) {
                $mb1 = str_replace("/review/%NUMBER%?accept=1&%LOGINURLPARTS%", "/review/%NUMBER%?cap=%REVIEWACCEPTOR%&accept=1", $mb);
                $mb1 = str_replace("/review/%NUMBER%?decline=1&%LOGINURLPARTS%", "/review/%NUMBER%?cap=%REVIEWACCEPTOR%&decline=1", $mb1);
                if ($mb1 !== $mb) {
                    $conf->save_setting("mailbody_requestreview", 1, $mb1);
                }
            }
            $conf->update_schema_version(219);
        }
        if ($conf->sversion === 219
            && $conf->ql_ok("alter table MailLog add `status` tinyint(1) NOT NULL DEFAULT '0'")) {
            $conf->update_schema_version(220);
        }
        if ($conf->sversion === 220
            && $conf->ql_ok("update PaperReview set reviewNeedsSubmit=0 where reviewNeedsSubmit>0 and timeApprovalRequested<0")) {
            $conf->update_schema_version(221);
        }
        if ($conf->sversion === 221
            && $conf->ql_ok("alter table PaperComment drop column `paperStorageId`")) {
            $conf->update_schema_version(222);
        }
        if ($conf->sversion === 222
            && $conf->ql_ok("update PaperComment set timeDisplayed=if(timeNotified=0,timeModified,timeNotified) where timeDisplayed=0 and (commentType&" . CommentInfo::CT_DRAFT . ")=0")) {
            $conf->update_schema_version(223);
        }
        if ($conf->sversion === 223
            && $this->v224_set_review_time_displayed()) {
            $conf->update_schema_version(224);
        }
        if ($conf->sversion === 224
            && $conf->ql_ok("update ContactInfo set contactTags=null where contactTags=''")) {
            $conf->update_schema_version(225);
        }
        if ($conf->sversion === 225
            && $conf->ql_ok("lock tables PaperReview write")) {
            if ($conf->ql_ok("alter table PaperReview add `reviewViewScore` tinyint(1) NOT NULL DEFAULT '-3'")) {
                $conf->ql_ok("update PaperReview set reviewViewScore=" . ReviewInfo::VIEWSCORE_RECOMPUTE);
                $conf->review_form()->compute_view_scores();
                $ok = true;
            } else {
                $ok = false;
            }
            $conf->ql_ok("unlock tables");
            if ($ok) {
                $conf->update_schema_version(226);
            }
        }
        if ($conf->sversion === 226
            && $conf->ql_ok("update ContactInfo set contactTags=trim(trailing from contactTags) where contactTags is not null")
            && $conf->ql_ok("update PaperComment set commentTags=trim(trailing from commentTags) where commentTags is not null")) {
            $conf->update_schema_version(227);
        }
        if ($conf->sversion === 227
            && $this->v228_add_comment_tag_values(0)) {
            $conf->update_schema_version(228);
        }
        if ($conf->sversion === 228
            && $conf->ql_ok("alter table Formula drop column `heading`")
            && $conf->ql_ok("alter table Formula drop column `headingTitle`")) {
            $conf->update_schema_version(229);
        }
        if ($conf->sversion === 229
            && $this->v228_add_comment_tag_values(1)) {
            $conf->update_schema_version(230);
        }
        if ($conf->sversion === 230
            && $conf->ql_ok("alter table Paper add `dataOverflow` longblob")) {
            $conf->update_schema_version(231);
        }
        if ($conf->sversion === 231
            && $conf->ql_ok("update PaperConflict set conflictType=if(conflictType>64,64,32) where conflictType>=64")) {
            $conf->update_schema_version(232);
        }
        if ($conf->sversion === 232
            && $conf->ql_ok("update PaperConflict set conflictType=(case conflictType&31 when 8 then 3 when 1 then 2 when 0 then 0 else (conflictType-1)*2 end + (conflictType&96))")) {
            $conf->update_schema_version(233);
        }
        if (($conf->sversion === 233 || $conf->sversion === 234)
            && $conf->ql_ok("alter table PaperReviewRefused change `reviewType` `refusedReviewType` tinyint(1) NOT NULL DEFAULT '0'")) {
            $conf->update_schema_version(235);
        }
        if ($conf->sversion === 235
            && $conf->ql_ok("alter table PaperStorage add `crc32` binary(4) DEFAULT NULL")) {
            $conf->update_schema_version(236);
        }
        if ($conf->sversion === 236
            && $conf->ql_ok("alter table ContactInfo drop `birthday`")
            && $conf->ql_ok("alter table ContactInfo drop `gender`")) {
            $conf->update_schema_version(237);
        }
        if ($conf->sversion === 237
            && $conf->ql_ok("alter table ContactInfo add `orcid` varbinary(64) DEFAULT NULL")) {
            $conf->update_schema_version(238);
        }
        if ($conf->sversion === 238
            && $conf->ql_ok("alter table DeletedContactInfo add `affiliation` varbinary(2048) NOT NULL DEFAULT ''")) {
            $conf->update_schema_version(239);
        }
        if ($conf->sversion === 239) {
            Dbl::qx($conf->dblink, "alter table PaperReviewRefused change `reviewType` `refusedReviewType` tinyint(1) NOT NULL DEFAULT '0'");
            $conf->update_schema_version(240);
        }
        if ($conf->sversion === 240) {
            Dbl::qx($conf->dblink, "alter table PaperReviewRefused add `refusedReviewId` int(11) DEFAULT NULL");
            $conf->update_schema_version(241);
        }
        if ($conf->sversion === 241
            && $conf->ql_ok("update ContactInfo set firstName=trim(firstName), lastName=trim(lastName), affiliation=trim(affiliation)")) {
            $conf->update_schema_version(242);
        }
        if ($conf->sversion === 242
            && $this->v243_simplify_user_whitespace()) {
            $conf->update_schema_version(243);
        }
        if ($conf->sversion === 243
            && $conf->ql_ok("alter table ContactInfo drop column `creationTime`")) {
            $conf->update_schema_version(244);
        }
        if ($conf->sversion === 244
            && $conf->ql_ok("alter table ContactInfo add `primaryContactId` int(11) NOT NULL DEFAULT '0'")) {
            $conf->update_schema_version(245);
        }
        if (($conf->settings["au_seerev"] ?? 0) === 1) {
            $conf->save_setting("au_seerev", 2);
        }
        if ($conf->sversion === 245) {
            Dbl::qx($conf->dblink, "delete from Settings where name='opt.allow_auseerev_unlessincomplete'");
            $conf->update_schema_version(246);
        }
        if ($conf->sversion === 246) {
            if (($rfj = $conf->review_form_json())) {
                if (isset($rfj->t01)) {
                    unset($rfj->t01->display_space);
                }
                if (isset($rfj->t02)) {
                    unset($rfj->t02->display_space);
                }
                if (isset($rfj->t03)) {
                    unset($rfj->t03->display_space);
                }
                $conf->save_setting("review_form", 1, $rfj);
            }
            $conf->update_schema_version(247);
        }
        if ($conf->sversion === 247) {
            $conf->update_schema_version(248);
        }
        if ($conf->sversion === 248
            && $conf->ql_ok("alter table MailLog add `contactId` int NOT NULL DEFAULT '0'")) {
            $conf->update_schema_version(249);
        }
        if ($conf->sversion === 249) {
            Dbl::qx($conf->dblink, "DROP TABLE IF EXISTS `Invitation`");
            Dbl::qx($conf->dblink, "DROP TABLE IF EXISTS `InvitationLog`");
            if ($conf->ql_ok("CREATE TABLE `Invitation` (
  `invitationId` int(11) NOT NULL AUTO_INCREMENT,
  `invitationType` int(11) NOT NULL,
  `email` varchar(120) NOT NULL,
  `firstName` varbinary(120) DEFAULT NULL,
  `lastName` varbinary(120) DEFAULT NULL,
  `affiliation` varbinary(2048) DEFAULT NULL,
  `requestedBy` int(11) NOT NULL,
  `timeRequested` bigint(11) NOT NULL DEFAULT '0',
  `timeRequestNotified` bigint(11) NOT NULL DEFAULT '0',
  `salt` varbinary(255) NOT NULL,
  `data` varbinary(4096) DEFAULT NULL,
  PRIMARY KEY (`invitationId`),
  UNIQUE KEY (`salt`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4")
                && $conf->ql_ok("CREATE TABLE `InvitationLog` (
  `logId` int(11) NOT NULL AUTO_INCREMENT,
  `invitationId` int(11) NOT NULL,
  `mailId` int(11) DEFAULT NULL,
  `contactId` int(11) NOT NULL,
  `action` int(11) NOT NULL,
  `timestamp` bigint(11) NOT NULL,
  PRIMARY KEY (`logId`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4")) {
                $conf->update_schema_version(250);
            }
        }
        if ($conf->sversion === 250
            && $this->v251_change_default_charset()) {
            $conf->update_schema_version(251);
        }
        if ($conf->sversion === 251
            && $this->v252_change_column_default_charset()) {
            $conf->update_schema_version(252);
        }
        if ($conf->sversion === 252
            && $conf->ql_ok("alter table PaperComment add `commentData` varbinary(4096) DEFAULT NULL")) {
            $conf->update_schema_version(253);
        }
        if ($conf->sversion === 253
            && $conf->ql_ok("alter table Capability add `timeCreated` bigint(11) NOT NULL DEFAULT 0")
            && $conf->ql_ok("alter table Capability change `timeCreated` `timeCreated` bigint(11) NOT NULL")
            && $conf->ql_ok("alter table Capability change `data` `data` varbinary(8192) DEFAULT NULL")
            && $conf->ql_ok("alter table Capability add `otherId` int(11) NOT NULL DEFAULT 0")) {
            $conf->update_schema_version(254);
        }
        if ($conf->sversion === 254
            && $conf->ql_ok("alter table Capability add `timeInvalid` bigint(11) NOT NULL")
            && $conf->ql_ok("alter table Capability add `timeUsed` bigint(11) NOT NULL")) {
            $conf->update_schema_version(255);
        }
        if ($conf->sversion === 255
            && $this->v256_tokenize_review_acceptors()) {
            $conf->update_schema_version(256);
        }
        if ($conf->sversion === 256
            && $this->v257_update_response_settings()) {
            $conf->update_schema_version(257);
        }
        if ($conf->sversion === 257) {
            $conf->update_schema_version(258);
        }
        if ($conf->sversion === 258
            && $conf->ql_ok("alter table ContactInfo change `unaccentedName` `unaccentedName` varbinary(2048) NOT NULL DEFAULT ''")
            && $conf->ql_ok("alter table DeletedContactInfo change `unaccentedName` `unaccentedName` varbinary(2048) NOT NULL DEFAULT ''")
            && $this->v259_add_affiliation_to_unaccented_name("ContactInfo")
            && $this->v259_add_affiliation_to_unaccented_name("DeletedContactInfo")) {
            $conf->update_schema_version(259);
        }
        if ($conf->sversion === 259
            && $this->v260_paperreview_fields()) {
            $conf->update_schema_version(260);
        }
        if ($conf->sversion === 260
            && $conf->setting("__response_round_v261")) {
            $conf->save_setting("__review_form_v258", null);
            $conf->save_setting("__response_round_v261", null);
            $conf->update_schema_version(261);
        }
        if ($conf->sversion === 261) {
            if (($conf->setting("pcrev_soft") ?? 0) <= 0
                && ($conf->setting("pcrev_hard") ?? 0) <= 0
                && ($conf->setting("extrev_soft") ?? 0) <= 0
                && ($conf->setting("extrev_hard") ?? 0) <= 0
                && (trim($conf->setting_data("tag_rounds") ?? "") === ""
                    || $conf->fetch_ivalue("select exists (select * from PaperReview where reviewRound=0) from dual"))) {
                $conf->save_setting("pcrev_soft", 0);
            }
            $conf->update_schema_version(262);
        }
        if ($conf->sversion === 262
            && $conf->ql_ok("update ContactInfo set roles=roles&15 where roles>15") /* ROLE_DBMASK */
            && $this->v260_paperreview_fields() /* schema.sql had tinyint for a while */) {
            $conf->update_schema_version(263);
        }
        if ($conf->sversion === 263
            && $conf->ql_ok("update PaperComment set commentTags=null where commentRound!=0")) {
            $conf->update_schema_version(264);
        }
        if ($conf->sversion === 264
            && $conf->setting("__review_view_score_v264")) {
            $conf->save_setting("__review_view_score_v264", null);
            $conf->update_schema_version(265);
        }
        if ($conf->sversion === 265
            && $this->v266_contact_counter()) {
            $conf->update_schema_version(266);
        }
        if ($conf->sversion === 266
            && $this->v267_paper_review_history()) {
            $conf->update_schema_version(267);
        }
        if ($conf->sversion === 267
            && $conf->ql_ok("alter table PaperReviewHistory add `timeApprovalRequested` bigint(11) NOT NULL DEFAULT 0")
            && $conf->ql_ok("alter table PaperReviewHistory change `timeApprovalRequested` `timeApprovalRequested` bigint(11) NOT NULL")
            && $conf->ql_ok("alter table PaperReviewHistory change `reviewAuthorNotified` `reviewAuthorNotified` bigint(11) NOT NULL")
            && $conf->ql_ok("alter table PaperReviewHistory change `reviewEditVersion` `reviewEditVersion` int(1) NOT NULL")) {
            $conf->update_schema_version(268);
        }
        if ($conf->sversion === 268
            && $conf->ql_ok("alter table PaperReviewHistory add `reviewNextTime` bigint(11) NOT NULL DEFAULT 0")
            && $conf->ql_ok("delete from PaperReviewHistory")) {
            $conf->update_schema_version(269);
        }
        if ($conf->sversion === 269) {
            $conf->update_schema_version(270);
        }
        if ($conf->sversion === 270
            && $this->v271_action_log_paper_actions()) {
            $conf->update_schema_version(271);
        }
        if ($conf->setting("tracker") !== null) {
            $conf->save_setting("__tracker", $conf->setting("tracker"), $conf->setting_data("tracker"));
            $conf->save_setting("tracker", null);
        }
        if ($conf->sversion === 271) {
            $conf->update_schema_version(272);
        }
        if ($conf->sversion === 272
            && $conf->ql_ok("alter table Paper change `size` `size` bigint(11) NOT NULL DEFAULT -1")
            && $conf->ql_ok("update PaperStorage set size=-1 where size is null or (size=0 and sha1!=x'da39a3ee5e6b4b0d3255bfef95601890afd80709' and sha1!=x'736861322de3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855')")
            && $conf->ql_ok("alter table PaperStorage change `size` `size` bigint(11) NOT NULL DEFAULT -1")) {
            $conf->update_schema_version(273);
        }
        if ($conf->sversion === 273
            || $conf->sversion === 274) {
            $conf->update_schema_version(275);
        }
        if ($conf->sversion === 275
            && $conf->ql_ok("alter table PaperStorage add `npages` int(3) NOT NULL DEFAULT -1")
            && $conf->ql_ok("alter table PaperStorage add `width` int(8) NOT NULL DEFAULT -1")
            && $conf->ql_ok("alter table PaperStorage add `height` int(8) NOT NULL DEFAULT -1")) {
            $conf->update_schema_version(276);
        }
        if ($conf->sversion === 276
            || $conf->sversion === 277
            || $conf->sversion === 278) {
            $conf->update_schema_version(279);
        }
        if ($conf->sversion === 279) {
            $this->v280_filter_download_log();
            $conf->update_schema_version(280);
        }
        if ($conf->sversion === 280) {
            $this->v281_update_response_rounds();
            $conf->update_schema_version(281);
        }
        if ($conf->sversion === 281) {
            $conf->save_setting("__extrev_seerev_v282", null);
            $conf->update_schema_version(282);
        }
        if ($conf->sversion === 282) {
            $this->v283_ensure_rev_roundtag();
            $conf->update_schema_version(283);
        }
        if ($conf->sversion === 283
            && $conf->ql_ok("delete from Settings where name like 'msg.resp\\_instrux%'")) {
            $conf->update_schema_version(284);
        }
        if ($conf->sversion === 284
            && $conf->ql_ok("alter table ContactInfo add `cflags` int(11) NOT NULL DEFAULT 0")
            && $conf->ql_ok("update ContactInfo set cflags=disabled")) {
            $conf->update_schema_version(285);
        }
        if ($conf->sversion === 285
            && $conf->ql_ok("update ContactInfo set cflags=34 where cflags=2")) {
            $conf->update_schema_version(286);
        }
        if ($conf->sversion === 286
            && $conf->ql_ok("alter table Capability change `otherId` `reviewId` int(11) NOT NULL DEFAULT 0")
            && $conf->ql_ok("alter table Capability change `data` `data` varbinary(16384) DEFAULT NULL")
            && $conf->ql_oK("alter table Capability add `output` longblob DEFAULT NULL")) {
            $conf->update_schema_version(287);
        }
        if ($conf->sversion === 287
            && $conf->ql_ok("alter table Capability change `output` `outputData` longblob DEFAULT NULL")
            && $conf->ql_ok("alter table Capability add `inputData` varbinary(16384) DEFAULT NULL")) {
            $conf->update_schema_version(288);
        }

        $conf->ql_ok("delete from Settings where name='__schema_lock'");
        Conf::$main = $old_conf_g;
        return $this->need_run;
    }
}
