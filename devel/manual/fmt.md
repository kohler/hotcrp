# Message formatting in HotCRP

## Markup types

HotCRP messages can use several markup languages, each identified by a
nonnegative integer. The languages defined so far are:

| Markup ID | Description                       |
|-----------|:----------------------------------|
|     0     | Plain text                        |
|     1     | Markdown (no HTML allowed)        |
|     3     | Markdown (HTML allowed)           |
|     5     | HTML                              |

The `Ftext` class can convert between some formats.

(Note that open-source HotCRP ships without Markdown support.)


## Ftext

An **ftext**, short for “formatted text,” is a string that includes its markup
ID as a prefix. Ftexts are used for many HotCRP messages, and some HotCRP
subsystems, such as error messages, require ftexts.

An ftext is written `<MARKUPTYPE>STRING`, where `MARKUPTYPE` is a non-negative
integer. The most common `FORMAT`s are `0` (plain text) and `5` (HTML). For
example, this ftext is the string “`Fortnum & Mason`” in plain text:

```
<0>Fortnum & Mason
```


## Translation overview

HotCRP renders messages using a JSON **translation database**. Translations
can change message text based on context, database settings, and arguments,
and could be used for internationalization.

A translation request comprises a **string**, an optional **context** (a
slash-separated string), and optional **arguments**, which can be named or
positional. The arguments can help determine the chosen translation string,
and can be interpolated into the translation result as **replacement fields**.

Here is an example translation request:

```php
$conf->_("Hello, {names:list}!", new FmtArg("names", ["Alice", "Joan"]));
```

In this request, the string is `Hello, {names:list}`; the context is empty
(the `_` translation function assumes an empty context); and the single
argument is the list `["Alice", "Joan"]`.

In the absence of a translation database, this request will resolve to:

```
Hello, Alice and Joan!
```

with the `names` argument interpolated as a list.

Related requests would resolve as follows:

```php
$conf->_("Hello, {names:list}!", new FmtArg("names", ["Gesine"]))
    === "Hello, Gesine!";
$conf->_("Hello, {names:list}!", new FmtArg("names", []))
    === "Hello, !";
$conf->_("Hello, {names:list}!", new FmtArg("names", range(1, 15)))
    === "Hello, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, and 15!";
```

A translation database can set up substitute texts for particular arguments or
database settings. For example, this database defines special-case messages
when there are no names, or more than five:

```json
[
    {"in": "Hello, {names:list}!", "out": "Hello!", "require": ["!#{names}"]},
    {"in": "Hello, {names:list}!", "out": "Hello, all!", "require": ["#{names}>5"]}
]
```

With that database, our requests would resolve as:


```php
$conf->_("Hello, {names:list}!", new FmtArg("names", ["Alice", "Joan"]))
    === "Hello, Alice and Joan!";    // using default translation
$conf->_("Hello, {names:list}!", new FmtArg("names", ["Gesine"]))
    === "Hello, Gesine!";            // using default translation
$conf->_("Hello, {names:list}!", new FmtArg("names", []))
    === "Hello!";                    // using first database record
$conf->_("Hello, {names:list}!", new FmtArg("names", range(1, 15)))
    === "Hello, all!";               // using second database record
```

## Translation records

A translation record is an object with these properties:

* `in`: The input string.
* `out`: The output string (i.e., the translation). If not specified, it
  defaults to the value of `in`.
* `context`: (Optional) The record’s context.
* `require`: (Optional) A list of requirements that must hold for the
  translation to match. A requirement is specified as a string with format
  defined below.
* `priority`: (Optional) The priority of this translation. Priority defaults
  to 0, except that messages in the default `etc/msgs.json` database have
  default priority -1.
* `template`: (Optional) If `true`, indicates that this translation should be
  made accessible as a replacement field from other translations.
* `expand`: (Optional) Determines how replacement fields are interpolated into
  this translation. Defaults to `"full"`, which expands arguments and
  templates. Other possibilities are `"template"`, which only expands
  templates, and `"none"`, which uses the translation verbatim.

