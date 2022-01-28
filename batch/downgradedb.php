<?php
// Systematically downgrade a current HotCRP database
// into a version at schema version 11 (commit b0054f80185d624597d5bbbec0f2eafc73afe69b)

require_once(dirname(__DIR__) . "/src/init.php");

$arg = getopt("hn:", array("help", "name:"));
if (isset($arg["h"]) || isset($arg["help"])) {
    fwrite(STDOUT, "Usage: php batch/fakenames.php\n");
    exit(0);
}

$Conf->qe("alter table ActionLog change `ipaddr` `ipaddr` varchar(16) DEFAULT NULL");
$Conf->qe("alter table ActionLog change `action` `action` text NOT NULL");
$Conf->qe("alter table ActionLog add unique key `logId` (`logId`)");
$Conf->qe("alter table ActionLog ENGINE=MyISAM");

$Conf->qe("drop table if exists `Chair`");
$Conf->qe("create table `Chair` (`contactId` int(11) NOT NULL, UNIQUE KEY `contactId` (`contactId`) ) ENGINE=MyISAM DEFAULT CHARSET=utf8");
$Conf->qe("insert into Chair select contactId from ContactInfo where (roles&4)!=0");

$Conf->qe("drop table if exists `ChairAssistant`");
$Conf->qe("create table `ChairAssistant` (`contactId` int(11) NOT NULL, UNIQUE KEY `contactId` (`contactId`) ) ENGINE=MyISAM DEFAULT CHARSET=utf8");
$Conf->qe("insert into Chair select contactId from ContactInfo where (roles&2)!=0");

