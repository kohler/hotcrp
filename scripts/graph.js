// graph.js -- HotCRP JavaScript library for graph drawing
// HotCRP is Copyright (c) 2006-2016 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

var hotcrp_graphs = (function ($, d3) {
var BOTTOM_MARGIN = 30;
var PATHSEG_ARGMAP = {
    m: 2, M: 2, z: 0, Z: 0, l: 2, L: 2, h: 1, H: 1, v: 1, V: 1, c: 6, C: 6,
    s: 4, S: 4, q: 4, Q: 4, t: 2, T: 2, a: 7, A: 7, b: 1, B: 1
};

function svg_path_number_of_items(s) {
    if (s instanceof SVGPathElement)
        s = s.getAttribute("d");
    return s.replace(/[^A-DF-Za-df-z]/g, "").length;
}

function make_svg_path_parser(s) {
    if (s instanceof SVGPathElement)
        s = s.getAttribute("d");
    var i = 0, e = s.length, next_cmd;
    return function () {
        var a = null, j, ch;
        while (i < e) {
            ch = s.charAt(i);
            if (ch == "," || ch <= " ")
                ++i;
            else if (ch >= "+" && ch <= "9") {
                if (!a && next_cmd)
                    a = [next_cmd];
                else if (!a || a.length == PATHSEG_ARGMAP[a[0]] + 1)
                    break;
                for (j = i, ++i;
                     i < e && (((ch = s.charAt(i)) >= "+" && ch <= "9" && ch !== ",")
                               || ch === "e" || ch === "E");
                     ++i)
                    /* skip */;
                a.push(+s.substring(j, i));
            } else if (ch >= "A" && ch <= "z" && !a) {
                a = [ch];
                next_cmd = ch;
                if (ch == "m" || ch == "M" || ch == "z" || ch == "Z")
                    next_cmd = ch == "m" ? "l" : "L";
                ++i;
            } else
                break;
        }
        return a;
    };
}

var normalize_path_complaint = false;
function normalize_svg_path(s) {
    var res = [],
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
                    res.push(["L", cx0, cy0]);
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
        } else if (ch === "L")
            res.push(["L", cx, cy, a[1], a[2]]);
        else if (ch === "C")
            res.push(["C", cx, cy, a[1], a[2], a[3], a[4], a[5], a[6]]);
        else if (ch === "Q")
            res.push(["C", cx, cy,
                      cx + 2 * (a[1] - cx) / 3, cy + 2 * (a[2] - cy) / 3,
                      a[3] + 2 * (a[1] - a[3]) / 3, a[4] + 2 * (a[2] - a[4]) / 3,
                      a[3], a[4]]);
        else {
            // XXX should render "A" as a bezier
            if (++normalize_path_complaint == 1)
                log_jserror("bad normalize_svg_path " + ch);
            res.push(a);
        }

        preva = a;
        cx = a[a.length - 2];
        cy = a[a.length - 1];
    }
    return res;
}

function pathNodeMayBeNearer(pathNode, point, dist) {
    function oob(l, t, r, b) {
        return l - point[0] >= dist || point[0] - r >= dist
            || t - point[1] >= dist || point[1] - b >= dist;
    }
    // check bounding rectangle of path
    if ("clientX" in point) {
        var bounds = pathNode.getBoundingClientRect();
        var dx = point[0] - point.clientX, dy = point[1] - point.clientY;
        if (oob(bounds.left + dx, bounds.top + dy,
                bounds.right + dx, bounds.bottom + dy))
            return false;
    }
    // check path
    var npsl = normalize_svg_path(pathNode);
    var l, t, r, b;
    for (var i = 0; i < npsl.length; ++i) {
        var item = npsl[i];
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
        } else if (item[0] === "Z" || item[0] === "M")
            continue;
        else
            return true;
        if (!oob(l, t, r, b))
            return true;
    }
    return false;
}

function closestPoint(pathNode, point, inbest) {
    // originally from Mike Bostock http://bl.ocks.org/mbostock/8027637
    var pathLength = pathNode.getTotalLength(),
        precision = pathLength / svg_path_number_of_items(pathNode) * .125,
        best, bestLength, bestDistance2 = Infinity;

    if (inbest && !pathNodeMayBeNearer(pathNode, point, inbest.distance))
        return inbest;

    function check(pLength) {
        var p = pathNode.getPointAtLength(pLength);
        var dx = point[0] - p.x, dy = point[1] - p.y, d2 = dx * dx + dy * dy;
        if (d2 < bestDistance2) {
            best = [p.x, p.y];
            best.pathNode = pathNode;
            bestLength = pLength;
            bestDistance2 = d2;
            return true;
        } else
            return false;
    }

    // linear scan for coarse approximation
    var sl;
    for (sl = 0; sl <= pathLength; sl += precision)
        check(sl);

    // binary search for precise estimate
    precision *= .5;
    while (precision > .5) {
        if (!((sl = bestLength - precision) >= 0 && check(sl))
            && !((sl = bestLength + precision) <= pathLength && check(sl)))
            precision *= .5;
    }

    best.distance = Math.sqrt(bestDistance2);
    best.pathLength = bestLength;
    return inbest && inbest.distance < best.distance + 0.01 ? inbest : best;
}

function tangentAngle(pathNode, length) {
    var length0 = Math.max(0, length - 0.25);
    if (length0 == length)
        length += 0.25;
    var p0 = pathNode.getPointAtLength(length0),
        p1 = pathNode.getPointAtLength(length);
    return Math.atan2(p1.y - p0.y, p1.x - p0.x);
}


/* CDF functions */
function submission_delay_seq(ri) {
    var seq = [], i;
    for (i in ri)
        if (ri[i][1] > 0)
            seq.push((ri[i][1] - ri[i][0]) / 86400);
    seq.ntotal = ri.length;
    return seq;
}
submission_delay_seq.label = function (dl) {
    return "Days after assignment";
};

