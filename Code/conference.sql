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
  visits int(11) NOT NULL default 0,
  firstName varchar(60) default NULL,
  lastName varchar(60) default NULL,
  email varchar(120) default NULL,
  affiliation varchar(200) default NULL,
  voicePhoneNumber varchar(24) default NULL,
  faxPhoneNumber varchar(24) default NULL,
  password varchar(32) default NULL,
  note varchar(200) default NULL,
  collaborators text,
  lastLogin int(11) NOT NULL default 0,
  PRIMARY KEY (contactId),
  UNIQUE KEY contactId (contactId),
  UNIQUE KEY email (email),
  KEY fullName (lastName,firstName,email),
  FULLTEXT KEY name (lastName,firstName,email),
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
  name char(40) NOT NULL,
  start timestamp(14) NOT NULL,
  end timestamp(14) NOT NULL default 0,
  UNIQUE KEY name (name)
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

  timeSubmitted int(11) NOT NULL default 0,
  timeWithdrawn int(11) NOT NULL default 0,
  pcPaper tinyint(11) NOT NULL default 0,

  paperStorageId int(11) NOT NULL default 0,
  # copied from PaperStorage to reduce joins
  size int(11) NOT NULL default 0,
  mimetype varchar(40) NOT NULL default '',
  timestamp int(11) NOT NULL default 0,

  blind tinyint(1) NOT NULL default 1,
  authorsResponse mediumtext,
  outcome tinyint(1) NOT NULL default 0,
  showReviewsToReviewers tinyint(1) NOT NULL default 0,
  showResponseToReviewers tinyint(1) NOT NULL default 0,

  # calculated from PaperComment to reduce joins
  numComments int(11) NOT NULL default 0,
  numAuthorComments int(11) NOT NULL default 0,

  PRIMARY KEY (paperId),
  UNIQUE KEY paperId (paperId),
  KEY title (title),
  KEY contactId (contactId),
  FULLTEXT KEY titleAbstractText (title,abstract),
  FULLTEXT KEY allText (title,abstract,authorInformation,collaborators),
  FULLTEXT KEY authorText (authorInformation,collaborators)
) TYPE=MyISAM;

#
# Dumping data for table 'Paper'
#


#
# Table structure for table 'PaperComment'
#

drop table if exists PaperComment;
CREATE TABLE PaperComment (
  commentId int(11) NOT NULL auto_increment,
  contactId int(11) NOT NULL,
  paperId int(11) NOT NULL,
  timeModified int(11) NOT NULL,
  comment text NOT NULL default '',
  forReviewers tinyint(1) NOT NULL default 0,
  forAuthors tinyint(1) NOT NULL default 0,
  blind tinyint(1) NOT NULL default 1,
  PRIMARY KEY (commentId),
  UNIQUE KEY commentId (commentId),
  KEY contactId (contactId),
  KEY paperId (paperId)
) TYPE=MyISAM;

#
# Dumping data for table 'PaperComment'
#


#
# Table structure for table 'PaperTag'
#

drop table if exists PaperTag;
CREATE TABLE PaperTag (
  paperId int(11) NOT NULL,
  tag varchar(40) NOT NULL,
  UNIQUE KEY paperTag (paperId,tag)
) TYPE=MyISAM;

#
# Dumping data for table 'PaperTag'
#


#
# Table structure for table 'PaperConflict'
#

drop table if exists PaperConflict;
CREATE TABLE PaperConflict (
  paperId int(11) NOT NULL,
  contactId int(11) NOT NULL,
  author tinyint(1) NOT NULL default 0,
  UNIQUE KEY contactPaper (contactId,paperId),
  UNIQUE KEY contactPaperAuthor (contactId,paperId,author)
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
  paperId int(11) NOT NULL,
  contactId int(11) NOT NULL,
  reviewType tinyint(1) NOT NULL default '0',

  requestedBy int(11) NOT NULL default 0,
  requestMadeOn timestamp(14) NOT NULL,
  acceptedOn timestamp(14) NOT NULL default 0,

  KEY paperId (paperId),
  KEY contactId (contactId),
  KEY reviewType (reviewType),
  KEY requestedBy (requestedBy)
) TYPE=MyISAM;

