# Documents

These endpoints query documents associated with HotCRP submissions, reviews,
and comments. The `/upload` endpoint can be used to upload a large file using
multiple requests; this file can be a document or a temporary file intended
for input to another API.


# get /document

> Fetch document

Fetch a document and return it in the response body. Specify the document to
return either with the `doc` parameter, which names the document using a pattern
like `testconf-paper1.pdf`, or the `p`, `dt`, and optional `file` parameters,
which define the submission ID and submission field and, in the case of fields
that accept multiple documents, the name of the desired file.

The `hash` and `docid` parameters select a specific version of a document.
`hash` selects a document by hash, and `docid` by internal document ID.
Responses to requests with `hash` or `docid` are usually cacheable. Only
administrators and authors can select specific document versions.

Successful requests (HTTP status code 200) return the requested document as the
response, without any JSON wrapper. Find the document’s MIME type using the
response’s `Content-Type` header. Unsuccessful requests (HTTP status code 300 or
higher) usually return a JSON object with `ok` set to `false` and a
`message_list` describing the error.

This API understands conditional requests with HTTP headers `If-Match`,
`If-None-Match`, `If-Modified-Since`, and `If-Unmodified-Since`, and many
responses include `ETag` and `Last-Modified` HTTP headers. It also understands
range requests.


# get /documentlist

> Fetch list of documents

Fetch information about documents and document versions accessible to the
requesting user. A request with just the `p=PID` parameter lists all available
documents currently associated with the submission. To request information about
a specific submission field, add a `dt` or `doc` parameter. Setting `all=1`
requests information about past document versions as well as current ones.

* param ?dt
* param ?doc
* param ?history boolean
* response dt document_type
* response document_history [document_history_entry]


# get /formatcheck

> Check PDF format

Run HotCRP’s PDF format checker on a specified document. A human-readable
response is returned in `message_list`. The `problem_fields` response property
lists the names of any PDF checks that failed; examples include `"papersize"`,
`"pagelimit"`, `"columns"`, `"textblock"`, `"bodyfontsize"`, `"bodylineheight"`,
and `"wordlimit"`.

* param ?doc document_name
* param ?p
* param ?dt document_type
* param ?docid document_id
* param ?soft boolean
* response docid document_id
* response npages nullable_int: Number of pages in PDF
* response nwords nullable_int: Number of words in PDF
* response problem_fields [string]
* response has_error boolean


# get /archivecontents

> List contents of archive document

List the contents of a ZIP, .tar, .tar.gz, .tar.bz2, or .tar.xz archive. Returns
the list of included filenames in the `archive_contents` property. The
`summary=1` parameter requests an additional `archive_contents_summary`, which a
preformatted string that uses `{}` notation to represent subdirectories; for
instance, `subdir/{file1.txt, file2.txt}`.

* param ?summary boolean: True requests `archive_contents_summary`
* response archive_contents [string]: List of archive elements
* response archive_contents_summary string: Parsed archive listing


# post /upload

> Upload file

Upload large files for later use.

Servers limit how much data they will accept in a single request. The upload API
can upload large files using multiple requests. When an upload is complete,
later API requests can refer to that file using an *upload token*.

The lifecycle of an upload is as follows.

1. A request with `start=1` begins a new upload. This request should also
   include a `size` parameter to define the size of the uploaded file, if that
   is known, and parameters defining its purpose (`dt`, `mimetype`, `filename`,
   and `temp`).
2. The response to this request includes the upload token in its `token`
   property. This is a string like `hcupwhvGDVmHNYyDKdqeqA`. All subsequent
   requests relating to the upload must include this token as a `token`
   parameter.
3. Subsequent requests upload the contents of the file in chunks. The `blob`
   parameter (which can be an attached file) contains the chunk itself, and the
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
