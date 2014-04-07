// trackercomet.js -- HotCRP helper for tracker-oriented long polling
// HotCRP is Copyright (c) 2014 Eddie Kohler
// Distributed under an MIT-like license; see LICENSE

"use strict";

var http = require("http");
var https = require("https");
var url = require("url");
var querystring = require("querystring");
var fs = require("fs");
var util = require("util");

var server_config = {
    port: 20444,
    access_log: "log/trackercomet_access_log",
    error_log: "log/trackercomet_error_log",
    update_checker: true,
    conference_capacity: 1000
};

var access_log = process.stdout;

var conferences = {}, conference_list = [];


// HELPER FUNCTIONS

// Return current time as an integer number of milliseconds
function get_now() {
    return (new Date).getTime();
}


// LOGGING AND RESPONSES

function log_format(now) {
    var d = new Date(now), i, tzo = d.getTimezoneOffset(), atzo = Math.abs(tzo),
	x = [d.getDate(), d.getHours(), d.getMinutes(), d.getSeconds(),
	     Math.floor(atzo / 60), atzo % 60];
    for (i = 0; i < 6; ++i)
	if (x[i] < 10)
	    x[i] = "0" + x[i];
    i = x[0] + "/" +
	"JanFebMarAprMayJunJulAugSepOctNovDec".substr(d.getMonth() * 3, 3) +
	"/" + (d.getYear() + 1900) + ":" + x[1] + ":" + x[2] + ":" + x[3];
    if (atzo)
	i += (atzo < 0 ? " +" : " -") + x[4] + x[5];
    return i;
}

function end_and_log(u, req, res, data) {
    var path = u.pathname, parts, t;
    if (server_config.access_log_query) {
        parts = [];
        if (u.query && (t = querystring.stringify(u.query)))
            parts.push(t);
        if (u.body && u.body != u.query && (t = querystring.stringify(u.body)))
            parts.push(t);
        if (parts.length)
            path += "?" + parts.join("&");
    }
    res.end(data);
    access_log.write(util.format("%s - - [%s] \"%s %s HTTP/%s\" %d %s\n",
				 u.remoteAddress, log_format(u.now),
				 req.method, path, req.httpVersion,
				 res.statusCode, data.length));
}

function json_response(u, req, res, j) {
    var content_type;
    if (u.query.callback)
	content_type = "application/javascript";
    else if (u.query.jsontext)
	content_type = "text/plain";
    else
	content_type = "application/json";

    j = JSON.stringify(j);
    if (u.query.callback)
	j = u.query.callback + "(" + j + ")";

    res.writeHead(200, {
	"Content-Type": content_type,
	"Content-Length": Buffer.byteLength(j),
	"Access-Control-Allow-Origin": req.headers.origin || "*",
	"Access-Control-Allow-Credentials": true,
	"Access-Control-Allow-Headers": "Accept-Encoding"
    });
    end_and_log(u, req, res, j);
}

function redirect(where, u, req, res) {
    var m = "Redirecting\n";
    if (where[0] == "/")
	where = where.substr(1);
    res.writeHead(302, {
	"Location": "/" + u.course + "/" + where,
	"Content-Length": m.length,
	"Access-Control-Allow-Origin": req.headers.origin || "*",
	"Access-Control-Allow-Credentials": true,
	"Access-Control-Allow-Headers": "Accept-Encoding"
    });
    end_and_log(u, req, res, m);
}

function not_found(u, req, res) {
    res.writeHead(404);
    end_and_log(u, req, res, "File not found\n");
}


// CONFERENCE OBJECT

function Conference(url) {
    this.url = url;
    this.https = /^https:/i.test(url);
    this.pollers = {};
    this.next_poller = 1;
    this.npollers = 0;

    var now = get_now();
    this.check_at = this.check_response_at = now - 120000;
    this.tracker_status = null;

    this.access_at = now;

    this.check_interval = server_config.check_interval || 60000;
    this.update_access = server_config.update_access;
    if (server_config.update_access == null)
        this.update_access = true;
    if (typeof this.update_access === "string")
        this.update_access = new RegExp(this.update_access);
}

