# info

> HotCRP conference management software API

[HotCRP](https://github.com/kohler/hotcrp) is conference review software. It
is open source; a supported version runs on [hotcrp.com](https://hotcrp.com/).
This documentation is for the HotCRP REST API.

To request documentation for an API method, please open a [GitHub
issue](https://github.com/kohler/hotcrp/issues). We also welcome [pull
requests](https://github.com/kohler/hotcrp/pulls).


## Overview

API calls use paths under `api`. For instance, to call the `paper` endpoint on
a server at `https://example.hotcrp.org/funconf25`, you might use a URL like
`https://example.hotcrp.org/funconf25/api/paper?p=1`.

Parameters are provided in the query string or the request body, in most cases
using form encoding (`application/x-www-form-urlencoded` or
`multipart/form-data`).

Responses are formatted as JSON. Every response has an `ok` field, which is
`true` if the request format succeeded and `false` otherwise. Typically `"ok":
false` indicates a serious error with the request that prevented proper
processing. Messages about the request, if any, are expressed in a
`message_list` field.

`GET` operations retrieve system state and `POST` operations modify system
state. Other operations are occasionally used when semantically meaningful—for
example, the `/paper` endpoint supports `DELETE`.


## Parameter types

**Boolean** parameters accept `1` or `true` for true and `0` or `false` for
false; the values `on`/`yes` and `off`/`no` are also accepted. Any other value
is treated as if the parameter were absent. For example, `dry_run=1` and
`dry_run=true` are equivalent.

**Integer** and **number** parameters are written in decimal, such as `p=10`.
**String** parameters are sent literally; an enumerated parameter is a string
restricted to a fixed set of values.

More complex inputs use other encodings. Some requests group parameters into
logical objects using structured keys, such as `named_search/1/q`; others define
an object using JSON, supplied either as the request body or as a JSON-valued
parameter such as `json`. Use `multipart/form-data` encoding for requests that
include uploaded files; since servers limit upload size, you may need the upload
API to send a large file before processing it with another call.


## Common parameters

The `p` parameter defines a submission ID. It can appear either in the query
string or immediately following `api/` in the query path: `api/comment?p=1`
and `api/1/comment` are the same API call. `p` is a positive decimal integer,
but some API calls accept `p=new` when defining a new submission.

The `forceShow` boolean parameter allows administrators to override their
conflicts when that is possible. It defaults to `true`, so administrators
override their conflicts by default; pass `forceShow=false` to respect conflicts
instead.

The `:method:` parameter overrides the request’s HTTP method. Setting
`:method:=GET` on a `POST` request makes HotCRP process it with `GET` semantics.
The parameter must appear in the URL query string, not the request body, and
`GET` is its only meaningful value. Use this for read requests whose parameters
are too large to fit within a URL’s length limit: send the parameters in a
`POST` body while still getting `GET` behavior.


## Search parameters

Many endpoints act on a set of submissions chosen by a search rather than by a
single `p`. These share a family of search parameters: `q` (the search
expression), `t` (the collection of submissions to search), and the modifiers
`qt`, `sort`, `scoresort`, and `reviewer`. They mean the same thing everywhere
they appear—for example in [`/papers`](#get-papers),
[`/reviews`](#get-reviews), and [`/assign`](#post-assign). See
[Search](#tag-search) for the search-string syntax and the full meaning of each
parameter.


## Kinds of administrator

Two terms describe administrative authority in this documentation, and they are
not interchangeable.

A **site administrator** has site-wide authority over the whole conference.
Chairs and system administrators are administrators; they can act on every
submission, the review process, and conference settings. Endpoints restricted to
this authority are badged **Site admin only**.

A **submission administrator** has administrative authority over a particular
submission or a subset of submissions. For example, a track manager has
administrative authority over a track, and each submission can have an explicit
manager assigned from the PC. For most submissions, a site administrator is also
a submission administrator (there are exceptions involving track rights and
conflicts), but a submission manager is not necessarily a site administrator.
Endpoints that require administrative authority over specific submissions, but
do not require broad site administration rights, are badged **Admin only** or
**Track manager only**.


## Authentication

External applications should authenticate to HotCRP’s API using bearer tokens
(an `Authorization: bearer` HTTP header). Obtain API tokens using Account
settings > Developer. HotCRP Javascript makes API calls using session cookies
for authentication.


## Featured endpoints

Operations marked with a yellow star (<span class="featured-star featured-star-op" title="Featured" aria-label="Featured">★</span>) are **featured**: the parts of the API
most useful to external integrations, which we aim to keep stable and well
documented. When several endpoints can accomplish a task, the featured one is
usually the best choice.

Other API endpoints may be unstable or may be intended to serve HotCRP’s own web
client. These endpoints are documented for completeness, but they are more
likely to change without notice, and sometimes return HotCRP-internal formats
(such as pre-rendered HTML) rather than clean JSON. Prefer featured endpoints
when building an external application.
