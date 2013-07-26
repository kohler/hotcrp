HotCRP Conference Review Software
=================================

HotCRP is the best available software for managing the conference
review process, including paper submission, review and comment
management, rebuttals, and the PC meeting. Its main strengths are
flexibility and ease of use in the review process, especially through
smart paper search and an extensive tagging facility. It is widely
used in computer science conferences and for internal review processes
at several large companies.

HotCRP also has weaknesses. It requires that you run your own server,
and it does not natively support multitrack conferences (although you
can hack something together).

Prerequisites
-------------

HotCRP runs on Unix, including Mac OS X. It requires the following
software:

* Apache, http://apache.org/
  (You may be able to use another web server that works with PHP.)
* PHP version 5.2 or higher, http://php.net/
  - Including MySQL and GD support
* MySQL version 5 or higher, http://mysql.org/
* PHP PEAR extensions, http://pear.php.net/
* The zip compressor, http://www.info-zip.org/
* pdftohtml, http://poppler.freedesktop.org/ (Only required for format
  checking.)

Apache is preloaded on most Linux distributions.  You may need to install
additional packages for PHP, MySQL, GD, and PEAR, such as:

* Fedora Linux: php-mysql, php-pear, php-gd, zip, (poppler-utils)
* Debian Linux: php5-common, php5-gd, php5-mysql, php-pear,
  libapache2-mod-php5 (or libapache-mod-php5 for Apache 1.x),
  zip, (poppler-utils)
* Ubuntu Linux: php5-common, php5-gd, php5-mysql, php-pear,
  libapache2-mod-php5 (or libapache-mod-php5 for Apache 1.x),
  zip, (poppler-utils), and a package for SMTP support, such
  as sendmail

You may need to restart the Apache web server after installing these
packages (`sudo apachectl graceful` or `sudo apache2ctl graceful`).

Versions of the Mail and Mail_Mime PHP PEAR packages are currently
distributed as part of the HotCRP tarball (Mail 1.1.14, Mail_Mime 1.4.0).

**pdftohtml notes**: HotCRP and the banal script use pdftohtml for
paper format checking. As of 2013, many current Unix distributions
ship with a suitable version of pdftohtml, such as “pdftohtml version
0.18.4, Copyright 2005-2011 The Poppler Developers.” Older versions of
pdftohtml may not be suitable. In particular, version 0.40a can be
hundreds of times slower than other versions, and version 0.39 doesn’t
understand the most current PDF standard. If your pdftohtml is bad,
try installing Geoff Voelker’s patched version of pdftohtml; see
http://www.sysnet.ucsd.edu/sigops/banal/download.html.

**Load notes**: HotCRP requires a system with at least 256MB of
memory, more if paper format checking is used and submission load is
expected to be high. If you run HotCRP in a virtual machine, make sure
you configure suitable swap space! HotCRP uses the fast, but less
reliable, MyISAM database engine. If MySQL is killed due to memory
shortages your database may be corrupted.

Installation
------------

1. Run `Code/createdb.sh` to create the database. Use
`Code/createdb.sh OPTIONS` to pass options to MySQL, such as `--user`
and `--password`. Many MySQL installations require privilege to create
tables, so you may need `sudo Code/createdb.sh OPTIONS`. Run
`Code/createdb.sh --help` for more information. You will need to
decide on a name for your database (no spaces allowed).

    The username and password information for the conference database
is stored in `Code/options.inc`, which HotCRP marks as
world-unreadable. You must ensure that your web server can read this
file, for instance by changing its group.

2. Edit `Code/options.inc`, which is annotated to guide you.
(`Code/createdb.sh` creates this file based on
`Code/distoptions.inc`.)

3. Redirect Apache so your server URL will point at the HotCRP
directory. (If you get an Error 500, see "Configuration notes".) This
will generally require adding a `<Directory>` for the HotCRP
directory, and an Alias redirecting a particular URL to that
directory. For example, this section of httpd.conf makes the
"/testconf" URL point at a HotCRP installation in /home/kohler/hotcrp.

        <Directory "/home/kohler/hotcrp">
            Options Indexes Includes FollowSymLinks
            AllowOverride all
            Order allow,deny
            Allow from all
        </Directory>
        Alias /testconf /home/kohler/hotcrp

    Note that the first argument to Alias should NOT end in a slash. The
"AllowOverride all" directive is required.

