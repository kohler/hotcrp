// settings.js -- HotCRP JavaScript library for settings
// Copyright (c) 2006-2021 Eddie Kohler; see LICENSE.

function next_lexicographic_permutation(i, size) {
    var y = (i & -i) || 1, c = i + y, highbit = 1 << size;
    i = (((i ^ c) >> 2) / y) | c;
    if (i >= highbit) {
        i = ((i & (highbit - 1)) << 2) | 3;
        if (i >= highbit)
            i = false;
    }
    return i;
}

handle_ui.on("js-settings-resp-active", function (event) {
    $(".if-response-active").toggleClass("hidden", !this.checked);
});

$(function () { $(".js-settings-resp-active").trigger("change"); });

handle_ui.on("js-settings-au-seerev-tag", function (event) {
    $("#au_seerev_3").click(); // AUSEEREV_TAGS
});

handle_ui.on("js-settings-sub-nopapers", function (event) {
    var v = $(this).val();
    hotcrp.fold("pdfupload", v == 1, 2);
    hotcrp.fold("pdfupload", v != 0, 3);
});

$(function () { $(".js-settings-sub-nopapers").trigger("change"); });


handle_ui.on("js-settings-option-type", function (event) {
    var v = this.value;
    $(this).closest(".settings-opt").find(".has-optvt-condition").each(function () {
        toggleClass(this, "hidden", this.getAttribute("data-optvt-condition").split(" ").indexOf(v) < 0);
    });
});

handle_ui.on("js-settings-show-property", function () {
    var prop = this.getAttribute("data-property"),
        $j = $(this).closest(".settings-opt, .settings-rf").find(".is-property-" + prop);
    $j.removeClass("hidden");
    addClass(this, "btn-disabled");
    tooltip.erase.call(this);
    if (document.activeElement === this || document.activeElement === document.body) {
        var $jx = $j.find("input, select, textarea").not("[type=hidden], :disabled");
        $jx.length && setTimeout(function () { focus_at($jx[0]); }, 0);
    }
});

handle_ui.on("js-settings-option-move", function (event) {
    var odiv = $(this).closest(".settings-opt")[0];
    if (hasClass(this, "moveup") && odiv.previousSibling)
        odiv.parentNode.insertBefore(odiv, odiv.previousSibling);
    else if (hasClass(this, "movedown") && odiv.nextSibling)
        odiv.parentNode.insertBefore(odiv, odiv.nextSibling.nextSibling);
    else if (hasClass(this, "delete")) {
        var $odiv = $(odiv), x;
        if ($odiv.find(".settings-opt-id").val() === "new")
            $odiv.remove();
        else {
            tooltip.erase.call(this);
            $odiv.find(".settings-opt-fp").val("deleted").change();
            $odiv.find(".f-i, .entryi").each(function () {
                if (!$(this).find(".settings-opt-fp").length)
                    $(this).remove();
            });
            $odiv.find("input[type=text]").prop("disabled", true).css("text-decoration", "line-through");
            if ((x = this.getAttribute("data-option-exists")))
                $odiv.append('<div class="f-i"><em>This field will be deleted from the submission form and from ' + plural(x, 'submission') + '.</em></div>');
            else
                $odiv.append('<div class="f-i"><em>This field will be deleted from the submission form.</em></div>');
        }
    }
    settings_option_positions();
});

handle_ui.on("js-settings-option-new", function (event) {
    var h = $("#settings_newopt").attr("data-template");
    var next = 1;
    while ($("#optn_" + next).length)
        ++next;
    h = h.replace(/_0/g, "_" + next);
    var odiv = $(h).appendTo("#settings_opts");
    odiv.find(".need-autogrow").autogrow();
    odiv.find(".need-tooltip").each(tooltip);
    odiv.find(".js-settings-option-type").change();
    $("#optn_" + next)[0].focus();
    settings_option_positions();
});

function settings_option_positions() {
    if ($(".settings-opt").length) {
        $(".settings-opt .moveup, .settings-opt .movedown").prop("disabled", false);
        $(".settings-opt:first-child .moveup").prop("disabled", true);
        $(".settings-opt:last-child .movedown").prop("disabled", true);
        var index = 0;
        $(".settings-opt-fp").each(function () {
            if (this.value !== "deleted" && this.name !== "optfp_0") {
                ++index;
                if (this.value != index)
                    $(this).val(index).change();
            }
        });
    }
}

$(settings_option_positions);


