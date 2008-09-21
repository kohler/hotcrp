--
-- Table structure for table `ActionLog`
--

DROP TABLE IF EXISTS `ActionLog`;
CREATE TABLE `ActionLog` (
  `logId` int(11) NOT NULL auto_increment,
  `contactId` int(11) NOT NULL,
  `paperId` int(11) default NULL,
  `time` timestamp NOT NULL default CURRENT_TIMESTAMP,
  `ipaddr` varchar(16) default NULL,
  `action` text NOT NULL,
  PRIMARY KEY  (`logId`),
  UNIQUE KEY `logId` (`logId`),
  KEY `contactId` (`contactId`),
  KEY `paperId` (`paperId`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8;

--
-- Table structure for table `Chair`
--

DROP TABLE IF EXISTS `Chair`;
CREATE TABLE `Chair` (
  `contactId` int(11) NOT NULL,
  UNIQUE KEY `contactId` (`contactId`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8;

--
-- Table structure for table `ChairAssistant`
--

DROP TABLE IF EXISTS `ChairAssistant`;
CREATE TABLE `ChairAssistant` (
  `contactId` int(11) NOT NULL,
  UNIQUE KEY `contactId` (`contactId`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8;

--
-- Table structure for table `ContactAddress`
--

DROP TABLE IF EXISTS `ContactAddress`;
CREATE TABLE `ContactAddress` (
  `contactId` int(11) NOT NULL,
  `addressLine1` varchar(2048) NOT NULL,
  `addressLine2` varchar(2048) NOT NULL,
  `city` varchar(2048) NOT NULL,
  `state` varchar(2048) NOT NULL,
  `zipCode` varchar(2048) NOT NULL,
  `country` varchar(2048) NOT NULL,
  UNIQUE KEY `contactId` (`contactId`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8;

--
-- Table structure for table `ContactInfo`
--

DROP TABLE IF EXISTS `ContactInfo`;
CREATE TABLE `ContactInfo` (
  `contactId` int(11) NOT NULL auto_increment,
  `visits` int(11) NOT NULL default '0',
  `firstName` varchar(60) NOT NULL default '',
  `lastName` varchar(60) NOT NULL default '',
  `email` varchar(120) NOT NULL,
  `affiliation` varchar(2048) NOT NULL default '',
  `voicePhoneNumber` varchar(2048) NOT NULL default '',
  `faxPhoneNumber` varchar(2048) NOT NULL default '',
  `password` varchar(2048) NOT NULL,
  `note` mediumtext,
  `collaborators` mediumtext,
  `creationTime` int(11) NOT NULL default '0',
  `lastLogin` int(11) NOT NULL default '0',
  `defaultWatch` tinyint(1) NOT NULL default '2',
  `roles` tinyint(1) NOT NULL default '0',
  PRIMARY KEY  (`contactId`),
  UNIQUE KEY `contactId` (`contactId`),
  UNIQUE KEY `contactIdRoles` (`contactId`,`roles`),
  UNIQUE KEY `email` (`email`),
  KEY `fullName` (`lastName`,`firstName`,`email`),
  FULLTEXT KEY `name` (`lastName`,`firstName`,`email`),
  FULLTEXT KEY `affiliation` (`affiliation`),
  FULLTEXT KEY `email_3` (`email`),
  FULLTEXT KEY `firstName_2` (`firstName`),
  FULLTEXT KEY `lastName` (`lastName`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8;

--
-- Table structure for table `OptionType`
--

DROP TABLE IF EXISTS `OptionType`;
CREATE TABLE `OptionType` (
  `optionId` int(11) NOT NULL auto_increment,
  `optionName` varchar(200) NOT NULL,
  `description` text,
  `pcView` tinyint(1) NOT NULL default '1',
  `optionValues` text NOT NULL default '',
  PRIMARY KEY  (`optionId`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8;

--
-- Table structure for table `PCMember`
--

DROP TABLE IF EXISTS `PCMember`;
CREATE TABLE `PCMember` (
  `contactId` int(11) NOT NULL,
  UNIQUE KEY `contactId` (`contactId`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8;

--
-- Table structure for table `Paper`
--

DROP TABLE IF EXISTS `Paper`;
CREATE TABLE `Paper` (
  `paperId` int(11) NOT NULL auto_increment,
  `title` varchar(200) default NULL,
  `authorInformation` text,
  `abstract` text,
  `collaborators` text,
  `timeSubmitted` int(11) NOT NULL default '0',
  `timeWithdrawn` int(11) NOT NULL default '0',
  `timeFinalSubmitted` int(11) NOT NULL default '0',
  `pcPaper` tinyint(1) NOT NULL default '0',
  `paperStorageId` int(11) NOT NULL default '0',
  `sha1` varbinary(20) NOT NULL default '',
  `finalPaperStorageId` int(11) NOT NULL default '0',
  `blind` tinyint(1) NOT NULL default '1',
  `outcome` tinyint(1) NOT NULL default '0',
  `leadContactId` int(11) NOT NULL default '0',
  `shepherdContactId` int(11) NOT NULL default '0',
  # next 3 fields copied from PaperStorage to reduce joins
  `size` int(11) NOT NULL default '0',
  `mimetype` varchar(40) NOT NULL default '',
  `timestamp` int(11) NOT NULL default '0',
  # next 2 fields calculated from PaperComment to reduce joins
  `numComments` int(11) NOT NULL default '0',
  `numAuthorComments` int(11) NOT NULL default '0',
  PRIMARY KEY  (`paperId`),
  UNIQUE KEY `paperId` (`paperId`),
  KEY `title` (`title`),
  FULLTEXT KEY `titleAbstractText` (`title`,`abstract`),
  FULLTEXT KEY `allText` (`title`,`abstract`,`authorInformation`,`collaborators`),
  FULLTEXT KEY `authorText` (`authorInformation`,`collaborators`),
  KEY `timeSubmitted` (`timeSubmitted`),
  KEY `leadContactId` (`leadContactId`),
  KEY `shepherdContactId` (`shepherdContactId`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8;

--
-- Table structure for table `PaperComment`
--

DROP TABLE IF EXISTS `PaperComment`;
CREATE TABLE `PaperComment` (
  `commentId` int(11) NOT NULL auto_increment,
  `contactId` int(11) NOT NULL,
  `paperId` int(11) NOT NULL,
  `timeModified` int(11) NOT NULL,
  `timeNotified` int(11) NOT NULL default '0',
  `comment` mediumtext NOT NULL,
  `forReviewers` tinyint(1) NOT NULL default '0',
  `forAuthors` tinyint(1) NOT NULL default '0',
  `blind` tinyint(1) NOT NULL default '1',
  `replyTo` int(11) NOT NULL,
  PRIMARY KEY  (`commentId`),
  UNIQUE KEY `commentId` (`commentId`),
  KEY `contactId` (`contactId`),
  KEY `paperId` (`paperId`),
  KEY `contactPaper` (`contactId`,`paperId`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8;

--
-- Table structure for table `PaperConflict`
--

DROP TABLE IF EXISTS `PaperConflict`;
CREATE TABLE `PaperConflict` (
  `paperId` int(11) NOT NULL,
  `contactId` int(11) NOT NULL,
  `conflictType` tinyint(1) NOT NULL default '0',
  UNIQUE KEY `contactPaper` (`contactId`,`paperId`),
  UNIQUE KEY `contactPaperConflict` (`contactId`,`paperId`,`conflictType`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8;

--
-- Table structure for table `PaperOption`
--

DROP TABLE IF EXISTS `PaperOption`;
CREATE TABLE `PaperOption` (
  `paperId` int(11) NOT NULL,
  `optionId` int(11) NOT NULL,
  `value` int(11) NOT NULL default '0',
  UNIQUE KEY `paperOption` (`paperId`,`optionId`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8;

--
-- Table structure for table `PaperReview`
--

DROP TABLE IF EXISTS `PaperReview`;
CREATE TABLE `PaperReview` (
  `reviewId` int(11) NOT NULL auto_increment,
  `paperId` int(11) NOT NULL,
  `contactId` int(11) NOT NULL,
  `reviewToken` int(11) NOT NULL default '0',
  `reviewType` tinyint(1) NOT NULL default '0',
  `reviewRound` tinyint(1) NOT NULL default '0',
  `requestedBy` int(11) NOT NULL default '0',
  `requestedOn` timestamp NOT NULL default CURRENT_TIMESTAMP,
  `reviewBlind` tinyint(1) NOT NULL default '1',
  `reviewModified` int(1) default NULL,
  `reviewSubmitted` int(1) default NULL,
  `reviewOrdinal` int(1) default NULL,
  `reviewEditVersion` int(1) NOT NULL default '0',
  `reviewNeedsSubmit` tinyint(1) NOT NULL default '1',
  `overAllMerit` tinyint(1) NOT NULL default '0',
  `reviewerQualification` tinyint(1) NOT NULL default '0',
  `novelty` tinyint(1) NOT NULL default '0',
  `technicalMerit` tinyint(1) NOT NULL default '0',
  `interestToCommunity` tinyint(1) NOT NULL default '0',
  `longevity` tinyint(1) NOT NULL default '0',
  `grammar` tinyint(1) NOT NULL default '0',
  `likelyPresentation` tinyint(1) NOT NULL default '0',
  `suitableForShort` tinyint(1) NOT NULL default '0',
  `paperSummary` mediumtext NOT NULL,
  `commentsToAuthor` mediumtext NOT NULL,
  `commentsToPC` mediumtext NOT NULL,
  `commentsToAddress` mediumtext NOT NULL,
  `weaknessOfPaper` mediumtext NOT NULL,
  `strengthOfPaper` mediumtext NOT NULL,
  `potential` tinyint(4) NOT NULL default '0',
  `fixability` tinyint(4) NOT NULL default '0',
  `textField7` mediumtext NOT NULL,
  `textField8` mediumtext NOT NULL,
  PRIMARY KEY  (`reviewId`),
  UNIQUE KEY `reviewId` (`reviewId`),
  UNIQUE KEY `contactPaper` (`contactId`,`paperId`),
  KEY `paperId` (`paperId`,`reviewOrdinal`),
  KEY `reviewSubmitted` (`reviewSubmitted`),
  KEY `reviewNeedsSubmit` (`reviewNeedsSubmit`),
  KEY `reviewType` (`reviewType`),
  KEY `reviewRound` (`reviewRound`),
  KEY `requestedBy` (`requestedBy`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8;

--
-- Table structure for table `PaperReviewArchive`
--

DROP TABLE IF EXISTS `PaperReviewArchive`;
CREATE TABLE `PaperReviewArchive` (  
  `reviewArchiveId` int(11) NOT NULL auto_increment,
  `reviewId` int(11) NOT NULL,
  `paperId` int(11) NOT NULL,
  `contactId` int(11) NOT NULL,
  `reviewType` tinyint(1) NOT NULL default '0',
  `reviewRound` tinyint(1) NOT NULL default '0',
  `requestedBy` int(11) NOT NULL default '0',
  `requestedOn` timestamp NOT NULL default CURRENT_TIMESTAMP,
  `reviewBlind` tinyint(1) NOT NULL default '1',
  `reviewModified` int(1),
  `reviewSubmitted` int(1),
  `reviewOrdinal` int(1),
  `reviewNeedsSubmit` tinyint(1) NOT NULL default '1',
  `overAllMerit` tinyint(1) NOT NULL default '0',
  `reviewerQualification` tinyint(1) NOT NULL default '0',
  `novelty` tinyint(1) NOT NULL default '0',
  `technicalMerit` tinyint(1) NOT NULL default '0',
  `interestToCommunity` tinyint(1) NOT NULL default '0',
  `longevity` tinyint(1) NOT NULL default '0',
  `grammar` tinyint(1) NOT NULL default '0',
  `likelyPresentation` tinyint(1) NOT NULL default '0',
  `suitableForShort` tinyint(1) NOT NULL default '0',
  `paperSummary` mediumtext NOT NULL,
  `commentsToAuthor` mediumtext NOT NULL,
  `commentsToPC` mediumtext NOT NULL,
  `commentsToAddress` mediumtext NOT NULL,
  `weaknessOfPaper` mediumtext NOT NULL,
  `strengthOfPaper` mediumtext NOT NULL,
  `potential` tinyint(4) NOT NULL default '0',
  `fixability` tinyint(4) NOT NULL default '0',
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
) ENGINE=MyISAM DEFAULT CHARSET=utf8;

--
-- Table structure for table `PaperReviewPreference`
--

DROP TABLE IF EXISTS `PaperReviewPreference`;
CREATE TABLE `PaperReviewPreference` (
  `paperId` int(11) NOT NULL,
  `contactId` int(11) NOT NULL,
  `preference` int(4) NOT NULL default '0',
  UNIQUE KEY `contactPaper` (`contactId`,`paperId`),
  KEY `paperId` (`paperId`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8;

--
-- Table structure for table `PaperReviewRefused`
--

DROP TABLE IF EXISTS `PaperReviewRefused`;
CREATE TABLE `PaperReviewRefused` (
  `paperId` int(11) NOT NULL,
  `contactId` int(11) NOT NULL,
  `requestedBy` int(11) NOT NULL,
  `reason` text NOT NULL,
  KEY `paperId` (`paperId`),
  KEY `contactId` (`contactId`),
  KEY `requestedBy` (`requestedBy`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8;

--
-- Table structure for table `PaperStorage`
--

DROP TABLE IF EXISTS `PaperStorage`;
CREATE TABLE `PaperStorage` (
  `paperStorageId` int(11) NOT NULL auto_increment,
  `paperId` int(11) NOT NULL,
  `timestamp` int(11) NOT NULL,
  `mimetype` varchar(40) NOT NULL default '',
  `paper` longblob,
  `compression` tinyint(1) NOT NULL default '0',
  PRIMARY KEY  (`paperStorageId`),
  UNIQUE KEY `paperStorageId` (`paperStorageId`),
  KEY `paperId` (`paperId`),
  KEY `mimetype` (`mimetype`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8;

--
-- Table structure for table `PaperTag`
--

DROP TABLE IF EXISTS `PaperTag`;
CREATE TABLE `PaperTag` (
  `paperId` int(11) NOT NULL,
  `tag` varchar(40) NOT NULL,		# see TAG_MAXLEN in header.php
  `tagIndex` int(11) NOT NULL default '0',
  UNIQUE KEY `paperTag` (`paperId`,`tag`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8;

--
-- Table structure for table `PaperTopic`
--

DROP TABLE IF EXISTS `PaperTopic`;
CREATE TABLE `PaperTopic` (
  `topicId` int(11) NOT NULL,
  `paperId` int(11) NOT NULL,
  UNIQUE KEY `paperTopic` (`paperId`,`topicId`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8;

--
-- Table structure for table `PaperWatch`
--

DROP TABLE IF EXISTS `PaperWatch`;
CREATE TABLE `PaperWatch` (
  `paperId` int(11) NOT NULL,
  `contactId` int(11) NOT NULL,
  `watch` tinyint(1) NOT NULL default '0',
  UNIQUE KEY `contactPaper` (`contactId`,`paperId`),
  UNIQUE KEY `contactPaperWatch` (`contactId`,`paperId`,`watch`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8;

--
-- Table structure for table `ReviewFormField`
--

DROP TABLE IF EXISTS `ReviewFormField`;
CREATE TABLE `ReviewFormField` (
  `fieldName` varchar(25) NOT NULL,
  `shortName` varchar(40) NOT NULL,
  `description` text,
  `sortOrder` tinyint(1) NOT NULL default '-1',
  `rows` tinyint(1) NOT NULL default '0',
  `authorView` tinyint(1) NOT NULL default '1',
  `levelChar` tinyint(1) NOT NULL default '0',
  PRIMARY KEY  (`fieldName`),
  UNIQUE KEY `fieldName` (`fieldName`),
  KEY `shortName` (`shortName`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8;

--
-- Table structure for table `ReviewFormOptions`
--

DROP TABLE IF EXISTS `ReviewFormOptions`;
CREATE TABLE `ReviewFormOptions` (
  `fieldName` varchar(25) NOT NULL,
  `level` tinyint(1) NOT NULL,
  `description` text,
  KEY `fieldName` (`fieldName`),
  KEY `level` (`level`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8;

--
-- Table structure for table `ReviewRating`
--

DROP TABLE IF EXISTS `ReviewRating`;
CREATE TABLE `ReviewRating` (
  `reviewId` int(11) NOT NULL,
  `contactId` int(11) NOT NULL,
  `rating` tinyint(1) NOT NULL default '0',
  UNIQUE KEY `reviewContact` (`reviewId`,`contactId`),
  UNIQUE KEY `reviewContactRating` (`reviewId`,`contactId`,`rating`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8;

--
-- Table structure for table `ReviewRequest`
--

DROP TABLE IF EXISTS `ReviewRequest`;
CREATE TABLE `ReviewRequest` (
  `paperId` int(11) NOT NULL,
  `name` varchar(120) default NULL,
  `email` varchar(120) default NULL,
  `reason` text,
  `requestedBy` int(11) NOT NULL,
  KEY `paperId` (`paperId`),
  UNIQUE KEY `paperEmail` (`paperId`,`email`),
  KEY `requestedBy` (`requestedBy`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8;

--
-- Table structure for table `Settings`
--

DROP TABLE IF EXISTS `Settings`;
CREATE TABLE `Settings` (
  `name` char(40) NOT NULL,
  `value` int(11) NOT NULL,
  `data` text, 
  UNIQUE KEY `name` (`name`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8;

--
-- Table structure for table `TopicArea`
--

DROP TABLE IF EXISTS `TopicArea`;
CREATE TABLE `TopicArea` (
  `topicId` int(11) NOT NULL auto_increment,
  `topicName` varchar(80) default NULL,
  PRIMARY KEY  (`topicId`),
  UNIQUE KEY `topicId` (`topicId`),
  KEY `topicName` (`topicName`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8;

--
-- Table structure for table `TopicInterest`
--

DROP TABLE IF EXISTS `TopicInterest`;
CREATE TABLE `TopicInterest` (
  `contactId` int(11) NOT NULL,
  `topicId` int(11) NOT NULL,
  `interest` int(1) default NULL,
  UNIQUE KEY `contactTopic` (`contactId`,`topicId`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8;


delete from Settings where name='setupPhase';
insert into Settings (name, value) values ('setupPhase', 1);
insert into Settings (name, value) values ('allowPaperOption', 21);
# collect PC conflicts from authors by default, but not collaborators
insert into Settings (name, value) values ('sub_pcconf', 1);
# default chair-only tags
insert into Settings (name, value, data) values ('tag_chair', 1, 'accept reject pcpaper');
# turn on SHA-1 calculation by default
insert into Settings (name, value) values ('sub_sha1', 1);

insert into PaperStorage set paperStorageId=1, paperId=0, timestamp=0, mimetype='text/plain', paper='' on duplicate key update paper='';


insert into ReviewFormField set fieldName='overAllMerit',
	shortName='Overall merit', sortOrder=0;
insert into ReviewFormField set fieldName='reviewerQualification',
	shortName='Reviewer expertise', sortOrder=1;
insert into ReviewFormField set fieldName='novelty',
	shortName='Novelty';
insert into ReviewFormField set fieldName='technicalMerit',
	shortName='Technical merit';
insert into ReviewFormField set fieldName='interestToCommunity',
	shortName='Community interest';
insert into ReviewFormField set fieldName='longevity',
	shortName='Longevity', description='How important will this work be over time?';
insert into ReviewFormField set fieldName='grammar',
	shortName='Writing';
insert into ReviewFormField set fieldName='suitableForShort',
	shortName='Suitable for short paper';
insert into ReviewFormField set fieldName='paperSummary',
	shortName='Paper summary', sortOrder=2, rows=5;
insert into ReviewFormField set fieldName='commentsToAuthor',
	shortName='Comments for author', sortOrder=3, rows=15;
insert into ReviewFormField set fieldName='commentsToPC',
	shortName='Comments for PC', sortOrder=4, rows=10, authorView=0;
insert into ReviewFormField set fieldName='commentsToAddress',
	shortName='Comments to address in the response', rows=10;
insert into ReviewFormField set fieldName='weaknessOfPaper',
	shortName='Paper weakness', rows=5;
insert into ReviewFormField set fieldName='strengthOfPaper',
	shortName='Paper strengths', rows=5;
insert into ReviewFormField set fieldName='likelyPresentation',
	shortName='Additional score field';
insert into ReviewFormField set fieldName='potential',
	shortName='Additional score field';
insert into ReviewFormField set fieldName='fixability',
	shortName='Additional score field';
insert into ReviewFormField set fieldName='textField7',
	shortName='Additional text field';
insert into ReviewFormField set fieldName='textField8',
	shortName='Additional text field';

insert into ReviewFormOptions set fieldName='overAllMerit', level=1, description='Reject';
insert into ReviewFormOptions set fieldName='overAllMerit', level=2, description='Weak reject';
insert into ReviewFormOptions set fieldName='overAllMerit', level=3, description='Weak accept';
insert into ReviewFormOptions set fieldName='overAllMerit', level=4, description='Accept';
insert into ReviewFormOptions set fieldName='overAllMerit', level=5, description='Strong accept';

insert into ReviewFormOptions set fieldName='reviewerQualification', level=1, description='No familiarity';
insert into ReviewFormOptions set fieldName='reviewerQualification', level=2, description='Some familiarity';
insert into ReviewFormOptions set fieldName='reviewerQualification', level=3, description='Knowledgeable';
insert into ReviewFormOptions set fieldName='reviewerQualification', level=4, description='Expert';

insert into ReviewFormOptions set fieldName='novelty', level=1, description='Published before';
insert into ReviewFormOptions set fieldName='novelty', level=2, description='Done before (not necessarily published)';
insert into ReviewFormOptions set fieldName='novelty', level=3, description='Incremental improvement';
insert into ReviewFormOptions set fieldName='novelty', level=4, description='New contribution';
insert into ReviewFormOptions set fieldName='novelty', level=5, description='Surprisingly new contribution';

insert into ReviewFormOptions set fieldName='technicalMerit', level=1, description='Poor';
insert into ReviewFormOptions set fieldName='technicalMerit', level=2, description='Fair';
insert into ReviewFormOptions set fieldName='technicalMerit', level=3, description='Average';
insert into ReviewFormOptions set fieldName='technicalMerit', level=4, description='Good';
insert into ReviewFormOptions set fieldName='technicalMerit', level=5, description='Excellent';

insert into ReviewFormOptions set fieldName='interestToCommunity', level=1, description='None';
insert into ReviewFormOptions set fieldName='interestToCommunity', level=2, description='Low';
insert into ReviewFormOptions set fieldName='interestToCommunity', level=3, description='Average';
insert into ReviewFormOptions set fieldName='interestToCommunity', level=4, description='High';
insert into ReviewFormOptions set fieldName='interestToCommunity', level=5, description='Exciting';

insert into ReviewFormOptions set fieldName='longevity', level=1, description='Not important now or later';
insert into ReviewFormOptions set fieldName='longevity', level=2, description='Low importance';
insert into ReviewFormOptions set fieldName='longevity', level=3, description='Average importance';
insert into ReviewFormOptions set fieldName='longevity', level=4, description='Important';
insert into ReviewFormOptions set fieldName='longevity', level=5, description='Exciting';

insert into ReviewFormOptions set fieldName='grammar', level=1, description='Poor';
insert into ReviewFormOptions set fieldName='grammar', level=2, description='Fair';
insert into ReviewFormOptions set fieldName='grammar', level=3, description='Average';
insert into ReviewFormOptions set fieldName='grammar', level=4, description='Good';
insert into ReviewFormOptions set fieldName='grammar', level=5, description='Excellent';

insert into ReviewFormOptions set fieldName='suitableForShort', level=1, description='Not suitable';
insert into ReviewFormOptions set fieldName='suitableForShort', level=2, description='Can\'t tell';
insert into ReviewFormOptions set fieldName='suitableForShort', level=3, description='Suitable';

insert into ReviewFormOptions set fieldName='outcome', level=0, description='Unspecified';
insert into ReviewFormOptions set fieldName='outcome', level=-1, description='Rejected';
insert into ReviewFormOptions set fieldName='outcome', level=1, description='Accepted as short paper';
insert into ReviewFormOptions set fieldName='outcome', level=2, description='Accepted';

delete from Settings where name='revform_update';
insert into Settings set name='revform_update', value=unix_timestamp(current_timestamp);
