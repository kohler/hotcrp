<?php

$Opt['sendEmail'] = 0;
$Opt['setupPhase'] = 0;
$Opt['emailSubmissions'] = 0;

$Opt['allowPDF'] = 1;
$Opt['allowPS'] = 1;

// You need a unique session name to distinguish conference sessions
// running on the same server. DO NOT PUT SPACES IN THE SESSION NAME!!
//
$Opt['sessionName'] = "HotNetsV";

//
// Conference names
//
// You need a unique session name to distinguish conference sessions
// running on the same server. DO NOT PUT SPACES IN THE SESSION NAME!!
//
$Opt['shortName'] = "HotNets V";
$Opt['longName'] = "HotNets V";

//
// The conference site (i.e. CFP, registration)
//
$Opt['conferenceSite'] = "http://www.acm.org/sigs/sigcomm/HotNets-V/";

//
// This is the person advertised as the appropriate person
// to contact w.r.t problems
//
$Opt['contactName'] = "Eddie Kohler";
$Opt['contactEmail'] = "kohler@cs.ucla.edu";
//
// Email appears to come from this address
//
$Opt['emailFrom'] = "kohler@read.cs.ucla.edu";

//
// This is the location of the hypothetical conference
// the test server
//
$Opt['paperSite'] = "http://www.read.cs.ucla.edu/hotnets5";

//
// The prefix for downloaded files
//
$Opt['downloadPrefix'] = "hotnets5-";

//
// A CSS, if you want one
//
$Opt['theme'] = "<link rel='stylesheet' type='text/css' href='" . $ConfSiteBase . "style.css' />\n";

//
// Database configuration information
//
$Opt['dbHost'] = "127.0.0.1";
$Opt['dbUser'] = "HotNetsV";
$Opt['dbPassword'] = "HotNetsV";
$Opt['dbName'] = "HotNetsV";

//
// This is where dumps of the database are placed
//
$Opt['dbDumpDir'] = "/var/spool/conference";
