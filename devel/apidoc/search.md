# Search

These endpoints perform searches on submissions.


# get /search

> Retrieve search results

Return IDs, and optionally other fields, of submissions that match a search.

Pass the search query in the `q` parameter. The list of matching submission
IDs is returned in the `ids` response property, ordered according to the
search.

The `t`, `qt`, `reviewer`, `sort`, and `scoresort` parameters can also affect
the search. `t` defines the collection of submissions to search, where
`t=viewable` checks all submissions the user can view. If `t` is not provided,
HotCRP picks a default based on the user’s roles and the site’s current
configuration; for PC members and chairs, the typical default is `t=s`, which
searches complete submissions.

### Search annotations

The `groups` response property is an array of search annotations, and is
returned for `THEN` searches, `LEGEND` searches, and searches on annotated
tags. Each `groups` entry contains a position `pos`, which is an integer index
into the search results. Annotations with `pos` `P` should appear immediately
before the submission at index `P` in the result. A `groups` entry may also
have other properties, including `legend` (the textual legend corresponding to
the annotation), `search` (for `THEN` searches, the search string representing
the following results), and `annoid`.

As an example, this response might be returned for the search `10-12 THEN
5-8`.

```json
{
    "ok": true,
    "ids": [10, 12, 8],
    "groups": [
        {
            "pos": 0,
            "legend": "10-12",
            "search": "10-12"
        },
        {
            "pos": 2,
            "legend": "5-8",
            "search": "5-8"
        }
    ]
}
```

### Submission fields

Pass `f` and `format` parameters to retrieve more fields for each submission
in the search result.

`format` is either `csv` or `html`, and requests CSV or HTML format for the
response data. `f` is a string indicating the fields to return, such as `title
authors[full]`.

The response will contain `fields` and `papers` properties. `fields` is an
array of objects defining the emitted fields; typically, each entry in
`fields` corresponds to an field definition in `f`. `papers` is an array of
objects defining the exported fields for each matching submission. Each
`papers` entry has a `pid` property with the submission ID, and properties
corresponding to the `fields`; the `papers` entries are in the same order as
`ids`. In some cases, the response will additionally have a `statistics`
property defining overall statistics for some of the requested fields.

As an example, this response might be returned for the search `10-12` with
`format=csv` and `f=title`.

```json
{
    "ok": true,
    "ids": [10, 12],
    "fields": [
        {
            "name": "title",
            "title": "Title",
            "order": 120,
            "sort": "ascending"
        }
    ],
    "groups": [],
    "papers": [
        {
            "pid": 10,
            "title": "Active Bridging"
        },
        {
            "pid": 12,
            "title": "Dynamics of Random Early Detection"
        }
    ]
}
```

Please note that `html` format is unlikely to be useful outside the HotCRP web
application. The returned HTML uses elements, tag structures, and class names
suitable for HotCRP’s internal use, and may change at any time. Furthermore,
in some cases (such as `f=allpref`), the returned data is compressed into a
field-specific format that the HotCRP web application expands.

### More

The `search_params` response property is a URL-encoded string defining all
relevant parameters for the search.

Set the `hotlist` parameter to get a `hotlist` response property, which is
used by the HotCRP browser Javascript to remember information about a list of
papers.


* param q
* param t
* param f string: Space-separated field definitions
* param format search_field_format: Format for returned submission fields (`f`)
* param qt
* param sort
* param scoresort
* param reviewer
* param report string: Report defining default view options
* param warn_missing boolean: Get warnings for missing submissions
* param hotlist boolean: Get a `hotlist` response property
* response_schema search_response
* response ?fields []
* response ?papers []
* response ?statistics


# get /searchactions

> List search actions

Return a list of search actions accessible via HotCRP’s API.

Search actions perform actions on a set of papers specified via search. In the
HotCRP web application, many search actions are shown underneath the search
list; examples include “Get > Review forms (zip)” or “Tag > Add to order”. This
API endpoint retrieves the search actions that the current user can access
programmatically via the `/searchaction` API.

* response actions [search_action]: List of available actions


# get /searchaction

> Perform search action

Perform the search action specified by the `action` parameter on the papers
defined by the `q` and `t` search parameters.

The `action` parameter must correspond to the `name` of a valid search action,
as returned from the `/searchactions` API endpoint. Other parameters may be
provided; the `/searchactions` response mentions relevant parameters for each
action.

Search action responses do not follow HotCRP’s typical conventions. Successful
responses may not use the JSON content type. For instance, the `get/paper`
action typically returns a ZIP file containing submission PDFs, and the
`get/csv` action returns a CSV-formatted text file. Furthermore, successful JSON
responses may not be objects, or may not contain an `ok` property; for example,
a successful response to a `get/json` request is an array of objects.
Applications wanting predictable JSON responses should use other API endpoints.
Nevertheless, `/searchaction` can be more convenient than using more
standardized APIs.

* param action string: Name of action


# post /searchaction

> Perform search action

Perform the search action specified by the `action` parameter on the papers
defined by the `q` and `t` search parameters.

The request format for POST requests is the same as for GET requests.

* param action string: Name of action