#
# Dumping data for table 'ReviewRequest'
#


#
# Table structure for table 'PaperReview'
#

drop table if exists PaperReview;
CREATE TABLE PaperReview (
  reviewId int(11) NOT NULL auto_increment,
  paperId int(11) NOT NULL,
  contactId int(11) NOT NULL,

  reviewType tinyint(1) NOT NULL default 0,
  requestedBy int(11) NOT NULL default 0,
  requestedOn timestamp(14) NOT NULL,
  acceptedOn timestamp(14) NOT NULL default 0,
  blind tinyint(1) NOT NULL default 1,

  reviewModified int(1),
  reviewSubmitted int(1),
  reviewOrdinal int(1),
  reviewNeedsSubmit tinyint(1) NOT NULL default 1,

  overAllMerit tinyint(1) NOT NULL default 0,
  reviewerQualification tinyint(1) NOT NULL default 0,
  novelty tinyint(1) NOT NULL default 0,
  technicalMerit tinyint(1) NOT NULL default 0,
  interestToCommunity tinyint(1) NOT NULL default 0,
  longevity tinyint(1) NOT NULL default 0,
  grammar tinyint(1) NOT NULL default 0,
  likelyPresentation tinyint(1) NOT NULL default 0,
  suitableForShort tinyint(1) NOT NULL default 0,
  paperSummary text NOT NULL default '',
  commentsToAuthor text NOT NULL default '',
  commentsToPC text NOT NULL default '',
  commentsToAddress text NOT NULL default '',
  weaknessOfPaper text NOT NULL default '',
  strengthOfPaper text NOT NULL default '',

  potential tinyint(4) NOT NULL default 0,
  fixability tinyint(4) NOT NULL default 0,

  PRIMARY KEY (reviewId),
  UNIQUE KEY reviewId (reviewId),
  UNIQUE KEY contactPaper (contactId,paperId),
  KEY paperId (paperId),
  KEY reviewSubmitted (reviewSubmitted),
  KEY reviewNeedsSubmit (reviewNeedsSubmit),
  KEY reviewType (reviewType),
  KEY requestedBy (requestedBy)
) TYPE=MyISAM;

drop table if exists PaperReviewRefused;
CREATE TABLE PaperReviewRefused (
  paperId int(11) NOT NULL,
  contactId int(11) NOT NULL,
  requestedBy int(11) NOT NULL,
  reason text NOT NULL default '',
  KEY paperId (paperId),
  KEY contactId (contactId),
  KEY requestedBy (requestedBy)
) TYPE=MyISAM;

