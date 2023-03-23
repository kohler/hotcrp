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

<pre class=\"sample\">$ curl -H \"Authorization: Bearer hct_SksHaeRYmWEfgQnsFcGSJUpCFtYpWayPYTgsDBCrAMpF\" \\
        http://site.hotcrp.com/api/whoami
{
    \"ok\": true,
    \"email\": \"ekohler@gmail.com\"
}</pre>

<p>A token has the same rights and permissions as the
user who created it.</p>";
    }
}
