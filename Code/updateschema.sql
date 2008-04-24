-- This file contains the SQL statements required to update from one version
-- of HotCRP to the next.
--
-- HotCRP updates are designed so that new code can run transparently with an
-- old database.  This lets you easily fix bugs in old features.  However, if
-- you want to enable a new feature, you will need to update your current
-- database schema.  This file tells you how.
--
-- Updates are cumulative, so apply them in order.
--
-- If you're not sure which update to apply, run the following query:

select value from Settings where name='allowPaperOption';

-- UPDATE FROM VERSION 2.3 TO VERSION 2.4
-- Apply if `allowPaperOption <= 3`.

alter table ContactInfo add `creationTime` int(11) NOT NULL default '0';
update Settings set value=4 where name='allowPaperOption';


-- UPDATE FROM VERSION 2.4 TO VERSION 2.5
-- Apply if `allowPaperOption <= 4`.

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
update Settings set value=5 where name='allowPaperOption';


-- UPDATE FROM VERSION 2.5 TO VERSION 2.6
-- No update necessary.


-- UPDATE FROM VERSION 2.6 TO VERSION 2.7
-- Apply if `allowPaperOption <= 5`.

alter table ContactInfo add `defaultWatch` tinyint(1) NOT NULL default '2';
alter table ContactInfo add `roles` tinyint(1) NOT NULL default '0';
update ContactInfo, PCMember set roles=roles+1 where ContactInfo.contactId=PCMember.contactId;
update ContactInfo, ChairAssistant set roles=roles+2 where ContactInfo.contactId=ChairAssistant.contactId;
update ContactInfo, Chair set roles=roles+4 where ContactInfo.contactId=Chair.contactId;
alter table ContactInfo add unique key `contactIdRoles` (`contactId`,`roles`);
alter table PaperComment add key `contactPaper` (`contactId`,`paperId`);
create table `PaperWatch` (
  `paperId` int(11) NOT NULL,
  `contactId` int(11) NOT NULL,
  `watch` tinyint(1) NOT NULL default '0',
  UNIQUE KEY `contactPaper` (`contactId`,`paperId`),
  UNIQUE KEY `contactPaperWatch` (`contactId`,`paperId`,`watch`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8;
update Settings set value=6 where name='allowPaperOption';


-- UPDATE FROM VERSIONS 2.7-2.11 TO VERSION 2.12
-- Apply if `allowPaperOption <= 6`.

alter table ReviewRequest add `reason` text;
update Settings set value=7 where name='allowPaperOption';


-- UPDATE FROM VERSION 2.12 TO VERSION 2.13
-- Apply if `allowPaperOption <= 7`.

alter table PaperReview add `textField7` mediumtext NOT NULL;
alter table PaperReview add `textField8` mediumtext NOT NULL;
insert into ReviewFormField set fieldName='textField7',
	shortName='Additional text field';
insert into ReviewFormField set fieldName='textField8',
	shortName='Additional text field';
update Settings set value=8 where name='allowPaperOption';


-- UPDATE FROM VERSION 2.13
-- Apply if `allowPaperOption <= 8`.

alter table ReviewFormField add `levelChar` tinyint(1) NOT NULL default '0';
alter table PaperReviewArchive add `textField7` mediumtext NOT NULL;
alter table PaperReviewArchive add `textField8` mediumtext NOT NULL;
alter table Paper add `sha1` varbinary(20) NOT NULL default '';
update Settings set value=10 where name='allowPaperOption';
insert into Settings (name, value) values ('sub_sha1', 1);

alter table PaperReview add `reviewRound` tinyint(1) NOT NULL default '0';
alter table PaperReview add key `reviewRound` (`reviewRound`);
update Settings set value=11 where name='allowPaperOption';

-- VERSION 2.17 AND LATER
-- These versions upgrade the schema automatically.  See updateschema.inc.
