--
-- Table structure for table `ActionLog`
--

DROP TABLE IF EXISTS `ActionLog`;
CREATE TABLE `ActionLog` (
  `logId` int(11) NOT NULL AUTO_INCREMENT,
  `contactId` int(11) NOT NULL,
  `paperId` int(11) DEFAULT NULL,
  `time` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `ipaddr` varchar(16) DEFAULT NULL,
  `action` varbinary(4096) NOT NULL,
  PRIMARY KEY (`logId`),
  UNIQUE KEY `logId` (`logId`),
  KEY `contactId` (`contactId`),
  KEY `paperId` (`paperId`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;



--
-- Table structure for table `Capability`
--

DROP TABLE IF EXISTS `Capability`;
CREATE TABLE `Capability` (
  `capabilityId` int(11) NOT NULL AUTO_INCREMENT,
  `capabilityType` int(11) NOT NULL,
  `contactId` int(11) NOT NULL,
  `paperId` int(11) NOT NULL,
  `timeExpires` int(11) NOT NULL,
  `salt` varbinary(255) NOT NULL,
  `data` varbinary(4096) DEFAULT NULL,
  PRIMARY KEY (`capabilityId`),
  UNIQUE KEY `capabilityId` (`capabilityId`),
  UNIQUE KEY `salt` (`salt`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;



--
-- Table structure for table `ContactInfo`
--

DROP TABLE IF EXISTS `ContactInfo`;
CREATE TABLE `ContactInfo` (
  `contactId` int(11) NOT NULL AUTO_INCREMENT,
  `firstName` varchar(60) NOT NULL DEFAULT '',
  `lastName` varchar(60) NOT NULL DEFAULT '',
  `unaccentedName` varchar(120) NOT NULL DEFAULT '',
  `email` varchar(120) NOT NULL,
  `preferredEmail` varchar(120) DEFAULT NULL,
  `affiliation` varchar(2048) NOT NULL DEFAULT '',
  `voicePhoneNumber` varchar(256) DEFAULT NULL,
  `faxPhoneNumber` varchar(256) DEFAULT NULL,
  `password` varbinary(2048) NOT NULL,
  `passwordTime` int(11) NOT NULL DEFAULT '0',
  `passwordIsCdb` tinyint(1) NOT NULL DEFAULT '0',
  `collaborators` varbinary(8192) DEFAULT NULL,
  `creationTime` int(11) NOT NULL DEFAULT '0',
  `lastLogin` int(11) NOT NULL DEFAULT '0',
  `defaultWatch` int(11) NOT NULL DEFAULT '2',
  `roles` tinyint(1) NOT NULL DEFAULT '0',
  `disabled` tinyint(1) NOT NULL DEFAULT '0',
  `contactTags` varbinary(4096) DEFAULT NULL,
  `data` varbinary(32767) DEFAULT NULL,
  PRIMARY KEY (`contactId`),
  UNIQUE KEY `contactId` (`contactId`),
  UNIQUE KEY `contactIdRoles` (`contactId`,`roles`),
  UNIQUE KEY `email` (`email`),
  KEY `fullName` (`lastName`,`firstName`,`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;



--
-- Table structure for table `Formula`
--

DROP TABLE IF EXISTS `Formula`;
CREATE TABLE `Formula` (
  `formulaId` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(200) NOT NULL,
  `heading` varchar(200) NOT NULL DEFAULT '',
  `headingTitle` varbinary(4096) NOT NULL,
  `expression` varbinary(4096) NOT NULL,
  `authorView` tinyint(1) NOT NULL DEFAULT '1',
  `createdBy` int(11) NOT NULL DEFAULT '0',
  `timeModified` int(11) NOT NULL DEFAULT '0',
  PRIMARY KEY (`formulaId`),
  UNIQUE KEY `formulaId` (`formulaId`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;



--
-- Table structure for table `MailLog`
--

DROP TABLE IF EXISTS `MailLog`;
CREATE TABLE `MailLog` (
  `mailId` int(11) NOT NULL AUTO_INCREMENT,
  `recipients` varchar(200) NOT NULL,
  `q` varchar(4096) DEFAULT NULL,
  `t` varchar(200) DEFAULT NULL,
  `paperIds` text,
  `cc` text,
  `replyto` text,
  `subject` text,
  `emailBody` text,
  PRIMARY KEY (`mailId`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;



--
-- Table structure for table `Paper`
--

DROP TABLE IF EXISTS `Paper`;
CREATE TABLE `Paper` (
  `paperId` int(11) NOT NULL AUTO_INCREMENT,
  `title` varchar(200) DEFAULT NULL,
  `authorInformation` varbinary(8192) DEFAULT NULL,
  `abstract` varbinary(16384) DEFAULT NULL,
  `collaborators` varbinary(8192) DEFAULT NULL,
  `timeSubmitted` int(11) NOT NULL DEFAULT '0',
  `timeWithdrawn` int(11) NOT NULL DEFAULT '0',
  `timeFinalSubmitted` int(11) NOT NULL DEFAULT '0',
  `paperStorageId` int(11) NOT NULL DEFAULT '0',
  `sha1` varbinary(20) NOT NULL DEFAULT '',
  `finalPaperStorageId` int(11) NOT NULL DEFAULT '0',
  `blind` tinyint(1) NOT NULL DEFAULT '1',
  `outcome` tinyint(1) NOT NULL DEFAULT '0',
  `leadContactId` int(11) NOT NULL DEFAULT '0',
  `shepherdContactId` int(11) NOT NULL DEFAULT '0',
  `managerContactId` int(11) NOT NULL DEFAULT '0',
  `capVersion` int(1) NOT NULL DEFAULT '0',
  # next 3 fields copied from PaperStorage to reduce joins
  `size` int(11) NOT NULL DEFAULT '0',
  `mimetype` varchar(80) NOT NULL DEFAULT '',
  `timestamp` int(11) NOT NULL DEFAULT '0',
  `withdrawReason` varbinary(1024) DEFAULT NULL,
  PRIMARY KEY (`paperId`),
  UNIQUE KEY `paperId` (`paperId`),
  KEY `title` (`title`),
  KEY `timeSubmitted` (`timeSubmitted`),
  KEY `leadContactId` (`leadContactId`),
  KEY `shepherdContactId` (`shepherdContactId`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;



--
-- Table structure for table `PaperComment`
--

DROP TABLE IF EXISTS `PaperComment`;
CREATE TABLE `PaperComment` (
  `commentId` int(11) NOT NULL AUTO_INCREMENT,
  `contactId` int(11) NOT NULL,
  `paperId` int(11) NOT NULL,
  `timeModified` int(11) NOT NULL,
  `timeNotified` int(11) NOT NULL DEFAULT '0',
  `comment` varbinary(32767) DEFAULT NULL,
  `commentType` int(11) NOT NULL DEFAULT '0',
  `replyTo` int(11) NOT NULL,
  `paperStorageId` int(11) NOT NULL DEFAULT '0',
  `ordinal` int(11) NOT NULL DEFAULT '0',
  `commentTags` varbinary(1024) DEFAULT NULL,
  `commentRound` int(11) NOT NULL DEFAULT '0',
  PRIMARY KEY (`commentId`),
  UNIQUE KEY `commentId` (`commentId`),
  KEY `contactId` (`contactId`),
  KEY `paperId` (`paperId`),
  KEY `contactPaper` (`contactId`,`paperId`),
  KEY `timeModified` (`timeModified`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;



--
-- Table structure for table `PaperConflict`
--

DROP TABLE IF EXISTS `PaperConflict`;
CREATE TABLE `PaperConflict` (
  `paperId` int(11) NOT NULL,
  `contactId` int(11) NOT NULL,
  `conflictType` tinyint(1) NOT NULL DEFAULT '0',
  UNIQUE KEY `contactPaper` (`contactId`,`paperId`),
  UNIQUE KEY `contactPaperConflict` (`contactId`,`paperId`,`conflictType`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;



--
-- Table structure for table `PaperOption`
--

DROP TABLE IF EXISTS `PaperOption`;
CREATE TABLE `PaperOption` (
  `paperId` int(11) NOT NULL,
  `optionId` int(11) NOT NULL,
  `value` int(11) NOT NULL DEFAULT '0',
  `data` varbinary(32768),
  KEY `paperOption` (`paperId`,`optionId`,`value`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;



--
-- Table structure for table `PaperReview`
--

DROP TABLE IF EXISTS `PaperReview`;
CREATE TABLE `PaperReview` (
  `reviewId` int(11) NOT NULL AUTO_INCREMENT,
  `paperId` int(11) NOT NULL,
  `contactId` int(11) NOT NULL,
  `reviewToken` int(11) NOT NULL DEFAULT '0',
  `reviewType` tinyint(1) NOT NULL DEFAULT '0',
  `reviewRound` int(1) NOT NULL DEFAULT '0',
  `requestedBy` int(11) NOT NULL DEFAULT '0',
  `timeRequested` int(11) NOT NULL DEFAULT '0',
  `timeRequestNotified` int(11) NOT NULL DEFAULT '0',
  `reviewBlind` tinyint(1) NOT NULL DEFAULT '1',
  `reviewModified` int(1) DEFAULT NULL,
  `reviewSubmitted` int(1) DEFAULT NULL,
  `reviewNotified` int(1) DEFAULT NULL,
  `reviewAuthorNotified` int(11) NOT NULL DEFAULT '0',
  `reviewAuthorSeen` int(1) DEFAULT NULL,
  `reviewOrdinal` int(1) DEFAULT NULL,
  `reviewEditVersion` int(1) NOT NULL DEFAULT '0',
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
  `paperSummary` mediumtext,
  `commentsToAuthor` mediumtext,
  `commentsToPC` mediumtext,
  `commentsToAddress` mediumtext,
  `weaknessOfPaper` mediumtext,
  `strengthOfPaper` mediumtext,
  `potential` tinyint(4) NOT NULL DEFAULT '0',
  `fixability` tinyint(4) NOT NULL DEFAULT '0',
  `textField7` mediumtext,
  `textField8` mediumtext,
  `reviewWordCount` int(11) DEFAULT NULL,
  PRIMARY KEY (`reviewId`),
  UNIQUE KEY `reviewId` (`reviewId`),
  UNIQUE KEY `contactPaper` (`contactId`,`paperId`),
  KEY `paperId` (`paperId`,`reviewOrdinal`),
  KEY `reviewSubmitted` (`reviewSubmitted`),
  KEY `reviewNeedsSubmit` (`reviewNeedsSubmit`),
  KEY `reviewType` (`reviewType`),
  KEY `reviewRound` (`reviewRound`),
  KEY `requestedBy` (`requestedBy`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;



--
-- Table structure for table `PaperReviewArchive`
--

DROP TABLE IF EXISTS `PaperReviewArchive`;
CREATE TABLE `PaperReviewArchive` (
  `reviewArchiveId` int(11) NOT NULL AUTO_INCREMENT,
  `reviewId` int(11) NOT NULL,
  `paperId` int(11) NOT NULL,
  `contactId` int(11) NOT NULL,
  `reviewToken` int(11) NOT NULL DEFAULT '0',
  `reviewType` tinyint(1) NOT NULL DEFAULT '0',
  `reviewRound` int(1) NOT NULL DEFAULT '0',
  `requestedBy` int(11) NOT NULL DEFAULT '0',
  `timeRequested` int(11) NOT NULL DEFAULT '0',
  `timeRequestNotified` int(11) NOT NULL DEFAULT '0',
  `reviewBlind` tinyint(1) NOT NULL DEFAULT '1',
  `reviewModified` int(1) DEFAULT NULL,
  `reviewSubmitted` int(1) DEFAULT NULL,
  `reviewNotified` int(1) DEFAULT NULL,
  `reviewAuthorNotified` int(11) NOT NULL DEFAULT '0',
  `reviewAuthorSeen` int(1) DEFAULT NULL,
  `reviewOrdinal` int(1) DEFAULT NULL,
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
  `paperSummary` mediumtext,
  `commentsToAuthor` mediumtext,
  `commentsToPC` mediumtext,
  `commentsToAddress` mediumtext,
  `weaknessOfPaper` mediumtext,
  `strengthOfPaper` mediumtext,
  `potential` tinyint(4) NOT NULL DEFAULT '0',
  `fixability` tinyint(4) NOT NULL DEFAULT '0',
  `textField7` mediumtext,
  `textField8` mediumtext,
  `reviewWordCount` int(11) DEFAULT NULL,
  PRIMARY KEY (`reviewArchiveId`),
  UNIQUE KEY `reviewArchiveId` (`reviewArchiveId`),
  KEY `paperId` (`paperId`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;



--
-- Table structure for table `PaperReviewPreference`
--

DROP TABLE IF EXISTS `PaperReviewPreference`;
CREATE TABLE `PaperReviewPreference` (
  `paperId` int(11) NOT NULL,
  `contactId` int(11) NOT NULL,
  `preference` int(4) NOT NULL DEFAULT '0',
  `expertise` int(4) DEFAULT NULL,
  UNIQUE KEY `contactPaper` (`contactId`,`paperId`),
  KEY `paperId` (`paperId`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;



--
-- Table structure for table `PaperReviewRefused`
--

DROP TABLE IF EXISTS `PaperReviewRefused`;
CREATE TABLE `PaperReviewRefused` (
  `paperId` int(11) NOT NULL,
  `contactId` int(11) NOT NULL,
  `requestedBy` int(11) NOT NULL,
  `reason` varbinary(32767) DEFAULT NULL,
  KEY `paperId` (`paperId`),
  KEY `contactId` (`contactId`),
  KEY `requestedBy` (`requestedBy`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;



--
-- Table structure for table `PaperStorage`
--

DROP TABLE IF EXISTS `PaperStorage`;
CREATE TABLE `PaperStorage` (
  `paperStorageId` int(11) NOT NULL AUTO_INCREMENT,
  `paperId` int(11) NOT NULL,
  `timestamp` int(11) NOT NULL,
  `mimetype` varchar(80) NOT NULL DEFAULT '',
  `paper` longblob,
  `compression` tinyint(1) NOT NULL DEFAULT '0',
  `sha1` varbinary(20) NOT NULL DEFAULT '',
  `documentType` int(3) NOT NULL DEFAULT '0',
  `filename` varchar(255) DEFAULT NULL,
  `infoJson` varchar(255) DEFAULT NULL,
  `size` bigint(11) DEFAULT NULL,
  `filterType` int(3) DEFAULT NULL,
  `originalStorageId` int(11) DEFAULT NULL,
  PRIMARY KEY (`paperStorageId`),
  UNIQUE KEY `paperStorageId` (`paperStorageId`),
  KEY `paperId` (`paperId`),
  KEY `mimetype` (`mimetype`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;



--
-- Table structure for table `PaperTag`
--

DROP TABLE IF EXISTS `PaperTag`;
CREATE TABLE `PaperTag` (
  `paperId` int(11) NOT NULL,
  `tag` varchar(40) NOT NULL,		# see TAG_MAXLEN in header.php
  `tagIndex` int(11) NOT NULL DEFAULT '0',
  UNIQUE KEY `paperTag` (`paperId`,`tag`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;



--
-- Table structure for table `PaperTopic`
--

DROP TABLE IF EXISTS `PaperTopic`;
CREATE TABLE `PaperTopic` (
  `topicId` int(11) NOT NULL,
  `paperId` int(11) NOT NULL,
  UNIQUE KEY `paperTopic` (`paperId`,`topicId`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;



--
-- Table structure for table `PaperWatch`
--

DROP TABLE IF EXISTS `PaperWatch`;
CREATE TABLE `PaperWatch` (
  `paperId` int(11) NOT NULL,
  `contactId` int(11) NOT NULL,
  `watch` int(11) NOT NULL DEFAULT '0',
  UNIQUE KEY `contactPaper` (`contactId`,`paperId`),
  UNIQUE KEY `contactPaperWatch` (`contactId`,`paperId`,`watch`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;



--
-- Table structure for table `ReviewRating`
--

DROP TABLE IF EXISTS `ReviewRating`;
CREATE TABLE `ReviewRating` (
  `reviewId` int(11) NOT NULL,
  `contactId` int(11) NOT NULL,
  `rating` tinyint(1) NOT NULL DEFAULT '0',
  UNIQUE KEY `reviewContact` (`reviewId`,`contactId`),
  UNIQUE KEY `reviewContactRating` (`reviewId`,`contactId`,`rating`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;



--
-- Table structure for table `ReviewRequest`
--

DROP TABLE IF EXISTS `ReviewRequest`;
CREATE TABLE `ReviewRequest` (
  `paperId` int(11) NOT NULL,
  `name` varchar(120) DEFAULT NULL,
  `email` varchar(120) DEFAULT NULL,
  `reason` varbinary(32767) DEFAULT NULL,
  `requestedBy` int(11) NOT NULL,
  UNIQUE KEY `paperEmail` (`paperId`,`email`),
  KEY `paperId` (`paperId`),
  KEY `requestedBy` (`requestedBy`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;



--
-- Table structure for table `Settings`
--

DROP TABLE IF EXISTS `Settings`;
CREATE TABLE `Settings` (
  `name` varbinary(256) DEFAULT NULL,
  `value` int(11) NOT NULL,
  `data` varbinary(32767) DEFAULT NULL,
  UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;



--
-- Table structure for table `TopicArea`
--

DROP TABLE IF EXISTS `TopicArea`;
CREATE TABLE `TopicArea` (
  `topicId` int(11) NOT NULL AUTO_INCREMENT,
  `topicName` varchar(200) DEFAULT NULL,
  PRIMARY KEY (`topicId`),
  UNIQUE KEY `topicId` (`topicId`),
  KEY `topicName` (`topicName`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;



--
-- Table structure for table `TopicInterest`
--

DROP TABLE IF EXISTS `TopicInterest`;
CREATE TABLE `TopicInterest` (
  `contactId` int(11) NOT NULL,
  `topicId` int(11) NOT NULL,
  `interest` int(1) DEFAULT NULL,
  UNIQUE KEY `contactTopic` (`contactId`,`topicId`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;




insert into Settings (name, value) values ('allowPaperOption', 101);
insert into Settings (name, value) values ('setupPhase', 1);
-- collect PC conflicts from authors by default, but not collaborators
insert into Settings (name, value) values ('sub_pcconf', 1);
-- default chair-only tags
insert into Settings (name, value, data) values ('tag_chair', 1, 'accept reject pcpaper');
-- turn on SHA-1 calculation by default
insert into Settings (name, value) values ('sub_sha1', 1);
-- allow PC members to review any paper by default
insert into Settings (name, value) values ('pcrev_any', 1);
-- allow external reviewers to see the other reviews by default
insert into Settings (name, value) values ('extrev_view', 2);
-- default outcome map
insert into Settings (name, value, data) values ('outcome_map', 1, '{"0":"Unspecified","-1":"Rejected","1":"Accepted"}');
-- default review form
insert into Settings (name, value, data) values ('review_form',1,'{"overAllMerit":{"name":"Overall merit","position":1,"view_score":1,"options":["Reject","Weak reject","Weak accept","Accept","Strong accept"]},"reviewerQualification":{"name":"Reviewer expertise","position":2,"view_score":1,"options":["No familiarity","Some familiarity","Knowledgeable","Expert"]},"suitableForShort":{"name":"Suitable for short paper","view_score":1,"options":["Not suitable","Can''t tell","Suitable"]},"paperSummary":{"name":"Paper summary","position":3,"display_space":5,"view_score":1},"commentsToAuthor":{"name":"Comments for author","position":4,"display_space":15,"view_score":1},"commentsToPC":{"name":"Comments for PC","position":5,"display_space":10,"view_score":0}}');

insert into PaperStorage set paperStorageId=1, paperId=0, timestamp=0, mimetype='text/plain', paper='' on duplicate key update paper='';