function procrastination_seq(ri, dl) {
    var seq = [], i;
    for (i in ri)
        if (ri[i][1] > 0)
            seq.push((ri[i][1] - dl[ri[i][2]]) / 86400);
    seq.ntotal = ri.length;
    return seq;
}
procrastination_seq.label = function (dl) {
    return dl.length > 1 ? "Days until round deadline" : "Days until deadline";
};

function max_procrastination_seq(ri, dl) {
    var seq = [], i, dlx = Math.max.apply(null, dl);
    for (i in ri)
        if (ri[i][1] > 0)
            seq.push((ri[i][1] - dlx) / 86400);
    seq.ntotal = ri.length;
    return seq;
}
max_procrastination_seq.label = function (dl) {
    return dl.length > 1 ? "Days until maximum deadline" : "Days until deadline";
};
procrastination_seq.tick_format = max_procrastination_seq.tick_format =
    function (x) { return -x; };

function seq_to_cdf(seq, flip) {
    var cdf = [], i, n = seq.ntotal || seq.length;
    seq.sort(flip ? d3.descending : d3.ascending);
    for (i = 0; i <= seq.length; ++i) {
        if (i != 0 && (i == seq.length || seq[i-1] != seq[i]))
            cdf.push([seq[i-1], i/n]);
        if (i != seq.length && (i == 0 || seq[i-1] != seq[i]))
            cdf.push([seq[i], i/n]);
    }
    cdf.cdf = true;
    return cdf;
}


function expand_extent(e, is_y) {
    if (e[0] > 0 && e[0] < e[1] / 11)
        e[0] = 0;
    if (e[1] - e[0] < 10) {
        var delta = Math.min(1, e[1] - e[0]) * 0.2;
        if (!is_y || e[0])
            e[0] -= delta;
        e[1] += delta;
    }
    return e;
}


function make_axes(svg, xAxis, yAxis, args) {
    var css = {"text-anchor": "end", "font-size": "smaller", "pointer-events": "none"};

    svg.append("g")
        .attr("class", "x axis")
        .attr("transform", "translate(0," + args.height + ")")
        .call(xAxis).call(args.x.axis_setup || function () {})
      .append("text")
        .attr("x", args.width).attr("y", 0).attr("dy", "-.5em")
        .style(css).text(args.x.label || "");

    svg.append("g")
        .attr("class", "y axis")
        .call(yAxis).call(args.y.axis_setup || function () {})
      .append("text")
        .attr("transform", "rotate(-90)")
        .attr("y", 6).attr("dy", ".71em")
        .style(css).text(args.y.label || "");

    args.x.ticks.rewrite.call(svg.select(".x.axis"), svg);
    args.y.ticks.rewrite.call(svg.select(".y.axis"), svg);
    xAxis.axis_args = args.x;
    yAxis.axis_args = args.y;
}

function proj0(d) {
    return d[0];
}

function proj1(d) {
    return d[1];
}

function proj2(d) {
    return d[2];
}

function pid_sorter(a, b) {
    var d = (typeof a === "string" ? parseInt(a, 10) : a) -
            (typeof b === "string" ? parseInt(b, 10) : b);
    return d ? d : (a < b ? -1 : (a == b ? 0 : 1));
}

function clicker(pids) {
    var m, x, i, url;
    if (!pids)
        return;
    if (typeof pids !== "object")
        pids = [pids];
    for (i = 0, x = []; i < pids.length; ++i) {
        m = parseInt(pids[i], 10);
        if (!x.length || x[x.length - 1] != m)
            x.push(m);
    }
    if (x.length == 1 && pids.length == 1 && /[A-Z]$/.test(pids[0]))
        clicker_go(hoturl("paper", {p: x[0], anchor: "r" + pids[0]}));
    else if (x.length == 1)
        clicker_go(hoturl("paper", {p: x[0]}));
    else {
        x = d3.set(x).values();
        x.sort(pid_sorter);
        clicker_go(hoturl("search", {q: x.join(" ")}));
    }
}

function clicker_go(url) {
    if (d3.event.metaKey)
        window.open(url, "_blank");
    else
        window.location = url;
}

function make_axis(args) {
    return $.extend({
        ticks: function (extent) {},
        rewrite: function () {},
        unparse_html: function (value) {
            if (value == Math.floor(value))
                return value;
            var dom = this.scale().domain(),
                dig = Math.max(0, -Math.round(Math.log10(dom[1] - dom[0])) + 2);
            return value.toFixed(dig);
        },
        search: function (value) { return null; }
    }, args || {});
}

function axis_domain(axis, argextent, e) {
    if (argextent && argextent[0] != null)
        e = [argextent[0], e[1]];
    if (argextent && argextent[1] != null)
        e = [e[0], argextent[1]];
    axis.domain(e);
}

function make_args(args) {
    args = $.extend({top: 20, right: 20, bottom: BOTTOM_MARGIN, left: 50}, args);
    args.x = args.x || {};
    args.y = args.y || {};
    args.x.ticks = make_axis(args.x.ticks);
    args.y.ticks = make_axis(args.y.ticks);
    args.width = $(args.selector).width() - args.left - args.right;
    args.height = 500 - args.top - args.bottom;
    return args;
}

function position_label(axis, p, prefix) {
    var t = '<span class="nw">' + (prefix || "");
    if (axis.axis_args.label)
        t += escape_entities(axis.axis_args.label) + " ";
    return t + axis.axis_args.ticks.unparse_html.call(axis, p) + '</span>';
}


/* actual graphs */
var hotcrp_graphs = {};

