HotCRP OAuth test server
========================

This server, built using https://github.com/thephpleague/oauth2-server and
https://github.com/Nyholm/psr7, can be used to test HotCRP’s OAuth support.


Usage
-----

1. Install required libraries with `composer install`

2. Configure the server for your HotCRP installation. This involves choosing a
   `redirect_uri`, which is the full URI for the `oauth` page on the HotCRP
   installation you want to test. Enter this URI in `db.json`’s `clients`. The
   default is `http://localhost:8080/testconf/oauth`. (You can also copy
   `db.json` to `localdb.json` and edit the copy.)

3. Run the server with `php -S localhost:19382 oauth-provider.php`

4. Configure HotCRP to access the server by setting `$Opt["oAuthProviders"]`
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

    The `redirect_uri` here must equal the `redirect_uri` you configured in
    step 2.
