<?php
// options.php -- HotCRP conference options
// Placed in the public domain

/*****************************************************************************
 * HotCRP Settings
 * Required for all installations.
 * Set up this file before running HotCRP for the first time.
 *****************************************************************************/

global $Opt;

// MANDATORY CONFIGURATION
//
//   dbName          Database name. NO SPACES ALLOWED.
//   dbUser          Database user name. Defaults to $Opt["dbName"].
//   dbPassword      Password for database user.

$Opt["dbName"] = "FIXME";


// GENERAL CONFIGURATION
//
//   include         Other configuration files to load. String or array of
//                   strings. Wildcards are expanded (e.g., "conf/*.conf");
//                   relative paths are interpreted based on HOTCRPDIR.
//   multiconference, multiconferenceAnalyzer
//                   Support multiple conferences from a single installation.
//                   See README.md.


// NAMES AND SITES
//
//   shortName       Short name of the conference, including the year or
//                   number. Examples: "SIGCOMM 2007", "HotNets V".
//   longName        Longer name of the conference. Example: "ACM SIGCOMM
//                   2007 Conference".
//   downloadPrefix  Prefix for downloaded files, such as papers; should
//                   end in a dash. Example: "hotnets5-". Defaults to
//                   $Opt["dbName"] plus a dash.
//   paperSite       [OPTIONAL] URL for this HotCRP installation. Used in
//                   emails. Default is derived from the access URL.
//   conferenceSite  [OPTIONAL] Conference site URL (CFP, registration).


// EMAIL
//
//   contactName     Full name for site contact (the person to contact about
//                   site problems). Usually the PC chair(s).
//   contactEmail    Email address for site contact.
//   sendEmail       Boolean. If false, HotCRP will send no email. This should
//                   almost always be set to true.
//   emailFrom       "From:" header for email sent by HotCRP. Should be a
//                   plausible email address for mail originating at the
//                   conference server.
//   emailSender     Envelope sender address for email sent by HotCRP. Usually
//                   defaults to something like "www-data@yourservername".
//                   This email address should be connected to a valid mailbox
//                   or certain spam filters will reject email from HotCRP.
//   emailCc         Default "Cc:" address for email sent by HotCRP to
//                   reviewers and via the mail tool. (Does not apply to most
//                   email automatically sent to submitters.) If not set, the
//                   default Cc is $Opt["contactEmail"].
//   emailReplyTo    If set, default "Reply-To:" address for email sent by
//                   HotCRP.
//   sendmailParam   Extra parameters to be passed to your mailer. The default
//                   is derived from $Opt["emailSender"]. If your system's
//                   mailer is not sendmail, you may need to change the
//                   default value; see src/mailer.php.
//   internalMailer  Set to true to use HotCRP's replacement for PHP's weirdo
//                   mail() function, false to use PHP's mail() function.
//                   Defaults to false on Windows, true elsewhere. A
//                   replacement for PHP's mail() is desired because of PHP
//                   mail()'s confused handling of CRLF issues.
//   postfixMailer   Set to true if your system mailer is UNIX Postfix and
//                   HotCRP mail has garbled headers, particularly for long or
//                   non-ASCII subject lines. (The symptom is that some mail
//                   headers will appear as part of the message body.) It's
//                   always safe to set postfixMailer to true, although the
//                   resulting mails may not be standards compliant.

$Opt["contactName"] = "Your Name";
$Opt["contactEmail"] = "you@example.com";
$Opt["sendEmail"] = true;
$Opt["emailFrom"] = "you@example.com";
$Opt["emailSender"] = null;


// -------------------------------------------------------------------------
// OTHER CONFIGURATION OPTIONS
// -------------------------------------------------------------------------

// USER ACCOUNTS
//
//   ldapLogin       If set, use LDAP to authenticate users. The ldapLogin
//                   string must have the form "LDAP_URL DN_PATTERN", where
//                   DN_PATTERN contains a "*" character to be replaced by
//                   the username. Example: "ldaps://ldapserver/ uid=*,o=ORG"
//   httpAuthLogin   If set, use HTTP authentication to authenticate users.
//                   Requires additional web server configuration. A string
//                   value is sent as a WWW-Authenticate header. The default
//                   string is "Basic realm="HotCRP"".
//   defaultEmailDomain Set to the default domain for account email addresses
//                   when using httpAuthLogin.


// USER PASSWORDS
//
//   safePasswords   Controls how passwords are stored in the database. If
//                   false, passwords are stored in plaintext; if true, user-
//                   chosen passwords are stored as cryptographic hashes with
//                   random salts, which are less vulnerable to cracking.
//                   True is the default. Randomly-generated passwords, such
//                   as those generated for new accounts, are stored in
//                   plaintext for usability reasons. Set safePasswords to 2
//                   to opportunistically upgrade these passwords to hashes.
//   chairHidePasswords  If true, then chairs cannot view or modify other
//                   users' passwords. Defaults to false.
//
//   In PHP versions below 5.5, password hashing uses these settings:
//   passwordHmacKey  Secret key used for password HMAC.
//   passwordHmacKeyid  If a secret key is compromised, change
//                   passwordHmacKeyid to switch keys. Defaults to 0.
//   passwordHmacKey.ID  Secret key for passwordHmacKeyid==ID.

$Opt["safePasswords"] = true;
$Opt["passwordHmacKey"] = null;


