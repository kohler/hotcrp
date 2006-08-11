<?php 

//
// Database configuration information
//
$dbHost = "127.0.0.1";
$dbUser = "HotNetsV";
$dbPassword = "HotNetsV";
$dbName = "HotNetsV";

$Conf = new Conference($dbUser, $dbPassword, $dbHost, $dbName);

//
// Conference names
//
// You need a unique session name to distinguish conference sessions
// running on the same server. DO NOT PUT SPACES IN THE SESSION NAME!!
//
$Conf->sessionName = "HotNetsV";
$Conf->shortName = "HotNets V";
$Conf->longName = "HotNets V";

// allowed paper formats
$Conf->allowPDF = 1;
$Conf->allowPS = 1;

//
// OPTION: can authors specify preferred reviewers?
//
$Conf->optionAllowReviewerPreferences = 1;
//
// OPTION: can PC members see reviewer identify during PC meeting?
//
$Conf->optionLetPCSeeReviewers = 1;
//
// Can PC see unfinished reviews in review form during PC meeting?
//
$Conf->optionLetPCSeeUnfinishedReviews = 1;
//
// Can PC see author information in review form during PC meeting?
//
$Conf->optionLetPCSeeAuthorInfo = 0;

//
// The name of this site. You can conditionalize this based on
// e.g. hostname in case you have a development site and a deployment
// site
//

$posixinfo = posix_uname();
$hostname = $posixinfo['nodename'];

//
// This is the location of the hypothetical conference
// the test server
//
$Conf->paperSite = "http://www.read.cs.ucla.edu/hotnets5";

//
// The conference site (i.e. CFP, registration)
//
$Conf->conferenceSite="http://www.acm.org/sigs/sigcomm/HotNets-V/";

//
// The prefix for downloaded files
//
$Conf->downloadPrefix="hotnets5-";

//
// A CSS, if you want one
//
$Conf->theme = "<link rel='stylesheet' type='text/css' href='" . $ConfSiteBase . "style.css' />\n";

//
// This is the person advertised as the appropriate person
// to contact w.r.t problems
//
$Conf->contactName = "Eddie Kohler";
$Conf->contactEmail = "kohler@cs.ucla.edu";
//
// Email appears to come from this address
//
$Conf->emailFrom = "kohler@read.cs.ucla.edu";

//
// This is where dumps of the database are placed
//
$Conf->dbDumpDir="/var/spool/conference";
