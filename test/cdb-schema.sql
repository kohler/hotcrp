--
-- Table structure for table `Capability`
--

DROP TABLE IF EXISTS `Capability`;
CREATE TABLE `Capability` (
  `capabilityType` int(11) NOT NULL,
  `contactId` int(11) NOT NULL,
  `paperId` int(11) NOT NULL,
  `timeCreated` bigint(11) NOT NULL,
  `timeUsed` bigint(11) NOT NULL DEFAULT 0,
  `timeInvalid` bigint(11) NOT NULL DEFAULT 0,
  `timeExpires` bigint(11) NOT NULL,
  `salt` varbinary(255) NOT NULL,
  `data` varbinary(8192) DEFAULT NULL,
  PRIMARY KEY (`salt`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;



--
-- Table structure for table `Conferences`
--

DROP TABLE IF EXISTS `Conferences`;
CREATE TABLE `Conferences` (
  `confid` int(11) NOT NULL AUTO_INCREMENT,
  `confuid` varbinary(64) DEFAULT NULL,
  PRIMARY KEY (`confid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


--
-- Table structure for table `ContactInfo`
--

DROP TABLE IF EXISTS `ContactInfo`;
CREATE TABLE `ContactInfo` (
  `contactDbId` int(11) NOT NULL AUTO_INCREMENT,
  `firstName` varbinary(240) NOT NULL DEFAULT '',
  `lastName` varbinary(240) NOT NULL DEFAULT '',
  `email` varchar(120) NOT NULL,
  `affiliation` varbinary(2048) NOT NULL DEFAULT '',
  `orcid` varbinary(64) DEFAULT NULL,
  `disabled` tinyint(1) NOT NULL DEFAULT 0,
  `cflags` int(11) NOT NULL DEFAULT 0,
  `data` varbinary(32767) DEFAULT NULL,
  `password` varbinary(2048) DEFAULT NULL,
  `passwordTime` int(11) NOT NULL DEFAULT 0,
  `country` varbinary(256) DEFAULT NULL,
  `collaborators` varbinary(8192) DEFAULT NULL,
  `passwordUseTime` bigint(11) NOT NULL DEFAULT 0,
  `updateTime` bigint(11) NOT NULL DEFAULT 0,
  `demoBirthday` int(11) DEFAULT NULL,
  PRIMARY KEY (`contactDbId`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


--
-- Table structure for table `Roles`
--

DROP TABLE IF EXISTS `Roles`;
CREATE TABLE `Roles` (
  `contactDbId` int(11) NOT NULL,
  `confid` int(11) NOT NULL,
  `roles` tinyint(1) NOT NULL DEFAULT 0,
  `activity_at` bigint(20) NOT NULL DEFAULT 0,
  PRIMARY KEY (`contactDbId`,`confid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


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



insert into Settings (name, value) values ('sversion', 100);
