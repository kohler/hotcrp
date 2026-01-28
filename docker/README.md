Running with Docker/Docker Compose
==================================

Copy `.env.default` to `.env` and update the values inside `.env` accordingly.

```
docker compose up
```

Once all containers are up and running, open a browser and go to `http://localhost:9001`.
The first default user is `user`/`pass` with `sysadmin` privilege.

Ports
-----

| port |container|
|------|:-------:|
| 9001 | hotcrp |
| 9002 | smtp/mailhog |
| 9003 | keycloak |
| 3306 | MariaDB  |

Default Credentials
-------------------
HotCRP:
* admin user: `user`/`pass`
Keycloak:
* Admin user: `admin`/`admin`
* Test user: `user`/`pass`
* OAuth client credential:
  * client_id: `hotcrp`
  * client_secret: `v2az66Huos6KwA65LOFfvJCPaqo5tUCq`
MariaDB:
* Root: `root`/`root`
* HotCRP: `hotcrp`/`hotcrppwd`

# Running Tests

On MacOS, poppler is required to run tests. You can install it with `brew install poppler`.

```bash
docker run --rm --name hotcrp-db -e MARIADB_ROOT_PASSWORD=root -p 3306:3306 -v ./docker/05-skipcache.cnf:/etc/mysql/mariadb.conf.d/05-skipcache.cnf mariadb:10.11
lib/createdb.sh -u root -proot -h127.0.0.1 -c test/options.php --batch --grant-host='%'
lib/createdb.sh -u root -proot -h127.0.0.1 -c test/cdb-options.php --no-dbuser --batch --grant-host='%'
MYSQL_HOST=127.0.0.1 test/check.sh --all
```