$Conf->qe("drop table if exists `ContactAddress`");
$Conf->qe("create table `ContactAddress` (
  `contactId` int(11) NOT NULL,
  `addressLine1` varchar(2048) NOT NULL,
  `addressLine2` varchar(2048) NOT NULL,
  `city` varchar(2048) NOT NULL,
  `state` varchar(2048) NOT NULL,
  `zipCode` varchar(2048) NOT NULL,
  `country` varchar(2048) NOT NULL,
  UNIQUE KEY `contactId` (`contactId`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8");

$result = $Conf->qe("select contactId, country, data from ContactInfo where country is not null or data is not null");
$qp = $qv = [];
while (($x = $result->fetch_object())) {
    $qp[] = "($x->contactId,?,?,?,?,?,?)";
    $data = $x->data ? json_decode((string) $x->data) : (object) [];
    $qv[] = isset($data->address) && isset($data->address[0]) ? $data->address[0] : "";
    $qv[] = isset($data->address) && isset($data->address[1]) ? $data->address[1] : "";
    $qv[] = isset($data->city) ? $data->city : "";
    $qv[] = isset($data->state) ? $data->state : "";
    $qv[] = isset($data->zip) ? $data->zip : "";
    $qv[] = isset($x->country) ? $x->country : "";
}
Dbl::free($result);

$Conf->qe_apply("insert into ContactAddress values " . join(",", $qp), $qv);


$Conf->qe("drop table if exists `OptionType`");
$Conf->qe("CREATE TABLE `OptionType` (
  `optionId` int(11) NOT NULL auto_increment,
  `optionName` varchar(200) NOT NULL,
  `description` text,
  `pcView` tinyint(1) NOT NULL default '1',
  PRIMARY KEY  (`optionId`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8");
$qp = $qv = [];
foreach ($Conf->options() as $opt) {
    if ($opt->type === "checkbox") {
        $qp[] = "(?,?,?,?)";
        $qv[] = $opt->id;
        $qv[] = $opt->name;
        $qv[] = $opt->description;
        $qv[] = $opt->visibility === "admin" ? 0 : 1;
    }
}
$Conf->qe_apply("insert into OptionType values " . join(",", $qp), $qv);


$Conf->qe("drop table if exists `PCMember`");
$Conf->qe("create table `PCMember` (`contactId` int(11) NOT NULL, UNIQUE KEY `contactId` (`contactId`) ) ENGINE=MyISAM DEFAULT CHARSET=utf8");
$Conf->qe("insert into PCMember select contactId from ContactInfo where (roles&1)!=0");


$Conf->qe("alter table PaperComment drop primary key");
$Conf->qe("alter table PaperComment add primary key (`commentId`)");
$Conf->qe("alter table PaperComment drop key `timeModifiedContact`");
$Conf->qe("alter table PaperComment add key `paperId` (`paperId`)");
$Conf->qe("alter table PaperComment add key `contactPaper` (`contactId`,`paperId`)");
$Conf->qe("alter table PaperComment drop column `timeNotified`");
$Conf->qe("alter table PaperComment drop column `timeDisplayed`");
$Conf->qe("alter table PaperComment change `comment` `comment` mediumtext");
$Conf->qe("alter table PaperComment add `forReviewers` tinyint(1) NOT NULL DEFAULT '0'");
$Conf->qe("alter table PaperComment add `forAuthors` tinyint(1) NOT NULL DEFAULT '0'");
$Conf->qe("alter table PaperComment add `blind` tinyint(1) NOT NULL DEFAULT '1'");
$Conf->qe("update PaperComment set forAuthors=1 where (commentType&" . CommentInfo::CT_VISIBILITY . ")=" . CommentInfo::CT_AUTHOR);
$Conf->qe("update PaperComment set forAuthors=2 where (commentType&" . CommentInfo::CT_VISIBILITY . ")=" . CommentInfo::CT_AUTHOR . " and (commentType&" . CommentInfo::CT_RESPONSE . ")!=0");
$Conf->qe("update PaperComment set blind=0 where (commentType&" . CommentInfo::CT_BLIND . ")=0");
$Conf->qe("update PaperComment set forReviewers=1 where (commentType&" . CommentInfo::CT_DRAFT . ")=0 and (commentType&" . CommentInfo::CT_VISIBILITY . ")>" . CommentInfo::CT_PCONLY);
$Conf->qe("alter table PaperComment drop column `commentType`");
$Conf->qe("alter table PaperComment drop column `replyTo`");
$Conf->qe("alter table PaperComment drop column `paperStorageId`");
$Conf->qe("alter table PaperComment drop column `ordinal`");
$Conf->qe("alter table PaperComment drop column `authorOrdinal`");
$Conf->qe("alter table PaperComment drop column `commentTags`");
$Conf->qe("alter table PaperComment drop column `commentRound`");
$Conf->qe("alter table PaperComment drop column `commentFormat`");
$Conf->qe("alter table PaperComment drop column `commentOverflow`");
$Conf->qe("alter table PaperComment ENGINE=MyISAM");


$Conf->qe("alter table PaperConflict add unique key `contactPaper` (`contactId`,`paperId`)");
$Conf->qe("alter table PaperConflict add unique key `contactPaperConflict` (`contactId`,`paperId`,`conflictType`)");
$Conf->qe("alter table PaperConflict drop primary key");
$Conf->qe("alter table PaperConflict ENGINE=MyISAM");


$Conf->qe("alter table PaperOption drop column `data`");
$Conf->qe("alter table PaperOption drop column `dataOverflow`");
$Conf->qe("alter table PaperOption add unique key `paperOption` (`paperId`,`optionId`)");
$Conf->qe("alter table PaperOption drop primary key");
$Conf->qe("alter table PaperOption ENGINE=MyISAM");


$Conf->qe("alter table PaperReview drop column `reviewToken`");
$Conf->qe("alter table PaperReview drop column `reviewAuthorModified`");
$Conf->qe("alter table PaperReview drop column `reviewNotified`");
$Conf->qe("alter table PaperReview drop column `reviewAuthorNotified`");
$Conf->qe("alter table PaperReview drop column `reviewAuthorSeen`");
$Conf->qe("alter table PaperReview drop column `timeDisplayed`");
$Conf->qe("alter table PaperReview drop column `timeApprovalRequested`");
$Conf->qe("alter table PaperReview drop column `timeRequestNotified`");
$Conf->qe("alter table PaperReview drop column `reviewEditVersion`");
$Conf->qe("alter table PaperReview change `reviewRound` `reviewRound` tinyint(1) NOT NULL DEFAULT '0'");
$Conf->qe("alter table PaperReview add `requestedOn` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP");
$Conf->qe("update PaperReview set requestedOn=from_unixtime(timeRequested)");
$Conf->qe("alter table PaperReview drop column `timeRequested`");
$Conf->qe("alter table PaperReview add `acceptedOn` timestamp NOT NULL DEFAULT '0'");
$Conf->qe("alter table PaperReview change `paperSummary` `paperSummary` mediumtext");
$Conf->qe("alter table PaperReview change `commentsToAuthor` `commentsToAuthor` mediumtext");
$Conf->qe("alter table PaperReview change `commentsToPC` `commentsToPC` mediumtext");
$Conf->qe("alter table PaperReview change `commentsToAddress` `commentsToAddress` mediumtext");
$Conf->qe("alter table PaperReview change `weaknessOfPaper` `weaknessOfPaper` mediumtext");
$Conf->qe("alter table PaperReview change `strengthOfPaper` `strengthOfPaper` mediumtext");
$Conf->qe("alter table PaperReview change `textField7` `textField7` mediumtext");
$Conf->qe("alter table PaperReview change `textField8` `textField8` mediumtext");
$Conf->qe("alter table PaperReview drop column `reviewWordCount`");
$Conf->qe("alter table PaperReview drop column `reviewFormat`");
$Conf->qe("alter table PaperReview drop primary key");
$Conf->qe("alter table PaperReview add primary key (`reviewId`)");
$Conf->qe("alter table PaperReview drop key `reviewSubmittedContact`");
$Conf->qe("alter table PaperReview add key `reviewSubmitted` (`reviewSubmitted`)");
$Conf->qe("alter table PaperReview ENGINE=MyISAM");


$Conf->qe("DROP TABLE IF EXISTS `PaperReviewArchive`");
$Conf->qe("CREATE TABLE `PaperReviewArchive` (
  `reviewArchiveId` int(11) NOT NULL AUTO_INCREMENT,
  `reviewId` int(11) NOT NULL,
  `paperId` int(11) NOT NULL,
  `contactId` int(11) NOT NULL,
  `reviewType` tinyint(1) NOT NULL DEFAULT '0',
  `requestedBy` int(11) NOT NULL DEFAULT '0',
  `requestedOn` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `acceptedOn` timestamp NOT NULL DEFAULT 0,
  `reviewBlind` tinyint(1) NOT NULL DEFAULT '1',
  `reviewModified` int(1),
  `reviewSubmitted` int(1),
  `reviewOrdinal` int(1),
  `reviewNeedsSubmit` tinyint(1) NOT NULL DEFAULT '1',
  `overAllMerit` tinyint(1) NOT NULL DEFAULT '0',
  `reviewerQualification` tinyint(1) NOT NULL DEFAULT '0',
  `novelty` tinyint(1) NOT NULL DEFAULT '0',
  `technicalMerit` tinyint(1) NOT NULL DEFAULT '0',
  `interestToCommunity` tinyint(1) NOT NULL DEFAULT '0',
  `longevity` tinyint(1) NOT NULL DEFAULT '0',
  `grammar` tinyint(1) NOT NULL DEFAULT '0',
  `likelyPresentation` tinyint(1) NOT NULL DEFAULT '0',
  `suitableForShort` tinyint(1) NOT NULL DEFAULT '0',
  `paperSummary` mediumtext NOT NULL,
  `commentsToAuthor` mediumtext NOT NULL,
  `commentsToPC` mediumtext NOT NULL,
  `commentsToAddress` mediumtext NOT NULL,
  `weaknessOfPaper` mediumtext NOT NULL,
  `strengthOfPaper` mediumtext NOT NULL,
  `potential` tinyint(4) NOT NULL DEFAULT '0',
  `fixability` tinyint(4) NOT NULL DEFAULT '0',
  `textField7` mediumtext NOT NULL,
  `textField8` mediumtext NOT NULL,
  PRIMARY KEY  (`reviewArchiveId`),
  UNIQUE KEY `reviewArchiveId` (`reviewArchiveId`),
  KEY `reviewId` (`reviewId`),
  KEY `contactPaper` (`contactId`,`paperId`),
  KEY `paperId` (`paperId`),
  KEY `reviewSubmitted` (`reviewSubmitted`),
  KEY `reviewNeedsSubmit` (`reviewNeedsSubmit`),
  KEY `reviewType` (`reviewType`),
  KEY `requestedBy` (`requestedBy`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8");


$Conf->qe("alter table PaperReviewPreference drop column `expertise`");
$Conf->qe("alter table PaperReviewPreference drop primary key");
$Conf->qe("alter table PaperReviewPreference ENGINE=MyISAM");


$Conf->qe("alter table PaperReviewRefused change `reason` `reason` text");
$Conf->qe("alter table PaperReviewRefused ENGINE=MyISAM");


$Conf->qe("alter table PaperStorage change `mimetype` `mimetype` varchar(40) NOT NULL DEFAULT ''");
$Conf->qe("alter table PaperStorage drop column `mimetypeid`");
$Conf->qe("alter table PaperStorage drop column `sha1`");
$Conf->qe("alter table PaperStorage drop column `documentType`");
$Conf->qe("alter table PaperStorage drop column `filename`");
$Conf->qe("alter table PaperStorage drop column `infoJson`");
$Conf->qe("alter table PaperStorage drop column `size`");
$Conf->qe("alter table PaperStorage drop column `filterType`");
$Conf->qe("alter table PaperStorage drop column `originalStorageId`");
$Conf->qe("alter table PaperStorage drop primary key");
$Conf->qe("alter table PaperStorage add primary key (`paperStorageId`)");
$Conf->qe("alter table PaperStorage add key `paperId` (`paperId`)");
$Conf->qe("alter table PaperStorage add key `mimetype` (`mimetype`)");
$Conf->qe("alter table PaperStorage drop key `byPaper`");
$Conf->qe("alter table PaperStorage ENGINE=MyISAM");


$Conf->qe("alter table PaperTag change `tagIndex` `tagIndex` int(11) NOT NULL DEFAULT '0'");
$Conf->qe("alter table PaperTag drop primary key");
$Conf->qe("alter table PaperTag add unique key `paperTag` (`paperId`,`tag`)");
$Conf->qe("alter table PaperTag ENGINE=MyISAM");


$Conf->qe("alter table PaperTopic drop primary key");
$Conf->qe("alter table PaperTopic add unique key `paperTopic` (`paperId`,`topicId`)");
$Conf->qe("alter table PaperTopic ENGINE=MyISAM");


$Conf->qe("alter table PaperWatch change `watch` `watch` tinyint(1) NOT NULL DEFAULT '0'");
$Conf->qe("alter table PaperWatch drop primary key");
$Conf->qe("alter table PaperWatch add unique key `contactPaper` (`contactId`,`paperId`)");
$Conf->qe("alter table PaperWatch add unique key `contactPaperWatch` (`contactId`,`paperId`,`watch`)");
$Conf->qe("alter table PaperWatch ENGINE=MyISAM");



$Conf->qe("DROP TABLE IF EXISTS `ReviewFormField`");
$Conf->qe("CREATE TABLE `ReviewFormField` (
  `fieldName` varchar(25) NOT NULL,
  `shortName` varchar(40) NOT NULL,
  `description` text,
  `sortOrder` tinyint(1) NOT NULL DEFAULT '-1',
  `rows` tinyint(1) NOT NULL DEFAULT '0',
  `authorView` tinyint(1) NOT NULL DEFAULT '1',
  `levelChar` tinyint(1) NOT NULL DEFAULT '0',
  PRIMARY KEY  (`fieldName`),
  UNIQUE KEY `fieldName` (`fieldName`),
  KEY `shortName` (`shortName`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8");

$Conf->qe("DROP TABLE IF EXISTS `ReviewFormOptions`");
$Conf->qe("CREATE TABLE `ReviewFormOptions` (
  `fieldName` varchar(25) NOT NULL,
  `level` tinyint(1) NOT NULL,
  `description` text,
  KEY `fieldName` (`fieldName`),
  KEY `level` (`level`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8");

$got_fields = [];
foreach ($Conf->review_form()->all_fields() as $f) {
    $Conf->qe("insert into ReviewFormField values (?,?,?,?,?,?,?)",
              $f->id, $f->name, $f->description, $f->order,
              (int) $f->display_space,
              $f->view_score >= VIEWSCORE_AUTHORDEC ? 1 : 0,
              $f->option_letter ? : 0);
    if ($f->has_options) {
        foreach ($f->options as $i => $x)
            $Conf->qe("insert into ReviewFormOptions values (?,?,?)",
                      $f->id, $i, $x);
    }
}
foreach ($Conf->decision_map() as $d => $x)
    $Conf->qe("insert into ReviewFormOptions values (?,?,?)", "outcome", $d, $x);



$Conf->qe("alter table ReviewRequest change `reason` `reason` text");
$Conf->qe("alter table ReviewRequest drop column `reviewRound`");
$Conf->qe("alter table ReviewRequest ENGINE=MyISAM");


$Conf->qe("alter table Settings change `name` `name` char(40) NOT NULL");
$Conf->qe("alter table Settings change `data` `data` blob"); /* XXX was `text` */
$Conf->qe("alter table Settings ENGINE=MyISAM");


$Conf->qe("alter table TopicArea change `topicName` `topicName` varchar(80) DEFAULT NULL");
$Conf->qe("alter table TopicArea add unique key `topicId` (`topicId`)");
$Conf->qe("alter table TopicArea ENGINE=MyISAM");


$Conf->qe("alter table TopicInterest change `interest` `interest` int(1) DEFAULT NULL");
$Conf->qe("alter table TopicInterest drop primary key");
$Conf->qe("alter table TopicInterest add unique key `contactTopic` (`contactId`,`topicId`)");
$Conf->qe("alter table TopicInterest ENGINE=MyISAM");


// The big ones.

// ContactInfo
$Conf->qe("alter table ContactInfo add `visits` int(11) NOT NULL DEFAULT '0'");
$Conf->qe("alter table ContactInfo drop column `unaccentedName`");
$Conf->qe("alter table ContactInfo drop column `preferredEmail`");
$Conf->qe("alter table ContactInfo drop column `passwordTime`");
$Conf->qe("alter table ContactInfo drop column `passwordUseTime`");
$Conf->qe("alter table ContactInfo drop column `disabled`");
$Conf->qe("alter table ContactInfo drop column `contactTags`");
$Conf->qe("alter table ContactInfo drop column `data`");
$Conf->qe("alter table ContactInfo change `defaultWatch` `defaultWatch` tinyint(1) NOT NULL DEFAULT '2'");
$Conf->qe("alter table ContactInfo change `collaborators` `collaborators` mediumtext");
$Conf->qe("alter table ContactInfo change `voicePhoneNumber` `voicePhoneNumber` varchar(2048) NOT NULL DEFAULT ''");
$Conf->qe("alter table ContactInfo drop column `country`");
$Conf->qe("alter table ContactInfo add `faxPhoneNumber` varchar(2048) NOT NULL DEFAULT ''");
// XXX do not change `password` to varchar(); maybe it has binary values
$Conf->qe("alter table ContactInfo add `note` mediumtext");
$Conf->qe("alter table ContactInfo drop column `updateTime`");
$Conf->qe("alter table ContactInfo drop key `rolesContactId`");
$Conf->qe("alter table ContactInfo add unique key `contactId` (`contactId`)");
$Conf->qe("alter table ContactInfo add unique key `contactIdRoles` (`contactId`,`roles`)");
$Conf->qe("alter table ContactInfo ENGINE=MyISAM");
$Conf->qe("alter table ContactInfo add FULLTEXT KEY `name` (`lastName`,`firstName`,`email`)");
$Conf->qe("alter table ContactInfo add FULLTEXT KEY `affiliation` (`affiliation`)");
$Conf->qe("alter table ContactInfo add FULLTEXT KEY `email_3` (`email`)");
$Conf->qe("alter table ContactInfo add FULLTEXT KEY `firstName_2` (`firstName`)");
$Conf->qe("alter table ContactInfo add FULLTEXT KEY `lastName` (`lastName`)");

// Paper
$Conf->qe("alter table Paper change `title` `title` varchar(200) DEFAULT NULL");
$Conf->qe("alter table Paper change `authorInformation` `authorInformation` text");
$Conf->qe("alter table Paper change `abstract` `abstract` text");
$Conf->qe("alter table Paper change `collaborators` `collaborators` text");
$Conf->qe("alter table Paper drop column `timeModified`");
$Conf->qe("alter table Paper add `pcPaper` tinyint(1) NOT NULL DEFAULT '0'");
// XXX keep long sha1 column
$Conf->qe("alter table Paper drop column `managerContactId`");
$Conf->qe("alter table Paper drop column `capVersion`");
$Conf->qe("alter table Paper change `mimetype` `mimetype` varchar(40) NOT NULL DEFAULT ''");
$Conf->qe("alter table Paper drop column `pdfFormatStatus`");
$Conf->qe("alter table Paper drop column `withdrawReason`");
$Conf->qe("alter table Paper drop column `paperFormat`");
$Conf->qe("alter table Paper add `numComments` int(11) NOT NULL DEFAULT '0'");
$Conf->qe("alter table Paper add `numAuthorComments` int(11) NOT NULL DEFAULT '0'");
$Conf->qe("alter table Paper add unique key `paperId` (`paperId`)");
$Conf->qe("alter table Paper add key `title` (`title`)");
$Conf->qe("alter table Paper ENGINE=MyISAM");
$Conf->qe("alter table Paper add fulltext key `titleAbstractText` (`title`,`abstract`)");
$Conf->qe("alter table Paper add fulltext key `allText` (`title`,`abstract`,`authorInformation`,`collaborators`)");
$Conf->qe("alter table Paper add fulltext key `authorText` (`authorInformation`,`collaborators`)");
$Conf->qe("update Paper set numComments=(select count(commentId) from PaperComment where paperId=Paper.paperId), numAuthorComments=(select count(commentId) from PaperComment where paperId=Paper.paperId and forAuthors>0)");


$Conf->qe("drop table if exists `Capability`");
$Conf->qe("drop table if exists `FilteredDocument`");
$Conf->qe("drop table if exists `Formula`");
$Conf->qe("drop table if exists `MailLog`");
$Conf->qe("drop table if exists `Mimetype`");
$Conf->qe("drop table if exists `PaperTagAnno`");
$Conf->qe("drop table if exists `ReviewRating`");


$Conf->qe("update Settings set value=11 where name='allowPaperOption'");
$Conf->qe("delete from Settings where name='options' or name='review_form' or name='outcome_map'");
$Conf->qe("insert into Settings set name='revform_update', value=unix_timestamp(current_timestamp)");
$Conf->qe("insert into Settings set name='sub_sha1', value=1 on duplicate key update value=1");
