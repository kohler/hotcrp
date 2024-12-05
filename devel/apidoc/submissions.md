# Submissions

These endpoints query and modify HotCRP submissions.


# get /paper

> Retrieve submission(s)

Use this endpoint to retrieve JSON-formatted information about submissions.

Provide either the `p` parameter or the `q` parameter. The `p` parameter
should be a submission ID; the server will return information about that
single submission in the `paper` response field. Otherwise, the `q` parameter
should be a search query (other search parameters `t`, `qt`, etc. are also
accepted); the server will return information about all matching submissions
in the `papers` response field, which is an array of paper objects.

Error messages—for instance, about permission errors—are returned in the
`message_list` component as usual.

Paper object fields depend on the submission form. Every paper has an `object`
member, a `pid`, and a `status`. Other fields are provided depending on
whether they exist and whether the accessing user can see them.


# post /paper

> Create or modify submission(s)

### Single submission

A request with a `p` parameter (as a path parameter `/{p}/paper` or a query
parameter) modifies the submission with that ID. The special ID `new` can be
used to create a submission.

The body of the request may be formatted as an HTML form
(`application/x-www-form-urlencoded` or `multipart/form-data`), a JSON object
(`application/json`), or a ZIP file (`application/zip`—see below). HTML form
input follows the conventions of the HotCRP web application and is subject to
change at any time.

### Multiple submissions

A request with no `p` parameter can create or modify any number of
submissions.


### ZIP uploads

A ZIP upload should contain a file named `data.json` (`PREFIX-data.json` is
also acceptable). This file’s content is parsed as JSON and treated a
submission object (or array of submission objects). Attachment fields in the
JSON content can refer to other files in the ZIP. For instance, this shell
session might upload a submission with content `paper.pdf`:

```
$ cat data.json
{
	"object": "paper",
	"pid": "new",
	"title": "Aught: A Methodology for the Visualization of Scheme",
	"authors": [{"name": "Nevaeh Gomez", "email": "ngomez@example.edu"}],
	"submission": {"content_file": "paper.pdf"},
	"status": "submitted"
}
$ zip upload.zip data.json paper.pdf
$ curl -H "Authorization: bearer hct_XXX" --data-binary @upload.zip -H "Content-Type: application/zip" SITEURL/api/paper
```
