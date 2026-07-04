# HotCRP settings

Conference configuration is edited through the Settings pages (`/settings`) and
the settings API (`/api/settings`, which speaks JSON). This subsystem is driven
by two [component][components] configuration files plus a set of PHP classes:

* `etc/settinginfo.json` defines the individual settings—their names, types, and
  storage (often the database `Settings` table, but not always). Each
  definition becomes an `Si` object (`src/si.php`), looked up via
  `$conf->si($name)` or `$conf->si_set()` (`SettingInfoSet`,
  `src/settinginfoset.php`).
* `etc/settinggroups.json` defines the Settings pages—the topic groups shown
  in the left menu and the rendering functions for each section.
* `SettingValues` (`src/settingvalues.php`) manages a settings request. It holds
  old (saved) and new (requested) values, parses and validates changes, and
  saves them.
* `SettingParser` (`src/settingparser.php`) is the base class for per-setting
  parsing and saving logic. Subclasses live in `src/settings/s_*.php`.
* `Sitype` (`src/sitype.php`) implements the primitive setting types
  (checkbox, date, string, …).

Both JSON files use the standard [component expansion and merge
rules][components]; a conference can add or override entries with
`$Opt["settingInfo"]` and `$Opt["settingGroups"]`.


## Setting definitions

`etc/settinginfo.json` holds an array of setting definitions. A minimal
example:

```json
{
    "name": "preference_shuffle", "storage": "pref_shuffle", "type": "checkbox",
    "title": "Shuffle submissions on review preferences page",
    "tags": "revpref"
}
```

Each setting definition gives an **export name** for the setting (here,
`preference_shuffle`); specifies how the setting is **stored** in the database
(here, as the value of the `Settings` row with `name='pref_shuffle'`); and
defines its **type**, which determines what values are allowed (here,
`checkbox`, a boolean setting).

Export names are like `submission_done` or `review_open`: lowercase words
separated by underscores, generally noun first so related settings sort
together.

Structured settings use multiple setting definitions, linked by slash-separated
hierarchical names and name patterns. An example (decisions):

```json
{
    "name": "decision", "type": "oblist", "parser_class": "Decision_SettingParser",
    "title": "Decision types", "tags": "decision"
},
{
    "name_pattern": "decision/$", "type": "object", "subtype": "decision",
    "parser_class": "Decision_SettingParser"
},
{
    "name_pattern": "decision/$/id", "type": "int",
    "internal": true, "json": true, "id_member": true
},
{
    "name_pattern": "decision/$/name", "type": "simplestring", "size": 30,
    "required": true, "placeholder": "Decision name", "title": "Decision name"
},
{
    "name_pattern": "decision/$/category", "type": "radio",
    "values": ["accept", "reject", "desk_reject", "maybe"],
    "title": "Decision category"
}
```

In a `name_pattern`, `$` placeholders (`$` the first, `$$` the second, and so
forth) mark extensible settings. For example, `decision/$/name` defines the
`name` member of every object in a `decision` object list. Structured settings
handle both naturally-structured JSON and flat Web forms. For instance, this
`decision` JSON setting:

```json
{ "decision": [
    { "id": -1, "name": "Rejected", "category": "reject" },
    { "id": 2, "name": "Awesome", "category": "accept" }
] }
```

would correspond to these name/value pairs:

```
decision/1/id        -1
decision/1/name      Rejected
decision/1/category  reject
decision/2/id        2
decision/2/name      Awesome
decision/2/category  accept
```

`SettingValues` converts JSON input into name/value pairs like these up front,
so both encodings share one parsing path.

In object lists, counters (the `1` and `2` above) identify slots in the current
request, not persistent identities. Identity is provided by `id` members; edits
match request objects to existing objects by `id` (and sometimes by name).


### Properties

