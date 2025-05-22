# Hotcrapi: Command-line interface to HotCRP APIs

The Hotcrapi tool, accessible as `php batch/hotcrapi.php`, offers a command-line
interface to HotCRP APIs, including APIs on [hotcrp.com](https://hotcrp.com)
sites.

## Configuration

Hotcrapi requires a **site URL** and an **API token** for every access. The site
URL defines the HotCRP site you want to access, and the API token authenticates
to that site. Specify these parameters as command-line options, using
environment variables, or in a per-user configuration file `~/.hotcrapi.conf`.

Supply a site URL using the `-S SITEURL` command line option. If not present,
Hotcrapi checks the `HOTCRAPI_SITE` environment variable; and if that is not
present, Hotcrapi searches the per-user configuration file for a default site.

An API token is a long alphanumeric string starting with `hct_`, such as
`hct_KYyAfMVWXrRCmQxfhjYQiMhiYKYLidQtctSgGsknSXaL`. Create an API token for your
site on Account settings > Developer. Once the token is created, supply it to
Hotcrapi using the `-T APITOKEN` command line option; the `HOTCRAPI_TOKEN`
environment variable; or the per-user configuration file. API tokens are
sensitive secrets, so if you don’t want to expose one on the command line, you
can also put it in a file and supply it via `-T "<FILENAME"`.

The per-user configuration file is formatted using [INI
format](https://en.wikipedia.org/wiki/INI_file). This example defines three
sites:

```ini
[site]
url = https://sigcomm24.hotcrp.com
token = hct_KYyAfMVWXrRCmQxfhjYQiMhiYKYLidQtctSgGsknSXaL
default = true

[site "s23"]
url = https://sigcomm23.hotcrp.com

[site "s25"]
url = https://sigcomm25.hotcrp.com
token = hct_aephiFaiKiecieSah1oChoa6aaviejiechaeD1phaesh
```

Given this configuration file, a command line like `php batch/hotcrapi.php -S
s23`  would contact `https://sigcomm23.hotcrp.com` with the default token
(`hct_KYy...`), whereas a command line like `php batch/hotcrapi.php -S s25`
would contact `https://sigcomm25.hotcrp.com` with token `hct_aep...`.


## `test`

Use the `test` subcommand to check your configuration and API token. `php
batch/hotcrapi.php test` will contact the site you specify and print `Success`
if the connection succeeds. The command’s exit status is 0 on success and 1 on
failure. Options change what’s printed; `-q` will print nothing (use the exit
status to determine whether the connection succeeded), `--email` will print
the user email who owns the token, and `--roles` will print the user email and
any roles that user has on the given site.


## `paper`

Use the `paper` subcommand to fetch, modify, or delete submissions from a site.
The subcommand writes textual error messages and warnings to standard error.

To fetch a single submission, run `php batch/hotcrapi.php paper PID`, where
`PID` is the relevant submission ID. The JSON representation of the submission
is written to standard output.

To fetch multiple submissions, run `php batch/hotcrapi.php paper -q SEARCH`,
where `SEARCH` is a search query. For instance, `php batch/hotcrapi.php paper -q
1-10 -t s` will return all viewable, complete submissions with IDs between 1 and
10, inclusive. An array of JSON submissions is written to standard output.

To modify a single submission, run `php batch/hotcrapi.php paper save PID <
FILE`. `PID` can be `new` to create a submission. The modification is specified
as a JSON object. `FILE` can contain that JSON object. However, if you want to
upload a document or attachment, `FILE` should instead be a ZIP file; the
contents of the ZIP file should contain the JSON object (in a member named
`data.json` or `WHATEVER-data.json`) as well as any attachments. (To see an
example of this format, use HotCRP search to download a “JSON with attachments”
file.) If the modification succeeds, the JSON representation of the modified
submission is written to standard output.

To modify multiple submissions, run `php batch/hotcrapi.php paper save < FILE`.
Here, the JSON in `FILE` should be an *array* of JSON objects.

To delete a submission, run `php batch/hotcrapi.php paper delete PID`.


## `settings`

Use the `settings` subcommand to fetch or modify site settings in JSON format.
Administrator privilege is required to access `settings`. The subcommand writes
textual error messages and warnings to standard error.

To fetch all site settings, run `php batch/hotcrapi.php settings`. The JSON
settings are written to standard output. (The format for JSON settings is
described in [HotCRP Help > Advanced
settings](https://help.hotcrp.com/help/jsonsettings).)

You can also output a subset of the settings. For instance, `php
batch/hotcrapi.php settings --filter "#sf"` will output all settings relating to
the submission form.

To test a settings modification, run `php batch/hotcrapi.php settings test
FILE`, where `FILE` contains a JSON text. (You can also supply a JSON on the
command line instead of a file.) Information about the tested modification is
written to standard error: either `No changes` or `Would change [LIST OF
TOP-LEVEL SETTINGS]`. You can also save a full list of settings, including
the tested modifications, to a file by adding `-o OUTPUT`.

To actually change settings, run `php batch/hotcrapi.php settings save FILE`.
Either `No changes` or `Saved changes to [LIST OF TOP-LEVEL SETTINGS]` is
written to standard error. Again, you can save the resulting full settings by
adding `-o OUTPUT`.
