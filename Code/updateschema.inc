<?php
// updateschema.inc -- HotCRP function for updating old schemata
// HotCRP is Copyright (c) 2006-2012 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

function updateSchema($Conf) {
    global $Opt, $OK;
    error_log("Note: updating schema from version " . $Conf->settings["allowPaperOption"]);

    if ($Conf->settings["allowPaperOption"] == 6
	&& $Conf->q("alter table ReviewRequest add `reason` text")
	&& $Conf->q("update Settings set value=7 where name='allowPaperOption'"))
	$Conf->settings["allowPaperOption"] = 7;
    if ($Conf->settings["allowPaperOption"] == 7
	&& $Conf->q("alter table PaperReview add `textField7` mediumtext NOT NULL")
	&& $Conf->q("alter table PaperReview add `textField8` mediumtext NOT NULL")
	&& $Conf->q("insert into ReviewFormField set fieldName='textField7', shortName='Additional text field'")
	&& $Conf->q("insert into ReviewFormField set fieldName='textField8', shortName='Additional text field'")
	&& $Conf->q("update Settings set value=8 where name='allowPaperOption'"))
	$Conf->settings["allowPaperOption"] = 8;
    if ($Conf->settings["allowPaperOption"] == 8
	&& $Conf->q("alter table ReviewFormField add `levelChar` tinyint(1) NOT NULL default '0'")
	&& $Conf->q("alter table PaperReviewArchive add `textField7` mediumtext NOT NULL")
	&& $Conf->q("alter table PaperReviewArchive add `textField8` mediumtext NOT NULL")
	&& $Conf->q("update Settings set value=9 where name='allowPaperOption'"))
	$Conf->settings["allowPaperOption"] = 9;
    if ($Conf->settings["allowPaperOption"] == 9
	&& $Conf->q("alter table Paper add `sha1` varbinary(20) NOT NULL default ''")
	&& $Conf->q("update Settings set value=10 where name='allowPaperOption'"))
	$Conf->settings["allowPaperOption"] = 10;

    if ($Conf->settings["allowPaperOption"] == 10
	&& $Conf->q("alter table PaperReview add `reviewRound` tinyint(1) NOT NULL default '0'")
	&& $Conf->q("alter table PaperReviewArchive add `reviewRound` tinyint(1) NOT NULL default '0'")
	&& $Conf->q("alter table PaperReview add key `reviewRound` (`reviewRound`)")
	&& $Conf->q("update Settings set value=11 where name='allowPaperOption'")) {
	$Conf->settings["allowPaperOption"] = 11;
	if (count($Conf->settings["rounds"]) > 1) {
	    // update review rounds (XXX locking)
	    $result = $Conf->q("select paperId, tag from PaperTag where tag like '%~%'");
	    $rrs = array();
	    while (($row = edb_row($result))) {
		list($contact, $round) = explode("~", $row[1]);
		if (($round = array_search($round, $Conf->settings["rounds"]))) {
		    if (!isset($rrs[$round]))
			$rrs[$round] = array();
		    $rrs[$round][] = "(contactId=$contact and paperId=$row[0])";
		}
	    }
	    foreach ($rrs as $round => $pairs) {
		$q = "update PaperReview set reviewRound=$round where " . join(" or ", $pairs);
		$Conf->q($q);
	    }
	    $x = trim(preg_replace('/(\S+)\s*/', "tag like '%~\$1' or ", $Conf->settingText("tag_rounds")));
	    $Conf->q("delete from PaperTag where " . substr($x, 0, strlen($x) - 3));
	}
    }
    if ($Conf->settings["allowPaperOption"] == 11
	&& $Conf->q("create table `ReviewRating` (
  `reviewId` int(11) NOT NULL,
  `contactId` int(11) NOT NULL,
  `rating` tinyint(1) NOT NULL default '0',
  UNIQUE KEY `reviewContact` (`reviewId`,`contactId`),
  UNIQUE KEY `reviewContactRating` (`reviewId`,`contactId`,`rating`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8")
	&& $Conf->q("update Settings set value=12 where name='allowPaperOption'"))
	$Conf->settings["allowPaperOption"] = 12;
    if ($Conf->settings["allowPaperOption"] == 12
	&& $Conf->q("alter table PaperReview add `reviewToken` int(11) NOT NULL default '0'")
	&& $Conf->q("update Settings set value=13 where name='allowPaperOption'"))
	$Conf->settings["allowPaperOption"] = 13;
    if ($Conf->settings["allowPaperOption"] == 13
	&& $Conf->q("alter table OptionType add `optionValues` text NOT NULL default ''")
	&& $Conf->q("update Settings set value=14 where name='allowPaperOption'"))
	$Conf->settings["allowPaperOption"] = 14;
    if ($Conf->settings["allowPaperOption"] == 14
	&& $Conf->q("insert into Settings (name, value) select 'rev_tokens', count(reviewId) from PaperReview where reviewToken!=0 on duplicate key update value=values(value)")
	&& $Conf->q("update Settings set value=15 where name='allowPaperOption'"))
	$Conf->settings["allowPaperOption"] = 15;
    if ($Conf->settings["allowPaperOption"] == 15) {
	// It's OK if this fails!  Update 11 added reviewRound to
	// PaperReviewArchive (so old databases have the column), but I forgot
	// to upgrade schema.sql (so new databases lack the column).
	$Conf->q("alter table PaperReviewArchive add `reviewRound` tinyint(1) NOT NULL default '0'");
	$OK = true;
	if ($Conf->q("update Settings set value=16 where name='allowPaperOption'"))
	    $Conf->settings["allowPaperOption"] = 16;
    }
    if ($Conf->settings["allowPaperOption"] == 16
	&& $Conf->q("alter table PaperReview add `reviewEditVersion` int(1) NOT NULL default '0'")
	&& $Conf->q("update Settings set value=17 where name='allowPaperOption'"))
	$Conf->settings["allowPaperOption"] = 17;
    if ($Conf->settings["allowPaperOption"] == 17
	&& $Conf->q("alter table PaperReviewPreference add key `paperId` (`paperId`)")
	&& $Conf->q("create table PaperRank (
  `paperId` int(11) NOT NULL,
  `contactId` int(11) NOT NULL,
  `rank` int(11) NOT NULL,
  UNIQUE KEY `contactPaper` (`contactId`,`paperId`),
  KEY `paperId` (`paperId`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8;")
	&& $Conf->q("update Settings set value=18 where name='allowPaperOption'"))
	$Conf->settings["allowPaperOption"] = 18;
    if ($Conf->settings["allowPaperOption"] == 18
	&& $Conf->q("alter table PaperComment add `replyTo` int(11) NOT NULL")
	&& $Conf->q("update Settings set value=19 where name='allowPaperOption'"))
	$Conf->settings["allowPaperOption"] = 19;
    if ($Conf->settings["allowPaperOption"] == 19
	&& $Conf->q("drop table PaperRank")
	&& $Conf->q("update Settings set value=20 where name='allowPaperOption'"))
	$Conf->settings["allowPaperOption"] = 20;
    if ($Conf->settings["allowPaperOption"] == 20
	&& $Conf->q("alter table PaperComment add `timeNotified` int(11) NOT NULL default '0'")
	&& $Conf->q("update Settings set value=21 where name='allowPaperOption'"))
	$Conf->settings["allowPaperOption"] = 21;
    if ($Conf->settings["allowPaperOption"] == 21
	&& $Conf->q("update PaperConflict set conflictType=8 where conflictType=3")
	&& $Conf->q("update Settings set value=22 where name='allowPaperOption'"))
	$Conf->settings["allowPaperOption"] = 22;
    if ($Conf->settings["allowPaperOption"] == 22
	&& $Conf->q("insert into ChairAssistant (contactId) select contactId from Chair on duplicate key update ChairAssistant.contactId=ChairAssistant.contactId")
	&& $Conf->q("update ContactInfo set roles=roles+2 where roles=5")
	&& $Conf->q("update Settings set value=23 where name='allowPaperOption'"))
	$Conf->settings["allowPaperOption"] = 23;
    if ($Conf->settings["allowPaperOption"] == 23
	&& $Conf->q("update Settings set value=24 where name='allowPaperOption'"))
	$Conf->settings["allowPaperOption"] = 24;
    if ($Conf->settings["allowPaperOption"] == 24
	&& $Conf->q("alter table ContactInfo add `preferredEmail` varchar(120)")
	&& $Conf->q("update Settings set value=25 where name='allowPaperOption'"))
	$Conf->settings["allowPaperOption"] = 25;
    if ($Conf->settings["allowPaperOption"] == 25) {
	if ($Conf->settings["final_done"] > 0
	    && !isset($Conf->settings["final_soft"])
	    && $Conf->q("insert into Settings (name, value) values ('final_soft', " . $Conf->settings["final_done"] . ") on duplicate key update value=values(value)"))
	    $Conf->settings["final_soft"] = $Conf->settings["final_done"];
	if ($Conf->q("update Settings set value=26 where name='allowPaperOption'"))
	    $Conf->settings["allowPaperOption"] = 26;
    }
    if ($Conf->settings["allowPaperOption"] == 26
	&& $Conf->q("alter table PaperOption add `data` text")
	&& $Conf->q("alter table OptionType add `type` tinyint(1) NOT NULL default '0'")
	&& $Conf->q("update OptionType set type=2 where optionValues='\x7Fi'")
	&& $Conf->q("update OptionType set type=1 where type=0 and optionValues!=''")
	&& $Conf->q("update Settings set value=27 where name='allowPaperOption'"))
	$Conf->settings["allowPaperOption"] = 27;
    if ($Conf->settings["allowPaperOption"] == 27
	&& $Conf->q("alter table PaperStorage add `sha1` varbinary(20) NOT NULL default ''")
	&& $Conf->q("alter table PaperStorage add `documentType` int(3) NOT NULL default '0'")
	&& $Conf->q("update PaperStorage s, Paper p set s.sha1=p.sha1 where s.paperStorageId=p.paperStorageId and p.finalPaperStorageId=0 and s.paperStorageId>0")
	&& $Conf->q("update PaperStorage s, Paper p set s.sha1=p.sha1, s.documentType=" . DTYPE_FINAL . " where s.paperStorageId=p.finalPaperStorageId and s.paperStorageId>0")
	&& $Conf->q("update Settings set value=28 where name='allowPaperOption'"))
	$Conf->settings["allowPaperOption"] = 28;
    if ($Conf->settings["allowPaperOption"] == 28
	&& $Conf->q("alter table OptionType add `sortOrder` tinyint(1) NOT NULL default '0'")
	&& $Conf->q("update Settings set value=29 where name='allowPaperOption'"))
	$Conf->settings["allowPaperOption"] = 29;
    if ($Conf->settings["allowPaperOption"] == 29) {
	$pld = $Conf->settingText("pldisplay_default");
	$pld2 = updatePaperListDisplayOptions($pld);
	if ((!$pld || $pld == $pld2
	     || $Conf->q("update Settings set data='" . sqlq($pld2) . "' where name='pldisplay_default'"))
	    && $Conf->q("update Settings set value=30 where name='allowPaperOption'"))
	    $Conf->settings["allowPaperOption"] = 30;
    }
    if ($Conf->settings["allowPaperOption"] == 30
	&& $Conf->q("DROP TABLE IF EXISTS `Formula`")
	&& $Conf->q("CREATE TABLE `Formula` (
  `formulaId` int(11) NOT NULL auto_increment,
  `name` varchar(200) NOT NULL,
  `heading` varchar(200) NOT NULL default '',
  `headingTitle` text NOT NULL default '',
  `expression` text NOT NULL,
  `authorView` tinyint(1) NOT NULL default '1',
  PRIMARY KEY  (`formulaId`),
  UNIQUE KEY `formulaId` (`formulaId`),
  UNIQUE KEY `name` (`name`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8")
	&& $Conf->q("update Settings set value=31 where name='allowPaperOption'"))
	$Conf->settings["allowPaperOption"] = 31;
    if ($Conf->settings["allowPaperOption"] == 31
	&& $Conf->q("alter table Formula add `createdBy` int(11) NOT NULL default '0'")
	&& $Conf->q("alter table Formula add `timeModified` int(11) NOT NULL default '0'")
	&& $Conf->q("alter table Formula drop index `name`")
	&& $Conf->q("update Settings set value=32 where name='allowPaperOption'"))
	$Conf->settings["allowPaperOption"] = 32;
    if ($Conf->settings["allowPaperOption"] == 32
	&& $Conf->q("alter table PaperComment add key `timeModified` (`timeModified`)")
	&& $Conf->q("update Settings set value=33 where name='allowPaperOption'"))
	$Conf->settings["allowPaperOption"] = 33;
    if ($Conf->settings["allowPaperOption"] == 33
	&& $Conf->q("alter table PaperComment add `paperStorageId` int(11) NOT NULL default '0'")
	&& $Conf->q("update Settings set value=34 where name='allowPaperOption'"))
	$Conf->settings["allowPaperOption"] = 34;
    if ($Conf->settings["allowPaperOption"] == 34
	&& $Conf->q("alter table ContactInfo add `contactTags` text")
	&& $Conf->q("update Settings set value=35 where name='allowPaperOption'"))
	$Conf->settings["allowPaperOption"] = 35;
    if ($Conf->settings["allowPaperOption"] == 35
	&& $Conf->q("alter table ContactInfo modify `defaultWatch` int(11) NOT NULL default '2'")
	&& $Conf->q("alter table PaperWatch modify `watch` int(11) NOT NULL default '0'")
	&& $Conf->q("update Settings set value=36 where name='allowPaperOption'"))
	$Conf->settings["allowPaperOption"] = 36;
    if ($Conf->settings["allowPaperOption"] == 36
	&& $Conf->q("alter table PaperReview add `reviewNotified` int(1) default NULL")
	&& $Conf->q("alter table PaperReviewArchive add `reviewNotified` int(1) default NULL")
	&& $Conf->q("update Settings set value=37 where name='allowPaperOption'"))
	$Conf->settings["allowPaperOption"] = 37;
    if ($Conf->settings["allowPaperOption"] == 37
	&& $Conf->q("alter table OptionType add `displayType` tinyint(1) NOT NULL default '0'")
	&& $Conf->q("update Settings set value=38 where name='allowPaperOption'"))
	$Conf->settings["allowPaperOption"] = 38;
    if ($Conf->settings["allowPaperOption"] == 38
	&& $Conf->q("update PaperComment set forReviewers=1 where forReviewers=-1")
	&& $Conf->q("update Settings set value=39 where name='allowPaperOption'"))
	$Conf->settings["allowPaperOption"] = 39;
    if ($Conf->settings["allowPaperOption"] == 39
	&& $Conf->q("CREATE TABLE `MailLog` (
  `mailId` int(11) NOT NULL auto_increment,
  `recipients` varchar(200) NOT NULL,
  `paperIds` text,
  `cc` text,
  `replyto` text,
  `subject` text,
  `emailBody` text,
  PRIMARY KEY  (`mailId`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8")
	&& $Conf->q("update Settings set value=40 where name='allowPaperOption'"))
	$Conf->settings["allowPaperOption"] = 40;
    if ($Conf->settings["allowPaperOption"] == 40
	&& $Conf->q("alter table Paper add `capVersion` int(1) NOT NULL default '0'")
	&& $Conf->q("update Settings set value=41 where name='allowPaperOption'"))
	$Conf->settings["allowPaperOption"] = 41;
    if ($Conf->settings["allowPaperOption"] == 41
	&& $Conf->q("alter table Paper modify `mimetype` varchar(80) NOT NULL default ''")
	&& $Conf->q("alter table PaperStorage modify `mimetype` varchar(80) NOT NULL default ''")
	&& $Conf->q("update Settings set value=42 where name='allowPaperOption'"))
	$Conf->settings["allowPaperOption"] = 42;
    if ($Conf->settings["allowPaperOption"] == 42
	&& $Conf->q("alter table PaperComment add `ordinal` int(11) NOT NULL default '0'")
	&& $Conf->q("update Settings set value=43 where name='allowPaperOption'"))
	$Conf->settings["allowPaperOption"] = 43;
    if ($Conf->settings["allowPaperOption"] == 42
	&& ($result = $Conf->q("describe PaperComment `ordinal`"))
	&& ($o = edb_orow($result))
	&& substr($o->Type, 0, 3) == "int"
	&& (!$o->Null || $o->Null == "NO")
	&& (!$o->Default || $o->Default == "0")
	&& $Conf->q("update Settings set value=43 where name='allowPaperOption'"))
	$Conf->settings["allowPaperOption"] = 43;
    if ($Conf->settings["allowPaperOption"] == 43
	&& $Conf->q("alter table Paper add `withdrawReason` text")
	&& $Conf->q("update Settings set value=44 where name='allowPaperOption'"))
	$Conf->settings["allowPaperOption"] = 44;
    if ($Conf->settings["allowPaperOption"] == 44
	&& $Conf->q("alter table PaperStorage add `filename` varchar(255)")
	&& $Conf->q("update Settings set value=45 where name='allowPaperOption'"))
	$Conf->settings["allowPaperOption"] = 45;
    if ($Conf->settings["allowPaperOption"] == 45
	&& $Conf->q("alter table PaperReview add `timeRequested` int(11) NOT NULL DEFAULT '0'")
	&& $Conf->q("alter table PaperReview add `timeRequestNotified` int(11) NOT NULL DEFAULT '0'")
	&& $Conf->q("alter table PaperReviewArchive add `timeRequested` int(11) NOT NULL DEFAULT '0'")
	&& $Conf->q("alter table PaperReviewArchive add `timeRequestNotified` int(11) NOT NULL DEFAULT '0'")
	&& $Conf->q("alter table PaperReview drop column `requestedOn`")
	&& $Conf->q("alter table PaperReviewArchive drop column `requestedOn`")
	&& $Conf->q("update Settings set value=46 where name='allowPaperOption'"))
	$Conf->settings["allowPaperOption"] = 46;
    if ($Conf->settings["allowPaperOption"] == 46
	&& $Conf->q("alter table ContactInfo add `disabled` tinyint(1) NOT NULL DEFAULT '0'")
	&& $Conf->q("update Settings set value=47 where name='allowPaperOption'"))
	$Conf->settings["allowPaperOption"] = 47;
}
