# info

> HotCRP conference management software API

[HotCRP](https://github.com/kohler/hotcrp) is conference review software. It
is open source; a supported version runs on [hotcrp.com](https://hotcrp.com/).

We welcome [pull requests](https://github.com/kohler/hotcrp/pulls) that fill
out this documentation. To request documentation for an API method, please
open a [GitHub issue](https://github.com/kohler/hotcrp/issues).

## Basics

HotCRP reads parameters using [form
encoding](https://developer.mozilla.org/en-US/docs/Web/HTTP/Methods/POST),
either in query strings or in the request body. Complex requests either use
structured keys, such as `named_search/1/q`, or, occasionally, JSON encoding.
`multipart/form-data` is used for requests that include file data.

The `p` parameter, which defines a submission ID, can appear either in the
query string or immediately following `api/` in the query path.
`api/comment?p=1` and `api/1/comment` are the same API call.

Responses are formatted as JSON.
