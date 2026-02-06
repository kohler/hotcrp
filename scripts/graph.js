// graph.js -- HotCRP JavaScript library for graph drawing
// Copyright (c) 2006-2026 Eddie Kohler; see LICENSE.

/* global hotcrp, siteinfo */
hotcrp.graph = (function ($, d3) {
const $$ = hotcrp.$$,
    $e = hotcrp.$e,
    $frag = hotcrp.$frag,
    $svg = hotcrp.$svg,
    ensure_pattern = hotcrp.ensure_pattern,
    feedback = hotcrp.feedback,
    handle_ui = hotcrp.handle_ui,
    hasClass = hotcrp.classes.has,
    hoturl = hotcrp.hoturl,
    log_jserror = hotcrp.log_jserror,
    make_bubble = hotcrp.make_bubble,
    strftime = hotcrp.text.strftime;

let BOTTOM_MARGIN = 37;
const PATHSEG_ARGMAP = {
    m: 2, M: 2, z: 0, Z: 0, l: 2, L: 2, h: 1, H: 1, v: 1, V: 1, c: 6, C: 6,
    s: 4, S: 4, q: 4, Q: 4, t: 2, T: 2, a: 7, A: 7, b: 1, B: 1
};
let normalized_path_cache = {}, normalized_path_cache_size = 0;

function svg_path_number_of_items(s) {
    if (s instanceof SVGPathElement) {
        s = s.getAttribute("d");
    }
    if (normalized_path_cache[s]) {
        return normalized_path_cache[s].length;
    } else {
        return s.replace(/[^A-DF-Za-df-z]+/g, "").length;
    }
}

function make_svg_path_parser(s) {
    if (s instanceof SVGPathElement) {
        s = s.getAttribute("d");
    }
    s = s.split(/([a-zA-Z]|[-+]?(?:\d+\.?\d*|\.\d+)(?:[Ee][-+]?\d+)?)/);
    let i = 1, e = s.length, next_cmd;
    return function () {
        let a = null;
        while (i < e) {
            const ch = s[i];
            if (ch >= "A") {
                if (a)
                    break;
                a = [ch];
                next_cmd = ch;
                if (ch === "m" || ch === "M" || ch === "z" || ch === "Z")
                    next_cmd = (ch === "m" || ch === "z" ? "l" : "L");
            } else {
                if (!a && next_cmd)
                    a = [next_cmd];
                else if (!a || a.length === PATHSEG_ARGMAP[a[0]] + 1)
                    break;
                a.push(+ch);
            }
            i += 2;
        }
        return a;
    };
}

let normalize_path_complaint = false;
function normalize_svg_path(s) {
    if (s instanceof SVGPathElement) {
        s = s.getAttribute("d");
    }
    if (normalized_path_cache[s]) {
        return normalized_path_cache[s];
    }

    let res = [],
        cx = 0, cy = 0, cx0 = 0, cy0 = 0, copen = false,
        cb = 0, sincb = 0, coscb = 1,
        i, dx, dy,
        parser = make_svg_path_parser(s), a, ch, preva;
    while ((a = parser())) {
        ch = a[0];
        // special commands: bearing, closepath
        if (ch === "b" || ch === "B") {
            cb = ch === "b" ? cb + a[1] : a[1];
            coscb = Math.cos(cb);
            sincb = Math.sin(cb);
            continue;
        } else if (ch === "z" || ch === "Z") {
            preva = res.length ? res[res.length - 1] : null;
            if (copen) {
                if (cx != cx0 || cy != cy0)
                    res.push(["L", cx, cy, cx0, cy0]);
                res.push(["Z"]);
                copen = false;
            }
            cx = cx0, cy = cy0;
            continue;
        }

        // normalize command 1: remove horiz/vert
        if (PATHSEG_ARGMAP[ch] == 1) {
            if (a.length == 1)
                a = ["L"]; // all data is missing
            else if (ch === "h")
                a = ["l", a[1], 0];
            else if (ch === "H")
                a = ["L", a[1], cy];
            else if (ch === "v")
                a = ["l", 0, a[1]];
            else if (ch === "V")
                a = ["L", cx, a[1]];
        }

        // normalize command 2: relative -> absolute
        ch = a[0];
        if (ch >= "a" && !cb) {
            for (i = ch !== "a" ? 1 : 6; i < a.length; i += 2) {
                a[i] += cx;
                a[i+1] += cy;
            }
        } else if (ch >= "a") {
            if (ch === "a")
                a[3] += cb;
            for (i = ch !== "a" ? 1 : 6; i < a.length; i += 2) {
                dx = a[i], dy = a[i + 1];
                a[i] = cx + dx * coscb + dy * sincb;
                a[i+1] = cy + dx * sincb + dy * coscb;
            }
        }
        ch = a[0] = ch.toUpperCase();

        // normalize command 3: use cx0,cy0 for missing data
        while (a.length < PATHSEG_ARGMAP[ch] + 1)
            a.push(cx0, cy0);

        // normalize command 4: shortcut -> full
        if (ch === "S") {
            dx = dy = 0;
            if (preva && preva[0] === "C")
                dx = cx - preva[3], dy = cy - preva[4];
            a = ["C", cx + dx, cy + dy, a[1], a[2], a[3], a[4]];
            ch = "C";
        } else if (ch === "T") {
            dx = dy = 0;
            if (preva && preva[0] === "Q")
                dx = cx - preva[1], dy = cy - preva[2];
            a = ["Q", cx + dx, cy + dy, a[1], a[2]];
            ch = "Q";
        }

        // process command
        if (!copen && ch !== "M") {
            res.push(["M", cx, cy]);
            copen = true;
        }
        if (ch === "M") {
            cx0 = a[1];
            cy0 = a[2];
            copen = false;
        } else if (ch === "L") {
            res.push(["L", cx, cy, a[1], a[2]]);
        } else if (ch === "C") {
            res.push(["C", cx, cy, a[1], a[2], a[3], a[4], a[5], a[6]]);
        } else if (ch === "Q") {
            res.push(["C", cx, cy,
                      cx + 2 * (a[1] - cx) / 3, cy + 2 * (a[2] - cy) / 3,
                      a[3] + 2 * (a[1] - a[3]) / 3, a[4] + 2 * (a[2] - a[4]) / 3,
                      a[3], a[4]]);
        } else {
            // XXX should render "A" as a bezier
            if (++normalize_path_complaint == 1)
                log_jserror("bad normalize_svg_path " + ch);
            res.push(a);
        }

        preva = a;
        cx = a[a.length - 2];
        cy = a[a.length - 1];
    }

    if (normalized_path_cache_size >= 1000) {
        normalized_path_cache = {};
        normalized_path_cache_size = 0;
    }
    normalized_path_cache[s] = res;
    ++normalized_path_cache_size;
    return res;
}

function pathNodeMayBeNearer(pathNode, point, dist) {
    function oob(l, t, r, b) {
        return l - point[0] >= dist || point[0] - r >= dist
            || t - point[1] >= dist || point[1] - b >= dist;
    }
    // check bounding rectangle of path
    if ("clientX" in point) {
        const bounds = pathNode.getBoundingClientRect(),
            dx = point[0] - point.clientX, dy = point[1] - point.clientY;
        if (bounds && oob(bounds.left + dx, bounds.top + dy,
                          bounds.right + dx, bounds.bottom + dy))
            return false;
    }
    // check path
    const npsl = normalize_svg_path(pathNode);
    let l, t, r, b;
    for (const item of npsl) {
        if (item[0] === "L") {
            l = Math.min(item[1], item[3]);
            t = Math.min(item[2], item[4]);
            r = Math.max(item[1], item[3]);
            b = Math.max(item[2], item[4]);
        } else if (item[0] === "C") {
            l = Math.min(item[1], item[3], item[5], item[7]);
            t = Math.min(item[2], item[4], item[6], item[8]);
            r = Math.max(item[1], item[3], item[5], item[7]);
            b = Math.max(item[2], item[4], item[6], item[8]);
        } else if (item[0] === "Z" || item[0] === "M") {
            continue;
        } else {
            return true;
        }
        if (!oob(l, t, r, b)) {
            return true;
        }
    }
    return false;
}

function closestPoint(pathNode, point, inbest) {
    // originally from Mike Bostock http://bl.ocks.org/mbostock/8027637
    if (inbest && !pathNodeMayBeNearer(pathNode, point, inbest.distance))
        return inbest;

    let pathLength = pathNode.getTotalLength(),
        precision = Math.max(pathLength / svg_path_number_of_items(pathNode) * .125, 3),
        best, bestLength, bestDistance2 = Infinity;

    function check(pLength) {
        const p = pathNode.getPointAtLength(pLength),
            dx = point[0] - p.x, dy = point[1] - p.y,
            d2 = dx * dx + dy * dy;
        if (d2 >= bestDistance2) {
            return false;
        }
        best = [p.x, p.y];
        best.pathNode = pathNode;
        bestLength = pLength;
        bestDistance2 = d2;
        return true;
    }

    // linear scan for coarse approximation
    for (let sl = 0; sl <= pathLength; sl += precision) {
        check(sl);
    }

    // binary search for precise estimate
    precision *= .5;
    while (precision > .5) {
        const bl0 = bestLength - precision;
        if (bl0 < 0 || !check(bl0)) {
            const bl1 = bestLength + precision;
            if (bl1 > pathLength || !check(bl1)) {
                precision *= 0.5;
            }
        }
    }

    best.distance = Math.sqrt(bestDistance2);
    best.pathLength = bestLength;
    return inbest && inbest.distance < best.distance + 0.01 ? inbest : best;
}

function tangentAngle(pathNode, length) {
    const length0 = Math.max(0, length - 0.25);
    if (length0 == length) {
        length += 0.25;
    }
    const p0 = pathNode.getPointAtLength(length0),
        p1 = pathNode.getPointAtLength(length);
    return Math.atan2(p1.y - p0.y, p1.x - p0.x);
}


/* CDF functions */
function procrastination_seq(ri, dl) {
    const seq = [];
    for (const rf of ri) {
        if (rf[0] > 0)
            seq.push((rf[0] - dl[rf[1]]) / 86400);
    }
    seq.ntotal = ri.length;
    return seq;
}
procrastination_seq.label = function (dl) {
    return dl.length > 1 ? "Days until round deadline" : "Days until deadline";
};

function max_procrastination_seq(ri, dl) {
    const now = (new Date).getTime() / 1000;
    let dlx;
    for (const d of dl) {
        if (dlx == null
            || (d > now ? d < dlx || dlx < now : d > dlx))
            dlx = d;
    }
    const seq = [];
    for (const r of ri) {
        if (r[0] > 0)
            seq.push((r[0] - dlx) / 86400);
    }
    seq.ntotal = ri.length;
    return seq;
}
max_procrastination_seq.label = function (dl) {
    return dl.length > 1 ? "Days until maximum deadline" : "Days until deadline";
};
procrastination_seq.tickFormat = max_procrastination_seq.tickFormat =
    function (x) { return -x; };

function seq_to_cdf(seq, flip, raw) {
    const cdf = [], n = seq.ntotal || seq.length;
    seq.sort(flip ? d3.descending : d3.ascending);
    for (let i = 0; i <= seq.length; ++i) {
        const y = raw ? i : i/n;
        if (i != 0 && (i == seq.length || seq[i-1] != seq[i]))
            cdf.push([seq[i-1], y]);
        if (i != seq.length && (i == 0 || seq[i-1] != seq[i]))
            cdf.push([seq[i], y]);
    }
    cdf.cdf = true;
    return cdf;
}


function expand_extent(e, args) {
    let l = e[0], h = e[1];
    if (l > 0 && l < h / 11) {
        l = 0;
    } else if (l > 0 && args.discrete) {
        l -= 0.5;
    }
    if (h - l < 10) {
        const delta = Math.min(1, h - l) * 0.2;
        if (args.orientation !== "y" || l > 0) {
            l -= delta;
        }
        h += delta;
    }
    if (args.discrete) {
        h += 0.5;
    }
    return [l, h];
}


function draw_axes(svg, xAxis, yAxis, args) {
    const parent = d3.select(svg.node().parentElement);

    const xaxe = parent.append("g")
        .attr("class", "x-axis")
        .attr("transform", `translate(${args.marginLeft},${args.marginTop+args.plotHeight})`)
        .call(xAxis)
        .attr("font-family", null)
        .attr("font-size", null)
        .attr("fill", null)
        .call(make_rotate_ticks(args.x.tickRotation));
    if (args.x.label) {
        xaxe.append("text")
            .attr("class", "label")
            .attr("x", args.plotWidth)
            .attr("y", args.marginBottom - 3)
            .attr("text-anchor", "end")
            .attr("pointer-events", "none")
            .text(`${args.x.label} →`);
    }
    xaxe.select(".domain").each(function () {
        const d = this.getAttribute("d");
        this.setAttribute("d", d.replace(/^M([^A-Z]*),([^A-Z]*)V0H([^A-Z]*)V([^A-Z]*)$/,
            function (m, x1, y1, x2, y2) {
                return y1 === y2 ? "M".concat(x1, ",0H", x2) : m;
            }));
    });

    const yaxe = parent.append("g")
        .attr("class", "y-axis")
        .attr("transform", `translate(${args.marginLeft},${args.marginTop})`)
        .call(yAxis)
        .attr("font-family", null)
        .attr("font-size", null)
        .attr("fill", null)
        .call(make_rotate_ticks(args.y.tickRotation));
    if (args.y.label) {
        yaxe.append("text")
            .attr("class", "label")
            .attr("x", -args.marginLeft)
            .attr("y", -14)
            .attr("text-anchor", "start")
            .attr("pointer-events", "none")
            .text(`↑ ${args.y.label}`);
    }
    yaxe.select(".domain").remove();
    /*args.y.discrete && yaxe.select(".domain").each(function () {
        var d = this.getAttribute("d");
        this.setAttribute("d", d.replace(/^M([^A-Z]*),([^A-Z]*)H0V([^A-Z]*)H([^A-Z]*)$/,
            function (m, x1, y1, y2, x2) {
                return x1 === x2 ? "M0,".concat(y1, "V", y2) : m;
            }));
    });*/

    args.x.ticks.rewrite.call(xaxe, svg);
    args.y.ticks.rewrite.call(yaxe, svg);
}

function proj0(d) {
    return d[0];
}

function proj1(d) {
    return d[1];
}

function projx(d) {
    return d.x;
}

function projy(d) {
    return d.y;
}

function id2pid(id) {
    if (typeof id === "string") {
        return parseInt(id, 10);
    }
    return id;
}

function pid_sorter(a, b) {
    if (typeof a === "object") {
        a = a.id || a[2];
    }
    if (typeof b === "object") {
        b = b.id || b[2];
    }
    const d = id2pid(a) - id2pid(b);
    return d ? d : (a < b ? -1 : (a == b ? 0 : 1));
}

function render_pid_p(ps, cc) {
    ps.sort(pid_sorter);
    const e = $e("p");
    for (let i = 0; i !== ps.length; ++i) {
        let p = ps[i], cx = cc, rest = "";
        if (typeof p === "object") {
            if (p.id) {
                rest = p.rest;
                cx = p.cc;
                p = p.id;
            } else {
                cx = p[3];
                p = p[2];
            }
        }
        const comma = i === ps.length - 1 ? "" : ",";
        let pe = "#" + p;
        if (cx) {
            ensure_pattern(cx);
            pe = $e("span", cx, pe);
        }
        i > 0 && e.append(" ");
        if (rest || cx) {
            e.append($e("span", "nw", pe, rest, comma));
        } else {
            e.append(pe + comma);
        }
    }
    e.normalize();
    return e;
}

function clicker(pids, event) {
    if (!pids) {
        return;
    }
    if (typeof pids !== "object") {
        pids = [pids];
    }
    let x = [], last_review = null;
    for (let p of pids) {
        if (typeof p === "object") {
            p = p.id;
        }
        if (typeof p === "string") {
            last_review = p;
            p = parseInt(p, 10);
        }
        x.push(p);
    }
    if (x.length === 1 && pids.length === 1 && last_review !== null) {
        clicker_go(hoturl("paper", {p: x[0], anchor: "r" + last_review}), event);
    } else if (x.length === 1) {
        clicker_go(hoturl("paper", {p: x[0]}), event);
    } else {
        x = Array.from(new Set(x).values());
        x.sort(pid_sorter);
        clicker_go(hoturl("search", {q: x.join(" ")}), event);
    }
}

function make_reviewer_clicker(email) {
    return function (event) {
        clicker_go(hoturl("search", {q: "re:" + email}), event);
    };
}

function clicker_go(url, event) {
    if (event && event.metaKey) {
        window.open(url, "_blank", "noopener");
    } else {
        window.location = url;
    }
}

const default_axis = {
    make_axis: numeric_make_axis,
    rewrite: function () {},
    render_onto: function (e, value) {
        e.append(this.scale.tickFormat()(value));
    },
    search: function () { return null; }
};

function make_ticks(ticks) {
    if (ticks && ticks[0] === "named") {
        ticks = named_integer_ticks(ticks[1]);
    } else if (ticks && ticks[0] === "score") {
        ticks = score_ticks(hotcrp.make_review_field(ticks[1]));
    } else if (ticks && ticks[0] === "time") {
        ticks = time_ticks();
    } else {
        ticks = {type: ticks ? ticks[0] : null};
    }
    return $.extend({}, default_axis, ticks);
}

function make_linear_scale(argextent, e) {
    if (argextent && argextent[0] != null) {
        e = [argextent[0], e[1]];
    }
    if (argextent && argextent[1] != null) {
        e = [e[0], argextent[1]];
    }
    return d3.scaleLinear().domain(e);
}

function render_position(aa, p, prefix) {
    const e = $e("span", "nw");
    if (prefix || aa.label) {
        e.append((prefix || "") + (aa.label ? aa.label + " " : ""));
    }
    aa.ticks.render_onto.call(aa, e, p, true);
    return e;
}


// args: {data: [{d: [ARRAY], label: STRING, className: STRING}],
//        x/y: {label: STRING, tickFormat: STRING}}
function graph_cdf(element, args) {
    const svg = this;

    // massage data
    let series = args.data;
    if (!series.length) {
        series = Object.values(series);
        series.sort(function (a, b) {
            return d3.ascending(a.priority || 0, b.priority || 0);
        });
    }
    series = series.filter(function (d) {
        return (d.d ? d.d : d).length > 0;
    });
    const data = series.map(function (d) {
        d = d.d ? d.d : d;
        return d.cdf ? d : seq_to_cdf(d, args.x.flip, args.y.raw);
    });

    // axis domains
    let xdomain = data.reduce(function (e, d) {
        e[0] = Math.min(e[0], d[0][0], d[d.length - 1][0]);
        e[1] = Math.max(e[1], d[0][0], d[d.length - 1][0]);
        return e;
    }, [Infinity, -Infinity]);
    xdomain = [xdomain[0] - (xdomain[1] - xdomain[0]) / 32,
               xdomain[1] + (xdomain[1] - xdomain[0]) / 32];
    const x = make_linear_scale(args.x.extent, xdomain),
        y = make_linear_scale(args.y.extent, [0, Math.ceil(d3.max(data, function (d) {
                return d[d.length - 1][1];
            }) * 10) / 10]),
        axes = make_axis_pair(args, x, y);

    // lines
    const line = d3.line().x(function (d) {return x(d[0]);})
        .y(function (d) {return y(d[1]);});

    // CDF lines
    data.forEach(function (d, i) {
        var cl = series[i].className;
        if (d[d.length - 1][0] != xdomain[args.x.flip ? 0 : 1])
            d.push([xdomain[args.x.flip ? 0 : 1], d[d.length - 1][1]]);
        var p = svg.append("path").attr("data-index", i)
            .datum(d)
            .attr("class", "gcdf" + (cl ? " " + cl : ""))
            .attr("stroke", ensure_pattern(series[i].className, "gcdf"))
            .attr("d", line);
        if (series[i].dashpattern)
            p.attr("stroke-dasharray", series[i].dashpattern.join(","));
    });

    svg.append("path").attr("class", "gcdf gcdf-hover0");
    svg.append("path").attr("class", "gcdf gcdf-hover1");
    const hovers = svg.selectAll(".gcdf-hover0, .gcdf-hover1");
    hovers.style("display", "none");

    draw_axes(svg, axes[0], axes[1], args);

    svg.append("rect")
        .attr("x", -args.marginLeft)
        .attr("width", args.plotWidth + args.marginLeft)
        .attr("height", args.plotHeight + args.marginBottom)
        .attr("fill", "none")
        .attr("pointer-events", "all")
        .on("mouseover", mousemoved)
        .on("mousemove", mousemoved)
        .on("mouseout", mouseout)
        .on("click", mouseclick);

    var hovered_path, hovered_series, hubble;
    function mousemoved(event) {
        var m = d3.pointer(event), p = {distance: 16};
        m.clientX = event.clientX;
        m.clientY = event.clientY;
        for (var i in data) {
            if (series[i].label || args.cdf_tooltip_position)
                p = closestPoint(svg.select("[data-index='" + i + "']").node(), m, p);
        }
        if (p.pathNode !== hovered_path) {
            if (p.pathNode) {
                i = p.pathNode.getAttribute("data-index");
                hovered_series = series[i];
                hovers.datum(data[i]).attr("d", line).style("display", null);
            } else {
                hovered_series = null;
                hovers.style("display", "none");
            }
            hovered_path = p.pathNode;
        }
        if (hovered_series && (hovered_series.label || args.cdf_tooltip_position)) {
            hubble = hubble || make_bubble({class: args.tooltip_class || "graphtip", "pointer-events": "none"});
            var dir = Math.abs(tangentAngle(p.pathNode, p.pathLength));
            if (args.cdf_tooltip_position) {
                const f = $frag();
                hovered_series.label && f.append(hovered_series.label + " ");
                args.x.ticks.render_onto.call(args.x, f, x.invert(p[0]), true);
                f.append(", ");
                args.y.ticks.render_onto.call(args.y, f, y.invert(p[1]), true);
                hubble.replace_content(f);
            } else {
                hubble.text(hovered_series.label);
            }
            hubble.anchor(dir >= 0.25*Math.PI && dir <= 0.75*Math.PI ? "e" : "s")
                .at(p[0] + args.marginLeft, p[1], this);
        } else if (hubble) {
            hubble = hubble.remove() && null;
        }
    }

    function mouseout() {
        hovers.style("display", "none");
        hubble && hubble.remove();
        hovered_path = hovered_series = hubble = null;
    }

    function mouseclick(evt) {
        if (hovered_series && hovered_series.click)
            hovered_series.click.call(this, evt);
    }
}


function procrastination_filter(revdata) {
    const args = {type: "cdf", data: {}, x: {}, y: {}, tooltip_class: "graphtip dark"};

    // collect data
    const alldata = [];
    for (const cid in revdata.reviews) {
        const d = {d: revdata.reviews[cid], className: "gcdf-many"},
            u = revdata.users[cid];
        u && u.name && (d.label = u.name);
        u && u.email && (d.click = make_reviewer_clicker(u.email));
        if (cid && cid == siteinfo.user.uid) {
            d.className = "gcdf-highlight";
            d.priority = 1;
        } else if (u && u.light) {
            d.className += " gcdf-thin";
        }
        u && u.color_classes && (d.className += " " + u.color_classes);
        Array.prototype.push.apply(alldata, d.d);
        if (cid !== "conflicts") {
            args.data[cid] = d;
        }
    }
    args.data.all = {d: alldata, className: "gcdf-cumulative", priority: 2};

    var dlf = max_procrastination_seq;

    // infer deadlines when not set
    for (const i in revdata.deadlines) {
        if (revdata.deadlines[i]) {
            continue;
        }
        const subat = alldata.filter(function (d) { return (d[2] || 0) == i; })
            .map(proj0);
        subat.sort(d3.ascending);
        revdata.deadlines[i] = subat.length ? d3.quantile(subat, 0.8) : 0;
    }
    // make cdfs
    for (const i in args.data) {
        args.data[i].d = seq_to_cdf(dlf(args.data[i].d, revdata.deadlines));
    }

    if (dlf.tickFormat) {
        args.x.tickFormat = dlf.tickFormat;
    }
    args.x.label = dlf.label(revdata.deadlines);
    args.y.label = "Fraction of assignments completed";
    return args;
}


/* grouped quadtree */
// mark bounds of each node
function grouped_quadtree_mark_bounds(q, rf, ordinalf) {
    //ordinalf = ordinalf || (function () { var m = 0; return function () { return ++m; }; })();
    //q.ordinal = ordinalf();

    let b, p, i, n, ps;
    if (!q.length) {
        for (p = q.data, ps = []; p; p = p.next) {
            ps.push(p);
        }
        ps.sort(function (a, b) { return d3.ascending(a.n, b.n); });
        for (i = n = 0; i < ps.length; ++i) {
            ps[i].r0 = i ? ps[i-1].r : 0;
            n += ps[i].n;
            ps[i].r = rf(n);
        }
        q.maxr = ps[ps.length - 1].r;
        p = q.data;
        b = [p[1] - q.maxr, p[0] + q.maxr, p[1] + q.maxr, p[0] - q.maxr];
    } else {
        b = [Infinity, -Infinity, -Infinity, Infinity];
        for (i = 0; i < 4; ++i)
            if ((p = q[i])) {
                grouped_quadtree_mark_bounds(p, rf, ordinalf);
                b[0] = Math.min(b[0], p.bounds[0]);
                b[1] = Math.max(b[1], p.bounds[1]);
                b[2] = Math.max(b[2], p.bounds[2]);
                b[3] = Math.min(b[3], p.bounds[3]);
            }
    }
    q.bounds = b;
}

function grouped_quadtree_gfind(point, min_distance) {
    var closest = null;
    if (min_distance == null)
        min_distance = Infinity;
    function visitor(node) {
        if (node.bounds[0] > point[1] + min_distance
            || node.bounds[1] < point[0] - min_distance
            || node.bounds[2] < point[1] - min_distance
            || node.bounds[3] > point[0] + min_distance)
            return true;
        if (node.length)
            return;
        let p = node.data;
        const dx = p[0] - point[0], dy = p[1] - point[1];
        if (Math.abs(dx) - node.maxr < min_distance
            || Math.abs(dy) - node.maxr < min_distance) {
            const dd = Math.sqrt(dx * dx + dy * dy);
            for (; p; p = p.next) {
                const d = Math.max(dd - p.r, 0);
                if (d < min_distance || (d == 0 && p.r < closest.r))
                    closest = p, min_distance = d;
            }
        }
    }
    this.visit(visitor);
    return closest;
}

function grouped_quadtree(data, xs, ys, rf, expand) {
    function make_extent() {
        const xe = xs.range(), ye = ys.range();
        return [[Math.min(xe[0], xe[1]), Math.min(ye[0], ye[1])],
                [Math.max(xe[0], xe[1]), Math.max(ye[0], ye[1])]];
    }
    const q = d3.quadtree().extent(make_extent()), nd = [];

    for (const d of data) {
        if (d[0] == null || d[1] == null) {
            continue;
        }
        const vd = {
            "0": xs(d[0]),
            "1": ys(d[1]),
            data: [d],
            cc: d[3],
            next: null,
            head: null,
            n: expand ? d[2].length : 1,
            i: nd.length,
            r0: null,
            r: null,
            ur: null
        };
        let vp = q.find(vd[0], vd[1]);
        if (vp) {
            const dx = Math.abs(vp[0] - vd[0]),
                dy = Math.abs(vp[1] - vd[1]);
            if (dx > 2 || dy > 2 || dx * dx + dy * dy > 4) {
                vp = null;
            }
        }
        while (vp && vp.cc != vd.cc && vp.next) {
            vp = vp.next;
        }
        if (vp && vp.cc == vd.cc) {
            vp.data.push(d);
            vp.n += vd.n;
        } else {
            if (vp) {
                vp.next = vd;
                vd.head = vp.head || vp;
            } else {
                q.add(vd);
            }
            nd.push(vd);
        }
    }

    if (rf == null) {
        rf = Math.sqrt;
    } else if (typeof rf === "number") {
        rf = (function (f) {
            return function (n) { return Math.sqrt(n) * f; };
        })(rf);
    }
    if (q.root()) {
        grouped_quadtree_mark_bounds(q.root(), rf);
    }

    delete q.add;
    q.gfind = grouped_quadtree_gfind;
    return {data: nd, quadtree: q};
}

function gqdata_ids(gqp, want_cc) {
    const a = [], cch = gqp.cc;
    for (; gqp; gqp = gqp.next) {
        for (const d of gqp.data) {
            const ids = typeof d[2] === "object" ? d[2] : [d[2]];
            if (want_cc && cch !== d[3]) {
                for (const id of ids) {
                    a.push({id: id, cc: d[3]});
                }
            } else {
                a.push(...ids);
            }
        }
    }
    return a;
}

function ungroup_data(data) {
    if ($.isArray(data)) {
        return data;
    }
    for (const style in data) {
        if (style) {
            data[style].forEach(d => d.push(style));
        }
    }
    return d3.merge(Object.values(data));
}

function remap_scatter_data(data, rv, map) {
    if (!rv.x || !rv.x.reordered || rv.x.ticks[0] !== "named")
        return;
    var ov2ok = {}, k;
    for (k in map) {
        if (typeof map[k] === "string")
            ov2ok[map[k]] = +k;
        else
            ov2ok[map[k].id] = +k;
    }
    var ik2ok = {}, inmap = rv.x.ticks[1];
    for (k in inmap) {
        if (typeof inmap[k] === "string") {
            if (ov2ok[inmap[k]] != null)
                ik2ok[k] = ov2ok[inmap[k]];
        } else {
            if (ov2ok[inmap[k].id] != null)
                ik2ok[k] = ov2ok[inmap[k].id];
        }
    }
    var n = data.length;
    for (var i = 0; i !== n; ) {
        var x = ik2ok[data[i][0]];
        if (x != null) {
            data[i][0] = x;
            ++i;
        } else {
            data[i] = data[n - 1];
            data.pop();
            --n;
        }
    }
}

const scatter_annulus = d3 ? d3.arc()
    .innerRadius(function (d) { return d.r0 ? d.r0 - 0.5 : 0; })
    .outerRadius(function (d) { return d.r - 0.5; })
    .startAngle(0)
    .endAngle(Math.PI * 2) : null;

const scatter_union_annulus = d3 ? d3.arc()
    .outerRadius(function (d) { return (d.ur || d.r) - 0.5; })
    .startAngle(0)
    .endAngle(Math.PI * 2) : null;

function scatter_transform(d) {
    return "translate(" + d[0] + "," + d[1] + ")";
}

function scatter_key(d) {
    return d[0] + "," + d[1] + "," + d.r;
}

function scatter_create(svg, gqdata, klass) {
    let sel = svg.selectAll(".gdot");
    if (klass)
        sel = sel.filter("." + klass);
    sel = sel.data(gqdata, scatter_key);
    sel.exit().remove();
    const pathklass = "gdot" + (klass ? " " + klass : "");
    sel.enter()
        .append("path")
        .attr("class", function (d) { return pathklass + (d.cc ? " " + d.cc : "") })
        .style("fill", function (d) { return ensure_pattern(d.cc, "gdot"); })
      .merge(sel)
        .attr("d", scatter_annulus)
        .attr("transform", scatter_transform);
    return sel;
}

function highlight_pattern() {
    if ($$("svggpat_dot_highlight")) {
        return;
    }
    $$("p-body").prepend($svg("svg", {width: 0, height: 0, "class": "position-absolute"},
        $svg("defs", null,
            $svg("radialGradient", {id: "svggpat_dot_highlight"},
                $svg("stop", {offset: "50%", "stop-opacity": 0}),
                $svg("stop", {offset: "50%", "stop-color": "#ffff00", "stop-opacity": 0.5}),
                $svg("stop", {offset: "100%", "stop-color": "#ffff00", "stop-opacity": 0})))));
}

function highlight_update(svg, data, keyfunc, klass) {
    highlight_pattern();
    let sel = svg.selectAll(".ghighlight");
    if (klass) {
        sel = sel.filter("." + klass);
    }
    sel = sel.data(data, keyfunc);
    sel.exit().remove();
    let g = sel.enter()
      .append("g")
        .attr("class", "ghighlight" + (klass ? " " + klass : ""));
    g.append("circle")
        .attr("class", "gdot-hover");
    g.append("circle")
        .style("fill", "url(#svggpat_dot_highlight)");
    return g.merge(sel).selectAll("circle");
}

function scatter_highlight(svg, data, klass) {
    highlight_update(svg, data, scatter_key, klass)
        .attr("cx", proj0)
        .attr("cy", proj1)
        .attr("r", function (d, i) {
            return i ? (d.r + 0.5) * 2 : d.r - 0.5;
        });
}

function scatter_union(p) {
    if (!p) {
        return null;
    }
    if (p.head) {
        p = p.head;
    }
    if (!p.next || p.ur != null) {
        return p;
    }
    p.ur = p.r;
    for (let pp = p.next; pp; pp = pp.next) {
        p.ur = Math.max(p.ur, pp.r);
    }
    return p;
}

function make_hover_interactor(svg, hovers, identity) {
    let data = null, over = null;
    function mouseout() {
        hovers.style("display", "none");
        if (self.bubble) {
            self.bubble.remove();
        }
        self.data = self.bubble = data = over = null;
        svg.style("cursor", null);
    }
    const self = {
        data: null,
        bubble: null,
        move: function (d) {
            if (!d && data) {
                mouseout();
            }
            if (!d || (data && (identity ? identity(d, data) : d === data))) {
                over = d;
                return false;
            }
            self.data = data = d;
            self.bubble = self.bubble || make_bubble({class: "graphtip", "pointer-events": "none"});
            svg.style("cursor", "pointer");
            return true;
        },
        mouseout: mouseout,
        mouseout_soon: function () {
            if (!data) {
                return;
            }
            const kill = data;
            over = null;
            setTimeout(function () {
                if (data === kill && !over)
                    mouseout();
            }, 10);
        }
    };
    return self;
}

function graph_scatter(element, args) {
    const svg = this;
    let data = ungroup_data(args.data);
    const x = make_linear_scale(args.x.extent, expand_extent(d3.extent(data, proj0), args.x)),
        y = make_linear_scale(args.y.extent, expand_extent(d3.extent(data, proj1), args.y)),
        axes = make_axis_pair(args, x, y);

    $(element).on("hotgraphhighlight", highlight);

    const gq = grouped_quadtree(data, x, y, 4, args.data_format === "xyis");
    data = null;
    scatter_create(svg, gq.data);

    svg.append("path").attr("class", "gdot gdot-hover");
    const hovers = svg.selectAll(".gdot-hover").style("display", "none"),
        hoverer = make_hover_interactor(svg, hovers);

    draw_axes(svg, axes[0], axes[1], args);

    svg.append("rect")
        .attr("x", -args.marginLeft)
        .attr("width", args.plotWidth + args.marginLeft)
        .attr("height", args.plotHeight + args.marginBottom)
        .attr("fill", "none")
        .attr("pointer-events", "all")
        .on("mouseover", mousemoved)
        .on("mousemove", mousemoved)
        .on("mouseout", hoverer.mouseout_soon)
        .on("click", mouseclick);

    function make_tooltip(p) {
        const pinstance = p.data[0];
        return [
            $e("p", null, render_position(args.x, pinstance[0]), ", ", render_position(args.y, pinstance[1])),
            render_pid_p(gqdata_ids(p, true), p.cc)
        ];
    }

    function mousemoved(event) {
        let m = d3.pointer(event), p = scatter_union(gq.quadtree.gfind(m, 4));
        if (!hoverer.move(p)) {
            return;
        }
        hovers.datum(p)
            .attr("d", scatter_union_annulus)
            .attr("transform", scatter_transform)
            .style("display", null);
        hoverer.bubble.replace_content(...make_tooltip(p))
            .anchor("s")
            .near(hovers.node());
    }

    function mouseclick(event) {
        clicker(hoverer.data ? gqdata_ids(hoverer.data) : null, event);
    }

    function highlight(event) {
        if (!event.ids) {
            if (event.q && event.ok) {
                $.getJSON(hoturl("api/search", {q: event.q}), null, highlight);
            }
            return;
        }
        hoverer.mouseout();
        let myd = [];
        if (event.ids.length) {
            myd = gq.data.filter(function (pd) {
                for (const d of pd.data) {
                    if (event.ids.indexOf(id2pid(d[2])) >= 0) {
                        return true;
                    }
                }
                return false;
            });
        }
        scatter_highlight(svg, myd);
    }
}

function dot_highlight(svg, data, klass) {
    highlight_update(svg, data, d => d.id, klass)
        .attr("cx", projx)
        .attr("cy", projy)
        .attr("r", 4.5);
}

function graph_dot(element, args) {
    const svg = this;
    let data = ungroup_data(args.data);
    const x = make_linear_scale(args.x.extent, expand_extent(d3.extent(data, proj0), args.x)),
        y = make_linear_scale(args.y.extent, expand_extent(d3.extent(data, proj1), args.y)),
        axes = make_axis_pair(args, x, y);
    data = data.map(d => {
        const xv = x(d[0]), yv = y(d[1]);
        return {"0": d[0], "1": d[1], x: xv, x0: xv, y: yv, y0: yv, id: d[2], cc: d[3]};
    });

    const sim = d3.forceSimulation(data)
        .force("collide", d3.forceCollide(6))
        .force("x", d3.forceX(d => d.x0).strength(0.05))
        .force("y", d3.forceY(d => d.y0).strength(0.05))
        .stop();
    sim.tick(Math.ceil(Math.log(sim.alphaMin()) / Math.log(1 - sim.alphaDecay())));

    $(element).on("hotgraphhighlight", highlight);

    svg.selectAll(".gdot")
        .data(data)
        .enter()
        .append("circle")
        .attr("cx", projx)
        .attr("cy", projy)
        .attr("r", 5)
        .attr("class", d => "gdot" + (d.cc ? " " + d.cc : ""))
        .style("fill", d => ensure_pattern(d.cc, "gdot"));

    svg.append("circle").attr("class", "gdot gdot-hover");
    const hovers = svg.selectAll(".gdot-hover")
            .attr("r", 5)
            .style("display", "none"),
        hoverer = make_hover_interactor(svg, hovers);

    draw_axes(svg, axes[0], axes[1], args);

    svg.append("rect")
        .attr("x", -args.marginLeft)
        .attr("width", args.plotWidth + args.marginLeft)
        .attr("height", args.plotHeight + args.marginBottom)
        .attr("fill", "none")
        .attr("pointer-events", "all")
        .on("mouseover", mousemoved)
        .on("mousemove", mousemoved)
        .on("mouseout", hoverer.mouseout_soon)
        .on("click", mouseclick);

    const gq = d3.quadtree(data, projx, projy);

    function make_tooltip(p) {
        return [
            $e("p", null, render_position(args.x, p[0]), ", ", render_position(args.y, p[1])),
            render_pid_p([p], p.cc)
        ];
    }

    function mousemoved(event) {
        let m = d3.pointer(event), p = gq.find(m[0], m[1], 6);
        if (!hoverer.move(p)) {
            return;
        }
        hovers.datum(p)
            .attr("cx", projx)
            .attr("cy", projy)
            .style("display", null);
        hoverer.bubble.replace_content(...make_tooltip(p))
            .anchor("s")
            .near(hovers.node());
    }

    function mouseclick(event) {
        clicker(hoverer.data ? hoverer.data.id : null, event);
    }

    function highlight(event) {
        if (!event.ids) {
            if (event.q && event.ok) {
                $.getJSON(hoturl("api/search", {q: event.q}), null, highlight);
            }
            return;
        }
        hoverer.mouseout();
        let myd = [];
        if (event.ids.length) {
            myd = data.filter(function (d) {
                return event.ids.indexOf(id2pid(d.id)) >= 0;
            });
        }
        dot_highlight(svg, myd);
    }
}

function data_quantize_x(data) {
    data = ungroup_data(data);
    if (!data.length) {
        return data;
    }
    data.sort(function (a, b) { return d3.ascending(a[0], b[0]); });
    const epsilon = (data[data.length - 1][0] - data[0][0]) / 5000;
    let active = null;
    for (const d of data) {
        if (active !== null && Math.abs(active - d[0]) <= epsilon) {
            d[0] = active;
        } else {
            active = d[0];
        }
    }
    return data;
}

function data_to_barchart(data, yaxis) {
    data = data_quantize_x(data);
    if (!data.length) {
        return data;
    }

    data.sort(function (a, b) {
        return d3.ascending(a[0], b[0])
            || d3.ascending(a[4] || 0, b[4] || 0)
            || (a[3] || "").localeCompare(b[3] || "");
    });

    let last = null;
    const ndata = [];
    for (const d of data) {
        if (d[1] == null) {
            continue;
        }
        const cur = {
            "0": d[0],
            "1": d[1],
            ids: d[2],
            yoff: 0,
            i0: ndata.length,
            cc: d[3],
            sx: d[4]
        };
        ndata.push(cur);
        if (last && cur[0] == last[0] && cur.sx == last.sx) {
            cur.yoff = last.yoff + last[1];
            cur.i0 = last.i0;
        }
        last = cur;
    }

    if (!yaxis.fraction) {
        return ndata;
    }

    if (ndata.some(function (d) { return d.sx != data[0].sx; })) {
        let maxy = {};
        ndata.forEach(function (d) { maxy[d[0]] = d[1] + d.yoff; });
        ndata.forEach(function (d) { d.yoff /= maxy[d[0]]; d[1] /= maxy[d[0]]; });
    } else {
        let maxy = 0;
        ndata.forEach(function (d) { maxy += d[1]; });
        ndata.forEach(function (d) { d.yoff /= maxy; d[1] /= maxy; });
    }
    return ndata;
}

function graph_bars(element, args) {
    const svg = this,
        bdata = data_to_barchart(args.data, args.y);

    const ystart = args.y.ticks.type === "score" ? 0.75 : 0,
        xe = d3.extent(bdata, proj0),
        ge = d3.extent(bdata, function (d) { return d.sx || 0; }),
        ye = [d3.min(bdata, function (d) { return Math.max(d.yoff, ystart); }),
              d3.max(bdata, function (d) { return d.yoff + d[1]; })],
        deltae = d3.extent(bdata, function (d, i) {
            const delta = i ? d[0] - bdata[i-1][0] : 0;
            return delta || Infinity;
        }),
        x = make_linear_scale(args.x.extent, expand_extent(xe, args.x)),
        y = make_linear_scale(args.y.extent, ye),
        axes = make_axis_pair(args, x, y);

    const dpr = window.devicePixelRatio || 1;
    let barwidth = args.plotWidth / 20;
    if (deltae[0] != Infinity) {
        barwidth = Math.min(barwidth, Math.abs(x(xe[0] + deltae[0]) - x(xe[0])));
    }
    barwidth = Math.max(5, barwidth);
    if (ge[1]) {
        barwidth = Math.floor((barwidth - 3) * dpr) / (dpr * (ge[1] + 1));
    }
    const gdelta = -(ge[1] + 1) * barwidth / 2;

    function place(sel, close) {
        close = close || "";
        return sel.attr("d", function (d) {
            const yoff = Math.max(d.yoff, ystart),
                x0 = x(d[0]) + gdelta + (d.sx ? barwidth * d.sx : 0),
                y0 = y(yoff),
                y1 = y(d.yoff + d[1]);
            return `M${x0},${y0}V${y1}h${barwidth}V${y0}${close}`;
        });
    }

    place(svg.selectAll(".gbar").data(bdata)
          .enter().append("path")
            .attr("class", function (d) {
                return d.cc ? "gbar " + d.cc : "gbar";
            })
            .style("fill", function (d) { return ensure_pattern(d.cc, "gdot"); }));

    draw_axes(svg, axes[0], axes[1], args);

    svg.append("path").attr("class", "gbar gbar-hover0");
    svg.append("path").attr("class", "gbar gbar-hover1");
    const hovers = svg.selectAll(".gbar-hover0, .gbar-hover1")
            .style("display", "none")
            .attr("pointer-events", "none"),
        hoverer = make_hover_interactor(svg, hovers, function (d1, d2) {
            return d1.i0 === d2.i0;
        });

    svg.selectAll(".gbar")
        .on("mouseover", mouseover)
        .on("mouseout", hoverer.mouseout_soon)
        .on("click", mouseclick);

    function make_tooltip(p) {
        return [
            $e("p", null, render_position(args.x, p[0]), ", ", render_position(args.y, p[1])),
            render_pid_p(p.ids, p.cc)
        ];
    }

    function make_hovered_data(p) {
        const hd = {
            "0": p[0],
            "1": 0,
            ids: [],
            yoff: 0,
            cc: "",
            sx: p.sx,
            i0: p.i0
        };
        for (let i = p.i0; i !== bdata.length && bdata[i].i0 === p.i0; ++i) {
            const p2 = bdata[i];
            hd[1] = p2[1] + p2.yoff;
            for (let id of p2.ids) {
                hd.ids.push(p2.cc ? {id: id, cc: p2.cc} : id);
            }
        }
        return hd;
    }

    function mouseover() {
        const p = d3.select(this).data()[0];
        if (!hoverer.move(p)) {
            return;
        }
        hoverer.data = make_hovered_data(p);
        place(hovers.datum(hoverer.data), "Z").style("display", null);
        hoverer.bubble.replace_content(...make_tooltip(hoverer.data))
            .anchor("h")
            .near(hovers.node());
    }

    function mouseclick(event) {
        clicker(hoverer.data ? hoverer.data.ids : null, event);
    }
}

function boxplot_sort(data) {
    data.sort(function (a, b) {
        return d3.ascending(a[0], b[0])
            || d3.ascending(a[1], b[1])
            || (a[3] || "").localeCompare(b[3] || "")
            || pid_sorter(a[2], b[2]);
    });
    return data;
}

function data_to_boxplot(data, septags) {
    data = boxplot_sort(data_quantize_x(data));

    let active = null;
    data = data.reduce(function (newdata, d) {
        if (!active || active[0] != d[0] || (septags && active.cc != d[3])) {
            active = {
                "0": d[0],
                ymin: d[1],
                ymax: 0,
                cc: d[3] || "",
                ys: [],
                ids: [],
                qnt: null,
                mean: null
            };
            newdata.push(active);
        } else if (active.cc != d[3]) {
            active.cc = "";
        }
        active.ymax = d[1];
        active.ys.push(d[1]);
        active.ids.push(d[2]);
        return newdata;
    }, []);

    data.map(function (d) {
        const l = d.ys.length, med = d3.quantile(d.ys, 0.5);
        if (l < 4) {
            d.qnt = [d.ys[0], d.ys[0], med, d.ys[l-1], d.ys[l-1]];
        } else {
            const q1 = d3.quantile(d.ys, 0.25),
                q3 = d3.quantile(d.ys, 0.75),
                iqr = q3 - q1;
            d.qnt = [
                Math.max(d.ys[0], q1 - 1.5 * iqr),
                q1,
                med,
                q3,
                Math.min(d.ys[l-1], q3 + 1.5 * iqr)
            ];
        }
        d.mean = d3.sum(d.ys) / d.ys.length;
    });

    return data;
}

function graph_boxplot(element, args) {
    const data = data_to_boxplot(args.data, !!args.y.fraction, true),
        svg = this;

    const xe = d3.extent(data, proj0),
        ye = [d3.min(data, function (d) { return d.ymin; }),
              d3.max(data, function (d) { return d.ymax; })],
        deltae = d3.extent(data, function (d, i) {
            var delta = i ? d[0] - data[i-1][0] : 0;
            return delta || Infinity;
        }),
        x = make_linear_scale(args.x.extent, expand_extent(xe, args.x)),
        y = make_linear_scale(args.y.extent, expand_extent(ye, args.y)),
        axes = make_axis_pair(args, x, y);

    let barwidth = args.plotWidth / 80;
    if (deltae[0] != Infinity) {
        barwidth = Math.max(Math.min(barwidth, Math.abs(x(xe[0] + deltae[0]) - x(xe[0])) * 0.5), 6);
    }

    function place_whisker(l, sel) {
        sel.attr("x1", function (d) { return x(d[0]); })
            .attr("x2", function (d) { return x(d[0]); })
            .attr("y1", function (d) { return y(d.qnt[l]); })
            .attr("y2", function (d) { return y(d.qnt[l + 1]); });
    }

    function place_box(sel) {
        sel.attr("d", function (d) {
            const x0 = x(d[0]),
                yq2 = y(d.qnt[2]);
            let yq1 = y(d.qnt[1]), yq3 = y(d.qnt[3]);
            if (yq1 < yq3) {
                const tmp = yq3;
                yq3 = yq1;
                yq1 = tmp;
            }
            if (yq1 - yq3 < 4) {
                yq3 = yq2 - 2;
                yq1 = yq3 + 4;
            }
            yq3 = Math.min(yq3, yq2 - 1);
            yq1 = Math.max(yq1, yq2 + 1);
            return `M${x0 - barwidth / 2},${yq3}h${barwidth}v${yq1 - yq3}h${-barwidth}Z`;
        });
    }

    function place_median(sel) {
        sel.attr("x1", function (d) { return x(d[0]) - barwidth / 2; })
            .attr("x2", function (d) { return x(d[0]) + barwidth / 2; })
            .attr("y1", function (d) { return y(d.qnt[2]); })
            .attr("y2", function (d) { return y(d.qnt[2]); });
    }

    function place_outlier(sel) {
        sel.attr("cx", proj0).attr("cy", proj1)
            .attr("r", function (d) { return d.r; });
    }

    function place_mean(sel) {
        sel.attr("transform", function (d) { return "translate(" + x(d[0]) + "," + y(d.mean) + ")"; })
            .attr("d", "M2.2,0L0,2.2L-2.2,0L0,-2.2Z");
    }

    const nonoutliers = data.filter(function (d) { return d.ys.length > 1; });

    place_whisker(0, svg.selectAll(".gbox.whiskerl").data(nonoutliers)
            .enter().append("line")
            .attr("class", function (d) { return "gbox whiskerl " + d.cc; }));

    place_whisker(3, svg.selectAll(".gbox.whiskerh").data(nonoutliers)
            .enter().append("line")
            .attr("class", function (d) { return "gbox whiskerh " + d.cc; }));

    place_box(svg.selectAll(".gbox.box").data(nonoutliers)
            .enter().append("path")
            .attr("class", function (d) { return "gbox box " + d.cc; })
            .style("fill", function (d) { return ensure_pattern(d.cc, "gdot"); }));

    place_median(svg.selectAll(".gbox.median").data(nonoutliers)
            .enter().append("line")
            .attr("class", function (d) { return "gbox median " + d.cc; }));

    place_mean(svg.selectAll(".gbox.mean").data(nonoutliers)
            .enter().append("path")
            .attr("class", function (d) { return "gbox mean " + d.cc; }));

    let outliers = d3.merge(data.map(function (d) {
        const nd = [], len = d.ys.length;
        for (let i = 0; i < len; ++i) {
            if (d.ys[i] < d.qnt[0] || d.ys[i] > d.qnt[4] || len <= 1)
                nd.push([d[0], d.ys[i], d.ids[i], d.cc]);
        }
        return nd;
    }));
    outliers = grouped_quadtree(outliers, x, y, 2);
    place_outlier(svg.selectAll(".gbox.outlier")
            .data(outliers.data)
            .enter()
              .append("circle")
              .attr("class", function (d) { return "gbox outlier " + d[3]; }));

    draw_axes(svg, axes[0], axes[1], args);

    svg.append("line").attr("class", "gbox whiskerl gbox-hover");
    svg.append("line").attr("class", "gbox whiskerh gbox-hover");
    svg.append("path").attr("class", "gbox box gbox-hover");
    svg.append("line").attr("class", "gbox median gbox-hover");
    svg.append("circle").attr("class", "gbox outlier gbox-hover");
    svg.append("path").attr("class", "gbox mean gbox-hover");
    const hovers = svg.selectAll(".gbox-hover")
            .style("display", "none")
            .style("ponter-events", "none"),
        hoverer = make_hover_interactor(svg, hovers);

    $(element).on("hotgraphhighlight", highlight);

    element.addEventListener("mouseout", hoverer.mouseout_soon, false);

    element.addEventListener("mouseover", function (event) {
        if (hasClass(event.target, "outlier")
            || hasClass(event.target, "gscatter"))
            mouseover_outlier.call(event.target);
        else if (hasClass(event.target, "gbox"))
            mouseover.call(event.target);
    }, false);

    element.addEventListener("click", function (event) {
        if (hasClass(event.target, "gbox")
            || hasClass(event.target, "gscatter"))
            mouseclick.call(event.target, event);
    }, false);

    function make_tooltip(p) {
        const yformat = args.y.ticks.render_onto,
            posd = p.qnt ? p : p.data[0],
            pe = $e("p", null, render_position(args.x, posd[0]), ", ");
        let ids;
        if (p.qnt) {
            pe.append(render_position(args.y, p.qnt[2], "median "));
            ids = [];
            for (let i = 0; i !== p.ys.length; ++i) {
                const rest = $frag(" (");
                yformat.call(args.y, rest, p.ys[i]);
                rest.append(")");
                ids.push({id: p.ids[i], rest: rest});
            }
        } else {
            pe.append(render_position(args.y, posd[1]));
            ids = gqdata_ids(p, true);
        }
        return [pe, render_pid_p(ids, p.cc)];
    }

    function mouseover() {
        const p = d3.select(this).data()[0];
        if (!hoverer.move(p)) {
            return;
        }
        hovers.style("display", "none");
        hovers.filter(":not(.outlier)").style("display", null).datum(p);
        place_whisker(0, hovers.filter(".whiskerl"));
        place_whisker(3, hovers.filter(".whiskerh"));
        place_box(hovers.filter(".box"));
        place_median(hovers.filter(".median"));
        place_mean(hovers.filter(".mean"));
        hoverer.bubble.replace_content(...make_tooltip(p))
            .anchor("h")
            .near(hovers.filter(".box").node());
    }

    function mouseover_outlier() {
        const po = d3.select(this).data()[0];
        if (!hoverer.move(po)) {
            return;
        }
        hovers.style("display", "none");
        place_outlier(hovers.filter(".outlier").style("display", null).datum(po));
        hoverer.bubble.replace_content(...make_tooltip(po))
            .anchor("h")
            .near(hovers.filter(".outlier").node());
    }

    function mouseclick(event) {
        let s;
        if (!hoverer.data) {
            clicker(null, event);
        } else if (!hoverer.data.qnt) {
            clicker(gqdata_ids(hoverer.data), event);
        } else if ((s = args.x.ticks.search(hoverer.data[0]))) {
            clicker_go(hoturl("search", {q: s}), event);
        } else {
            clicker(hoverer.data.ids, event);
        }
    }

    function highlight(event) {
        hoverer.mouseout();
        if (event.ids && !event.ids.length) {
            svg.selectAll(".gscatter").remove();
            return;
        }
        $.getJSON(hoturl("api/graphdata"), {
            x: element.getAttribute("data-graph-fx"),
            y: element.getAttribute("data-graph-fy"),
            q: event.q
        }, function (rv) {
            if (!rv.ok) {
                return;
            }
            const data = ungroup_data(rv.data);
            if (args.x.reordered && args.x.ticks.map) {
                remap_scatter_data(data, rv, args.x.ticks.map);
            }
            const gq = grouped_quadtree(data, x, y, 4);
            scatter_create(svg, gq.data, "gscatter");
            scatter_highlight(svg, gq.data, "gscatter");
        });
    }
}

function make_axis_pair(args, x, y) {
    const axes = [
        args.x.ticks.make_axis.call(args.x, "x", args, x),
        args.y.ticks.make_axis.call(args.y, "y", args, y)
    ];
    if (args.y.tickLength > 0 && args.marginLeftDefault) {
        args.marginLeft = 10 * Math.max(args.y.tickLength, 1.5) + 6;
        args.plotWidth = args.width - args.marginLeft - args.marginRight;
        x.range(args.x.flip ? [args.plotWidth, 0] : [0, args.plotWidth]);
    }
    args.svg.attr("transform", "translate(".concat(args.marginLeft, ",", args.marginTop, ")"));
    return axes;
}

function basic_make_axis(side, args, scale) {
    const dimen = side === "x" ? args.plotWidth : args.plotHeight;
    scale.range(!this.flip === (side === "y") ? [dimen, 0] : [0, dimen]);
    const ax = side === "x" ? d3.axisBottom(scale) : d3.axisLeft(scale);
    if (this.tickFormat) {
        ax.tickFormat(this.tickFormat);
    }
    this.scale = scale;
    this.axis = ax;
    return ax;
}

function numeric_make_axis(side, args, scale) {
    const ax = basic_make_axis.call(this, side, args, scale),
        tf = scale.tickFormat();
    this.tickLength = 0;
    for (const v of scale.ticks()) {
        this.tickLength = Math.max(this.tickLength, tf(v).replace(/,/g, "").length);
    }
    return ax;
}

function score_ticks(rf) {
    let myfmt;
    return {
        make_axis: function (side, args, scale) {
            const domain = scale.domain();
            let count = Math.floor(domain[1] * 2) - Math.ceil(domain[0] * 2) + 1;
            if (count > 11) {
                count = Math.floor(domain[1]) - Math.ceil(domain[0]) + 1;
            }
            const ax = basic_make_axis.call(this, side, args, scale);
            if (!rf.default_numeric) {
                ax.ticks(count);
            }
            this.tickLength = 1;
            myfmt = scale.tickFormat();
            for (const v of scale.ticks()) {
                let vt = rf.unparse_symbol(v);
                if (typeof vt === "number") {
                    vt = myfmt(vt);
                }
                this.tickLength = Math.max(this.tickLength, vt.length);
            }
            return ax;
        },
        rewrite: function () {
            this.selectAll("g.tick text").each(function () {
                const d = d3.select(this), v = +d.text();
                d.attr("class", "sv");
                d.attr("fill", rf.color(v));
                if (!rf.default_numeric && v) {
                    let vt = rf.unparse_symbol(v);
                    if (typeof vt === "number") {
                        vt = myfmt(vt);
                    }
                    d.text(vt);
                }
            });
        },
        render_onto: function (e, value, include_numeric) {
            const k = rf.className(value);
            let vt = rf.unparse_symbol(value);
            if (typeof vt === "number") {
                vt = vt.toFixed(2).replace(/\.00$/, "");
            }
            e.append(k ? $e("span", "sv " + k, vt) : vt);
            if (include_numeric
                && !rf.default_numeric
                && value !== Math.round(value * 2) / 2) {
                e.append(" (" + value.toFixed(2).replace(/\.00$/, "") + ")");
            }
        },
        type: "score"
    };
}

function time_ticks() {
    function format(value) {
        if (value < 1000000000) {
            value = Math.round(value / 8640) / 10;
            return value + "d";
        }
        const d = new Date(value * 1000);
        if (d.getHours() || d.getMinutes()) {
            return strftime("%Y-%m-%dT%R", d);
        } else {
            return strftime("%Y-%m-%d", d);
        }
    }
    return {
        make_axis: function (side, args, scale) {
            const ax = basic_make_axis.call(this, side, args, scale),
                domain = scale.domain();
            if (domain[0] < 1000000000 || domain[1] < 1000000000) {
                const ddomain = [domain[0] / 86400, domain[1] / 86400],
                    nscale = d3.scaleLinear().domain(ddomain).range(scale.range());
                ax.tickValues(nscale.ticks().map(function (value) {
                    return value * 86400;
                }));
                this.tickLength = Math.ceil(Math.log10(domain[1]));
            } else {
                const ddomain = [new Date(domain[0] * 1000), new Date(domain[1] * 1000)],
                    nscale = d3.scaleTime().domain(ddomain).range(scale.range());
                ax.tickValues(nscale.ticks().map(function (value) {
                    return value.getTime() / 1000;
                }));
                this.tickLength = 10;
            }
            ax.tickFormat(format);
            return ax;
        },
        render_onto: function (e, value) {
            e.append(format(value));
        },
        type: "time"
    };
}

function get_max_tick_width(axis) {
    return d3.max($(axis.selectAll("g.tick text").nodes()).map(function () {
        if (this.getBoundingClientRect) {
            const r = this.getBoundingClientRect();
            return r.right - r.left;
        } else {
            return $(this).width();
        }
    }));
}

function get_sample_tick_height(axis) {
    return d3.quantile($(axis.selectAll("g.tick text").nodes()).map(function () {
        if (this.getBoundingClientRect) {
            const r = this.getBoundingClientRect();
            return r.bottom - r.top;
        } else {
            return $(this).height();
        }
    }), 0.5);
}

function named_integer_ticks(map) {
    const want_tilt = Object.values(map).length > 30
            || d3.max(Object.keys(map).map(function (k) { return mtext(k).length; })) > 4,
        want_mclasses = Object.keys(map).some(function (k) { return mclasses(k); });

    function mtext(value) {
        const m = map[value];
        return m && typeof m === "object" ? m.text : m;
    }
    function mclasses(value) {
        const m = map[value];
        return (m && typeof m === "object" && m.color_classes) || "";
    }

    function rewrite() {
        if (!want_tilt && !want_mclasses) {
            return;
        }

        let max_width = get_max_tick_width(this);
        if (max_width > 100) { // shrink font
            this.attr("class", function () {
                return this.getAttribute("class") + " widelabel";
            });
            max_width = get_max_tick_width(this);
        }
        const example_height = get_sample_tick_height(this);

        // apply offset first (so `mclasses` rects include offset)
        if (want_tilt) {
            this.selectAll("g.tick text")
                .attr("text-anchor", "end")
                .attr("dx", "-9px")
                .attr("dy", "2px");
        }

        // apply classes by adding them and adding background rects
        if (want_mclasses) {
            this.selectAll("g.tick text").filter(mclasses).each(function (i) {
                const c = mclasses(i);
                d3.select(this).attr("class", c + " taghh");
                if (/\btagbg\b/.test(c)) {
                    const b = this.getBBox();
                    d3.select(this.parentNode).insert("rect", "text")
                        .attr("x", b.x - 3).attr("y", b.y)
                        .attr("width", b.width + 6).attr("height", b.height + 1)
                        .attr("class", "glab " + c)
                        .style("fill", ensure_pattern(c, "glab"));
                }
            });
        }

        // apply tilt rotation, enlarge container if necessary
        if (want_tilt) {
            this.selectAll("g.tick text, g.tick rect")
                .attr("transform", "rotate(-65)");
            max_width = max_width * Math.sin(1.13446) + 20; // 65 degrees in radians
            if (max_width > BOTTOM_MARGIN && this.classed("x-axis")) {
                const delta = max_width - BOTTOM_MARGIN,
                    container = $(this.node()).closest("svg");
                container.attr("height", +container.attr("height") + delta);
                this.select(".label")
                    .attr("y", function () { return +this.getAttribute("y") + delta; });
            }
        }

        // prevent label overlap
        if (want_tilt) {
            const total_height = Object.values(map).length * (example_height * Math.cos(1.13446) + 8),
                alternation = Math.ceil(total_height / this.node().getBBox().width - 0.1);
            if (alternation > 1) {
                this.selectAll("g.tick").each(function (i) {
                    if (i % alternation != 1)
                        d3.select(this).style("display", "none");
                });
            }
        }
    }

    return {
        make_axis: function (side, args, scale) {
            const domain = scale.domain(),
                count = Math.floor(domain[1]) - Math.ceil(domain[0]) + 1,
                ax = basic_make_axis.call(this, side, args, scale);
            ax.ticks(count).tickFormat(mtext);
            this.tickLength = 1;
            for (const v of scale.ticks(count)) {
                const m = mtext(v);
                if (m) {
                    this.tickLength = Math.max(this.tickLength, m.length);
                }
            }
            return ax;
        },
        rewrite: rewrite,
        render_onto: function (e, value, include_numeric) {
            const fvalue = Math.round(value);
            if (Math.abs(value - fvalue) <= 0.05 && map[fvalue]) {
                e.append(mtext(fvalue));
                if (include_numeric
                    && value !== fvalue
                    && typeof value === "number") {
                    e.append(" (" + value.toFixed(2) + ")");
                }
            }
        },
        search: function (value) {
            const m = map[value];
            return (m && typeof m === "object" && m.search) || null;
        },
        type: "named_integer",
        map: map
    };
}

function make_rotate_ticks(angle) {
    if (!angle) {
        return function () {};
    }
    return function (axis) {
        axis.selectAll("text")
            .attr("x", 0).attr("y", 0).attr("dy", "-.71em")
            .attr("transform", "rotate(" + angle + ")")
            .attr("text-anchor", "middle");
    };
}

handle_ui.on("js-hotgraph-highlight", function () {
    const s = $.trim(this.value);
    let pids = null;
    if (s === "") {
        pids = [];
    } else if (/^[1-9][0-9]*$/.test(s)) {
        pids = [+s];
    }
    const e = $.Event("hotgraphhighlight");
    e.ok = true;
    e.q = s;
    e.ids = pids;
    $(this).closest(".has-hotgraph").find(".hotgraph").trigger(e);
});

const graphers = {
    procrastination: {filter: true, function: procrastination_filter},
    scatter: {function: graph_scatter},
    dot: {function: graph_dot},
    cdf: {function: graph_cdf},
    cumfreq: {function: graph_cdf},
    bar: {function: graph_bars},
    fraction: {function: graph_bars},
    box: {function: graph_boxplot}
};

function make_args(element, args) {
    args = $.extend({}, args);
    const mns = ["marginTop", "marginRight", "marginBottom", "marginLeft"],
        m = args.margin || [null, null, null, null],
        mdefaults = [24, 20, BOTTOM_MARGIN, 50];
    for (let i = 0; i < 4; ++i) {
        const mn = mns[i];
        if (args[mn] == null) {
            args[mn] = m[i];
        }
        if (args[mn] == null) {
            args[mn] = mdefaults[i];
            args[mn + "Default"] = true;
        }
    }
    if (args.width == null) {
        args.width = $(element).width();
        args.widthDefault = true;
    }
    if (args.height == null) {
        args.height = 540;
        args.heightDefault = true;
    }
    args.plotWidth = args.width - args.marginLeft - args.marginRight;
    args.plotHeight = args.height - args.marginTop - args.marginBottom;
    args.x = $.extend({}, args.x || {});
    args.y = $.extend({}, args.y || {});
    args.x.ticks = make_ticks(args.x.ticks);
    args.y.ticks = make_ticks(args.y.ticks);
    return args;
}

return function (selector, args) {
    const element = $(selector)[0];
    if (!element) {
        return null;
    }
    if (!d3) {
        const erre = $e("div", "msg-error");
        feedback.append_item_near(erre, {message: "<0>Graphs are not supported on this browser", status: 2});
        if (document.documentMode) {
            feedback.append_item_near(erre, {message: "<5>You appear to be using a version of Internet Explorer, which is no longer supported. <a href=\"https://browsehappy.com\">Edge, Firefox, Chrome, and Safari</a> are supported, among others.", status: -5 /*MessageSet::INFORM*/});
        }
        element.append(erre);
        return null;
    }
    let g = graphers[args.type];
    while (g && g.filter) {
        args = g["function"](args);
        g = graphers[args.type];
    }
    if (!g) {
        return null;
    }
    args = make_args(element, args);
    args.svg = d3.select(element).append("svg")
        .attr("width", args.width)
        .attr("height", args.height)
      .append("g");
    return g["function"].call(args.svg, element, args);
};
})(jQuery, window.d3);
