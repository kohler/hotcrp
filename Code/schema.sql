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
# Table structure for table 'Roles'
#

drop table if exists Roles;
CREATE TABLE Roles (
  contactId int(11) NOT NULL,
  role tinyint(1) NOT NULL,
  paperId int(11) NOT NULL,
  KEY contactId (contactId)
) TYPE=MyISAM;

#
# Dumping data for table 'Roles'
#


#
# Table structure for table 'ContactInfo'
#

drop table if exists ContactInfo;
CREATE TABLE ContactInfo (
  contactId int(11) NOT NULL auto_increment,
  visits int(11) default '0',
  firstName varchar(40) default NULL,
  lastName varchar(40) default NULL,
  email varchar(80) default NULL,
  affiliation varchar(200) default NULL,
  voicePhoneNumber varchar(20) default NULL,
  faxPhoneNumber varchar(20) default NULL,
  password varchar(20) default NULL,
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
# Table structure for table 'ImportantDates'
#

drop table if exists ImportantDates;
CREATE TABLE ImportantDates (
  id int(11) NOT NULL auto_increment,
  name char(40) NOT NULL default '',
  start timestamp(14) NOT NULL,
  end timestamp(14) NOT NULL,
  PRIMARY KEY (id)
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
  pdfLocation varchar(120) default NULL,
  contactId int(11) default NULL,
  submitted timestamp(14) NOT NULL,
  acknowledged int(11) default '0',
  withdrawn int(11) default '0',
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
# Table structure for table 'PaperAuthor'
#

drop table if exists PaperAuthor;
CREATE TABLE PaperAuthor (
  paperId int(11) default NULL,
  authorId int(11) default NULL,
  authorOrder int(11) default NULL,
  KEY paperId (paperId),
  KEY authorId (authorId)
) TYPE=MyISAM;

#
# Dumping data for table 'PaperAuthor'
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
  paperConflictId int(11) NOT NULL auto_increment,
  paperId int(11) default NULL,
  authorId int(11) default NULL,
  PRIMARY KEY  (paperConflictId),
  UNIQUE KEY paperConflictId (paperConflictId),
  KEY paperId (paperId),
  KEY authorId (authorId)
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
  PRIMARY KEY  (gradeId),
  UNIQUE KEY gradeId (gradeId),
  KEY contactId (contactId),
  KEY paperId (paperId)
) TYPE=MyISAM;

#
# Dumping data for table 'PaperGrade'
#


#
# Table structure for table 'PaperReview'
#

drop table if exists PaperReview;
CREATE TABLE PaperReview (
  paperReviewId int(11) NOT NULL auto_increment,
  paperId int(11) default NULL,
  reviewer int(11) default NULL,
  finalized int(11) default '0',
  lastModified timestamp(14) NOT NULL,
  reviewerQualification int(11) default '0',
  overAllMerit int(11) default '0',
  novelty int(11) default '0',
  technicalMerit int(11) default '0',
  interestToCommunity int(11) default '0',
  longevity int(11) default '0',
  grammar int(11) default '0',
  likelyPresentation int(11) default '0',
  suitableForShort int(11) default '0',
  paperSummary text,
  commentsToAuthor text,
  commentsToPC text,
  commentsToAddress text,
  weaknessOfPaper text,
  strengthOfPaper text,
  potential tinyint(4) NOT NULL default '-1',
  fixability tinyint(4) NOT NULL default '0',
  PRIMARY KEY (paperReviewId),
  UNIQUE KEY paperReviewid (paperReviewId),
  KEY finalized (finalized),
  KEY paperId (paperId),
  KEY reviewer (reviewer)
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
  reviewer int(11) default NULL,
  paperId int(11) default NULL,
  requestMadeOn timestamp(14) NOT NULL,
  PRIMARY KEY  (primaryReviewerId),
  UNIQUE KEY primaryReviewerId (primaryReviewerId),
  KEY reviewer (reviewer),
  KEY paperId (paperId)
) TYPE=MyISAM;

#
# Dumping data for table 'PrimaryReviewer'
#


#
# Table structure for table 'ReviewRequest'
#

drop table if exists ReviewRequest;
CREATE TABLE ReviewRequest (
  reviewRequestId int(11) NOT NULL auto_increment,
  requestedBy int(11) default NULL,
  asked int(11) default NULL,
  paperId int(11) default NULL,
  requestMadeOn timestamp(14) NOT NULL,
  acceptedOn timestamp(14) NOT NULL,
  accepted int(11) default '0',
  PRIMARY KEY  (reviewRequestId),
  UNIQUE KEY reviewRequestId (reviewRequestId),
  KEY reviewRequestId_2 (reviewRequestId),
  KEY requestedBy (requestedBy),
  KEY asked (asked),
  KEY paperId (paperId)
) TYPE=MyISAM;

#
# Dumping data for table 'ReviewRequest'
#


#
# Table structure for table 'SecondaryReviewer'
#

drop table if exists SecondaryReviewer;
CREATE TABLE SecondaryReviewer (
  secondaryReviewerId int(11) NOT NULL auto_increment,
  reviewer int(11) default NULL,
  paperId int(11) default NULL,
  requestMadeOn timestamp(14) NOT NULL,
  PRIMARY KEY  (secondaryReviewerId),
  UNIQUE KEY secondaryReviewerId (secondaryReviewerId),
  KEY reviewer (reviewer),
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
  PRIMARY KEY  (preferenceId),
  UNIQUE KEY preferenceId (preferenceId)
) TYPE=MyISAM;

drop table if exists PaperReviewerConflict;
CREATE TABLE PaperReviewerConflict(
  conflictId int(11) NOT NULL auto_increment,
  paperId int(11) default NULL,
  contactId int(11) default NULL,
  PRIMARY KEY  (conflictId),
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