Conference.prototype.check = function () {
    var that = this, text = "";

    function result() {
        var e;
        try {
            var j = JSON.parse(text);
            that.check_response_at = get_now();
            if (j.ok) {
                if (j.tracker_status !== that.tracker_status)
                    that.update(j.tracker_status);
            } else
                that.update(null);
        } catch (e) {
            that.update(null);
        }
    }

    this.check_at = get_now();
    (this.https ? https : http)
        .request(this.url + "deadlines.php?checktracker=1&ajax=1",
                 function (res) {
                     res.setEncoding("utf8");
                     res.on("data", function (chunk) { text += chunk; });
                     res.on("end", result);
                 })
        .on("error", function () { that.update(null); })
        .end();
};

Conference.prototype.allow_update = function (u, req, res) {
    if (this.update_access === true)
        return u.remoteAddress == "127.0.0.1" || u.remoteAddress == "::1";
    else if (typeof this.update_access == "function")
        return this.update_access(u, req, res);
    else if ("test" in this.update_access)
        return this.update_access.test(u.remoteAddress);
    else if (this.update_access)
        return false;
    else
        return true;
};

Conference.prototype.update = function (tracker_status) {
    var now = get_now();
    this.tracker_status = tracker_status;
    if (this.tracker_status === "")
        this.tracker_status = false;
    for (var i in this.pollers)
	this.pollers[i](now);
    this.pollers = {};
    this.npollers = 0;
};

Conference.prototype.add_poller = function (u, req, res) {
    var that = this, timeout = null, poller;

    function pollf(arg) {
	if (arg) {
            timeout && clearTimeout(timeout);
	    u.now = arg;
	} else {
	    u.now = get_now();
	    delete that.pollers[poller];
	    --that.npollers;
	}
	timeout = null;
	that.poll_response(u, req, res);
    }

    timeout = that.poll_timeout ? setTimeout(f, that.poll_timeout, 0) : null;
    if (that.poll_capacity && that.npollers == that.poll_capacity)
	pollf(u.now);
    else {
	poller = that.next_poller;
	++that.next_poller;
	++that.npollers;
	while (that.pollers[poller])
	    poller = (poller + 1) % 32768;
	that.pollers[poller] = pollf;
	res.on("close", function () {
	    if (timeout) {
		clearTimeout(timeout);
		delete that.pollers[poller];
		--that.npollers;
	    }
	});
    }
};

Conference.prototype.poll = function (u, req, res) {
    var cinterval = this.tracker_status === null ? 10000 : this.check_interval;
    if (this.check_at < u.now - cinterval
        && (u.query.poll || this.tracker_status === null)) {
        this.add_poller(u, req, res);
        this.check();
    } else if (u.query.poll && u.query.poll === this.tracker_status)
        this.add_poller(u, req, res);
    else
        this.poll_response(u, req, res);
};

Conference.prototype.poll_response = function (u, req, res) {
    if (this.tracker_status !== null)
        json_response(u, req, res, {"ok": true,
                                    "tracker_status": this.tracker_status});
    else
        json_response(u, req, res, {"ok": false});
};

Conference.prototype.kill = function (now) {
    this.update(null);
    console.warn(util.format("[%s] killing conference %s",
                             log_format(now), this.url));
};


function make_conference(conf, now) {
    var x, c, modconf;

    // conference lookup ignores protocol
    modconf = conf.replace(/^https?:/i, "");

    if ((x = conferences[modconf]))
        x.access_at = now;
    else {
        conferences[modconf] = x = new Conference(conf);
        conference_list.push(x);
        if (conference_list.length >= 2 * server_config.conference_capacity) {
            // too many conferences, sort by access time and throw away half
            conference_list.sort(function (a, b) {
                return b.access_at - a.access_at;
            });
            while (conference_list.length > server_config.conference_capacity) {
                c = conference_list[conference_list.length - 1];
                delete conferences[c.url];
                c.kill(now);
                conference_list.pop();
            }
        }
    }

    return x;
}