handle_ui.on("js-settings-banal-pagelimit", function (evt) {
    var s = $.trim(this.value),
        empty = s === "" || s.toUpperCase() === "N/A",
        $ur = $(this).closest(".has-fold").find(".settings-banal-unlimitedref");
    $ur.find("label").toggleClass("dim", empty);
    $ur.find("input").prop("disabled", empty);
    if (evt && evt.type === "change" && empty)
        $ur.find("input").prop("checked", false);
});


handle_ui.on("js-settings-add-decision-type", function (event) {
    var $t = $("#settings-decision-types"), next = 1;
    while ($t.find("input[name=dec_name_" + next + "]").length)
        ++next;
    $("#settings-decision-type-notes").removeClass("hidden");
    var h = $("#settings-new-decision-type").html().replace(/_0/g, "_" + next),
        $r = $(h).appendTo($t);
    $r.find("input[type=text]").autogrow();
    $r.find("input[name=dec_name_" + next + "]")[0].focus();
});

handle_ui.on("js-settings-remove-decision-type", function (event) {
    var $r = $(this).closest("tr");
    $r.addClass("hidden").find("input[name^=dec_name]").val("");
    $r.find("select[name^=dec_class]").val("1");
    form_highlight($r.closest("form"));
});

handle_ui.on("js-settings-new-autosearch", function (event) {
    var odiv = $(this).closest(".settings_tag_autosearch")[0],
        h = $("#settings_newtag_autosearch").html(), next = 1;
    while ($("#tag_autosearch_t_" + next).length)
        ++next;
    h = h.replace(/_0/g, "_" + next);
    odiv = $(h).appendTo("#settings_tag_autosearch");
    odiv.find("input[type=text]").autogrow();
    $("#tag_autosearch_t_" + next)[0].focus();
});

handle_ui.on("js-settings-delete-autosearch", function (event) {
    var odiv = $(this).closest(".settings_tag_autosearch")[0];
    $(odiv).find("input[name^=tag_autosearch_q_]").val("");
    $(odiv).find("input[type=text]").prop("disabled", true).css("text-decoration", "line-through");
});

handle_ui.on("js-settings-add-track", function () {
    for (var i = 1; jQuery("#trackgroup" + i).length; ++i)
        /* do nothing */;
    $("#trackgroup" + (i - 1)).after("<div id=\"trackgroup" + i + "\" class=\"mg has-fold fold3o\"></div>");
    var $j = jQuery("#trackgroup" + i);
    $j.html(jQuery("#trackgroup0").html().replace(/_track0/g, "_track" + i));
    $j.find(".need-suggest").each(suggest);
    $j.find("input[name^=name]").focus();
});

handle_ui.on("js-settings-copy-topics", function () {
    var topics = [];
    $(this).closest(".has-copy-topics").find("[name^=top]").each(function () {
        topics.push(escape_entities(this.value));
    });
    var node = $("<textarea></textarea>").appendTo(document.body);
    node.val(topics.join("\n"));
    node[0].select();
    document.execCommand("copy");
    node.remove();
});


window.review_round_settings = (function ($) {
var added = 0;

function namechange() {
    var roundnum = this.id.substr(10), name = $.trim($(this).val());
    $("#rev_roundtag_" + roundnum + ", #extrev_roundtag_" + roundnum)
        .text(name === "" ? "(no name)" : name);
}

function add() {
    var i, h, j;
    for (i = 1; $("#roundname_" + i).length; ++i)
        /* do nothing */;
    $("#round_container").show();
    $("#roundtable").append($("#newround").html().replace(/\$/g, i));
    var $mydiv = $("#roundname_" + i).closest(".js-settings-review-round");
    $("#rev_roundtag").append('<option value="#' + i + '" id="rev_roundtag_' + i + '">(new round)</option>');
    $("#extrev_roundtag").append('<option value="#' + i + '" id="extrev_roundtag_' + i + '">(new round)</option>');
    $("#roundname_" + i).focus().on("input change", namechange);
}

function kill() {
    var divj = $(this).closest(".js-settings-review-round"),
        roundnum = divj.data("reviewRoundNumber"),
        vj = divj.find("input[name=deleteround_" + roundnum + "]"),
        ej = divj.find("input[name=roundname_" + roundnum + "]");
    if (vj.val()) {
        vj.val("");
        divj.find(".js-settings-review-round-deleted").remove();
        ej.prop("disabled", false);
        $(this).html("Delete round");
    } else {
        vj.val(1);
        ej.prop("disabled", true);
        $(this).html("Restore round").after('<strong class="js-settings-review-round-deleted" style="padding-left:1.5em;font-style:italic;color:red">&nbsp; Review round deleted</strong>');
    }
    divj.find("table").toggle(!vj.val());
    form_highlight("#settingsform");
}

return function () {
    $("#roundtable input[type=text]").on("input change", namechange);
    $("#settings_review_round_add").on("click", add);
    $("#roundtable").on("click", ".js-settings-review-round-delete", kill);
};
})($);


