<?php
// updateschema.php -- HotCRP function for updating old schemata
// Copyright (c) 2006-2018 Eddie Kohler; see LICENSE.

function update_schema_create_review_form($conf) {
    if (!($result = $conf->ql("select * from ReviewFormField where fieldName!='outcome'")))
        return false;
    $rfj = (object) array();
    while (($row = edb_orow($result))) {
        $field = (object) array();
        $field->name = $row->shortName;
        if (trim($row->description) != "")
            $field->description = trim($row->description);
        if ($row->sortOrder >= 0)
            $field->position = $row->sortOrder + 1;
        if ($row->rows > 3)
            $field->display_space = (int) $row->rows;
        $field->view_score = (int) $row->authorView;
        if (in_array($row->fieldName, ["overAllMerit", "technicalMerit", "novelty",
                                "grammar", "reviewerQualification", "potential",
                                "fixability", "interestToCommunity", "longevity",
                                "likelyPresentation", "suitableForShort"])) {
            $field->options = array();
            if ((int) $row->levelChar > 1)
                $field->option_letter = (int) $row->levelChar;
        }
        $fname = $row->fieldName;
        $rfj->$fname = $field;
    }

    if (!($result = $conf->ql("select * from ReviewFormOptions where fieldName!='outcome' order by level asc")))
        return false;
    while (($row = edb_orow($result))) {
        $fname = $row->fieldName;
        if (isset($rfj->$fname) && isset($rfj->$fname->options))
            $rfj->$fname->options[$row->level - 1] = $row->description;
    }

    return $conf->save_setting("review_form", 1, $rfj);
}

function update_schema_create_options($conf) {
    if (!($result = $conf->ql("select * from OptionType")))
        return false;
    $opsj = (object) array();
    $byabbr = array();
    while (($row = edb_orow($result))) {
        // backward compatibility with old schema versions
        if (!isset($row->optionValues))
            $row->optionValues = "";
        if (!isset($row->type) && $row->optionValues == "\x7Fi")
            $row->type = 2;
        else if (!isset($row->type))
            $row->type = ($row->optionValues ? 1 : 0);

        $opj = (object) array();
        $opj->id = $row->optionId;
        $opj->name = $row->optionName;

        if (trim($row->description) != "")
            $opj->description = trim($row->description);

        if ($row->pcView == 2)
            $opj->view_type = "nonblind";
        else if ($row->pcView == 0)
            $opj->view_type = "admin";

        $opj->position = (int) $row->sortOrder;
        if ($row->displayType == 1)
            $opj->highlight = true;
        else if ($row->displayType == 2)
            $opj->near_submission = true;

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

        $oid = $opj->id;
        $opsj->$oid = $opj;
    }

    return $conf->save_setting("options", 1, $opsj);
}

function update_schema_transfer_address($conf) {
    $result = $conf->ql("select * from ContactAddress");
    while (($row = edb_orow($result)))
        if (($c = $conf->user_by_id($row->contactId))) {
            $x = (object) array();
            if ($row->addressLine1 || $row->addressLine2)
                $x->address = array();
            foreach (array("addressLine1", "addressLine2") as $k)
                if ($row->$k)
                    $x->address[] = $row->$k;
            foreach (array("city" => "city", "state" => "state",
                           "zipCode" => "zip", "country" => "country") as $k => $v)
                if ($row->$k)
                    $x->$v = $row->$k;
            $c->merge_and_save_data($x);
        }
    return true;
}

function update_schema_unaccented_name($conf) {
    if (!$conf->ql("alter table ContactInfo add `unaccentedName` varchar(120) NOT NULL DEFAULT ''"))
        return false;

    $result = $conf->ql("select contactId, firstName, lastName from ContactInfo");
    if (!$result)
        return false;

    $qs = $qv = array();
    while ($result && ($x = $result->fetch_row())) {
        $qs[] = "update ContactInfo set unaccentedName=? where contactId=$x[0]";
        $qv[] = Text::unaccented_name($x[1], $x[2]);
    }
    Dbl::free($result);

    $q = Dbl::format_query_apply($conf->dblink, join(";", $qs), $qv);
    if (!$conf->dblink->multi_query($q))
        return false;
    do {
        if (($result = $conf->dblink->store_result()))
            $result->free();
    } while ($conf->dblink->more_results() && $conf->dblink->next_result());
    return true;
}

function update_schema_transfer_country($conf) {
    $result = $conf->ql("select * from ContactInfo where `data` is not null and `data`!='{}'");
    while ($result && ($c = Contact::fetch($result, $conf))) {
        if (($country = $c->data("country")))
            $conf->ql("update ContactInfo set country=? where contactId=?", $country, $c->contactId);
    }
    return true;
}

function update_schema_review_word_counts($conf) {
    $rf = new ReviewForm($conf->review_form_json(), $conf);
    do {
        $q = array();
        $result = $conf->ql("select * from PaperReview where reviewWordCount is null limit 32");
        while (($rrow = edb_orow($result)))
            $q[] = "update PaperReview set reviewWordCount="
                . $rf->word_count($rrow) . " where reviewId=" . $rrow->reviewId;
        Dbl::free($result);
        $conf->dblink->multi_query(join(";", $q));
        while ($conf->dblink->more_results())
            Dbl::free($conf->dblink->next_result());
    } while (count($q) == 32);
}

function update_schema_bad_comment_timeDisplayed($conf) {
    $badids = Dbl::fetch_first_columns($conf->dblink, "select a.commentId from PaperComment a join PaperComment b where a.paperId=b.paperId and a.commentId<b.commentId and a.timeDisplayed>b.timeDisplayed");
    return !count($badids) || $conf->ql("update PaperComment set timeDisplayed=0 where commentId ?a", $badids);
}

function update_schema_drop_keys_if_exist($conf, $table, $key) {
    $indexes = Dbl::fetch_first_columns($conf->dblink, "select distinct index_name from information_schema.statistics where table_schema=database() and `table_name`='$table'");
    $drops = [];
    foreach (is_array($key) ? $key : [$key] as $k)
        if (in_array($k, $indexes))
            $drops[] = ($k === "PRIMARY" ? "drop primary key" : "drop key `$k`");
    if (count($drops))
        return $conf->ql("alter table `$table` " . join(", ", $drops));
    else
        return true;
}

function update_schema_mimetype_extensions($conf) {
    if (!($result = $conf->ql("select * from Mimetype where extension is null")))
        return false;
    $qv = [];
    while (($row = $result->fetch_object()))
        if (($extension = Mimetype::extension($row->mimetype)))
            $qv[] = [$row->mimetypeid, $row->mimetype, $extension];
    Dbl::free($result);
    return empty($qv) || $conf->ql("insert into Mimetype (mimetypeid, mimetype, extension) values ?v on duplicate key update extension=values(extension)", $qv);
}

function update_schema_paper_review_tfields(Conf $conf) {
    if (!$conf->ql("alter table PaperReview add `tfields` longblob")
        || !$conf->ql("alter table PaperReview add `sfields` varbinary(2048) DEFAULT NULL"))
        return false;
    $cleanf = Dbl::make_multi_ql_stager($conf->dblink);
    $result = $conf->ql("select * from PaperReview");
    while (($row = ReviewInfo::fetch($result, $conf))) {
        $data = $row->unparse_tfields();
        if ($data !== null)
            $cleanf("update PaperReview set `tfields`=? where paperId=? and reviewId=?", [$data, $row->paperId, $row->reviewId]);
    }
    Dbl::free($result);
    $cleanf(true);
    return true;
}

function update_schema_paper_review_null_main_fields(Conf $conf) {
    $rid = [];
    $result = $conf->ql("select * from PaperReview");
    while (($rrow = ReviewInfo::fetch($result, $conf))) {
        $tfields = $rrow->tfields ? json_decode($rrow->tfields, true) : [];
        $any = false;
        foreach (ReviewInfo::$text_field_map as $kmain => $kjson) {
            $mainval = (string) get($rrow, $kmain);
            $jsonval = (string) get($tfields, $kjson);
            if ($mainval !== $jsonval) {
                error_log("{$conf->dbname}: #{$rrow->paperId}/{$rrow->reviewId}: {$kmain} ["
                    . simplify_whitespace(UnicodeHelper::utf8_abbreviate($mainval === "" ? "EMPTY" : $mainval, 20))
                    . "] != tf/{$kjson} ["
                    . simplify_whitespace(UnicodeHelper::utf8_abbreviate($jsonval === "" ? "EMPTY" : $jsonval, 20))
                    . "]");
                return false;
            }
        }
    }
    Dbl::free($result);
    $kf = array_map(function ($k) { return "$k=null"; }, array_keys(ReviewInfo::$text_field_map));
    return $conf->ql("update PaperReview set " . join(", ", $kf));
}

function update_schema_paper_review_drop_main_fields(Conf $conf) {
    $rid = [];
    $kf = array_map(function ($k) { return "$k is not null"; }, array_keys(ReviewInfo::$text_field_map));
    if (!$conf->ql("lock tables PaperReview write"))
        return false;
    $result = $conf->ql("select * from PaperReview where " . join(" or ", $kf));
    $rrow = ReviewInfo::fetch($result, $conf);
    Dbl::free($result);
    if ($rrow) {
        error_log("{$conf->dbname}: #{$rrow->paperId}/{$rrow->reviewId}: nonnull main field cancels schema upgrade");
        $ok = false;
    } else {
        $ok = true;
        foreach (ReviewInfo::$text_field_map as $kmain => $kjson)
            $ok = $ok && $conf->ql("alter table PaperReview drop column `$kmain`");
    }
    $conf->ql("unlock tables");
    return $ok;
}

function update_schema_split_review_request_name(Conf $conf) {
    if (!$conf->ql("alter table ReviewRequest add `firstName` varbinary(120) DEFAULT NULL")
        || !$conf->ql("alter table ReviewRequest add `lastName` varbinary(120) DEFAULT NULL")
        || !$conf->ql("lock tables ReviewRequest write"))
        return false;
    $result = $conf->ql("select * from ReviewRequest");
    $cleanf = Dbl::make_multi_ql_stager($conf->dblink);
    while ($result && ($row = $result->fetch_object())) {
        list($first, $last) = Text::split_name($row->name);
        $cleanf("update ReviewRequest set firstName=?, lastName=? where paperId=? and email=?", [(string) $first === "" ? null : $first,
                   (string) $last === "" ? null : $last,
                   $row->paperId, $row->email]);
    }
    Dbl::free($result);
    $cleanf(true);
    $conf->ql("unlock tables");
    return $conf->ql("alter table ReviewRequest drop column `name`");
}

