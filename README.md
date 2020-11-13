HotCRP Conference Review Software [![Build Status](https://travis-ci.org/kohler/hotcrp.svg?branch=master)](https://travis-ci.org/kohler/hotcrp)
=================================

HotCRP is awesome software for managing review processes, especially
for academic conferences. It supports paper submission, review and
comment management, rebuttals, and the PC meeting. Its main strengths
are flexibility and ease of use in the review process, especially
through smart paper search and an extensive tagging facility. It is
widely used in computer science conferences and for internal review
processes at several large companies.

Multitrack conferences with per-track deadlines should use other software.

HotCRP is the open-source version of the software running on
[hotcrp.com](https://hotcrp.com). If you want to run HotCRP without setting
up your own server, use hotcrp.com.

Prerequisites
-------------

HotCRP runs on Unix, including Mac OS X. It requires the following
software:

* Nginx, http://nginx.org/ \
  (You may be able to use another web server that works with PHP.)
* PHP version 7.0 or higher, http://php.net/
  - Including MySQL support, php-fpm, php-gmp, and php-intl
* MySQL version 5 or higher, http://mysql.org/
* The zip compressor, http://www.info-zip.org/
* Poppler’s version of pdftohtml, http://poppler.freedesktop.org/ (only
  required for format checking)

You may need to install additional packages, such as php73, php73-fpm,
php73-intl, php73-mysqlnd, zip, poppler-utils, and sendmail or postfix.

Installation
------------

1. Run `lib/createdb.sh` to create the database. Use
`lib/createdb.sh OPTIONS` to pass options to MySQL, such as `--user`
and `--password`. Many MySQL installations require privilege to create
tables, so you may need `sudo lib/createdb.sh OPTIONS`. Run
`lib/createdb.sh --help` for more information. You will need to
decide on a name for your database (no spaces allowed).

    The username and password information for the conference database is
stored in `conf/options.php`, which HotCRP marks as world-unreadable. You must
ensure that your PHP can read this file.

    If you don’t want to run `lib/createdb.sh`, you will have to create your
own database and user, initialize the database with the contents of
`src/schema.sql`, and create `conf/options.php` (use `src/distoptions.php`
as a guide).

2. Edit `conf/options.php`, which is annotated to guide you.
(`lib/createdb.sh` creates this file based on `src/distoptions.php`.)

3. Configure your web server to access HotCRP. For Nginx, configure Nginx to
access `php-fpm` for anything under the HotCRP URL path. All accesses
should be redirected to `index.php`. This example, which would go in a
`server` block, makes `/testconf` point at a HotCRP installation in
/home/kohler/hotcrp (assuming `php-fpm` is listening on port 9000):

        location /testconf/ {
            fastcgi_pass 127.0.0.1:9000;
            fastcgi_split_path_info ^(/testconf)(/.*)$;
            fastcgi_param PATH_INFO $fastcgi_path_info;
            fastcgi_param SCRIPT_FILENAME /home/kohler/hotcrp/index.php;
            include fastcgi_params;
        }

    You may also set up separate `location` blocks so that Nginx
serves files under `images/`, `scripts/`, and `stylesheets/` directly.

4. Update PHP settings.

    The first three settings, `upload_max_filesize`, `post_max_size`, and
`max_input_vars`, may be changed system-wide or in HotCRP’s `.htaccess` and
`.user.ini` files.

  * `upload_max_filesize`: Set to the largest file upload HotCRP should accept.
    `15M` is a good default.

  * `post_max_size`: Set to the largest total upload HotCRP should accept. Must
    be at least as big as `upload_max_filesize`. `20M` is a good default.

  * `max_input_vars`: Set to the largest number of distinct input variables
    HotCRP should accept. `4096` is a good default.

    The last setting, `session.gc_maxlifetime`, must be changed globally. This
provides an upper bound on HotCRP session lifetimes (the amount of idle time
before a user is logged out automatically). On Unix machines, systemwide PHP
settings are often stored in `/etc/php.ini`. The suggested value for this
setting is 86400, e.g., 24 hours:

        session.gc_maxlifetime = 86400

    If you want sessions to expire sooner, we recommend you set
`session.gc_maxlifetime` to 86400 anyway, then edit `conf/options.php`
to set `$Opt["sessionLifetime"]` to the correct session timeout.

5. Edit MySQL’s my.cnf (typical locations: `/etc/mysql/my.cnf` or
`/etc/mysql/mysql.conf.d/mysqld.cnf`) to ensure that MySQL can handle
paper-sized objects.  It should contain something like this:

        [mysqld]
        max_allowed_packet=32M

    max_allowed_packet must be at least as large as the largest paper
you are willing to accept. It defaults to 1M on some systems, which is
not nearly large enough. HotCRP will warn you if it is too small. Some
MySQL setups, such as on Mac OS X, may not have a my.cnf by default;
just create one. If you edit my.cnf, also restart the mysqld server.
On Linux try something like `sudo /etc/init.d/mysql restart`.

6. Enable a mail transport agent, such as Postfix or Sendmail. You may need
help from an administrator to ensure HotCRP can send mail.

7. Sign in to the site to create an account. The first account created
automatically receives system administrator privilege.

You can set up everything else through the web site itself.

* Configuration notes

  - Uploaded papers and reviews are limited in size by several PHP
    configuration variables, set by default to 15 megabytes in the HotCRP
    directory’s `.user.ini` (or `.htaccess` if using Apache).

  - HotCRP PHP scripts can take a lot of memory, particularly if they're
    doing things like generating MIME-encoded mail messages. By default
    HotCRP sets the PHP memory limit to 128MB.

  - Most HotCRP settings are assigned in the conference database’s
    Settings table. The Settings table can also override values in
    `conf/options.php`: a Settings record with name "opt.XXX" takes
    precedence over option $Opt["XXX"].

Database access
---------------

Run `lib/backupdb.sh` at the shell prompt to back up the database.
This will write the database’s current structure and comments to the
standard output. As typically configured, HotCRP stores all paper
submissions in the database, so the backup file may be quite large.

Run `lib/restoredb.sh BACKUPFILE` at the shell prompt to restore the
database from a backup stored in `BACKUPFILE`.

Run `lib/runsql.sh` at the shell prompt to get a MySQL command prompt
for the conference database.

Updates
-------

HotCRP code can be updated at any time without bringing down the site.
If you obtained the code from git, use `git pull`. if you obtained
the code from a tarball, copy the new version over your old code,
preserving `conf/options.php`. For instance, using GNU tar:

    % cd HOTCRPINSTALLATION
    % tar --strip=1 -xf ~/hotcrp-NEWVERSION.tar.gz

License
-------

HotCRP is available under the Click license, a BSD-like license. See the
LICENSE file for full license terms.

Authors
-------

Eddie Kohler, Harvard

* HotCRP is based on CRP, which was written by Dirk Grunwald,
  University of Colorado
* HotCRP’s banal is substantially modified from the original
  [banal by Geoff Voelker, UCSD](http://www.sysnet.ucsd.edu/sigops/banal/)