A translation database is simply a JSON array of translation objects.

Define translations more parsimoniously using shorthand:

* A translation’s `m` property can define an array of **child** translation
  records. Each child record inherits properties from its parent by default.

* A translation without `template` or `expand` properties can be defined using
  array shorthand. Specifically:

    | Object definition                                   | Array shorthand          |
    |:----------------------------------------------------|:-------------------------|
    | `{"in": "IN", "out": "OUT"}`                        | `["IN", "OUT"]`          |
    | `{"context": "CTX", "in": "IN", "out": "OUT"}`      | `["CTX", "IN", "OUT"]`   |
    | `{"in": "IN", "out": "OUT", "priority": 2}`         | `["IN", "OUT", 2]`       |
    | `{"in": "IN", "out": "OUT", "require": ["REQ"]}`    | `["IN", "OUT", ["REQ"]]` |
    | `{"in": "STR", "out": "STR"}`                       | `["STR"]`                |

    (The `priority` and `require` properties can occur anywhere in the array,
    not just at the end.)

* An identity translation with no requirements and default priority can be
  written as just the input string.

For example, this set of related translations:

```json
[
    {"in": "Hello, {names:list}!", "out": "Hello, {names:list}!"},
    {"in": "Hello, {names:list}!", "out": "Hello!", "require": ["!#{names}"]},
    {"in": "Hello, {names:list}!", "out": "Hello, all!", "require": ["#{names}>5"]}
    {"in": "Hello, {names:list}!", "out": "Boujour mes enfants !", "require": "lang=fr", "priority": 1}
]
```

can be expressed more concisely as

```json
[
    {"in": "Hello, {names:list}!", "m": [
        "Hello, {names:list}!",
        ["Hello!", ["!#{names}"]],
        ["Hello, all!", ["#{names}>5"]],
        ["Bonjour mes enfants !", 1, ["lang=fr"]]
    ]}
]
```

(Note that the child records need not define `in`, since since it is inherited
from the parent.)

There are some restrictions on nested translations. A nested translation’s
`context` must be more specific than its parent’s, and when a parent
translation defines an input string, its children translations must use the
same input string.


## Translation search

To find the best translation for a request:

1. HotCRP first scans the database for records with matching string, context,
   and requirements.

    A translation record’s *input string* matches if it is
    character-for-character identical with the requested string.

    *Contexts* can distinguish strings that might be translated differently
    based on where in the UI they appear. A context is a slash-separated
    string. A record’s context matches if it is a prefix of the requested
    context. For example, the context `"paper"` would match requested contexts
    `"paper"` and `"paper/edit"`, but not `"paperedit"` (because components
    between slashes must match exactly). A translation with empty context
    matches all requested contexts.

    A record’s *requirements* match if each of them evaluates to true.

2. Of the matching translation records, HotCRP selects the ones with the
   maximum *priority* (a number that allows translations to override one
   another regardless of context or requirements).

3. Of the remaining, HotCRP selects the records with the maximum *context
   length*.

4. Of the remaining, HotCRP selects the records with the maximum *number of
   requirements* (so a translation with more requirements will beat a
   translation with fewer).

5. Finally, HotCRP selects the record that was defined last.

The search yields the resulting record’s output string, if any records
matched, or a copy of the input string, if none matched.


## Requirement minilanguage

Requirements can check arguments or certain configuration properties and can
perform simple comparisons. A requirement should have one of these formats:

* `V`: Check whether value `V` is truthy (not null, empty array, or empty
  string).
* `!V`: Check whether `V` is falsy.
* `V=CV`: Check whether two scalar values are equal, considered as strings.
* `V!=CV`: Check whether two scalar values are not equal, considered as
  strings.
* `V<CV`, `V>CV`, `V<=CV`, `V>=CV`: Compare numeric values.
* `V^=CV`: Check whether string `V` is a prefix of `CV`.
* `V!^=CV`: Check whether string `V` is not a prefix of `CV`.

