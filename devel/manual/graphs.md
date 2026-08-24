# Formula graphs

The `/graph` page draws graphs of formula and search results. It is one of
HotCRP’s most capable analysis features and one of its least discovered ones,
because until recently the only way in was to type a formula into a text box.

## Architecture

* `FormulaGraph` (`src/formulagraph.php`) is the engine. Its constructor takes
  a graph type, an X expression, and a Y expression; `add_dataset()` adds a
  search; `graph_json()` returns everything the front end needs. Graph types
  are the bitmask constants `SCATTER`, `CDF`, `BARCHART`, `FBARCHART`,
  `BOXPLOT`, `DOT`, `NUMDOT`, and `OGIVE`, and `type_json()` maps them to the
  strings the JavaScript uses (`scatter`, `cdf`, `bar`, `fraction`, `box`,
  `dot`, `numdot`, `cumfreq`).
* `Graph_Formula_Page` (`src/pages/p_graph_formula.php`) renders the page and
  its form. `Graph_Page` (`src/pages/p_graph.php`) renders the tab bar shared
  with `/graph/procrastination`.
* `GraphData_API` (`src/api/api_graphdata.php`) exposes the same computation at
  `/api/graphdata`, taking the same parameters the page form submits.
* `scripts/graph.js` draws. `hotcrp.graph(selector, args)` takes a
  `graph_json()` object and renders it with d3.

### Axes

`scripts/graph.js` draws axes itself; `d3.axis` is not used (`d3.scale` is).
An axis class supplies `tick_values(scale)`, `value_format(v)`, and optionally
`tick_decoration(v)` for per-tick classes, fill, and background. Everything
generic is in two functions: `layout_axis` chooses, measures, thins, and
shortens the ticks for a given along-axis length and returns the breadth they
need across; `draw_axis` emits that layout without measuring anything.

`make_axis_pair` settles the two margins against each other — a wider left
margin narrows the plot, thinning the X ticks, which can shorten the bottom
margin, which lengthens the plot, which un-thins the Y ticks. It iterates to a
fixed point; the caps bound it, and when the box may grow there is no cycle.

### Sizing

Margins and width are **computed, not supplied**: the axis label placement in
`draw_axes` is written against them, so a caller-supplied number can only be
wrong. Width comes from the element. `height` is a caller argument; it
defaults to 540.

What a caller controls is `expandable`, which names the dimensions of the box
that may grow to fit tick labels. Note the crossover — the *X* axis's labels
hang below the axis and claim height, the *Y* axis's run to its left and claim
width:

| `expandable` | Meaning |
| --- | --- |
| `"height"` (default) | the box may grow taller to fit tilted X tick labels |
| `false` | the box is fixed; labels come out of the plot, then truncate |
| `"width"`, `true` | not implemented; treated as `false` and `"height"` |

Margins are measured, not estimated: `measure_max_text_width` renders every
tick string into scratch nodes and reads `getComputedTextLength`, sizing the
margins from real widths. The nodes are all created before any is measured, so
the set costs one layout flush rather than one per string, and they are hidden
so nothing paints.

`MAX_LABEL_WIDTH` is a hard pixel ceiling on tick label width that applies
whether or not the dimension can grow, so one pathological label can't set the
size for everything. It is compared against the width at the size the label
actually renders — after any `widelabel` shrink, which is materially narrower
than the base font.

A dimension that can't grow gets a margin capped at
`MAX_X_LABEL_HEIGHT_FRACTION` / `MAX_Y_LABEL_WIDTH_FRACTION` of the box, and
tick labels over the cap are truncated with an ellipsis, keeping the full text
in a `<title>`. Width is never expandable today, so the Y cap always applies —
which is what stops one long tag or reviewer name from eating the plot.

The wizard preview passes `expandable: false`, since it lives in a fixed panel.

See `devel/graph-render-plan.md` for what this API is growing into.

### Request parameters

| Parameter | Meaning |
| --- | --- |
| `gtype` | graph type; if absent, taken from a prefix on `y` |
| `x` | X expression: a formula, `search`, `tag`, `sort <formula>`, or—for CDFs—several formulas separated by `;` |
| `y` | Y expression: a formula, optionally prefixed with a graph type |
| `xorder` | optional formula that orders the X axis |
| `q1`, `s1`, `q2`, `s2`, … | series: a search and a style |
| `t` | search collection applied to every data set |

Either axis may carry a `paper` or `review` prefix. The prefix is an
**assertion** about the expression’s data level, not a transformation: `review
X` requires `X` to be review-indexed and `paper X` requires it not to be.
Asserting `review` on *both* axes of a scatter plot additionally forces
per-submission combining, so the Y expression must then be an aggregate. This
is why the wizard only ever emits a prefix on X, and only when it can prove the
assertion holds.

## Discovery features

Two things make the page usable without knowing the formula language.