drop table if exists PaperReviewArchive;
CREATE TABLE PaperReviewArchive (  
  reviewArchiveId int(11) NOT NULL auto_increment,
  reviewId int(11) NOT NULL,
  paperId int(11) NOT NULL,
  contactId int(11) NOT NULL,

  reviewType tinyint(1) NOT NULL default 0,
  requestedBy int(11) NOT NULL default 0,
  requestedOn timestamp(14) NOT NULL,
  acceptedOn timestamp(14) NOT NULL default 0,
  blind tinyint(1) NOT NULL default 1,

  reviewModified int(1),
  reviewSubmitted int(1),
  reviewOrdinal int(1),
  reviewNeedsSubmit tinyint(1) NOT NULL default 1,

  overAllMerit tinyint(1) NOT NULL default 0,
  reviewerQualification tinyint(1) NOT NULL default 0,
  novelty tinyint(1) NOT NULL default 0,
  technicalMerit tinyint(1) NOT NULL default 0,
  interestToCommunity tinyint(1) NOT NULL default 0,
  longevity tinyint(1) NOT NULL default 0,
  grammar tinyint(1) NOT NULL default 0,
  likelyPresentation tinyint(1) NOT NULL default 0,
  suitableForShort tinyint(1) NOT NULL default 0,
  paperSummary text NOT NULL default '',
  commentsToAuthor text NOT NULL default '',
  commentsToPC text NOT NULL default '',
  commentsToAddress text NOT NULL default '',
  weaknessOfPaper text NOT NULL default '',
  strengthOfPaper text NOT NULL default '',

  potential tinyint(4) NOT NULL default 0,
  fixability tinyint(4) NOT NULL default 0,

  PRIMARY KEY (reviewArchiveId),
  UNIQUE KEY reviewArchiveId (reviewArchiveId),
  KEY reviewId (reviewId),
  KEY contactPaper (contactId,paperId),
  KEY paperId (paperId),
  KEY reviewSubmitted (reviewSubmitted),
  KEY reviewNeedsSubmit (reviewNeedsSubmit),
  KEY reviewType (reviewType),
  KEY requestedBy (requestedBy)
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
  mimetype varchar(40) NOT NULL default '',
  paper longblob,
  compression tinyint(1) NOT NULL default 0,
  PRIMARY KEY (paperStorageId),
  UNIQUE KEY paperStorageId (paperStorageId),
  KEY paperId (paperId),
  KEY mimetype (mimetype)
) TYPE=MyISAM;

insert into PaperStorage set paperId=0, timestamp=0, mimetype='text/plain', paper='';

#
# Dumping data for table 'PaperStorage'
#



