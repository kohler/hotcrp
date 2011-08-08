<?php
// options.inc -- HotCRP conference options
// Placed in the public domain

/*****************************************************************************
 * HotCRP User Settings
 * Required for all installations.
 * Set up this file before running HotCRP for the first time.
 *****************************************************************************/

global $Opt;

// CONFERENCE NAMES
//
//   dbName          Database name.  Use the name you supplied to
//                   "Code/createdb.sh".  NO SPACES ALLOWED.
//   dbUser          Database user name.  Defaults to $Opt["dbName"].
//   dbPassword      Password for database user.  Defaults to $Opt["dbName"].
//   shortName       Short name of the conference.  Examples: "SIGCOMM 2007",
//                   "HotNets V".
//   longName        Longer name of the conference.  Example: "ACM SIGCOMM
//                   2007 Conference".
//   downloadPrefix  Prefix for downloaded files, such as papers.  Should
//                   end in a dash.  Example: "hotnets5-".  Defaults to
//                   $Opt["dbName"] plus a dash.
//   If your mysql database isn't running on localhost, see $Opt["dsn"] below.

$Opt["dbName"] = "YourConfI";
$Opt["shortName"] = "YourConf I";
$Opt["longName"] = "Your Conference I";
$Opt["downloadPrefix"] = "yourconf1-";


// URLS
//
//   conferenceSite  Conference site URL (e.g., CFP, registration).
//   paperSite       URL for this HotCRP installation.  Used in emails.

$Opt["conferenceSite"] = "http://your.site.here.com/";
$Opt["paperSite"] = "http://your.site.here.com/";


// EMAIL
//
//   contactName     Full name for site administrator (the person to contact
//                   about site problems).  Usually the PC chair(s).
//   contactEmail    Email address for site administrator.
//   sendEmail       Boolean.  If false, HotCRP will send no email.  This
//                   should almost always be set to true.
//   emailFrom       "From:" address for email sent by HotCRP.  Should be a
//                   plausible email address for mail originating at the
//                   conference server, so things like SPF don't mistake
//                   the mail for spam.
//   emailSender     Envelope sender address for email sent by HotCRP.  Usually
//                   defaults to something like "www-data@yourservername".
//                   This email address should be connected to a valid mailbox
//                   or certain spam filters will reject email from HotCRP.
//   emailCc         Default "Cc:" address for email sent by HotCRP to
//                   reviewers and via the mail tool.  (Does not apply to most
//                   email automatically sent to submitters.)  If not set, the
//                   default Cc is $Opt["contactEmail"].
//   emailReplyTo    If set, default "Reply-To:" address for email sent by
//                   HotCRP.
//   sendmailParam   Extra parameters to be passed to PHP's mail() function.
//                   If your system's mailer is not sendmail, you may need to
//                   change the default value; see Code/mailtemplate.inc,
//                   sendPrepared().
//   postfixEOL      Set to true if your system mailer is UNIX Postfix and
//                   HotCRP mail has garbled headers, particularly for long or
//                   non-ASCII subject lines.  (The symptom is that some mail
//                   headers will appear as part of the message body.)  It's
//                   always safe to set postfixEOL to true, although the
//                   resulting mails may not be standards compliant.

$Opt["contactName"] = "Your Name";
$Opt["contactEmail"] = "your@name.edu";
$Opt["sendEmail"] = true;
$Opt["emailFrom"] = "from@your.site.here.com";


// SUBMISSIONS
//
//   emailSubmissions  If this is non-zero, then it should be an email address.
//                   All submissions are MIME-encoded and sent to this address
//                   as a backup.  Note that this will increase server load.

$Opt["emailSubmissions"] = 0;


// OPTIONAL SETTINGS (the defaults are reasonable)
//
//   dsn             Database configuration information in the format
//                   "mysql://DBUSER:DBPASSWORD@DBHOST/DBNAME".
//                   The default is derived from $Opt["dbName"], assuming
//                   that DBUSER, DBPASSWORD, and DBNAME are all the same
//                   and that DBHOST is "localhost".
//                   Example: "mysql://HotNetsV:HotNetsV@localhost/HotNetsV".
//   multiconference Set to true to run multiple conferences out of this
//                   installation.  See README.
//   sessionName     Internal name used to distinguish conference sessions
//                   running on the same server.  NO SPACES ALLOWED.  Defaults
//                   to $Opt["dbName"].
//   sessionLifetime Number of seconds a user may be idle before their session
//                   is garbage collected and they must log in again.  Defaults
//                   to 86400 (24 hours).  Should be less than or equal to the
//                   system-wide setting for 'session.gc_maxlifetime' in
//                   the PHP initialization file, 'php.ini'.
//   memoryLimit     Maximum amount of memory a PHP script can use.  Defaults
//                   to 128MB.
//   timezone        Server timezone.  See http://php.net/manual/en/timezones
//                   for a list.
//   time24hour      Set to true to use 24-hour timestamps, rather than the
//                   default am/pm.
//   stylesheets     Optional array of additional stylesheet filenames/URIs to
//                   be included after "style.css".  Example: array("x.css").
//   extraFooter     Optional extra HTML text shown at the bottom of every
//                   page, before the HotCRP link.  If set, should generally
//                   end with " <span class='barsep'>|</span> ".
//   favicon         Optional link to favicon.  Default is images/review24.png.
//   noFooterVersion Set to true to avoid a version comment in footer HTML.
//   disablePDF      Set to true to disable PDF format submissions.
//   disablePS       Set to true to disable PostScript format submissions.
//   disableSHA1     Set to true to not store SHA-1 hashes of submissions.
//   noPapers        Set to true to collect abstracts only, not papers.
//   pdftohtml       Pathname to pdftohtml executable (used only by the "banal"
//                   paper format checker).
//   banalLimit      Limit on number of parallel paper format checker
//                   executions.  Defaults to 8.
//   ldapLogin       If set, use LDAP to authenticate users.  The ldapLogin
//                   string must have the form "LDAP_URL DN_PATTERN", where
//                   DN_PATTERN contains a "*" character to be replaced by
//                   the username.  Example: "ldaps://ldapserver/ uid=*,o=ORG"
//   zipCommand      Set to the path to the "zip" executable.  Defaults to
//                   "zip".
//   disableCSV      Set to true if all downloaded tabular text files should be
//                   tab-separated rather than CSV.

$Opt["disablePS"] = true;
