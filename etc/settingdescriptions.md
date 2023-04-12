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
a <span class="badge">#badge</span>.

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

You can also make your own badge colors. Extended styles include:

* `rgb-RRGGBB`: Color the tag with `#RRGGBB`, an HTML hex color.


# automatic_tag

> Configures tags set automatically by the system

The `tag` will be set automatically on all papers matching `search` (and
removed from papers not matching `search`).

The optional `value` is a formula that sets the tag’s value. For example, the
formula “avg(OveMer)” would set the tag’s value to the relevant paper’s
average overall merit score. If the formula evaluates to null for a paper,
then the tag for that paper is removed. If `value` is an empty string, 0 is
used.
