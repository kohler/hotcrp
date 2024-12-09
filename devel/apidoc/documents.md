# get /formatcheck

> Check PDF format

* param ?doc document_name
* param ?dt document_type
* param ?docid document_id
* param ?soft boolean


# post /upload

> Upload file

The `POST /upload` endpoint uploads documents to the server. It is intended
for large documents, and can upload a file over multiple requests, each
containing a slice of the data.

To start an upload, set `start=1` and include the `size` of the whole uploaded
file. The response will include a `token` field, a string like
`hcupwhvGDVmHNYyDKdqeqA` that identifies the upload in progress. All further
requests for the upload must include the `token` as parameter.

Each request adds a chunk of data to the upload. The `offset` parameter gives
the byte offset of the uploaded chunk; the `blob` attachment parameter
contains the chunk itself. The request that completes the upload should set
`finish=1`; this request will fail unless all chunks have been received.

The `ranges` response field represents the ranges of bytes received so far.
The response to a `finish=1` request will include a `hash` field, which is the
hash of the uploaded document.

The upload API is only available on sites that have enabled the document
store.

* param ?dtype document_type
* param ?start boolean
* param ?finish boolean
* param ?cancel boolean
* param ?token upload_token
* param ?offset nonnegative_integer: Offset of `blob` in uploaded file
* param ?size nonnegative_integer: Size of uploaded file
* param ?mimetype mimetype
* param ?filename string
* param blob
* response token upload_token
* response dtype document_type
* response filename string
* response mimetype mimetype
* response size nonnegative_integer
* response ranges [offset_range]
* response hash string
* response server_progress_loaded integer
* response server_progress_max integer
