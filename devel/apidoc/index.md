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

Provide parameters in query strings or the request body, typically using [form
encoding](https://developer.mozilla.org/en-US/docs/Web/HTTP/Methods/POST). Some
requests use groups of parameters to define logical objects using structured
keys, such as `named_search/1/q`; other parameters define objects using JSON
format. Use `multipart/form-data` encoding for requests that include uploaded
files. Since servers limit upload size, you may need to use the upload API to
upload a large file before processing it with another call.

Responses are formatted as JSON. Every response has an `ok` property, which is
`true` if the request format succeeded and `false` otherwise. Typically `"ok":
false` indicates a serious error with the request that prevented proper
processing. Messages about the request, if any, are expressed in a
`message_list` property.

`GET` operations retrieve system state and `POST` operations modify system
state. Other operations are occasionally used when semantically meaningful—for
example, the `/paper` endpoint supports `DELETE`.


### Common parameters

The `p` parameter defines a submission ID. It can appear either in the query
string or immediately following `api/` in the query path: `api/comment?p=1`
and `api/1/comment` are the same API call. `p` is a positive decimal integer,
but some API calls accept `p=new` when defining a new submission.

The `forceShow` boolean parameter allows administrators to override their
conflicts when that is possible.


### Authentication

External applications should authenticate to HotCRP’s API using bearer tokens
(an `Authorization: bearer` HTTP header). Obtain API tokens using Account
settings > Developer. HotCRP Javascript makes API calls using session cookies
for authentication.


### Large queries

A `POST` request whose `:method:` query parameter is set to `GET` is treated as
if it were a `GET` request. Use this to work around URL length limits.