// PAPER STORAGE
//
//   disablePS       Set to true to disable PostScript format submissions.
//   noPapers        Set to true to collect abstracts only, not papers.
//   docstore        Set to true to serve papers and other downloads from a
//                   cache on the local filesystem. By default this cache is
//                   created in the "docs" directory. You can also set
//                   $Opt["docstore"] to a directory name.
//   docstoreSubdir  Set to true (or a small number, like 3) if the document
//                   store should use subdirectories. This can be useful if
//                   you expect thousands of submissions.
//   s3_bucket       Amazon S3 bucket name to store paper submissions.
//   s3_key          Amazon AWS access key ID (used for S3).
//   s3_secret       Amazon AWS secret access key (used for S3).
//   dbNoPapers      Set to true to not store papers in the database.
//                   Requires filestore, S3 storage, or both.

$Opt["disablePS"] = true;


// TIMES AND DATES
//
//   timezone        Server timezone. See http://php.net/manual/en/timezones
//                   for a list. Defaults to America/New_York if you haven't
//                   set a server-wide PHP timestamp in `php.ini`.
//   time24hour      Set to true to use 24-hour timestamps, rather than the
//                   default am/pm.
//   dateFormat      Format for displaying short dates. Uses PHP date() syntax:
//                   http://www.php.net/manual/en/function.date.php
//                   Defaults to "j M Y H:i:s" [e.g., "1 Jan 2012 00:00:00"]
//                   or "j M Y g:i:sa" [e.g., "1 Jan 2012 12:00:00am"].
//   dateFormatLong  Format for displaying long dates. Defaults to
//                   "l " + dateFormat [e.g., "Tuesday 1 Jan 2012 12:00:00am"].
//   timestampFormat Format for displaying paper timestamps. Defaults to
//                   dateFormat.
//   dateFormatSimplifier Regular expression used to simplify dates. The
//                   default removes ":00" from the ends of dates.
//   dateFormatTimezone Timezone abbreviation used to print dates. Defaults to
//                   the system's timezone abbreviation.


// DISPLAY CUSTOMIZATION OPTIONS
//
//   favicon         Link to favicon. Default is images/review24.png.
//   stylesheets     Array of additional stylesheet filenames/URIs to be
//                   included after "style.css". Example: array("x.css").
//   fontScript      HTML added to <head> before stylesheets.
//   extraFooter     Extra HTML text shown at the bottom of every page, before
//                   the HotCRP link. If set, should generally end with
//                   " <span class='barsep'>|</span> ".
//   assetsUrl       URL prefix for assets (stylesheets/, scripts/, images/).
//                   Defaults to the conference installation.
//   scriptAssetsUrl URL prefix for script assets. Defaults to assetsURL,
//                   except for browsers known to ignore `crossorigin`, where
//                   it defaults to the conference installation.
//   jqueryUrl       URL for jQuery. Defaults to the local minified jquery.
//   jqueryCdn       If true, use the jQuery CDN.
//   redirectToHttps If set to true, then HotCRP will redirect all http
//                   connections to https.
//   allowLocalHttp  Only meaningful if redirectToHttps is set. If true, then
//                   HotCRP will *not* redirect http connections that
//                   originate from localhost.


// BEHAVIOR OPTIONS
//
//   sortByLastName  Set to true to sort users by last name.
//   smartScoreCompare Set to true if a search like "ovemer:>B" should search
//                   for scores better than B (i.e., A), rather than scores
//                   alphabetically after B (i.e., C or D).
//   noFooterVersion Set to true to avoid a version comment in footer HTML.
//   strictJavascript  If true, send Javascript with "use strict".
//   disableCsv      Set to true if downloaded information files should be
//                   tab-separated rather than CSV.
//   hideManager     If set, PC members are not shown paper managers.
//   disableCapabilities If set, emails to authors will not have a
//                   token enabling them to view their papers without logging in.
//   mobileStylesheet  If true, make the site mobile aware.

$Opt["smartScoreCompare"] = true;


// EXTERNAL SOFTWARE CONFIGURATION
//
//   dbHost          Database host. Defaults to localhost.
//   dsn             Database configuration string in the format
//                   "mysql://DBUSER:DBPASSWORD@DBHOST/DBNAME".
//                   The default is derived from $Opt["dbName"], etc.
//   sessionName     Internal name used to distinguish conference sessions
//                   running on the same server. NO SPACES ALLOWED. Defaults
//                   to $Opt["dbName"].
//   sessionLifetime Number of seconds a user may be idle before their session
//                   is garbage collected and they must log in again. Defaults
//                   to 86400 (24 hours). Should be less than or equal to the
//                   system-wide setting for `session.gc_maxlifetime` in
//                   the PHP initialization file, `php.ini`.
//   sessionSecure   If true, then set the session cookie only on secure
//                   connections. Defaults to false.
//   sessionDomain   The domain scope for the session cookie. Defaults to the
//                   server's domain. To share a cookie across subdomains,
//                   prefix it with a dot: ".hotcrp.com".
//   memoryLimit     Maximum amount of memory a PHP script can use. Defaults
//                   to 128MB.
//   pdftohtml       Pathname to pdftohtml executable (used only by the "banal"
//                   paper format checker).
//   banalLimit      Limit on number of parallel paper format checker
//                   executions. Defaults to 8.
//   zipCommand      Set to the path to the `zip` executable. Defaults to
//                   `zip`.
