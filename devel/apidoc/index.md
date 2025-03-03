# info

> HotCRP conference management software API

[HotCRP](https://github.com/kohler/hotcrp) is conference review software. It
is open source; a supported version runs on [hotcrp.com](https://hotcrp.com/).
This documentation is for the HotCRP REST-like API.

To request documentation for an API method, please open a [GitHub
issue](https://github.com/kohler/hotcrp/issues). We also welcome [pull
requests](https://github.com/kohler/hotcrp/pulls).


## Overview

API calls use paths under `api`. For instance, to call the `paper` endpoint on
a server at `https://example.hotcrp.org/funconf25`, you might use a URL like
`https://example.hotcrp.org/funconf25/api/paper?p=1`.

Most parameters are provided using [form
encoding](https://developer.mozilla.org/en-US/docs/Web/HTTP/Methods/POST),
either in query strings or in the request body. Some parameters are formatted
as JSON. Some complex requests use structured keys, such as
`named_search/1/q`. Use `multipart/form-data` for requests that include
uploaded files. Since servers limit upload size, you may need to use the
upload API to upload a large file before processing it with another call.

Responses are formatted as JSON. Every response has an `ok` property; `ok` is
`true` if the request succeeded and `false` otherwise. Messages about the
request, if any, are expressed in a `message_list` property.

The `GET` method is used to retrieve information and the `POST` method to
modify information. Other methods are generally not used; for instance,
deleting a comment uses a `delete=1` parameter for a `POST` request, rather
than a `DELETE` request.


### Common parameters

The `p` parameter defines a submission ID. It can appear either in the query
string or immediately following `api/` in the query path: `api/comment?p=1`
and `api/1/comment` are the same API call. `p` is a positive decimal integer,
but some API calls accept `p=new` when defining a new submission.


### Authentication

External applications should authenticate to HotCRP’s API using bearer tokens
(an `Authorization: bearer` HTTP header). Obtain API tokens using Account
settings > Developer. HotCRP Javascript makes API calls using session cookies
for authentication.
