# Search

These endpoints perform searches on submissions.


# get /search

> Retrieve search results

Return IDs of submissions that match a search.

Pass the search query in the `q` parameter. The list of matching IDs is
returned in the `ids` response property.

The `t`, `qt`, `reviewer`, `sort`, and `scoresort` parameters can also affect
the search. `t` defines the collection of submissions to search, where
`t=viewable` checks all submissions the user can view. If `t` is not provided,
HotCRP picks a default based on the user’s roles and the site’s current
configuration. For PC members and chairs, the typical default is `t=s`, which
searches complete submissions.

The `groups` response property is an array of annotations that apply to the
search. It is returned for `THEN` searches, `LEGEND` searches, and searches on
annotated tags. Each `groups` entry contains a position `pos`, and may also have
a `legend`, a `search`, an `annoid`, and other properties. `pos` is an integer
index into the `ids` array; it ranges from 0 to the number of items in that
array. Annotations with a given `pos` should appear *before* the paper at that
index in the `ids` array. For instance, this response might be returned for the
search `10-12 THEN 15-18`:

```json
{
    "ok": true,
    "message_list": [],
    "ids": [10, 12, 18],
    "groups": [
        {
            "pos": 0,
            "legend": "10-12",
            "search": "10-12"
        },
        {
            "pos": 2,
            "legend": "15-18",
            "search": "15-18"
        }
    ]
}
```

* response_schema search_response


# get /fieldhtml

> Retrieve search results as field HTML


# get /fieldtext

> Retrieve list field text


# get /searchactions

> Retrieve available search actions

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
