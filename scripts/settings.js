// settings.js -- HotCRP JavaScript library for settings
// HotCRP is Copyright (c) 2006-2015 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

function do_option_type(e, nohilite) {
    var m;
    if (!nohilite)
        hiliter(e);
    if ((m = e.name.match(/^optvt(.*)$/))) {
        fold("optv" + m[1], e.value != "selector" && e.value != "radio");
        fold("optvis" + m[1], !/:final/.test(e.value), 2);
        fold("optvis" + m[1], e.value != "pdf:final", 3);
    }
}


function settings_add_track() {
    var i, h, j;
    for (i = 1; jQuery("#trackgroup" + i).length; ++i)
        /* do nothing */;
    jQuery("#trackgroup" + (i - 1)).after("<div id=\"trackgroup" + i + "\"></div>");
    j = jQuery("#trackgroup" + i);
    j.html(jQuery("#trackgroup0").html().replace(/_track0/g, "_track" + i));
    hiliter_children(j);
    j.find("input[hottemptext]").each(mktemptext);
}


window.review_round_settings = (function () {
var added = 0;

function namechange() {
    var roundnum = this.id.substr(10), name = jQuery.trim(jQuery(this).val());
    jQuery("#rev_roundtag_" + roundnum).text(name === "" ? "(no name)" : name);
}

function init() {
    jQuery("#roundtable input[type=text]").on("input change", namechange);
}

function add() {
    var i, h, j;
    for (i = 1; jQuery("#roundname_" + i).length; ++i)
        /* do nothing */;
    jQuery("#round_container").show();
    jQuery("#roundtable").append(jQuery("#newround").html().replace(/\$/g, i));
    if (++added == 1 && i == 1)
        jQuery("div[hotroundnum=" + i + "] > :first-child").append('<div class="hint">Example name: “R1”</div>');
    jQuery("#rev_roundtag").append('<option value="#' + i + '" id="rev_roundtag_' + i + '">(new round)</option>');
    jQuery("div[hotroundnum=" + i + "] input.temptext").each(mktemptext);
    jQuery("#roundname_" + i).focus().on("input change", namechange);
}

function kill(e) {
    var divj = jQuery(e).closest("div[hotroundnum]"),
        roundnum = divj.attr("hotroundnum"),
        vj = divj.find("input[name=deleteround_" + roundnum + "]"),
        ej = divj.find("input[name=roundname_" + roundnum + "]");
    if (vj.val()) {
        vj.val("");
        ej.val(ej.attr("hotroundname"));
        ej.removeClass("dim").prop("disabled", false);
        jQuery(e).html("Delete round");
    } else {
        vj.val(1);
        var x = ej.val();
        ej.attr("hotroundname", x);
        ej.val(x == "(no name)" ? "(deleted)" : "(" + ej.val() + " deleted)")
            .addClass("dim").prop("disabled", true);
        jQuery(e).html("Restore round");
    }
    divj.find("table").toggle(!vj.val());
    hiliter(e);
}

return {init: init, add: add, kill: kill};
})();