function server(req, res) {
    var u = url.parse(req.url, true, true), m, conf;
    u.now = get_now();

    u.remoteAddress = req.connection.remoteAddress;
    if (server_config.proxy && req.headers["x-forwarded-for"]
        && (server_config.proxy === true
            || (server_config.proxy.toLowerCase
                ? server_config.proxy == u.remoteAddress
                : server_config.proxy.test(u.remoteAddress)))) {
        u.proxied = true;
        if ((m = req.headers["x-forwarded-for"].match(/.*?([^\s,]*)[\s,]*$/)))
            u.remoteAddress = m[1];
    }

    if (!u.query || !u.query.conference)
        return json_response(u, req, res, {"error": "missing conference"});
    if (!/^(?:https?:)?\/[^?#]+\/?$/.test(u.query.conference)
        || (server_config.conference_matcher
            && !server_config.conference_matcher.test(u.query.conference)))
        return json_response(u, req, res, {"error": "bad conference"});
    if (!/\/$/.test(u.query.conference))
        u.query.conference += "/";

    conf = make_conference(u.query.conference, u.now);
    if (("check" in u.query || "update" in u.query)
        && !conf.allow_update(u, req, res))
        return json_response(u, req, res, {"error": "permission denied"});
    else if ("check" in u.query) {
        conf.add_poller(u, req, res);
        conf.check();
    } else if ("update" in u.query) {
        conf.update(u.query.update);
        conf.poll_response(u, req, res);
    } else
        conf.poll(u, req, res);
}


// INITIALIZATION

(function () {
    var needargs = {
        port: 1, p: 1, "init-file": 1, config: 1, f: 1,
        "access-log": 1, "error-log": 1
    }, opt = {}, i, x, m, access_log_name, error_log_name;
    for (var i = 2; i < process.argv.length; ++i) {
	if ((m = process.argv[i].match(/^--([^=]*)(=.*)?$/)))
	    m[2] = m[2] ? m[2].substr(1) : null;
        else if (!(m = process.argv[i].match(/^-(\w)(.*)$/)))
	    break;
	if (needargs[m[1]] && !m[2])
	    m[2] = process.argv[++i];
	opt[m[1]] = m[2] ? m[2] : true;
    }

    if ((x = opt["init-file"] || opt.config || opt.f))
	eval(fs.readFileSync(x, "utf8"));
    else if (!opt["no-init-file"] && fs.existsSync("conf/trackercomet.opt.js"))
	eval(fs.readFileSync("jkconfig.js", "utf8"));

    access_log_name = opt["access-log"] || server_config.access_log;
    if (access_log_name == "-" || access_log_name == "stdout")
        access_log_name = "inherit";
    error_log_name = opt["error-log"] || server_config.error_log;

    if (!opt.fg) {
	if (access_log_name != "ignore" && access_log_name != "inherit")
	    access_log_name = "ignore";
	if (error_log_name != "ignore" && error_log_name != "inherit")
	    error_log_name = fs.openSync(error_log_name, "a");
	require("child_process").spawn(process.argv[0],
				       process.argv.slice(1).concat(["--fg", "--nohup"]),
				       {stdio: ["ignore",
                                                access_log_name,
                                                error_log_name],
					detached: true});
	process.exit();
    }
    if (opt.nohup)
	process.on("SIGHUP", function () {});
    if (opt.port || opt.p)
	server_config.port = +(opt.port || opt.p);

    server_config.opened_access_log = false;
    if (access_log_name == "ignore")
	access_log = fs.createWriteStream("/dev/null", {flags: "a"});
    else if (access_log_name && access_log_name != "inherit") {
        server_config.opened_access_log = true;
	access_log = fs.createWriteStream(access_log_name, {flags: "a"});
    }
    if (server_config.conference_matcher
        && typeof server_config.conference_matcher === "string")
        server_config.conference_matcher = new RegExp(server_config.conference_matcher, "i");
})();

(function () {
    var s = http.createServer(server);
    s.on("error", function (e) {
        if (e.code != "EMFILE") {
            console.log(e.toString());
            process.exit(1);
        }
    });
    s.listen(server_config.port, function () {
        var now_s = log_format(get_now()), access_log_sep = "", stats;
        if (server_config.opened_access_log
            && (stats = fs.statSync(server_config.access_log))
            && stats.isFile() && stats.size != 0)
            access_log_sep = "\n";
	access_log.write(util.format("%s- - - [%s] \"START http://%s:%s/\" 0 0\n",
				     access_log_sep, now_s,
				     server_config.host || "localhost",
				     server_config.port));
	console.warn("[%s] HotCRP trackercomet server running at http://%s:%s/",
		     now_s,
		     server_config.host || "localhost",
		     server_config.port);
    });
})();
