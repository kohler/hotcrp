# Settings

These endpoints retrieve and modify site settings, and provide supporting
information for the settings UI: machine-readable descriptions of every setting,
libraries of ready-made submission- and review-field templates, and mail-template
expansion.


# get /settings

> Retrieve site settings

This endpoint returns a JSON object defining all site settings. The result can
be used to examine settings offline, change settings otherwise unavailable
through the settings UI, or transfer settings to another site.

The `filter` and `exclude` parameters can filter the returned settings to a
subset. For example, when exporting one site’s settings for use by another, you
might set `exclude` to `#identity OR #deadline`; this excludes settings relevant
to a conference’s identity (`conference_name`, `site_contact_email`, etc.) or
deadlines.

* badge featured
* param ?reset boolean
* param ?filter string: Search expression defining settings to include
* param ?exclude string: Search expression defining settings to exclude
* response settings object
* badge siteadmin


# post /settings

> Modify site settings

This endpoint modifies site settings according to a JSON object. This object may
define a full complement of settings or a subset. It may be provided as a
request body with type `application/json`, or as a body parameter with name
`settings`.

The `filter` and `exclude` parameters can filter the modifications that are
applied; for example, uploading a settings object with `filter` set to `#rf`
will only change settings relevant to the review form.

For more information on JSON settings, see [Help > Advanced settings](https://help.hotcrp.com/help/jsonsettings).

* badge featured
* param ?dry_run boolean: True checks input for errors, but does not save changes
* param ?reset boolean
* param ?filter string: Search expression defining settings to include
* param ?exclude string: Search expression defining settings to exclude
* param settings object: Settings to change
* param ?filename string: File name for `settings` object (for error messages)
* response ?dry_run boolean: True for dry-run requests
* response valid boolean: True if the modification was valid
* response change_list [string]: List of modified top-level settings
* response settings object: New settings
* badge siteadmin


# get /settingdescriptions

> Describe site settings

Return machine-readable descriptions of the site’s settings. The result is a
schema for the JSON objects accepted and returned by [`/settings`](#post-settings):
use it to discover the available setting names, learn each setting’s type, and
present settings in a user interface.

Each entry in `setting_descriptions` describes one top-level setting. The set
mirrors the settings the caller is allowed to view.

* response setting_descriptions [setting_description]: One descriptor per top-level setting.
* badge siteadmin


# get /submissionfieldlibrary

> List sample submission fields

Return a library of ready-made **submission fields** that an administrator can
add to the site. HotCRP’s settings UI uses this to offer sample fields (for
example, “Supplemental material” or a “Subject area” selector) and to enumerate
the submission-field types this installation supports. Chair only.

* response samples [object]: Sample submission-field configurations, each a
  settings object ready to merge into [`/settings`](#post-settings).
* response types [object]: The submission-field types available on this site.
* badge siteadmin


# get /reviewfieldlibrary

> List sample review fields

Return a library of ready-made **review fields**, the review-form counterpart of
[`/submissionfieldlibrary`](#get-submissionfieldlibrary). `samples` holds sample
review-field configurations and `types` enumerates the review-field types this
installation supports. Chair only.

* response samples [object]: Sample review-field configurations, each a settings
  object ready to merge into [`/settings`](#post-settings).
* response types [object]: The review-field types available on this site.
* badge siteadmin


# get /mailtext

> Expand a mail template

Render a mail template—or arbitrary mail text—using HotCRP’s keyword
substitution, returning the resulting subject and body. The web application’s
mail composer uses this to preview templates before sending. Available to PC
members and administrators.

There are three ways to call it:

1. Supply a `template` name to expand a stored mail template. The response
   contains the expanded `subject` and `body`, plus, when the template defines
   them, a default recipient set (`recipients`, `recipient_description`) and a
   default search collection (`t`).
2. Supply `template=all` to expand every template the caller may use; the
   results are returned in the `templates` array.
3. Supply literal `text`, `subject`, and/or `body` strings to expand arbitrary
   content instead of a stored template. Each supplied field is returned
   expanded.

Keyword substitution can be personalized. Identify a recipient with `email` (and
optionally `given_name`, `family_name`, and `affiliation`), a submission with
`p`, and one of its reviews with `r`; `reason` fills the `%REASON%` keyword and
`width` sets the line-wrapping width.

* param ?template string: Name of the template to expand, or `all` to expand
  every available template.
* param ?p pid: Submission supplying context for paper-specific keywords.
* param ?r rid: Review of `p` supplying context for review-specific keywords.
* param ?email string: Recipient email address, for personalized expansion.

    * group Recipient identity
* param ?given_name string: Recipient given (first) name.

    * group Recipient identity
* param ?family_name string: Recipient family (last) name.

    * group Recipient identity
* param ?affiliation string: Recipient affiliation.

    * group Recipient identity
* param ?reason string: Text substituted for the `%REASON%` keyword.
* param ?width integer: Line-wrapping width; defaults to no wrapping.
* param ?text string: Literal mail text to expand instead of a stored template.

    * group Literal content
* param ?subject string: Literal subject line to expand.

    * group Literal content
* param ?body string: Literal mail body to expand.

    * group Literal content
* response ?subject string: Expanded subject line.
* response ?body string: Expanded mail body.
* response ?text string: Expanded `text`, when `text` was supplied.
* response ?templates [object]: Present for `template=all`; one entry per
  template, each with `name`, `title`, expanded `subject` and `body`, and any
  default `recipients`, `recipient_description`, and `t`.
* response ?recipients string: Default recipient set for the expanded template.
* response ?recipient_description string: Human-readable description of `recipients`.
* response ?t string: Default search collection associated with the template.


# get /namedsearch

> List named searches

Return the **named (saved) searches** available to the caller, in the `searches`
array. PC members only. Each entry has a `name` and its search string `q`, and
may also carry a `display` hint, a `description`, and `editable` (whether the
caller may change it). Per-search diagnostics, if any, appear in `message_list`.

* response searches [named_search]: The caller’s viewable named searches.


# post /namedsearch

> Save named searches

Create, rename, retarget, or delete named searches. Changes are supplied as a
numbered list of structured parameters `named_search/<n>/<field>`, one group per
search; the fields are `id` (the existing search’s name, or `new` to create
one), `name`, `search` (the search string), `highlight`, `description`, and
`delete`. On success the updated list is returned exactly as [`namedsearch`
GET](#get-namedsearch) returns it.

* param ?=:named_search string: Structured per-search fields, `named_search/<n>/<field>` (see above).
* response searches [named_search]: The named searches after the change.


# get /namedformula

> List named formulas

Return the conference’s **named formulas** in `formulas`. A named formula gives a
reusable name to a [formula](#get-search) expression, so it can be referenced
from searches, scoring, and graphs. Each entry has a `name`, its `expression`,
and an `id`; `editable` marks formulas the caller may change, and a per-formula
`message_list` reports any problems evaluating the expression.

* response formulas [named_formula]: The conference’s named formulas.


# post /namedformula

> Save named formulas

Create, edit, or delete named formulas. Available to PC members. Changes are supplied as a
numbered list of structured parameters `formula/<n>/<field>`, one group per
formula; the fields are `id` (the existing formula’s ID, or `new`), `name`,
`expression`, and `delete`. On success the updated list is returned as
[`namedformula` GET](#get-namedformula) returns it.

* param ?=:formula string: Structured per-formula fields, `formula/<n>/<field>` (see above).
* response formulas [named_formula]: The named formulas after the change.
