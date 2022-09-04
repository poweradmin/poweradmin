#!/usr/bin/env python3

# change these values if not given by arguments to the script
login = "username"
password = "password"
dyndns = "mydynamicdns.example.com"
poweradmin = 'http://www.example.com/poweradmin'
verbose = 1

import argparse
import requests
import socket
from unittest.mock import patch
from requests.auth import HTTPBasicAuth

parser = argparse.ArgumentParser(description='Client for PowerAdmin dynamic DNS')
parser.add_argument('-l', '--login', dest='login', help='PowerAdmin user name')
parser.add_argument('-p', '--password', dest='password', help='PowerAdmin user password')
parser.add_argument('-d', '--dyndns', dest='dyndns', help='Dynamic DNS name')
parser.add_argument('-u', '--poweradmin', dest='poweradmin', help='PowerAdmin URL')
parser.add_argument('-v', '--verbose', dest='verbose', help='Output verbosity')
args = parser.parse_args()

if hasattr(args, "login"):
    login = args.login
if hasattr(args, "password"):
    password = args.password
if hasattr(args, "dyndns"):
    dyndns = args.dyndns
if hasattr(args, "poweradmin"):
    poweradmin = args.poweradmin
if hasattr(args, "verbose"):
    verbose = args.verbose

ip_lookup_url  = poweradmin + '/addons/clientip.php'

ipv4 = ''
ipv6 = ''

orig_getaddrinfo = socket.getaddrinfo
def getaddrinfoIPv6(host, port, family=0, type=0, proto=0, flags=0):
    return orig_getaddrinfo(host=host, port=port, family=socket.AF_INET6, type=type, proto=proto, flags=flags)

def getaddrinfoIPv4(host, port, family=0, type=0, proto=0, flags=0):
    return orig_getaddrinfo(host=host, port=port, family=socket.AF_INET, type=type, proto=proto, flags=flags)

with patch('socket.getaddrinfo', side_effect=getaddrinfoIPv6):
    r = requests.get(ip_lookup_url)
    ipv6 = r.text

with patch('socket.getaddrinfo', side_effect=getaddrinfoIPv4):
    r = requests.get(ip_lookup_url)
    ipv4 = r.text

if verbose:
    print ("Updating the IP address (" + ipv4 + ") now ...")
response = requests.get(poweradmin + "/dynamic_update.php?hostname=" + dyndns + "&myip=" + ipv4 + "&verbose=" + str(verbose), auth = HTTPBasicAuth(login, password))
if verbose:
    print ("Status: " + response.text)

if verbose:
    print ("Updating the IP address (" + ipv6 + ") now ...")
response = requests.get(poweradmin + "/dynamic_update.php?hostname=" + dyndns + "&myip=" + ipv6 + "&verbose=" + str(verbose), auth = HTTPBasicAuth(login, password))
if verbose:
    print ("Status: " + response.text)