// args: {selector: JQUERYSELECTOR,
//        data: [{d: [ARRAY], label: STRING, className: STRING}],
//        x/y: {label: STRING, tick_format: STRING}}
function hotcrp_graphs_cdf(args) {
    args = make_args(args);

    var x = d3.scale.linear().range(args.x.flip ? [args.width, 0] : [0, args.width]),
        y = d3.scale.linear().range([args.height, 0]);

    var svg = d3.select(args.selector).append("svg")
        .attr("width", args.width + args.left + args.right)
        .attr("height", args.height + args.top + args.bottom)
      .append("g")
        .attr("transform", "translate(" + args.left + "," + args.top + ")");

    // massage data
    var series = args.data;
    if (!series.length) {
        series = d3.values(series);
        series.sort(function (a, b) {
            return d3.ascending(a.priority || 0, b.priority || 0);
        });
    }
    series = series.filter(function (d) {
        return (d.d ? d.d : d).length > 0;
    });
    var data = series.map(function (d) {
        d = d.d ? d.d : d;
        return d.cdf ? d : seq_to_cdf(d, !!args.x.flip);
    });

    // axis domains
    var xdomain = data.reduce(function (e, d) {
        e[0] = Math.min(e[0], d[0][0], d[d.length - 1][0]);
        e[1] = Math.max(e[1], d[0][0], d[d.length - 1][0]);
        return e;
    }, [Infinity, -Infinity]);
    xdomain = [xdomain[0] - (xdomain[1] - xdomain[0]) / 32,
               xdomain[1] + (xdomain[1] - xdomain[0]) / 32];
    axis_domain(x, args.x.extent, xdomain);
    axis_domain(y, args.y.extent, [0, Math.ceil(d3.max(data, function (d) {
        return d[d.length - 1][1];
    }) * 10) / 10]);

    // axes
    var xAxis = d3.svg.axis().scale(x).orient("bottom");
    args.x.ticks.ticks.call(xAxis, x.domain());
    args.x.tick_format && xAxis.tickFormat(args.x.tick_format);
    var yAxis = d3.svg.axis().scale(y).orient("left");
    var line = d3.svg.line().x(function (d) {return x(d[0]);})
        .y(function (d) {return y(d[1]);});

    // CDF lines
    data.forEach(function (d, i) {
        var klass = "gcdf";
        if (series[i].className)
            klass += " " + series[i].className;
        if (d[d.length - 1][0] != xdomain[args.x.flip ? 0 : 1])
            d.push([xdomain[args.x.flip ? 0 : 1], d[d.length - 1][1]]);
        svg.append("path").attr("dataindex", i)
            .datum(d)
            .attr("class", klass)
            .attr("d", line);
    });

    svg.append("path").attr("class", "gcdf gcdf_hover0");
    svg.append("path").attr("class", "gcdf gcdf_hover1");
    var hovers = svg.selectAll(".gcdf_hover0, .gcdf_hover1");
    hovers.style("display", "none");

    make_axes(svg, xAxis, yAxis, args);

    svg.append("rect").attr("x", -args.left).attr("width", args.width + args.left)
        .attr("height", args.height + args.bottom)
        .style({"fill": "none", "pointer-events": "all"})
        .on("mouseover", mousemoved).on("mousemove", mousemoved)
        .on("mouseout", mouseout);

    var hovered_path, hubble;
    function mousemoved() {
        var m = d3.mouse(this), p = {distance: 16};
        m.clientX = d3.event.clientX;
        m.clientY = d3.event.clientY;
        for (var i in data)
            if (series[i].label)
                p = closestPoint(svg.select("[dataindex='" + i + "']").node(), m, p);
        if (p.pathNode != hovered_path) {
            if (p.pathNode)
                hovers.datum(data[p.pathNode.getAttribute("dataindex")])
                    .attr("d", line).style("display", null);
            else
                hovers.style("display", "none");
            hovered_path = p.pathNode;
        }
        var u = p.pathNode ? series[p.pathNode.getAttribute("dataindex")] : null;
        if (u && u.label) {
            hubble = hubble || make_bubble("", {color: "graphtip dark", "pointer-events": "none"});
            var dir = Math.abs(tangentAngle(p.pathNode, p.pathLength));
            hubble.text(u.label)
                .dir(dir >= 0.25*Math.PI && dir <= 0.75*Math.PI ? "r" : "b")
                .at(p[0] + args.left, p[1], this);
        } else if (hubble)
            hubble = hubble.remove() && null;
    }

    function mouseout() {
        hovers.style("display", "none");
        hubble && hubble.remove();
        hovered_path = hubble = null;
    }
};
hotcrp_graphs.cdf = hotcrp_graphs_cdf;


hotcrp_graphs.procrastination = function (selector, revdata) {
    var args = {selector: selector, data: {}, x: {}, y: {}};

    // collect data
    var alldata = [], d, i, l, cid, u;
    for (cid in revdata.reviews) {
        var d = {d: revdata.reviews[cid]};
        if ((u = revdata.users[cid]) && u.name)
            d.label = u.name;
        if (cid && cid == hotcrp_user.cid) {
            d.className = "revtimel_hilite";
            d.priority = 1;
        } else if (u && u.light)
            d.className = "revtimel_light";
        if (u && u.color_classes)
            d.className = (d.className ? d.className + " " : "") + u.color_classes;
        Array.prototype.push.apply(alldata, d.d);
        if (cid !== "conflicts")
            args.data[cid] = d;
    }
    args.data.all = {d: alldata, className: "revtimel_all", priority: 2};

    var dlf = max_procrastination_seq;

    // infer deadlines when not set
    if (dlf != submission_delay_seq) {
        for (i in revdata.deadlines)
            if (!revdata.deadlines[i]) {
                var subat = alldata.filter(function (d) { return d[2] == i; })
                    .map(proj0);
                subat.sort(d3.ascending);
                revdata.deadlines[i] = subat.length ? d3.quantile(subat, 0.8) : 0;
            }
    }
    // make cdfs
    for (i in args.data)
        args.data[i].d = seq_to_cdf(dlf(args.data[i].d, revdata.deadlines));

    if (dlf.tick_format)
        args.x.tick_format = dlf.tick_format;
    args.x.label = dlf.label(revdata.deadlines);
    args.y.label = "Fraction of assignments completed";

    hotcrp_graphs_cdf(args);
};


