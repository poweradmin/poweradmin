#!/usr/bin/perl

use strict;
use warnings;

use File::Find;

my @date = localtime(time);
my $curr_year = $date[5] + 1900;
my $last_year = $curr_year - 1;

$curr_year = 2012;
$last_year = 2011;

sub process_file {
	my $fn = $File::Find::name;
	
	return if ($fn =~ /\.svn/);
	return if (-d $_);

	my $found = 0;

	open(FH, "$_") or die $!." <$fn>\n";
	while (my $line = <FH>) {
		if ($line =~ /Copyright 2010-$last_year  Poweradmin/) {
			$found = 1;
			last;
		}
	}
	close(FH);

	if ($found) {
		open(IN, "< $_") or die $!." <$fn>\n";
		open(OUT, "> $_.new") or die $!." <$fn.new>\n";

		while (my $line = <IN>) {
			if ($line =~ /Copyright 2010-$last_year  Poweradmin/) {
				print "Updating copyright in <$fn>\n";
				$line =~ s/2010-$last_year/2010-$curr_year/;
				$line =~ s/\s$//;
			}	
			print OUT $line;
		}

		close(OUT);
		close(IN);
	
		unlink $_;
		rename("$_.new", "$_");
	}
}

my @dirs = ('../');
find( \&process_file, @dirs );
