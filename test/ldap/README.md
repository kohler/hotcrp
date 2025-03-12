HotCRP LDAP test server
=======================

This directory contains a configuration for a [Light
LDAP](https://github.com/lldap/lldap) server that can be used to test HotCRP’s
LDAP support.


Installation
------------

1. Run `docker compose up` in this directory

2. Sign in to the LLDAP server at `http://localhost:17170` with username
   `admin` and password `aequee0Oe1ee1A`

3. Create users using the LLDAP admin interface (for instance, see the example
   user table below)

4. Configure HotCRP to use LDAP authentication by setting `$Opt["ldapLogin"]`
   in `conf/options.php`:

   	```php
	$Opt["ldapLogin"] = "ldap://localhost:17169/ uid=*,ou=people,dc=hotcrp,dc=org";
	```

5. Sign in to HotCRP as one of the users you created


Example user table
------------------

| User     | Name         | Mail                   | Password       |
|----------|--------------|------------------------|----------------|
| fran     | Fran Framer  | fran@hotcrp-ldap.org   | raec3ohL5u     |
| paula    | Paula Books  | paula@hotcrp-ldap.org  | gi1eiluoCh     |
