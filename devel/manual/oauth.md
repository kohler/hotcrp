# HotCRP OAuth

Configure HotCRP’s `$Opt["oAuthProviders"]` setting in `conf/options.php` to use
[OAuth 2.0][OAuth] and [OpenID Connect][] to authenticate users, and configure
`$Opt["oAuthClients"]` to allow clients to use HotCRP as an authorization
server.

## HotCRP as authentication client

The `oAuthProviders` option is a [component list][components] of OAuth
authentication providers. Each `oAuthProviders` component should define:

* `name`: The name of the provider. Each provider must have a distinct name.
  Internal to HotCRP. Example: `"Google"`

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

* `trusted_audiences`: (Optional) An audience, or list of audiences, that this
  site accepts in an ID token’s `aud` claim in addition to its own
  `client_id`. HotCRP rejects an ID token that names any other audience, since
  such a token was issued to that party as well. Set this only if your provider
  deliberately gives its ID tokens more than one audience; set it to `true` to
  accept any audience. Example: `["https://api.example.com"]`

* `pkce`: (Optional) If false, HotCRP omits PKCE (RFC 7636) from its
  authorization requests to this provider. Defaults to true; set it only for a
  provider that rejects `code_challenge`.

* `token_function`: (Optional) PHP callback to be called after a token is
  returned, but before HotCRP validates the token.

* `disabled`: (Optional) If true, HotCRP disables this provider.

* `title`: (Optional) A short description of the authentication provider, to
  be used in error messages. Defaults to `name`.

* `button_html`: HTML contents of the signin button for this provider. If
  empty, then HotCRP does not display a signin button. Example: `"Sign in with
  Google"`

### Example configuration for Google authentication

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

### Authentication flow

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

### Disabling other authentication sources

Set `$Opt["loginType"]` to `"oauth"` to use *only* OAuth to authenticate
users. If `$Opt["loginType"]` is `"oauth"` or `"none"`, then HotCRP will not
use its own password storage or allow attempts to sign in other than through
OAuth.

### Importing roles and tags

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

## HotCRP as authorization server

HotCRP can also act as an OAuth 2 authorization server: other applications can
sign users in with HotCRP accounts, and can obtain access tokens for HotCRP’s
API. `$Opt["oAuthClients"]` is a [component list][components] of allowed clients;
if it is empty, `SITEURL/authorize` refuses all requests.
HotCRP can advertise what it supports at
`.well-known/oauth-authorization-server`, and describe its API as a protected
resource at `.well-known/oauth-protected-resource/api`.

Settings shared by all client components:

* `name`: The name of the client. Each client must have a distinct name.

* `title`: (Optional) The client’s name as shown to users on the
  authorization page. Defaults to `name`.

* `client_uri`: (Optional) A URL for the client, linked from the
  authorization page.

* `scope`: (Optional) The maximum [token scope][] this client may be granted.
  If unset, the client can obtain OpenID Connect identity tokens, but no API
  access.

* `allow_if`: (Optional) An [`XtParams`][components] expression limiting which
  users may authorize this client. Example: `"pc"`.

* `access_token_expires_in`, `refresh_token_expires_in`: (Optional)
  Durations, such as `"1h"`, or `"never"`.

* `is_cdb`: (Optional) Mint the client’s tokens in the contact database
  (`$Opt["contactdbDsn"]`) rather than in this conference’s database. Such a token
  identifies the user, not the site, so it works at *every* conference that
  shares the contact database, including conferences where this component’s
  `allow_if` was never evaluated. Because a token scope’s submission
  selectors (`#12`, `#tag`, `?q=…`) would mean something different at each
  site, they are refused for an `is_cdb` client; only site-independent scopes
  such as `read` or `submission:admin` may be granted.

A client can identify itself to HotCRP as a preregistered client, using a
[Client ID Metadata Document][cimd], or using [dynamic client
registration][RFC7591].

### Registered clients

A component with a `client_id`, a `client_secret`, and a `redirect_uris` list
describes a client registered by hand in `conf/options.php`. This is the most
restrictive and most secure option.

### Client ID metadata documents

When `$Opt["oAuthMetadataDocumentClients"]` is set, a component with
`"metadata_document": true` accepts clients that identify themselves with a
[client ID metadata document][cimd]. No registration step is required, so this
is the easiest way for an AI agent or other third-party tool to connect to a
conference.

When HotCRP sees a URL-shaped `client_id` that matches some component with
`"metadata_document": true` (see `client_id_match` below), it fetches the named
URL and validates the result. The client identifier URL must use `https`, must
have a path component, and must not name an IP address or a private host; the
document must be JSON served with status 200, must contain a `client_id`
identical to the URL, and must list `redirect_uris` that are `https` or loopback
URLs. HotCRP does not follow redirects and limits the response size.

The fetch is triggered by an unauthenticated request, so:

* Authorization requests must use PKCE with `code_challenge_method=S256`.
* The token endpoint expects no client secret.
* ID tokens are unsecured JWTs (`"alg": "none"`).
* The authorization page shows the user the client identifier’s host and the
  host that will receive the authorization, since the client’s chosen name is
  not vetted by anyone.
* Names that resolve to special-use IP addresses (loopback, link-local,
  private ranges, and so forth) are rejected.

The `oAuthClients.client_id_match` property limits the `client_id`s that a
component will accept. It is a pattern (or list of patterns) possibly containing
`*` wildcards, which match any number of characters within one URL component
(host, port, path, or query). Thus, `https://*.foo/bar` matches
`https://a.foo/bar` but not `https://a.com/x.foo/bar`. A pattern of `"*"`, or no
`client_id_match` at all, is equivalent to `https://*:*/*`, which matches any
HTTPS `client_id`. Note that `client_id` URLs must contain nonempty paths, so a
pathless pattern like `https://host` will never match.

For development, a `client_id_match` pattern can explicitly name an
`http://localhost`-type URL. Only hosts `localhost`, `127.0.0.1`, and `[::1]`
are allowed.

```
$Opt["oAuthClients"][] = '{
    "name": "cimd-dev",
    "metadata_document": true,
    "client_id_match": "http://localhost:*/*",
    "scope": "read"
}';
$Opt["oAuthMetadataDocumentClients"] = true;
```

Client ID metadata documents are only supported on PHP version 8.5 or later.

### Dynamically registered clients

When `$Opt["oAuthDynamicClients"]` is set, a component with `"dynamic": true`
accepts clients that register themselves via [OAuth dynamic client
registration][RFC7591] at `SITEURL/api/oauthregister`. An optional
`redirect_uris` list restricts the redirect URIs such a client may register.

### Revocation

A client may hand back a token it no longer needs by posting `token` to
`SITEURL/api/oauthrevoke` ([RFC7009][]), authenticating as it would at the
token endpoint. Revoking a refresh token also revokes the access tokens it
minted; revoking an access token affects only that token. An unknown token is
reported as success, so the endpoint cannot be used to test whether a token
exists.

[OAuth]: https://en.wikipedia.org/wiki/OAuth
[OpenID Connect]: https://en.wikipedia.org/wiki/OpenID
[OIDC Core]: https://openid.net/specs/openid-connect-core-1_0.html
[components]: ./components.md
[token scope]: ./hotcrapi.md
[cimd]: https://datatracker.ietf.org/doc/html/draft-ietf-oauth-client-id-metadata-document
[MCP]: https://modelcontextprotocol.io/specification/2026-07-28/basic/authorization/client-registration
[RFC7591]: https://datatracker.ietf.org/doc/html/rfc7591
[RFC7009]: https://datatracker.ietf.org/doc/html/rfc7009