function update_schema_missing_sha1($conf) {
    $result = $conf->ql("select * from PaperStorage where sha1='' and paper is not null and paper!='' and paperStorageId>1");
    $cleanf = Dbl::make_multi_ql_stager($conf->dblink);
    while (($doc = DocumentInfo::fetch($result, $conf))) {
        $hash = $doc->content_binary_hash();
        $cleanf("update PaperStorage set sha1=? where paperId=? and paperStorageId=?", [$hash, $doc->paperId, $doc->paperStorageId]);
        if ($doc->documentType == DTYPE_SUBMISSION)
            $cleanf("update Paper set sha1=? where paperId=? and paperStorageId=? and finalPaperStorageId<=0", [$hash, $doc->paperId, $doc->paperStorageId]);
        else if ($doc->documentType == DTYPE_FINAL)
            $cleanf("update Paper set sha1=? where paperId=? and finalPaperStorageId=?", [$hash, $doc->paperId, $doc->paperStorageId]);
    }
    Dbl::free($result);
    $cleanf(true);
}

function update_schema_selector_options($conf) {
    $oids = [];
    foreach ($conf->paper_opts->full_option_list() as $opt)
        if ($opt->has_selector())
            $oids[] = $opt->id;
    return empty($oids)
        || $conf->ql("update PaperOption set value=value+1 where optionId?a", $oids);
}