/* grouped quadtree */
// mark bounds of each node
function grouped_quadtree_mark_bounds(q, rf, ordinalf) {
    ordinalf = ordinalf || (function () { var m = 0; return function () { return ++m; }; })();
    q.ordinal = ordinalf();

    var b, p, i, n, ps;
    if (q.point) {
        for (p = q.point, ps = []; p; p = p.next)
            ps.push(p);
        ps.sort(function (a, b) { return d3.ascending(a.n, b.n); });
        for (i = n = 0; i < ps.length; ++i) {
            ps[i].r0 = i ? ps[i-1].r : 0;
            n += ps[i].n;
            ps[i].r = rf(n);
        }
        p = q.point;
        p.maxr = ps[ps.length - 1].r;
        b = [p[1] - p.maxr, p[0] + p.maxr, p[1] + p.maxr, p[0] - p.maxr];
    } else
        b = [Infinity, -Infinity, -Infinity, Infinity];

    for (i = 0; i < 4; ++i)
        if ((p = q.nodes[i])) {
            grouped_quadtree_mark_bounds(p, rf, ordinalf);
            b[0] = Math.min(b[0], p.bounds[0]);
            b[1] = Math.max(b[1], p.bounds[1]);
            b[2] = Math.max(b[2], p.bounds[2]);
            b[3] = Math.min(b[3], p.bounds[3]);
        }

    q.bounds = b;
}

function grouped_quadtree_gfind(point, min_distance) {
    var q = this, closest = null;
    if (min_distance == null)
        min_distance = Infinity;
    function visitor(node) {
        var p;
        if (node.bounds[0] > point[1] + min_distance
            || node.bounds[1] < point[0] - min_distance
            || node.bounds[2] < point[1] - min_distance
            || node.bounds[3] > point[0] + min_distance)
            return true;
        if (!(p = node.point))
            return;
        var dx = p[0] - point[0], dy = p[1] - point[1];
        if (Math.abs(dx) - p.maxr < min_distance
            || Math.abs(dy) - p.maxr < min_distance) {
            var dd = Math.sqrt(dx * dx + dy * dy);
            for (; p; p = p.next) {
                var d = Math.max(dd - p.r, 0);
                if (d < min_distance || (d == 0 && p.r < closest.r))
                    closest = p, min_distance = d;
            }
        }
    }
    this.visit(visitor);
    return closest;
}

function grouped_quadtree(data, xs, ys, rf) {
    function make_extent() {
        var xe = xs.range(), ye = ys.range();
        return [[Math.min(xe[0], xe[1]), Math.min(ye[0], ye[1])],
                [Math.max(xe[0], xe[1]), Math.max(ye[0], ye[1])]];
    }
    var q = d3.geom.quadtree().extent(make_extent())([]);

    var d, nd = [], vp, vd, dx, dy;
    for (var i = 0; (d = data[i]); ++i) {
        if (d[0] == null || d[1] == null)
            continue;
        vd = [xs(d[0]), ys(d[1]), [d], d[3]];
        if ((vp = q.find(vd))) {
            dx = Math.abs(vp[0] - vd[0]);
            dy = Math.abs(vp[1] - vd[1]);
            if (dx > 2 || dy > 2 || dx * dx + dy * dy > 4)
                vp = null;
        }
        while (vp && vp[3] != vd[3] && vp.next)
            vp = vp.next;
        if (vp && vp[3] == vd[3]) {
            vp[2].push(d);
            vp.n += 1;
        } else {
            vp ? vp.next = vd : q.add(vd);
            vd.n = 1;
            nd.push(vd);
        }
    }

    if (rf == null)
        rf = Math.sqrt;
    else if (typeof rf === "number")
        rf = (function (f) {
            return function (n) { return Math.sqrt(n) * f; };
        })(rf);
    grouped_quadtree_mark_bounds(q, rf);

    delete q.add;
    q.gfind = grouped_quadtree_gfind;
    return {data: nd, quadtree: q};
}

function data_to_scatter(data) {
    if (!$.isArray(data)) {
        for (var i in data)
            i && data[i].forEach(function (d) { d.push(i); });
        data = d3.merge(d3.values(data));
    }
    return data;
}

