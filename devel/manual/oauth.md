# HotCRP OAuth

Configure HotCRP’s `$Opt["oAuthProviders"]` setting in `conf/options.php` to use
[OAuth 2.0][OAuth] and [OpenID Connect][] to authenticate users.

## `oAuthProviders` format

The `oAuthProviders` option is a [component list][components] of OAuth
authentication providers. Each `oAuthProviders` component should define:

* `name`: The name of the provider. Each provider must have a distinct name.
  Internal to HotCRP. Example: `"Google"`

* `title`: (Optional) A short description of the authentication provider, to
  be used in error messages. Defaults to `name`.

* `issuer`: (Optional) The issuer ID of the authentication provider. This is
  the value the provider sends as its `iss` claim in OAuth responses. If
  provided, HotCRP requires that ID tokens contain an `iss` claim that exactly
  matches this value. You can look it up the issuer for a provider by
  accessing an OpenID configuration file, such as
  https://accounts.google.com/.well-known/openid-configuration. Example:
  `"https://accounts.google.com"`

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

## Example configuration for Google authentication

```
$Opt["oAuthProviders"][] = '{
    "name": "Google",
    "issuer": "https://accounts.google.com",
    "auth_uri": "https://accounts.google.com/o/oauth2/v2/auth",
    "token_uri": "https://oauth2.googleapis.com/token",
    "client_id": "123456789-nnnnnnnnnnnnnnnnnnnnnnnnn.apps.googleusercontent.com",
    "client_secret": "GOCSPX-nnnnnnnnnnnnnnnnnnnnnnnn",
    "button_html": "Sign in with Google"
}';
```

You’ll get the client ID and client secret from Google when you register your
application.

## Authentication flow

HotCRP’s page component `"signin/form/oauth"` renders a button for each
defined OAuth provider. Clicking on that button redirects to
`SITEURL/oauth?authtype=NAME&post=CSRFTOKEN`. That page initiates an OAuth 2
authorization code flow by choosing a random token, recording it, and
redirecting the user to the specified `auth_uri` with appropriate parameters.
When the user completes their authentication request, the provider redirects
back to HotCRP via the `redirect_uri`. HotCRP contacts the provider’s
`token_uri` with the provided parameters via an HTTP `POST` request with
`application/x-www-form-urlencoded` content. HotCRP then validates the
returned JWT and uses its `email` to authenticate the user.

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

## Importing roles and tags

HotCRP roles (`pc`, `sysadmin`, `chair`) and user tags can be imported from
OAuth `groups` and/or `roles` claims. A `roles` claim is a list of application
role names, while a `groups` claim is a list of opaque group names that must
be mapped to roles.

Role parsing is enabled on a per-provider basis. To parse a provider’s `roles`
claims, set `roles` to `true` in its `oAuthProviders` setting. To parse its
`groups` claims, add a `group_roles` object that maps group names to roles and
tags.

Provider-claimed roles and tags are added to a user account when that user
signs in. By default, HotCRP does not remove existing roles or tags on
signin—the provider claims augment any preexisting roles tags. You can change
this by setting `reset_roles` to a list of roles and tags that should be reset
to provider-claimed values on signin.

```
$Opt["oAuthProviders"][] = '{
    "name": "Google",
    "issuer": "https://accounts.google.com",
    "auth_uri": "https://accounts.google.com/o/oauth2/v2/auth",
    "token_uri": "https://oauth2.googleapis.com/token",
    "client_id": "123456789-nnnnnnnnnnnnnnnnnnnnnnnnn.apps.googleusercontent.com",
    "client_secret": "GOCSPX-nnnnnnnnnnnnnnnnnnnnnnnn",
    "button_html": "Sign in with Google",
    "reset_roles": "pc heavy",
    "group_roles": {
        "operators": "+sysadmin",
        "heavy-reviewers": "pc heavy",
        "reviewers": "pc",
        "chairs": "+chair"
    }
}';
```

[OAuth]: https://en.wikipedia.org/wiki/OAuth
[OpenID Connect]: https://en.wikipedia.org/wiki/OpenID
[components]: ./components.md
