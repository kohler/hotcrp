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
    last if /^(?:BALLOTS|RBALLOTS)/;
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
my($rballots) = (/^RBALLOTS/ ? 1 : 0);
while (<DATA>) {
    next if /^$/ || /^\s*\#/;
    chomp $_;
    last if /^RATINGS/;
    $_ = join(" ", split(//)) if !/[\s,]/;
    print "insert into ContactInfo (contactId, firstName, lastName, email, password, collaborators, creationTime, roles) values ($voternum, 'Jane', 'Voter" . ($voternum - $voterdelta) . "', 'comm" . ($voternum - $voterdelta) . "\@_.com', 'x', 'None', $now, 1) on duplicate key update firstName=firstName;\n";
    print "insert into PCMember (contactId) values ($voternum) on duplicate key update contactId=contactId;\n";
    my($p, $r, $i);
    $i = 0;
    while (/\A(\d+|x)\s*([,=]?)\s*(.*)\z/) {
	$_ = $3;
	++$i;
	next if !$rballots && $1 eq "x";
	$p = $rballots ? $1 : $i;
	$r = $rballots ? $i : $1;
	die "VOTE FOR BAD PAPER $p" if !$papers[$p];
	print "insert into PaperTag (paperId, tag, tagIndex) values ($p, '$voternum~r', $r) on duplicate key update tagIndex=$r;\n";
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

# FILE FORMAT:
# Paper information: PAPERNUMBER [tab] PAPERTITLE
# "BALLOTS"
# Ballots: e.g. 3142 means paper #1 is ranked 3rd, paper #2 is ranked 1st, etc.;
#  leave "x" marks for unranked papers.
# OR: "RBALLOTS"
# e.g. 349867251 means paper #3 is ranked 1st, etc.
__DATA__
# Paper information: PAPERNUMBER [tab] PAPERTITLE
1	A
2	B
3	C
4	D
5	E
6	F
7	G
8	H
9	I
RBALLOTS
# Ballots: e.g. 3142 means paper #1 is ranked 3rd, paper #2 is ranked 1st, etc.
349867251
36459
3975
438
4389
463981572
48375912
493518762
493581627
495732168
537
59436128
743
895312
943
96437815
987236145
RATINGS
