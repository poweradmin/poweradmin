#!/usr/bin/env perl

use LWP::Simple;

use strict;
use warnings;

# change these values
my $login          = 'username';
my $password       = 'password';
my $domain         = 'mydynamicdns.example.com';
my $poweradmin_url = 'http://www.example.com/poweradmin';
my $up_update_url  = $poweradmin_url . '/dynamic_update.php';
my $ip_lookup_url  = $poweradmin_url . '/addons/clientip.php';
my $verbose        = 1;

my $ip_address = LWP::Simple::get($ip_lookup_url)
  or die("Error: Could not get your global IP address!\n");

# FIXME: doesn't support IPv6
if ( $ip_address !~/^(?:(?:25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)\.){3}(?:25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)$/ )
{
    print
      "Error: Invalid global IP address! Check if Poweradmin url is correct\n";
    exit;
}

print "Updating the IP address ($ip_address) now ... \n" if $verbose;

# insert authentication data to url
$poweradmin_url =~ s/^(http[s]?:\/\/)/$1$login:$password\@/;

my $response =
  LWP::Simple::get( "$poweradmin_url/dynamic_update.php"
      . "?hostname=$domain&myip=$ip_address&verbose=$verbose" )
  or die($!);

if ( !defined $response || $response eq "" ) {
    print "Error: Could not contact your poweradmin web server\n";
    exit(0);
}

print "Status: $response\n" if $verbose;
