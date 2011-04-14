#! /usr/bin/perl

$now = time();

if ($ARGV[0] =~ /^--voteengine$/) {
    my(%v, $maxp);
    while (<DATA>) {
	if (/^(\d+)\s+(\d+)~r\s+(\d+)$/) {
	    $v{$2} = [] if !$v{$2};
	    $v{$2}->[$3] = [] if !$v{$2}->[$3];
	    push @{$v{$2}->[$3]}, $1;
	    $maxp = $1 if !$maxp || $1 > $maxp;
	}
    }
    print "-m schulze -cands 1-$maxp\n";
    foreach $vx (values(%v)) {
	for ($i = 0; $i < @$vx; ++$i) {
	    if ($vx->[$i]) {
		print join(" = ", @{$vx->[$i]}), " ";
	    }
	}
	print "\n";
    }
    exit 0;
}

print "delete from ContactInfo;\n";
print "delete from PCMember;\n";
print "delete from Chair;\n";
print "delete from Paper;\n";
print "delete from PaperReview;\n";
print "delete from PaperConflict;\n";
print "delete from PaperTag;\n";

print "insert into ContactInfo (contactId, firstName, lastName, email, password, collaborators, creationTime, roles) values (1, 'Janette', 'Chair', 'chair\@_.com', 'chair', 'None', $now, 7);\n";
print "insert into PCMember (contactId) values (1);\n";
print "insert into Chair (contactId) values (1);\n";

print "insert into Settings (name, value, data) values ('rev_open', $now, null), ('tag_rank', 1, 'r') on duplicate key update value=values(value), data=values(data);\n";

my(@papers);
while (<DATA>) {
    next if /^$/ || /^\s*\#/;
    last if /^BALLOTS/;
    chomp $_;
    my($p, $n) = split(/\t/);
    die "PAPER $p DUPLICATE\n" if $papers[$p];
    $papers[$p] = 1;
    print "insert into Paper (paperId, title, authorInformation, abstract, timeSubmitted) values ($p, '$n', 'Jane\\tAuthor$p\\tauthor$p\@_.com\\t\\n', 'This is Paper $p', $now) on duplicate key update abstract=abstract;\n";
    print "insert into ContactInfo (firstName, lastName, email, password) values ('Jane', 'Author$p', 'author$p\@_.com', 'x') on duplicate key update password=password;\n";
    print "insert into PaperConflict (paperId, contactId, conflictType) values ($p, (select contactId from ContactInfo where email='author$p\@_.com'), 10) on duplicate key update conflictType=conflictType;\n";
}

my($voternum) = @papers + 1;
my($voterdelta) = $voternum - 1;
while (<DATA>) {
    next if /^$/ || /^\s*\#/;
    chomp $_;
    my(@x) = split(//);
    last if /^RATINGS/;
    print "insert into ContactInfo (contactId, firstName, lastName, email, password, collaborators, creationTime, roles) values ($voternum, 'Jane', 'Voter" . ($voternum - $voterdelta) . "', 'comm" . ($voternum - $voterdelta) . "\@_.com', 'x', 'None', $now, 1) on duplicate key update firstName=firstName;\n";
    print "insert into PCMember (contactId) values ($voternum) on duplicate key update contactId=contactId;\n";
    for ($i = 0; $i < @x; ++$i) {
	if ($x[$i] ne 'x') {
	    die "VOTE FOR BAD PAPER " . ($i+1) if !$papers[$i+1];
	    print "insert into PaperTag (paperId, tag, tagIndex) values (" . ($i+1) . ", '$voternum~r', " . $x[$i] . ") on duplicate key update tagIndex=" . $x[$i] . ";\n";
	}
    }
    ++$voternum;
}

while (<DATA>) {
    next if /^$/ || /^\s*\#/;
    my($p, $c, $r) = split;
    $ns[$p] = 0 if !$ns[$p];
    my($ro) = ++$ns[$p];
    print "insert into PaperReview (paperId, contactId, reviewType, reviewModified, reviewSubmitted, reviewOrdinal, reviewNeedsSubmit, overAllMerit) values ($p, $c, 2, $now, $now, $ro, 0, $r) on duplicate key update reviewType=reviewType;\n";
}

__DATA__
# Paper information: PAPERNUMBER [tab] PAPERTITLE
BALLOTS
# Ballots: e.g. 3142 means paper #1 is ranked 3rd, paper #2 is ranked 1st, etc.
RATINGS