window.review_form_settings = (function () {
var fieldorder, original, samples, stemplate, ttemplate,
    colors = ["sv", "Red to green", "svr", "Green to red",
              "sv-blpu", "Blue to purple", "sv-publ", "Purple to blue",
              "sv-viridis", "Purple to yellow", "sv-viridisr", "Yellow to purple"];

function get_fid(elt) {
    return elt.id.replace(/^.*_/, "");
}

function unparse_option(fieldj, idx) {
    if (fieldj.option_letter) {
        var cc = fieldj.option_letter.charCodeAt(0);
        return String.fromCharCode(cc + fieldj.options.length - idx);
    } else
        return idx.toString();
}

function options_to_text(fieldj) {
    var i, t = [];
    if (!fieldj.options)
        return "";
    for (i = 0; i !== fieldj.options.length; ++i)
        t.push(unparse_option(fieldj, i + 1) + ". " + fieldj.options[i]);
    if (fieldj.option_letter)
        t.reverse();
    if (t.length)
        t.push(""); // get a trailing newline
    return t.join("\n");
}

function option_class_prefix(fieldj) {
    var sv = fieldj.option_class_prefix || "sv";
    if (fieldj.option_letter)
        sv = colors[(colors.indexOf(sv) || 0) ^ 2];
    return sv;
}

function fill_order() {
    var i, c = $("#reviewform_container")[0], n;
    for (i = 1, n = c.firstChild; n; ++i, n = n.nextSibling)
        $(n).find(".rf-position").val(i);
    c = $("#reviewform_removedcontainer")[0];
    for (n = c.firstChild; n; n = n.nextSibling)
        $(n).find(".rf-position").val(0);
    form_highlight("#settingsform");
}

function fold_property(fid, property, $j, hideval) {
    var $f = $("#rf_" + fid), hidden = true;
    for (var i = 0; i !== $j.length; ++i) {
        hidden = hidden && !input_differs($j[i]) && $($j[i]).val() == hideval[i];
    }
    $f.find(".is-property-" + property).toggleClass("hidden", hidden);
    $f.find(".js-settings-show-property[data-property=\"".concat(property, "\"]")).toggleClass("btn-disabled", !hidden);
}

function fold_properties(fid) {
    fold_property(fid, "description", $("#rf_description_" + fid), [""]);
    fold_property(fid, "editing", $("#rf_ec_" + fid), ["all"]);
}

function fill_field_control(sel, value, order) {
    var $j = $(sel).val(value);
    order && $j.attr("data-default-value", value);
}

function fill_field($f, fid, fieldj, order) {
    fieldj = fieldj || original[fid] || {};
    fill_field_control("#rf_name_" + fid, fieldj.name || "", order);
    fill_field_control("#rf_description_" + fid, fieldj.description || "", order);
    fill_field_control("#rf_visibility_" + fid, fieldj.visibility || "pc", order);
    fill_field_control("#rf_options_" + fid, options_to_text(fieldj), order);
    fill_field_control("#rf_required_" + fid, fieldj.required ? "1" : "0", order);
    fill_field_control("#rf_colorsflipped_" + fid, fieldj.option_letter ? "1" : "", order);
    fill_field_control("#rf_colors_" + fid, option_class_prefix(fieldj), order);
    var ec, ecs = fieldj.exists_if != null ? fieldj.exists_if : "";
    if (ecs === "" || ecs.toLowerCase() === "all") {
        ec = "all";
    } else if (/^round:[a-zA-Z][-_a-zA-Z0-9]*$/.test(ecs)
               && $("#rf_ec_" + fid + " > option[value=\"" + ecs + "\"]").length) {
        ec = ecs;
    } else {
        ec = "custom";
    }
    fill_field_control("#rf_ec_" + fid, ec, order);
    fill_field_control("#rf_ecs_" + fid, ecs, order);
    $("#rf_" + fid + " textarea").trigger("change");
    $("#rf_" + fid + "_view").html("").append(create_field_view(fieldj));
    $("#rf_" + fid + "_delete").attr("aria-label", fieldj.has_any_nonempty ? "Delete from form and current reviews" : "Delete from form");
    order && fill_field_control("#rf_position_" + fid, fieldj.position || 0, order);
    fold_properties(fid);
    return false;
}

function remove() {
    var $f = $(this).closest(".settings-rf"),
        fid = $f.attr("data-revfield");
    $f.find(".rf-position").val(0);
    $f.detach().hide().appendTo("#reviewform_removedcontainer");
    $("#reviewform_removedcontainer").append('<div id="revfieldremoved_' + fid + '" class="settings-rf-deleted"><span class="settings-revfn" style="text-decoration:line-through">' + escape_entities($f.find("#rf_name_" + fid).val()) + '</span>&nbsp; (field removed)</div>');
    fill_order();
}

tooltip.add_builder("settings-review-form", function (info) {
    var m = this.name.match(/^rf_(.*)_[a-z]\d+$/);
    return $.extend({
        anchor: "w", content: $("#settings-review-form-caption-" + m[1]).html(), className: "gray"
    }, info);
});

tooltip.add_builder("settings-option", function (info) {
    var x = "#option_caption_choices";
    if (/^optn/.test(this.name))
        x = "#option_caption_name";
    else if (/^optecs/.test(this.name))
        x = "#option_caption_condition_search";
    return $.extend({anchor: "h", content: $(x).html(), className: "gray"}, info);
});

function option_value_html(fieldj, value) {
    var t, n;
    if (!value || value < 0)
        return ["", "No entry"];
    t = '<strong class="rev_num sv';
    if (value <= fieldj.options.length) {
        if (fieldj.options.length > 1)
            n = Math.floor((value - 1) * 8 / (fieldj.options.length - 1) + 1.5);
        else
            n = 1;
        t += " " + (fieldj.option_class_prefix || "sv") + n;
    }
    return [t + '">' + unparse_option(fieldj, value) + '.</strong>',
            escape_entities(fieldj.options[value - 1] || "Unknown")];
}

function view_unfold(event) {
    var $f = $(event.target).closest(".settings-rf");
    if ($f.hasClass("fold2c") || !form_differs($f))
        foldup.call(event.target, event, {n: 2});
    return false;
}

function field_visibility_text(visibility) {
    if ((visibility || "pc") === "pc")
        return "(hidden from authors)";
    else if (visibility === "admin")
        return "(administrators only)";
    else if (visibility === "secret")
        return "(secret)";
    else if (visibility === "audec")
        return "(hidden from authors until decision)";
    else
        return "";
}

function create_field_view(fieldj) {
    var hc = new HtmlCollector;
    hc.push('<div>', '</div>');

    hc.push('<h3 class="revet">', '</h3>');
    hc.push('<label class="revfn'.concat(fieldj.required ? " field-required" : "", '">', escape_entities(fieldj.name || "<unnamed>"), '</label>'));
    var t = field_visibility_text(fieldj.visibility), i;
    if (t)
        hc.push('<div class="field-visibility">'.concat(t, '</div>'));
    hc.pop();

    if (fieldj.exists_if && /^round:[a-zA-Z][-_a-zA-Z0-9]*$/.test(fieldj.exists_if)) {
        hc.push('<p class="feedback is-warning">Present on ' + fieldj.exists_if.substring(6) + ' reviews</p>');
    } else if (fieldj.exists_if) {
        hc.push('<p class="feedback is-warning">Present on reviews matching “' + escape_entities(fieldj.exists_if) + '”</p>');
    }

    if (fieldj.description)
        hc.push('<div class="field-d">'.concat(fieldj.description, '</div>'));

    hc.push('<div class="revev">', '</div>');
    if (fieldj.options) {
        for (i = 0; i !== fieldj.options.length; ++i) {
            var n = fieldj.option_letter ? fieldj.options.length - i : i + 1;
            hc.push('<label class="checki"><span class="checkc"><input type="radio" disabled></span>'.concat(option_value_html(fieldj, n).join(" "), '</label>'));
        }
        if (!fieldj.required) {
            hc.push('<label class="checki g"><span class="checkc"><input type="radio" disabled></span>No entry</label>');
        }
    } else
        hc.push('<textarea class="w-text" rows="' + Math.max(fieldj.display_space || 0, 3) + '" disabled>(Text field)</textarea>');

    return $(hc.render());
}

function move_field(event) {
    var isup = $(this).hasClass("moveup"),
        $f = $(this).closest(".settings-rf").detach(),
        fid = $f.attr("data-revfield"),
        pos = $f.find(".rf-position").val() | 0,
        $c = $("#reviewform_container")[0], $n, i;
    for (i = 1, $n = $c.firstChild;
         $n && i < (isup ? pos - 1 : pos + 1);
         ++i, $n = $n.nextSibling)
        /* nada */;
    $c.insertBefore($f[0], $n);
    fill_order();
}

function append_field(fid, pos) {
    var $f = $("#rf_" + fid), i, $j, $tmpl = $("#rf_template");
    $("#revfieldremoved_" + fid).remove();

    if ($f.length) {
        $f.detach().show().appendTo("#reviewform_container");
        fill_order();
        return;
    }

    $f = $($tmpl.html().replace(/\$/g, fid));

    if (fid.charAt(0) === "s") {
        $j = $f.find("select[name^=\"rf_colors_\"]");
        for (i = 0; i < colors.length; i += 2)
            $j.append("<option value=\"" + colors[i] + "\">" + colors[i+1] + "</option>");
    } else
        $f.find(".is-property-options").remove();

    var rnames = [];
    for (i in hotcrp_status.revs || {})
        rnames.push(i);
    if (rnames.length > 1) {
        var v, j, text;
        $j = $f.find("select[name$=\"rounds\"]");
        for (i = 0; i < (1 << rnames.length) - 1;
             i = next_lexicographic_permutation(i, rnames.length)) {
            text = [];
            for (j = 0; j < rnames.length; ++j)
                if (i & (1 << j))
                    text.push(rnames[j]);
            if (!text.length)
                $j.append("<option value=\"all\">All rounds</option>");
            else if (text.length == 1)
                $j.append("<option value=\"" + text[0] + "\">" + text[0] + " only</option>");
            else
                $j.append("<option value=\"" + text.join(" ") + "\">" + commajoin(text) + "</option>");
        }
    } else {
        $f.find(".reviewrow_rounds").remove();
    }

    $f.find(".js-settings-rf-delete").on("click", remove);
    $f.find(".js-settings-rf-move").on("click", move_field);
    $f.appendTo("#reviewform_container");

    fill_field($f, fid, original[fid], true);
    $f.find(".need-tooltip").each(tooltip);
}

function add_field(fid) {
    fieldorder.push(fid);
    original[fid] = original[fid] || {};
    original[fid].position = fieldorder.length;
    append_field(fid, fieldorder.length);
    foldup.call($("#rf_" + fid)[0], null, {n: 2, f: false});
    $("#rf_position_" + fid).attr("data-default-value", "0");
    form_highlight("#settingsform");
    return true;
}

function rfs(data) {
    var i, fid, $j, m, elt, entryi;
    original = data.fields;
    samples = data.samples;
    stemplate = data.stemplate;
    ttemplate = data.ttemplate;

    fieldorder = [];
    for (fid in original) {
        if (original[fid].position)
            fieldorder.push(fid);
    }
    fieldorder.sort(function (a, b) {
        return original[a].position - original[b].position;
    });

    // construct form
    for (i = 0; i != fieldorder.length; ++i) {
        append_field(fieldorder[i], i + 1);
    }
    $("#reviewform_container").on("click", "a.settings-field-folder", view_unfold);
    $("#reviewform_container").on("unfold", ".settings-rf", function (evt, opts) {
        $(this).find("textarea").css("height", "auto").autogrow();
        $(this).find("input[type=text]").autogrow();
    });

    // highlight errors, apply request
    for (i in data.req || {}) {
        m = i.match(/^rf_(?:[a-z]*_|)([st]\d+)$/);
        if (m) {
            $j = $("#" + i);
            if (!$j[0]) {
                add_field(m[1]);
                $j = $("#" + i);
            }
            if ($j[0] && !text_eq($j.val(), data.req[i])) {
                $j.val(data.req[i]);
                foldup.call($j[0], null, {n: 2, f: false});
                fold_properties(m[1]);
            }
        }
    }
    for (i in data.errf || {}) {
        elt = document.getElementById(i);
        entryi = elt.closest(".entryi") || elt;
        removeClass(entryi, "hidden");
        addClass(entryi, "has-error");
        foldup.call(entryi, null, {n: 2, f: false});
    }
    for (i in data.message_list || []) {
        m = data.message_list[i];
        if (m.field
            && m.message
            && (elt = document.getElementById(m.field))
            && (entryi = elt.closest(".entry"))) {
            $(render_feedback(m.message, m.status)).prependTo(entryi);
        }
    }
    form_highlight("#settingsform");
};

function add_dialog(fid, focus) {
    var $d, template = 0, has_options = fid.charAt(0) === "s";
    function render_template() {
        var $dtn = $d.find(".newreviewfield-template-name"),
            $dt = $d.find(".newreviewfield-template");
        if (!template || !samples[template - 1] || !samples[template - 1].options != !has_options) {
            template = 0;
            $dtn.text("(Blank)");
            $dt.html("");
        } else {
            var s = samples[template - 1];
            $dtn.text(s.selector);
            $dt.html(create_field_view(s));
        }
    }
    function submit(event) {
        add_field(fid);
        template && fill_field($("#rf_" + fid), fid, samples[template - 1], false);
        $("#rf_name_" + fid)[0].focus();
        $d.close();
        event.preventDefault();
    }
    function click() {
        if (this.name == "next" || this.name == "prev") {
            var dir = this.name == "next" ? 1 : -1;
            template += dir;
            if (template < 0)
                template = samples.length;
            while (template
                   && samples[template - 1]
                   && !samples[template - 1].options !== !has_options)
                template += dir;
            render_template();
        }
    }
    function change_template() {
        ++template;
        while (samples[template - 1] && !samples[template - 1].options != !has_options)
            ++template;
        render_template();
    }
    function create() {
        var hc = popup_skeleton();
        hc.push('<h2>' + (has_options ? "Add score field" : "Add text field") + '</h2>');
        hc.push('<p>Choose a template for the new field.</p>');
        hc.push('<table style="width:500px;max-width:90%;margin-bottom:2em"><tbody><tr>', '</tr></tbody></table>');
        hc.push('<td style="text-align:left"><button name="prev" type="button" class="need-tooltip" data-tooltip="Previous template">&lt;</button></td>');
        hc.push('<td class="newreviewfield-template-name" style="text-align:center"></td>');
        hc.push('<td style="text-align:right"><button name="next" type="button" class="need-tooltip" data-tooltip="Next template">&gt;</button></td>');
        hc.pop();
        hc.push('<div class="newreviewfield-template" style="width:500px;max-width:90%;min-height:6em"></div>');
        hc.push_actions(['<button type="submit" name="add" class="btn-primary want-focus">Create field</button>',
            '<button type="button" name="cancel">Cancel</button>']);
        $d = hc.show();
        render_template();
        $d.find(".newreviewfield-template-name").on("click", change_template);
        $d.on("click", "button", click);
        $d.find("form").on("submit", submit);
    }
    create();
}

handle_ui.on("js-settings-add-review-field", function () {
    var has_options = hasClass(this, "score"), fid;
    // prefer recently removed fields
    var i = 0, x = [];
    for (var $n = $("#reviewform_removedcontainer")[0].firstChild;
         $n && $n.hasAttribute("data-revfield"); $n = $n.nextSibling) {
        x.push([$n.getAttribute("data-revfield"), i]);
        ++i;
    }
    // otherwise prefer fields that have ever been defined
    for (fid in original)
        if ($.inArray(fid, fieldorder) < 0) {
            x.push([fid, i + (original[fid].name && original[fid].name !== "Field name" ? 0 : 1000)]);
            ++i;
        }
    // find a field
    x.sort(function (a, b) { return a[1] - b[1]; });
    for (i = 0; i != x.length; ++i)
        if (!has_options === (x[i][0].charAt(0) === "t"))
            return add_dialog(x[i][0]);
    // no field found, so add one
    var ffmt = has_options ? "s%02d" : "t%02d";
    for (i = 1; ; ++i) {
        fid = sprintf(ffmt, i);
        if ($.inArray(fid, fieldorder) < 0)
            break;
    }
    original[fid] = has_options ? stemplate : ttemplate;
    return add_dialog(fid);
});

return rfs;
})();


handle_ui.on("js-settings-resp-round-new", function () {
    var i, j;
    for (i = 1; jQuery("#response_" + i).length; ++i)
        /* do nothing */;
    jQuery("#response_n").before("<div id=\"response_" + i + "\" class=\"form-g\"></div>");
    j = jQuery("#response_" + i);
    j.html(jQuery("#response_n").html().replace(/_n\"/g, "_" + i + "\""));
    j.find("textarea").css({height: "auto"}).autogrow().val(jQuery("#response_n textarea").val());
    j.find(".need-suggest").each(suggest);
    return false;
});


hotcrp.settings = {
    review_form: review_form_settings,
    review_round: review_round_settings
};