**The examples gallery** is a list of ready-made graphs under the form.
`FormulaGraphCatalog::examples()` (`src/formulagraphcatalog.php`) builds
candidates out of the conference’s own review fields and then drops any whose
formulas don’t work here, so every link on the page is guaranteed to draw
something.

**The graphing wizard** is a three-panel dialog, built in `scripts/graph.js`
(`graph_wizard()`), opened by the `js-graph-wizard` button:

1. *Graph type* — a grid of tiles, each with a schematic SVG of the graph shape.
2. *Axes* — a “data points” selector (automatic / per submission / per review)
   plus, for each axis, a quantity menu, a summary menu (average, median,
   count, …), and the formula they compose. The formula entry stays
   authoritative: type into it and the menus resync from what you wrote.
3. *Series* — search-and-style rows.
4. *Advanced* — the X axis order formula.

A live preview beside the panels re-fetches `/api/graphdata` on every change,
so the user sees their real data while choosing. Field-level errors from the
server are placed next to the input that caused them—the wizard names its axis
inputs `x` and `y` to match `FormulaGraph`’s message fields—and the whole
message list is repeated beside the preview, where it is visible from any panel.

The preview samples its data. `graph_dot` runs a d3 force simulation over every
mark, so a large conference makes the preview slow to draw and dense enough
that the dots spill outside the plot area; `preview_sample()` thins
`style_xyi` data (scatter, dot, ldot, box) to `PREVIEW_MARK_LIMIT` marks by
taking a fixed stride, and the preview says how many marks it kept. `xyis`
data (bar, fraction) is already aggregated per X value, and a `cdf`’s Y axis
may be a raw count, so neither is sampled.

`FormulaGraphCatalog::quantity_groups()` supplies the axis menus. Every
quantity is validated with `Formula::make_indexed()` before it is offered, so
the menus never contain something this conference can’t graph; each entry
records whether it is review-indexed, which is what decides whether the summary
menu applies.

## Proposed: a saved-graph library

The wizard makes a graph easy to *build*. It does not make one easy to *keep*.
Conferences re-draw the same handful of graphs constantly, and today the only
way to save one is to bookmark its URL.

### Data model

A named graph is exactly the request parameters above plus a name:

```json
{
    "name": "Score spread",
    "description": "Submissions ordered by average score",
    "gtype": "box",
    "x": "sort avg(OveMer)",
    "y": "OveMer",
    "datasets": [{"q": "", "style": "default"}],
    "owner": "chair"
}
```

### Storage

Follow named searches, not named formulas. Named formulas live in their own
`Formula` table; named searches used to occupy one `Settings` row apiece and
were consolidated into a single JSON `named_searches` setting by schema
update v277 (`src/updateschema.php`). The consolidated form is the better
precedent — no schema change, and the whole set loads with the other settings.

So: a `named_graphs` setting holding a JSON array, read through a
`Conf::named_graphs()` accessor that mirrors `Conf::named_searches()`
(`src/conference.php`).

Adopt the named-search name conventions wholesale, since PC members will expect
them: a bare name is shared, `~name` is private to its owner (stored prefixed
with the owner’s `contactId`), and `~~name` is chair-only. `Contact::viewable_named_searches()`
already implements exactly this filtering and is worth copying closely.

### Code

| Piece | Model it on |
| --- | --- |
| `src/settings/s_namedgraph.php` — `NamedGraph_Setting`, `NamedGraph_SettingParser` | `s_namedsearch.php` |
| `etc/settinginfo.json` — a `named_graph` oblist plus the internal `named_graphs` string | the `named_search` entries |
| `src/api/api_graphconfig.php` — GET to list, POST to save/delete | `SearchConfig_API::namedsearch` / `save_namedsearch` |
| A settings page group under Search & tags | the named-search settings group |

### UI

The wizard is the natural home. Add a fourth action to its button row —
*Save as…* — and a “Saved graphs” list at the top of the first panel, above the
type tiles, whose entries load their parameters into the wizard. Rendering
saved graphs on the page above the examples gallery gives them the same
one-click access the examples now have.

Worth doing at the same time: a `graph:` link from a saved graph to the
equivalent search, and a saved-graph entry on the home page next to the
existing named-search list (`src/pages/p_home.php` already renders one).

### Recent graphs

Cheaper and complementary. Every successful graph request is a small JSON
object; keeping the last ~10 per user in the session (`devel/manual/sessions.md`)
gives a “recent graphs” list with no schema, no permissions questions, and no
settings UI. It is the right thing to build first: it costs almost nothing, and
the usage it produces tells you which graphs are worth naming.

### Open questions

* **Formula references.** A saved graph that mentions `OveMer` breaks if the
  review field is renamed. Named formulas have the same problem today, so
  matching their behavior — break visibly, with the field name in the error —
  is defensible. Storing review-field UIDs instead would be more robust and
  less legible.
* **Sharing vs. clutter.** Shared named searches are chair-managed. If any PC
  member can save a shared graph, the list will grow without bound; scoping
  saves to `~name` by default, with an explicit promotion to shared, avoids
  this.
