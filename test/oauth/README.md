HotCRP OAuth test server
========================

This server, built using https://github.com/thephpleague/oauth2-server and
https://github.com/Nyholm/psr7, can be used to test HotCRP’s OAuth support.


Usage
-----

1. Install required libraries with `composer install`

2. Run the server with `php -S localhost:19382 oauth-provider.php`

3. Configure HotCRP to access the server by setting `$Opt["oAuthProviders"]`
   in `conf/options.php`:

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
