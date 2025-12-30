# Search

These endpoints perform searches on submissions.


# get /search

> Fetch search results

Return IDs, and optionally other display fields, of submissions that match a
search.

The `q` parameter defines the search. The list of matching submission IDs is
returned in the `ids` response property, ordered according to the search.

The `t`, `qt`, `reviewer`, `sort`, and `scoresort` parameters can modify the
search. `t` defines the collection of searched submissions. `t=viewable` is the
broadest; it checks all submissions the user can view. If `t` is not provided,
HotCRP picks a default based on the user’s roles and the site’s current
configuration. For PC members and chairs, the typical default is `t=s`, which
searches complete submissions.

### Display fields

The `f` and `format` parameters retrieve display fields for each submission in
the search result.

`f` defines the display fields to return. An example is `title authors[full]`,
which requests two fields: `title`, and `authors` with the `full` view option.
The `/displayfields` API lists available display fields. `format` is either
`csv` or `html`, and requests CSV or HTML format for the response data.

The response will contain `fields` and `papers` properties. `fields` is an array
of objects defining the emitted display fields. Typically, each entry in
`fields` corresponds to a member of `f`, but some field requests can expand into
multiple display fields. `papers` is an array of objects defining the exported
fields for each matching submission. Each `papers` entry has a `pid` property
with the submission ID, and properties corresponding to the `fields`. The
`papers` entries are in the same order as `ids`. In some cases, the response
will have a `statistics` property defining overall statistics for some of the
requested fields.

This response might be returned for the search `10-12` with `format=csv` and
`f=title`:

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

The `html` format is unlikely to be useful outside the HotCRP web application.
The returned HTML uses elements, tag structures, and class names suitable for
HotCRP’s internal use, and may change at any time. Furthermore, in some cases
(such as `f=allpref`), the returned data is compressed into a field-specific
format that the HotCRP web application expands.

### Search annotations

The `groups` response property is an array of search annotations, and is
returned for `THEN` searches, `LEGEND` searches, and searches on annotated
tags. Each `groups` entry contains a position `pos`, which is an integer index
into the search results. Annotations with `pos` `P` should appear immediately
before the submission at index `P` in the result. A `groups` entry may also
have other properties, including `legend` (the textual legend corresponding to
the annotation), `search` (for `THEN` searches, the search string representing
the following results), and `annoid`.

This response might be returned for the search `10-12 THEN 5-8`:

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
* response ?fields [object]
* response ?papers [object]
* response ?statistics


# get /displayfields

> List display fields

Return a list of all supported display fields. Display fields can be requested
in the web UI (search for `show:FIELDNAME`) or in the API (supply `f=FIELDNAME`
to the `/search` endpoint).

* response fields [display_field]


# get /searchaction

> Perform search action

Perform the search action specified by the `action` parameter on the papers
defined by the `q` and `t` search parameters.

The `action` parameter names a search action. The `/searchactions` API lists
available actions. Other parameters may be provided; the `/searchactions`
response mentions relevant parameters for each action.

Search action responses do not follow HotCRP’s normal API conventions, in that
successful responses may not be JSON objects. For instance, the `get/paper`
action typically returns a ZIP file containing submission PDFs, the `get/csv`
action returns a CSV-formatted text file, and the `get/json` request returns a
JSON array, rather than a JSON object. Applications wanting predictable JSON
responses should use other API endpoints. Nevertheless, `/searchaction` can be
more convenient than other more standardized APIs.

* param action string: Name of action


# post /searchaction

> Perform search action

Perform the search action specified by the `action` parameter on the papers
defined by the `q` and `t` search parameters.

The request format for POST requests is the same as for GET requests.

* param action string: Name of action


# get /searchactions

> List search actions

Return a list of search actions accessible via HotCRP’s API.

Search actions perform actions on a set of papers specified via search. In the
HotCRP web application, search actions are shown underneath the search list;
examples include “Download > Review forms (zip)” and “Tag > Add to order”. The
`/searchactions` API endpoint retrieves the search actions that the current user
can access programmatically via the `/searchaction` API.

* response actions [search_action]: List of available actions