drop table if exists PaperReviewPreference;
CREATE TABLE PaperReviewPreference (
  paperId int(11) NOT NULL,
  contactId int(11) NOT NULL,
  preference int(4) NOT NULL default 0,
  UNIQUE KEY contactPaper (contactId,paperId)
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
  UNIQUE KEY paperTopic (paperId,topicId)
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
  UNIQUE KEY contactTopic (contactId,topicId)
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
  authorView tinyint(1) NOT NULL default 1,
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
	shortName='Numeric field 1';
insert into ReviewFormField set fieldName='potential',
	shortName='Numeric field 2';
insert into ReviewFormField set fieldName='fixability',
	shortName='Numeric field 3';

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

delete from ImportantDates where name='reviewFormUpdate';
insert into ImportantDates set name='reviewFormUpdate', start=current_timestamp;


#
# Paper lists
#

drop table if exists PaperList;
create table PaperList (
  paperListId int(11) NOT NULL auto_increment,
  paperListName varchar(20) NOT NULL,
  shortDescription varchar(40) NOT NULL default '',
  description varchar(80) NOT NULL default '',
  queryType varchar(20) NOT NULL default 'any',
  listHome varchar(40) NOT NULL default '',
  listContact varchar(20) NOT NULL default '',
  listContactType varchar(20) NOT NULL default 'any',
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
  UNIQUE KEY paperListCol (paperListId,col)
) TYPE=MyISAM;

insert into PaperFields set fieldId=1, fieldName='id', description='ID';
insert into PaperFields set fieldId=2, fieldName='id', description='ID (manage link)';
insert into PaperFields set fieldId=3, fieldName='id', description='ID (review link)';
insert into PaperFields set fieldId=11, fieldName='title', description='Title';
insert into PaperFields set fieldId=12, fieldName='title', description='Title (manage link)';
insert into PaperFields set fieldId=13, fieldName='title', description='Title (review link)';
insert into PaperFields set fieldId=14, fieldName='title', description='Title (review link)';
insert into PaperFields set fieldId=27, fieldName='status', description='Status';
insert into PaperFields set fieldId=28, fieldName='download', description='Download', sortable=0;
insert into PaperFields set fieldId=29, fieldName='reviewer', description='Reviewer type';
insert into PaperFields set fieldId=30, fieldName='reviewerStatus', description='Reviewer status';
insert into PaperFields set fieldId=31, fieldName='selector', description='Selector';
insert into PaperFields set fieldId=32, fieldName='review', description='Review';
insert into PaperFields set fieldId=33, fieldName='status', description='Status (for reviewers)';
insert into PaperFields set fieldId=34, fieldName='reviewerName', description='Reviewer name';
insert into PaperFields set fieldId=35, fieldName='reviewAssignment', description='Review assignment';
insert into PaperFields set fieldId=36, fieldName='topicMatch', description='Topic interest score';
insert into PaperFields set fieldId=37, fieldName='topicNames', description='Topic names', sortable=0, display=2;
insert into PaperFields set fieldId=38, fieldName='reviewerNames', description='Reviewer names', sortable=0, display=2;
insert into PaperFields set fieldId=39, fieldName='reviewPreference', description='Review preference';
insert into PaperFields set fieldId=40, fieldName='editReviewPreference', description='Edit review preference';
insert into PaperFields set fieldId=41, fieldName='reviewsStatus', description='Review counts';
insert into PaperFields set fieldId=42, fieldName='matches', description='Matches', display=0;
insert into PaperFields set fieldId=43, fieldName='desirability', description='Desirability';
insert into PaperFields set fieldId=44, fieldName='allPreferences', description='Reviewer preferences', sortable=0, display=2;
insert into PaperFields set fieldId=45, fieldName='reviewerTypeIcon', description='Reviewer type';
insert into PaperFields set fieldId=46, fieldName='optOverallMeritIcon', description='Overall merit (icon)';

insert into PaperList set paperListId=1, paperListName='author',
	shortDescription='Authored', description='Authored papers',
	listHome='list.php?list=author', listContact='contactId',
	queryType='author', sortCol=0, query='';
insert into PaperListColumns set paperListId=1, fieldId=2, col=0;
insert into PaperListColumns set paperListId=1, fieldId=12, col=1;
insert into PaperListColumns set paperListId=1, fieldId=28, col=2;
insert into PaperListColumns set paperListId=1, fieldId=27, col=3;

insert into PaperList set paperListId=2, paperListName='submitted',
	shortDescription='Submitted', description='Submitted papers',
	listHome='list.php?list=submitted',
	queryType='pc', sortCol=0, query='';
insert into PaperListColumns set paperListId=2, fieldId=31, col=0;
insert into PaperListColumns set paperListId=2, fieldId=1, col=1;
insert into PaperListColumns set paperListId=2, fieldId=11, col=2;
insert into PaperListColumns set paperListId=2, fieldId=29, col=3;
insert into PaperListColumns set paperListId=2, fieldId=45, col=4;
insert into PaperListColumns set paperListId=2, fieldId=41, col=5;
insert into PaperListColumns set paperListId=2, fieldId=33, col=6;

insert into PaperList set paperListId=3, paperListName='all',
	shortDescription='All', description='All papers', 
	listHome='list.php?list=all',
	queryType='chair', sortCol=0, query='';
insert into PaperListColumns set paperListId=3, fieldId=31, col=0;
insert into PaperListColumns set paperListId=3, fieldId=1, col=1;
insert into PaperListColumns set paperListId=3, fieldId=11, col=2;
insert into PaperListColumns set paperListId=3, fieldId=27, col=3;
insert into PaperListColumns set paperListId=3, fieldId=29, col=5;

insert into PaperList set paperListId=4, paperListName='authorHome',
	shortDescription='Your papers', description='My papers (homepage view)', 
	listHome='list.php?list=author', listContact='contactId',
	queryType='author', sortCol=0, query='';
insert into PaperListColumns set paperListId=4, fieldId=2, col=0;
insert into PaperListColumns set paperListId=4, fieldId=12, col=1;
insert into PaperListColumns set paperListId=4, fieldId=27, col=2;

insert into PaperList set paperListId=6, paperListName='reviewerHome',
	shortDescription='Your reviews', description='Papers to review (homepage view)',
	listHome='list.php?list=reviewer', listContact='reviewer', listContactType='reviewer',
	queryType='myReviews', sortCol=0, query='';
insert into PaperListColumns set paperListId=6, fieldId=3, col=0;
insert into PaperListColumns set paperListId=6, fieldId=13, col=1;
insert into PaperListColumns set paperListId=6, fieldId=29, col=2;
insert into PaperListColumns set paperListId=6, fieldId=33, col=3;

insert into PaperList set paperListId=7, paperListName='reviewer',
	shortDescription='Your reviews', description='Papers to review',
	listHome='list.php?list=reviewer', listContact='reviewer', listContactType='reviewer',
	queryType='myReviews', sortCol=0, query='';
insert into PaperListColumns set paperListId=7, fieldId=31, col=0;
insert into PaperListColumns set paperListId=7, fieldId=3, col=1;
insert into PaperListColumns set paperListId=7, fieldId=14, col=2;
insert into PaperListColumns set paperListId=7, fieldId=29, col=3;
insert into PaperListColumns set paperListId=7, fieldId=41, col=4;
insert into PaperListColumns set paperListId=7, fieldId=33, col=5;

insert into PaperList set paperListId=8, paperListName='reviewAssignment',
	shortDescription='Review assignment', description='Review assignments',
	listHome='Chair/AssignPapers.php', listContact='reviewer', listContactType='pc',
	queryType='pc', sortCol=3, query='';
insert into PaperListColumns set paperListId=8, fieldId=3, col=0;
insert into PaperListColumns set paperListId=8, fieldId=13, col=1;
insert into PaperListColumns set paperListId=8, fieldId=39, col=2;
insert into PaperListColumns set paperListId=8, fieldId=36, col=3;
insert into PaperListColumns set paperListId=8, fieldId=43, col=4;
insert into PaperListColumns set paperListId=8, fieldId=35, col=5;
insert into PaperListColumns set paperListId=8, fieldId=37, col=6;
insert into PaperListColumns set paperListId=8, fieldId=38, col=7;
insert into PaperListColumns set paperListId=8, fieldId=44, col=8;

insert into PaperList set paperListId=9, paperListName='editReviewPreference',
	shortDescription='Review preferences', description='Edit reviewer preferences',
	listHome='PC/reviewprefs.php', listContact='reviewer', listContactType='pc',
	queryType='pc', sortCol=3, query='';
insert into PaperListColumns set paperListId=9, fieldId=1, col=0;
insert into PaperListColumns set paperListId=9, fieldId=11, col=1;
insert into PaperListColumns set paperListId=9, fieldId=28, col=2;
insert into PaperListColumns set paperListId=9, fieldId=36, col=3;
insert into PaperListColumns set paperListId=9, fieldId=29, col=4;
insert into PaperListColumns set paperListId=9, fieldId=40, col=5;
insert into PaperListColumns set paperListId=9, fieldId=37, col=6;

insert into PaperList set paperListId=10, paperListName='matches',
	shortDescription='Search matches', description='Search matches',
	listHome='search.php?q=*',
	queryType='pc', sortCol=3, query='';
insert into PaperListColumns set paperListId=10, fieldId=1, col=0;
insert into PaperListColumns set paperListId=10, fieldId=11, col=1;
insert into PaperListColumns set paperListId=10, fieldId=45, col=2;
insert into PaperListColumns set paperListId=10, fieldId=42, col=3;

insert into PaperList set paperListId=11, paperListName='matchesAll',
	shortDescription='Search matches', description='Search matches',
	listHome='search.php?q=*&all=1',
	queryType='chair', sortCol=3, query='';
insert into PaperListColumns set paperListId=11, fieldId=1, col=0;
insert into PaperListColumns set paperListId=11, fieldId=11, col=1;
insert into PaperListColumns set paperListId=11, fieldId=27, col=2;
insert into PaperListColumns set paperListId=10, fieldId=45, col=3;
insert into PaperListColumns set paperListId=11, fieldId=42, col=4;

delete from ImportantDates where name='paperListUpdate';
insert into ImportantDates set name='paperListUpdate', start=current_timestamp;
