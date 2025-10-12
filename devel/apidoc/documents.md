# get /formatcheck

> Check PDF format

* param ?doc document_name
* param ?dt document_type
* param ?docid document_id
* param ?soft boolean


# post /upload

> Upload file

Upload large files to HotCRP for later use.

Servers limit how much data they will accept in a single request. The upload
API uploads larger files over multiple requests. When an upload is complete,
later requests can refer to that file using an *upload token*.

The lifecycle of an upload is as follows.

1. A request with `start=1` begins a new upload. This request should also
   include a `size` parameter to define the size of the uploaded file, if that
   is known.
2. The response to this request will include the upload token for the uploaded
   file in its `token` field. This is a string like `hcupwhvGDVmHNYyDKdqeqA`.
   All subsequent requests relating to the upload must include this token as a
   `token` parameter.
3. Subsequent requests upload the contents of the file in chunks. The `blob`
   parameter (which can be an attached file) contains the chunk itself; the
   `offset` parameter defines the offset of chunk relative to the file.
4. A request with `finish=1` completes the upload. The server seals the upload
   and responds with the file’s content hash. A `finish=1` request will fail
   unless all expected chunks have been received.

`start=1` and `finish=1` requests can also include a chunk. The `ranges`
response field represents the ranges of bytes received so far.

The upload API is only available on sites that have enabled the document
store.

* param ?start boolean
* param ?finish boolean
* param ?cancel boolean
* param ?token upload_token
* param ?offset nonnegative_integer: Offset of `blob` in uploaded file
* param ?length nonnegative_integer: Length of `blob` in bytes (must match
  actual length of `blob`)
* param blob
* param ?size nonnegative_integer: Size of uploaded file in bytes
* param ?dt document_type: (start only) Purpose of uploaded document;
  typically corresponds to a submission field ID
* param ?temp boolean: (start only) If true, the uploaded file is
  expected to be temporary
* param ?mimetype mimetype: (start only) Type of uploaded file
* param ?filename string: (start only) Name of uploaded file
* response token upload_token
* response dt document_type
* response filename string
* response mimetype mimetype
* response size nonnegative_integer
* response ranges [offset_range]
* response hash string
* response crc32 string
* response progress_value integer
* response progress_max integer
