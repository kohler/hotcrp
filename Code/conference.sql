# MySQL dump 8.14
#
# Host: 127.0.0.1    Database: OSDI2002
#--------------------------------------------------------
# Server version	3.23.41-log

#
# Table structure for table 'ActionLog'
#

drop table if exists ActionLog;
CREATE TABLE ActionLog (
  logId int(11) NOT NULL auto_increment,
  contactId int(11) default NULL,
  paperId int(11) default NULL,
  time timestamp(14) NOT NULL,
  ipaddr varchar(16) default NULL,
  action varchar(120) default NULL,
  PRIMARY KEY  (logId),
  UNIQUE KEY logId (logId),
  KEY contactId (contactId),
  KEY paperId (paperId)
) TYPE=MyISAM;

#
# Dumping data for table 'ActionLog'
#



#
# Table structure for table 'ContactInfo'
#

drop table if exists ContactInfo;
CREATE TABLE ContactInfo (
  contactId int(11) NOT NULL auto_increment,
  visits int(11) default '0',
  firstName varchar(60) default NULL,
  lastName varchar(60) default NULL,
  email varchar(120) default NULL,
  affiliation varchar(200) default NULL,
  voicePhoneNumber varchar(24) default NULL,
  faxPhoneNumber varchar(24) default NULL,
  password varchar(32) default NULL,
  note varchar(200) default NULL,
  collaborators text,
  PRIMARY KEY (contactId),
  UNIQUE KEY contactId (contactId),
  UNIQUE KEY email (email),
  KEY email_2 (email),
  FULLTEXT KEY firstName (firstName,lastName,email),
  FULLTEXT KEY affiliation (affiliation),
  FULLTEXT KEY email_3 (email),
  FULLTEXT KEY firstName_2 (firstName),
  FULLTEXT KEY lastName (lastName)
) TYPE=MyISAM;

#
# Dumping data for table 'ContactInfo'
#


#
# Table structure for table 'PCMember'
#

drop table if exists PCMember;
CREATE TABLE PCMember (
  contactId int(11) NOT NULL,
  UNIQUE KEY contactId (contactId)
) TYPE=MyISAM;

#
# Dumping data for table 'PCMember'
#


#
# Table structure for table 'Chair'
#

drop table if exists Chair;
CREATE TABLE Chair (
  contactId int(11) NOT NULL,
  UNIQUE KEY contactId (contactId)
) TYPE=MyISAM;

#
# Dumping data for table 'Chair'
#


#
# Table structure for table 'ChairAssistant'
#

drop table if exists ChairAssistant;
CREATE TABLE ChairAssistant (
  contactId int(11) NOT NULL,
  UNIQUE KEY contactId (contactId)
) TYPE=MyISAM;

#
# Dumping data for table 'ChairAssistant'
#


#
# Table structure for table 'ImportantDates'
#

drop table if exists ImportantDates;
CREATE TABLE ImportantDates (
  name char(40) NOT NULL default '',
  start timestamp(14) NOT NULL,
  end timestamp(14) NOT NULL,
  KEY name (name)
) TYPE=MyISAM;

#
# Dumping data for table 'ImportantDates'
#


#
# Table structure for table 'Paper'
#

drop table if exists Paper;
CREATE TABLE Paper (
  paperId int(11) NOT NULL auto_increment,
  title varchar(200) default NULL,
  authorInformation text,
  abstract text,
  collaborators text,
  contactId int(11) default NULL,
  submitted timestamp(14) NOT NULL,
  acknowledged int(11) NOT NULL default '0',
  withdrawn int(11) NOT NULL default '0',
  pcPaper int(11) default '0',
  paperStorageId int(11) NOT NULL default '0',
  authorsResponse mediumtext,
  outcome enum('unspecified','accepted','rejected','acceptedShort') default 'unspecified',
  showReviewsToReviewers tinyint(1) default '0',
  showResponseToReviewers tinyint(1) default '0',
  PRIMARY KEY (paperId),
  UNIQUE KEY paperId (paperId),
  KEY title (title),
  KEY contactId (contactId),
  FULLTEXT KEY abstract (abstract,authorInformation)
) TYPE=MyISAM;

