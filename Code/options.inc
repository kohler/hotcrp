<?php
// options.inc -- HotCRP conference options
// Placed in the public domain

/*****************************************************************************
 * HotCRP User Settings
 * Required for all installations.
 * Set up this file before running HotCRP for the first time.
 *****************************************************************************/

// CONFERENCE NAMES
//
//   dbName          Database name.  Use the name you supplied to
//                   "Code/createdb.sh".  NO SPACES ALLOWED.
//   dbUser	     Database user name.  Defaults to $Opt['dbUser'].
//   dbPassword      Password for database user.  Defaults to $Opt['dbName'].
//   shortName       Short name of the conference.  Examples: "SIGCOMM 2007",
//                   "HotNets V".
//   longName        Longer name of the conference.  Example: "ACM SIGCOMM
//                   2007 Conference".
//   downloadPrefix  Prefix for downloaded files, such as papers.  Should
//                   end in a dash.

$Opt['dbName'] = "HotNetsV";
$Opt['shortName'] = "HotNets V";
$Opt['longName'] = "HotNets V";
$Opt['downloadPrefix'] = "hotnets5-";


// EMAIL
//
//   contactName     Full name for site administrator (the person to contact
//                   about site problems).  Usually the PC chair(s).
//   contactEmail    Email address for site administrator.
//   sendEmail       Boolean.  If false, HotCRP will send no email.  Generally
//                   it is safe to set this to true.
//   emailFrom       Email sent by HotCRP appears to come from this address.
//                   Should be a plausible email address for mail originating
//                   at the conference server, so things like SPF don't mistake
//                   the mail for spam.

$Opt['contactName'] = "Eddie Kohler";
$Opt['contactEmail'] = "kohler@cs.ucla.edu";
$Opt['sendEmail'] = true;
$Opt['emailFrom'] = "kohler@read.cs.ucla.edu";


// SUBMISSIONS
//
//   emailSubmissions  If this is non-zero, then it should be an email address.
//                   All submissions are MIME-encoded and sent to this address
//                   as a backup.  Note that this will increase server load.

$Opt['emailSubmissions'] = 0;


// URLS
//
//   conferenceSite  Conference site URL (e.g., CFP, registration).
//   paperSite       URL for this HotCRP installation.  Used in emails.

$Opt['conferenceSite'] = "http://www.acm.org/sigs/sigcomm/HotNets-V/";
$Opt['paperSite'] = "http://www.read.cs.ucla.edu/hotnets5";


// OPTIONAL SETTINGS (the defaults are reasonable)
//
//   sessionName     Internal name used to distinguish conference sessions
//                   running on the same server.  NO SPACES ALLOWED.  Defaults
//		     to $Opt["dbName"].
//   sessionLifetime Number of seconds a user may be idle before their session
//                   is garbage collected and they must log in again.  Defaults
//                   to 86400 (24 hours).
//                   DEBIAN NOTE: On some Debian installations, a cron job
//                   garbage-collects old sessions.  That cron job ignores
//                   local 'session.gc_maxlifetime' settings, and thus
//                   $Opt['sessionLifetime'], so you'll also need to change
//		     the system-wide setting in 'php.ini'.
//   memoryLimit     Maximum amount of memory a PHP script can use.  Defaults
//		     to 128MB.
//   disablePDF      Set to disable PDF format submissions.
//   disablePS       Set to disable PostScript format submissions.
//   dsn             Database configuration information in the format
//                   "mysql://DBUSER:DBPASSWORD@127.0.0.1/DBNAME".
//                   The default is derived from $Opt["dbName"], assuming
//		     that DBUSER, DBPASSWORD, and DBNAME are all the same.
//                   Example: "mysql://HotNetsV:HotNetsV@127.0.0.1/HotNetsV".
//   dbDumpDir       Directory where user-requested database dumps are stored.
