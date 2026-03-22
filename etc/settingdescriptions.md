# tag_style

> Configures relationships between tags and display colors and styles

Submissions tagged with one of the `tags` listed for `style` will appear in
that style in submission lists and on submission pages.

Built-in `style`s include
<span class="taghh tagbg tag-red">red</span>,
<span class="taghh tagbg tag-orange">orange</span>,
<span class="taghh tagbg tag-yellow">yellow</span>,
<span class="taghh tagbg tag-green">green</span>,
<span class="taghh tagbg tag-blue">blue</span>,
<span class="taghh tagbg tag-purple">purple</span>,
<span class="taghh tagbg tag-gray">gray</span>,
<span class="taghh tagbg tag-white">white</span>,
<span class="taghh tag-bold">bold</span>,
<span class="taghh tag-italic">italic</span>,
<span class="taghh tag-underline">underline</span>,
<span class="taghh tag-strikethrough">strikethrough</span>,
<span class="taghh tag-big">big</span>,
<span class="taghh tag-small">small</span>, and
<span class="taghh tag-dim">dim</span>.

You can also add styles. Extended styles include:

* `rgb-RRGGBB`: Color the submission with `#RRGGBB`, an HTML hex color.
  Examples: <span class="taghh tagbg dark tag-rgb-000000">rgb-000000</span>,
  <span class="taghh tagbg tag-rgb-ffee33">rgb-ffee33</span>.
* `text-rgb-RRGGBB`: Display the submission’s title in color `#RRGGBB`.
  Examples: <span class="taghh tag-text-rgb-ff0000">text-rgb-ff0000</span>,
  <span class="taghh tag-text-rgb-8899cc">text-rgb-8899cc</span>.
* `font-NAME`: Display the submission’s title using font `NAME`. Examples:
  <span class="taghh tag-font-fantasy">font-fantasy</span>,
  <span class="taghh tag-font-serif">font-serif</span>,
  <span class="taghh tag-font-Courier_New">font-Courier_New</span>.
* `weight-WEIGHT`: Display the submission’s title in font weight `WEIGHT`.
  Examples: <span class="taghh tag-weight-100">weight-100</span>,
  <span class="taghh tag-weight-900">weight-900</span>.

For example, this entry would cause papers tagged `#shocking` to appear with a
<span class="taghh tagbg dark tag-rgb-ff3ac6">shocking pink background</span>.

```json
{ "style": "rgb-ff3ac6", "tags": "shocking" }
```

A tag can appear in more than one list.
Submissions with multiple styles will appear with
<span class="taghh tagbg tag-red tag-orange tag-underline">combined styles</span>.


# badge

> Configures tags that appear as badges

Submissions tagged with one of the `tags` listed for `style` will appear with
that style of <span class="badge">#badge</span>.

Built-in `style`s include
<span class="badge badge-black">black</span>,
<span class="badge badge-white">white</span>,
<span class="badge badge-red">red</span>,
<span class="badge badge-orange">orange</span>,
<span class="badge badge-yellow">yellow</span>,
<span class="badge badge-green">green</span>,
<span class="badge badge-blue">blue</span>,
<span class="badge badge-purple">purple</span>,
<span class="badge badge-gray">gray</span>, and
<span class="badge badge-pink">pink</span>.

You can also make your own badge colors with a style like `rgb-RRGGBB`, which
colors the badge with `#RRGGBB`, an HTML hex color.


# automatic_tag

> Configures tags set automatically by the system

The `tag` will be set automatically on all papers matching `search`, and removed
from papers not matching `search`, where `search` uses HotCRP’s search syntax.

The optional `value` is a formula that sets the tag’s value. For example, the
formula “avg(OveMer)” would set the tag’s value to the relevant paper’s
average overall merit score. If the formula evaluates to null for a paper,
then the tag for that paper is removed. If `value` is an empty string, 0 is
used.


# sf

> Configures the submission form

Each entry defines a submission field that authors fill out when submitting a
paper.

Fields with numeric IDs must specify a `type`, and can be added and removed by
administrators. Built-in fields have textual IDs (e.g., `"title"`, `"abstract"`)
and are always listed in the settings, though many can be removed from the
submission form by modifying their `condition`.

### Display

The `name` is a short name for the submission field. The submission edit page
also displays the HTML `description`. The form presents fields in ascending
`order`, with the `"title"` field always first.

`visibility` controls which reviewers can see a field: all reviewers (`"all"`),
reviewers who can see the authors (`"nonblind"`), reviewers who can see
conflicts (`"conflict"`; usually the same as `"nonblind"`, but separately
configurable), assigned reviewers (`"review"`), or only administrators
(`"admin"`). `display` controls where the field appears on display pages: with
the title (`"title"`), underneath the title (`"top"`), in the left panel with
the abstract (`"left"`), in the right panel with the authors (`"right"`),
grouped with topics and options (`"rest"`), or not at all (`"none"`).

### Editing

`condition` and `edit_condition` implement conditional fields. A field is
only present on the submission form if the submission matches the `condition`
search expression, and a field is only editable if the submission matches the
`edit_condition` search expression. A field’s `condition` and `edit_condition`
can refer to the values of submission fields present earlier on the form.

The `required` property controls whether a field must be present before
registration (`"register"`) or submission/ready-for-review (`"submit"`).

### Type-specific properties

The `"dropdown"`, `"radio"`, and `"checkboxes"` types require a list of choices
in the `values` property.

The `"authors"`, `"topics"`, and `"checkboxes"` types support `min` and `max`
properties, which restrict the number of choices that may be selected. For
example, if the `"authors"` field has `"min": 3` and `"max": 6`, then each
submission must have at least 3 authors, but no more than 6.

The `"text"` and `"mtext"` types accept arbitrary text. `"text"` presents a
single-line text entry, while `"mtext"` presents a multi-line field. The
`wordlimit` and `hard_wordlimit` properties set soft and hard word limits for
these fields.

The `"numeric"` and `"realnumber"` types accept numbers (integers and real
numbers, respectively). `min_value` and `max_value` set the minimum and maximum
acceptable values, and `precision` sets the number of decimal places saved for
`"realnumber"` fields.

The `"author_certification"` type requires author certifications. Its
`"max_submissions"` property defines the maximum number of submissions that each
author may certify.