#
# Dumping data for table 'Paper'
#


#
# Table structure for table 'PaperComments'
#

drop table if exists PaperComments;
CREATE TABLE PaperComments (
  commentId int(11) NOT NULL auto_increment,
  contactId int(11) default NULL,
  paperId int(11) default NULL,
  time timestamp(14) NOT NULL,
  comment text,
  forReviewers int(11) default '0',
  forAuthor int(11) default '0',
  PRIMARY KEY  (commentId),
  UNIQUE KEY commentId (commentId),
  KEY contactId (contactId),
  KEY paperId (paperId)
) TYPE=MyISAM;

#
# Dumping data for table 'PaperComments'
#


#
# Table structure for table 'PaperConflict'
#

drop table if exists PaperConflict;
CREATE TABLE PaperConflict (
  paperId int(11) NOT NULL,
  contactId int(11) NOT NULL,
  author tinyint(1) NOT NULL default '0',
  KEY paperId (paperId),
  KEY contactId (contactId)
) TYPE=MyISAM;

#
# Dumping data for table 'PaperConflict'
#


#
# Table structure for table 'PaperGrade'
#

drop table if exists PaperGrade;
CREATE TABLE PaperGrade (
  gradeId int(11) NOT NULL auto_increment,
  contactId int(11) default NULL,
  paperId int(11) default NULL,
  time timestamp(14) NOT NULL,
  grade int(11) default NULL,
  PRIMARY KEY (gradeId),
  UNIQUE KEY gradeId (gradeId),
  KEY contactId (contactId),
  KEY paperId (paperId)
) TYPE=MyISAM;

#
# Dumping data for table 'PaperGrade'
#


#
# Table structure for table 'ReviewRequest'
#

drop table if exists ReviewRequest;
CREATE TABLE ReviewRequest (
  contactId int(11) NOT NULL,
  paperId int(11) NOT NULL,
  type tinyint(1) NOT NULL default '0',

  requestedBy int(11) NOT NULL default 0,
  requestMadeOn timestamp(14) NOT NULL,
  acceptedOn timestamp(14) NOT NULL default 0,

  KEY requestedBy (requestedBy),
  KEY contactId (contactId),
  KEY paperId (paperId),
  KEY type (type)
) TYPE=MyISAM;

#
# Dumping data for table 'ReviewRequest'
#


#
# Table structure for table 'PaperReview'
#

drop table if exists PaperReview;
CREATE TABLE PaperReview (
  paperReviewId int(11) NOT NULL auto_increment,

  paperId int(11) NOT NULL,
  contactId int(11) NOT NULL,
  lastModified timestamp(14) NOT NULL,
  finalized tinyint(1) NOT NULL default 0,

  overAllMerit tinyint(1) NOT NULL default '0',
  reviewerQualification tinyint(1) NOT NULL default '0',
  novelty tinyint(1) NOT NULL default '0',
  technicalMerit tinyint(1) NOT NULL default '0',
  interestToCommunity tinyint(1) NOT NULL default '0',
  longevity tinyint(1) NOT NULL default '0',
  grammar tinyint(1) NOT NULL default '0',
  likelyPresentation tinyint(1) NOT NULL default '0',
  suitableForShort tinyint(1) NOT NULL default '0',
  paperSummary text,
  commentsToAuthor text,
  commentsToPC text,
  commentsToAddress text,
  weaknessOfPaper text,
  strengthOfPaper text,

  potential tinyint(4) NOT NULL default '0',
  fixability tinyint(4) NOT NULL default '0',

  PRIMARY KEY (paperReviewId),
  UNIQUE KEY paperReviewid (paperReviewId),
  KEY paperId (paperId),
  KEY contactId (contactId),
  KEY finalized (finalized)
) TYPE=MyISAM;

