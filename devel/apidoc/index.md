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

`GET` operations retrieve system state and `POST` operations modify system
state. Other operations are occasionally used when semantically meaningful—for
example, the `/paper` endpoint supports `DELETE`.

Parameters may be provided in the query string or (for non-GET requests) the
request body. In most cases, the request body should have Content-Type
`application/x-www-form-urlencoded` or `multipart/form-data`; exceptions are
documented.

Responses are formatted as JSON. Every response has an `ok` field, which is
`true` if the request passed initial validation and `false` otherwise. Typically
`"ok": false` indicates a serious error, such as a missing or malformatted
parameter or a permission problem. `"ok": true` does not necessarily mean that
the request fully succeeded. Messages about the request, if any, are expressed
in a [`message_list` field](#overview-message-lists); a successful response may
still carry errors and warnings there.

Operations marked with a yellow star (<span class="featured-star
featured-star-op" title="Featured" aria-label="Featured">★</span>) are
**featured**: the parts of the API most useful to external integrations, which
we aim to keep stable and well documented. When several endpoints can accomplish
a task, the featured one is usually the best choice. Other API endpoints may be
unstable or may be intended to serve HotCRP’s own web client.

External applications should [authenticate](#authentication) to HotCRP’s API
using bearer tokens obtained using Account settings > Developer.


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

**`p`**: The `p` parameter defines a submission ID. It can appear either in the
query string or immediately following `api/` in the query path:
`api/comment?p=1` and `api/1/comment` are the same API call. `p` is a positive
decimal integer, but some API calls accept `p=new` when defining a new
submission.

**`forceShow`**: HotCRP site administrators and track managers can administer
submissions with which they are conflicted. For instance, when viewing a
conflicted submission in the web UI, they see the conflicted view, but selecting
“Override conflict” shows them a full administrator view. Conflict overriding
can affect many aspects of site behavior, including search order, visible tags,
and whether modifications are accepted. The `forceShow` boolean API parameter
determines whether conflicts should be overridden when that is possible. It
defaults to `true`, so administrators override their conflicts by default; pass
`forceShow=false` to respect conflicts instead.

**`actas`**: HotCRP site administrators can assume other users’ identities,
allowing them to check how the site appears to users with less authority.
The `actas` parameter implements this in the API. Site administrators can set
`actas` to an email address or numeric user ID; the API call will execute with
the named user’s privileges.

**`:method:`**: The `:method:` parameter overrides the request’s HTTP method.
Setting `:method:=GET` on a `POST` request makes HotCRP process it with `GET`
semantics. The parameter must appear in the URL query string, not the request
body, and `GET` is its only meaningful value. Use this for read requests whose
parameters are too large to fit within a URL’s length limit: send the parameters
in a `POST` body while still getting `GET` behavior.

**`q` and search modifiers**: Many endpoints act on a set of submissions chosen
by a search rather than by a single `p`. These share a family of search
parameters: `q` (the search expression), `t` (the search scope—the collection of
submissions to search), and the modifiers `qt`, `sort`, `scoresort`, and
`reviewer`. They mean the same thing everywhere they appear—for example in
[`/papers`](#get-papers), [`/reviews`](#get-reviews), and
[`/assign`](#post-assign). See [Search](#tag-search) for the search-string
syntax and the full meaning of each parameter.


## Message lists

The **`message_list`** response field holds an array of diagnostics about a
request, such as why it failed, which field was invalid, or a note about what
changed. It can appear on successful or unsuccessful responses and may be empty.
The messages appear in display order.

Each entry is a **message object** whose only required field is `status`, an
integer that classifies the message:

- `2` or `3`—an **error**; the request was rejected or could not be fully
  processed. (`3` marks an especially serious error.)
- `1`—a **warning**.
- `0`—a **plain** informational message.
- `-3`—a **success** message.
- `-5`—an **informational note** about the preceding non-informational message
  (rendered indented).
- other **negative** values—notes that are not full errors, but that should be
  rendered with highlights.

A message object may also have these fields:

- `message`—the human-readable text, as a **formatted-text string**: a leading
  format sigil gives the encoding, with `<0>` for plain text, `<1>` for
  Markdown, and `<5>` for HTML. For example, `"<0>Entry required"` is the plain
  text “Entry required”. The field may be absent or empty, since a message
  object can carry only a field status.
- `field`—when present, names the request parameter or submission field the
  message is about (such as `title` or `abstract`), letting a client attach the
  diagnostic to the corresponding input or highlight that input.
- `pos1`, `pos2`, `context`—locate the message within the text it concerns. A
  message carries **either** `pos1` and `pos2` (byte offsets of a span in the
  UTF-8-encoded value of `field`) **or** a `context` array `["…excerpt…", pos1,
  pos2]` that bundles an excerpt of that text with the span’s byte offsets into
  the excerpt. These let the web client underline the exact problem text.

For example, an unsuccessful submission update might return:

```json
{
    "ok": false,
    "message_list": [
        {
            "status": 2,
            "field": "abstract",
            "message": "<0>Entry required to complete submission"
        },
        {
            "status": 1,
            "field": "other_topics",
            "message": "<0>Please avoid superlatives",
            "context": ["Extremely interesting ideas", 0, 9]
        },
        {
            "status": 2,
            "message": "<0>Submission not saved"
        }
    ]
}
```


## User rights

Two terms describe administrative authority in this documentation, and they are
not interchangeable.

A **site administrator** has site-wide authority over the whole conference.
Chairs and sysadmins are site administrators; they can act on every submission,
the review process, and conference settings. Endpoints restricted to this
authority are badged **Site admin only**.

A **submission administrator** has administrative authority over a particular
submission or a subset of submissions. For example, a track manager has
administrative authority over a track, and each submission can have an explicit
manager assigned from the PC. For most submissions, a site administrator is also
a submission administrator (there are exceptions involving track rights and
conflicts), but a submission manager is not necessarily a site administrator.
Endpoints that require administrative authority over specific submissions, but
do not require broad site administration rights, are badged **Admin only** or
**Track manager only**.
