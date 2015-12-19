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
) ENGINE=MyISAM DEFAULT CHARSET=utf8;



--
-- Table structure for table `ContactInfo`
--

DROP TABLE IF EXISTS `ContactInfo`;
CREATE TABLE `ContactInfo` (
  `contactDbId` int(11) NOT NULL AUTO_INCREMENT,
  `firstName` varchar(60) NOT NULL DEFAULT '',
  `lastName` varchar(60) NOT NULL DEFAULT '',
  `unaccentedName` varchar(120) NOT NULL DEFAULT '',
  `email` varchar(120) NOT NULL,
  `affiliation` varchar(2048) NOT NULL DEFAULT '',
  `country` varbinary(256) DEFAULT NULL,
  `collaborators` varbinary(8192) DEFAULT NULL,
  `disabled` tinyint(1) NOT NULL DEFAULT '0',
  `data` varbinary(32767) DEFAULT NULL,
  `password` varbinary(2048) DEFAULT NULL,
  `activity_at` int(11) NOT NULL DEFAULT '0',
  `passwordTime` int(11) NOT NULL DEFAULT '0',
  `passwordUseTime` int(11) NOT NULL DEFAULT '0',
  `updateTime` int(11) NOT NULL DEFAULT '0',
  PRIMARY KEY (`contactDbId`),
  UNIQUE KEY `contactDbId` (`contactDbId`),
  UNIQUE KEY `contactId` (`contactDbId`),
  UNIQUE KEY `email` (`email`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8;