#
# Dumping data for table 'PaperReview'
#


#
# Table structure for table 'PaperStorage'
#

drop table if exists PaperStorage;
CREATE TABLE PaperStorage (
  paperStorageId int(11) NOT NULL auto_increment,
  paperId int(11) NOT NULL,
  timestamp int(11) NOT NULL,
  mimetype varchar(120) NOT NULL default '',
  paper longblob,
  PRIMARY KEY (paperStorageId),
  UNIQUE KEY paperStorageId (paperStorageId),
  KEY paperId (paperId),
  KEY mimetype (mimetype)
) TYPE=MyISAM;

insert into PaperStorage set paperId=0, timestamp=0, mimetype='text/plain', paper='';

#
# Dumping data for table 'PaperStorage'
#


#
# Table structure for table 'PrimaryReviewer'
#

drop table if exists PrimaryReviewer;
CREATE TABLE PrimaryReviewer (
  primaryReviewerId int(11) NOT NULL auto_increment,
  contactId int(11) default NULL,
  paperId int(11) default NULL,
  requestMadeOn timestamp(14) NOT NULL,
  PRIMARY KEY (primaryReviewerId),
  UNIQUE KEY primaryReviewerId (primaryReviewerId),
  KEY contactId (contactId),
  KEY paperId (paperId)
) TYPE=MyISAM;

#
# Dumping data for table 'PrimaryReviewer'
#


#
# Table structure for table 'SecondaryReviewer'
#

drop table if exists SecondaryReviewer;
CREATE TABLE SecondaryReviewer (
  secondaryReviewerId int(11) NOT NULL auto_increment,
  contactId int(11) default NULL,
  paperId int(11) default NULL,
  requestMadeOn timestamp(14) NOT NULL,
  PRIMARY KEY (secondaryReviewerId),
  UNIQUE KEY secondaryReviewerId (secondaryReviewerId),
  KEY contactId (contactId),
  KEY paperId (paperId)
) TYPE=MyISAM;

#
# Dumping data for table 'SecondaryReviewer'
#


drop table if exists PaperReviewerPreference;
CREATE TABLE PaperReviewerPreference(
  preferenceId int(11) NOT NULL auto_increment,
  paperId int(11) default NULL,
  contactId int(11) default NULL,
  PRIMARY KEY (preferenceId),
  UNIQUE KEY preferenceId (preferenceId)
) TYPE=MyISAM;

drop table if exists PaperReviewerConflict;
CREATE TABLE PaperReviewerConflict(
  conflictId int(11) NOT NULL auto_increment,
  paperId int(11) default NULL,
  contactId int(11) default NULL,
  PRIMARY KEY (conflictId),
  UNIQUE KEY conflictId (conflictId)
) TYPE=MyISAM;




#
# Table structure for table 'TopicArea'
#

drop table if exists TopicArea;
CREATE TABLE TopicArea (
  topicId int(11) NOT NULL auto_increment,
  topicName varchar(80) default NULL,
  PRIMARY KEY (topicId),
  UNIQUE KEY topicId (topicId),
  KEY topicName (topicName)
) TYPE=MyISAM;

#
# Dumping data for table 'TopicArea'
#


#
# Table structure for table 'PaperTopic'
#

drop table if exists PaperTopic;
CREATE TABLE PaperTopic (
  topicId int(11) default NULL,
  paperId int(11) default NULL,
  KEY topicId (topicId),
  KEY paperId (paperId)
) TYPE=MyISAM;

#
# Dumping data for table 'PaperTopic'
#


#
# Table structure for table 'TopicInterest'
#

drop table if exists TopicInterest;
CREATE TABLE TopicInterest (
  contactId int(11) NOT NULL,
  topicId int(11) NOT NULL,
  interest int(1),
  KEY contactId (contactId),
  KEY topicId (topicId)
) TYPE=MyISAM;

#
# Dumping data for table 'TopicInterest'
#


#
# Review form
#

