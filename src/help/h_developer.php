<?php
// help/h_developer.php -- HotCRP help functions
// Copyright (c) 2006-2023 Eddie Kohler; see LICENSE.

class Developer_HelpTopic {
    /** @var Conf */
    private $conf;
    /** @var Contact */
    private $user;
    /** @var HelpRenderer */
    private $hth;

    function __construct(HelpRenderer $hth) {
        $this->conf = $hth->conf;
        $this->user = $hth->user;
        $this->hth = $hth;
    }

    function print() {
        echo "<p>HotCRP users can access its API using OAuth bearer tokens.
Administrators with shell access to a HotCRP installation can control
it using command line scripts.</p>";
    }

    function print_tokens() {
        echo "<p>HotCRP’s API is most easily accessed using bearer tokens,
which are long strings of letters and numbers starting with <code>hct_</code>
(for example, <code>hct_SksHaeRYmWEfgQnsFcGSJUpCFtYpWayPYTgsDBCrAMpF</code>).
Any HotCRP user can create bearer tokens using ",
    $this->hth->hotlink("Profile &gt; Developer", "profile", ["t" => "developer"]),
    ". To use a bearer token, supply it in an HTTP Authorization header, as in
this <code>curl</code> example:</p>

<pre class=\"sample\"><code class=\"language-shellsession\"><span class=\"shellsession-prompt\">$ </span><span class=\"shellsession-typed\">curl -H \"Authorization: Bearer hct_SksHaeRYmWEfgQnsFcGSJUpCFtYpWayPYTgsDBCrAMpF\" \\
        http://site.hotcrp.com/api/whoami</span>
{
    \"ok\": true,
    \"email\": \"ekohler@gmail.com\"
}</code></pre>

<p>A token has the same rights and permissions as the
user who created it.</p>";
    }

    function print_usage() {
        echo "<p>HotCRP API functions generally use GET or POST methods.
Parameters are read from the URL and, for most POST methods, in the request
body using <code>application/x-www-form-urlencoded</code> or
<code>multipart/form-data</code> encoding. (Some API functions take a JSON
request body.)</p>

<p>Responses are returned as JSON objects. Common components include:<p>

<dl>
<dt><code>ok</code> (boolean)</dt>
<dd>Whether the API request succeeded.</dd>
<dt><code>message_list</code> (list of objects)</dt>
<dd>Error messages, warnings, and other messages about the API request.</dd>
</dl>";
    }

    function print_settings() {
        echo "<p>The chair-only <code>api/settings</code> endpoint accesses
conference settings in ",
    $this->hth->hotlink("JSON format", "help", ["t" => "jsonsettings"]) . ".
To modify settings, use the POST method and provide a JSON request body.
Examples:</p>

<pre class=\"sample\"><code class=\"language-shellsession\"><span class=\"shellsession-prompt\">$ </span><span class=\"shellsession-typed\">curl -H \"Authorization: Bearer hct_SksHaeRYmWEfgQnsFcGSJUpCFtYpWayPYTgsDBCrAMpF\" \\
        http://site.hotcrp.com/api/settings</span>
{
    \"ok\": true,
    \"settings\": {
        \"accepted_author_visibility\": false,
        \"author_visibility\": \"blind\", ...
    }
}
<span class=\"shellsession-prompt\">$ </span><span class=\"shellsession-typed\">curl -H \"Authorization: Bearer hct_SksHaeRYmWEfgQnsFcGSJUpCFtYpWayPYTgsDBCrAMpF\" \\
        -H \"Content-Type: application/json\" \\
        --data-binary '{\"accepted_author_visibility\": true}' \\
        http://site.hotcrp.com/api/settings</span>
{
    \"ok\": true,
    \"message_list\": [],
    \"changes\": [\"seedec_hideau\"],
    \"settings\": {
        \"accepted_author_visibility\": true,
        \"author_visibility\": \"blind\", ...
    }
}</code></pre>";
    }

    function print_submissions() {
        echo "<p>The <code>api/paper</code> endpoint accesses conference
submissions. GET calls return paper information; use
<code>api/PAPERID/paper</code> to return one paper, and
<code>api/paper?q=SEARCH&amp;t=SEARCHTYPE</code> to return all papers matching
<code>SEARCH</code>.</p>";
    }
}
