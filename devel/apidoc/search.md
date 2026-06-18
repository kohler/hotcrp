# Search

These endpoints run submission **searches** and manage the machinery around
them: saved (named) searches, the display options that control how a result list
is shown, search-box completions, formula graphs, and bulk **search actions**.

A search is written as a HotCRP **search string**—the same syntax as the web
search box—in the `q` parameter, and runs against a **collection** of submissions
named by `t`. [`/search`](#get-search) returns the matching submission IDs and,
optionally, display fields; it is the backbone of every paper list, and several
other endpoints (here and in other sections) accept the same search parameters to
act on a search result.

These shared search parameters are:

- `q`—the search string.
- `t`—the collection of submissions to search. `t=viewable`, the broadest,
  checks every submission the caller can view; `t=s` is complete submissions. If
  `t` is omitted, HotCRP picks a default from the caller’s roles and the site
  configuration (typically `t=s` for PC members and chairs).
- `qt`—default fields to search for query terms that do not name a field, such as
  `ti` (title) or `au` (authors).
- `sort`—the result sort order, such as `id` or `-title`.
- `scoresort`—the sort order for review-score fields, such as `average` or
  `counts`.
- `reviewer`—the reviewer (email or ID) whose viewpoint evaluates
  reviewer-relative search terms such as `myreview`.


# get /search

> Retrieve search results

Return IDs, and optionally other display fields, of submissions that match a
search. The matching submission IDs are returned in the `ids` response field,
ordered according to the search.

## Display fields

The `f` and `format` parameters retrieve display fields for each submission in
the search result.

`f` defines the display fields to return. An example is `title authors[full]`,
which requests two fields: `title`, and `authors` with the `full` view option.
The `/displayfields` API lists available display fields. `format` is either
`csv` or `html`, and requests CSV or HTML format for the response data.

The response will contain two fields, `fields` and `papers`. `fields` is an array
of objects defining the emitted display fields. Typically, each entry in
`fields` corresponds to a member of `f`, but some field requests can expand into
multiple display fields. `papers` is an array of objects defining the exported
fields for each matching submission. Each `papers` entry has a `pid` field
with the submission ID, and one field for each entry in `fields`. The
`papers` entries are in the same order as `ids`. In some cases, the response
will have a `statistics` field defining overall statistics for some of the
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

## Search annotations

The `groups` response field is an array of search annotations, and is
returned for `THEN` searches, `LEGEND` searches, and searches on annotated
tags. Each `groups` entry contains a position `pos`, which is an integer index
into the search results. Annotations with `pos` `P` should appear immediately
before the submission at index `P` in the result. A `groups` entry may also
have other fields, including `legend` (the textual legend corresponding to
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

## More

The `search_params` response field is a URL-encoded string defining all
relevant parameters for the search.

Set the `hotlist` parameter to get a `hotlist` response field. A
[hotlist](#tag-search) records this ordered result—its members and the search
that produced it—so the HotCRP web client can remember the list across page
loads and offer “previous”/“next” navigation between submissions. It is returned
as an opaque JSON-encoded string (a serialized [`hotlist`](#tag-search) object);
external integrations that just want the matching IDs should read `ids`
instead.


* badge featured
* param q search_string: The search expression.
* param t

    * group Search modifiers
* param qt

    * group Search modifiers
* param sort

    * group Search modifiers
* param scoresort

    * group Search modifiers
* param reviewer

    * group Search modifiers
* param report string: Report defining default view options.
* param f string: Space-separated display field definitions.
* param format search_field_format: Format for returned display fields.
* param warn_missing boolean: Get warnings for missing submissions.
* param ?forceShow
* param hotlist boolean: Get a `hotlist` response field.
* response_schema search_response
* response ?fields [object]

    * condition format

* response ?papers [object]

    * condition format

* response ?statistics

    * condition format


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

* badge featured
* param action string: Name of action
* param q search_string: The search expression.
* param t

    * group Search modifiers
* param qt

    * group Search modifiers
* param sort

    * group Search modifiers
* param scoresort

    * group Search modifiers
* param reviewer

    * group Search modifiers
* param ?forceShow


# post /searchaction

> Perform search action

Perform the search action specified by the `action` parameter on the papers
defined by the `q` and `t` search parameters.

The request format for POST requests is the same as for GET requests.

* badge featured
* param action string: Name of action
* param q search_string: The search expression.
* param t

    * group Search modifiers
* param qt

    * group Search modifiers
* param sort

    * group Search modifiers
* param scoresort

    * group Search modifiers
* param reviewer

    * group Search modifiers
* param ?forceShow


# get /searchactions

> List search actions

Return a list of search actions accessible via HotCRP’s API.

Search actions perform actions on a set of papers specified via search. In the
HotCRP web application, search actions are shown underneath the search list;
examples include “Download > Review forms (zip)” and “Tag > Add to order”. The
`/searchactions` API endpoint retrieves the search actions that the current user
can access programmatically via the `/searchaction` API.

* badge featured
* response actions [search_action]: List of available actions


# get /viewoptions

> Retrieve display options

Return the **view options** for a report: the fields a result is displayed
with and how it is sorted. View options are expressed as a HotCRP view string
of `show:`, `hide:`, and `sort:` terms. `report` selects which display these
options govern: `pl` is the default `search` page, and `pf` is the
`reviewprefs` page.

The response gives the view from several angles: `display_current` is what the
caller currently sees (the site default plus any of their own session
adjustments), `display_default` is the site default, and `display_difference`
expresses the caller’s view as a difference from HotCRP’s built-in default.

* param ?report =pl|pf: Which report’s options to return; `pl` (paper list,
  the default) or `pf` (review preferences).

    * default pl
* param ?q search_string: A search whose result provides context for evaluating the view.
* response report =pl|pf: The report these options apply to.
* response display_current string: The view currently in effect for the caller.
* response display_default string: The site default view.
* response display_difference string: The caller’s view as a difference from the built-in default.
* response display_default_message_list message_list: Diagnostics about the default view.


# post /viewoptions

> Set default display options

Save the site default view options for a report. Chair only. Supply the new view
string in `display`; the response reports the resulting options exactly as
[`viewoptions` GET](#get-viewoptions) does.

* param ?report =pl|pf: Which report to configure; `pl` (the default) or `pf`.

    * default pl
* param ?q search_string: A search whose result provides context for evaluating the view.
* param =display string: The new default view string (`show:`/`hide:`/`sort:` terms).
* response report =pl|pf: The report these options apply to.
* response display_current string: The view currently in effect for the caller.
* response display_default string: The new site default view.
* response display_difference string: The caller’s view as a difference from the built-in default.
* response display_default_message_list message_list: Diagnostics about the default view.
* badge siteadmin


# get /searchcompletion

> Retrieve search completions

Return suggested search keywords for the search box, in `searchcompletion`.
Without arguments the full suggestion set is returned; `category` limits it to a
single class—for example `sf` (submission fields), `has`, `ss` (saved
searches), `dec` (decisions), or `round` (review rounds). Most entries are
completion strings; a few are grouped objects that bundle several related
suggestions.

* param ?category string: Limit suggestions to one category (e.g. `sf`, `has`, `ss`, `dec`, `round`).
* response searchcompletion [string|object]: Completion suggestions.


# get /graphdata

> Retrieve formula graph data

Return the data behind a **formula graph** (HotCRP’s “Graphs” feature)—for
instance a scatter plot of one formula against another over a set of
submissions. `x` (required) and `y` are formula expressions for the axes, and
`gtype` selects the graph type (such as `scatter`). The submissions to plot come
from a search: supply `q` (and optionally `t`). Several series can be overlaid by
numbering the searches `q1`, `q2`, … with optional labels `s1`, `s2`, ….

The response carries the graph in HotCRP’s internal plotting format—the axis
descriptions (`x`, `y`), a `data_format` code, and the `data` points—intended
for the HotCRP graphing UI rather than for general consumption.

* param x string: Formula expression for the x-axis.
* param ?y string: Formula expression for the y-axis.
* param ?gtype string: Graph type, such as `scatter`.
* param ?xorder string: Ordering expression for the x-axis.
* param ?q search_string: Search selecting the submissions to plot.
* param ?t search_scope: Scope of search.
* param ?s string: Label for the data series.
* response type object: Description of the graph type.
* response data_format integer: Code identifying the encoding of `data`.
* response data: The plotted data points; the exact shape depends on the graph type and `data_format`.
* response x object: X-axis description (scale, ticks, labels).
* response y object: Y-axis description (scale, ticks, labels).