drop table if exists ReviewFormField;
create table ReviewFormField (
  fieldName varchar(25) NOT NULL,
  shortName varchar(40) NOT NULL,
  description text,
  sortOrder tinyint(1) NOT NULL default -1,
  rows tinyint(1) NOT NULL default 0,
  PRIMARY KEY (fieldName),
  UNIQUE KEY fieldName (fieldName),
  KEY shortName (shortName)
) TYPE=MyISAM;

drop table if exists ReviewFormOptions;
create table ReviewFormOptions (
  fieldName varchar(25) NOT NULL,
  level tinyint(1) NOT NULL,
  description text,
  KEY fieldName (fieldName),
  KEY level (level)
) TYPE=MyISAM;

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
	shortName='Longevity';
insert into ReviewFormField set fieldName='grammar',
	shortName='Grammar';
insert into ReviewFormField set fieldName='likelyPresentation',
	shortName='Attendance likelihood';
insert into ReviewFormField set fieldName='suitableForShort',
	shortName='Suitable for short paper';
insert into ReviewFormField set fieldName='paperSummary',
	shortName='Paper summary', sortOrder=2, rows=5;
insert into ReviewFormField set fieldName='commentsToAuthor',
	shortName='Comments for author', sortOrder=3, rows=15;
insert into ReviewFormField set fieldName='commentsToPC',
	shortName='Comments for PC', sortOrder=4, rows=10;
insert into ReviewFormField set fieldName='commentsToAddress',
	shortName='Comments to address', rows=10;
insert into ReviewFormField set fieldName='weaknessOfPaper',
	shortName='Paper weakness', rows=5;
insert into ReviewFormField set fieldName='strengthOfPaper',
	shortName='Paper strengths', rows=5;
insert into ReviewFormField set fieldName='potential',
	shortName='Potential';
insert into ReviewFormField set fieldName='fixability',
	shortName='Fixability';

insert into ReviewFormOptions set fieldName='overAllMerit', level=1, description='Reject';
insert into ReviewFormOptions set fieldName='overAllMerit', level=2, description='Weak reject';
insert into ReviewFormOptions set fieldName='overAllMerit', level=3, description='Weak accept';
insert into ReviewFormOptions set fieldName='overAllMerit', level=4, description='Accept';
insert into ReviewFormOptions set fieldName='overAllMerit', level=5, description='Strong accept';

insert into ReviewFormOptions set fieldName='reviewerQualification', level=1, description='No familiarity';
insert into ReviewFormOptions set fieldName='reviewerQualification', level=2, description='Some familiarity';
insert into ReviewFormOptions set fieldName='reviewerQualification', level=3, description='Knowledgeable';
insert into ReviewFormOptions set fieldName='reviewerQualification', level=4, description='Expert';

delete from ImportantDates where name='reviewFormUpdate';
insert into ImportantDates set name='reviewFormUpdate', start=current_timestamp, end=current_timestamp;


#
# Paper lists
#

drop table if exists PaperList;
create table PaperList (
  paperListId int(11) NOT NULL auto_increment,
  paperListName varchar(20) NOT NULL,
  shortDescription varchar(40) NOT NULL default '',
  description varchar(80) NOT NULL default '',
  minRole tinyint(1) NOT NULL default '0',
  sortCol int,
  query varchar(120),
  PRIMARY KEY (paperListId),
  UNIQUE KEY paperListId (paperListId),
  KEY paperListName (paperListName)
) TYPE=MyISAM;

drop table if exists PaperFields;
create table PaperFields (
  fieldId int(11) NOT NULL,
  fieldName varchar(20),
  description varchar(80),
  sortable tinyint(1) default '1',
  display tinyint(1) default '1',
  PRIMARY KEY (fieldId),
  UNIQUE KEY fieldId (fieldId)
) TYPE=MyISAM;

drop table if exists PaperListColumns;
create table PaperListColumns (
  paperListId int(11) NOT NULL,
  fieldId int(11) NOT NULL,
  col int(3) NOT NULL,
  KEY paperListId (paperListId)
) TYPE=MyISAM;

