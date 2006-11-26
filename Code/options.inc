<?php

/*****************************************************************************
 * CRP User Settings
 * Required for all installations.
 * Set up this file before running CRP for the first time.
 *
 *****************************************************************************/

// CONFERENCE NAMES
//
//   sessionName     Internal name used to distinguish conference sessions
//                   running on the same server. NO SPACES ALLOWED.
//   shortName       Short name of the conference.  Examples: "SIGCOMM 2007",
//                   "HotNets V".
//   longName        Longer name of the conference.  Example: "ACM SIGCOMM
//                   2007 Conference".
//   downloadPrefix  Prefix for downloaded files, such as papers.  Should
//                   end in a dash.

$Opt['sessionName'] = "HotNetsV";
$Opt['shortName'] = "HotNets V";
$Opt['longName'] = "HotNets V";
$Opt['downloadPrefix'] = "hotnets5-";


// EMAIL
//
//   contactName     Full name for site administrator (the person to contact
//                   about site problems).
//   contactEmail    Email address for site administrator.
//   sendEmail       Boolean.  If false, CRP will send no email.  Generally
//                   it is safe to set this to true.
//   emailFrom       Email sent by CRP appears to come from this address.
//                   Should be a plausible email address for mail originating
//                   at the conference site, so things like SPF don't mistake
//                   the mail for spam.

$Opt['contactName'] = "Eddie Kohler";
$Opt['contactEmail'] = "kohler@cs.ucla.edu";
$Opt['sendEmail'] = 0;
$Opt['emailFrom'] = "kohler@read.cs.ucla.edu";


// SUBMISSIONS
//
//   allowPDF        Allow PDF format submissions?  Defaults to true.
//   allowPS         Allow PostScript format submissions?  Defaults to true.
//   emailSubmissions  If this is non-zero, then it should be an email address.
//                   All submissions are MIME-encoded and sent to this address
//                   as a backup.  Note that this will increase server load.

// $Opt['allowPDF'] = false;   // uncomment to disable PDF submissions
// $Opt['allowPS'] = false;    // uncomment to disable PostScript submissions
$Opt['emailSubmissions'] = 0;


// DATABASE
//
//   dsn             Database configuration information in the format
//                   "mysql://DBUSER:DBPASSWORD@127.0.0.1/DBNAME".
//                   Example: "mysql:HotNetsV:HotNetsV@127.0.0.1/HotNetsV".
//   dbDumpDir       Directory where user-requested database dumps are stored.

$Opt['dsn'] = "mysql://HotNetsV:HotNetsV@127.0.0.1/HotNetsV";
$Opt['dbDumpDir'] = "/var/spool/conference";


// URLS
//
//   conferenceSite  Conference site URL (e.g., CFP, registration).
//   paperSite       URL for this CRP installation.  Used in emails.

$Opt['conferenceSite'] = "http://www.acm.org/sigs/sigcomm/HotNets-V/";
$Opt['paperSite'] = "http://www.read.cs.ucla.edu/hotnets5";


// SESSIONS AND WEB SETUP
//
//   theme           <head> code used to define the theme.  Default should
//                   not be changed.
//   sessionLifetime Number of seconds a user may be idle before their session
//                   is garbage collected and they must log in again.  Defaults
//                   to 86400 (24 hours).
//                   DEBIAN NOTE: On some Debian installations, a cron job
//                   garbage-collects old sessions.  That cron job ignores
//                   local 'session.gc_maxlifetime' settings, and thus
//                   $Opt['sessionLifetime'], so in addition to this variable,
//                   you'll also need to change the system-wide setting in
//                   'php.ini'.

// $Opt['theme'] = "whatever";
// $Opt['sessionLifetime'] = 86400;
