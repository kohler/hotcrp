# info

> HotCRP conference management software API

[HotCRP](https://github.com/kohler/hotcrp) is conference review software. It
is open source; a supported version runs on [hotcrp.com](https://hotcrp.com/).
This documentation is for the HotCRP REST-like API.

To request documentation for an API method, please open a [GitHub
issue](https://github.com/kohler/hotcrp/issues). We also welcome [pull
requests](https://github.com/kohler/hotcrp/pulls).


## Basics

HotCRP reads parameters using [form
encoding](https://developer.mozilla.org/en-US/docs/Web/HTTP/Methods/POST),
either in query strings or in the request body. Complex requests generally use
structured keys, such as `named_search/1/q`, but can also use JSON encoding.
Use `multipart/form-data` for requests that include uploaded files.

The `p` parameter, which defines a submission ID, can appear either in the
query string or immediately following `api/` in the query path.
`api/comment?p=1` and `api/1/comment` are the same API call. `p` is generally
a decimal number greater than 0.

Responses are formatted as JSON. Every response has an `ok` property; `ok` is
`true` if the request succeeded and `false` otherwise. Messages about the
request, if any, are expressed in a `message_list` property.

The `GET` method is used to retrieve information and the `POST` method to
modify information. Other methods are generally not used; for instance,
deleting a comment uses a `delete=1` parameter for a `POST` request, rather
than a `DELETE` request.


## Authentication

Programmatic use of HotCRP’s API should authenticate using bearer tokens.
Obtain an API token using Account settings > Developer.

HotCRP Javascript makes API calls using session cookies for authentication.
