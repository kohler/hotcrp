# tag_style

> Configures relationships between tags and display colors and styles

Submissions tagged with one of the `tags` listed for `style` will appear in
that style in submission lists and on submission pages.

Built-in styles include
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

Extensible `style`s include:

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

Submissions with multiple styled tags will appear with
<span class="taghh tagbg tag-red tag-orange tag-underline">combined styles</span>.