4. Update the systemwide setting for PHP’s `session.gc_maxlifetime`
configuration variable. This provides an upper bound on HotCRP session
lifetimes (the amount of idle time before a user is logged out
automatically). On Unix machines, systemwide PHP settings are often
stored in `/etc/php.ini`. The suggested value for this setting is
86400, e.g., 24 hours:

        session.gc_maxlifetime = 86400

    If you want sessions to expire sooner, we recommend you set
`session.gc_maxlifetime` to 86400 anyway, then edit Code/options.inc to
set `$Opt["sessionLifetime"]` to the correct session timeout.

5. Edit MySQL’s my.cnf (typical location: `/etc/mysql/my.cnf`) to ensure
that MySQL can handle paper-sized objects.  It should contain something
like this:

        [mysqld]
        max_allowed_packet=32M

    max_allowed_packet must be at least as large as the largest paper
you are willing to accept. It defaults to 1M on some systems, which is
not nearly large enough. HotCRP will warn you if it is too small. Some
MySQL setups, such as on Mac OS X, may not have a my.cnf by default;
just create one. If you edit my.cnf, also restart the mysqld server.
On Linux try something like `sudo /etc/init.d/mysql restart`.

6. Sign in to the site to create an account. The first account created
automatically receives system administrator privilege.

    If your server configuration doesn’t let .htaccess files set
options, Apache will report an “Error 500” when you try to load
HotCRP. Change your Apache configuration to `AllowOverride All` in the
HotCRP directory, as our example does above.

You can set up everything else through the web site itself.

* Configuration notes

  - Uploaded papers and reviews are limited in size by several PHP
    configuration variables, set by default to 15 megabytes in the HotCRP
    directory’s `.htaccess`.

  - HotCRP PHP scripts can take a lot of memory, particularly if they're
    doing things like generating MIME-encoded mail messages.  By default
    HotCRP sets the PHP memory limit to 128MB.

  - HotCRP benefits from Apache’s `mod_expires` and `mod_rewrite`
    modules; consider enabling them.

Backups
-------

Run `Code/backupdb.sh` at the shell prompt to back up the database.
This will write the database’s current structure and comments to the
standard output. HotCRP stores all paper submissions in the database,
so the backup file may be quite large.

Run `Code/restoredb.sh BACKUPFILE` at the shell prompt to restore the
database from a backup stored in `BACKUPFILE`.

Updates
-------

HotCRP code can be updated at any time without bringing down the site.
If you obtained the code from git, use `git pull`. if you obtained
the code from a tarball, copy the new version over your old code,
preserving `Code/options.inc`. For instance, using GNU tar:

    % cd HOTCRPINSTALLATION
    % tar --strip=1 -xf ~/hotcrp-NEWVERSION.tar.gz

Multiconference support
-----------------------

HotCRP supports running multiple conferences from a single
installation. Edit options.inc to set $Opt["multiconference"] to 1.
The last directory component of the URL is used for the database name,
user, and password. For instance:

    http://read.seas.harvard.edu/conferences/testconf/doc/testconf-paper1.pdf
                                             ^^^^^^^^
                                     last directory component

Alternately, you may set $Opt["dbName"], $Opt["dbUser"],
$Opt["dbPassword"], and/or $Opt["dsn"]. HotCRP will edit each of those
settings to replace the "*" character with the last directory component.
(This replacement is also performed on the sessionName, downloadPrefix,
conferenceSite, and paperSite options.)

You will still need to create a new database for each conference using the
`Code/createdb.sh` script, and convince your Apache to use the HotCRP
install directory for all relevant URLs.

To set other $Opt options per conference, such as the conference name and
contact email, modify the conference’s Settings database table. A Settings
row with name "opt.XXX" takes precedence over option $Opt["XXX"]. For
example, to set a conference’s longName:

    mysql> insert into Settings (name, value, data)
	   values ('opt.longName', 1, 'My Conference')
	   on duplicate key update data=values(data);

Note that the `Code/backupdb.sh` script doesn't work for multiconference
installations, and that several important options, such as contactName and
contactEmail, cannot yet be set using the web interface.

License
-------

HotCRP is available under the Click license, a BSD-like license. See the
LICENSE file for full license terms.

Authors
-------

Eddie Kohler, Harvard/UCLA

* HotCRP is based on CRP, which was written by Dirk Grunwald,
  University of Colorado
* banal by Geoff Voelker, UCSD
