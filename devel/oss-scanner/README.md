# OSS Scanner support

HotCRP is enrolled in Anthropic’s OSS Scanner
(<https://github.com/anthropics/oss-scanner>). The scanner clones this
repository, builds `Dockerfile` with the repository root as build context,
reads `threat_model.md`, and then analyzes the image with no network access.
The enrollment record, `projects/hotcrp/project.yaml` in the oss-scanner
repository, names the paths of these files, so changes here take effect
without a pull request there.

| File              | Purpose                                                            |
|-------------------|--------------------------------------------------------------------|
| `Dockerfile`      | Debian + PHP + MariaDB + nginx/php-fpm; creates the databases      |
| `threat_model.md` | Principals, components, severity ratings, out-of-scope list        |
| `hotcrp-scanctl`  | In-image helper: `start`, `stop`, `reset`, `test`, `db`, `mail`    |
| `hotcrp-sendmail` | Fake `sendmail`; saves each message under `/var/spool/hotcrp-mail` |
| `seed.php`        | Loads the sample conferences                                       |

`hotcrp-scanctl start` runs MariaDB, php-fpm, and nginx. nginx serves two sample
conferences from one multiconference installation, sharing a contact database:
`http://localhost:8080/conf1/` (the test fixture `test/db.json`) and
`http://localhost:8080/conf2/` (a small conference whose chair is only an author
in conf1). Every account's password is `test1234`. By default conf1 is populated
as if mid-review (reviews, comments, responses, decisions, tags, votes, a
track); `hotcrp-scanctl start --minimal` or `reset --minimal` gives just
accounts, papers, and assignments. `threat_model.md` describes the state. conf1
stores documents only in a filesystem docstore (`/var/lib/hotcrp/docs`, with
`dbNoPapers`); conf2 keeps them in the database, the default for new
installations. The unit tests (`test/run.php`) use their own databases,
`hotcrp_testdb` and `hotcrp_testdb_cdb`, so running them does not affect the
sample conferences.

## Local check

Build from a clean clone; the working tree may be too large to be a build
context (e.g. docstore), and the clone matches what the scanner sees (committed
files only).

```sh
git clone . /tmp/hc
cd /tmp/hc && docker build -f devel/oss-scanner/Dockerfile -t hotcrp-scan .
docker run --rm -it --network none hotcrp-scan bash
hotcrp-scanctl test                 # run the default test collections
hotcrp-scanctl start
```

To use the sample conferences from a browser on the host, publish the port and
listen on all interfaces:

```sh
docker run --rm -it -p 8080:8080 hotcrp-scan bash
hotcrp-scanctl start --public       # then open http://localhost:8080/conf1/
```

For a check that matches the scanner exactly, including its Claude Code layer,
run `tools/check hotcrp` from a checkout of the oss-scanner repository. It
builds from GitHub, so push first.
