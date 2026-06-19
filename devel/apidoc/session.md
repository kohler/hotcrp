# Session

These endpoints manage the *session* that ties a sequence of API calls to a
single browser or client: the CSRF token required to modify state, lightweight
per-user UI preferences, deferred (“stashed”) user messages, reauthentication
for sensitive operations, and a few diagnostic/reporting endpoints used by the
HotCRP Javascript client. They are mostly intended for HotCRP’s own front-end
code rather than third-party integrations, but they are documented here because
understanding them clarifies how HotCRP authenticates and protects write
requests.

## CSRF tokens and `post`

HotCRP protects state-changing requests against [cross-site request
forgery](https://developer.mozilla.org/en-US/docs/Web/Security/Attacks/CSRF).
Browser-driven `POST` requests (those authenticated with a session cookie
rather than a bearer token) must include a CSRF token in the `post` parameter.
The token is obtained from [`GET /session`](#get-session), where it
is returned as the `sessioninfo.postvalue` field; send that value back as
the `post` query or body parameter on later `POST` requests in the same
session.

CSRF protection does **not** apply to requests authenticated with an
`Authorization: bearer` token, so external applications using API tokens never
need the `post` parameter. See [Authentication](#authentication).

## Session cookies

The `session` endpoints create a session cookie if one is not already present
(this is how a fresh client obtains a CSRF token). Some endpoints in this group
are explicitly callable by unauthenticated clients and across origins; where
that matters it is noted on the individual endpoint, since such endpoints take
care never to leak user information or CSRF tokens to other origins.


# get /whoami

> Identify the current user

Report which user the request authenticates as. This is the simplest way to
confirm that a set of credentials (a session cookie or an `Authorization:
bearer` token) is valid and to learn whom it identifies.

The response includes the user’s `email`, `given_name`, `family_name`, and
`affiliation`. When the user holds any conference roles, a `roles` array lists
them, drawn from:

* `chair`—a conference chair
* `pc`—a program committee member; this is also present for chairs
* `sysadmin`—a system administrator
* `manager`—an administrator of some submissions who is not a full chair
* `author`—an author or contact of at least one submission
* `reviewer`—assigned at least one review

* badge featured
* response email email: Email of the signed-in user
* response given_name string: First (given) name
* response family_name string: Last (family) name
* response affiliation string: Affiliation
* response roles [role]: Conference roles held by the user


# get /session

> Retrieve session information and CSRF token

Open a session (setting a session cookie if necessary) and return information
about the current client, including the CSRF token needed to authorize
cookie-authenticated `POST` requests.

This endpoint may be called by unauthenticated clients, and—unlike most of the
API—it permits cross-origin (CORS) requests. To avoid leaking sensitive data to
other origins, a cross-origin or cross-site request receives **only `{"ok":
true}`**, with no `sessioninfo`. The full response is returned only for
same-origin requests (as determined by the `Sec-Fetch-Site` request header, or
by the absence of an `Origin` header on clients that do not send
`Sec-Fetch-Site`).

The returned `sessioninfo.postvalue` is the CSRF token; see [CSRF tokens and
`post`](#tag-session) above. The `email` and `uid` fields are present only
when a user is signed in.

* response sessioninfo sessioninfo: Session information, omitted for cross-origin requests


# post /session

> Update session UI preferences

Update a small set of per-user, per-session UI preferences and return the same
session information as [`GET /session`](#get-session). This endpoint
backs the HotCRP Javascript client’s “remember my view” behavior; the
preferences it manages are cosmetic (folding state, which columns are shown in
paper and user lists, score-sort order) and have no effect on submissions,
reviews, or permissions. Unlike `GET /session`, it requires an authenticated,
same-origin request with a valid `post` CSRF token.

The preferences to change are encoded in the `v` parameter as a
whitespace-separated list of assignments. Each assignment has the form
`name[.key][=value]`:

* `name` selects the preference. Recognized names include `foldpaper`,
  `foldpscollab`, `foldhomeactivity` (folding toggles), `pldisplay`,
  `pfdisplay`, `uldisplay` (which columns/fields are shown in the paper list,
  paper form, and user list), and `scoresort` / `ulscoresort` (score sort
  order).
* `.key` is an optional sub-selector. For the `*display` preferences it names
  the column or field being shown or hidden; for `foldpaper` it names an
  individual foldable region.
* `=value` is an optional integer. `0` (or an omitted value) means “unfold” or
  “show”; a nonzero value means “fold” or “hide”.

For example, `v=pldisplay.authors=0 foldhomeactivity=1` reveals the authors
column in the paper list and folds the home-page activity region.

Parsing is best-effort: every recognized assignment is applied, and any
component that is not understood is silently ignored. A successful request
returns `ok` of `true` whether or not every component was recognized.

* param v string: Whitespace-separated list of preference assignments
* response sessioninfo sessioninfo: Updated session information


# post /reauth

> Reconfirm the signed-in user’s identity

Reconfirm the credentials of the already-signed-in user. HotCRP requires
reauthentication before especially sensitive actions (for example, changing
account email or managing security settings); a successful call records a
recent-authentication marker in the session so those actions can proceed.

Supply the user’s `password`. On success the response has `ok` of `true`; if the
password is wrong, the response is an error (`ok` is `false`) whose
`message_list` describes the problem.

The `reason` parameter is a short tag describing why reauthentication is being
requested (HotCRP uses values such as `manageemail`); it can influence how long
the resulting confirmation remains valid. `confirm=1` requests an explicit
success message in `message_list` on success. The `email` and `totpcode`
parameters support alternate verification flows (such as time-based one-time
passwords) where those are configured.

* param ?confirm boolean: If true, include a success message on success
* param ?=reason string: Short tag describing why reauthentication is requested
* param ?=email email: Email of the account being confirmed
* param ?=password string: Account password
* param ?=totpcode string: Time-based one-time password code, if applicable


# post /stashmessages

> Stash messages for later display

Store a list of user-facing messages on the server, keyed by a short
identifier, so they can be displayed after a navigation or redirect. This
supports the common pattern where a `POST` handler wants to show a confirmation
after redirecting the browser to a new page: the handler stashes the messages,
then includes the returned identifier in the redirect URL, and the destination
page retrieves and shows them.

The `message_list` parameter is a **JSON-encoded string** representing an array
of message objects. Each object has an integer `status` and a string `message`.
`status` ranges from -5 to 3 (see the [`message`
schema](#tag-submissions)); common values are `0` (plain), `1` (warning), `2`
(error), and `-3` (success). For example:

```
message_list=[{"status":2,"message":"<0>Submission failed"},{"status":-3,"message":"<0>Draft saved"}]
```

Message text must carry a HotCRP format sigil. Only plain-text (`<0>`),
Markdown (`<1>`), and sanitized HTML (`<5>`) formats are accepted; entries in
other formats, or with out-of-range `status`, are silently dropped. An empty
`message` is allowed and produces a status-only entry.

The response `smsg` field is the identifier under which the messages were
stashed; pass it to the page that should display them. If no usable messages
were supplied, `smsg` is `false`. To append to an existing stash, supply its
identifier in the `smsg` request parameter (a 10–64 character alphanumeric
string); otherwise the server generates a fresh identifier.

* param =message_list string: JSON-encoded array of `{status, message}` objects
* param ?=smsg string: Existing stash identifier to append to
* response smsg string|boolean: Stash identifier, or `false` if nothing was stashed


# post /oauthregister

> OAuth 2.0 dynamic client registration endpoint

Register a new OAuth client and obtain its credentials, following [RFC
7591](https://datatracker.ietf.org/doc/html/rfc7591) (OAuth 2.0 Dynamic Client
Registration). Like [`POST /oauthtoken`](#post-oauthtoken), this is
a standards-based endpoint used when HotCRP acts as an OAuth/OpenID Connect
identity provider; it does not use HotCRP’s usual `{"ok": ...}` response
envelope. It is available only when the conference enables OAuth clients and
dynamic registration; otherwise it returns `404`.

The request body is a JSON object (content type `application/json`). The only
required member is `redirect_uris`, a non-empty array of redirect URI strings;
every URI is validated. Optional members include `client_name` (a
human-readable name) and `scope` (a space-separated scope string). Registration
succeeds only when the request matches a dynamic-client template configured for
the conference (in particular, the requested `redirect_uris` must be permitted
by that template).

On success the response has HTTP status `201` and a JSON body describing the
newly registered client: `client_id`, `client_secret`,
`client_id_issued_at`, `client_secret_expires_at` (Unix timestamps),
the accepted `redirect_uris`, the supported `grant_types`
(`authorization_code` and `refresh_token`), and the
`token_endpoint_auth_method` (`client_secret_basic`). Use the returned
`client_id` and `client_secret` with [`POST
/oauthtoken`](#post-oauthtoken). On failure the response has a
`4xx` status and a body of the form `{"error": "invalid_request"}` (other
values include `invalid_redirect_uri` and `invalid_client_metadata`).


# post /oauthtoken

> OAuth 2.0 / OpenID Connect token endpoint

Exchange an authorization grant for tokens, following [RFC
6749](https://datatracker.ietf.org/doc/html/rfc6749). This is a standards-based
OAuth/OpenID Connect token endpoint used when HotCRP acts as an identity
provider for a registered client application; it is not a general HotCRP API
call and does not use HotCRP’s usual `{"ok": ...}` response envelope.

The request is form-encoded. Send `grant_type=authorization_code` with the
`code` returned from the authorization step (plus `redirect_uri` and, for
[PKCE](https://datatracker.ietf.org/doc/html/rfc7636) clients, `code_verifier`), or
`grant_type=refresh_token` with a `refresh_token`. The client authenticates
either with HTTP Basic authentication (`Authorization: Basic`) or by sending
`client_id` and `client_secret` as body parameters. An optional `scope`
parameter narrows the requested scopes.

On success the response is a standard OAuth token object containing
`access_token`, `token_type` (`Bearer`), `expires_in`, `refresh_token`,
`scope`, and—when the `openid` scope was granted—an `id_token` JWT. On failure
the response carries an HTTP `4xx` status and a body of the form `{"error":
"invalid_grant"}` (other values include `invalid_request`, `invalid_client`,
`invalid_scope`, and `unsupported_grant_type`).

* param ?=grant_type string: `authorization_code` or `refresh_token`
* param ?=code string: Authorization code (for `authorization_code` grants)
* param ?=redirect_uri string: Redirect URI matching the authorization request
* param ?=code_verifier string: PKCE code verifier
* param ?=refresh_token string: Refresh token (for `refresh_token` grants)
* param ?=client_id string: Client identifier
* param ?=client_secret string: Client secret
* param ?=scope string: Space-separated requested scopes


# post /jserror

> Report a client-side Javascript error

Record a Javascript error encountered by the HotCRP front-end in the server
error log. This is a diagnostic endpoint used by HotCRP’s own client code; it
may be called by unauthenticated clients. It always returns `{"ok": true}`,
even when the report is ignored.

Reports are filtered: errors that appear to originate in browser extensions, or
that come from known crawlers, are accepted but not logged.

* param =error string: Error message
* param ?=url string: URL where the error occurred
* param ?=lineno integer: Line number
* param ?=colno integer: Column number
* param ?=stack string: Stack trace
* param ?=detail string: Additional detail (truncated when logged)


# post /cspreport

> Report a Content Security Policy violation

Receive a [Content Security
Policy](https://developer.mozilla.org/en-US/docs/Web/HTTP/Guides/CSP) violation
report and append it to the server’s CSP report log. This endpoint is the
target of a `report-to`/`report-uri` directive; it is called directly by the
browser, may be unauthenticated, and is not intended for manual use.

The request body is JSON sent with content type `application/reports+json`,
`application/csp-report`, or `application/json`; it may be a single report
object or an array of them. Reports attributed to browser extensions are
discarded. A well-formed request returns `{"ok": true}`. A malformed body
returns a `400` error; if the report cannot be persisted (the log is missing,
unwritable, or over quota) the response is a `5xx` error.

* response ok boolean: True if the report was accepted