hotcrp_graphs.scatter = function (args) {
    args = make_args(args);
    var data = data_to_scatter(args.data);

    var xe = d3.extent(data, proj0),
        ye = d3.extent(data, proj1),
        x = d3.scale.linear().range(args.x.flip ? [args.width, 0] : [0, args.width]),
        y = d3.scale.linear().range(args.y.flip ? [0, args.height] : [args.height, 0]),
        rf = function (d) { return d.r - 1; };
    axis_domain(x, args.x.extent, expand_extent(xe));
    axis_domain(y, args.y.extent, expand_extent(ye, true));
    data = grouped_quadtree(data, x, y, 4);

    var xAxis = d3.svg.axis().scale(x).orient("bottom");
    args.x.ticks.ticks.call(xAxis, xe);
    var yAxis = d3.svg.axis().scale(y).orient("left");
    args.y.ticks.ticks.call(yAxis, ye);

    var svg = d3.select(args.selector).append("svg")
        .attr("width", args.width + args.left + args.right)
        .attr("height", args.height + args.top + args.bottom)
      .append("g")
        .attr("transform", "translate(" + args.left + "," + args.top + ")");

    var annulus = d3.svg.arc()
        .innerRadius(function (d) { return d.r0 ? d.r0 - 0.5 : 0; })
        .outerRadius(function (d) { return d.r - 0.5; })
        .startAngle(0)
        .endAngle(Math.PI * 2);

    function place(sel) {
        return sel.attr("d", annulus)
            .attr("transform", function (d) { return "translate(" + d[0] + "," + d[1] + ")"; });
    }

    place(svg.selectAll(".gdot").data(data.data)
          .enter().append("path")
            .attr("class", function (d) {
                return d[3] ? "gdot " + d[3] : "gdot";
            })
            .style("fill", function (d) { return make_pattern_fill(d[3], "gdot "); }));

    svg.append("path").attr("class", "gdot gdot_hover0");
    svg.append("path").attr("class", "gdot gdot_hover1");
    var hovers = svg.selectAll(".gdot_hover0, .gdot_hover1").style("display", "none");

    make_axes(svg, xAxis, yAxis, args);

    svg.append("rect").attr("x", -args.left).attr("width", args.width + args.left)
        .attr("height", args.height + args.bottom)
        .style({"fill": "none", "pointer-events": "all"})
        .on("mouseover", mousemoved).on("mousemove", mousemoved)
        .on("mouseout", mouseout).on("click", mouseclick);

    function make_tooltip(p, ps) {
        ps.sort(pid_sorter);
        return '<p>' + position_label(xAxis, p[0]) + ', ' +
            position_label(yAxis, p[1]) + '</p><p>#' + ps.join(', #') + '</p>';
    }

    var hovered_data, hubble;
    function mousemoved() {
        var m = d3.mouse(this), p = data.quadtree.gfind(m, 4);
        if (p != hovered_data) {
            if (p)
                place(hovers.datum(p)).style("display", null);
            else
                hovers.style("display", "none");
            svg.style("cursor", p ? "pointer" : null);
            hovered_data = p;
        }
        if (p) {
            hubble = hubble || make_bubble("", {color: "graphtip dark", "pointer-events": "none"});
            hubble.html(make_tooltip(p[2][0], p[2].map(proj2)))
                .dir("b").near(hovers.node());
        } else if (hubble)
            hubble = hubble.remove() && null;
    }

    function mouseout() {
        hovers.style("display", "none");
        hubble && hubble.remove();
        hovered_data = hubble = null;
    }

    function mouseclick() {
        clicker(hovered_data ? hovered_data[2].map(proj2) : null);
    }
};

function data_quantize_x(data) {
    if (data.length) {
        data.sort(function (a, b) { return d3.ascending(a[0], b[0]); });
        var epsilon = (data[data.length - 1][0] - data[0][0]) / 5000, active = null;
        data.forEach(function (d) {
            if (active !== null && Math.abs(active - d[0]) <= epsilon)
                d[0] = active;
            else
                active = d[0];
        });
    }
    return data;
}

function data_to_barchart(data, isfraction) {
    data = data_quantize_x(data);
    data.sort(function (a, b) {
        return d3.ascending(a[0], b[0])
            || d3.ascending(a[4] || 0, b[4] || 0)
            || (a[3] || "").localeCompare(b[3] || "");
    });

    for (var i = 0; i != data.length; ++i)
        if (i && data[i-1][0] == data[i][0] && data[i-1][4] == data[i][4])
            data[i].yoff = data[i-1].yoff + data[i-1][1];
        else
            data[i].yoff = 0;

    if (isfraction && data.some(function (d) { return d[4] != data[0][4]; })) {
        var maxy = {};
        data.forEach(function (d) { maxy[d[0]] = d[1] + d.yoff; });
        data.forEach(function (d) { d.yoff /= maxy[d[0]]; d[1] /= maxy[d[0]]; });
    } else if (isfraction) {
        var maxy = 0;
        data.forEach(function (d) { maxy += d[1]; });
        data.forEach(function (d) { d.yoff /= maxy; d[1] /= maxy; });
    }

    return data;
}

