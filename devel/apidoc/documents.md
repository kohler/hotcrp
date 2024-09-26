# get /formatcheck

> Check PDF format


# post /upload

> Upload file

This endpoint uploads documents to the server. It is intended for large
documents, and can upload a file over multiple requests, each containing a
slice of the data.

An upload is identified by a `token`. To start an upload, set `start=1` and do
not include a `token` parameter. All subsequent requests must include the
`token` returned by the server in response to the `start=1` request.