window.review_form_settings = (function () {
var fieldmap, fieldorder, original, samples,
    colors = ["sv", "Red to green", "svr", "Green to red",
              "sv-blpu", "Blue to purple", "sv-publ", "Purple to blue"];

function get_fid(elt) {
    return elt.id.replace(/^.*_/, "");
}

function options_to_text(fieldj) {
    var cc = 49, ccdelta = 1, i, t = [];
    if (!fieldj.options)
        return "";
    if (fieldj.option_letter) {
        cc = fieldj.option_letter.charCodeAt(0) + fieldj.options.length - 1;
        ccdelta = -1;
    }
    for (i = 0; i != fieldj.options.length; ++i, cc += ccdelta)
        t.push(String.fromCharCode(cc) + ". " + fieldj.options[i]);
    fieldj.option_letter && t.reverse();
    t.length && t.push(""); // get a trailing newline
    return t.join("\n");
}

function set_position(fid, pos) {
    var i, t = "", p;
    for (i = 0; i != fieldorder.length; ++i)
        t += "<option value='" + (i + 1) + "'>" + ordinal(i + 1) + "</option>";
    $("#order_" + fid).html(t).val(pos);
}

function option_class_prefix(fieldj) {
    var sv = fieldj.option_class_prefix || "sv";
    if (fieldj.option_letter)
        sv = colors[(colors.indexOf(sv) || 0) ^ 2];
    return sv;
}

function check_change(fid) {
    var fieldj = original[fid] || {}, j, sv,
        removed = $("#removed_" + fid).val() != "0";
    if ($.trim($("#shortName_" + fid).val()) != fieldj.name
        || $("#order_" + fid).val() != (fieldj.position || 0)
        || $("#description_" + fid).val() != (fieldj.description || "")
        || $("#authorView_" + fid).val() != (fieldj.view_score || "pc")
        || $.trim($("#options_" + fid).val()) != $.trim(options_to_text(fieldj))
        || ((j = $("#option_class_prefix_" + fid)) && j.length
            && j.val() != option_class_prefix(fieldj))
        || removed) {
        $("#revfield_" + fid + " .revfield_revert").show();
        hiliter("reviewform_container");
    } else
        $("#revfield_" + fid + " .revfield_revert").hide();
    fold("revfield_" + fid, removed);
}

function check_this_change() {
    check_change(get_fid(this));
}

function fill_field(fid, fieldj) {
    if (fid instanceof Node)
        fid = get_fid(fid);
    fieldj = fieldj || original[fid] || {};
    $("#shortName_" + fid).val(fieldj.name || "");
    if (!fieldj.selector || fieldj.position) // don't remove if sample
        set_position(fid, fieldj.position || 0);
    $("#description_" + fid).val(fieldj.description || "");
    $("#authorView_" + fid).val(fieldj.view_score || "pc");
    $("#options_" + fid).val(options_to_text(fieldj));
    $("#option_class_prefix_flipped_" + fid).val(fieldj.option_letter ? "1" : "");
    $("#option_class_prefix_" + fid).val(option_class_prefix(fieldj));
    if (!fieldj.selector)
        $("#removed_" + fid).val(fieldj.position ? 0 : 1);
    check_change(fid);
    return false;
}

function revert() {
    fill_field(this);
    $("#samples_" + get_fid(this)).val("x");
}

function remove() {
    var fid = get_fid(this);
    $("#removed_" + fid).val(1);
    check_change(fid);
}

function samples_change() {
    var val = $(this).val();
    if (val == "original")
        fill_field(this);
    else if (val != "x")
        fill_field(this, samples[val]);
}

var revfield_template = '<div id="revfield_$" class="settings_revfield f-contain foldo errloc_$">\
  <div class="f-i errloc_shortName_$">\
    <div class="f-c">Field name</div><input name="shortName_$" id="shortName_$" type="text" size="50" style="font-weight:bold" />\
  </div>\
  <div class="f-i fx">\
    <div class="f-ix">\
      <div class="f-c">Form position</div>\
      <select name="order_$" id="order_$" class="reviewfield_order"></select>\
      <span class="fn"><span class="sep"></span><button id="revert2_$" type="button" class="revfield_revert">Button</button></span>\
    </div>\
    <div class="f-ix">\
      <div class="f-c">Visibility</div>\
      <select name="authorView_$" id="authorView_$" class="reviewfield_authorView">\
        <option value="author">Authors &amp; reviewers</option>\
        <option value="pc">Reviewers only</option>\
        <option value="admin">Administrators only</option>\
      </select>\
    </div>\
    <div class="f-ix reviewrow_options">\
      <div class="f-c">Colors</div>\
      <select name="option_class_prefix_$" id="option_class_prefix_$" class="reviewfield_option_class_prefix"></select>\
<input type="hidden" name="option_class_prefix_flipped_$" id="option_class_prefix_flipped_$" value="" />\
    </div>\
    <hr class="c" />\
  </div>\
  <div class="f-i errloc_description_$ fx">\
    <div class="f-c">Description</div>\
    <textarea name="description_$" id="description_$" class="reviewtext hottooltip" rows="6" hottooltipcontent="#review_form_caption_description" hottooltipdir="l" hottooltiptype="focus"></textarea>\
  </div>\
  <div class="f-i errloc_options_$ fx reviewrow_options">\
    <div class="f-c">Options</div>\
    <textarea name="options_$" id="options_$" class="reviewtext hottooltip" rows="6" hottooltipcontent="#review_form_caption_options" hottooltipdir="l" hottooltiptype="focus"></textarea>\
  </div>\
  <div class="f-i">\
    <select name="samples_$" id="samples_$" class="revfield_samples fx"></select>\
    <span class="fx"><span class="sep"></span><button id="remove_$" class="revfield_remove" type="button">Remove field from form</button></span>\
<span class="fn" style="font-style:italic">Removed from form</span>\
<input type="hidden" name="removed_$" id="removed_$" value="0" />\
<span class="sep"></span><button id="revert_$" class="revfield_revert" style="display:none" type="button">Revert</button>\
  </div>\
</div>';

function append_field(fid) {
    var jq = $(revfield_template.replace(/\$/g, fid)), i;

    if (fieldmap[fid]) {
        for (i = 0; i < colors.length; i += 2)
            jq.find(".reviewfield_option_class_prefix").append("<option value=\"" + colors[i] + "\">" + colors[i+1] + "</option>");
    } else
        jq.find(".reviewrow_options").remove();

    var sampleopt = "<option value=\"x\">Load field from library...</option>";
    for (i = 0; i != samples.length; ++i)
        if (!samples[i].options == !fieldmap[fid])
            sampleopt += "<option value=\"" + i + "\">" + samples[i].selector + "</option>";
    jq.find(".revfield_samples").html(sampleopt).on("change", samples_change);

    jq.find(".revfield_remove").on("click", remove);
    jq.find(".revfield_revert").on("click", revert);
    jq.find("input, textarea, select").on("change", check_this_change);
    jq.find("textarea").autogrow();
    jq.appendTo("#reviewform_container");
    fill_field(fid, original[fid]);
}

function rfs(fieldmapj, originalj, samplesj, errors, request) {
    var i, fid;
    fieldmap = fieldmapj;
    original = originalj;
    samples = samplesj;

    fieldorder = [];
    for (fid in original)
        if (original[fid].position)
            fieldorder.push(fid);
    fieldorder.sort(function (a, b) {
        return original[a].position - original[b].position;
    });

    // construct form
    for (i = 0; i != fieldorder.length; ++i)
        append_field(fieldorder[i]);

    // highlight errors, apply request
    for (i in request || {}) {
        if (!$("#" + i).length)
            rfs.add(false, i.replace(/^.*_/, ""));
        $("#" + i).val(request[i]);
        hiliter("reviewform_container");
    }
    for (i in errors || {})
        $(".errloc_" + i).addClass("error");
};

function do_add(fid) {
    fieldorder.push(fid);
    original[fid] = original[fid] || {};
    original[fid].position = fieldorder.length;
    append_field(fid);
    $(".reviewfield_order").each(function () {
        var xfid = get_fid(this);
        if (xfid != "$")
            set_position(xfid, $(this).val());
    });
    hiliter("reviewform_container");
    return true;
}

rfs.add = function (has_options, fid) {
    if (fid)
        return do_add(fid);
    for (fid in fieldmap)
        if (!fieldmap[fid] == !has_options
            && $.inArray(fid, fieldorder) < 0)
            return do_add(fid);
    alert("You’ve reached the maximum number of " + (has_options ? "score fields." : "text fields."));
};

return rfs;
})();


function settings_add_resp_round() {
    var i, j;
    for (i = 1; jQuery("#response_" + i).length; ++i)
        /* do nothing */;
    jQuery("#response_n").before("<div id=\"response_" + i + "\" style=\"padding-top:1em\"></div>");
    j = jQuery("#response_" + i);
    j.html(jQuery("#response_n").html().replace(/_n\"/g, "_" + i + "\""));
    hiliter_children(j);
    j.find("input[hottemptext]").each(mktemptext);
    j.find("textarea").css({height: "auto"}).autogrow().val(jQuery("#response_n textarea").val());
    return false;
}
