# Search

These endpoints perform searches on submissions.


# get /search

> Retrieve search results

Use this endpoint to return the list of submission IDs corresponding to a
search.

Pass the search query in the `q` parameter. The list of matching IDs is
returned in the `ids` response property.

The `t`, `qt`, `reviewer`, `sort`, and `scoresort` parameters can also affect
the search. `t` defines the collection of submissions to search. `t=viewable`
checks all submissions the user can view; the default collection is often
narrower (a typical default is `t=s`, which searches complete submissions).

The `groups` response property is an array of annotations that apply to the
search, and is returned for `THEN` searches, `LEGEND` searches, and searches
on tags with annotations. Each annotation contains a position `pos`, and may
also have a `legend`, a `search`, an `annoid`, and other properties. `pos` is
an integer index into the `ids` array; it ranges from 0 to the number of items
in that array. Annotations with a given `pos` should appear *before* the paper
at that index in the `ids` array. For instance, this response might be
returned for the search `10-12 THEN 15-18`:

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


# get /searchaction

> Perform search action


# post /searchaction

> Perform search action
