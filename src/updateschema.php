<?php
// updateschema.php -- HotCRP function for updating old schemata
// HotCRP is Copyright (c) 2006-2016 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

function update_schema_create_review_form($Conf) {
    if (!($result = Dbl::ql("select * from ReviewFormField where fieldName!='outcome'")))
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

    if (!($result = Dbl::ql("select * from ReviewFormOptions where fieldName!='outcome' order by level asc")))
        return false;
    while (($row = edb_orow($result))) {
        $fname = $row->fieldName;
        if (isset($rfj->$fname) && isset($rfj->$fname->options))
            $rfj->$fname->options[$row->level - 1] = $row->description;
    }

    return $Conf->save_setting("review_form", 1, $rfj);
}

function update_schema_create_options($Conf) {
    if (!($result = Dbl::ql("select * from OptionType")))
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

        $abbr = PaperOption::abbreviate($opj->name, $opj->id);
        if (!@$byabbr[$abbr]) {
            $opj->abbr = $abbr;
            $byabbr[$abbr] = $opj;
        } else {
            $opj->abbr = "opt$opj->id";
            $byabbr[$abbr]->abbr = "opt" . $byabbr[$abbr]->id;
        }

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

    return $Conf->save_setting("options", 1, $opsj);
}

function update_schema_transfer_address($Conf) {
    $result = Dbl::ql("select * from ContactAddress");
    while (($row = edb_orow($result)))
        if (($c = Contact::find_by_id($row->contactId))) {
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

function update_schema_unaccented_name($Conf) {
    if (!Dbl::ql("alter table ContactInfo add `unaccentedName` varchar(120) NOT NULL DEFAULT ''"))
        return false;

    $result = Dbl::ql("select contactId, firstName, lastName from ContactInfo");
    if (!$result)
        return false;

    $qs = $qv = array();
    while ($result && ($x = $result->fetch_row())) {
        $qs[] = "update ContactInfo set unaccentedName=? where contactId=$x[0]";
        $qv[] = Text::unaccented_name($x[1], $x[2]);
    }
    Dbl::free($result);

    $q = Dbl::format_query_apply($Conf->dblink, join(";", $qs), $qv);
    if (!$Conf->dblink->multi_query($q))
        return false;
    do {
        if ($result = $Conf->dblink->store_result())
            $result->free();
    } while ($Conf->dblink->more_results() && $Conf->dblink->next_result());
    return true;
}

function update_schema_transfer_country($Conf) {
    $result = Dbl::ql($Conf->dblink, "select * from ContactInfo where `data` is not null and `data`!='{}'");
    while ($result && ($c = $result->fetch_object("Contact"))) {
        if (($country = $c->data("country")))
            Dbl::ql($Conf->dblink, "update ContactInfo set country=? where contactId=?", $country, $c->contactId);
    }
    return true;
}

function update_schema_review_word_counts($Conf) {
    $rf = new ReviewForm($Conf->review_form_json());
    do {
        $q = array();
        $result = Dbl::ql("select * from PaperReview where reviewWordCount is null limit 32");
        while (($rrow = edb_orow($result)))
            $q[] = "update PaperReview set reviewWordCount="
                . $rf->word_count($rrow) . " where reviewId=" . $rrow->reviewId;
        Dbl::free($result);
        $Conf->dblink->multi_query(join(";", $q));
        while ($Conf->dblink->more_results())
            Dbl::free($Conf->dblink->next_result());
    } while (count($q) == 32);
}

function updateSchema($Conf) {
    global $Opt, $OK;
    // avoid error message abut timezone, set to $Opt
    // (which might be overridden by database values later)
    if (function_exists("date_default_timezone_set") && @$Opt["timezone"])
        date_default_timezone_set($Opt["timezone"]);

    error_log($Opt["dbName"] . ": updating schema from version " . $Conf->sversion);

    if ($Conf->sversion == 6
        && Dbl::ql("alter table ReviewRequest add `reason` text"))
        $Conf->update_schema_version(7);
    if ($Conf->sversion == 7
        && Dbl::ql("alter table PaperReview add `textField7` mediumtext NOT NULL")
        && Dbl::ql("alter table PaperReview add `textField8` mediumtext NOT NULL")
        && Dbl::ql("insert into ReviewFormField set fieldName='textField7', shortName='Additional text field'")
        && Dbl::ql("insert into ReviewFormField set fieldName='textField8', shortName='Additional text field'"))
        $Conf->update_schema_version(8);
    if ($Conf->sversion == 8
        && Dbl::ql("alter table ReviewFormField add `levelChar` tinyint(1) NOT NULL default '0'")
        && Dbl::ql("alter table PaperReviewArchive add `textField7` mediumtext NOT NULL")
        && Dbl::ql("alter table PaperReviewArchive add `textField8` mediumtext NOT NULL"))
        $Conf->update_schema_version(9);
    if ($Conf->sversion == 9
        && Dbl::ql("alter table Paper add `sha1` varbinary(20) NOT NULL default ''"))
        $Conf->update_schema_version(10);
    if ($Conf->sversion == 10
        && Dbl::ql("alter table PaperReview add `reviewRound` tinyint(1) NOT NULL default '0'")
        && Dbl::ql("alter table PaperReviewArchive add `reviewRound` tinyint(1) NOT NULL default '0'")
        && Dbl::ql("alter table PaperReview add key `reviewRound` (`reviewRound`)")
        && $Conf->update_schema_version(11)) {
        if (count($Conf->round_list()) > 1) {
            // update review rounds (XXX locking)
            $result = Dbl::ql("select paperId, tag from PaperTag where tag like '%~%'");
            $rrs = array();
            while (($row = edb_row($result))) {
                list($contact, $round) = explode("~", $row[1]);
                if (($round = array_search($round, $Conf->round_list()))) {
                    if (!isset($rrs[$round]))
                        $rrs[$round] = array();
                    $rrs[$round][] = "(contactId=$contact and paperId=$row[0])";
                }
            }
            foreach ($rrs as $round => $pairs) {
                $q = "update PaperReview set reviewRound=$round where " . join(" or ", $pairs);
                Dbl::ql($q);
            }
            $x = trim(preg_replace('/(\S+)\s*/', "tag like '%~\$1' or ", $Conf->setting_data("tag_rounds")));
            Dbl::ql("delete from PaperTag where " . substr($x, 0, strlen($x) - 3));
        }
    }
    if ($Conf->sversion == 11
        && Dbl::ql("create table `ReviewRating` (
  `reviewId` int(11) NOT NULL,
  `contactId` int(11) NOT NULL,
  `rating` tinyint(1) NOT NULL default '0',
  UNIQUE KEY `reviewContact` (`reviewId`,`contactId`),
  UNIQUE KEY `reviewContactRating` (`reviewId`,`contactId`,`rating`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8"))
        $Conf->update_schema_version(12);
    if ($Conf->sversion == 12
        && Dbl::ql("alter table PaperReview add `reviewToken` int(11) NOT NULL default '0'"))
        $Conf->update_schema_version(13);
    if ($Conf->sversion == 13
        && Dbl::ql("alter table OptionType add `optionValues` text NOT NULL default ''"))
        $Conf->update_schema_version(14);
    if ($Conf->sversion == 14
        && Dbl::ql("insert into Settings (name, value) select 'rev_tokens', count(reviewId) from PaperReview where reviewToken!=0 on duplicate key update value=values(value)"))
        $Conf->update_schema_version(15);
    if ($Conf->sversion == 15) {
        // It's OK if this fails!  Update 11 added reviewRound to
        // PaperReviewArchive (so old databases have the column), but I forgot
        // to upgrade schema.sql (so new databases lack the column).
        Dbl::ql("alter table PaperReviewArchive add `reviewRound` tinyint(1) NOT NULL default '0'");
        $OK = true;
        $Conf->update_schema_version(16);
    }
    if ($Conf->sversion == 16
        && Dbl::ql("alter table PaperReview add `reviewEditVersion` int(1) NOT NULL default '0'"))
        $Conf->update_schema_version(17);
    if ($Conf->sversion == 17
        && Dbl::ql("alter table PaperReviewPreference add key `paperId` (`paperId`)")
        && Dbl::ql("create table PaperRank (
  `paperId` int(11) NOT NULL,
  `contactId` int(11) NOT NULL,
  `rank` int(11) NOT NULL,
  UNIQUE KEY `contactPaper` (`contactId`,`paperId`),
  KEY `paperId` (`paperId`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8;"))
        $Conf->update_schema_version(18);
    if ($Conf->sversion == 18
        && Dbl::ql("alter table PaperComment add `replyTo` int(11) NOT NULL"))
        $Conf->update_schema_version(19);
    if ($Conf->sversion == 19
        && Dbl::ql("drop table PaperRank"))
        $Conf->update_schema_version(20);
    if ($Conf->sversion == 20
        && Dbl::ql("alter table PaperComment add `timeNotified` int(11) NOT NULL default '0'"))
        $Conf->update_schema_version(21);
    if ($Conf->sversion == 21
        && Dbl::ql("update PaperConflict set conflictType=8 where conflictType=3"))
        $Conf->update_schema_version(22);
    if ($Conf->sversion == 22
        && Dbl::ql("insert into ChairAssistant (contactId) select contactId from Chair on duplicate key update ChairAssistant.contactId=ChairAssistant.contactId")
        && Dbl::ql("update ContactInfo set roles=roles+2 where roles=5"))
        $Conf->update_schema_version(23);
    if ($Conf->sversion == 23)
        $Conf->update_schema_version(24);
    if ($Conf->sversion == 24
        && Dbl::ql("alter table ContactInfo add `preferredEmail` varchar(120)"))
        $Conf->update_schema_version(25);
    if ($Conf->sversion == 25) {
        if ($Conf->settings["final_done"] > 0
            && !isset($Conf->settings["final_soft"])
            && Dbl::ql("insert into Settings (name, value) values ('final_soft', " . $Conf->settings["final_done"] . ") on duplicate key update value=values(value)"))
            $Conf->settings["final_soft"] = $Conf->settings["final_done"];
        $Conf->update_schema_version(26);
    }
    if ($Conf->sversion == 26
        && Dbl::ql("alter table PaperOption add `data` text")
        && Dbl::ql("alter table OptionType add `type` tinyint(1) NOT NULL default '0'")
        && Dbl::ql("update OptionType set type=2 where optionValues='\x7Fi'")
        && Dbl::ql("update OptionType set type=1 where type=0 and optionValues!=''"))
        $Conf->update_schema_version(27);
    if ($Conf->sversion == 27
        && Dbl::ql("alter table PaperStorage add `sha1` varbinary(20) NOT NULL default ''")
        && Dbl::ql("alter table PaperStorage add `documentType` int(3) NOT NULL default '0'")
        && Dbl::ql("update PaperStorage s, Paper p set s.sha1=p.sha1 where s.paperStorageId=p.paperStorageId and p.finalPaperStorageId=0 and s.paperStorageId>0")
        && Dbl::ql("update PaperStorage s, Paper p set s.sha1=p.sha1, s.documentType=" . DTYPE_FINAL . " where s.paperStorageId=p.finalPaperStorageId and s.paperStorageId>0"))
        $Conf->update_schema_version(28);
    if ($Conf->sversion == 28
        && Dbl::ql("alter table OptionType add `sortOrder` tinyint(1) NOT NULL default '0'"))
        $Conf->update_schema_version(29);
    if ($Conf->sversion == 29
        && Dbl::ql("delete from Settings where name='pldisplay_default'"))
        $Conf->update_schema_version(30);
    if ($Conf->sversion == 30
        && Dbl::ql("DROP TABLE IF EXISTS `Formula`")
        && Dbl::ql("CREATE TABLE `Formula` (
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
        $Conf->update_schema_version(31);
    if ($Conf->sversion == 31
        && Dbl::ql("alter table Formula add `createdBy` int(11) NOT NULL default '0'")
        && Dbl::ql("alter table Formula add `timeModified` int(11) NOT NULL default '0'")
        && Dbl::ql("alter table Formula drop index `name`"))
        $Conf->update_schema_version(32);
    if ($Conf->sversion == 32
        && Dbl::ql("alter table PaperComment add key `timeModified` (`timeModified`)"))
        $Conf->update_schema_version(33);
    if ($Conf->sversion == 33
        && Dbl::ql("alter table PaperComment add `paperStorageId` int(11) NOT NULL default '0'"))
        $Conf->update_schema_version(34);
    if ($Conf->sversion == 34
        && Dbl::ql("alter table ContactInfo add `contactTags` text"))
        $Conf->update_schema_version(35);
    if ($Conf->sversion == 35
        && Dbl::ql("alter table ContactInfo modify `defaultWatch` int(11) NOT NULL default '2'")
        && Dbl::ql("alter table PaperWatch modify `watch` int(11) NOT NULL default '0'"))
        $Conf->update_schema_version(36);
    if ($Conf->sversion == 36
        && Dbl::ql("alter table PaperReview add `reviewNotified` int(1) default NULL")
        && Dbl::ql("alter table PaperReviewArchive add `reviewNotified` int(1) default NULL"))
        $Conf->update_schema_version(37);
    if ($Conf->sversion == 37
        && Dbl::ql("alter table OptionType add `displayType` tinyint(1) NOT NULL default '0'"))
        $Conf->update_schema_version(38);
    if ($Conf->sversion == 38
        && Dbl::ql("update PaperComment set forReviewers=1 where forReviewers=-1"))
        $Conf->update_schema_version(39);
    if ($Conf->sversion == 39
        && Dbl::ql("CREATE TABLE `MailLog` (
  `mailId` int(11) NOT NULL auto_increment,
  `recipients` varchar(200) NOT NULL,
  `paperIds` text,
  `cc` text,
  `replyto` text,
  `subject` text,
  `emailBody` text,
  PRIMARY KEY  (`mailId`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8"))
        $Conf->update_schema_version(40);
    if ($Conf->sversion == 40
        && Dbl::ql("alter table Paper add `capVersion` int(1) NOT NULL default '0'"))
        $Conf->update_schema_version(41);
    if ($Conf->sversion == 41
        && Dbl::ql("alter table Paper modify `mimetype` varchar(80) NOT NULL default ''")
        && Dbl::ql("alter table PaperStorage modify `mimetype` varchar(80) NOT NULL default ''"))
        $Conf->update_schema_version(42);
    if ($Conf->sversion == 42
        && Dbl::ql("alter table PaperComment add `ordinal` int(11) NOT NULL default '0'"))
        $Conf->update_schema_version(43);
    if ($Conf->sversion == 42
        && ($result = Dbl::ql("describe PaperComment `ordinal`"))
        && ($o = edb_orow($result))
        && substr($o->Type, 0, 3) == "int"
        && (!$o->Null || $o->Null == "NO")
        && (!$o->Default || $o->Default == "0"))
        $Conf->update_schema_version(43);
    if ($Conf->sversion == 43
        && Dbl::ql("alter table Paper add `withdrawReason` text"))
        $Conf->update_schema_version(44);
    if ($Conf->sversion == 44
        && Dbl::ql("alter table PaperStorage add `filename` varchar(255)"))
        $Conf->update_schema_version(45);
    if ($Conf->sversion == 45
        && Dbl::ql("alter table PaperReview add `timeRequested` int(11) NOT NULL DEFAULT '0'")
        && Dbl::ql("alter table PaperReview add `timeRequestNotified` int(11) NOT NULL DEFAULT '0'")
        && Dbl::ql("alter table PaperReviewArchive add `timeRequested` int(11) NOT NULL DEFAULT '0'")
        && Dbl::ql("alter table PaperReviewArchive add `timeRequestNotified` int(11) NOT NULL DEFAULT '0'")
        && Dbl::ql("alter table PaperReview drop column `requestedOn`")
        && Dbl::ql("alter table PaperReviewArchive drop column `requestedOn`"))
        $Conf->update_schema_version(46);
    if ($Conf->sversion == 46
        && Dbl::ql("alter table ContactInfo add `disabled` tinyint(1) NOT NULL DEFAULT '0'"))
        $Conf->update_schema_version(47);
    if ($Conf->sversion == 47
        && Dbl::ql("delete from ti using TopicInterest ti left join TopicArea ta using (topicId) where ta.topicId is null"))
        $Conf->update_schema_version(48);
    if ($Conf->sversion == 48
        && Dbl::ql("alter table PaperReview add `reviewAuthorNotified` int(11) NOT NULL DEFAULT '0'")
        && Dbl::ql("alter table PaperReviewArchive add `reviewAuthorNotified` int(11) NOT NULL DEFAULT '0'")
        && Dbl::ql("alter table PaperReviewArchive add `reviewToken` int(11) NOT NULL DEFAULT '0'"))
        $Conf->update_schema_version(49);
    if ($Conf->sversion == 49
        && Dbl::ql("alter table PaperOption drop index `paperOption`")
        && Dbl::ql("alter table PaperOption add index `paperOption` (`paperId`,`optionId`,`value`)"))
        $Conf->update_schema_version(50);
    if ($Conf->sversion == 50
        && Dbl::ql("alter table Paper add `managerContactId` int(11) NOT NULL DEFAULT '0'"))
        $Conf->update_schema_version(51);
    if ($Conf->sversion == 51
        && Dbl::ql("alter table Paper drop column `numComments`")
        && Dbl::ql("alter table Paper drop column `numAuthorComments`"))
        $Conf->update_schema_version(52);
    // Due to a bug in the schema updater, some databases might have
    // sversion>=53 but no `PaperComment.commentType` column. Fix them.
    if (($Conf->sversion == 52
         || ($Conf->sversion >= 53
             && ($result = Dbl::ql("show columns from PaperComment like 'commentType'"))
             && edb_nrows($result) == 0))
        && Dbl::ql("lock tables PaperComment write, Settings write")
        && Dbl::ql("alter table PaperComment add `commentType` int(11) NOT NULL DEFAULT '0'")) {
        $new_sversion = max($Conf->sversion, 53);
        $result = Dbl::ql("show columns from PaperComment like 'forAuthors'");
        if (!$result
            || edb_nrows($result) == 0
            || (Dbl::ql("update PaperComment set commentType=" . (COMMENTTYPE_AUTHOR | COMMENTTYPE_RESPONSE) . " where forAuthors=2")
                && Dbl::ql("update PaperComment set commentType=commentType|" . COMMENTTYPE_DRAFT . " where forAuthors=2 and forReviewers=0")
                && Dbl::ql("update PaperComment set commentType=" . COMMENTTYPE_ADMINONLY . " where forAuthors=0 and forReviewers=2")
                && Dbl::ql("update PaperComment set commentType=" . COMMENTTYPE_PCONLY . " where forAuthors=0 and forReviewers=0")
                && Dbl::ql("update PaperComment set commentType=" . COMMENTTYPE_REVIEWER . " where forAuthors=0 and forReviewers=1")
                && Dbl::ql("update PaperComment set commentType=" . COMMENTTYPE_AUTHOR . " where forAuthors!=0 and forAuthors!=2")
                && Dbl::ql("update PaperComment set commentType=commentType|" . COMMENTTYPE_BLIND . " where blind=1")))
            $Conf->update_schema_version($new_sversion);
    }
    if ($Conf->sversion < 53)
        Dbl::qx_raw($Conf->dblink, "alter table PaperComment drop column `commentType`");
    Dbl::ql("unlock tables");
    if ($Conf->sversion == 53
        && Dbl::ql("alter table PaperComment drop column `forReviewers`")
        && Dbl::ql("alter table PaperComment drop column `forAuthors`")
        && Dbl::ql("alter table PaperComment drop column `blind`"))
        $Conf->update_schema_version(54);
    if ($Conf->sversion == 54
        && Dbl::ql("alter table PaperStorage add `infoJson` varchar(255) DEFAULT NULL"))
        $Conf->update_schema_version(55);
    if ($Conf->sversion == 55
        && Dbl::ql("alter table ContactInfo modify `password` varbinary(2048) NOT NULL"))
        $Conf->update_schema_version(56);
    if ($Conf->sversion == 56
        && Dbl::ql("alter table Settings modify `data` blob"))
        $Conf->update_schema_version(57);
    if ($Conf->sversion == 57
        && Dbl::ql("DROP TABLE IF EXISTS `Capability`")
        && Dbl::ql("CREATE TABLE `Capability` (
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
        && Dbl::ql("DROP TABLE IF EXISTS `CapabilityMap`")
        && Dbl::ql("CREATE TABLE `CapabilityMap` (
  `capabilityValue` varbinary(255) NOT NULL,
  `capabilityId` int(11) NOT NULL,
  `timeExpires` int(11) NOT NULL,
  PRIMARY KEY (`capabilityValue`),
  UNIQUE KEY `capabilityValue` (`capabilityValue`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8"))
        $Conf->update_schema_version(58);
    if ($Conf->sversion == 58
        && Dbl::ql("alter table PaperReview modify `paperSummary` mediumtext DEFAULT NULL")
        && Dbl::ql("alter table PaperReview modify `commentsToAuthor` mediumtext DEFAULT NULL")
        && Dbl::ql("alter table PaperReview modify `commentsToPC` mediumtext DEFAULT NULL")
        && Dbl::ql("alter table PaperReview modify `commentsToAddress` mediumtext DEFAULT NULL")
        && Dbl::ql("alter table PaperReview modify `weaknessOfPaper` mediumtext DEFAULT NULL")
        && Dbl::ql("alter table PaperReview modify `strengthOfPaper` mediumtext DEFAULT NULL")
        && Dbl::ql("alter table PaperReview modify `textField7` mediumtext DEFAULT NULL")
        && Dbl::ql("alter table PaperReview modify `textField8` mediumtext DEFAULT NULL")
        && Dbl::ql("alter table PaperReviewArchive modify `paperSummary` mediumtext DEFAULT NULL")
        && Dbl::ql("alter table PaperReviewArchive modify `commentsToAuthor` mediumtext DEFAULT NULL")
        && Dbl::ql("alter table PaperReviewArchive modify `commentsToPC` mediumtext DEFAULT NULL")
        && Dbl::ql("alter table PaperReviewArchive modify `commentsToAddress` mediumtext DEFAULT NULL")
        && Dbl::ql("alter table PaperReviewArchive modify `weaknessOfPaper` mediumtext DEFAULT NULL")
        && Dbl::ql("alter table PaperReviewArchive modify `strengthOfPaper` mediumtext DEFAULT NULL")
        && Dbl::ql("alter table PaperReviewArchive modify `textField7` mediumtext DEFAULT NULL")
        && Dbl::ql("alter table PaperReviewArchive modify `textField8` mediumtext DEFAULT NULL"))
        $Conf->update_schema_version(59);
    if ($Conf->sversion == 59
        && Dbl::ql("alter table ActionLog modify `action` varbinary(4096) NOT NULL")
        && Dbl::ql("alter table ContactInfo modify `note` varbinary(4096) DEFAULT NULL")
        && Dbl::ql("alter table ContactInfo modify `collaborators` varbinary(32767) DEFAULT NULL")
        && Dbl::ql("alter table ContactInfo modify `contactTags` varbinary(4096) DEFAULT NULL")
        && Dbl::ql("alter table Formula modify `headingTitle` varbinary(4096) NOT NULL")
        && Dbl::ql("alter table Formula modify `expression` varbinary(4096) NOT NULL")
        && Dbl::ql("alter table OptionType modify `description` varbinary(8192) DEFAULT NULL")
        && Dbl::ql("alter table OptionType modify `optionValues` varbinary(8192) NOT NULL")
        && Dbl::ql("alter table PaperReviewRefused modify `reason` varbinary(32767) DEFAULT NULL")
        && Dbl::ql("alter table ReviewFormField modify `description` varbinary(32767) DEFAULT NULL")
        && Dbl::ql("alter table ReviewFormOptions modify `description` varbinary(32767) DEFAULT NULL")
        && Dbl::ql("alter table ReviewRequest modify `reason` varbinary(32767) DEFAULT NULL")
        && Dbl::ql("alter table Settings modify `data` varbinary(32767) DEFAULT NULL")
        && Dbl::ql("alter table ContactAddress modify `addressLine1` varchar(2048) NOT NULL")
        && Dbl::ql("alter table ContactAddress modify `addressLine2` varchar(2048) NOT NULL")
        && Dbl::ql("alter table ContactAddress modify `city` varchar(2048) NOT NULL")
        && Dbl::ql("alter table ContactAddress modify `state` varchar(2048) NOT NULL")
        && Dbl::ql("alter table ContactAddress modify `zipCode` varchar(2048) NOT NULL")
        && Dbl::ql("alter table ContactAddress modify `country` varchar(2048) NOT NULL")
        && Dbl::ql("alter table PaperTopic modify `topicId` int(11) NOT NULL")
        && Dbl::ql("alter table PaperTopic modify `paperId` int(11) NOT NULL")
        && Dbl::ql("drop table if exists ChairTag"))
        $Conf->update_schema_version(60);
    if ($Conf->sversion == 60
        && Dbl::ql("insert into Settings (name,value,data) select concat('msg.',substr(name,1,length(name)-3)), value, data from Settings where name='homemsg' or name='conflictdefmsg'")
        && $Conf->update_schema_version(61)) {
        foreach (array("conflictdef", "home") as $k)
            if (isset($Conf->settingTexts["${k}msg"]))
                $Conf->settingTexts["msg.$k"] = $Conf->settingTexts["${k}msg"];
        $Conf->settings["allowPaperOption"] = 61;
    }
    if ($Conf->sversion == 61
        && Dbl::ql("alter table Capability modify `data` varbinary(4096) DEFAULT NULL"))
        $Conf->update_schema_version(62);
    if (!isset($Conf->settings["outcome_map"])) {
        $ojson = array();
        $result = Dbl::ql("select * from ReviewFormOptions where fieldName='outcome'");
        while (($row = edb_orow($result)))
            $ojson[$row->level] = $row->description;
        $Conf->save_setting("outcome_map", 1, $ojson);
    }
    if ($Conf->sversion == 62
        && isset($Conf->settings["outcome_map"]))
        $Conf->update_schema_version(63);
    if (!isset($Conf->settings["review_form"])
        && $Conf->sversion < 65)
        update_schema_create_review_form($Conf);
    if ($Conf->sversion == 63
        && isset($Conf->settings["review_form"]))
        $Conf->update_schema_version(64);
    if ($Conf->sversion == 64
        && Dbl::ql("drop table if exists `ReviewFormField`")
        && Dbl::ql("drop table if exists `ReviewFormOptions`"))
        $Conf->update_schema_version(65);
    if (!isset($Conf->settings["options"])
        && $Conf->sversion < 67)
        update_schema_create_options($Conf);
    if ($Conf->sversion == 65
        && isset($Conf->settings["options"]))
        $Conf->update_schema_version(66);
    if ($Conf->sversion == 66
        && Dbl::ql("drop table if exists `OptionType`"))
        $Conf->update_schema_version(67);
    if ($Conf->sversion == 67
        && Dbl::ql("alter table PaperComment modify `comment` varbinary(32767) DEFAULT NULL")
        && Dbl::ql("alter table PaperComment add `commentTags` varbinary(1024) DEFAULT NULL"))
        $Conf->update_schema_version(68);
    if ($Conf->sversion == 68
        && Dbl::ql("alter table PaperReviewPreference add `expertise` int(4) DEFAULT NULL"))
        $Conf->update_schema_version(69);
    if ($Conf->sversion == 69
        && Dbl::ql("alter table Paper drop column `pcPaper`"))
        $Conf->update_schema_version(70);
    if ($Conf->sversion == 70
        && Dbl::ql("alter table ContactInfo modify `voicePhoneNumber` varbinary(256) DEFAULT NULL")
        && Dbl::ql("alter table ContactInfo modify `faxPhoneNumber` varbinary(256) DEFAULT NULL")
        && Dbl::ql("alter table ContactInfo modify `collaborators` varbinary(8192) DEFAULT NULL")
        && Dbl::ql("alter table ContactInfo drop column `note`")
        && Dbl::ql("alter table ContactInfo add `data` varbinary(32767) DEFAULT NULL"))
        $Conf->update_schema_version(71);
    if ($Conf->sversion == 71
        && Dbl::ql("alter table Settings modify `name` varbinary(256) DEFAULT NULL")
        && Dbl::ql("update Settings set name=rtrim(name)"))
        $Conf->update_schema_version(72);
    if ($Conf->sversion == 72
        && Dbl::ql("update TopicInterest set interest=-2 where interest=0")
        && Dbl::ql("update TopicInterest set interest=4 where interest=2")
        && Dbl::ql("delete from TopicInterest where interest=1"))
        $Conf->update_schema_version(73);
    if ($Conf->sversion == 73
        && Dbl::ql("alter table PaperStorage add `size` bigint(11) DEFAULT NULL")
        && Dbl::ql("update PaperStorage set `size`=length(paper) where paper is not null"))
        $Conf->update_schema_version(74);
    if ($Conf->sversion == 74
        && Dbl::ql("alter table ContactInfo drop column `visits`"))
        $Conf->update_schema_version(75);
    if ($Conf->sversion == 75) {
        foreach (array("capability_gc", "s3_scope", "s3_signing_key") as $k)
            if (isset($Conf->settings[$k])) {
                $Conf->save_setting("__" . $k, $Conf->settings[$k], @$Conf->settingTexts[$k]);
                $Conf->save_setting($k, null);
            }
        $Conf->update_schema_version(76);
    }
    if ($Conf->sversion == 76
        && Dbl::ql("update PaperReviewPreference set expertise=-expertise"))
        $Conf->update_schema_version(77);
    if ($Conf->sversion == 77
        && Dbl::ql("alter table MailLog add `q` varchar(4096)"))
        $Conf->update_schema_version(78);
    if ($Conf->sversion == 78
        && Dbl::ql("alter table MailLog add `t` varchar(200)"))
        $Conf->update_schema_version(79);
    if ($Conf->sversion == 79
        && Dbl::ql("alter table ContactInfo add `passwordTime` int(11) NOT NULL DEFAULT '0'"))
        $Conf->update_schema_version(80);
    if ($Conf->sversion == 80
        && Dbl::ql("alter table PaperReview modify `reviewRound` int(11) NOT NULL DEFAULT '0'")
        && Dbl::ql("alter table PaperReviewArchive modify `reviewRound` int(11) NOT NULL DEFAULT '0'"))
        $Conf->update_schema_version(81);
    if ($Conf->sversion == 81
        && Dbl::ql("alter table PaperStorage add `filterType` int(3) DEFAULT NULL")
        && Dbl::ql("alter table PaperStorage add `originalStorageId` int(11) DEFAULT NULL"))
        $Conf->update_schema_version(82);
    if ($Conf->sversion == 82
        && Dbl::ql("update Settings set name='msg.resp_instrux' where name='msg.responseinstructions'"))
        $Conf->update_schema_version(83);
    if ($Conf->sversion == 83
        && Dbl::ql("alter table PaperComment add `commentRound` int(11) NOT NULL DEFAULT '0'"))
        $Conf->update_schema_version(84);
    if ($Conf->sversion == 84
        && Dbl::ql("insert ignore into Settings (name, value) select 'resp_active', value from Settings where name='resp_open'"))
        $Conf->update_schema_version(85);
    if ($Conf->sversion == 85
        && Dbl::ql("DROP TABLE IF EXISTS `PCMember`")
        && Dbl::ql("DROP TABLE IF EXISTS `ChairAssistant`")
        && Dbl::ql("DROP TABLE IF EXISTS `Chair`"))
        $Conf->update_schema_version(86);
    if ($Conf->sversion == 86
        && update_schema_transfer_address($Conf))
        $Conf->update_schema_version(87);
    if ($Conf->sversion == 87
        && Dbl::ql("DROP TABLE IF EXISTS `ContactAddress`"))
        $Conf->update_schema_version(88);
    if ($Conf->sversion == 88
        && Dbl::ql("alter table ContactInfo drop key `name`")
        && Dbl::ql("alter table ContactInfo drop key `affiliation`")
        && Dbl::ql("alter table ContactInfo drop key `email_3`")
        && Dbl::ql("alter table ContactInfo drop key `firstName_2`")
        && Dbl::ql("alter table ContactInfo drop key `lastName`"))
        $Conf->update_schema_version(89);
    if ($Conf->sversion == 89
        && update_schema_unaccented_name($Conf))
        $Conf->update_schema_version(90);
    if ($Conf->sversion == 90
        && Dbl::ql("alter table PaperReview add `reviewAuthorSeen` int(11) DEFAULT NULL"))
        $Conf->update_schema_version(91);
    if ($Conf->sversion == 91
        && Dbl::ql("alter table PaperReviewArchive add `reviewAuthorSeen` int(11) DEFAULT NULL"))
        $Conf->update_schema_version(92);
    if ($Conf->sversion == 92
        && Dbl::ql("alter table Paper drop key `titleAbstractText`")
        && Dbl::ql("alter table Paper drop key `allText`")
        && Dbl::ql("alter table Paper drop key `authorText`")
        && Dbl::ql("alter table Paper modify `authorInformation` varbinary(8192) DEFAULT NULL")
        && Dbl::ql("alter table Paper modify `abstract` varbinary(16384) DEFAULT NULL")
        && Dbl::ql("alter table Paper modify `collaborators` varbinary(8192) DEFAULT NULL")
        && Dbl::ql("alter table Paper modify `withdrawReason` varbinary(1024) DEFAULT NULL"))
        $Conf->update_schema_version(93);
    if ($Conf->sversion == 93
        && Dbl::ql("alter table TopicArea modify `topicName` varchar(200) DEFAULT NULL"))
        $Conf->update_schema_version(94);
    if ($Conf->sversion == 94
        && Dbl::ql("alter table PaperOption modify `data` varbinary(32768) DEFAULT NULL")) {
        foreach (PaperOption::option_list($Conf) as $opt)
            if ($opt->type === "text")
                Dbl::ql("delete from PaperOption where optionId={$opt->id} and data=''");
        $Conf->update_schema_version(95);
    }
    if ($Conf->sversion == 95
        && Dbl::ql("alter table Capability add unique key `salt` (`salt`)")
        && Dbl::ql("update Capability join CapabilityMap using (capabilityId) set Capability.salt=CapabilityMap.capabilityValue")
        && Dbl::ql("drop table if exists `CapabilityMap`"))
        $Conf->update_schema_version(96);
    if ($Conf->sversion == 96
        && Dbl::ql("alter table ContactInfo add `passwordIsCdb` tinyint(1) NOT NULL DEFAULT '0'"))
        $Conf->update_schema_version(97);
    if ($Conf->sversion == 97
        && Dbl::ql("alter table PaperReview add `reviewWordCount` int(11) DEFAULT NULL")
        && Dbl::ql("alter table PaperReviewArchive add `reviewWordCount` int(11)  DEFAULT NULL")
        && Dbl::ql("alter table PaperReviewArchive drop key `reviewId`")
        && Dbl::ql("alter table PaperReviewArchive drop key `contactPaper`")
        && Dbl::ql("alter table PaperReviewArchive drop key `reviewSubmitted`")
        && Dbl::ql("alter table PaperReviewArchive drop key `reviewNeedsSubmit`")
        && Dbl::ql("alter table PaperReviewArchive drop key `reviewType`")
        && Dbl::ql("alter table PaperReviewArchive drop key `requestedBy`"))
        $Conf->update_schema_version(98);
    if ($Conf->sversion == 98) {
        update_schema_review_word_counts($Conf);
        $Conf->update_schema_version(99);
    }
    if ($Conf->sversion == 99
        && Dbl::ql("alter table ContactInfo ENGINE=InnoDB")
        && Dbl::ql("alter table Paper ENGINE=InnoDB")
        && Dbl::ql("alter table PaperComment ENGINE=InnoDB")
        && Dbl::ql("alter table PaperConflict ENGINE=InnoDB")
        && Dbl::ql("alter table PaperOption ENGINE=InnoDB")
        && Dbl::ql("alter table PaperReview ENGINE=InnoDB")
        && Dbl::ql("alter table PaperStorage ENGINE=InnoDB")
        && Dbl::ql("alter table PaperTag ENGINE=InnoDB")
        && Dbl::ql("alter table PaperTopic ENGINE=InnoDB")
        && Dbl::ql("alter table Settings ENGINE=InnoDB"))
        $Conf->update_schema_version(100);
    if ($Conf->sversion == 100
        && Dbl::ql("alter table ActionLog ENGINE=InnoDB")
        && Dbl::ql("alter table Capability ENGINE=InnoDB")
        && Dbl::ql("alter table Formula ENGINE=InnoDB")
        && Dbl::ql("alter table MailLog ENGINE=InnoDB")
        && Dbl::ql("alter table PaperReviewArchive ENGINE=InnoDB")
        && Dbl::ql("alter table PaperReviewPreference ENGINE=InnoDB")
        && Dbl::ql("alter table PaperReviewRefused ENGINE=InnoDB")
        && Dbl::ql("alter table PaperWatch ENGINE=InnoDB")
        && Dbl::ql("alter table ReviewRating ENGINE=InnoDB")
        && Dbl::ql("alter table ReviewRequest ENGINE=InnoDB")
        && Dbl::ql("alter table TopicArea ENGINE=InnoDB")
        && Dbl::ql("alter table TopicInterest ENGINE=InnoDB"))
        $Conf->update_schema_version(101);
    if ($Conf->sversion == 101
        && Dbl::ql("alter table ActionLog modify `ipaddr` varbinary(32) DEFAULT NULL")
        && Dbl::ql("alter table MailLog modify `recipients` varbinary(200) NOT NULL")
        && Dbl::ql("alter table MailLog modify `q` varbinary(4096) DEFAULT NULL")
        && Dbl::ql("alter table MailLog modify `t` varbinary(200) DEFAULT NULL")
        && Dbl::ql("alter table Paper modify `mimetype` varbinary(80) NOT NULL DEFAULT ''")
        && Dbl::ql("alter table PaperStorage modify `mimetype` varbinary(80) NOT NULL DEFAULT ''")
        && Dbl::ql("alter table PaperStorage modify `filename` varbinary(255) DEFAULT NULL")
        && Dbl::ql("alter table PaperStorage modify `infoJson` varbinary(8192) DEFAULT NULL"))
        $Conf->update_schema_version(102);
    if ($Conf->sversion == 102
        && Dbl::ql("alter table PaperReview modify `paperSummary` mediumblob")
        && Dbl::ql("alter table PaperReview modify `commentsToAuthor` mediumblob")
        && Dbl::ql("alter table PaperReview modify `commentsToPC` mediumblob")
        && Dbl::ql("alter table PaperReview modify `commentsToAddress` mediumblob")
        && Dbl::ql("alter table PaperReview modify `weaknessOfPaper` mediumblob")
        && Dbl::ql("alter table PaperReview modify `strengthOfPaper` mediumblob")
        && Dbl::ql("alter table PaperReview modify `textField7` mediumblob")
        && Dbl::ql("alter table PaperReview modify `textField8` mediumblob")
        && Dbl::ql("alter table PaperReviewArchive modify `paperSummary` mediumblob")
        && Dbl::ql("alter table PaperReviewArchive modify `commentsToAuthor` mediumblob")
        && Dbl::ql("alter table PaperReviewArchive modify `commentsToPC` mediumblob")
        && Dbl::ql("alter table PaperReviewArchive modify `commentsToAddress` mediumblob")
        && Dbl::ql("alter table PaperReviewArchive modify `weaknessOfPaper` mediumblob")
        && Dbl::ql("alter table PaperReviewArchive modify `strengthOfPaper` mediumblob")
        && Dbl::ql("alter table PaperReviewArchive modify `textField7` mediumblob")
        && Dbl::ql("alter table PaperReviewArchive modify `textField8` mediumblob"))
        $Conf->update_schema_version(103);
    if ($Conf->sversion == 103
        && Dbl::ql("alter table Paper modify `title` varbinary(256) DEFAULT NULL")
        && Dbl::ql("alter table Paper drop key `title`"))
        $Conf->update_schema_version(104);
    if ($Conf->sversion == 104
        && Dbl::ql("alter table PaperReview add `reviewFormat` tinyint(1) DEFAULT NULL")
        && Dbl::ql("alter table PaperReviewArchive add `reviewFormat` tinyint(1) DEFAULT NULL"))
        $Conf->update_schema_version(105);
    if ($Conf->sversion == 105
        && Dbl::ql("alter table PaperComment add `commentFormat` tinyint(1) DEFAULT NULL"))
        $Conf->update_schema_version(106);
    if ($Conf->sversion == 106
        && Dbl::ql("alter table PaperComment add `authorOrdinal` int(11) NOT NULL default '0'")
        && Dbl::ql("update PaperComment set authorOrdinal=ordinal where commentType>=" . COMMENTTYPE_AUTHOR))
        $Conf->update_schema_version(107);

    // repair missing comment ordinals; reset incorrect `ordinal`s for
    // author-visible comments
    if ($Conf->sversion == 107) {
        $result = Dbl::ql("select paperId, commentId from PaperComment where ordinal=0 and (commentType&" . (COMMENTTYPE_RESPONSE | COMMENTTYPE_DRAFT) . ")=0 and commentType>=" . COMMENTTYPE_PCONLY . " and commentType<" . COMMENTTYPE_AUTHOR . " order by commentId");
        while (($row = edb_row($result))) {
            Dbl::ql("update PaperComment,
(select coalesce(count(commentId),0) commentCount from Paper
    left join PaperComment on (PaperComment.paperId=Paper.paperId and (commentType&" . (COMMENTTYPE_RESPONSE | COMMENTTYPE_DRAFT) . ")=0 and commentType>=" . COMMENTTYPE_PCONLY . " and commentType<" . COMMENTTYPE_AUTHOR . " and commentId<$row[1])
    where Paper.paperId=$row[0] group by Paper.paperId) t
set ordinal=(t.commentCount+1) where commentId=$row[1]");
        }

        $result = Dbl::ql("select paperId, commentId from PaperComment where ordinal=0 and (commentType&" . (COMMENTTYPE_RESPONSE | COMMENTTYPE_DRAFT) . ")=0 and commentType>=" . COMMENTTYPE_AUTHOR . " order by commentId");
        while (($row = edb_row($result))) {
            Dbl::ql("update PaperComment,
(select coalesce(count(commentId),0) commentCount from Paper
    left join PaperComment on (PaperComment.paperId=Paper.paperId and (commentType&" . (COMMENTTYPE_RESPONSE | COMMENTTYPE_DRAFT) . ")=0 and commentType>=" . COMMENTTYPE_AUTHOR . " and commentId<$row[1])
    where Paper.paperId=$row[0] group by Paper.paperId) t
set authorOrdinal=(t.commentCount+1) where commentId=$row[1]");
        }

        $result = Dbl::ql("select paperId, commentId from PaperComment where ordinal=authorOrdinal and (commentType&" . (COMMENTTYPE_RESPONSE | COMMENTTYPE_DRAFT) . ")=0 and commentType>=" . COMMENTTYPE_AUTHOR . " order by commentId");
        while (($row = edb_row($result))) {
            Dbl::ql("update PaperComment,
(select coalesce(max(ordinal),0) maxOrdinal from Paper
    left join PaperComment on (PaperComment.paperId=Paper.paperId and (commentType&" . (COMMENTTYPE_RESPONSE | COMMENTTYPE_DRAFT) . ")=0 and commentType>=" . COMMENTTYPE_PCONLY . " and commentType<" . COMMENTTYPE_AUTHOR . " and commentId<$row[1])
    where Paper.paperId=$row[0] group by Paper.paperId) t
set ordinal=(t.maxOrdinal+1) where commentId=$row[1]");
        }

        $Conf->update_schema_version(108);
    }

    // contact tags format change
    if ($Conf->sversion == 108
        && Dbl::ql("update ContactInfo set contactTags=substr(replace(contactTags, ' ', '#0 ') from 3)")
        && Dbl::ql("update ContactInfo set contactTags=replace(contactTags, '#0#0 ', '#0 ')"))
        $Conf->update_schema_version(109);
    if ($Conf->sversion == 109
        && Dbl::ql("alter table PaperTag modify `tagIndex` float NOT NULL DEFAULT '0'"))
        $Conf->update_schema_version(110);
    if ($Conf->sversion == 110
        && Dbl::ql("alter table ContactInfo drop `faxPhoneNumber`")
        && Dbl::ql("alter table ContactInfo add `country` varbinary(256) default null")
        && update_schema_transfer_country($Conf))
        $Conf->update_schema_version(111);
    if ($Conf->sversion == 111) {
        update_schema_review_word_counts($Conf);
        $Conf->update_schema_version(112);
    }
    if ($Conf->sversion == 112
        && Dbl::ql("alter table ContactInfo add `passwordUseTime` int(11) NOT NULL DEFAULT '0'")
        && Dbl::ql("alter table ContactInfo add `updateTime` int(11) NOT NULL DEFAULT '0'")
        && Dbl::ql("update ContactInfo set passwordUseTime=lastLogin where passwordUseTime=0"))
        $Conf->update_schema_version(113);
    if ($Conf->sversion == 113
        && Dbl::ql("drop table if exists `PaperReviewArchive`"))
        $Conf->update_schema_version(114);
    if ($Conf->sversion == 114
        && Dbl::ql("alter table PaperReview add `timeDisplayed` int(11) NOT NULL DEFAULT '0'")
        && Dbl::ql("alter table PaperComment add `timeDisplayed` int(11) NOT NULL DEFAULT '0'"))
        $Conf->update_schema_version(115);
    if ($Conf->sversion == 115
        && Dbl::ql("alter table Formula drop column `authorView`"))
        $Conf->update_schema_version(116);
    if ($Conf->sversion == 116
        && Dbl::ql("alter table PaperComment add `commentOverflow` longblob DEFAULT NULL"))
        $Conf->update_schema_version(117);
}
