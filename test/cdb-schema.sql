--
-- Table structure for table `Capability`
--

DROP TABLE IF EXISTS `Capability`;
CREATE TABLE `Capability` (
  `capabilityType` int NOT NULL,
  `contactId` int NOT NULL,
  `paperId` int NOT NULL,
  `timeCreated` bigint NOT NULL,
  `timeUsed` bigint NOT NULL DEFAULT 0,
  `useCount` bigint NOT NULL DEFAULT 0,
  `timeInvalid` bigint NOT NULL DEFAULT 0,
  `timeExpires` bigint NOT NULL,
  `salt` varbinary(255) NOT NULL,
  `data` varbinary(8192) DEFAULT NULL,
  `dataOverflow` longblob DEFAULT NULL,
  PRIMARY KEY (`salt`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;



--
-- Table structure for table `Conferences`
--

DROP TABLE IF EXISTS `Conferences`;
CREATE TABLE `Conferences` (
  `confid` int NOT NULL AUTO_INCREMENT,
  `confuid` varbinary(64) DEFAULT NULL,
  PRIMARY KEY (`confid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


--
-- Table structure for table `ConferenceUpdates`
--

DROP TABLE IF EXISTS `ConferenceUpdates`;
CREATE TABLE `ConferenceUpdates` (
  `confid` int NOT NULL,
  `user_update_at` bigint NOT NULL DEFAULT 0,
  PRIMARY KEY (`confid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


--
-- Table structure for table `ContactInfo`
--

DROP TABLE IF EXISTS `ContactInfo`;
CREATE TABLE `ContactInfo` (
  `contactDbId` int NOT NULL AUTO_INCREMENT,
  `firstName` varbinary(240) NOT NULL DEFAULT '',
  `lastName` varbinary(240) NOT NULL DEFAULT '',
  `email` varchar(120) NOT NULL,
  `affiliation` varbinary(2048) NOT NULL DEFAULT '',
  `orcid` varbinary(64) DEFAULT NULL,
  `cflags` int NOT NULL DEFAULT 0,
  `data` varbinary(32767) DEFAULT NULL,
  `password` varbinary(2048) DEFAULT NULL,
  `passwordTime` int NOT NULL DEFAULT 0,
  `country` varbinary(256) DEFAULT NULL,
  `collaborators` varbinary(8192) DEFAULT NULL,
  `passwordUseTime` bigint NOT NULL DEFAULT 0,
  `updateTime` bigint NOT NULL DEFAULT 0,
  `demoBirthday` int DEFAULT NULL,
  `primaryContactId` int NOT NULL DEFAULT 0,
  PRIMARY KEY (`contactDbId`),
  UNIQUE KEY `email` (`email`)
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
-- Table structure for table `Roles`
--

DROP TABLE IF EXISTS `Roles`;
CREATE TABLE `Roles` (
  `contactDbId` int NOT NULL,
  `confid` int NOT NULL,
  `roles` tinyint NOT NULL DEFAULT 0,
  `activity_at` bigint NOT NULL DEFAULT 0,
  PRIMARY KEY (`contactDbId`,`confid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


--
-- Table structure for table `Settings`
--

DROP TABLE IF EXISTS `Settings`;
CREATE TABLE `Settings` (
  `name` varbinary(256) DEFAULT NULL,
  `value` int NOT NULL,
  `data` varbinary(32767) DEFAULT NULL,
  UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;



insert into Settings (name, value) values ('sversion', 100);