hotcrp_graphs.barchart = function (args) {
    args = make_args(args);
    var data = data_to_barchart(args.data, !!args.y.fraction);

    var xe = d3.extent(data, proj0),
        ge = d3.extent(data, function (d) { return d[4] || 0; }),
        ye = [d3.min(data, function (d) { return d.yoff; }),
              d3.max(data, function (d) { return d.yoff + d[1]; })],
        deltae = d3.extent(data, function (d, i) {
            var delta = i ? d[0] - data[i-1][0] : 0;
            return delta || Infinity;
        }),
        x = d3.scale.linear().range(args.x.flip ? [args.width, 0] : [0, args.width]),
        y = d3.scale.linear().range(args.y.flip ? [0, args.height] : [args.height, 0]);
    axis_domain(x, args.x.extent, expand_extent(xe));
    axis_domain(y, args.y.extent, ye);

    var dpr = window.devicePixelRatio || 1;
    var barwidth = args.width / 20;
    if (deltae[0] != Infinity)
        barwidth = Math.min(barwidth, Math.abs(x(xe[0] + deltae[0]) - x(xe[0])));
    barwidth = Math.max(5, barwidth);
    if (ge[1])
        barwidth = Math.floor((barwidth - 3) * dpr) / (dpr * (ge[1] + 1));
    var gdelta = -(ge[1] + 1) * barwidth / 2;

    var xAxis = d3.svg.axis().scale(x).orient("bottom");
    args.x.ticks.ticks.call(xAxis, xe);
    var yAxis = d3.svg.axis().scale(y).orient("left");
    args.y.ticks.ticks.call(yAxis, ye);

    var svg = d3.select(args.selector).append("svg")
        .attr("width", args.width + args.left + args.right)
        .attr("height", args.height + args.top + args.bottom)
      .append("g")
        .attr("transform", "translate(" + args.left + "," + args.top + ")");

    function place(sel, close) {
        return sel.attr("d", function (d) {
            return ["M", x(d[0]) + gdelta + (d[4] ? barwidth * d[4] : 0), y(d.yoff),
                    "V", y(d.yoff + d[1]), "h", barwidth,
                    "V", y(d.yoff)].join(" ") + (close || "");
        });
    }

    place(svg.selectAll(".gbar").data(data)
          .enter().append("path")
            .attr("class", function (d) {
                return d[3] ? "gbar " + d[3] : "gbar";
            })
            .style("fill", function (d) { return make_pattern_fill(d[3], "gdot "); }));

    make_axes(svg, xAxis, yAxis, args);

    svg.append("path").attr("class", "gbar gbar_hover0");
    svg.append("path").attr("class", "gbar gbar_hover1");
    var hovers = svg.selectAll(".gbar_hover0, .gbar_hover1")
        .style("display", "none").style("pointer-events", "none");

    svg.selectAll(".gbar").on("mouseover", mouseover).on("mouseout", mouseout)
        .on("click", mouseclick);

    function make_tooltip(p) {
        p[2].sort(pid_sorter);
        return '<p>' + position_label(xAxis, p[0]) + ', ' +
            position_label(yAxis, p[1]) + '</p><p>#' + p[2].join(', #') + '</p>';
    }

    var hovered_data, hubble;
    function mouseover() {
        var p = d3.select(this).data()[0];
        if (p != hovered_data) {
            if (p)
                place(hovers.datum(p), "Z").style("display", null);
            else
                hovers.style("display", "none");
            svg.style("cursor", p ? "pointer" : null);
            hovered_data = p;
        }
        if (p) {
            hubble = hubble || make_bubble("", {color: "graphtip dark", "pointer-events": "none"});
            hubble.html(make_tooltip(p)).dir("h").near(this);
        }
    }

    function mouseout() {
        hovers.style("display", "none");
        hubble && hubble.remove();
        hovered_data = hubble = null;
    }

    function mouseclick() {
        clicker(hovered_data ? hovered_data[2] : null);
    }
};

function boxplot_sort(data) {
    data.sort(function (a, b) {
        return d3.ascending(a[0], b[0]) || d3.ascending(a[1], b[1])
            || (a[3] || "").localeCompare(b[3] || "")
            || pid_sorter(a[2], b[2]);
    });
    return data;
}

function data_to_boxplot(data, septags) {
    data = boxplot_sort(data_quantize_x(data_to_scatter(data)));

    var active = null, count = 0;
    data = data.reduce(function (newdata, d) {
        if (!active || active[0] != d[0] || (septags && active[4] != d[3])) {
            active = {"0": d[0], ymin: d[1], c: d[3] || "", d: [], p: []};
            newdata.push(active);
        } else if (active.c != d[3])
            active.c = "";
        active.ymax = d[1];
        active.d.push(d[1]);
        active.p.push(d[2]);
        return newdata;
    }, []);

    data.map(function (d) {
        d.q = [d.d[0], d3.quantile(d.d, 0.25), d3.quantile(d.d, 0.5),
               d3.quantile(d.d, 0.75), d.d[d.d.length - 1]];
        if (d.d.length > 20) {
            d.q[0] = d3.quantile(d.d, 0.05);
            d.q[4] = d3.quantile(d.d, 0.95);
        }
        d.m = d3.sum(d.d) / d.d.length;
    });

    return data;
}

