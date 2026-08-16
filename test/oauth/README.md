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
   default is `http://localhost:8020/testconf/oauth`. (You can also copy
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


Testing HotCRP’s authorization server
-------------------------------------

The same server also runs the mirror image: it signs in *through* HotCRP, at
`http://localhost:19382/servers`. HotCRP’s authorization server is then
exercised by an implementation that shares no code with it — ID token
signatures are checked both by hand and by `lcobucci/jwt`, which arrives as a
`league/oauth2-server` dependency. A round trip through HotCRP’s own JWT code
cannot catch a disagreement with the specification, because both halves of it
would agree on the same wrong thing.

List the sites to test under `servers` in `db.json` (or `localdb.json`) — the
mirror of `clients`, which lists the sites that sign in through this server —
then visit `http://localhost:19382/servers` and start a run.

A `servers` entry is written the way HotCRP writes an `oAuthProviders` entry,
since it plays the same role from the other side. `auth_uri` and `token_uri`
are the endpoints that matter; `client_id`, `client_secret`, `scope`, and
`issuer` mean what they do there. As a shorthand for a HotCRP site, give
`hotcrp_uri` instead and the endpoints are discovered from it (RFC 8414),
along with the registration endpoint the dynamic and metadata-document modes
need. An explicit `auth_uri` or `token_uri` overrides what discovery found.
`name` labels the entry, for the `?server=` selector when you list more than
one.

Three modes:

* **configured client**: register the client yourself in
  `$Opt["oAuthClients"]`, with `client_id` and `client_secret` matching
  that server’s `client_id` and `client_secret`, and `redirect_uris` containing
  `http://localhost:19382/servers/callback`. This is the mode that exercises signed
  ID tokens, so give it a `client_secret` of at least 32 characters — shorter
  HMAC keys are refused by `lcobucci/jwt`.

    ```php
    $Opt["oAuthClients"][] = [
        "name" => "servertest", "title" => "OAuth server test",
        "client_id" => "hotcrp-oauth-servertest",
        "client_secret" => "Y5Ay6heHUZbXeZWMuKthYFrMfaJcyeCE",
        "scope" => "read",
        "redirect_uris" => ["http://localhost:19382/servers/callback"]
    ];
    ```

* **dynamic registration**: this server registers itself at the advertised
  `registration_endpoint`. Needs `$Opt["oAuthDynamicClients"]` and a component
  with `"dynamic" => true`.

* **metadata document**: this server identifies itself by the URL of the
  document it serves at `/servers/metadata.json`. That URL is plaintext and on
  loopback, so the component must name the `http` scheme in its
  `client_id_match` — that is what accepts a plaintext identifier and allows
  the loopback fetch:

    ```php
    $Opt["oAuthClients"][] = [
        "name" => "cimd-dev", "metadata_document" => true, "scope" => "read",
        "client_id_match" => "http://localhost:19382/*"
    ];
    ```

The run reports on discovery metadata, the `iss` of the authorization response
(RFC 9207), the token response, the ID token’s signature and claims, whether
the access token is accepted by `/api/whoami`, refresh token rotation, and
whether replaying a spent refresh token or authorization code is refused and
revokes what it produced (RFC 9700 §§4.2.4, 4.14.2). The last two checks spend
the grant on purpose, so each run needs a fresh sign-in.
