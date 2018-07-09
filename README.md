HotCRP Conference Review Software [![Build Status](https://travis-ci.org/kohler/hotcrp.svg?branch=master)](https://travis-ci.org/kohler/hotcrp)
=================================

HotCRP is the best available software for managing the conference
review process, including paper submission, review and comment
management, rebuttals, and the PC meeting. Its main strengths are
flexibility and ease of use in the review process, especially through
smart paper search and an extensive tagging facility. It is widely
used in computer science conferences and for internal review processes
at several large companies.

Multitrack conferences with per-track deadlines should use other software.

HotCRP is the open-source version of the software running on
[hotcrp.com](https://hotcrp.com). If you want to run HotCRP without setting
up your own server, use hotcrp.com.

Prerequisites
-------------

HotCRP runs on Unix, including Mac OS X. It requires the following
software:

* Nginx, http://nginx.org/ or Apache, http://apache.org/
  (You may be able to use another web server that works with PHP.)
* PHP version 5.6 or higher, http://php.net/
  - Including MySQL support
* MySQL version 5 or higher, http://mysql.org/
* The zip compressor, http://www.info-zip.org/
* Poppler’s version of pdftohtml, http://poppler.freedesktop.org/ (only
  required for format checking)

Apache is preloaded on most Linux distributions. You may need to install
additional packages, such as php71, php71-fpm, php71-mysqlnd, zip,
poppler-utils, and sendmail or postfix. You may need to restart the Apache web
server after installing these packages (`sudo apachectl graceful` or `sudo
apache2ctl graceful`). If using nginx, you will need php-fpm.

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

2. Edit `conf/options.php`, which is annotated to guide you.
(`lib/createdb.sh` creates this file based on `src/distoptions.php`.)

3. Configure your web server to access HotCRP. The right way to do this
depends on which server you’re running.

    **Nginx**: Configure Nginx to access `php-fpm` for anything under
the HotCRP URL path. All accesses should be redirected to `index.php`.
This example, which would go in a `server` block, makes `/testconf`
point at a HotCRP installation in /home/kohler/hotcrp (assuming
`php-fpm` is listening on port 9000):

        location /testconf/ {
            fastcgi_pass 127.0.0.1:9000;
            fastcgi_split_path_info ^(/testconf)(/.*)$;
            fastcgi_param PATH_INFO $fastcgi_path_info;
            fastcgi_param SCRIPT_FILENAME /home/kohler/hotcrp/index.php;
            include fastcgi_params;
        }

    You may also set up separate `location` blocks so that Nginx
serves files under `images/`, `scripts/`, and `stylesheets/` directly.

    **Apache**: Generally you must add a `<Directory>` to `httpd.conf`
(or one of its inclusions) for the HotCRP directory, and an `Alias`
redirecting your preferred URL path to that directory. This example
makes `/testconf` point at a HotCRP installation in
/home/kohler/hotcrp:

        # Apache 2.2 and earlier:
        <Directory "/home/kohler/hotcrp">
            Options Indexes Includes FollowSymLinks
            AllowOverride all
            Order allow,deny
            Allow from all
        </Directory>
        Alias /testconf /home/kohler/hotcrp
        
        # Apache 2.4 and later:
        <Directory "/home/kohler/hotcrp">
            Options Indexes Includes FollowSymLinks
            AllowOverride all
            Require all granted
        </Directory>
        Alias /testconf /home/kohler/hotcrp

    Note that the first argument to Alias should NOT end in a slash.
The `AllowOverride all` directive is required.

    Everything under HotCRP’s URL path (here, `/testconf`) should be
served by HotCRP. This normally happens automatically. However, if
the URL path is `/`, you may need to turn off your server’s default
handlers for subdirectories such as `/doc`.

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
    directory’s `.htaccess` (or `.user.ini` if you are using php-fpm).

  - HotCRP PHP scripts can take a lot of memory, particularly if they're
    doing things like generating MIME-encoded mail messages.  By default
    HotCRP sets the PHP memory limit to 128MB.

  - HotCRP benefits from Apache’s `mod_expires` and `mod_rewrite`
    modules; consider enabling them.

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

Multiconference support
-----------------------

HotCRP can run multiple conferences from a single installation. The
last directory component of the URL will define the conference ID.
For instance:

    http://read.seas.harvard.edu/conferences/testconf/doc/testconf-paper1.pdf
                                             ^^^^^^^^
                                           conference ID

The conference ID can only contain characters in `[-_.A-Za-z0-9]`, and
it must not start with a period. HotCRP will check for funny
conference IDs and replace them with `__invalid__`.

To turn on multiconference support:

1. Set your Web server to use the HotCRP install directory for all relevant
   URLs. For Apache, this may require an `Alias` directive per conference.

2. Set `$Opt["multiconference"]` to true in `conf/options.php`. This will set
   the conference ID to the last directory component as described above.
   Alternately, set `$Opt["multiconferenceAnalyzer"]` to a regular expression,
   a space, and a replacement pattern. HotCRP matches the full input URL to
   the regex, then uses the replacement pattern as the conference ID. For
   example, this setting will use "conf_CONFNAME" as the conference ID for a
   URL like "http://CONFNAME.crap.com/":

        $Opt["multiconferenceAnalyzer"] = '\w+://([^./]+)\.crap\.com\.?/ conf_$1';

3. Set HotCRP options to locate the options relevant for each conference. The
   best mechanism is to use `$Opt["include"]` to include a conference-specific
   options file. For example (note the single quotes):

        $Opt["include"] = 'conf/options-${confid}.php';

    The `${confid}` substring is replaced with the conference ID. HotCRP will
refuse to proceed if the conference-specific options file doesn’t exist. To
ignore nonexistent options files, use wildcards:

        $Opt["include"] = 'conf/[o]ptions-${confid}.php';

    `${confid}` replacement is also performed on these $Opt settings: dbName,
dbUser, dbPassword, sessionName, downloadPrefix, conferenceSite, paperSite,
defaultPaperSite, contactName, contactEmail, emailFrom, emailSender, emailCc,
emailReplyTo, and docstore.

4. Each conference needs its own database. Create one using the
   `lib/createdb.sh` script (the `-c CONFIGFILE` option will be useful).


Docker
-------

Start hotcrp in docker-environment

1) Start docker compse

`docker-compose up`

2) Only on first run: Initialize database

```
# attach to mysql docker 
docker exec -i -t hotcrp-database /bin/bash

# create database
# select no when asked for database creation, only fill database with scheme!!!!!
# ok -> hotcrp -> n -> Y
./lib/createdb.sh --dbuser=hotcrp,hotcrppwd --user=root --password=rootpwd
```

3) control conf/options.php may add:

```$xslt
$Opt["dsn"] = "mysql://hotcrp:hotcrppwd@hotcrp-mysql:3306/hotcrp";
```

5) Check connection 

`http://localhost:9000` 


License
-------

HotCRP is available under the Click license, a BSD-like license. See the
LICENSE file for full license terms.

Authors
-------

Eddie Kohler, Harvard/UCLA

* HotCRP is based on CRP, which was written by Dirk Grunwald,
  University of Colorado
* [banal by Geoff Voelker, UCSD](http://www.sysnet.ucsd.edu/sigops/banal/)
