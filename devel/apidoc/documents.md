# Documents

These endpoints query documents associated with HotCRP submissions, reviews,
and comments. The `/upload` endpoint can be used to upload a large file using
multiple requests; this file can be a document or a temporary file intended
for input to another API.


# get /document

> Retrieve document

Retrieve a document and return it in the response body. Add `hash` or `docid`
to retrieve a specific document version; such requests are usually cacheable,
and only administrators and authors can make them.

Successful requests (HTTP status code 200) return the requested document as the
response, without any JSON wrapper. Find the document’s MIME type using the
response’s `Content-Type` header. Unsuccessful requests (HTTP status code 300 or
higher) usually return a JSON object with `ok` set to `false` and a
`message_list` describing the error.

This API understands conditional requests with HTTP headers `If-Match`,
`If-None-Match`, `If-Modified-Since`, and `If-Unmodified-Since`, and many
responses include `ETag` and `Last-Modified` HTTP headers. It also understands
range requests.

* param ?doc document_name: Document name, e.g. `testconf-paper1.pdf`.

    * oneof docspec doc
* param ?p pid: Submission ID. Combine with `dt`, and optionally `file`, to
  identify a document by submission field.

    * oneof docspec p
* param ?dt document_type: Submission field holding the document.

  * oneof docspec p
* param ?file string: For fields that hold multiple documents, the name of the
  desired file.

  * oneof docspec p
* param ?hash string: Document version selected by hash.
* param ?docid document_id: Document version selected by internal document ID.


# get /documentlist

> List documents

Retrieve information about documents and document versions accessible to the
requesting user. A request with just the `p=PID` parameter lists all available
documents currently associated with the submission. To request information about
a specific submission field, add a `dt` or `doc` parameter. Setting `history=1`
requests information about past document versions as well as current ones.

* param ?doc document_name

   * oneof docspec doc
* param ?p pid

   * oneof docspec p
* param ?dt

   * oneof docspec p
* param ?history boolean
* response dt document_type
* response document_history [document_history_entry]


# get /formatcheck

> Check PDF format

Run HotCRP’s PDF format checker on a specified document. A human-readable
response is returned in `message_list`. The `problem_fields` response property
lists the names of any PDF checks that failed; examples include `"papersize"`,
`"pagelimit"`, `"columns"`, `"textblock"`, `"bodyfontsize"`, `"bodylineheight"`,
and `"wordlimit"`. The `npages_detail` response property is provided only if the
request’s `detail` parameter is truthy.

* param ?doc document_name
    * oneof docspec doc
* param ?p
    * oneof docspec p
* param ?dt document_type
    * oneof docspec p
* param ?file string
    * oneof docspec p
* param ?docid document_id
* param ?hash
* param ?soft boolean
* param ?detail boolean
* response docid document_id
* response npages nullable_int: Number of pages in PDF
* response nwords nullable_int: Number of words in PDF
* response problem_fields [string]
* response has_error boolean
* response ?npages_detail object: Number of pages in PDF per page type

    * condition detail


# get /archivecontents

> List contents of archive document

List the contents of a ZIP, .tar, .tar.gz, .tar.bz2, or .tar.xz archive. Returns
the list of included filenames in the `archive_contents` property. The
`summary=1` parameter requests an additional `archive_contents_summary`, which a
preformatted string that uses `{}` notation to represent subdirectories; for
instance, `subdir/{file1.txt, file2.txt}`.

* param ?doc document_name
    * oneof docspec doc
* param ?p pid
    * oneof docspec p
* param ?dt document_type
    * oneof docspec p
* param ?file string
    * oneof docspec p
* param ?docid
* param ?hash
* param ?summary boolean: True requests `archive_contents_summary`
* response archive_contents [string]: List of archive elements
* response archive_contents_summary string: Parsed archive listing

    * condition summary


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

* param ?p pid
* param ?start boolean

    Set to true to start a new upload.
* param ?dt document_type: Purpose of uploaded document;
  typically corresponds to a submission field ID

    * condition start
* param ?temp boolean: If true, the uploaded file is
  expected to be temporary

    * condition start
* param ?mimetype mimetype: Type of uploaded file

    * condition start
* param ?filename string: Name of uploaded file

    * condition start
* param ?token upload_token

    Token for the ongoing upload. Required unless `start=1`.
* param blob

    Chunk being uploaded.
* param ?offset nonnegative_integer: Offset of `blob` in uploaded file.

    * condition blob
* param ?length nonnegative_integer: Length of `blob` in bytes (must match
  actual length of `blob`).

    * condition blob
* param ?size nonnegative_integer: Size of uploaded file in bytes.
* param ?finish boolean

    Set to true to complete the upload.
* param ?cancel boolean

    Set to true to cancel an ongoing upload.
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
