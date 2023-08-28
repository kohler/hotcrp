<?php
// help/h_jsonsettings.php -- HotCRP help functions
// Copyright (c) 2006-2023 Eddie Kohler; see LICENSE.

class JSONSettings_HelpTopic {
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
        echo "<p>With ",
            $this->hth->setting_group_link("Settings &gt; Advanced", "json"),
            ", administrators can configure site operation by modifying a JSON
specification. Advanced users can copy settings from one conference to
another and configure aspects of the site not accessible via the normal
settings UI.</p>";


        echo $this->hth->subhead("View settings");

        echo "<p>", $this->hth->setting_group_link("Settings &gt; Advanced", "json"),
            " shows the full site configuration as an object with
dozens of components, including some settings unavailable elsewhere in the
settings UI. Click on a component to get more information about that
setting’s meaning and format.</p>";


        echo $this->hth->subhead("Modify settings");

        echo "<p>To modify the site configuration, edit the provided JSON
and save changes. Errors will be highlighted.</p>";

        echo "<p>Any settings not mentioned in the JSON will keep their
original values. For instance, to update a site’s submission fields and leave
everything else the same, you could save a JSON object with just the <code
class=\"settings-jpath\">sf</code> component.</p>";


        echo $this->hth->subhead("Object lists for complex settings");

        echo "<p>Settings including review rounds <code
class=\"settings-jpath\">review</code>, submission fields <code
class=\"settings-jpath\">sf</code>, review fields <code
class=\"settings-jpath\">rf</code>, and decision types <code
class=\"settings-jpath\">decision</code> are specified as <strong>object
lists</strong>, which are arrays of JSON objects where each object corresponds
to a subsetting. For instance, <code class=\"settings-jpath\">sf</code> is an
array with one entry per submission field, and <code
class=\"settings-jpath\">decision</code> is an array with one entry per
decision type. The default <code class=\"settings-jpath\">decision</code>
setting looks like this:</p>

<pre class=\"sample\"><code class=\"language-json\">{
    \"decision\": [
        { \"id\": 1, \"name\": \"Accepted\", \"category\": \"accept\" },
        { \"id\": -1, \"name\": \"Rejected\", \"category\": \"reject\" }
    ]
}</code></pre>";

        echo "<ul>", "<li><p><strong>IDs.</strong> Individual objects in most
lists are identified by their <code>\"id\"</code> components. The
<code>\"id\"</code> is assigned by HotCRP and should not be changed. If a
subsetting is missing its <code>\"id\"</code>, HotCRP searches existing
subsettings for one with a matching <code>\"name\"</code>; if none is found,
the subsetting is assumed to be new.</p></li>


<li><p><strong>Adding subsettings.</strong> <code>\"id\": \"new\"</code>
identifies subsettings that should be added. For instance, this adds two new
decision types:</p>

<pre class=\"sample\"><code class=\"language-json\">{
    \"decision\": [
        {\"id\": \"new\", \"name\": \"Desk reject\", \"category\": \"desk_reject\"},
        {\"id\": \"new\", \"name\": \"Desk accept\", \"category\": \"accept\"}
    ]
}</code></pre></li>


<li><p><strong>Deleting subsettings.</strong> Delete a subsetting by adding
<code>\"delete\": true</code> to its object. For instance, this would delete
a “Desk reject” decision:</p>

<pre class=\"sample\"><code class=\"language-json\">{
    \"decision\": [
        {\"name\": \"Desk reject\", \"delete\": true}
    ]
}</code></pre></li>


<li><p><strong>Resetting subsettings.</strong> Unmentioned subsettings are
left unchanged by default. If you want instead to delete any unmentioned subsettings,
set
<code class=\"language-json\">\"reset\"</code>
or
<code class=\"language-json\">\"SETTINGNAME_reset\"</code>
to true. For instance, this deletes all topics:</p>

<pre class=\"sample\"><code class=\"langage-json\">{
    \"topic_reset\": true, \"topic\": []
}</code></pre>

<p>This, on the other hand, has no effect (the JSON mentions no
topics, so <em>all</em> topic subsettings remain unchanged):

<pre class=\"sample\"><code class=\"langage-json\">{
    \"topic\": []
}</code></pre></li>


<li><p><strong>Copying settings between conferences.</strong> Beware of IDs
when copying settings between conferences. Unless you are careful, the IDs
used in one conference may differ from the IDs in another. Consider removing
the IDs from a conference’s JSON settings before uploading those settings to
another conference.</p></li>


<li><p><strong>Other common components</strong> in object lists include
<code>\"order\"</code>, which determines the natural order for subsettings
(e.g., the order submission fields appear on the submission form; lower values
appear first), and <code>\"values\"</code>, a nested object list that
determines the values that apply to a submission or review field.</p></li>";

        echo "</ul>";
    }
}
