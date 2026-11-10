--
-- Table structure for table `Capability`
--

DROP TABLE IF EXISTS `Capability`;
CREATE TABLE `Capability` (
  `capabilityType` int NOT NULL,
  `contactId` int NOT NULL,
  `paperId` int NOT NULL,
  `timeExpires` bigint NOT NULL,
  `salt` varbinary(255) NOT NULL,
  `data` varbinary(16384) DEFAULT NULL,
  `timeCreated` bigint NOT NULL,
  `timeUsed` bigint NOT NULL DEFAULT 0,
  `timeInvalid` bigint NOT NULL DEFAULT 0,
  `outputData` longblob DEFAULT NULL,
  `inputData` varbinary(16834) DEFAULT NULL,
  `lookupKey` varbinary(255) DEFAULT NULL,
  `inputDataOverflow` longblob DEFAULT NULL,
  `dataOverflow` longblob DEFAULT NULL,
  `useCount` bigint NOT NULL DEFAULT 0,
  PRIMARY KEY (`salt`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;



--
-- Table structure for table `ConferenceUpdates`
--

DROP TABLE IF EXISTS `ConferenceUpdates`;
CREATE TABLE `ConferenceUpdates` (
  `confid` int NOT NULL,
  `user_update_at` bigint NOT NULL DEFAULT 0,
  PRIMARY KEY (`confid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


--
-- Table structure for table `Conferences`
--

DROP TABLE IF EXISTS `Conferences`;
CREATE TABLE `Conferences` (
  `confid` int NOT NULL AUTO_INCREMENT,
  `confuid` varbinary(64) DEFAULT NULL,
  `dbname` varbinary(64) DEFAULT NULL,
  `shortName` varchar(256) DEFAULT NULL,
  `longName` varbinary(512) DEFAULT NULL,
  `conf_flags` int NOT NULL DEFAULT 0,
  PRIMARY KEY (`confid`),
  UNIQUE KEY `confuid` (`confuid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;



--
-- Table structure for table `ContactCounter`
--

DROP TABLE IF EXISTS `ContactCounter`;
CREATE TABLE `ContactCounter` (
  `contactId` int NOT NULL,
  `apiCount` bigint NOT NULL DEFAULT 0,
  `apiBase` bigint NOT NULL DEFAULT 0,
  `apiBaseMtime` bigint NOT NULL DEFAULT 0,
  `apiRefreshWindow` int DEFAULT NULL,
  `apiRefreshAmount` int DEFAULT NULL,
  `apiBase2` bigint NOT NULL DEFAULT 0,
  `apiBaseMtime2` bigint NOT NULL DEFAULT 0,
  `apiRefreshWindow2` int DEFAULT NULL,
  `apiRefreshAmount2` int DEFAULT NULL,
  PRIMARY KEY (`contactId`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;



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
  `passwordTime` bigint NOT NULL DEFAULT 0,
  `country` varbinary(256) DEFAULT NULL,
  `collaborators` varbinary(8192) DEFAULT NULL,
  `collaboratorsOverflow` longblob DEFAULT NULL,
  `passwordUseTime` bigint NOT NULL DEFAULT 0,
  `updateTime` bigint NOT NULL DEFAULT 0,
  `demoBirthday` int DEFAULT NULL,
  `primaryContactId` int NOT NULL DEFAULT 0,
  PRIMARY KEY (`contactDbId`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;



--
-- Table structure for table `ContactPrimary`
--

DROP TABLE IF EXISTS `ContactPrimary`;
CREATE TABLE `ContactPrimary` (
  `contactId` int NOT NULL,
  `primaryContactId` int NOT NULL,
  PRIMARY KEY (`contactId`),
  KEY `primaryContactId` (`primaryContactId`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;



--
-- Table structure for table `Roles`
--

DROP TABLE IF EXISTS `Roles`;
CREATE TABLE `Roles` (
  `contactDbId` int NOT NULL,
  `confid` int NOT NULL,
  `roles` tinyint NOT NULL DEFAULT 0,
  `activity_at` bigint NOT NULL DEFAULT 0,
  PRIMARY KEY (`contactDbId`,`confid`),
  KEY `confid` (`confid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;



--
-- Table structure for table `SessionData`
--

DROP TABLE IF EXISTS `SessionData`;
CREATE TABLE `SessionData` (
  `sid` varbinary(128) NOT NULL,
  `updated_at` bigint NOT NULL DEFAULT 0,
  `expires_at` bigint NOT NULL DEFAULT 0,
  `data` varbinary(32768) DEFAULT NULL,
  `dataOverflow` longblob DEFAULT NULL,
  `actionData` varbinary(16384) DEFAULT NULL,
  PRIMARY KEY (`sid`),
  KEY `expires_at` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;



--
-- Table structure for table `SessionUsers`
--

DROP TABLE IF EXISTS `SessionUsers`;
CREATE TABLE `SessionUsers` (
  `sid` varbinary(128) NOT NULL,
  `contactDbId` int NOT NULL,
  PRIMARY KEY (`sid`,`contactDbId`),
  KEY `contactDbId` (`contactDbId`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;



--
-- Table structure for table `Settings`
--

DROP TABLE IF EXISTS `Settings`;
CREATE TABLE `Settings` (
  `name` varbinary(256) DEFAULT NULL,
  `value` bigint NOT NULL,
  `data` varbinary(32767) DEFAULT NULL,
  UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;



--
-- Table structure for table `WorkItem`
--

DROP TABLE IF EXISTS `WorkItem`;
CREATE TABLE `WorkItem` (
  `serverId` int NOT NULL,
  `root` varbinary(255) NOT NULL,
  `workType` varbinary(80) NOT NULL,
  `workSubtype` varbinary(80) NOT NULL,
  `work` longblob NOT NULL,
  `touchedAt` bigint NOT NULL DEFAULT 0,
  `dequeuedAt` bigint NOT NULL DEFAULT 0,
  PRIMARY KEY (`serverId`,`root`,`workType`,`workSubtype`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


insert into Settings (name, value) values ('sversion', 188);
