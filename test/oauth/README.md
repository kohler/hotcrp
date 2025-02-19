HotCRP OAuth test server
========================

This server, built using https://github.com/thephpleague/oauth2-server and
https://github.com/Nyholm/psr7, can be used to test HotCRP’s OAuth support.

Installation
------------

```
$ composer install
```

Running
-------

```
$ php -S localhost:19382 oauth-provider.php
```

(You can pick any port.) Then configure HotCRP to access the server by setting
`$Opt["oAuthProviders"]`:

```php
$Opt["oAuthProviders"][] = [
	"name" => "local",
	"client_id" => "hotcrp-oauth-test",
	"client_secret" => "Dudfield",
	"auth_uri" => "http://localhost:19382/auth",
	"token_uri" => "http://localhost:19382/token",
	"redirect_uri" => "http://localhost:8080/testconf/oauth",
	"button_html" => "Sign in with local OAuth"
];
```
