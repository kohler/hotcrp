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
  `addressLine1` text NOT NULL,
  `addressLine2` text NOT NULL,
  `city` text NOT NULL,
  `state` text NOT NULL,
  `zipCode` text NOT NULL,
  `country` text NOT NULL,
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
