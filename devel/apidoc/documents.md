# get /formatcheck

> Check PDF format


# post /upload

> Upload file

This endpoint uploads documents to the server. It is intended for large
documents, and can upload a file over multiple requests, each containing a
slice of the data.

The `token` parameter identifies an upload. To start an upload, set `start=1`,
include the `size` of the whole uploaded file, and do not include a `token`
parameter. The response to this request includes a `token` identifying the
upload; all subsequent requests must include this `token`. To complete an
upload, set `finish=1`.

The upload is completed one chunk at a time. The `offset` parameter is the
initial byte offset of the uploaded chunk; the `blob` attachment parameter
contains the chunk itself. A `finish=1` request fails unless all chunks have
been received. The `ranges` response parameter represents the ranges of bytes
received so far.

The upload API is only available on sites that have enabled the document
store.
