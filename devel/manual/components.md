# HotCRP components

HotCRP features including page renderers, search keywords, submission and
review field types, and formula functions are configured through JSON
components. You can add to, modify, and selectively disable components for
your conference by declaring options in `conf/options.php`.

## Component types

The components for each feature are defined by a JSON configuration file in
`etc/`, and then modified by JSON objects and files listed in an `$Opt`
setting.

| Feature                                | Default configuration file    | `$Opt` setting            | Match? | User `allow_if`? | Nested? |
|:---------------------------------------|:------------------------------|:--------------------------|---|---|---|
| [Request handling and page rendering](./pages.md) | `etc/pages.json`   | `$Opt["pages"]`           |   | ✓ | ✓ |
| API endpoints                          | `etc/apifunctions.json`       | `$Opt["apiFunctions"]`    |   | ✓ |   |
| Formula functions                      | `etc/formulafunctions.json`   | `$Opt["formulaFunctions"]` |  | ✓ |   |
| Submission field types                 | `etc/optiontypes.json`        | `$Opt["optionTypes"]`     |   |   |   |
| Sample submission fields       | `etc/submissionfieldlibrary.json` | `$Opt["submissionFieldLibraries"]` |   |   |   |
| Assignment types for bulk assignment   | `etc/assignmentparsers.json`  | `$Opt["assignmentParsers"]` |   | ✓ |   |
| Autoassigners                          | `etc/autoassigners.json`      | `$Opt["autoassigners"]`   |   | ✓ |   |
| Help topics                            | `etc/helptopics.json`         | `$Opt["helpTopics"]`      |   | ✓ | ✓ |
| Search actions                         | `etc/listactions.json`        | `$Opt["listActions"]`     |   | ✓ |   |
| Mail keywords                          | `etc/mailkeywords.json`       | `$Opt["mailKeywords"]`    | ✓ | ✓ |   |
| Mail templates                         | `etc/mailtemplates.json`      | `$Opt["mailTemplates"]`   |   | ✓ |   |
| Search columns                         | `etc/papercolumns.json`       | `$Opt["paperColumns"]`    | ✓ | ✓ |   |
| Profile page topics                    | `etc/profilegroups.json`      | `$Opt["profileGroups"]`   |   | ✓ | ✓ |
| Sample review fields                   | `etc/reviewfieldlibrary.json` | `$Opt["reviewFieldLibraries"]` |   | ✓ |   |
| Review field types                     | `etc/reviewfieldtypes.json`   | `$Opt["reviewFieldTypes"]` |  |   |   |
| Search keywords                        | `etc/searchkeywords.json`     | `$Opt["searchKeywords"]`  | ✓ | ✓ |   |
| Setting topics and rendering           | `etc/settinggroups.json`      | `$Opt["settingGroups"]`   |   | ✓ | ✓ |
| Settings                               | `etc/settinginfo.json`        | `$Opt["settingInfo"]`     |   | ✓ |   |
| OAuth/OpenID authentication providers  | None                          | `$Opt["oAuthProviders"]`  |   |   |   |

## Component construction

HotCRP creates a feature’s components by **expanding** the default
configuration file and `$Opt` setting into a single list of **component
fragments**, which are named objects. To look up a component by name, HotCRP
**searches** this list for matching objects, then **merges** those matches
together to create a single component.

## Expansion: `$Opt` setting format

Each `$Opt` setting is an optional list of entries, where an entry is one of:

* A single PHP object (which is a component fragment).
* A list of PHP objects (component fragments).
* A JSON string that defines either a single object or an array of objects.
* A filename, which should contain a JSON array of objects.
  (HotCRP will report an error if the file can’t be found.)
* A filename preceded by `?`. (HotCRP will *not* report an error if the
  filename can’t be found.)
* A filename pattern, including wildcard characters like `*`, `?`, and
  `[...]`. (HotCRP will read all matching files.)

Filenames are searched for in the HotCRP directory and then using the
`$Opt["includePath"]` setting, which should be a list of directories.

## Search: Names and matches