function updateSchema($conf) {
    // avoid error message about timezone, set to $Opt
    // (which might be overridden by database values later)
    if (function_exists("date_default_timezone_set") && $conf->opt("timezone"))
        date_default_timezone_set($conf->opt("timezone"));
    while (($result = $conf->ql("insert into Settings set name='__schema_lock', value=1 on duplicate key update value=1"))
           && $result->affected_rows == 0)
        time_nanosleep(0, 200000000);
    $conf->update_schema_version(null);
    $old_conf_g = Conf::$g;
    Conf::$g = $conf;

    error_log($conf->dbname . ": updating schema from version " . $conf->sversion);

    if ($conf->sversion == 6
        && $conf->ql("alter table ReviewRequest add `reason` text"))
        $conf->update_schema_version(7);
    if ($conf->sversion == 7
        && $conf->ql("alter table PaperReview add `textField7` mediumtext NOT NULL")
        && $conf->ql("alter table PaperReview add `textField8` mediumtext NOT NULL")
        && $conf->ql("insert into ReviewFormField set fieldName='textField7', shortName='Additional text field'")
        && $conf->ql("insert into ReviewFormField set fieldName='textField8', shortName='Additional text field'"))
        $conf->update_schema_version(8);
    if ($conf->sversion == 8
        && $conf->ql("alter table ReviewFormField add `levelChar` tinyint(1) NOT NULL default '0'")
        && $conf->ql("alter table PaperReviewArchive add `textField7` mediumtext NOT NULL")
        && $conf->ql("alter table PaperReviewArchive add `textField8` mediumtext NOT NULL"))
        $conf->update_schema_version(9);
    if ($conf->sversion == 9
        && $conf->ql("alter table Paper add `sha1` varbinary(20) NOT NULL default ''"))
        $conf->update_schema_version(10);
    if ($conf->sversion == 10
        && $conf->ql("alter table PaperReview add `reviewRound` tinyint(1) NOT NULL default '0'")
        && $conf->ql("alter table PaperReviewArchive add `reviewRound` tinyint(1) NOT NULL default '0'")
        && $conf->ql("alter table PaperReview add key `reviewRound` (`reviewRound`)")
        && $conf->update_schema_version(11)) {
        if (count($conf->round_list()) > 1) {
            // update review rounds (XXX locking)
            $result = $conf->ql("select paperId, tag from PaperTag where tag like '%~%'");
            $rrs = array();
            while (($row = edb_row($result))) {
                list($contact, $round) = explode("~", $row[1]);
                if (($round = array_search($round, $conf->round_list()))) {
                    if (!isset($rrs[$round]))
                        $rrs[$round] = array();
                    $rrs[$round][] = "(contactId=$contact and paperId=$row[0])";
                }
            }
            foreach ($rrs as $round => $pairs) {
                $q = "update PaperReview set reviewRound=$round where " . join(" or ", $pairs);
                $conf->ql($q);
            }
            $x = trim(preg_replace('/(\S+)\s*/', "tag like '%~\$1' or ", $conf->setting_data("tag_rounds")));
            $conf->ql("delete from PaperTag where " . substr($x, 0, strlen($x) - 3));
        }
    }
    if ($conf->sversion == 11
        && $conf->ql("DROP TABLE IF EXISTS `ReviewRating`")
        && $conf->ql("create table `ReviewRating` (
  `reviewId` int(11) NOT NULL,
  `contactId` int(11) NOT NULL,
  `rating` tinyint(1) NOT NULL default '0',
  UNIQUE KEY `reviewContact` (`reviewId`,`contactId`),
  UNIQUE KEY `reviewContactRating` (`reviewId`,`contactId`,`rating`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8"))
        $conf->update_schema_version(12);
    if ($conf->sversion == 12
        && $conf->ql("alter table PaperReview add `reviewToken` int(11) NOT NULL default '0'"))
        $conf->update_schema_version(13);
    if ($conf->sversion == 13
        && $conf->ql("alter table OptionType add `optionValues` text NOT NULL default ''"))
        $conf->update_schema_version(14);
    if ($conf->sversion == 14
        && $conf->ql("insert into Settings (name, value) select 'rev_tokens', count(reviewId) from PaperReview where reviewToken!=0 on duplicate key update value=values(value)"))
        $conf->update_schema_version(15);
    if ($conf->sversion == 15) {
        // It's OK if this fails!  Update 11 added reviewRound to
        // PaperReviewArchive (so old databases have the column), but I forgot
        // to upgrade schema.sql (so new databases lack the column).
        $old_nerrors = Dbl::$nerrors;
        $conf->ql("alter table PaperReviewArchive add `reviewRound` tinyint(1) NOT NULL default '0'");
        Dbl::$nerrors = $old_nerrors;
        $conf->update_schema_version(16);
    }
    if ($conf->sversion == 16
        && $conf->ql("alter table PaperReview add `reviewEditVersion` int(1) NOT NULL default '0'"))
        $conf->update_schema_version(17);
    if ($conf->sversion == 17
        && $conf->ql("alter table PaperReviewPreference add key `paperId` (`paperId`)")
        && $conf->ql("DROP TABLE IF EXISTS `PaperRank`")
        && $conf->ql("create table PaperRank (
  `paperId` int(11) NOT NULL,
  `contactId` int(11) NOT NULL,
  `rank` int(11) NOT NULL,
  UNIQUE KEY `contactPaper` (`contactId`,`paperId`),
  KEY `paperId` (`paperId`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8;"))
        $conf->update_schema_version(18);
    if ($conf->sversion == 18
        && $conf->ql("alter table PaperComment add `replyTo` int(11) NOT NULL"))
        $conf->update_schema_version(19);
    if ($conf->sversion == 19
        && $conf->ql("drop table PaperRank"))
        $conf->update_schema_version(20);
    if ($conf->sversion == 20
        && $conf->ql("alter table PaperComment add `timeNotified` int(11) NOT NULL default '0'"))
        $conf->update_schema_version(21);
    if ($conf->sversion == 21
        && $conf->ql("update PaperConflict set conflictType=8 where conflictType=3"))
        $conf->update_schema_version(22);
    if ($conf->sversion == 22
        && $conf->ql("insert into ChairAssistant (contactId) select contactId from Chair on duplicate key update ChairAssistant.contactId=ChairAssistant.contactId")
        && $conf->ql("update ContactInfo set roles=roles+2 where roles=5"))
        $conf->update_schema_version(23);
    if ($conf->sversion == 23)
        $conf->update_schema_version(24);
    if ($conf->sversion == 24
        && $conf->ql("alter table ContactInfo add `preferredEmail` varchar(120)"))
        $conf->update_schema_version(25);
    if ($conf->sversion == 25) {
        if ($conf->settings["final_done"] > 0
            && !isset($conf->settings["final_soft"])
            && $conf->ql("insert into Settings (name, value) values ('final_soft', " . $conf->settings["final_done"] . ") on duplicate key update value=values(value)"))
            $conf->settings["final_soft"] = $conf->settings["final_done"];
        $conf->update_schema_version(26);
    }
    if ($conf->sversion == 26
        && $conf->ql("alter table PaperOption add `data` text")
        && $conf->ql("alter table OptionType add `type` tinyint(1) NOT NULL default '0'")
        && $conf->ql("update OptionType set type=2 where optionValues='\x7Fi'")
        && $conf->ql("update OptionType set type=1 where type=0 and optionValues!=''"))
        $conf->update_schema_version(27);
    if ($conf->sversion == 27
        && $conf->ql("alter table PaperStorage add `sha1` varbinary(20) NOT NULL default ''")
        && $conf->ql("alter table PaperStorage add `documentType` int(3) NOT NULL default '0'")
        && $conf->ql("update PaperStorage s, Paper p set s.sha1=p.sha1 where s.paperStorageId=p.paperStorageId and p.finalPaperStorageId=0 and s.paperStorageId>0")
        && $conf->ql("update PaperStorage s, Paper p set s.sha1=p.sha1, s.documentType=" . DTYPE_FINAL . " where s.paperStorageId=p.finalPaperStorageId and s.paperStorageId>0"))
        $conf->update_schema_version(28);
    if ($conf->sversion == 28
        && $conf->ql("alter table OptionType add `sortOrder` tinyint(1) NOT NULL default '0'"))
        $conf->update_schema_version(29);
    if ($conf->sversion == 29
        && $conf->ql("delete from Settings where name='pldisplay_default'"))
        $conf->update_schema_version(30);
    if ($conf->sversion == 30
        && $conf->ql("DROP TABLE IF EXISTS `Formula`")
        && $conf->ql("CREATE TABLE `Formula` (
  `formulaId` int(11) NOT NULL auto_increment,
  `name` varchar(200) NOT NULL,
  `heading` varchar(200) NOT NULL default '',
  `headingTitle` text NOT NULL default '',
  `expression` text NOT NULL,
  `authorView` tinyint(1) NOT NULL default '1',
  PRIMARY KEY  (`formulaId`),
  UNIQUE KEY `formulaId` (`formulaId`),
  UNIQUE KEY `name` (`name`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8"))
        $conf->update_schema_version(31);
    if ($conf->sversion == 31
        && $conf->ql("alter table Formula add `createdBy` int(11) NOT NULL default '0'")
        && $conf->ql("alter table Formula add `timeModified` int(11) NOT NULL default '0'")
        && $conf->ql("alter table Formula drop index `name`"))
        $conf->update_schema_version(32);
    if ($conf->sversion == 32
        && $conf->ql("alter table PaperComment add key `timeModified` (`timeModified`)"))
        $conf->update_schema_version(33);
    if ($conf->sversion == 33
        && $conf->ql("alter table PaperComment add `paperStorageId` int(11) NOT NULL default '0'"))
        $conf->update_schema_version(34);
    if ($conf->sversion == 34
        && $conf->ql("alter table ContactInfo add `contactTags` text"))
        $conf->update_schema_version(35);
    if ($conf->sversion == 35
        && $conf->ql("alter table ContactInfo modify `defaultWatch` int(11) NOT NULL default '2'")
        && $conf->ql("alter table PaperWatch modify `watch` int(11) NOT NULL default '0'"))
        $conf->update_schema_version(36);
    if ($conf->sversion == 36
        && $conf->ql("alter table PaperReview add `reviewNotified` int(1) default NULL")
        && $conf->ql("alter table PaperReviewArchive add `reviewNotified` int(1) default NULL"))
        $conf->update_schema_version(37);
    if ($conf->sversion == 37
        && $conf->ql("alter table OptionType add `displayType` tinyint(1) NOT NULL default '0'"))
        $conf->update_schema_version(38);
    if ($conf->sversion == 38
        && $conf->ql("update PaperComment set forReviewers=1 where forReviewers=-1"))
        $conf->update_schema_version(39);
    if ($conf->sversion == 39
        && $conf->ql("DROP TABLE IF EXISTS `MailLog`")
        && $conf->ql("CREATE TABLE `MailLog` (
  `mailId` int(11) NOT NULL auto_increment,
  `recipients` varchar(200) NOT NULL,
  `paperIds` text,
  `cc` text,
  `replyto` text,
  `subject` text,
  `emailBody` text,
  PRIMARY KEY  (`mailId`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8"))
        $conf->update_schema_version(40);
    if ($conf->sversion == 40
        && $conf->ql("alter table Paper add `capVersion` int(1) NOT NULL default '0'"))
        $conf->update_schema_version(41);
    if ($conf->sversion == 41
        && $conf->ql("alter table Paper modify `mimetype` varchar(80) NOT NULL default ''")
        && $conf->ql("alter table PaperStorage modify `mimetype` varchar(80) NOT NULL default ''"))
        $conf->update_schema_version(42);
    if ($conf->sversion == 42
        && $conf->ql("alter table PaperComment add `ordinal` int(11) NOT NULL default '0'"))
        $conf->update_schema_version(43);
    if ($conf->sversion == 42
        && ($result = $conf->ql("describe PaperComment `ordinal`"))
        && ($o = edb_orow($result))
        && substr($o->Type, 0, 3) == "int"
        && (!$o->Null || $o->Null == "NO")
        && (!$o->Default || $o->Default == "0"))
        $conf->update_schema_version(43);
    if ($conf->sversion == 43
        && $conf->ql("alter table Paper add `withdrawReason` text"))
        $conf->update_schema_version(44);
    if ($conf->sversion == 44
        && $conf->ql("alter table PaperStorage add `filename` varchar(255)"))
        $conf->update_schema_version(45);
    if ($conf->sversion == 45
        && $conf->ql("alter table PaperReview add `timeRequested` int(11) NOT NULL DEFAULT '0'")
        && $conf->ql("alter table PaperReview add `timeRequestNotified` int(11) NOT NULL DEFAULT '0'")
        && $conf->ql("alter table PaperReviewArchive add `timeRequested` int(11) NOT NULL DEFAULT '0'")
        && $conf->ql("alter table PaperReviewArchive add `timeRequestNotified` int(11) NOT NULL DEFAULT '0'")
        && $conf->ql("alter table PaperReview drop column `requestedOn`")
        && $conf->ql("alter table PaperReviewArchive drop column `requestedOn`"))
        $conf->update_schema_version(46);
    if ($conf->sversion == 46
        && $conf->ql("alter table ContactInfo add `disabled` tinyint(1) NOT NULL DEFAULT '0'"))
        $conf->update_schema_version(47);
    if ($conf->sversion == 47
        && $conf->ql("delete from ti using TopicInterest ti left join TopicArea ta using (topicId) where ta.topicId is null"))
        $conf->update_schema_version(48);
    if ($conf->sversion == 48
        && $conf->ql("alter table PaperReview add `reviewAuthorNotified` int(11) NOT NULL DEFAULT '0'")
        && $conf->ql("alter table PaperReviewArchive add `reviewAuthorNotified` int(11) NOT NULL DEFAULT '0'")
        && $conf->ql("alter table PaperReviewArchive add `reviewToken` int(11) NOT NULL DEFAULT '0'"))
        $conf->update_schema_version(49);
    if ($conf->sversion == 49
        && $conf->ql("alter table PaperOption drop index `paperOption`")
        && $conf->ql("alter table PaperOption add index `paperOption` (`paperId`,`optionId`,`value`)"))
        $conf->update_schema_version(50);
    if ($conf->sversion == 50
        && $conf->ql("alter table Paper add `managerContactId` int(11) NOT NULL DEFAULT '0'"))
        $conf->update_schema_version(51);
    if ($conf->sversion == 51
        && $conf->ql("alter table Paper drop column `numComments`")
        && $conf->ql("alter table Paper drop column `numAuthorComments`"))
        $conf->update_schema_version(52);
    // Due to a bug in the schema updater, some databases might have
    // sversion>=53 but no `PaperComment.commentType` column. Fix them.
    if (($conf->sversion == 52
         || ($conf->sversion >= 53
             && ($result = $conf->ql("show columns from PaperComment like 'commentType'"))
             && edb_nrows($result) == 0))
        && $conf->ql("lock tables PaperComment write, Settings write")
        && $conf->ql("alter table PaperComment add `commentType` int(11) NOT NULL DEFAULT '0'")) {
        $new_sversion = max($conf->sversion, 53);
        $result = $conf->ql("show columns from PaperComment like 'forAuthors'");
        if (!$result
            || edb_nrows($result) == 0
            || ($conf->ql("update PaperComment set commentType=" . (COMMENTTYPE_AUTHOR | COMMENTTYPE_RESPONSE) . " where forAuthors=2")
                && $conf->ql("update PaperComment set commentType=commentType|" . COMMENTTYPE_DRAFT . " where forAuthors=2 and forReviewers=0")
                && $conf->ql("update PaperComment set commentType=" . COMMENTTYPE_ADMINONLY . " where forAuthors=0 and forReviewers=2")
                && $conf->ql("update PaperComment set commentType=" . COMMENTTYPE_PCONLY . " where forAuthors=0 and forReviewers=0")
                && $conf->ql("update PaperComment set commentType=" . COMMENTTYPE_REVIEWER . " where forAuthors=0 and forReviewers=1")
                && $conf->ql("update PaperComment set commentType=" . COMMENTTYPE_AUTHOR . " where forAuthors!=0 and forAuthors!=2")
                && $conf->ql("update PaperComment set commentType=commentType|" . COMMENTTYPE_BLIND . " where blind=1")))
            $conf->update_schema_version($new_sversion);
    }
    if ($conf->sversion < 53)
        Dbl::qx_raw($conf->dblink, "alter table PaperComment drop column `commentType`");
    $conf->ql("unlock tables");
    if ($conf->sversion == 53
        && $conf->ql("alter table PaperComment drop column `forReviewers`")
        && $conf->ql("alter table PaperComment drop column `forAuthors`")
        && $conf->ql("alter table PaperComment drop column `blind`"))
        $conf->update_schema_version(54);
    if ($conf->sversion == 54
        && $conf->ql("alter table PaperStorage add `infoJson` varchar(255) DEFAULT NULL"))
        $conf->update_schema_version(55);
    if ($conf->sversion == 55
        && $conf->ql("alter table ContactInfo modify `password` varbinary(2048) NOT NULL"))
        $conf->update_schema_version(56);
    if ($conf->sversion == 56
        && $conf->ql("alter table Settings modify `data` blob"))
        $conf->update_schema_version(57);
    if ($conf->sversion == 57
        && $conf->ql("DROP TABLE IF EXISTS `Capability`")
        && $conf->ql("CREATE TABLE `Capability` (
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
        && $conf->ql("DROP TABLE IF EXISTS `CapabilityMap`")
        && $conf->ql("CREATE TABLE `CapabilityMap` (
  `capabilityValue` varbinary(255) NOT NULL,
  `capabilityId` int(11) NOT NULL,
  `timeExpires` int(11) NOT NULL,
  PRIMARY KEY (`capabilityValue`),
  UNIQUE KEY `capabilityValue` (`capabilityValue`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8"))
        $conf->update_schema_version(58);
    if ($conf->sversion == 58
        && $conf->ql("alter table PaperReview modify `paperSummary` mediumtext DEFAULT NULL")
        && $conf->ql("alter table PaperReview modify `commentsToAuthor` mediumtext DEFAULT NULL")
        && $conf->ql("alter table PaperReview modify `commentsToPC` mediumtext DEFAULT NULL")
        && $conf->ql("alter table PaperReview modify `commentsToAddress` mediumtext DEFAULT NULL")
        && $conf->ql("alter table PaperReview modify `weaknessOfPaper` mediumtext DEFAULT NULL")
        && $conf->ql("alter table PaperReview modify `strengthOfPaper` mediumtext DEFAULT NULL")
        && $conf->ql("alter table PaperReview modify `textField7` mediumtext DEFAULT NULL")
        && $conf->ql("alter table PaperReview modify `textField8` mediumtext DEFAULT NULL")
        && $conf->ql("alter table PaperReviewArchive modify `paperSummary` mediumtext DEFAULT NULL")
        && $conf->ql("alter table PaperReviewArchive modify `commentsToAuthor` mediumtext DEFAULT NULL")
        && $conf->ql("alter table PaperReviewArchive modify `commentsToPC` mediumtext DEFAULT NULL")
        && $conf->ql("alter table PaperReviewArchive modify `commentsToAddress` mediumtext DEFAULT NULL")
        && $conf->ql("alter table PaperReviewArchive modify `weaknessOfPaper` mediumtext DEFAULT NULL")
        && $conf->ql("alter table PaperReviewArchive modify `strengthOfPaper` mediumtext DEFAULT NULL")
        && $conf->ql("alter table PaperReviewArchive modify `textField7` mediumtext DEFAULT NULL")
        && $conf->ql("alter table PaperReviewArchive modify `textField8` mediumtext DEFAULT NULL"))
        $conf->update_schema_version(59);
    if ($conf->sversion == 59
        && $conf->ql("alter table ActionLog modify `action` varbinary(4096) NOT NULL")
        && $conf->ql("alter table ContactInfo modify `note` varbinary(4096) DEFAULT NULL")
        && $conf->ql("alter table ContactInfo modify `collaborators` varbinary(32767) DEFAULT NULL")
        && $conf->ql("alter table ContactInfo modify `contactTags` varbinary(4096) DEFAULT NULL")
        && $conf->ql("alter table Formula modify `headingTitle` varbinary(4096) NOT NULL")
        && $conf->ql("alter table Formula modify `expression` varbinary(4096) NOT NULL")
        && $conf->ql("alter table OptionType modify `description` varbinary(8192) DEFAULT NULL")
        && $conf->ql("alter table OptionType modify `optionValues` varbinary(8192) NOT NULL")
        && $conf->ql("alter table PaperReviewRefused modify `reason` varbinary(32767) DEFAULT NULL")
        && $conf->ql("alter table ReviewFormField modify `description` varbinary(32767) DEFAULT NULL")
        && $conf->ql("alter table ReviewFormOptions modify `description` varbinary(32767) DEFAULT NULL")
        && $conf->ql("alter table ReviewRequest modify `reason` varbinary(32767) DEFAULT NULL")
        && $conf->ql("alter table Settings modify `data` varbinary(32767) DEFAULT NULL")
        && $conf->ql("alter table ContactAddress modify `addressLine1` varchar(2048) NOT NULL")
        && $conf->ql("alter table ContactAddress modify `addressLine2` varchar(2048) NOT NULL")
        && $conf->ql("alter table ContactAddress modify `city` varchar(2048) NOT NULL")
        && $conf->ql("alter table ContactAddress modify `state` varchar(2048) NOT NULL")
        && $conf->ql("alter table ContactAddress modify `zipCode` varchar(2048) NOT NULL")
        && $conf->ql("alter table ContactAddress modify `country` varchar(2048) NOT NULL")
        && $conf->ql("alter table PaperTopic modify `topicId` int(11) NOT NULL")
        && $conf->ql("alter table PaperTopic modify `paperId` int(11) NOT NULL")
        && $conf->ql("drop table if exists ChairTag"))
        $conf->update_schema_version(60);
    if ($conf->sversion == 60) {
        foreach (["conflictdef", "home"] as $k)
            if ($conf->setting_data("{$k}msg", false) !== false) {
                $conf->save_setting("msg.$k", 1, $conf->setting_data("{$k}msg"));
                $conf->save_setting("{$k}msg", null);
            }
        $conf->update_schema_version(61);
    }
    if ($conf->sversion == 61
        && $conf->ql("alter table Capability modify `data` varbinary(4096) DEFAULT NULL"))
        $conf->update_schema_version(62);
    if (!isset($conf->settings["outcome_map"])) {
        $ojson = array();
        $result = $conf->ql("select * from ReviewFormOptions where fieldName='outcome'");
        while (($row = edb_orow($result)))
            $ojson[$row->level] = $row->description;
        $conf->save_setting("outcome_map", 1, $ojson);
    }
    if ($conf->sversion == 62
        && isset($conf->settings["outcome_map"]))
        $conf->update_schema_version(63);
    if (!isset($conf->settings["review_form"])
        && $conf->sversion < 65)
        update_schema_create_review_form($conf);
    if ($conf->sversion == 63
        && isset($conf->settings["review_form"]))
        $conf->update_schema_version(64);
    if ($conf->sversion == 64
        && $conf->ql("drop table if exists `ReviewFormField`")
        && $conf->ql("drop table if exists `ReviewFormOptions`"))
        $conf->update_schema_version(65);
    if (!isset($conf->settings["options"])
        && $conf->sversion < 67)
        update_schema_create_options($conf);
    if ($conf->sversion == 65
        && isset($conf->settings["options"]))
        $conf->update_schema_version(66);
    if ($conf->sversion == 66
        && $conf->ql("drop table if exists `OptionType`"))
        $conf->update_schema_version(67);
    if ($conf->sversion == 67
        && $conf->ql("alter table PaperComment modify `comment` varbinary(32767) DEFAULT NULL")
        && $conf->ql("alter table PaperComment add `commentTags` varbinary(1024) DEFAULT NULL"))
        $conf->update_schema_version(68);
    if ($conf->sversion == 68
        && $conf->ql("alter table PaperReviewPreference add `expertise` int(4) DEFAULT NULL"))
        $conf->update_schema_version(69);
    if ($conf->sversion == 69
        && $conf->ql("alter table Paper drop column `pcPaper`"))
        $conf->update_schema_version(70);
    if ($conf->sversion == 70
        && $conf->ql("alter table ContactInfo modify `voicePhoneNumber` varbinary(256) DEFAULT NULL")
        && $conf->ql("alter table ContactInfo modify `faxPhoneNumber` varbinary(256) DEFAULT NULL")
        && $conf->ql("alter table ContactInfo modify `collaborators` varbinary(8192) DEFAULT NULL")
        && $conf->ql("alter table ContactInfo drop column `note`")
        && $conf->ql("alter table ContactInfo add `data` varbinary(32767) DEFAULT NULL"))
        $conf->update_schema_version(71);
    if ($conf->sversion == 71
        && $conf->ql("alter table Settings modify `name` varbinary(256) DEFAULT NULL")
        && $conf->ql("update Settings set name=rtrim(name)"))
        $conf->update_schema_version(72);
    if ($conf->sversion == 72
        && $conf->ql("update TopicInterest set interest=-2 where interest=0")
        && $conf->ql("update TopicInterest set interest=4 where interest=2")
        && $conf->ql("delete from TopicInterest where interest=1"))
        $conf->update_schema_version(73);
    if ($conf->sversion == 73
        && $conf->ql("alter table PaperStorage add `size` bigint(11) DEFAULT NULL")
        && $conf->ql("update PaperStorage set `size`=length(paper) where paper is not null"))
        $conf->update_schema_version(74);
    if ($conf->sversion == 74
        && $conf->ql("alter table ContactInfo drop column `visits`"))
        $conf->update_schema_version(75);
    if ($conf->sversion == 75) {
        foreach (array("capability_gc", "s3_scope", "s3_signing_key") as $k)
            if ($conf->setting($k)) {
                $conf->save_setting("__" . $k, $conf->setting($k), $conf->setting_data($k));
                $conf->save_setting($k, null);
            }
        $conf->update_schema_version(76);
    }
    if ($conf->sversion == 76
        && $conf->ql("update PaperReviewPreference set expertise=-expertise"))
        $conf->update_schema_version(77);
    if ($conf->sversion == 77
        && $conf->ql("alter table MailLog add `q` varchar(4096)"))
        $conf->update_schema_version(78);
    if ($conf->sversion == 78
        && $conf->ql("alter table MailLog add `t` varchar(200)"))
        $conf->update_schema_version(79);
    if ($conf->sversion == 79
        && $conf->ql("alter table ContactInfo add `passwordTime` int(11) NOT NULL DEFAULT '0'"))
        $conf->update_schema_version(80);
    if ($conf->sversion == 80
        && $conf->ql("alter table PaperReview modify `reviewRound` int(11) NOT NULL DEFAULT '0'")
        && $conf->ql("alter table PaperReviewArchive modify `reviewRound` int(11) NOT NULL DEFAULT '0'"))
        $conf->update_schema_version(81);
    if ($conf->sversion == 81
        && $conf->ql("alter table PaperStorage add `filterType` int(3) DEFAULT NULL")
        && $conf->ql("alter table PaperStorage add `originalStorageId` int(11) DEFAULT NULL"))
        $conf->update_schema_version(82);
    if ($conf->sversion == 82
        && $conf->ql("update Settings set name='msg.resp_instrux' where name='msg.responseinstructions'"))
        $conf->update_schema_version(83);
    if ($conf->sversion == 83
        && $conf->ql("alter table PaperComment add `commentRound` int(11) NOT NULL DEFAULT '0'"))
        $conf->update_schema_version(84);
    if ($conf->sversion == 84
        && $conf->ql("insert ignore into Settings (name, value) select 'resp_active', value from Settings where name='resp_open'"))
        $conf->update_schema_version(85);
    if ($conf->sversion == 85
        && $conf->ql("DROP TABLE IF EXISTS `PCMember`")
        && $conf->ql("DROP TABLE IF EXISTS `ChairAssistant`")
        && $conf->ql("DROP TABLE IF EXISTS `Chair`"))
        $conf->update_schema_version(86);
    if ($conf->sversion == 86
        && update_schema_transfer_address($conf))
        $conf->update_schema_version(87);
    if ($conf->sversion == 87
        && $conf->ql("DROP TABLE IF EXISTS `ContactAddress`"))
        $conf->update_schema_version(88);
    if ($conf->sversion == 88
        && $conf->ql("alter table ContactInfo drop key `name`")
        && $conf->ql("alter table ContactInfo drop key `affiliation`")
        && $conf->ql("alter table ContactInfo drop key `email_3`")
        && $conf->ql("alter table ContactInfo drop key `firstName_2`")
        && $conf->ql("alter table ContactInfo drop key `lastName`"))
        $conf->update_schema_version(89);
    if ($conf->sversion == 89
        && update_schema_unaccented_name($conf))
        $conf->update_schema_version(90);
    if ($conf->sversion == 90
        && $conf->ql("alter table PaperReview add `reviewAuthorSeen` int(11) DEFAULT NULL"))
        $conf->update_schema_version(91);
    if ($conf->sversion == 91
        && $conf->ql("alter table PaperReviewArchive add `reviewAuthorSeen` int(11) DEFAULT NULL"))
        $conf->update_schema_version(92);
    if ($conf->sversion == 92
        && $conf->ql("alter table Paper drop key `titleAbstractText`")
        && $conf->ql("alter table Paper drop key `allText`")
        && $conf->ql("alter table Paper drop key `authorText`")
        && $conf->ql("alter table Paper modify `authorInformation` varbinary(8192) DEFAULT NULL")
        && $conf->ql("alter table Paper modify `abstract` varbinary(16384) DEFAULT NULL")
        && $conf->ql("alter table Paper modify `collaborators` varbinary(8192) DEFAULT NULL")
        && $conf->ql("alter table Paper modify `withdrawReason` varbinary(1024) DEFAULT NULL"))
        $conf->update_schema_version(93);
    if ($conf->sversion == 93
        && $conf->ql("alter table TopicArea modify `topicName` varchar(200) DEFAULT NULL"))
        $conf->update_schema_version(94);
    if ($conf->sversion == 94
        && $conf->ql("alter table PaperOption modify `data` varbinary(32768) DEFAULT NULL")) {
        foreach ($conf->paper_opts->nonfixed_option_list() as $xopt)
            if ($xopt->type === "text")
                $conf->ql("delete from PaperOption where optionId={$xopt->id} and data=''");
        $conf->update_schema_version(95);
    }
    if ($conf->sversion == 95
        && $conf->ql("alter table Capability add unique key `salt` (`salt`)")
        && $conf->ql("update Capability join CapabilityMap using (capabilityId) set Capability.salt=CapabilityMap.capabilityValue")
        && $conf->ql("drop table if exists `CapabilityMap`"))
        $conf->update_schema_version(96);
    if ($conf->sversion == 96
        && $conf->ql("alter table ContactInfo add `passwordIsCdb` tinyint(1) NOT NULL DEFAULT '0'"))
        $conf->update_schema_version(97);
    if ($conf->sversion == 97
        && $conf->ql("alter table PaperReview add `reviewWordCount` int(11) DEFAULT NULL")
        && $conf->ql("alter table PaperReviewArchive add `reviewWordCount` int(11)  DEFAULT NULL")
        && $conf->ql("alter table PaperReviewArchive drop key `reviewId`")
        && $conf->ql("alter table PaperReviewArchive drop key `contactPaper`")
        && $conf->ql("alter table PaperReviewArchive drop key `reviewSubmitted`")
        && $conf->ql("alter table PaperReviewArchive drop key `reviewNeedsSubmit`")
        && $conf->ql("alter table PaperReviewArchive drop key `reviewType`")
        && $conf->ql("alter table PaperReviewArchive drop key `requestedBy`"))
        $conf->update_schema_version(98);
    if ($conf->sversion == 98) {
        update_schema_review_word_counts($conf);
        $conf->update_schema_version(99);
    }
    if ($conf->sversion == 99
        && $conf->ql("alter table ContactInfo ENGINE=InnoDB")
        && $conf->ql("alter table Paper ENGINE=InnoDB")
        && $conf->ql("alter table PaperComment ENGINE=InnoDB")
        && $conf->ql("alter table PaperConflict ENGINE=InnoDB")
        && $conf->ql("alter table PaperOption ENGINE=InnoDB")
        && $conf->ql("alter table PaperReview ENGINE=InnoDB")
        && $conf->ql("alter table PaperStorage ENGINE=InnoDB")
        && $conf->ql("alter table PaperTag ENGINE=InnoDB")
        && $conf->ql("alter table PaperTopic ENGINE=InnoDB")
        && $conf->ql("alter table Settings ENGINE=InnoDB"))
        $conf->update_schema_version(100);
    if ($conf->sversion == 100
        && $conf->ql("alter table ActionLog ENGINE=InnoDB")
        && $conf->ql("alter table Capability ENGINE=InnoDB")
        && $conf->ql("alter table Formula ENGINE=InnoDB")
        && $conf->ql("alter table MailLog ENGINE=InnoDB")
        && $conf->ql("alter table PaperReviewArchive ENGINE=InnoDB")
        && $conf->ql("alter table PaperReviewPreference ENGINE=InnoDB")
        && $conf->ql("alter table PaperReviewRefused ENGINE=InnoDB")
        && $conf->ql("alter table PaperWatch ENGINE=InnoDB")
        && $conf->ql("alter table ReviewRating ENGINE=InnoDB")
        && $conf->ql("alter table ReviewRequest ENGINE=InnoDB")
        && $conf->ql("alter table TopicArea ENGINE=InnoDB")
        && $conf->ql("alter table TopicInterest ENGINE=InnoDB"))
        $conf->update_schema_version(101);
    if ($conf->sversion == 101
        && $conf->ql("alter table ActionLog modify `ipaddr` varbinary(32) DEFAULT NULL")
        && $conf->ql("alter table MailLog modify `recipients` varbinary(200) NOT NULL")
        && $conf->ql("alter table MailLog modify `q` varbinary(4096) DEFAULT NULL")
        && $conf->ql("alter table MailLog modify `t` varbinary(200) DEFAULT NULL")
        && $conf->ql("alter table Paper modify `mimetype` varbinary(80) NOT NULL DEFAULT ''")
        && $conf->ql("alter table PaperStorage modify `mimetype` varbinary(80) NOT NULL DEFAULT ''")
        && $conf->ql("alter table PaperStorage modify `filename` varbinary(255) DEFAULT NULL")
        && $conf->ql("alter table PaperStorage modify `infoJson` varbinary(8192) DEFAULT NULL"))
        $conf->update_schema_version(102);
    if ($conf->sversion == 102
        && $conf->ql("alter table PaperReview modify `paperSummary` mediumblob")
        && $conf->ql("alter table PaperReview modify `commentsToAuthor` mediumblob")
        && $conf->ql("alter table PaperReview modify `commentsToPC` mediumblob")
        && $conf->ql("alter table PaperReview modify `commentsToAddress` mediumblob")
        && $conf->ql("alter table PaperReview modify `weaknessOfPaper` mediumblob")
        && $conf->ql("alter table PaperReview modify `strengthOfPaper` mediumblob")
        && $conf->ql("alter table PaperReview modify `textField7` mediumblob")
        && $conf->ql("alter table PaperReview modify `textField8` mediumblob")
        && $conf->ql("alter table PaperReviewArchive modify `paperSummary` mediumblob")
        && $conf->ql("alter table PaperReviewArchive modify `commentsToAuthor` mediumblob")
        && $conf->ql("alter table PaperReviewArchive modify `commentsToPC` mediumblob")
        && $conf->ql("alter table PaperReviewArchive modify `commentsToAddress` mediumblob")
        && $conf->ql("alter table PaperReviewArchive modify `weaknessOfPaper` mediumblob")
        && $conf->ql("alter table PaperReviewArchive modify `strengthOfPaper` mediumblob")
        && $conf->ql("alter table PaperReviewArchive modify `textField7` mediumblob")
        && $conf->ql("alter table PaperReviewArchive modify `textField8` mediumblob"))
        $conf->update_schema_version(103);
    if ($conf->sversion == 103
        && $conf->ql("alter table Paper modify `title` varbinary(256) DEFAULT NULL")
        && $conf->ql("alter table Paper drop key `title`"))
        $conf->update_schema_version(104);
    if ($conf->sversion == 104
        && $conf->ql("alter table PaperReview add `reviewFormat` tinyint(1) DEFAULT NULL")
        && $conf->ql("alter table PaperReviewArchive add `reviewFormat` tinyint(1) DEFAULT NULL"))
        $conf->update_schema_version(105);
    if ($conf->sversion == 105
        && $conf->ql("alter table PaperComment add `commentFormat` tinyint(1) DEFAULT NULL"))
        $conf->update_schema_version(106);
    if ($conf->sversion == 106
        && $conf->ql("alter table PaperComment add `authorOrdinal` int(11) NOT NULL default '0'")
        && $conf->ql("update PaperComment set authorOrdinal=ordinal where commentType>=" . COMMENTTYPE_AUTHOR))
        $conf->update_schema_version(107);

    // repair missing comment ordinals; reset incorrect `ordinal`s for
    // author-visible comments
    if ($conf->sversion == 107) {
        $result = $conf->ql("select paperId, commentId from PaperComment where ordinal=0 and (commentType&" . (COMMENTTYPE_RESPONSE | COMMENTTYPE_DRAFT) . ")=0 and commentType>=" . COMMENTTYPE_PCONLY . " and commentType<" . COMMENTTYPE_AUTHOR . " order by commentId");
        while (($row = edb_row($result))) {
            $conf->ql("update PaperComment,
(select coalesce(count(commentId),0) commentCount from Paper
    left join PaperComment on (PaperComment.paperId=Paper.paperId and (commentType&" . (COMMENTTYPE_RESPONSE | COMMENTTYPE_DRAFT) . ")=0 and commentType>=" . COMMENTTYPE_PCONLY . " and commentType<" . COMMENTTYPE_AUTHOR . " and commentId<$row[1])
    where Paper.paperId=$row[0] group by Paper.paperId) t
set ordinal=(t.commentCount+1) where commentId=$row[1]");
        }

        $result = $conf->ql("select paperId, commentId from PaperComment where ordinal=0 and (commentType&" . (COMMENTTYPE_RESPONSE | COMMENTTYPE_DRAFT) . ")=0 and commentType>=" . COMMENTTYPE_AUTHOR . " order by commentId");
        while (($row = edb_row($result))) {
            $conf->ql("update PaperComment,
(select coalesce(count(commentId),0) commentCount from Paper
    left join PaperComment on (PaperComment.paperId=Paper.paperId and (commentType&" . (COMMENTTYPE_RESPONSE | COMMENTTYPE_DRAFT) . ")=0 and commentType>=" . COMMENTTYPE_AUTHOR . " and commentId<$row[1])
    where Paper.paperId=$row[0] group by Paper.paperId) t
set authorOrdinal=(t.commentCount+1) where commentId=$row[1]");
        }

        $result = $conf->ql("select paperId, commentId from PaperComment where ordinal=authorOrdinal and (commentType&" . (COMMENTTYPE_RESPONSE | COMMENTTYPE_DRAFT) . ")=0 and commentType>=" . COMMENTTYPE_AUTHOR . " order by commentId");
        while (($row = edb_row($result))) {
            $conf->ql("update PaperComment,
(select coalesce(max(ordinal),0) maxOrdinal from Paper
    left join PaperComment on (PaperComment.paperId=Paper.paperId and (commentType&" . (COMMENTTYPE_RESPONSE | COMMENTTYPE_DRAFT) . ")=0 and commentType>=" . COMMENTTYPE_PCONLY . " and commentType<" . COMMENTTYPE_AUTHOR . " and commentId<$row[1])
    where Paper.paperId=$row[0] group by Paper.paperId) t
set ordinal=(t.maxOrdinal+1) where commentId=$row[1]");
        }

        $conf->update_schema_version(108);
    }

    // contact tags format change
    if ($conf->sversion == 108
        && $conf->ql("update ContactInfo set contactTags=substr(replace(contactTags, ' ', '#0 ') from 3)")
        && $conf->ql("update ContactInfo set contactTags=replace(contactTags, '#0#0 ', '#0 ')"))
        $conf->update_schema_version(109);
    if ($conf->sversion == 109
        && $conf->ql("alter table PaperTag modify `tagIndex` float NOT NULL DEFAULT '0'"))
        $conf->update_schema_version(110);
    if ($conf->sversion == 110
        && $conf->ql("alter table ContactInfo drop `faxPhoneNumber`")
        && $conf->ql("alter table ContactInfo add `country` varbinary(256) default null")
        && update_schema_transfer_country($conf))
        $conf->update_schema_version(111);
    if ($conf->sversion == 111) {
        update_schema_review_word_counts($conf);
        $conf->update_schema_version(112);
    }
    if ($conf->sversion == 112
        && $conf->ql("alter table ContactInfo add `passwordUseTime` int(11) NOT NULL DEFAULT '0'")
        && $conf->ql("alter table ContactInfo add `updateTime` int(11) NOT NULL DEFAULT '0'")
        && $conf->ql("update ContactInfo set passwordUseTime=lastLogin where passwordUseTime=0"))
        $conf->update_schema_version(113);
    if ($conf->sversion == 113
        && $conf->ql("drop table if exists `PaperReviewArchive`"))
        $conf->update_schema_version(114);
    if ($conf->sversion == 114
        && $conf->ql("alter table PaperReview add `timeDisplayed` int(11) NOT NULL DEFAULT '0'")
        && $conf->ql("alter table PaperComment add `timeDisplayed` int(11) NOT NULL DEFAULT '0'"))
        $conf->update_schema_version(115);
    if ($conf->sversion == 115
        && $conf->ql("alter table Formula drop column `authorView`"))
        $conf->update_schema_version(116);
    if ($conf->sversion == 116
        && $conf->ql("alter table PaperComment add `commentOverflow` longblob DEFAULT NULL"))
        $conf->update_schema_version(117);
    if ($conf->sversion == 117
        && update_schema_drop_keys_if_exist($conf, "PaperTopic", ["paperTopic", "PRIMARY"])
        && $conf->ql("alter table PaperTopic add primary key (`paperId`,`topicId`)")
        && update_schema_drop_keys_if_exist($conf, "TopicInterest", ["contactTopic", "PRIMARY"])
        && $conf->ql("alter table TopicInterest add primary key (`contactId`,`topicId`)"))
        $conf->update_schema_version(118);
    if ($conf->sversion == 118
        && update_schema_drop_keys_if_exist($conf, "PaperTag", ["paperTag", "PRIMARY"])
        && $conf->ql("alter table PaperTag add primary key (`paperId`,`tag`)")
        && update_schema_drop_keys_if_exist($conf, "PaperReviewPreference", ["paperId", "PRIMARY"])
        && $conf->ql("alter table PaperReviewPreference add primary key (`paperId`,`contactId`)")
        && update_schema_drop_keys_if_exist($conf, "PaperConflict", ["contactPaper", "contactPaperConflict", "PRIMARY"])
        && $conf->ql("alter table PaperConflict add primary key (`contactId`,`paperId`)")
        && $conf->ql("alter table MailLog modify `paperIds` blob")
        && $conf->ql("alter table MailLog modify `cc` blob")
        && $conf->ql("alter table MailLog modify `replyto` blob")
        && $conf->ql("alter table MailLog modify `subject` blob")
        && $conf->ql("alter table MailLog modify `emailBody` blob"))
        $conf->update_schema_version(119);
    if ($conf->sversion == 119
        && update_schema_drop_keys_if_exist($conf, "PaperWatch", ["contactPaper", "contactPaperWatch", "PRIMARY"])
        && $conf->ql("alter table PaperWatch add primary key (`paperId`,`contactId`)"))
        $conf->update_schema_version(120);
    if ($conf->sversion == 120
        && $conf->ql("alter table Paper add `paperFormat` tinyint(1) DEFAULT NULL"))
        $conf->update_schema_version(121);
    if ($conf->sversion == 121
        && $conf->ql_raw("update PaperReview r, Paper p set r.reviewNeedsSubmit=1 where p.paperId=r.paperId and p.timeSubmitted<=0 and r.reviewSubmitted is null")
        && $conf->ql_raw("update PaperReview r, Paper p, PaperReview rq set r.reviewNeedsSubmit=0 where p.paperId=r.paperId and p.paperId=rq.paperId and p.timeSubmitted<=0 and r.reviewType=" . REVIEW_SECONDARY . " and r.contactId=rq.requestedBy and rq.reviewType<" . REVIEW_SECONDARY . " and rq.reviewSubmitted is not null")
        && $conf->ql_raw("update PaperReview r, Paper p, PaperReview rq set r.reviewNeedsSubmit=-1 where p.paperId=r.paperId and p.paperId=rq.paperId and p.timeSubmitted<=0 and r.reviewType=" . REVIEW_SECONDARY . " and r.contactId=rq.requestedBy and rq.reviewType<" . REVIEW_SECONDARY . " and r.reviewNeedsSubmit=0"))
        $conf->update_schema_version(122);
    if ($conf->sversion == 122
        && $conf->ql("alter table ReviewRequest add `reviewRound` int(1) DEFAULT NULL"))
        $conf->update_schema_version(123);
    if ($conf->sversion == 123
        && $conf->ql("update ContactInfo set disabled=1 where password='' and email regexp '^anonymous[0-9]*\$'"))
        $conf->update_schema_version(124);
    if ($conf->sversion == 124
        && $conf->ql("update ContactInfo set password='' where password='*' or passwordIsCdb"))
        $conf->update_schema_version(125);
    if ($conf->sversion == 125
        && $conf->ql("alter table ContactInfo drop column `passwordIsCdb`"))
        $conf->update_schema_version(126);
    if ($conf->sversion == 126
        && $conf->ql("update ContactInfo set disabled=1, password='' where email regexp '^anonymous[0-9]*\$'"))
        $conf->update_schema_version(127);
    if ($conf->sversion == 127
        && $conf->ql("update PaperReview set reviewWordCount=null"))
        $conf->update_schema_version(128);
    if ($conf->sversion == 128
        && update_schema_bad_comment_timeDisplayed($conf))
        $conf->update_schema_version(129);
    if ($conf->sversion == 129
        && $conf->ql("update PaperComment set timeDisplayed=1 where timeDisplayed=0 and timeNotified>0"))
        $conf->update_schema_version(130);
    if ($conf->sversion == 130
        && $conf->ql("DROP TABLE IF EXISTS `PaperTagAnno`")
        && $conf->ql("CREATE TABLE `PaperTagAnno` (
  `tag` varchar(40) NOT NULL,   # see TAG_MAXLEN in header.php
  `annoId` int(11) NOT NULL,
  `tagIndex` float NOT NULL DEFAULT '0',
  `heading` varbinary(8192) DEFAULT NULL,
  `annoFormat` tinyint(1) DEFAULT NULL,
  `infoJson` varbinary(32768) DEFAULT NULL,
  PRIMARY KEY (`tag`,`annoId`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8"))
        $conf->update_schema_version(131);
    if ($conf->sversion == 131
        && $conf->ql("alter table PaperStorage modify `infoJson` varbinary(32768) DEFAULT NULL"))
        $conf->update_schema_version(132);
    if ($conf->sversion == 132
        && $conf->ql("DROP TABLE IF EXISTS `Mimetype`")
        && $conf->ql("CREATE TABLE `Mimetype` (
  `mimetypeid` int(11) NOT NULL,
  `mimetype` varbinary(200) NOT NULL,
  `extension` varbinary(10) DEFAULT NULL,
  `description` varbinary(200) DEFAULT NULL,
  `inline` tinyint(1) NOT NULL DEFAULT '0',
  PRIMARY KEY (`mimetypeid`),
  UNIQUE KEY `mimetypeid` (`mimetypeid`),
  UNIQUE KEY `mimetype` (`mimetype`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8"))
        $conf->update_schema_version(133);
    if ($conf->sversion == 133)
        $conf->update_schema_version(134);
    if ($conf->sversion == 134) {
        foreach (Dbl::fetch_first_columns($conf->dblink, "select distinct mimetype from PaperStorage") as $mt)
            Mimetype::lookup($mt);
        if (!Dbl::has_error())
            $conf->update_schema_version(135);
    }
    if ($conf->sversion == 135
        && $conf->ql("alter table PaperStorage add `mimetypeid` int(11) NOT NULL DEFAULT '0'")
        && $conf->ql("update PaperStorage, Mimetype set PaperStorage.mimetypeid=Mimetype.mimetypeid where PaperStorage.mimetype=Mimetype.mimetype"))
        $conf->update_schema_version(136);
    if ($conf->sversion == 136
        && $conf->ql("alter table PaperStorage drop key `paperId`")
        && $conf->ql("alter table PaperStorage drop key `mimetype`")
        && $conf->ql("alter table PaperStorage add key `byPaper` (`paperId`,`documentType`,`timestamp`,`paperStorageId`)"))
        $conf->update_schema_version(137);
    if ($conf->sversion == 137)
        $conf->update_schema_version(138);
    if (($conf->sversion == 138 || $conf->sversion == 139)
        && $conf->ql("DROP TABLE IF EXISTS `FilteredDocument`")
        && $conf->ql("CREATE TABLE `FilteredDocument` (
  `inDocId` int(11) NOT NULL,
  `filterType` int(11) NOT NULL,
  `outDocId` int(11) NOT NULL,
  `createdAt` int(11) NOT NULL,
  PRIMARY KEY (`inDocId`,`filterType`),
  UNIQUE KEY `inDocFilter` (`inDocId`,`filterType`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8"))
        $conf->update_schema_version(140);
    if ($conf->sversion == 140
        && $conf->ql("update Paper p join PaperStorage ps on (p.paperStorageId>1 and p.finalPaperStorageId<=0 and p.paperStorageId=ps.paperStorageId) set p.sha1=ps.sha1, p.timestamp=ps.timestamp, p.mimetype=ps.mimetype, p.size=ps.size where p.sha1!=ps.sha1 or p.timestamp!=ps.timestamp or p.mimetype!=ps.mimetype or p.size!=ps.size")
        && $conf->ql("update Paper p join PaperStorage ps on (p.finalPaperStorageId>0 and p.finalPaperStorageId=ps.paperStorageId) set p.sha1=ps.sha1, p.timestamp=ps.timestamp, p.mimetype=ps.mimetype, p.size=ps.size where p.sha1!=ps.sha1 or p.timestamp!=ps.timestamp or p.mimetype!=ps.mimetype or p.size!=ps.size"))
        $conf->update_schema_version(141);
    if ($conf->sversion == 141
        && $conf->ql("delete from Settings where name='pc'"))
        $conf->update_schema_version(142);
    if ($conf->sversion == 142
        && $conf->ql("alter table PaperReview add `reviewAuthorModified` int(1) DEFAULT NULL"))
        $conf->update_schema_version(143);
    if ($conf->sversion == 143
        && $conf->ql("alter table PaperReview add `timeApprovalRequested` int(11) NOT NULL DEFAULT '0'"))
        $conf->update_schema_version(144);
    if ($conf->sversion == 144
        && $conf->ql("alter table Paper add `pdfFormatStatus` int(11) NOT NULL DEFAULT '0'"))
        $conf->update_schema_version(145);
    if ($conf->sversion == 145
        && $conf->ql("alter table MailLog add `fromNonChair` tinyint(1) NOT NULL DEFAULT '0'"))
        $conf->update_schema_version(146);
    if ($conf->sversion == 146
        && $conf->ql("alter table Paper add `timeModified` int(11) NOT NULL DEFAULT '0'"))
        $conf->update_schema_version(147);
    if ($conf->sversion == 147
        && $conf->ql("alter table Capability change `capabilityId` `capabilityId` int(11) NOT NULL")
        && update_schema_drop_keys_if_exist($conf, "Capability", ["capabilityId", "PRIMARY"])
        && $conf->ql("alter table Capability add primary key (`salt`)")
        && $conf->ql("alter table Capability drop column `capabilityId`"))
        $conf->update_schema_version(148);
    if ($conf->sversion == 148
        && $conf->ql("alter table ReviewRating add `paperId` int(11) NOT NULL DEFAULT '0'")
        && $conf->ql("update ReviewRating join PaperReview using (reviewId) set ReviewRating.paperId=PaperReview.paperId")
        && $conf->ql("alter table ReviewRating change `paperId` `paperId` int(11) NOT NULL")
        && update_schema_drop_keys_if_exist($conf, "ReviewRating", ["reviewContact", "reviewContactRating"])
        && $conf->ql("alter table ReviewRating add primary key (`paperId`,`reviewId`,`contactId`)"))
        $conf->update_schema_version(149);
    if ($conf->sversion == 149
        && update_schema_drop_keys_if_exist($conf, "PaperReview", ["PRIMARY"])
        && $conf->ql("alter table PaperReview add primary key (`paperId`,`reviewId`)"))
        $conf->update_schema_version(150);
    if ($conf->sversion == 150
        && update_schema_drop_keys_if_exist($conf, "PaperComment", ["PRIMARY"])
        && $conf->ql("alter table PaperComment add primary key (`paperId`,`commentId`)")
        && update_schema_drop_keys_if_exist($conf, "PaperStorage", ["PRIMARY"])
        && $conf->ql("alter table PaperStorage add primary key (`paperId`,`paperStorageId`)"))
        $conf->update_schema_version(151);
    if ($conf->sversion == 151
        && update_schema_drop_keys_if_exist($conf, "ContactInfo", ["rolesCid", "rolesContactId", "contactIdRoles"])
        && $conf->ql("alter table ContactInfo add key `rolesContactId` (`roles`,`contactId`)"))
        $conf->update_schema_version(152);
    if ($conf->sversion == 152
        && update_schema_drop_keys_if_exist($conf, "PaperReview", ["reviewSubmitted"])
        && update_schema_drop_keys_if_exist($conf, "PaperComment", ["timeModified", "paperId", "contactPaper"])
        && $conf->ql("alter table PaperComment add key `timeModifiedContact` (`timeModified`,`contactId`)")
        && $conf->ql("alter table PaperReview add key `reviewSubmittedContact` (`reviewSubmitted`,`contactId`)"))
        $conf->update_schema_version(153);
    if ($conf->sversion == 153
        && update_schema_mimetype_extensions($conf))
        $conf->update_schema_version(154);
    if ($conf->sversion == 154) {
        if ($conf->fetch_value("select tag from PaperTag where tag like ':%:' limit 1"))
            $conf->save_setting("has_colontag", 1);
        $conf->update_schema_version(155);
    }
    if ($conf->sversion == 155) {
        if ($conf->fetch_value("select tag from PaperTag where tag like '%:' limit 1"))
            $conf->save_setting("has_colontag", 1);
        $conf->update_schema_version(156);
    }
    if ($conf->sversion == 156
        && $conf->ql("delete from TopicInterest where interest is null")
        && $conf->ql("alter table TopicInterest change `interest` `interest` int(1) NOT NULL")
        && $conf->ql("update TopicInterest set interest=1 where interest=2")
        && $conf->ql("update TopicInterest set interest=2 where interest=4")
        && $conf->ql("delete from TopicInterest where interest=0"))
        $conf->update_schema_version(157);
    if ($conf->sversion == 157
        && $conf->ql("alter table PaperOption drop key `paperOption`")
        && $conf->ql("alter table PaperOption add primary key (`paperId`,`optionId`,`value`)")
        && $conf->ql("alter table PaperOption change `data` `data` varbinary(32767) DEFAULT NULL")
        && $conf->ql("alter table PaperOption add `dataOverflow` longblob DEFAULT NULL"))
        $conf->update_schema_version(158);
    if ($conf->sversion == 158
        && $conf->ql("alter table ContactInfo drop key `rolesContactId`")
        && $conf->ql("alter table ContactInfo add unique key `rolesContactId` (`roles`,`contactId`)"))
        $conf->update_schema_version(159);
    if ($conf->sversion == 159
        && $conf->ql("alter table ActionLog drop key `logId`")
        && $conf->ql("alter table Capability drop key `salt`")
        && $conf->ql("alter table ContactInfo drop key `contactId`")
        && $conf->ql("alter table FilteredDocument drop key `inDocFilter`")
        && $conf->ql("alter table Formula drop key `formulaId`")
        && $conf->ql("alter table Mimetype drop key `mimetypeid`")
        && $conf->ql("alter table Paper drop key `paperId`")
        && $conf->ql("alter table TopicArea drop key `topicId`"))
        $conf->update_schema_version(160);
    if ($conf->sversion == 160
        && $conf->ql("alter table Paper change `sha1` `sha1` varbinary(64) NOT NULL DEFAULT ''")
        && $conf->ql("alter table PaperStorage change `sha1` `sha1` varbinary(64) NOT NULL DEFAULT ''"))
        $conf->update_schema_version(161);
    if ($conf->sversion == 161
        && $conf->ql("alter table PaperTag change `tag` `tag` varbinary(80) NOT NULL")
        && $conf->ql("alter table PaperTagAnno change `tag` `tag` varbinary(80) NOT NULL"))
        $conf->update_schema_version(162);
    if ($conf->sversion == 162
        && $conf->ql("alter table PaperTag change `tag` `tag` varchar(80) NOT NULL")
        && $conf->ql("alter table PaperTagAnno change `tag` `tag` varchar(80) NOT NULL"))
        $conf->update_schema_version(163);
    if ($conf->sversion == 163
        && $conf->ql("alter table Capability change `timeExpires` `timeExpires` bigint(11) NOT NULL")
        && $conf->ql("alter table ContactInfo change `passwordTime` `passwordTime` bigint(11) NOT NULL DEFAULT '0'")
        && $conf->ql("alter table ContactInfo change `passwordUseTime` `passwordUseTime` bigint(11) NOT NULL DEFAULT '0'")
        && $conf->ql("alter table ContactInfo change `creationTime` `creationTime` bigint(11) NOT NULL DEFAULT '0'")
        && $conf->ql("alter table ContactInfo change `updateTime` `updateTime` bigint(11) NOT NULL DEFAULT '0'")
        && $conf->ql("alter table ContactInfo change `lastLogin` `lastLogin` bigint(11) NOT NULL DEFAULT '0'")
        && $conf->ql("alter table FilteredDocument change `createdAt` `createdAt` bigint(11) NOT NULL")
        && $conf->ql("alter table Formula change `timeModified` `timeModified` bigint(11) NOT NULL DEFAULT '0'")
        && $conf->ql("alter table Paper change `timeSubmitted` `timeSubmitted` bigint(11) NOT NULL DEFAULT '0'")
        && $conf->ql("alter table Paper change `timeWithdrawn` `timeWithdrawn` bigint(11) NOT NULL DEFAULT '0'")
        && $conf->ql("alter table Paper change `timeFinalSubmitted` `timeFinalSubmitted` bigint(11) NOT NULL DEFAULT '0'")
        && $conf->ql("alter table Paper change `timeModified` `timeModified` bigint(11) NOT NULL DEFAULT '0'")
        && $conf->ql("alter table Paper change `timestamp` `timestamp` bigint(11) NOT NULL DEFAULT '0'")
        && $conf->ql("alter table Paper change `pdfFormatStatus` `pdfFormatStatus` bigint(11) NOT NULL DEFAULT '0'")
        && $conf->ql("alter table PaperComment change `timeModified` `timeModified` bigint(11) NOT NULL")
        && $conf->ql("alter table PaperComment change `timeNotified` `timeNotified` bigint(11) NOT NULL DEFAULT '0'")
        && $conf->ql("alter table PaperComment change `timeDisplayed` `timeDisplayed` bigint(11) NOT NULL DEFAULT '0'")
        && $conf->ql("alter table PaperOption change `value` `value` bigint(11) NOT NULL DEFAULT '0'")
        && $conf->ql("alter table PaperReview change `timeRequested` `timeRequested` bigint(11) NOT NULL DEFAULT '0'")
        && $conf->ql("alter table PaperReview change `timeRequestNotified` `timeRequestNotified` bigint(11) NOT NULL DEFAULT '0'")
        && $conf->ql("alter table PaperReview change `reviewModified` `reviewModified` bigint(1) DEFAULT NULL")
        && $conf->ql("alter table PaperReview change `reviewAuthorModified` `reviewAuthorModified` bigint(1) DEFAULT NULL")
        && $conf->ql("alter table PaperReview change `reviewSubmitted` `reviewSubmitted` bigint(1) DEFAULT NULL")
        && $conf->ql("alter table PaperReview change `reviewNotified` `reviewNotified` bigint(1) DEFAULT NULL")
        && $conf->ql("alter table PaperReview change `reviewAuthorNotified` `reviewAuthorNotified` bigint(11) NOT NULL DEFAULT '0'")
        && $conf->ql("alter table PaperReview change `reviewAuthorSeen` `reviewAuthorSeen` bigint(1) DEFAULT NULL")
        && $conf->ql("alter table PaperReview change `timeDisplayed` `timeDisplayed` bigint(11) NOT NULL DEFAULT '0'")
        && $conf->ql("alter table PaperReview change `timeApprovalRequested` `timeApprovalRequested` bigint(11) NOT NULL DEFAULT '0'")
        && $conf->ql("alter table PaperStorage change `timestamp` `timestamp` bigint(11) NOT NULL")
        && $conf->ql("alter table Settings change `value` `value` bigint(11) NOT NULL"))
        $conf->update_schema_version(164);
    if ($conf->sversion == 164
        && $conf->ql("alter table Paper change `title` `title` varbinary(512) DEFAULT NULL"))
        $conf->update_schema_version(165);
    if ($conf->sversion == 165
        && $conf->ql("alter table TopicArea drop key `topicName`")
        && $conf->ql("alter table TopicArea change `topicName` `topicName` varbinary(1024) DEFAULT NULL"))
        $conf->update_schema_version(166);
    if ($conf->sversion == 166
        && $conf->ql("alter table PaperReviewPreference drop key `contactPaper`"))
        $conf->update_schema_version(167);
    if ($conf->sversion == 167
        && $conf->ql("update PaperReview set reviewOrdinal=0 where reviewOrdinal is null")
        && $conf->ql("alter table PaperReview change `reviewOrdinal` `reviewOrdinal` int(1) NOT NULL DEFAULT '0'"))
        $conf->update_schema_version(168);
    if ($conf->sversion == 168
        && $conf->ql("update PaperReview set reviewModified=0 where reviewModified is null")
        && $conf->ql("alter table PaperReview change `reviewModified` `reviewModified` bigint(1) NOT NULL DEFAULT '0'"))
        $conf->update_schema_version(169);
    if ($conf->sversion == 169) {
        if ($conf->fetch_ivalue("select exists (select * from TopicArea)"))
            $conf->save_setting("has_topics", 1);
        $conf->update_schema_version(170);
    }
    if ($conf->sversion == 170
        && $conf->ql("alter table ActionLog drop key `contactId`")
        && $conf->ql("alter table ActionLog drop key `paperId`")
        && $conf->ql("alter table ActionLog add `destContactId` int(11) NOT NULL DEFAULT '0'"))
        $conf->update_schema_version(171);
    if ($conf->sversion == 171
        && $conf->ql("DROP TABLE IF EXISTS `DeletedContactInfo`")
        && $conf->ql("CREATE TABLE `DeletedContactInfo` (
  `contactId` int(11) NOT NULL,
  `firstName` varchar(60) NOT NULL,
  `lastName` varchar(60) NOT NULL,
  `email` varchar(120) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8"))
        $conf->update_schema_version(172);
    if ($conf->sversion == 172
        && $conf->ql("alter table DeletedContactInfo add `unaccentedName` varchar(120) NOT NULL DEFAULT ''")
        && $conf->ql("alter table DeletedContactInfo change `unaccentedName` `unaccentedName` varchar(120) NOT NULL"))
        $conf->update_schema_version(173);
    if ($conf->sversion == 173
        && update_schema_paper_review_tfields($conf))
        $conf->update_schema_version(174);
    if ($conf->sversion == 174
        && update_schema_paper_review_null_main_fields($conf))
        $conf->update_schema_version(175);
    if ($conf->sversion == 175
        && update_schema_paper_review_drop_main_fields($conf))
        $conf->update_schema_version(176);
    if ($conf->sversion == 176) {
        if (($x = $conf->setting_data("scoresort_default"))) {
            $conf->save_setting("scoresort_default", null);
            $conf->save_setting("opt.defaultScoreSort", 1, $x);
        }
        $conf->update_schema_version(177);
    }
    if ($conf->sversion == 177
        && $conf->ql("alter table PaperStorage drop `mimetypeid`")
        && $conf->ql("drop table if exists `Mimetype`"))
        $conf->update_schema_version(178);
    if ($conf->sversion == 178
        && $conf->ql("delete from Settings where name='papersub'")) {
        $conf->update_papersub_setting(0);
        $conf->update_schema_version(179);
    }
    if ($conf->sversion == 179
        && $conf->ql("alter table ContactInfo change `affiliation` `affiliation` varbinary(2048) NOT NULL DEFAULT ''")
        && $conf->ql("alter table ContactInfo change `voicePhoneNumber` `voicePhoneNumber` varbinary(256) DEFAULT NULL"))
        $conf->update_schema_version(180);
    if ($conf->sversion == 180
        && $conf->ql("alter table ActionLog change `ipaddr` `ipaddr` varbinary(39) DEFAULT NULL"))
        $conf->update_schema_version(181);
    if ($conf->sversion == 181
        && $conf->ql("alter table ContactInfo change `firstName` `firstName` varbinary(120) NOT NULL DEFAULT ''")
        && $conf->ql("alter table ContactInfo change `lastName` `lastName` varbinary(120) NOT NULL DEFAULT ''")
        && $conf->ql("alter table ContactInfo change `unaccentedName` `unaccentedName` varbinary(240) NOT NULL DEFAULT ''")
        && $conf->ql("alter table DeletedContactInfo change `firstName` `firstName` varbinary(120) NOT NULL")
        && $conf->ql("alter table DeletedContactInfo change `lastName` `lastName` varbinary(120) NOT NULL")
        && $conf->ql("alter table DeletedContactInfo change `unaccentedName` `unaccentedName` varbinary(240) NOT NULL"))
        $conf->update_schema_version(182);
    if ($conf->sversion == 182
        && $conf->ql("alter table ContactInfo add `birthday` int(11) DEFAULT NULL")
        && $conf->ql("alter table ContactInfo add `gender` varbinary(24) DEFAULT NULL"))
        $conf->update_schema_version(183);
    if ($conf->sversion == 183
        // good=1,1; too short=0,4; too vague=-1,8; too narrow=-4,16;
        // not constructive=-2,32; not correct=-3,64
        && $conf->ql("update ReviewRating set rating=case rating when 0 then 4 when -1 then 8 when -4 then 16 when -2 then 32 when -3 then 64 else if(rating<0,2,1) end"))
        $conf->update_schema_version(184);
    if ($conf->sversion == 184
        && $conf->ql("alter table PaperReview drop key `reviewSubmittedContact`"))
        $conf->update_schema_version(185);
    if ($conf->sversion == 185
        && $conf->ql("alter table ContactInfo change `voicePhoneNumber` `phone` varbinary(64) DEFAULT NULL"))
        $conf->update_schema_version(186);
    if ($conf->sversion == 186
        && $conf->ql("alter table PaperReviewRefused add primary key (`paperId`,`contactId`)")
        && $conf->ql("alter table PaperReviewRefused drop key `paperId`")
        && $conf->ql("alter table PaperReviewRefused drop key `contactId`")
        && $conf->ql("alter table PaperReviewRefused drop key `requestedBy`"))
        $conf->update_schema_version(187);
    if ($conf->sversion == 187
        && $conf->ql("alter table ReviewRequest change `email` `email` varchar(120) NOT NULL")
        && $conf->ql("alter table ReviewRequest add primary key (`paperId`,`email`)")
        && $conf->ql("alter table ReviewRequest drop key `paperEmail`")
        && $conf->ql("alter table ReviewRequest drop key `paperId`")
        && $conf->ql("alter table ReviewRequest drop key `requestedBy`"))
        $conf->update_schema_version(188);
    if ($conf->sversion == 188
        && update_schema_split_review_request_name($conf))
        $conf->update_schema_version(189);
    if ($conf->sversion == 189
        && $conf->ql("alter table ReviewRequest add `affiliation` varbinary(2048) DEFAULT NULL"))
        $conf->update_schema_version(190);
    if ($conf->sversion == 190) {
        if ($conf->setting("rev_notifychair") > 0) {
            $conf->ql("update ContactInfo set defaultWatch=defaultWatch|" . Contact::WATCH_REVIEW_ALL . " where roles!=0 and (roles&" . Contact::ROLE_CHAIR . ")!=0");
            $conf->ql("delete from Settings where name=?", "rev_notifychair");
        }
        $conf->update_schema_version(191);
    }
    if ($conf->sversion == 191) {
        update_schema_missing_sha1($conf);
        $conf->update_schema_version(192);
    }
    if ($conf->sversion == 192
        && $conf->ql("alter table PaperStorage drop key `byPaper`"))
        $conf->update_schema_version(193);
    if ($conf->sversion == 193
        && $conf->ql("alter table Settings change `name` `name` varbinary(256) NOT NULL")
        && $conf->ql("alter table Settings add primary key (`name`)")
        && $conf->ql("alter table Settings drop key `name`"))
        $conf->update_schema_version(194);
    if ($conf->sversion == 194
        && $conf->ql("alter table ContactInfo drop key `rolesContactId`")
        && $conf->ql("alter table ContactInfo add key `roles` (`roles`)")
        && $conf->ql("alter table ContactInfo drop key `fullName`")
        && $conf->ql("alter table PaperReview drop key `contactPaper`")
        && $conf->ql("alter table PaperReview add key `contactId` (`contactId`)")
        && $conf->ql("alter table PaperReview drop key `reviewNeedsSubmit`")
        && $conf->ql("alter table PaperReview drop key `paperId`"))
        $conf->update_schema_version(195);
    if ($conf->sversion == 195
        && $conf->ql("alter table PaperStorage add `inactive` tinyint(1) NOT NULL DEFAULT '0'")
        && $conf->ql("update PaperStorage set inactive=1")
        && $conf->ql("update PaperStorage join Paper on (Paper.paperId=PaperStorage.paperId and Paper.paperStorageId=PaperStorage.paperStorageId) set PaperStorage.inactive=0")
        && $conf->ql("update PaperStorage join Paper on (Paper.paperId=PaperStorage.paperId and Paper.finalPaperStorageId=PaperStorage.paperStorageId) set PaperStorage.inactive=0")
        && $conf->ql("update PaperStorage join PaperOption on (PaperOption.paperId=PaperStorage.paperId and PaperOption.value=PaperStorage.paperStorageId) set PaperStorage.inactive=0"))
        $conf->update_schema_version(196);
    if ($conf->sversion == 196
        && $conf->ql("drop table if exists `DocumentLink`")
        && $conf->ql("create table `DocumentLink` (
  `paperId` int(11) NOT NULL,
  `linkId` int(11) NOT NULL,
  `linkType` int(11) NOT NULL,
  `documentId` int(11) NOT NULL,
  PRIMARY KEY (`paperId`,`linkId`,`linkType`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8"))
        $conf->update_schema_version(197);
    if ($conf->sversion == 197
        && $conf->ql("alter table PaperConflict add key `paperId` (`paperId`)"))
        $conf->update_schema_version(198);
    if ($conf->sversion == 198
        && update_schema_selector_options($conf))
        $conf->update_schema_version(199);

    $conf->ql("delete from Settings where name='__schema_lock'");
    Conf::$g = $old_conf_g;
}