hotcrp_graphs.boxplot = function (args) {
    args = make_args(args);
    var data = data_to_boxplot(args.data, !!args.y.fraction, true);

    var xe = d3.extent(data, proj0),
        ye = [d3.min(data, function (d) { return d.ymin; }),
              d3.max(data, function (d) { return d.ymax; })],
        deltae = d3.extent(data, function (d, i) {
            var delta = i ? d[0] - data[i-1][0] : 0;
            return delta || Infinity;
        }),
        x = d3.scale.linear().range(args.x.flip ? [args.width, 0] : [0, args.width]),
        y = d3.scale.linear().range(args.y.flip ? [0, args.height] : [args.height, 0]);
    axis_domain(x, args.x.extent, expand_extent(xe));
    axis_domain(y, args.y.extent, expand_extent(ye, true));

    var barwidth = args.width/80;
    if (deltae[0] != Infinity)
        barwidth = Math.max(Math.min(barwidth, Math.abs(x(xe[0] + deltae[0]) - x(xe[0])) * 0.5), 6);

    var xAxis = d3.svg.axis().scale(x).orient("bottom");
    args.x.ticks.ticks.call(xAxis, xe);
    var yAxis = d3.svg.axis().scale(y).orient("left");
    args.y.ticks.ticks.call(yAxis, ye);

    var svg = d3.select(args.selector).append("svg")
        .attr("width", args.width + args.left + args.right)
        .attr("height", args.height + args.top + args.bottom)
      .append("g")
        .attr("transform", "translate(" + args.left + "," + args.top + ")");

    function place_whisker(l, sel) {
        sel.attr("x1", function (d) { return x(d[0]); })
            .attr("x2", function (d) { return x(d[0]); })
            .attr("y1", function (d) { return y(d.q[l]); })
            .attr("y2", function (d) { return y(d.q[l + 1]); });
    }

    function place_box(sel) {
        sel.attr("d", function (d) {
            var yq1 = y(d.q[1]), yq2 = y(d.q[2]), yq3 = y(d.q[3]);
            if (yq1 < yq3) {
                var tmp = yq3;
                yq3 = yq1;
                yq1 = tmp;
            }
            if (yq1 - yq3 < 4)
                yq3 = yq2 - 2, yq1 = yq3 + 4;
            yq3 = Math.min(yq3, yq2 - 1);
            yq1 = Math.max(yq1, yq2 + 1);
            return ["M", x(d[0]) - barwidth / 2, ",", yq3, "l", barwidth, ",0",
                    "l0,", yq1 - yq3, "l-", barwidth, ",0Z"].join("");
        });
    }

    function place_median(sel) {
        sel.attr("x1", function (d) { return x(d[0]) - barwidth / 2; })
            .attr("x2", function (d) { return x(d[0]) + barwidth / 2; })
            .attr("y1", function (d) { return y(d.q[2]); })
            .attr("y2", function (d) { return y(d.q[2]); });
    }

    function place_outlier(sel) {
        sel.attr("cx", proj0).attr("cy", proj1)
            .attr("r", function (d) { return d.r; });
    }

    function place_mean(sel) {
        sel.attr("transform", function (d) { return "translate(" + x(d[0]) + "," + y(d.m) + ")"; })
            .attr("d", "M2.2,0L0,2.2L-2.2,0L0,-2.2Z");
    }

    var nonoutliers = data.filter(function (d) { return d.d.length > 1; });

    place_whisker(0, svg.selectAll(".gbox.whiskerl").data(nonoutliers)
            .enter().append("line")
            .attr("class", function (d) { return "gbox whiskerl " + d.c; }));

    place_whisker(3, svg.selectAll(".gbox.whiskerh").data(nonoutliers)
            .enter().append("line")
            .attr("class", function (d) { return "gbox whiskerh " + d.c; }));

    place_box(svg.selectAll(".gbox.box").data(nonoutliers)
            .enter().append("path")
            .attr("class", function (d) { return "gbox box " + d.c; })
            .style("fill", function (d) { return make_pattern_fill(d.c, "gdot "); }));

    place_median(svg.selectAll(".gbox.median").data(nonoutliers)
            .enter().append("line")
            .attr("class", function (d) { return "gbox median " + d.c; }));

    place_mean(svg.selectAll(".gbox.mean").data(nonoutliers)
            .enter().append("path")
            .attr("class", function (d) { return "gbox mean " + d.c; }));

    var outliers = d3.merge(data.map(function (d) {
        var nd = [], len = d.d.length;
        for (var i = 0; i < len; ++i)
            if (d.d[i] < d.q[0] || d.d[i] > d.q[4] || len == 1)
                nd.push([d[0], d.d[i], d.p[i], d.c]);
        return nd;
    }));
    outliers = grouped_quadtree(outliers, x, y, 2);
    place_outlier(svg.selectAll(".gbox.outlier")
            .data(outliers.data).enter().append("circle")
            .attr("class", function (d) { return "gbox outlier " + d[3]; }));

    make_axes(svg, xAxis, yAxis, args);

    svg.append("line").attr("class", "gbox whiskerl gbox_hover0");
    svg.append("line").attr("class", "gbox whiskerh gbox_hover0");
    svg.append("path").attr("class", "gbox box gbox_hover0");
    svg.append("line").attr("class", "gbox median gbox_hover0");
    svg.append("circle").attr("class", "gbox outlier gbox_hover0");
    svg.append("path").attr("class", "gbox mean gbox_hover0");
    svg.append("line").attr("class", "gbox whiskerl gbox_hover1");
    svg.append("line").attr("class", "gbox whiskerh gbox_hover1");
    svg.append("path").attr("class", "gbox box gbox_hover1");
    svg.append("line").attr("class", "gbox median gbox_hover1");
    svg.append("circle").attr("class", "gbox outlier gbox_hover1");
    svg.append("path").attr("class", "gbox mean gbox_hover1");
    var hovers = svg.selectAll(".gbox_hover0, .gbox_hover1")
        .style("display", "none").style("ponter-events", "none");

    svg.selectAll(".gbox").on("mouseout", mouseout).on("click", mouseclick);
    svg.selectAll(".gbox").filter(":not(.outlier)").on("mouseover", mouseover);
    svg.selectAll(".gbox.outlier").on("mouseover", mouseover_outlier);

    function make_tooltip(p, ps, ds) {
        var yformat = args.y.ticks.unparse_html, t, x = [];
        t = '<p>' + position_label(xAxis, p[0]);
        if (p.q) {
            t += ", " + position_label(yAxis, p.q[2], "median ");
            for (var i = 0; i < ps.length; ++i)
                x.push(ps[i] + " (" + yformat.call(yAxis, ds[i]) + ")");
        } else {
            t += ", " + position_label(yAxis, ds[0]);
            x = ps;
        }
        x.sort(pid_sorter);
        return t + '</p><p><span class="nw">#' + x.join(',</span> <span class="nw">#') + '</span></p>';
    }

    var hovered_data, hubble;
    function mouseover() {
        var p = d3.select(this).data()[0];
        if (p != hovered_data) {
            hovers.style("display", "none");
            if (p) {
                hovers.filter(":not(.outlier)").style("display", null).datum(p);
                place_whisker(0, hovers.filter(".whiskerl"));
                place_whisker(3, hovers.filter(".whiskerh"));
                place_box(hovers.filter(".box"));
                place_median(hovers.filter(".median"));
                place_mean(hovers.filter(".mean"));
            }
            svg.style("cursor", p ? "pointer" : null);
            hovered_data = p;
        }
        if (p) {
            hubble = hubble || make_bubble("", {color: "graphtip dark", "pointer-events": "none"});
            if (!p.th)
                p.th = make_tooltip(p, p.p, p.d);
            hubble.html(p.th).dir("h").near(hovers.filter(".box").node());
        }
    }

    function mouseover_outlier() {
        var p = d3.select(this).data()[0];
        if (p != hovered_data) {
            hovers.style("display", "none");
            if (p)
                place_outlier(hovers.filter(".outlier").style("display", null).datum(p));
            svg.style("cursor", p ? "pointer" : null);
            hovered_data = p;
        }
        if (p) {
            hubble = hubble || make_bubble("", {color: "graphtip dark", "pointer-events": "none"});
            if (!p.th)
                p.th = make_tooltip(p[2][0], p[2].map(proj2), p[2].map(proj1));
            hubble.html(p.th).dir("h").near(hovers.filter(".outlier").node());
        }
    }

    function mouseout() {
        hovers.style("display", "none");
        hubble && hubble.remove();
        hovered_data = hubble = null;
    }

    function mouseclick() {
        var s;
        if (!hovered_data)
            clicker(null);
        else if (!hovered_data.q)
            clicker(hovered_data[2].map(proj2));
        else if ((s = args.x.ticks.search(hovered_data[0])))
            clicker_go(hoturl("search", {q: s}));
        else
            clicker(hovered_data.p);
    }
};

