<?php

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
		list($contact, $round) = split("~", $row[1]);
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

    if ($Conf->settings["allowPaperOption"] < 10)
	$Opt["disableSHA1"] = 1;
}
