# Settings

These endpoints fetch and modify site settings.


# get /settings

> Fetch site settings

This endpoint returns a JSON object defining all site settings. The result can
be used to examine settings offline, change settings otherwise unavailable
through the settings UI, or transfer settings to another site.

The `filter` and `exclude` parameters can filter the returned settings to a
subset. For example, when exporting one site’s settings for use by another, you
might set `exclude` to `#id OR #deadline`; this excludes settings relevant to a
conference’s identity (`conference_name`, `site_contact_email`, etc.) or
deadlines.

* param ?reset boolean
* param ?filter string: Search expression defining settings to include
* param ?exclude string: Search expression defining settings to exclude
* response settings object
* badge admin


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
* badge admin
