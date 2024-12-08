HotCRP Conference Review Software [![Build Status](https://github.com/kohler/hotcrp/actions/workflows/tests.yml/badge.svg)](https://github.com/kohler/hotcrp/actions/workflows/tests.yml)
=================================

HotCRP is awesome software for managing review processes, especially for
academic conferences. It supports paper submission, review and comment
management, rebuttals, and the PC meeting. Its main strengths are flexibility
and ease of use in the review process, especially through smart paper search
and tagging. It has been widely used in computer science conferences and for
internal review processes at several large companies.

HotCRP is the open-source version of the software running on
[hotcrp.com](https://hotcrp.com). If you want to run HotCRP without setting
up your own server, use hotcrp.com.


Getting help
------------

Extensive online help has more on configuring and using HotCRP. See also:

* [The HotCRP development manual](./devel/manual/index.md) to learn about
  advanced configuration, software internals, and developing extensions.
* [The OpenAPI specification](./devel/openapi.json) for API information.


Prerequisites
-------------

HotCRP runs on Unix, including Mac OS X. It requires the following
software:

* Nginx, https://nginx.org/ \
  (Or [Apache](https://httpd.apache.org), or another web server that works with PHP)
* PHP version 7.2 or higher, http://php.net/
  - Including MySQL support, php-fpm, and php-intl
* MariaDB, https://mariadb.org/
* Poppler’s version of pdftohtml, https://poppler.freedesktop.org/ (only
  required for format checking)

You may need to install additional packages, such as php73, php73-fpm,
php73-intl, php73-mysqlnd, zip, poppler-utils, and sendmail or postfix.


Installation
------------

1. Run `lib/createdb.sh` to create the database. Use `lib/createdb.sh OPTIONS`
   to pass options to MariaDB, such as `--user` and `--password`. Many MariaDB
   installations require privilege to create tables, so you may need `sudo
   lib/createdb.sh OPTIONS`. Run `lib/createdb.sh --help` for more
   information. You will need to decide on a name for your database (no spaces
   allowed).

   The username and password information for the conference database is stored
   in `conf/options.php`. You must ensure that your PHP can read this file; it
   is marked as world-unreadable by default.

   If you don’t want to run `lib/createdb.sh`, you will have to create your
   own database and user, initialize the database with the contents of
   `src/schema.sql`, and create `conf/options.php` (using
   `etc/distoptions.php` as a guide).

2. Edit `conf/options.php`, which is annotated to guide you.

3. Configure your web server so that all accesses to the HotCRP site are
   handled by HotCRP’s `index.php` script. The right way to do this depends on
   your choice of server. We recommend using `php-fpm` with Nginx, but Apache
   also works. In the following examples, `SITE/testconf` is configured for a
   HotCRP installation in `/home/kohler/hotcrp`.

   **Nginx**: Configure Nginx to redirect accesses to `php-fpm` and the HotCRP
   `index.php` script. This example code would go in a `server` block, and
   assumes that `php-fpm` is listening on port 9000:

   ```
   location /testconf/ {
       fastcgi_pass 127.0.0.1:9000;
       fastcgi_split_path_info ^(/testconf)(/[\s\S]*)$;
       fastcgi_param SCRIPT_FILENAME /home/kohler/hotcrp/index.php;
       include fastcgi_params;
   }
   ```

   **Apache with mod_proxy and `php-fpm`**: Add a `ProxyPass`.

   ```
   ProxyPass "/testconf" "fcgi://localhost:9000/home/kohler/hotcrp/index.php"
   ```

   (If your site path is `"/"`, you will need something like `ProxyPass "/"
   "fcgi://localhost:9000/home/kohler/hotcrp/index.php/"`—note the trailing
   slash.)

   **Apache with mod_php _(not recommended)_**: Add a `ScriptAlias` for the
   HotCRP `index.php` script and a `<Directory>` for the installation.

   ```
   ScriptAlias "/testconf" "/home/kohler/hotcrp/index.php"
   <Directory "/home/kohler/hotcrp">
     Options None
     AllowOverride none
     Require all denied
     <Files "index.php">
       Require all granted
     </Files>
   </Directory>
   ```

   **General notes**: Everything under the site path (here, `/testconf`)
   should be served by HotCRP. This normally happens automatically, but if the
   site path is `/`, you may need to turn off your server’s default handlers
   for subdirectories such as `/doc`.

   The `images`, `scripts`, and `stylesheets` subdirectories contain static
   files that any user may access. It is safe to configure your server to
   serve those directories directly, without involving PHP.

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

5. Edit MariaDB’s my.cnf (typical locations: `/etc/mariadb/my.cnf` or
`/etc/mariadb/mysql.conf.d/mysqld.cnf`) to ensure that MySQL can handle
paper-sized objects.  It should contain something like this:

        [mysqld]
        max_allowed_packet=32M

    max_allowed_packet must be at least as large as the largest paper you are
willing to accept. It defaults to 1M on some systems, which is not nearly
large enough. HotCRP will warn you if it is too small. Some MariaDB or MySQL
setups, such as on Mac OS X, may not have a my.cnf by default; just create
one. If you edit my.cnf, also restart the database server.

6. Enable a mail transport agent, such as Postfix or Sendmail. You may need
help from an administrator to ensure HotCRP can send mail.

7. Sign in to the site to create an account. The first account created
automatically receives system administrator privilege.

You can set up everything else through the web site itself.

* Configuration notes

  - Uploaded papers and reviews are limited in size by several PHP
    configuration variables, set by default to 15 megabytes in the HotCRP
    directory’s `.user.ini` (or `.htaccess` if using Apache).

  - HotCRP PHP scripts can take a lot of memory. By default HotCRP sets the
    PHP memory limit to 128MB.

  - Most HotCRP settings are assigned in the conference database’s
    Settings table. The Settings table can also override values in
    `conf/options.php`: a Settings record with name `opt.XXX` takes
    precedence over option `$Opt["XXX"]`.

Database access
---------------

Run `php batch/backupdb.php` at the shell prompt to back up the database.
This will write the database’s current structure and comments to the
standard output. As typically configured, HotCRP stores all paper
submissions in the database, so the backup file may be quite large.

Run `php batch/backupdb.php -r BACKUPFILE` at the shell prompt to restore the
database from a backup stored in `BACKUPFILE`.

Run `lib/runsql.sh` at the shell prompt to get a SQL command prompt for the
conference database.

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
