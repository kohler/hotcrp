HotCRP LDAP test server
=======================

This directory contains a configuration for a [Light
LDAP](https://github.com/lldap/lldap) server that can be used to test HotCRP’s
LDAP support.


Installation
------------

1. Run `docker compose up` in this directory.

2. (Optional) Create users using the LLDAP admin interface.

	HotCRP ships with an LLDAP SQLite database whose users are listed below in
	“Default users.” If you want to change these users or create other ones,
	sign in to the LLDAP administration server at `http://localhost:17170`
	using username `admin` and password `aequee0Oe1ee1A` .

3. Configure HotCRP to use the running LLDAP server for authentication by
   setting `$Opt["ldapLogin"]` in `conf/options.php`:

   	```php
	$Opt["ldapLogin"] = "ldap://localhost:17169/ uid=*,ou=people,dc=hotcrp,dc=org";
	```

4. Sign in to HotCRP as one of the LDAP users.


Default users
-------------

| User     | Name         | Mail                   | Password       |
|----------|--------------|------------------------|----------------|
| fran     | Fran Framer  | fran@hotcrp-ldap.org   | raec3ohL5u     |
| paula    | Paula Books  | paula@hotcrp-ldap.org  | gi1eiluoCh     |