| Property        | Meaning |
|:----------------|:--------|
| `name`, `name_pattern` | Setting name (see above) |
| `type`, `subtype` | Value type (see below) |
| `values`        | For `radio` types, the list of allowed values, or `"auto"` (ask the parser) |
| `json_values`   | Parallel list of names used for `values` in the JSON API |
| `default_value` | Value corresponding to absent storage (see [Storage](#storage)); `"auto"` asks the parser |
| `initial_value` | Value a fresh installation starts with, when that differs from `default_value` (see [Storage](#storage)) |
| `json`          | JSON API exposure: `true`/`"inout"` (default), `"in"`, `"out"`, or `false` |
| `storage`       | Where the value is stored (see below) |
| `title`, `title_pattern` | Human-readable title, used in error messages and labels |
| `required`      | Whether an empty value is an error |
| `size`, `placeholder`, `autogrow`, `spellcheck` | Form control rendering hints (`placeholder` may be `"auto"`) |
| `parser_class`  | Name of a `SettingParser` subclass with custom logic |
| `parse_order`   | Numeric order in which requests are parsed (default 0; ties broken by source order) |
| `internal`      | True for settings not directly user-editable; skipped by parsing and (by default) by the JSON API |
| `id_member`     | Marks an object member used as its identity |
| `hashid`        | `#anchor` used to link to the setting on its Settings page; `false` for none; defaults to the setting name |
| `tags`, `member_tags` | Space-separated filter tags matched by the API `filter` parameter (e.g. `filter=#deadline`); `member_tags` apply to an object list’s members |
| `configurable`  | `false` disables editing entirely |
| `alias`, `alias_pattern` | This name is an alias for another setting |
| `merge`, `priority`, `allow_if` | Standard [component][components] properties |


### Types

`type` names a `Sitype` (`src/sitype.php`), which defines how request
strings and JSON values are parsed and unparsed. The primitive types:

| Type           | Values |
|:---------------|:-------|
| `checkbox`     | 0/1; JSON boolean |
| `radio`        | one of `values`; JSON uses `json_values` names |
| `cdate`        | checkbox that records when it was first checked |
| `date`         | timestamp; parses “15 Dec 2024 AoE”; special values for “none” |
| `grace`        | grace period in seconds; parses “15 min” |
| `int`, `nonnegint`, `float` | numbers |
| `string`, `simplestring`, `longstring` | text (`simplestring` simplifies whitespace) |
| `htmlstring`   | text validated as HTML |
| `email`, `emailheader`, `url` | validated text |
| `tag`, `taglist`, `tagselect` | tag names (subtypes select variants, e.g. `allow_wildcard`) |

Some types use `subtype` for variants; for example, `"type": "string",
"subtype": "search"` marks a string containing a search query.

Two meta-types define structured settings, `object` and `oblist`. An `object`
type is a group of member settings, represented as an object in JSON and as a
PHP object in `SettingValues`. Structured settings have no direct storage, so
each `object` setting must have a `parser_class` to load and save it. Object
`subtype`s are used for documentation. An `oblist` is a list of objects.

### Storage

By default, a setting is stored in the `Settings` table row named by
`storage` (or by the setting name if `storage` is absent). The `Settings`
table has an integer `value` column and a string `data` column; the setting
type determines which is used (most types use `value`, string types use
`data`). A `storage` prefix modifies this:

| Storage       | Meaning |
|:--------------|:--------|
| `val.NAME`    | The `value` column of row `NAME`, which other settings may share |
| `dat.NAME`    | The `data` column of row `NAME` |
| `negval.NAME` | The `value` column of row `NAME`, logically negated (setting is 1 when stored value is 0) |
| `opt.NAME`    | Conference option `$Opt["NAME"]`, stored as string data in Settings row `opt.NAME`, which overrides `conf/options.php` |
| `ova.NAME`    | Like `opt.NAME`, but stored as an integer value |
| `msg.NAME`    | A message override; the default value is the default translation of `NAME` |
| `member.NAME` | Property `NAME` of the enclosing object setting |
| `none`        | No automatic storage; a parser must handle saving |

For example, `review_visibility_author` (`val.au_seerev`) and
`review_visibility_author_condition` (`dat.au_seerev`) share the single
`au_seerev` settings row. Members of object settings default to
`member.LASTCOMPONENT`, so `decision/$/name` needs no explicit storage.

#### Default and initial values

Storage is sparse: a setting whose value is “empty” is stored by deleting
its `Settings` row (or option override), and absent storage reads back as the
empty value. A value counts as empty when it equals the setting’s
`default_value`, or when its type deems it empty—0 for checkboxes and dates,
`""` for strings (for `msg.` storage, the message’s default translation
counts as empty). Set `default_value` when the empty value isn’t the type’s
natural one; for example, `preference_min` sets `"default_value": -1000000`
so the common case stores nothing. Whatever interprets the setting elsewhere
in the code must treat a missing row the same way.

`initial_value` records the value a *newly created* conference starts with
when that differs from `default_value`. It does not affect parsing or
saving—fresh installations get such values because `src/schema.sql` inserts
explicit `Settings` rows for them (e.g. `review_self_assign`, stored as
`pcrev_any`, starts at 1 although absent storage means 0)—so `initial_value`
must be kept in sync with `schema.sql`. It is reported as the setting’s
default by `/api/settingdescriptions`.

### Titles

`title` is a plain-text name for the setting. A `title_pattern` may
interpolate name segments (`$`, `$$`) and expressions: `${sv NAME}` inserts
another setting’s displayed value (its `vstr`), and `${uc TEXT}` upper-cases
the first letter. Example: `"title_pattern": "‘${sv sf/$/title}’"` names each
submission field setting after the field itself.

A member title beginning with `/` is appended to the parent object’s title:
given `review/1/title` “Round One”, the member title `/Deadline` renders as
“Round One deadline”.

### Descriptions

Long-form documentation for settings—used by `/api/settingdescriptions` and
the `hotcrapi` CLI—lives in `etc/settingdescriptions.md` (extensible via
`$Opt["settingDescriptions"]`). Each `# setting_name` section provides a
summary (a `>` blockquote) and a Markdown description.

## Setting groups

`etc/settinggroups.json` defines the Settings *pages*. It is a normal
[component][components] group set: top-level entries are pages (`basics`,
`submissions`, `reviews`, …) and nested entries are page sections.

```json
{
    "name": "submissions", "order": 300, "title": "Submissions",
    "description": "<0>Submission deadlines and anonymity."
},
{
    "name": "submissions/deadlines", "order": 20,
    "separator_before": true,
    "print_function": "Submissions_SettingParser::print_deadlines",
    "settings": ["submission_registration", "submission_done", "submission_grace"]
}
```

Useful properties beyond the standard component ones:

| Property | Meaning |
|:---------|:--------|
| `title`, `short_title` | Section heading; `short_title` used in the left menu |
| `description` | Shown in the settings index ([Ftext][fmt] format) |
| `print_function` | Function that renders the section, called as `f(SettingValues $sv, $gj)`. A leading `*` (e.g. `"*Options_SettingParser::print"`) makes it an instance method on a per-request cached object |
| `settings` | List of setting names rendered by this section |
| `print_members` | Render member components after (or instead of) `print_function` |
| `inputs` | `false` marks a section with no form inputs (no Save button needed just for it) |
| `separator_before` | Print a horizontal rule before the section |
| `hashid` | `#anchor` for linking to the section |
| `alias` | Alternate name for a page (e.g. `sub` → `submissions`) |

An entry can also be an array shorthand, `[name, order, print_function]`.

The `settings` lists do double duty: they assign each setting to its
page(s). That assignment determines where error messages link and which
settings a form submission is “interested in” (`SettingValues::has_interest`).

Entries named `__crosscheck/*` define `crosscheck_function`s instead of
renderers. Crosschecks run when a Settings page is displayed without a
pending request (`SettingValues::crosscheck()`); they report warnings about
suspicious combinations of saved settings (e.g. a deadline after another
deadline). Since crosschecks examine saved values, they use `$sv->oldv()`.

## SettingValues

A `SettingValues` object (a `MessageSet` subclass) manages one settings
request—both rendering a settings form and applying an update. The overall
lifecycle:

```php
$sv = SettingValues::make_request($user, $qreq); // or (new SettingValues($user))->add_json_string(...)
if ($sv->execute()) {           // parse, validate, and save
    $changes = $sv->saved_keys();
}
$sv->report();                  // or $sv->all_jsonv() for the JSON API
```

### Value spaces

A setting’s value exists in three forms, and `SettingValues` has accessors
for each:

* **Request strings** (`$sv->reqstr($name)`, `$sv->has_req($name)`): the raw
  strings submitted with the request, stored in `$sv->req`. Form input names
  equal setting names. JSON API input is converted to request strings up
  front (`add_json_string`), so parsing has a single code path.
* **Old values** (`$sv->oldv($id)`): the value as saved, before this request
  is applied—an int or string for primitive settings, an object for `object`
  settings. Loaded from storage or, for complex settings, from the parser’s
  `set_oldv`.
* **New values** (`$sv->newv($id)`): the value the request would save,
  falling back to `oldv` where the request makes no change.

`$sv->vstr($id)` returns the *display* string for a form control: the
request string if this request contains one, otherwise the unparsed old
value. Rendering code uses `vstr` so that a form redisplayed after an error
shows what the user typed.

### Request conventions

* An unchecked checkbox does not appear in a form submission, so forms also
  include a hidden `has_NAME=1` input. `has_NAME` registers `NAME` in the
  request without a value; a registered-but-absent value parses as the
  type’s “empty” value (0 for checkboxes).
* Object list members use counters: `decision/1/name`, `decision/2/name`, …
  Each slot has an `id` member matching it to an existing object (empty or
  `new` for new objects).
* `PFX/N/delete=1` requests deletion of an object.
* `reset=1` (or `PFX_reset=1`) means the request is a complete description
  of an object list: existing objects not mentioned are deleted. Without
  `reset`, unmentioned objects are left alone (the JSON API way to update
  one review round without listing all of them).

### Parsing and saving

`$sv->execute()` runs the whole update pipeline; `$sv->parse()` runs just
the parse/validate part (`execute` calls it if needed).

1. **Parse.** Each requested top-level setting is applied in `parse_order`
   (`apply_req`). If the setting has a `parser_class`, its `apply_req` hook
   runs; otherwise (or if the hook returns `false`) the value is parsed by
   its `Sitype` and saved with `$sv->save($si, $value)`. `save` collects
   pending database writes in memory; nothing touches the database yet.
   Parsers report problems with `$sv->error_at($name, $msg)` and friends,
   which attach messages to specific fields (or JSON paths).
2. **Validate.** Parsers can call `$sv->request_validate($si)` to schedule a
   `validate` hook. Validation runs after all parsing, with pending values
   temporarily installed in `$conf`, so checks can use normal conference
   APIs against the *new* state. (`$sv->make_svconf()` offers a lighter
   overlay for reading pending values during parsing.)
3. **Save.** If any error was recorded, `execute` stops and returns `false`.
   Otherwise it locks `Settings` (plus tables requested via
   `request_read_lock`/`request_write_lock`), runs `store_value` hooks
   scheduled by `request_store_value` (for side effects like updating the
   `Paper` table), writes exactly the settings whose values differ from
   the database, unlocks, logs the change, and reloads `$conf`. Cleanup
   functions (`register_cleanup_function`) and cache invalidations
   (`mark_invalidate_caches`) run after the save.

### Rendering

Rendering the Settings page walks the `settinggroups.json` components
(`$sv->cs()`), calling each section’s `print_function`. `SettingValues`
provides form helpers that automatically wire up ids, displayed values
(`vstr`), old values (for change detection), feedback messages, and
read-only state for unauthorized viewers:

```php
$sv->print_checkbox("preference_shuffle", "Shuffle submissions…");
$sv->print_entry_group("submission_done", "Submission deadline", ["horizontal" => true]);
$sv->print_radio_table("review_blind", [...]);
```

Lower-level pieces include `$sv->entry`, `$sv->select`, `$sv->textarea`,
`$sv->feedback_at($name)` (per-field messages), `$sv->label`, and
`$sv->sjs($name, $attrs)` (attribute sets for hand-rolled controls). Links
to settings and groups use `$sv->setting_link` and `$sv->setting_group_link`.

`$sv->editable($id)` says whether the requesting user may edit a setting;
`$sv->has_interest($id)` says whether a setting belongs to the page being
processed (settings without interest are displayed but not treated as part
of the form’s defaults).

## SettingParser

A `SettingParser` subclass implements everything about a setting that the
declarative JSON can’t express. `Si::parser_class` names the subclass; one
instance per class is cached per request (`$sv->si_parser($si)`). The hooks,
all optional:

| Hook | When it runs |
|:-----|:-------------|
| `set_oldv(Si, SV)` | Supply the old value for a setting—required for `object` settings (construct and fill the settings object, then `$sv->set_oldv($si, $obj)`), useful for any computed value |
| `prepare_oblist(Si, SV)` | Populate an `oblist`’s slots from the saved configuration by calling `$sv->append_oblist($pfx, $objects, $namekey)`, which matches request slots to existing objects by id/name |
| `values(Si, SV)`, `json_values(Si, SV)`, `placeholder(Si, SV)`, `default_value(Si, SV)` | Resolve `"auto"` properties from `settinginfo.json` |
| `member_list(Si, SV)` | Override the set of members exported for an `object` setting |
| `apply_req(Si, SV)` | Parse this setting’s request value(s). Return `true` if handled; `false` falls through to default parsing. Called once per setting name that has both a request entry and this parser class |
| `validate(Si, SV)` | Scheduled by `$sv->request_validate($si)`; runs after parsing with pending values visible through `$conf` |
| `store_value(Si, SV)` | Scheduled by `$sv->request_store_value($si)`; runs during the locked save for database side effects beyond the `Settings` table |

A typical complex parser (see `Decision_SettingParser`,
`src/settings/s_decision.php`, for a compact example) fits together like
this:

1. `prepare_oblist` maps existing decisions into request slots
   (`decision/1`, `decision/2`, …).
2. `set_oldv` supplies a fresh `Decision_Setting` object for each slot.
3. `apply_req` on the list setting (`decision`) iterates
   `$sv->oblist_keys("decision")`, reads each parsed object with
   `$sv->newv("decision/{$ctr}")` (which parses the members), checks for
   duplicates and other errors, and—if the result differs—saves the
   composite value and calls `request_store_value` and
   `request_write_lock("Paper")`.
4. `store_value` applies side effects, e.g. clearing decisions of deleted
   types from the `Paper` table.

Members with simple types (like `decision/$/name`) need no parser code at
all; `object_newv` parses them into the settings object automatically, via
each member’s `Sitype` and `member.` storage.

Helpers commonly used inside parsers: `$sv->oblist_keys($pfx)` /
`oblist_nondeleted_keys($pfx)` (slot counters), `$sv->error_if_missing`,
`$sv->error_if_duplicate_member`, `$sv->error_if_match_ambiguous`,
`$sv->update($id, $value)` (save only if changed), and `$sv->check_date_before`.

## Adding a setting: checklist

1. Add a definition to `etc/settinginfo.json` (name, type, storage, title,
   tags). For a brand-new `Settings` row, no schema change is needed.
2. If the setting appears on a Settings page, add it to the appropriate
   group’s `settings` list in `etc/settinggroups.json` and render it in
   that group’s `print_function`.
3. If it needs custom parsing, storage, or crosschecks, add or extend a
   `SettingParser` in `src/settings/`.
4. If it should ship with a non-default value on new installations, add it
   to the `insert into Settings` statement in `src/schema.sql` and set
   `initial_value`.
5. Test via `test/t_settings.php` patterns: construct a `SettingValues`
   with `SettingValues::make_request($user, [...])` or `add_json_string`,
   then check `execute()` results and `$conf` state.

[components]: ./components.md
[fmt]: ./fmt.md
