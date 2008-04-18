<?php

function updateSchema($Conf) {
    global $Opt;
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

    if ($Conf->settings["allowPaperOption"] < 10)
	$Opt["disableSHA1"] = 1;
}
