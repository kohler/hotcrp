# Tags

These endpoints read and modify submission **tags** and the per-tag display
metadata that depends on them.

A tag is a label attached to a submission. A tag may carry a numeric **value**,
written `tag#4`; valueless tags conventionally have value 0 (and are displayed
without the `#0`). Tags drive much of HotCRP’s organization: chairs can
configure tags as **votes** or **approval votes** (each PC member has an
allotment), as **rankings**, or as **colors/styles** that highlight a
submission. Some tags are **automatic** (maintained by a formula and read-only)
or otherwise read-only to non-chairs.

Tags also have visibility rules. **Twiddle tags** are private: `~tag` is stored
per user (only you see your `~tag`), and `~~tag` is visible only to chairs.
Ordinary tags are visible to the PC, except that a conflicted PC member may see
a different set of tags on submissions they are conflicted with — which is why
several responses carry both a normal and a `…_conflicted` variant.

## Tag objects

Most endpoints return a submission’s tags as a **tag object** (the
[`tag_response`](#tag-tags) schema). Because tags feed several different UI
elements, the same tags are presented in several forms:

* `tags` — the machine-readable list of tags (with values), as a
  [`tag_value_list`](#tag-tags).
* `tags_edit_text` — the tags as editable text.
* `tags_view_html` — rendered HTML for display.
* `tag_decoration_html` — HTML for any badges and emoji the tags imply.
* `color_classes` — CSS style classes implied by the tags.

When the viewer is conflicted, `tags_conflicted` and `color_classes_conflicted`
give the conflict-blind versions.

Throughout, the `tag` parameter names a single tag, and tag-assignment input
uses HotCRP’s tag syntax (`tag`, `tag#value`, `tag#clear` to remove). Bulk
changes go through the [assignment](#tag-assignments) machinery, so the same
permission rules apply.

## Tag annotations

An ordered tag — one used for a ranking or for votes — can carry **annotations**
that divide its submissions into labeled groups, such as the section headings in
a discussion order. Each annotation is a [`tag_annotation`](#tag-tags) object:

* `annoid` — a stable integer ID for the annotation within the tag.
* `tagval` — the tag value at which the group begins. An annotation labels the
  run of submissions whose value for the tag is at least its `tagval` and less
  than the next annotation’s, so the annotations’ `tagval`s define the group
  boundaries.
* `legend` — the group’s display text (with a `format` code when it is not the
  default text format). A divider with no text instead carries `blank: true`.
* `tag` — the tag the annotation belongs to.
* `pos` — when annotations accompany a search result, the index in that result
  at which the annotation falls (the same meaning as `groups[].pos` from
  [`/search`](#get-search)).

When the tag organizes a meeting agenda, an annotation may also carry
session-scheduling metadata: `session_title`, `time`, `location`, and
`session_chair`.


# get /{p}/tags

> Retrieve a submission’s tags

Return the tags visible to the caller on submission `p`, as a
[tag object](#tag-tags).

* badge featured
* response_schema tag_response


# post /{p}/tags

> Change a submission’s tags

Modify the tags on submission `p` and return the updated [tag object](#tag-tags).

Two modes of change are available, and may be combined:

* **Replace** — `tags` sets the submission’s complete tag list, removing any
  tags not listed (subject to permissions).
* **Incremental** — `add_tags` adds tags and `remove_tags` removes them, leaving
  other tags untouched.

To make a change conditional, set `expected_tags` to the tag list you believe is
current; if the submission’s tags differ, the request fails without changing
anything (optimistic concurrency control). As with other modifying endpoints,
supplying `search` re-runs that search after the change so a client can refresh
a list in one round trip.

* badge featured
* param ?=tags string: Complete new tag list; replaces all editable tags on the submission.
* param ?=add_tags string: Tags to add, leaving others in place.
* param ?=remove_tags string: Tags to remove, leaving others in place.
* param ?=expected_tags string: If set, apply the change only if the submission’s current tags match this list.
* param ?=search search_parameter_specification: A search to evaluate after the change; its results are added to the response.
* response_schema tag_response
* response_schema search_response.opt


# post /assigntags

> Change tags on several submissions

Apply tags to many submissions in one request. `tagassignment` is a
whitespace/comma-separated stream of submission IDs and tags: each number sets
the “current” submission, and each following tag (until the next number) is
assigned to it. For example, `1 discuss 2 discuss reject` tags submission 1 with
`discuss` and submission 2 with `discuss` and `reject`.

The response’s `p` array carries refreshed [tag objects](#tag-tags) for the
affected submissions. Supplying `search` adds that search’s results to the
response as well.

* badge featured
* param =tagassignment string: Submission IDs interleaved with the tags to assign to them.
* param ?=search search_parameter_specification: A search to evaluate after the change; its results are added to the response.
* response p [tag_response]: Refreshed tag objects for the affected submissions.
* response_schema search_response.opt


# get /alltags

> List all visible tags

Return every tag name the caller can see, for tag completion and similar uses.
This endpoint is available to PC members only; the result is the set of tag
names across all viewable submissions, not the tags of any one submission.

`readonly_tagmap` and `sitewide_tagmap`, when present, are objects whose keys are
the (lowercased) tags that are read-only or site-wide, respectively.

* response tags tag_list: All visible tag names.
* response ?readonly_tagmap object: Map whose keys are the read-only tags.
* response ?sitewide_tagmap object: Map whose keys are the site-wide tags (chairs only).


# get /taganno

> Retrieve tag annotations

Return the **annotations** of a tag. Annotations divide an ordered (ranking or
votes) tag into labeled groups — for example, section headings in a discussion
order. `editable` reports whether the caller may change them.

* param tag tag: The tag whose annotations to return.
* param ?search search_parameter_specification: A search to evaluate; its results are added to the response.
* response tag tag: The tag.
* response editable boolean: Whether the caller may edit these annotations.
* response anno [tag_annotation]: The tag’s annotations, in order.
* response_schema search_response.opt


# post /taganno

> Change tag annotations

Replace the annotations of a tag. Supply the desired changes in `anno`, a JSON
array of [`tag_annotation`](#tag-tags) objects (a single object is also
accepted). Each entry is matched to an existing annotation by its `annoid`:

* To **modify** an annotation, give its existing integer `annoid` along with the
  fields to change (`legend`, `tagval`, and/or the session-scheduling fields
  `session_title`, `time`, `location`, `session_chair`; pass `none` for a session
  field to clear it).
* To **create** an annotation, use an `annoid` string beginning with `n` (for
  example `n1`); the server assigns the real ID.
* To **delete** an annotation, give its `annoid` and `"delete": true`.

Only the fields you supply are changed; omitted fields are left as they were.
Requires permission to administer the tag. Returns the updated annotations, as
for [`taganno` GET](#get-taganno).

* param tag tag: The tag whose annotations to change.
* param +anno [tag_annotation]: The new annotation list.
* param ?search search_parameter_specification: A search to evaluate; its results are added to the response.
* response tag tag: The tag.
* response editable boolean: Whether the caller may edit these annotations.
* response anno [tag_annotation]: The updated annotations, in order.
* response_schema search_response.opt


# get /{p}/tagmessages

> Retrieve tag edit messages

Return advisory messages about the caller’s tags on submission `p`, in the
standard `message_list`. These are the warnings HotCRP shows around voting tags
— for instance, how many votes remain in an allotment, or that an allotment has
been exceeded. The response carries the submission’s `pid`; the messages
themselves are in `message_list`.

* response pid pid: Submission ID.


# get /{p}/votereport

> Retrieve vote analysis

Return a human-readable summary of who voted for submission `p` using a voting
tag. `vote_report` is an HTML fragment listing the voters (with vote counts, for
allotment votes); it is empty when there are no votes. Requires permission to
see per-user values of the tag.

* param tag tag: The voting (or approval) tag to report on.
* response vote_report string: HTML summary of the voters, or an empty string.
