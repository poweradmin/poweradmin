#!/usr/bin/env perl

use LWP::Simple;
use LWP::UserAgent;
use HTTP::Request;

use strict;
use warnings;

# change these values
my $login = 'username';
my $password = 'password';
my $domain = 'mydynamicdns.example.com';
my $poweradmin_url = 'https://www.example.com/poweradmin';
my $ip_update_url = $poweradmin_url . '/dynamic_update.php';
my $ip_lookup_url = $poweradmin_url . '/addons/clientip.php';
my $verbose = 1;

my $ip_address = LWP::Simple::get($ip_lookup_url)
    or die("Error: Could not get your global IP address!\n");

if ($ip_address !~ /^(?:[0-9a-fA-F]{0,4}:){2}(?:[0-9a-fA-F]{0,4}:){0,5}[0-9a-fA-F]{0,4}|(?:\d{1,3}\.){3}\d{1,3}$/) {
    print "Error: Invalid global IP address! Check if Poweradmin url is correct\n";
    exit;
}

print "Updating the IP address ($ip_address) now ... \n" if $verbose;

# Send the credentials as a Basic Authorization header with the update request.
my $request = HTTP::Request->new(GET => $ip_update_url . "?hostname=$domain&myip=$ip_address&verbose=$verbose");
$request->authorization_basic($login, $password);
my $reply = LWP::UserAgent->new->request($request);
die("Error: " . $reply->status_line . "\n") unless $reply->is_success;
my $response = $reply->decoded_content;

if (!defined $response || $response eq "") {
    print "Error: Could not contact your poweradmin web server\n";
    exit(0);
}

print "Status: $response\n" if $verbose;
