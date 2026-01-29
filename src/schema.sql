--
-- Table structure for table `ActionLog`
--

DROP TABLE IF EXISTS `ActionLog`;
CREATE TABLE `ActionLog` (
  `logId` int NOT NULL AUTO_INCREMENT,
  `contactId` int NOT NULL,
  `destContactId` int DEFAULT NULL,
  `trueContactId` int DEFAULT NULL,
  `paperId` int DEFAULT NULL,
  `timestamp` bigint NOT NULL,
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
  `capabilityType` int NOT NULL,
  `contactId` int NOT NULL,
  `paperId` int NOT NULL,
  `reviewId` int NOT NULL DEFAULT 0,
  `timeCreated` bigint NOT NULL,
  `timeUsed` bigint NOT NULL,
  `useCount` bigint NOT NULL DEFAULT 0,
  `timeInvalid` bigint NOT NULL,
  `timeExpires` bigint NOT NULL,
  `salt` varbinary(255) NOT NULL,
  `inputData` varbinary(16384) DEFAULT NULL,
  `inputDataOverflow` longblob DEFAULT NULL,
  `data` varbinary(16384) DEFAULT NULL,
  `dataOverflow` longblob DEFAULT NULL,
  `outputData` longblob DEFAULT NULL,
  `outputTimestamp` bigint DEFAULT NULL,
  `outputMimetype` varbinary(80) DEFAULT NULL,
  `lookupKey` varbinary(255) DEFAULT NULL,
  PRIMARY KEY (`salt`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;



--
-- Table structure for table `ContactCounter`
--

DROP TABLE IF EXISTS `ContactCounter`;
CREATE TABLE `ContactCounter` (
  `contactId` int NOT NULL,
  `apiCount` bigint NOT NULL DEFAULT 0,
  `apiLimit` bigint NOT NULL DEFAULT 0,
  `apiRefreshMtime` bigint NOT NULL DEFAULT 0,
  `apiRefreshWindow` int NOT NULL DEFAULT 0,
  `apiRefreshAmount` int NOT NULL DEFAULT 0,
  `apiLimit2` bigint NOT NULL DEFAULT 0,
  `apiRefreshMtime2` bigint NOT NULL DEFAULT 0,
  `apiRefreshWindow2` int NOT NULL DEFAULT 0,
  `apiRefreshAmount2` int NOT NULL DEFAULT 0,
  PRIMARY KEY (`contactId`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;



--
-- Table structure for table `ContactInfo`
--

DROP TABLE IF EXISTS `ContactInfo`;
CREATE TABLE `ContactInfo` (
  `contactId` int NOT NULL AUTO_INCREMENT,
  `email` varchar(120) NOT NULL,
  `firstName` varbinary(120) NOT NULL DEFAULT '',
  `lastName` varbinary(120) NOT NULL DEFAULT '',
  `unaccentedName` varbinary(2048) NOT NULL DEFAULT '',
  `affiliation` varbinary(2048) NOT NULL DEFAULT '',
  `roles` tinyint NOT NULL DEFAULT 0,
  `primaryContactId` int NOT NULL DEFAULT 0,
  `contactTags` varbinary(4096) DEFAULT NULL,
  `cflags` int NOT NULL DEFAULT 0,
  `orcid` varbinary(64) DEFAULT NULL,
  `phone` varbinary(64) DEFAULT NULL,
  `country` varbinary(256) DEFAULT NULL,
  `password` varbinary(2048) NOT NULL,
  `passwordTime` bigint NOT NULL DEFAULT 0,
  `passwordUseTime` bigint NOT NULL DEFAULT 0,
  `collaborators` varbinary(8192) DEFAULT NULL,
  `preferredEmail` varchar(120) DEFAULT NULL,
  `updateTime` bigint NOT NULL DEFAULT 0,
  `lastLogin` bigint NOT NULL DEFAULT 0,
  `defaultWatch` int NOT NULL DEFAULT 2,
  `cdbRoles` tinyint NOT NULL DEFAULT 0,
  `data` varbinary(32767) DEFAULT NULL,
  PRIMARY KEY (`contactId`),
  UNIQUE KEY `email` (`email`),
  KEY `roles` (`roles`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;



--
-- Table structure for table `ContactPrimary`
--

DROP TABLE IF EXISTS `ContactPrimary`;
CREATE TABLE `ContactPrimary` (
  `contactId` int NOT NULL,
  `primaryContactId` int NOT NULL,
  PRIMARY KEY (`contactId`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;



--
-- Table structure for table `DeletedContactInfo`
--

DROP TABLE IF EXISTS `DeletedContactInfo`;
CREATE TABLE `DeletedContactInfo` (
  `contactId` int NOT NULL,
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
  `paperId` int NOT NULL,
  `linkId` int NOT NULL,
  `linkType` int NOT NULL,
  `linkIndex` int NOT NULL DEFAULT 0,
  `documentId` int NOT NULL,
  PRIMARY KEY (`paperId`,`linkId`,`linkType`,`linkIndex`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;



--
-- Table structure for table `FilteredDocument`
--

DROP TABLE IF EXISTS `FilteredDocument`;
CREATE TABLE `FilteredDocument` (
  `inDocId` int NOT NULL,
  `filterType` int NOT NULL,
  `outDocId` int NOT NULL,
  `createdAt` bigint NOT NULL,
  PRIMARY KEY (`inDocId`,`filterType`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;



--
-- Table structure for table `Formula`
--

DROP TABLE IF EXISTS `Formula`;
CREATE TABLE `Formula` (
  `formulaId` int NOT NULL AUTO_INCREMENT,
  `name` varchar(200) NOT NULL,
  `expression` varbinary(4096) NOT NULL,
  `createdBy` int NOT NULL DEFAULT 0,
  `timeModified` bigint NOT NULL DEFAULT 0,
  PRIMARY KEY (`formulaId`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;



--
-- Table structure for table `IDReservation`
--

DROP TABLE IF EXISTS `IDReservation`;
CREATE TABLE `IDReservation` (
  `type` int NOT NULL,
  `id` int NOT NULL,
  `timestamp` bigint NOT NULL,
  `uid` int NOT NULL AUTO_INCREMENT,
  PRIMARY KEY (`type`,`id`),
  UNIQUE KEY `uid` (`uid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;



--
-- Table structure for table `Invitation`
--

DROP TABLE IF EXISTS `Invitation`;
CREATE TABLE `Invitation` (
  `invitationId` int NOT NULL AUTO_INCREMENT,
  `invitationType` int NOT NULL,
  `email` varchar(120) NOT NULL,
  `firstName` varbinary(120) DEFAULT NULL,
  `lastName` varbinary(120) DEFAULT NULL,
  `affiliation` varbinary(2048) DEFAULT NULL,
  `requestedBy` int NOT NULL,
  `timeRequested` bigint NOT NULL DEFAULT 0,
  `timeRequestNotified` bigint NOT NULL DEFAULT 0,
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
  `logId` int NOT NULL AUTO_INCREMENT,
  `invitationId` int NOT NULL,
  `mailId` int DEFAULT NULL,
  `contactId` int NOT NULL,
  `action` int NOT NULL,
  `timestamp` bigint NOT NULL,
  PRIMARY KEY (`logId`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;



--
-- Table structure for table `MailLog`
--

DROP TABLE IF EXISTS `MailLog`;
CREATE TABLE `MailLog` (
  `mailId` int NOT NULL AUTO_INCREMENT,
  `contactId` int NOT NULL DEFAULT 0,
  `recipients` varbinary(200) NOT NULL,
  `q` varbinary(16384) DEFAULT NULL,
  `t` varbinary(200) DEFAULT NULL,
  `paperIds` blob,
  `cc` blob,
  `replyto` blob,
  `subject` blob,
  `emailBody` blob,
  `fromNonChair` tinyint NOT NULL DEFAULT 0,
  `status` tinyint NOT NULL DEFAULT 0,
  PRIMARY KEY (`mailId`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;



--
-- Table structure for table `Paper`
--

DROP TABLE IF EXISTS `Paper`;
CREATE TABLE `Paper` (
  `paperId` int NOT NULL AUTO_INCREMENT,
  `title` varbinary(512) DEFAULT NULL,
  `authorInformation` varbinary(8192) DEFAULT NULL,
  `abstract` varbinary(16384) DEFAULT NULL,
  `collaborators` varbinary(8192) DEFAULT NULL,
  `timeSubmitted` bigint NOT NULL DEFAULT 0,
  `timeWithdrawn` bigint NOT NULL DEFAULT 0,
  `timeModified` bigint NOT NULL DEFAULT 0,
  `timeFinalSubmitted` bigint NOT NULL DEFAULT 0,
  `paperStorageId` int NOT NULL DEFAULT 0,
  # `sha1` copied from PaperStorage to reduce joins
  `sha1` varbinary(64) NOT NULL DEFAULT '',
  `finalPaperStorageId` int NOT NULL DEFAULT 0,
  `blind` tinyint NOT NULL DEFAULT 1,
  `outcome` tinyint NOT NULL DEFAULT 0,
  `leadContactId` int NOT NULL DEFAULT 0,
  `shepherdContactId` int NOT NULL DEFAULT 0,
  `managerContactId` int NOT NULL DEFAULT 0,
  `capVersion` int NOT NULL DEFAULT 0, # XXX obsolete
  # next 3 fields copied from PaperStorage to reduce joins
  `size` bigint NOT NULL DEFAULT -1,
  `mimetype` varbinary(80) NOT NULL DEFAULT '',
  `timestamp` bigint NOT NULL DEFAULT 0,
  `pdfFormatStatus` bigint NOT NULL DEFAULT 0,
  `withdrawReason` blob DEFAULT NULL,
  `paperFormat` tinyint DEFAULT NULL,
  `dataOverflow` longblob DEFAULT NULL,
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
  `paperId` int NOT NULL,
  `commentId` int NOT NULL AUTO_INCREMENT,
  `contactId` int NOT NULL,
  `timeModified` bigint NOT NULL,
  `timeNotified` bigint NOT NULL DEFAULT 0,
  `timeDisplayed` bigint NOT NULL DEFAULT 0,
  `comment` varbinary(32767) DEFAULT NULL,
  `commentType` int NOT NULL DEFAULT 0,
  `replyTo` int NOT NULL,
  `ordinal` int NOT NULL DEFAULT 0,
  `authorOrdinal` int NOT NULL DEFAULT 0,
  `commentTags` varbinary(1024) DEFAULT NULL,
  `commentRound` int NOT NULL DEFAULT 0,
  `commentFormat` tinyint DEFAULT NULL,
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
  `paperId` int NOT NULL,
  `contactId` int NOT NULL,
  `conflictType` tinyint NOT NULL DEFAULT 0,
  PRIMARY KEY (`contactId`,`paperId`),
  KEY `paperId` (`paperId`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;



--
-- Table structure for table `PaperOption`
--

DROP TABLE IF EXISTS `PaperOption`;
CREATE TABLE `PaperOption` (
  `paperId` int NOT NULL,
  `optionId` int NOT NULL,
  `value` bigint NOT NULL DEFAULT 0,
  `data` varbinary(32767) DEFAULT NULL,
  `dataOverflow` longblob DEFAULT NULL,
  PRIMARY KEY (`paperId`,`optionId`,`value`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;



--
-- Table structure for table `PaperReview`
--

DROP TABLE IF EXISTS `PaperReview`;
CREATE TABLE `PaperReview` (
  `paperId` int NOT NULL,
  `reviewId` int NOT NULL AUTO_INCREMENT,
  `contactId` int NOT NULL,
  `reviewType` tinyint NOT NULL,
  `requestedBy` int NOT NULL DEFAULT 0,
  `reviewToken` int NOT NULL DEFAULT 0,
  `reviewRound` int NOT NULL DEFAULT 0,
  `reviewOrdinal` int NOT NULL DEFAULT 0,
  `reviewBlind` tinyint NOT NULL,
  `reviewTime` bigint NOT NULL DEFAULT 0,
  `reviewModified` bigint NOT NULL DEFAULT 0,
  `reviewSubmitted` bigint DEFAULT NULL,
  `reviewAuthorSeen` bigint NOT NULL DEFAULT 0,
  `timeDisplayed` bigint NOT NULL DEFAULT 0,
  `timeApprovalRequested` bigint NOT NULL DEFAULT 0,
  `reviewNeedsSubmit` tinyint NOT NULL DEFAULT 1,
  `reviewViewScore` tinyint NOT NULL DEFAULT -3,
  `rflags` int NOT NULL,

  `timeRequested` bigint NOT NULL DEFAULT 0,
  `timeRequestNotified` bigint NOT NULL DEFAULT 0,
  `reviewAuthorModified` bigint DEFAULT NULL,
  `reviewNotified` bigint DEFAULT NULL,
  `reviewAuthorNotified` bigint NOT NULL DEFAULT 0,
  `reviewEditVersion` int NOT NULL DEFAULT 0,
  `reviewWordCount` int DEFAULT NULL,

  `s01` smallint NOT NULL DEFAULT 0,
  `s02` smallint NOT NULL DEFAULT 0,
  `s03` smallint NOT NULL DEFAULT 0,
  `s04` smallint NOT NULL DEFAULT 0,
  `s05` smallint NOT NULL DEFAULT 0,
  `s06` smallint NOT NULL DEFAULT 0,
  `s07` smallint NOT NULL DEFAULT 0,
  `s08` smallint NOT NULL DEFAULT 0,
  `s09` smallint NOT NULL DEFAULT 0,
  `s10` smallint NOT NULL DEFAULT 0,
  `s11` smallint NOT NULL DEFAULT 0,

  `tfields` longblob DEFAULT NULL,
  `sfields` varbinary(2048) DEFAULT NULL,

  PRIMARY KEY (`paperId`,`reviewId`),
  UNIQUE KEY `reviewId` (`reviewId`),
  KEY `contactIdReviewType` (`contactId`,`reviewType`),
  KEY `reviewType` (`reviewType`),
  KEY `reviewRound` (`reviewRound`),
  KEY `requestedBy` (`requestedBy`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;



--
-- Table structure for table `PaperReviewHistory`
--

DROP TABLE IF EXISTS `PaperReviewHistory`;
CREATE TABLE `PaperReviewHistory` (
  `paperId` int NOT NULL,
  `reviewId` int NOT NULL,
  `reviewTime` bigint NOT NULL,
  `reviewNextTime` bigint NOT NULL,
  `contactId` int NOT NULL,
  `reviewRound` int NOT NULL,
  `reviewOrdinal` int NOT NULL,
  `reviewType` tinyint NOT NULL,
  `reviewBlind` tinyint NOT NULL,
  `reviewModified` bigint NOT NULL,
  `reviewSubmitted` bigint NOT NULL,
  `timeDisplayed` bigint NOT NULL,
  `timeApprovalRequested` bigint NOT NULL,
  `reviewAuthorSeen` bigint NOT NULL,
  `reviewAuthorModified` bigint DEFAULT NULL,
  `reviewNotified` bigint DEFAULT NULL,
  `reviewAuthorNotified` bigint NOT NULL,
  `reviewEditVersion` int NOT NULL,
  `rflags` int NOT NULL,
  `revdelta` longblob DEFAULT NULL,
  PRIMARY KEY (`paperId`,`reviewId`,`reviewTime`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;



--
-- Table structure for table `PaperReviewPreference`
--

DROP TABLE IF EXISTS `PaperReviewPreference`;
CREATE TABLE `PaperReviewPreference` (
  `paperId` int NOT NULL,
  `contactId` int NOT NULL,
  `preference` int NOT NULL DEFAULT 0,
  `expertise` int DEFAULT NULL,
  PRIMARY KEY (`paperId`,`contactId`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;



--
-- Table structure for table `PaperReviewRefused`
--

DROP TABLE IF EXISTS `PaperReviewRefused`;
CREATE TABLE `PaperReviewRefused` (
  `paperId` int NOT NULL,
  `email` varchar(120) NOT NULL,
  `firstName` varbinary(120) DEFAULT NULL,
  `lastName` varbinary(120) DEFAULT NULL,
  `affiliation` varbinary(2048) DEFAULT NULL,
  `contactId` int NOT NULL,
  `refusedReviewId` int DEFAULT NULL,
  `refusedReviewType` tinyint NOT NULL DEFAULT 0,
  `reviewRound` int DEFAULT NULL,
  `requestedBy` int NOT NULL,
  `timeRequested` bigint DEFAULT NULL,
  `refusedBy` int DEFAULT NULL,
  `timeRefused` bigint DEFAULT NULL,
  `reason` varbinary(32767) DEFAULT NULL,
  PRIMARY KEY (`paperId`,`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;



--
-- Table structure for table `PaperStorage`
--

DROP TABLE IF EXISTS `PaperStorage`;
CREATE TABLE `PaperStorage` (
  `paperId` int NOT NULL,
  `paperStorageId` int NOT NULL AUTO_INCREMENT,
  `timestamp` bigint NOT NULL,
  `timeReferenced` bigint DEFAULT NULL,
  `mimetype` varbinary(80) NOT NULL DEFAULT '',
  `paper` longblob DEFAULT NULL,
  `compression` tinyint NOT NULL DEFAULT 0,
  `sha1` varbinary(64) NOT NULL DEFAULT '',
  `crc32` binary(4) DEFAULT NULL,
  `documentType` int NOT NULL DEFAULT 0,
  `filename` varbinary(255) DEFAULT NULL,
  `infoJson` varbinary(32768) DEFAULT NULL,
  `size` bigint NOT NULL DEFAULT -1,
  `filterType` int DEFAULT NULL,
  `originalStorageId` int DEFAULT NULL,
  `inactive` tinyint NOT NULL DEFAULT 0,
  `npages` int NOT NULL DEFAULT -1,
  `width` int NOT NULL DEFAULT -1,
  `height` int NOT NULL DEFAULT -1,
  PRIMARY KEY (`paperId`,`paperStorageId`),
  UNIQUE KEY `paperStorageId` (`paperStorageId`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;



--
-- Table structure for table `PaperTag`
--

DROP TABLE IF EXISTS `PaperTag`;
CREATE TABLE `PaperTag` (
  `paperId` int NOT NULL,
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
  `annoId` int NOT NULL,
  `tagIndex` float NOT NULL DEFAULT 0,
  `heading` varbinary(8192) DEFAULT NULL,
  `annoFormat` tinyint DEFAULT NULL,
  `infoJson` varbinary(32768) DEFAULT NULL,
  PRIMARY KEY (`tag`,`annoId`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;



--
-- Table structure for table `PaperTopic`
--

DROP TABLE IF EXISTS `PaperTopic`;
CREATE TABLE `PaperTopic` (
  `paperId` int NOT NULL,
  `topicId` int NOT NULL,
  PRIMARY KEY (`paperId`,`topicId`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;



--
-- Table structure for table `PaperWatch`
--

DROP TABLE IF EXISTS `PaperWatch`;
CREATE TABLE `PaperWatch` (
  `paperId` int NOT NULL,
  `contactId` int NOT NULL,
  `watch` int NOT NULL DEFAULT 0,
  PRIMARY KEY (`paperId`,`contactId`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;



--
-- Table structure for table `ReviewRating`
--

DROP TABLE IF EXISTS `ReviewRating`;
CREATE TABLE `ReviewRating` (
  `paperId` int NOT NULL,
  `reviewId` int NOT NULL,
  `contactId` int NOT NULL,
  `rating` tinyint NOT NULL DEFAULT 0,
  PRIMARY KEY (`paperId`,`reviewId`,`contactId`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;



--
-- Table structure for table `ReviewRequest`
--

DROP TABLE IF EXISTS `ReviewRequest`;
CREATE TABLE `ReviewRequest` (
  `paperId` int NOT NULL,
  `email` varchar(120) NOT NULL,
  `firstName` varbinary(120) DEFAULT NULL,
  `lastName` varbinary(120) DEFAULT NULL,
  `affiliation` varbinary(2048) DEFAULT NULL,
  `reason` varbinary(32767) DEFAULT NULL,
  `requestedBy` int NOT NULL,
  `timeRequested` bigint NOT NULL,
  `reviewRound` int DEFAULT NULL,
  PRIMARY KEY (`paperId`,`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;



--
-- Table structure for table `Settings`
--

DROP TABLE IF EXISTS `Settings`;
CREATE TABLE `Settings` (
  `name` varbinary(256) NOT NULL,
  `value` bigint NOT NULL,
  `data` varbinary(32767) DEFAULT NULL,
  PRIMARY KEY (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;



--
-- Table structure for table `TopicArea`
--

DROP TABLE IF EXISTS `TopicArea`;
CREATE TABLE `TopicArea` (
  `topicId` int NOT NULL AUTO_INCREMENT,
  `topicName` varbinary(1024) DEFAULT NULL,
  PRIMARY KEY (`topicId`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;



--
-- Table structure for table `TopicInterest`
--

DROP TABLE IF EXISTS `TopicInterest`;
CREATE TABLE `TopicInterest` (
  `contactId` int NOT NULL,
  `topicId` int NOT NULL,
  `interest` int NOT NULL,
  PRIMARY KEY (`contactId`,`topicId`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;




-- Initial settings
-- (each setting must be on its own line for createdb.php/createdb.sh)
insert into Settings (name, value, data) values
  ('allowPaperOption', 321, null),   -- schema version
  ('setupPhase', 1, null),           -- initial user is chair
  ('no_papersub', 1, null),          -- no submissions yet
  ('sub_pcconf', 1, null),           -- collect PC conflicts, not collaborators
  ('tag_chair', 1, 'accept pcpaper reject'),  -- default read-only tags
  ('pcrev_any', 1, null),            -- PC members can review any paper
  ('viewrevid', 1, null),            -- PC members can see anonymous reviewer IDs
  ('extrev_chairreq', 2, null),      -- administrators must approve potentially-conflicted reviewers
  ('pcrev_soft', 0, null)            -- soft review deadline is explicit 0
  ;

-- matches DocumentInfo::make_empty()
insert ignore into PaperStorage set
    paperStorageId=1, paperId=0, timestamp=0, mimetype='text/plain',
    paper='', sha1=x'da39a3ee5e6b4b0d3255bfef95601890afd80709',
    documentType=0, size=0, inactive=1;