The first value `V` can be:

* A parameter definition enclosed in braces, such as `{value}`.
* An array count, such as `#{names}`. This evaluates to the number of elements
  in array parameter `{names}`.
* A database setting, such as `setting.sub_blind`.
* A configuration option, such as `opt.sendEmail`.
* `lang`, which expands to a language code.

The second, comparand value `CV` can be:

* A parameter definition enclosed in braces.
* An array count.
* A literal string.


## Expansion

By default, HotCRP interpolates replacement fields into translated strings.
Interpolated fields are specified using curly braces `{}`, as in Python `fmt`
or C++ `std::format`. To include a literal curly brace, especially if it would
otherwise be mistaken for a replacement field, double it: `{{` is interpolated
as `{`.

A replacement field consists of an optional argument ID, optionally followed
by colon and a **format specification** defining how the replacement should be
parsed.

An argument ID can be a nonnegative number, which specifies a positional
argument starting from 0, or a name. Fields with missing argument IDs are
assigned the positional arguments in order. A string should not use both
numeric argument IDs and missing argument IDs; don’t say, for example, `The
{0} score is {}`.

Named arguments are generally provided in PHP code using `FmtArg`; the names
available in a translation depend on the code that requests that translation.
However, a name can also refer to a template message from the database, such
as `{conflictdef}` (the definition of conflict of interest). Only
specifically-marked translations may be included as templates.

An argument is usually a string, boolean, or number, but it may also be an
array. Use square brackets to refer to a specific element of an array, as in
`{0[foo]}` or `{names[1]}`.

Arguments with known formats, such as ftexts, are translated to match the
expected format before being interpolated. For example, given these templates:

```json
[
    {"in": "company1", "out": "<0>Fortnum & Mason", "template": true},
    {"in": "company2", "out": "<5>Sanford &amp; Sons", "template": true}
]
```

HotCRP would translate:

```php
$conf->_("<0>{company1} and {company2}")
    === "<0>Fortnum & Mason and Sanford & Sons";
$conf->_("<5>{company1} and {company2}")
    === "<5>Fortnum &amp; Mason and Sanford &amp; Sons";
```

## Format specifications

HotCRP understands the following format specifications.

| Format specification      | Result                                               |
|:--------------------------|:-----------------------------------------------------|
| `:url`                    | The string argument is urlencoded.                   |
| `:html`                   | The string argument is HTML-encoded; i.e., `&<>"'` are replaced by HTML entities. |
| `:ftext`                  | When possible, the string argument is incorporated as an ftext, rather than having its format translated or stripped. |
| `:humanize_url`           | If the argument string is a simple url, such as `https://hotcrp.com/privacy`, it is replaced by a shorter version, such as `hotcrp.com/privacy`. |
| `:.2f`, etc.              | The numeric argument is rendered using a printf-style specification. |
| `:time`                   | The integer argument is treated as a number of seconds since the Unix epoch, and printed as a long-format time. |
| `:expandedtime`           | The integer argument is treated as a number of seconds since the Unix epoch, and printed as an expanded long-format time (including the time in the browser’s time zone). |
| `:list`                   | The array argument is incorporated as a comma-separated list. |
| `:nblist`                 | The array argument is incorporated as a comma-separated list; when formatting to HTML, the elements of the list will not be broken across lines. |
| `:lcrestlist`             | The array argument is incorporated as a comma-separated list; all but the first element of the list are lower-cased. |
| `:numlist`                | The argument, which should be a list of numbers, is incorporated as a list of numeric ranges; for example, `[1, 2, 3, 4, 5, 6]` is incorporated as `1-6`. |

The `expand` property defines how HotCRP interpolates a given message. If
`expand` is `"none"`, then no interpolation is performed. If `expand` is
`"template"`, then *only* templates are interpolated, and double braces like
`{{` are included verbatim.