Components are defined by name, and every component fragment created by the
expansion process should have a `name` property, which is a string. When looking
up a component, HotCRP first filters the list of fragments for those with
matching `name`.

Some component types, such as search keywords, can be constructed on demand.
These support **pattern fragments**, which are component fragments with a
`match` property instead of `name`. When looking up a component, HotCRP
includes all pattern fragments whose `match` property matches the desired name
as a regular expression.

A pattern fragment can have an `expand_function` property. When such a pattern
fragment matches a desired name, HotCRP calls that PHP function, passing as
arguments (1) the desired name, (2) an `XtParams` object, (3) the pattern
fragment, and (4) the `preg_match` data. The function must return a list of
component fragments (which might be empty).

## Merging

The search process yields a list of component fragments for the desired name.
HotCRP then merges those components together into a single component, which is
returned. The merge process works as follows.

1. The list of fragments is sorted by increasing `priority`. `priority`
   defaults to 0; fragments with equal `priority` are ordered by their
   position in the original fragment list (so, for example, fragments from
   `$Opt` generally override fragments from `etc/`).

2. Partial fragments are merged. A partial fragment has a `merge` property set
   to `true`; such fragments do not stand on their own, but modify preceding
   fragments by overriding their properties. For example, the partial fragment
   `{"name":"foo", "priority":2, "merge":true, "title":"Foo"}` will change the
   `title` property of some component without affecting its other properties.

    Once partial fragments are merged, the list contains complete components.

3. Disallowed components are dropped. A component can be restricted to
   specific users or conferences by setting its `allow_if` property. For
   instance, a component with `"allow_if":"admin"` is only available to
   administrators. A component with `"allow_if":false` is available to no one.

    A few features do not support per-user components, but `allow_if` can
    still be used to check selected conference properties, as in
    `"allow_if":"conf.external_login"`.

    `allow_if` syntax is described below.

4. The highest-priority remaining component, if any, is the result of the
   merge process.

## Listing components

In some cases, such as rendering pages, HotCRP needs to list all components,
or all components that match a given prefix. This list is usually produced by
ordering the relevant components according to their `order` properties.
`order` defaults to 0; ties are broken by the `name` property and and/or by
source order.

## Aliases

When a component fragment has an `alias` property, HotCRP replaces that
fragment by the result of looking up the component named `alias`. This can be
useful to provide synonyms; for example, the search keyword component fragment
`{"name": "administrator", "alias": "admin"}` allows users to search for
`administrator:` as an alias for `admin:`. Aliasing may be preferred to
copying a component definition because changes in the source component are
automatically incorporated in the alias.

An alias may point to another alias, but HotCRP will only follow alias chains
to a limited depth before giving up.

## Nested components

Certain component types, such as pages and help topics, support nesting. A
name may contain one or more slashes. The component is considered a member of
the **group** defined by everything up to the last slash; for instance,
components `a/b`, `a/c`, and `a/b/c` are members of groups `a`, `a`, and
`a/b`, respectively. If present, the component’s `group` property overrides
the group value derived from the component name.

## Allowance conditions

A component `allow_if` property should be `true`, `false`, or a string
describing a condition. Examples include:

* `admin`: The viewer is a system administrator
* `manager`: The viewer is a manager (system administrator, track
  administrator, or paper administrator)
* `pc`: The viewer is on the PC or a system administrator
* `author`: The viewer is an author
* `reviewer`: The viewer is on the PC, a system administrator, or an assigned
  reviewer
* `view_review`: The viewer may be allowed to see a review
* `empty`: The viewer is empty (has not signed in and has not provided any
  review tokens or other capabilities)
* `email`: The viewer has signed in
* `disabled`: The viewer is disabled
* `opt.XXX`: Conference option `$Opt[XXX]` is truthy
* `setting.XXX`: Conference setting `XXX` is truthy

Some component types define additional conditions.

It is also possible to combine multiple conditions using parentheses, `!`
(negation), `&&`, and `||`. For instance, the condition `author || reviewer`
is true for both authors and reviewers.
