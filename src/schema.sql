--
-- Table structure for table `ActionLog`
--

DROP TABLE IF EXISTS `ActionLog`;
CREATE TABLE `ActionLog` (
  `logId` int(11) NOT NULL AUTO_INCREMENT,
  `contactId` int(11) NOT NULL,
  `destContactId` int(11) DEFAULT NULL,
  `trueContactId` int(11) DEFAULT NULL,
  `paperId` int(11) DEFAULT NULL,
  `timestamp` bigint(11) NOT NULL,
  `ipaddr` varbinary(39) DEFAULT NULL,
  `action` varbinary(4096) NOT NULL,
  `data` varbinary(8192) DEFAULT NULL,
  PRIMARY KEY (`logId`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;



--
-- Table structure for table `Capability`
--

DROP TABLE IF EXISTS `Capability`;
CREATE TABLE `Capability` (
  `capabilityType` int(11) NOT NULL,
  `contactId` int(11) NOT NULL,
  `paperId` int(11) NOT NULL,
  `reviewId` int(11) NOT NULL DEFAULT 0,
  `timeCreated` bigint(11) NOT NULL,
  `timeUsed` bigint(11) NOT NULL,
  `timeInvalid` bigint(11) NOT NULL,
  `timeExpires` bigint(11) NOT NULL,
  `salt` varbinary(255) NOT NULL,
  `inputData` varbinary(16384) DEFAULT NULL,
  `data` varbinary(16384) DEFAULT NULL,
  `outputData` longblob DEFAULT NULL,
  PRIMARY KEY (`salt`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;



--
-- Table structure for table `ContactCounter`
--

DROP TABLE IF EXISTS `ContactCounter`;
CREATE TABLE `ContactCounter` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;



--
-- Table structure for table `ContactInfo`
--

DROP TABLE IF EXISTS `ContactInfo`;
CREATE TABLE `ContactInfo` (
  `contactId` int(11) NOT NULL AUTO_INCREMENT,
  `email` varchar(120) NOT NULL,
  `firstName` varbinary(120) NOT NULL DEFAULT '',
  `lastName` varbinary(120) NOT NULL DEFAULT '',
  `unaccentedName` varbinary(2048) NOT NULL DEFAULT '',
  `affiliation` varbinary(2048) NOT NULL DEFAULT '',
  `roles` tinyint(1) NOT NULL DEFAULT 0,
  `disabled` tinyint(1) NOT NULL DEFAULT 0,
  `primaryContactId` int(11) NOT NULL DEFAULT 0,
  `contactTags` varbinary(4096) DEFAULT NULL,
  `cflags` int(11) NOT NULL DEFAULT 0,
  `orcid` varbinary(64) DEFAULT NULL,
  `phone` varbinary(64) DEFAULT NULL,
  `country` varbinary(256) DEFAULT NULL,
  `password` varbinary(2048) NOT NULL,
  `passwordTime` bigint(11) NOT NULL DEFAULT 0,
  `passwordUseTime` bigint(11) NOT NULL DEFAULT 0,
  `collaborators` varbinary(8192) DEFAULT NULL,
  `preferredEmail` varchar(120) DEFAULT NULL,
  `updateTime` bigint(11) NOT NULL DEFAULT 0,
  `lastLogin` bigint(11) NOT NULL DEFAULT 0,
  `defaultWatch` int(11) NOT NULL DEFAULT 2,
  `cdbRoles` tinyint(1) NOT NULL DEFAULT 0,
  `data` varbinary(32767) DEFAULT NULL,
  PRIMARY KEY (`contactId`),
  UNIQUE KEY `email` (`email`),
  KEY `roles` (`roles`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;



--
-- Table structure for table `DeletedContactInfo`
--

DROP TABLE IF EXISTS `DeletedContactInfo`;
CREATE TABLE `DeletedContactInfo` (
  `contactId` int(11) NOT NULL,
  `firstName` varbinary(120) NOT NULL,
  `lastName` varbinary(120) NOT NULL,
  `unaccentedName` varbinary(2048) NOT NULL,
  `email` varchar(120) NOT NULL,
  `affiliation` varbinary(2048) NOT NULL DEFAULT ''
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;



--
-- Table structure for table `DocumentLink`
--

DROP TABLE IF EXISTS `DocumentLink`;
CREATE TABLE `DocumentLink` (
  `paperId` int(11) NOT NULL,
  `linkId` int(11) NOT NULL,
  `linkType` int(11) NOT NULL,
  `documentId` int(11) NOT NULL,
  PRIMARY KEY (`paperId`,`linkId`,`linkType`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;



--
-- Table structure for table `FilteredDocument`
--

DROP TABLE IF EXISTS `FilteredDocument`;
CREATE TABLE `FilteredDocument` (
  `inDocId` int(11) NOT NULL,
  `filterType` int(11) NOT NULL,
  `outDocId` int(11) NOT NULL,
  `createdAt` bigint(11) NOT NULL,
  PRIMARY KEY (`inDocId`,`filterType`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;



--
-- Table structure for table `Formula`
--

DROP TABLE IF EXISTS `Formula`;
CREATE TABLE `Formula` (
  `formulaId` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(200) NOT NULL,
  `expression` varbinary(4096) NOT NULL,
  `createdBy` int(11) NOT NULL DEFAULT 0,
  `timeModified` bigint(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`formulaId`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;



--
-- Table structure for table `Invitation`
--

DROP TABLE IF EXISTS `Invitation`;
CREATE TABLE `Invitation` (
  `invitationId` int(11) NOT NULL AUTO_INCREMENT,
  `invitationType` int(11) NOT NULL,
  `email` varchar(120) NOT NULL,
  `firstName` varbinary(120) DEFAULT NULL,
  `lastName` varbinary(120) DEFAULT NULL,
  `affiliation` varbinary(2048) DEFAULT NULL,
  `requestedBy` int(11) NOT NULL,
  `timeRequested` bigint(11) NOT NULL DEFAULT 0,
  `timeRequestNotified` bigint(11) NOT NULL DEFAULT 0,
  `salt` varbinary(255) NOT NULL,
  `data` varbinary(4096) DEFAULT NULL,
  PRIMARY KEY (`invitationId`),
  UNIQUE KEY `salt` (`salt`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;



--
-- Table structure for table `InvitationLog`
--

DROP TABLE IF EXISTS `InvitationLog`;
CREATE TABLE `InvitationLog` (
  `logId` int(11) NOT NULL AUTO_INCREMENT,
  `invitationId` int(11) NOT NULL,
  `mailId` int(11) DEFAULT NULL,
  `contactId` int(11) NOT NULL,
  `action` int(11) NOT NULL,
  `timestamp` bigint(11) NOT NULL,
  PRIMARY KEY (`logId`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;



--
-- Table structure for table `MailLog`
--

DROP TABLE IF EXISTS `MailLog`;
CREATE TABLE `MailLog` (
  `mailId` int(11) NOT NULL AUTO_INCREMENT,
  `contactId` int NOT NULL DEFAULT 0,
  `recipients` varbinary(200) NOT NULL,
  `q` varbinary(4096) DEFAULT NULL,
  `t` varbinary(200) DEFAULT NULL,
  `paperIds` blob,
  `cc` blob,
  `replyto` blob,
  `subject` blob,
  `emailBody` blob,
  `fromNonChair` tinyint(1) NOT NULL DEFAULT 0,
  `status` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`mailId`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;



--
-- Table structure for table `Paper`
--

DROP TABLE IF EXISTS `Paper`;
CREATE TABLE `Paper` (
  `paperId` int(11) NOT NULL AUTO_INCREMENT,
  `title` varbinary(512) DEFAULT NULL,
  `authorInformation` varbinary(8192) DEFAULT NULL,
  `abstract` varbinary(16384) DEFAULT NULL,
  `collaborators` varbinary(8192) DEFAULT NULL,
  `timeSubmitted` bigint(11) NOT NULL DEFAULT 0,
  `timeWithdrawn` bigint(11) NOT NULL DEFAULT 0,
  `timeModified` bigint(11) NOT NULL DEFAULT 0,
  `timeFinalSubmitted` bigint(11) NOT NULL DEFAULT 0,
  `paperStorageId` int(11) NOT NULL DEFAULT 0,
  # `sha1` copied from PaperStorage to reduce joins
  `sha1` varbinary(64) NOT NULL DEFAULT '',
  `finalPaperStorageId` int(11) NOT NULL DEFAULT 0,
  `blind` tinyint(1) NOT NULL DEFAULT 1,
  `outcome` tinyint(1) NOT NULL DEFAULT 0,
  `leadContactId` int(11) NOT NULL DEFAULT 0,
  `shepherdContactId` int(11) NOT NULL DEFAULT 0,
  `managerContactId` int(11) NOT NULL DEFAULT 0,
  `capVersion` int(1) NOT NULL DEFAULT 0,
  # next 3 fields copied from PaperStorage to reduce joins
  `size` bigint(11) NOT NULL DEFAULT -1,
  `mimetype` varbinary(80) NOT NULL DEFAULT '',
  `timestamp` bigint(11) NOT NULL DEFAULT 0,
  `pdfFormatStatus` bigint(11) NOT NULL DEFAULT 0,
  `withdrawReason` varbinary(1024) DEFAULT NULL,
  `paperFormat` tinyint(1) DEFAULT NULL,
  `dataOverflow` longblob,
  PRIMARY KEY (`paperId`),
  KEY `timeSubmitted` (`timeSubmitted`),
  KEY `leadContactId` (`leadContactId`),
  KEY `shepherdContactId` (`shepherdContactId`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;



--
-- Table structure for table `PaperComment`
--

DROP TABLE IF EXISTS `PaperComment`;
CREATE TABLE `PaperComment` (
  `paperId` int(11) NOT NULL,
  `commentId` int(11) NOT NULL AUTO_INCREMENT,
  `contactId` int(11) NOT NULL,
  `timeModified` bigint(11) NOT NULL,
  `timeNotified` bigint(11) NOT NULL DEFAULT 0,
  `timeDisplayed` bigint(11) NOT NULL DEFAULT 0,
  `comment` varbinary(32767) DEFAULT NULL,
  `commentType` int(11) NOT NULL DEFAULT 0,
  `replyTo` int(11) NOT NULL,
  `ordinal` int(11) NOT NULL DEFAULT 0,
  `authorOrdinal` int(11) NOT NULL DEFAULT 0,
  `commentTags` varbinary(1024) DEFAULT NULL,
  `commentRound` int(11) NOT NULL DEFAULT 0,
  `commentFormat` tinyint(1) DEFAULT NULL,
  `commentOverflow` longblob DEFAULT NULL,
  `commentData` varbinary(4096) DEFAULT NULL,
  PRIMARY KEY (`paperId`,`commentId`),
  UNIQUE KEY `commentId` (`commentId`),
  KEY `contactId` (`contactId`),
  KEY `timeModifiedContact` (`timeModified`,`contactId`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;



--
-- Table structure for table `PaperConflict`
--

DROP TABLE IF EXISTS `PaperConflict`;
CREATE TABLE `PaperConflict` (
  `paperId` int(11) NOT NULL,
  `contactId` int(11) NOT NULL,
  `conflictType` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`contactId`,`paperId`),
  KEY `paperId` (`paperId`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;



--
-- Table structure for table `PaperOption`
--

DROP TABLE IF EXISTS `PaperOption`;
CREATE TABLE `PaperOption` (
  `paperId` int(11) NOT NULL,
  `optionId` int(11) NOT NULL,
  `value` bigint(11) NOT NULL DEFAULT 0,
  `data` varbinary(32767) DEFAULT NULL,
  `dataOverflow` longblob,
  PRIMARY KEY (`paperId`,`optionId`,`value`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;



--
-- Table structure for table `PaperReview`
--

DROP TABLE IF EXISTS `PaperReview`;
CREATE TABLE `PaperReview` (
  `paperId` int(11) NOT NULL,
  `reviewId` int(11) NOT NULL AUTO_INCREMENT,
  `contactId` int(11) NOT NULL,
  `requestedBy` int(11) NOT NULL DEFAULT 0,
  `reviewToken` int(11) NOT NULL DEFAULT 0,
  `reviewRound` int(1) NOT NULL DEFAULT 0,
  `reviewOrdinal` int(1) NOT NULL DEFAULT 0,
  `reviewType` tinyint(1) NOT NULL DEFAULT 0,
  `reviewBlind` tinyint(1) NOT NULL DEFAULT 1,
  `reviewTime` bigint(1) NOT NULL DEFAULT 0,
  `reviewModified` bigint(1) NOT NULL DEFAULT 0,
  `reviewSubmitted` bigint(1) DEFAULT NULL,
  `reviewAuthorSeen` bigint(1) DEFAULT NULL,
  `timeDisplayed` bigint(11) NOT NULL DEFAULT 0,
  `timeApprovalRequested` bigint(11) NOT NULL DEFAULT 0,
  `reviewNeedsSubmit` tinyint(1) NOT NULL DEFAULT 1,
  `reviewViewScore` tinyint(2) NOT NULL DEFAULT -3,

  `timeRequested` bigint(11) NOT NULL DEFAULT 0,
  `timeRequestNotified` bigint(11) NOT NULL DEFAULT 0,
  `reviewAuthorModified` bigint(1) DEFAULT NULL,
  `reviewNotified` bigint(1) DEFAULT NULL,
  `reviewAuthorNotified` bigint(11) NOT NULL DEFAULT 0,
  `reviewEditVersion` int(1) NOT NULL DEFAULT 0,
  `reviewWordCount` int(11) DEFAULT NULL,

  `s01` smallint(1) NOT NULL DEFAULT 0,
  `s02` smallint(1) NOT NULL DEFAULT 0,
  `s03` smallint(1) NOT NULL DEFAULT 0,
  `s04` smallint(1) NOT NULL DEFAULT 0,
  `s05` smallint(1) NOT NULL DEFAULT 0,
  `s06` smallint(1) NOT NULL DEFAULT 0,
  `s07` smallint(1) NOT NULL DEFAULT 0,
  `s08` smallint(1) NOT NULL DEFAULT 0,
  `s09` smallint(1) NOT NULL DEFAULT 0,
  `s10` smallint(4) NOT NULL DEFAULT 0,
  `s11` smallint(4) NOT NULL DEFAULT 0,

  `tfields` longblob,
  `sfields` varbinary(2048) DEFAULT NULL,
  `data` varbinary(8192) DEFAULT NULL,

  PRIMARY KEY (`paperId`,`reviewId`),
  UNIQUE KEY `reviewId` (`reviewId`),
  KEY `contactId` (`contactId`),
  KEY `reviewType` (`reviewType`),
  KEY `reviewRound` (`reviewRound`),
  KEY `requestedBy` (`requestedBy`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;



--
-- Table structure for table `PaperReviewHistory`
--

DROP TABLE IF EXISTS `PaperReviewHistory`;
CREATE TABLE `PaperReviewHistory` (
  `paperId` int(11) NOT NULL,
  `reviewId` int(11) NOT NULL,
  `reviewTime` bigint(11) NOT NULL,
  `reviewNextTime` bigint(11) NOT NULL,
  `contactId` int(11) NOT NULL,
  `reviewRound` int(1) NOT NULL,
  `reviewOrdinal` int(1) NOT NULL,
  `reviewType` tinyint(1) NOT NULL,
  `reviewBlind` tinyint(1) NOT NULL,
  `reviewModified` bigint(11) NOT NULL,
  `reviewSubmitted` bigint(1) NOT NULL,
  `timeDisplayed` bigint(11) NOT NULL,
  `timeApprovalRequested` bigint(11) NOT NULL,
  `reviewAuthorSeen` bigint(1) NOT NULL,
  `reviewAuthorModified` bigint(1) DEFAULT NULL,
  `reviewNotified` bigint(1) DEFAULT NULL,
  `reviewAuthorNotified` bigint(11) NOT NULL,
  `reviewEditVersion` int(1) NOT NULL,
  `revdelta` longblob DEFAULT NULL,

  PRIMARY KEY (`paperId`,`reviewId`,`reviewTime`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;



--
-- Table structure for table `PaperReviewPreference`
--

DROP TABLE IF EXISTS `PaperReviewPreference`;
CREATE TABLE `PaperReviewPreference` (
  `paperId` int(11) NOT NULL,
  `contactId` int(11) NOT NULL,
  `preference` int(4) NOT NULL DEFAULT 0,
  `expertise` int(4) DEFAULT NULL,
  PRIMARY KEY (`paperId`,`contactId`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;



--
-- Table structure for table `PaperReviewRefused`
--

DROP TABLE IF EXISTS `PaperReviewRefused`;
CREATE TABLE `PaperReviewRefused` (
  `paperId` int(11) NOT NULL,
  `email` varchar(120) NOT NULL,
  `firstName` varbinary(120) DEFAULT NULL,
  `lastName` varbinary(120) DEFAULT NULL,
  `affiliation` varbinary(2048) DEFAULT NULL,
  `contactId` int(11) NOT NULL,
  `refusedReviewId` int(11) DEFAULT NULL,
  `refusedReviewType` tinyint(1) NOT NULL DEFAULT 0,
  `reviewRound` int(1) DEFAULT NULL,
  `requestedBy` int(11) NOT NULL,
  `timeRequested` bigint(11) DEFAULT NULL,
  `refusedBy` int(11) DEFAULT NULL,
  `timeRefused` bigint(11) DEFAULT NULL,
  `data` varbinary(8192) DEFAULT NULL,
  `reason` varbinary(32767) DEFAULT NULL,
  PRIMARY KEY (`paperId`,`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;



--
-- Table structure for table `PaperStorage`
--

DROP TABLE IF EXISTS `PaperStorage`;
CREATE TABLE `PaperStorage` (
  `paperId` int(11) NOT NULL,
  `paperStorageId` int(11) NOT NULL AUTO_INCREMENT,
  `timestamp` bigint(11) NOT NULL,
  `mimetype` varbinary(80) NOT NULL DEFAULT '',
  `paper` longblob,
  `compression` tinyint(1) NOT NULL DEFAULT 0,
  `sha1` varbinary(64) NOT NULL DEFAULT '',
  `crc32` binary(4) DEFAULT NULL,
  `documentType` int(3) NOT NULL DEFAULT 0,
  `filename` varbinary(255) DEFAULT NULL,
  `infoJson` varbinary(32768) DEFAULT NULL,
  `size` bigint(11) NOT NULL DEFAULT -1,
  `filterType` int(3) DEFAULT NULL,
  `originalStorageId` int(11) DEFAULT NULL,
  `inactive` tinyint(1) NOT NULL DEFAULT 0,
  `npages` int(3) NOT NULL DEFAULT -1,
  `width` int(8) NOT NULL DEFAULT -1,
  `height` int(8) NOT NULL DEFAULT -1,
  PRIMARY KEY (`paperId`,`paperStorageId`),
  UNIQUE KEY `paperStorageId` (`paperStorageId`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;



--
-- Table structure for table `PaperTag`
--

DROP TABLE IF EXISTS `PaperTag`;
CREATE TABLE `PaperTag` (
  `paperId` int(11) NOT NULL,
  `tag` varchar(80) NOT NULL,		# case-insensitive; see TAG_MAXLEN in init.php
  `tagIndex` float NOT NULL DEFAULT 0,
  PRIMARY KEY (`paperId`,`tag`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;



--
-- Table structure for table `PaperTagAnno`
--

DROP TABLE IF EXISTS `PaperTagAnno`;
CREATE TABLE `PaperTagAnno` (
  `tag` varchar(80) NOT NULL,   # case-insensitive; see TAG_MAXLEN in init.php
  `annoId` int(11) NOT NULL,
  `tagIndex` float NOT NULL DEFAULT 0,
  `heading` varbinary(8192) DEFAULT NULL,
  `annoFormat` tinyint(1) DEFAULT NULL,
  `infoJson` varbinary(32768) DEFAULT NULL,
  PRIMARY KEY (`tag`,`annoId`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;



--
-- Table structure for table `PaperTopic`
--

DROP TABLE IF EXISTS `PaperTopic`;
CREATE TABLE `PaperTopic` (
  `paperId` int(11) NOT NULL,
  `topicId` int(11) NOT NULL,
  PRIMARY KEY (`paperId`,`topicId`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;



--
-- Table structure for table `PaperWatch`
--

DROP TABLE IF EXISTS `PaperWatch`;
CREATE TABLE `PaperWatch` (
  `paperId` int(11) NOT NULL,
  `contactId` int(11) NOT NULL,
  `watch` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`paperId`,`contactId`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;



--
-- Table structure for table `ReviewRating`
--

DROP TABLE IF EXISTS `ReviewRating`;
CREATE TABLE `ReviewRating` (
  `paperId` int(11) NOT NULL,
  `reviewId` int(11) NOT NULL,
  `contactId` int(11) NOT NULL,
  `rating` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`paperId`,`reviewId`,`contactId`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;



--
-- Table structure for table `ReviewRequest`
--

DROP TABLE IF EXISTS `ReviewRequest`;
CREATE TABLE `ReviewRequest` (
  `paperId` int(11) NOT NULL,
  `email` varchar(120) NOT NULL,
  `firstName` varbinary(120) DEFAULT NULL,
  `lastName` varbinary(120) DEFAULT NULL,
  `affiliation` varbinary(2048) DEFAULT NULL,
  `reason` varbinary(32767) DEFAULT NULL,
  `requestedBy` int(11) NOT NULL,
  `timeRequested` bigint(11) NOT NULL,
  `reviewRound` int(1) DEFAULT NULL,
  PRIMARY KEY (`paperId`,`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;



--
-- Table structure for table `Settings`
--

DROP TABLE IF EXISTS `Settings`;
CREATE TABLE `Settings` (
  `name` varbinary(256) NOT NULL,
  `value` bigint(11) NOT NULL,
  `data` varbinary(32767) DEFAULT NULL,
  PRIMARY KEY (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;



--
-- Table structure for table `TopicArea`
--

DROP TABLE IF EXISTS `TopicArea`;
CREATE TABLE `TopicArea` (
  `topicId` int(11) NOT NULL AUTO_INCREMENT,
  `topicName` varbinary(1024) DEFAULT NULL,
  PRIMARY KEY (`topicId`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;



--
-- Table structure for table `TopicInterest`
--

DROP TABLE IF EXISTS `TopicInterest`;
CREATE TABLE `TopicInterest` (
  `contactId` int(11) NOT NULL,
  `topicId` int(11) NOT NULL,
  `interest` int(1) NOT NULL,
  PRIMARY KEY (`contactId`,`topicId`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;




-- Initial settings
-- (each setting must be on its own line for createdb.sh)
insert into Settings (name, value, data) values
  ('allowPaperOption', 288, null),   -- schema version
  ('setupPhase', 1, null),           -- initial user is chair
  ('no_papersub', 1, null),          -- no submissions yet
  ('sub_pcconf', 1, null),           -- collect PC conflicts, not collaborators
  ('tag_chair', 1, 'accept pcpaper reject'),  -- default read-only tags
  ('pcrev_any', 1, null),            -- PC members can review any paper
  ('viewrevid', 1, null),            -- PC members can see anonymous reviewer IDs
  ('extrev_chairreq', 2, null),      -- administrators must approve potentially-conflicted reviewers
  ('pcrev_soft', 0, null);           -- soft review deadline is explicit 0

-- matches DocumentInfo::make_empty()
insert ignore into PaperStorage set
    paperStorageId=1, paperId=0, timestamp=0, mimetype='text/plain',
    paper='', sha1=x'da39a3ee5e6b4b0d3255bfef95601890afd80709',
    documentType=0, size=0;
