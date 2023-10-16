# HotCRP OAuth

Configure HotCRP’s `$Opt["oAuthTypes"]` setting in `conf/options.php` to use
[OAuth 2.0][OAuth] and [OpenID Connect][] to authenticate users.

## `oAuthTypes` format

The `oAuthTypes` option is a list of [components][] defining supported OAuth
authentication providers. Each `oAuthTypes` component should define:

* `name`: The name of the provider. Each provider must have a distinct name.
  Internal to HotCRP. Example: `"Google"`

* `title`: (Optional) A short description of the authentication provider, to
  be used in error messages. Defaults to `name`.

* `client_id`, `client_secret`: Your client ID and secret. These are sent to
  the authentication provider as part of the authentication process.

* `auth_uri`: The provider’s authentication URI. Example:
  `"https://accounts.google.com/o/oauth2/v2/auth"`

* `token_uri`: The provider’s URI for fetching authentication results.
  Example: `"https://oauth2.googleapis.com/token"`

* `redirect_uri`: (Optional) The HotCRP URI registered with the provider.
  Defaults to `SITEURL/oauth`.

* `scope`: (Optional) The OAuth scopes to be requested as part of the
  authentication process. Defaults to `"openid email profile"`

* `token_function`: (Optional) PHP callback to be called after a token is
  returned, but before HotCRP validates the token.

* `button_html`: HTML contents of the signin button for this provider. If
  empty, then HotCRP does not display a signin button. Example: `"Sign in with
  Google"`

* `disabled`: (Optional) If true, HotCRP disables this provider.

## Authentication flow

HotCRP’s page component `"signin/form/oauth"` renders a button for each
defined provider. Clicking on that button redirects to
`SITEURL/oauth?authtype=NAME`. That page initiates an OAuth 2 implicit
authentication flow by choosing a random token, recording it, and redirecting
the user to the specified `auth_uri` with appropriate parameters. When the
user completes their authentication request, the provider redirects back to
HotCRP via the `redirect_uri`. HotCRP contacts the provider’s `token_uri` with
the provided parameters via an HTTP `POST` request with
`application/x-www-form-urlencoded` content. HotCRP then validates the
returned JWT and uses its included `email` to authenticate the user.

Many steps in this process might go wrong. HotCRP uses its own code to
validate the JWT; this might break. HotCRP does not support encrypted tokens.
Report problems to maintainers.

HotCRP does not currently validate that the returned token was
cryptographically signed by a public key corresponding to the provider. That
is, it trusts that the TLS connection to the provider is secure, and does not
access the provider’s JSON Web Key Set.

## Disabling other authentication sources

Set `$Opt["loginType"]` to `"oauth"` to use *only* OAuth to authenticate
users. If `$Opt["loginType"]` is `"oauth"` or `"none"`, then HotCRP will not
use its own password storage or allow attempts to sign in other than through
OAuth.


[OAuth]: https://en.wikipedia.org/wiki/OAuth]
[OpenID Connect]: https://en.wikipedia.org/wiki/OpenID
[components]: ./components.md
