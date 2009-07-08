#!/usr/bin/perl -w
use strict;
use warnings;
use LWP::Simple;
use Socket;



# set these four up#

my $login = 'usernameforzoneadmin';
my $password = 'passwordforzoneadmin';
my $domain = 'remoteofficecableinternet.powerdns.example.com';
my $poweradmin = 'powerdns.example.com';

#####################



my $ipaddress = LWP::Simple::get("http://www.whatismyip.com");
if ($ipaddress =~ /([0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3})/) 
{ 
  $ipaddress = $1; 
}

print "Updating the IP address ($ipaddress) now ... \n";


my $response = LWP::Simple::get("http://$login:$password\@" .
"$poweradmin/dynamic_update.php?hostname=$domain" .
"&myip=$ipaddress&verbose=1");
if (!defined $response || $response eq "")
{
print "Status : Could not contact your poweradmin web server\n";
exit(0);
}

print "Status : $response\n";

