<?php
// help/h_jsonsettings.php -- HotCRP help functions
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

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
        echo "<p>",
            $this->hth->setting_group_link("Settings &gt; Advanced", "json"),
            " lets administrators configure site operation by modifying a JSON
configuration file. Advanced users can copy settings from one conference to
another and configure aspects of the site not accessible via the normal
settings UI.</p>";


        echo $this->hth->subhead("Viewing");

        echo "<p>HotCRP site configuration is formatted as a JSON object. ",
            $this->hth->setting_group_link("Settings &gt; Advanced", "json"),
            " shows the full site configuration as an object with
dozens of components. Click on a component to get more information about that
setting’s meaning and format.</p>";


        echo $this->hth->subhead("Modifying");

        echo "<p>To modify the configuration, edit the provided JSON
and save changes. Errors will be highlighted.</p>";

        echo "<p>If you save a partial configuration, any settings not
mentioned in the JSON will keep their original values. For instance, to update
a site’s submission fields and leave everything else the same, you could save
a JSON object that only contained an <code class=\"settings-jpath\">sf</code>
component.</p>";


        echo $this->hth->subhead("Object lists for complex settings");

        echo "<p>Multipart HotCRP settings, including review rounds <code
class=\"settings-jpath\">review</code>, submission fields <code
class=\"settings-jpath\">sf</code>, review fields <code
class=\"settings-jpath\">rf</code>, and decision types <code
class=\"settings-jpath\">decision</code>, are specified as <strong>object
lists</strong>. These are arrays of JSON objects where each object corresponds
to a subsetting. For instance, <code class=\"settings-jpath\">sf</code> is an
object list with one entry per submission field. Read this section before
working with object lists.</p>";

        echo "<ul>", "<li><p><strong>IDs.</strong> Individual objects in most
lists are identified by their <code>\"id\"</code> components. The
<code>\"id\"</code> is assigned by HotCRP and should not be changed.</p></li>


<li><p><strong>Adding subsettings.</strong> Add a subsetting, such as a new
submission field, by including an object with <code>\"id\": \"new\"</code>. For
instance, this JSON adds a new decision type for desk-rejected papers (an
error will be reported if an existing decision type is named “Desk
rejects”).</p>

<pre class=\"example\">{
    \"decision\": [
        {\"id\": \"new\", \"name\": \"Desk rejects\", \"category\": \"reject\"}
    ]
}</pre></li>


<li><p><strong>Deleting subsettings.</strong> Delete a subsetting by adding
<code>\"delete\": true</code> to its object. Note that removing the object
<em>from the list</em> will preserve the corresponding setting, since settings
not mentioned in a JSON file preserve their original values. You must
explicitly delete subsettings you no longer want.</p></li>


<li><p><strong>Missing IDs.</strong> If an object is missing a required
<code>\"id\"</code> component, HotCRP try matching its <code>\"name\"</code>
component with those of existing subsettings. Any unmatched objects are
assumed to be new.</p></li>


<li><p><strong>Copying settings between conferences.</strong> Beware of IDs
when copying settings between conferences. Unless you are careful, the IDs
used in one conference may differ from the IDs in another. Consider removing
the IDs from a conference’s JSON settings before uploading those settings to
another conference.</p></li>


<li><p><strong>Other common components</strong> in object lists include
<code>\"order\"</code>, which determines the natural order for subsettings
(e.g., the order submission fields appear on the submission form; lower values
appear first), and <code>\"values\"</code>, a second-level object list that
determines the values that apply to a submission or review field.</p></li>";

        echo "</ul>";
    }
}