insert into PaperFields set fieldId=1, fieldName='ID', description='ID';
insert into PaperFields set fieldId=2, fieldName='Title', description='Title';
insert into PaperFields set fieldId=3, fieldName='Status', description='Status';
insert into PaperFields set fieldId=4, fieldName='Download', description='Download', sortable=0;
insert into PaperFields set fieldId=5, fieldName='ID (manage)', description='ID (manage link)';
insert into PaperFields set fieldId=6, fieldName='Title (manage)', description='Title (manage link)';
insert into PaperFields set fieldId=7, fieldName='ID (review)', description='ID (review link)';
insert into PaperFields set fieldId=8, fieldName='Title (review)', description='Title (review link)';
insert into PaperFields set fieldId=9, fieldName='Reviewer', description='Reviewer type';
insert into PaperFields set fieldId=10, fieldName='Reviewer status', description='Reviewer status';
insert into PaperFields set fieldId=11, fieldName='Selector', description='Selector';
insert into PaperFields set fieldId=12, fieldName='Review', description='Review';

insert into PaperList set paperListId=1, paperListName='author',
	shortDescription='Authored', description='Authored papers', 
	minRole=1, sortCol=0, query='';
insert into PaperListColumns set paperListId=1, fieldId=5, col=0;
insert into PaperListColumns set paperListId=1, fieldId=6, col=1;
insert into PaperListColumns set paperListId=1, fieldId=3, col=2;
insert into PaperListColumns set paperListId=1, fieldId=4, col=3;

insert into PaperList set paperListId=2, paperListName='submitted',
	shortDescription='Submitted', description='Submitted papers',
	minRole=3, sortCol=0, query='';
insert into PaperListColumns set paperListId=2, fieldId=11, col=0;
insert into PaperListColumns set paperListId=2, fieldId=1, col=1;
insert into PaperListColumns set paperListId=2, fieldId=2, col=2;
insert into PaperListColumns set paperListId=2, fieldId=3, col=3;
insert into PaperListColumns set paperListId=2, fieldId=4, col=4;
insert into PaperListColumns set paperListId=2, fieldId=9, col=5;
insert into PaperListColumns set paperListId=2, fieldId=10, col=6;
insert into PaperListColumns set paperListId=2, fieldId=12, col=7;

insert into PaperList set paperListId=3, paperListName='all',
	shortDescription='All', description='All papers', 
	minRole=5, sortCol=0, query='';
insert into PaperListColumns set paperListId=3, fieldId=11, col=0;
insert into PaperListColumns set paperListId=3, fieldId=1, col=1;
insert into PaperListColumns set paperListId=3, fieldId=2, col=2;
insert into PaperListColumns set paperListId=3, fieldId=3, col=3;
insert into PaperListColumns set paperListId=3, fieldId=4, col=4;
insert into PaperListColumns set paperListId=3, fieldId=9, col=5;
insert into PaperListColumns set paperListId=3, fieldId=10, col=6;
insert into PaperListColumns set paperListId=3, fieldId=12, col=7;

insert into PaperList set paperListId=4, paperListName='authorHome',
	description='My papers (homepage view)', minRole=1, sortCol=0, query='';
insert into PaperListColumns set paperListId=4, fieldId=5, col=0;
insert into PaperListColumns set paperListId=4, fieldId=6, col=1;
insert into PaperListColumns set paperListId=4, fieldId=3, col=2;

insert into PaperList set paperListId=5, paperListName='reviewerHome',
	description='Papers to review (homepage view)',
	minRole=100, sortCol=0, query='';
insert into PaperListColumns set paperListId=5, fieldId=7, col=0;
insert into PaperListColumns set paperListId=5, fieldId=8, col=1;
insert into PaperListColumns set paperListId=5, fieldId=9, col=2;
insert into PaperListColumns set paperListId=5, fieldId=10, col=3;
