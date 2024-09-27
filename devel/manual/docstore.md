# HotCRP document store

HotCRP submission metadata are stored in the per-conference MySQL database. In
default installations, document contents, such as PDF files, are also stored
in the database. However, the system can also be configured to store document
contents on the filesystem in a **document store** or **docstore**.


## Configuring the docstore

Set up a document store by configuring `$Opt["docstore"]`. This setting is a
filename pattern that sets where documents are stored on the filesystem.

To determine the filename for a document, HotCRP expands `%` escapes in
`$Opt["docstore"]` using document information. The escapes are:

| Escape | Meaning | Examples  |
|:-------|:--------|:---------|
| `%H`   | Content hash | `d16c7976d9081368c7dca2da3a771065c3222069a1ad80dcd99d972b2efadc8b` |
| `%NH`  | First `N` bytes of content hash | `d16` (for `%3H`) |
| `%a`   | Hash algorithm | `sha256`, `sha1` |
| `%A`   | Hash algorithm prefix | `sha2-` (for SHA-256), empty string (for SHA-1) |
| `%h`   | Content hash with algorithm prefix | `sha2-d16c7976d9081368c7dca2da3a771065c3222069a1ad80dcd99d972b2efadc8b` |
| `%Nh`  | First `N` bytes of content hash with algorithm prefix | `sha2-d16` (for `%3h`) |
| `%x`   | File extension | `.pdf`, `.txt` |
| `%%`   | Literal `%` | `%` |

A full `$Opt["docstore"]` setting must include a full hash (`%H` or `%h`). If
`$Opt["docstore"]` does not include a `%` sign, then HotCRP automatically
appends `/%h%x` to the setting value, and if `$Opt["docstore"]` is `true`,
HotCRP uses `docs/%h%x`. Relative paths are interpreted relative to the HotCRP
installation directory.

The HotCRP PHP server must have read and write permission to the document
store. `php-fpm` and/or `httpd` typically own the docstore directory, or they
have group access (and the docstore direcrory has set-group-id permission).
HotCRP will create subdirectories as necessary; for instance, with docstore
`"docs/%2h/%H%x`, HotCRP might try to create the docstore subdirectory
`docs/sha2-d1` to fit a file with SHA-256 hash
`d16c7976d9081368c7dca2da3a771065c3222069a1ad80dcd99d972b2efadc8b`.


## Temporary docstore

A special subdirectory of the docstore is used for large temporary files,
especially files that may need to outlive a single request. Examples include
chunks of uploaded documents and constructed ZIP archives and CSV files.

To form the temporary docstore, HotCRP appends `/tmp` to the docstore’s fixed
prefix. For example, the docstore `"/home/hotcrp/docs/sub-%3h/%H%x"` has
temporary docstore `/home/hotcrp/docs/tmp`.

The temporary docstore should be cleaned periodically, for instance by the
batch script `php batch/cleandocstore.php`.


## Docstore, database, and S3

HotCRP can store documents in the MySQL database (the default), in the
docstore, and in Amazon S3. Amazon S3 is typically the slowest of these
methods, but needs no separate backup. If you have configured either the
docstore or S3, you can disable database storage by setting
`$Opt["dbNoPapers"]` to `true`. If you have configured the docstore *and* S3,
then the docstore can act as a cache for S3. Incoming documents are stored in
both places; if a docstore version is missing later, HotCRP will check S3 for
it.