hotcrp_graphs.formulas_add_qrow = function () {
    author_change($("#qcontainer > tr:last-child > td:first-child"), 1);
};

hotcrp_graphs.option_letter_ticks = function (n, c, sv) {
    var info = make_score_info(n, c, sv), split = 2;
    function format(extent) {
        var count = Math.floor(extent[1] * 2) - Math.ceil(extent[0] * 2) + 1;
        if (count > 11)
            split = 1, count = Math.floor(extent[1]) - Math.ceil(extent[0]) + 1;
        if (c)
            this.ticks(count);
    }
    function rewrite(axis) {
        $(this[0]).find("g.tick text").each(function () {
            var $self = $(this);
            $self.css({fill: info.rgb($self.text())});
            if (c)
                $self.text(info.unparse($self.text(), split));
        });
    };
    return { ticks: format, rewrite: rewrite, unparse_html: info.unparse_html };
};

function get_max_tick_width(axis) {
    return d3.max($(axis[0]).find("g.tick text").map(function () {
        if (this.getBoundingClientRect) {
            var r = this.getBoundingClientRect();
            return r.right - r.left;
        } else
            return $(this).width();
    }));
}

function get_sample_tick_height(axis) {
    return d3.quantile($(axis[0]).find("g.tick text").map(function () {
        if (this.getBoundingClientRect) {
            var r = this.getBoundingClientRect();
            return r.bottom - r.top;
        } else
            return $(this).height();
    }), 0.5);
}

hotcrp_graphs.named_integer_ticks = function (map) {
    function mtext(value) {
        var m = map[value];
        return m && typeof m === "object" ? m.text : m;
    }
    function mclasses(value) {
        var m = map[value];
        return (m && typeof m === "object" && m.color_classes) || "";
    }
    function format(extent) {
        var count = Math.floor(extent[1]) - Math.ceil(extent[0]) + 1;
        this.ticks(count).tickFormat(mtext);
    }
    function unparse_html(value) {
        return map[value] != null ? text_to_html(mtext(value)) : value;
    };
    function search(value) {
        var m = map[value];
        return (m && typeof m === "object" && m.search) || null;
    };

    var want_tilt, want_mclasses;
    function rewrite() {
        if (!want_tilt && !want_mclasses)
            return;

        var max_width = get_max_tick_width(this);
        if (max_width > 100) { // shrink font
            $(this[0]).find("g.tick text").css("font-size", "smaller");
            max_width = get_max_tick_width(this);
        }
        var example_height = get_sample_tick_height(this);

        // apply offset first (so `mclasses` rects include offset)
        if (want_tilt)
            this.selectAll("g.tick text").style("text-anchor", "end")
                .attr("dx", "-9px").attr("dy", "2px");

        // apply classes by adding them and adding background rects
        if (want_mclasses)
            this.selectAll("g.tick text").filter(mclasses).each(function (i) {
                var c = mclasses(i);
                d3.select(this).attr("class", "taghl " + c);
                var b = this.getBBox();
                d3.select(this.parentNode).insert("rect", "text")
                    .attr("x", b.x - 3).attr("y", b.y)
                    .attr("width", b.width + 6).attr("height", b.height + 1)
                    .attr("class", "glab " + c)
                    .style("fill", make_pattern_fill(c, "glab "));
            });

        // apply tilt rotation, enlarge container if necessary
        if (want_tilt) {
            this.selectAll("g.tick text, g.tick rect")
                .attr("transform", "rotate(-65)");
            max_width = max_width * Math.sin(1.13446) + 20; // 65 degrees in radians
            if (max_width > BOTTOM_MARGIN && this.classed("x")) {
                var container = $(this[0]).closest("svg");
                container.attr("height", +container.attr("height") + (max_width - BOTTOM_MARGIN));
            }
        }

        // prevent label overlap
        if (want_tilt) {
            var total_height = d3.values(map).length * (example_height * Math.cos(1.13446) + 8);
            var alternation = Math.ceil(total_height / this.node().getBBox().width - 0.1);
            if (alternation > 1)
                this.selectAll("g.tick").each(function (i) {
                    if (i % alternation != 1)
                        d3.select(this).style("display", "none");
                });
        }
    }
    want_tilt = d3.values(map).length > 30
        || d3.max(d3.keys(map).map(function (k) { return mtext(k).length; })) > 4;
    want_mclasses = d3.keys(map).some(function (k) { return mclasses(k); });

    return { ticks: format, rewrite: rewrite, unparse_html: unparse_html,
             search: search };
};

hotcrp_graphs.rotate_ticks = function (angle) {
    return function (axis) {
        axis.selectAll("text")
            .attr("x", 0).attr("y", 0).attr("dy", "-.71em")
            .attr("transform", "rotate(" + angle + ")")
            .style("text-anchor", "middle");
    };
};

return hotcrp_graphs;
})(jQuery, d3);
