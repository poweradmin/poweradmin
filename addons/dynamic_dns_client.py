#!/usr/bin/env python3

import argparse
import requests
import socket
from unittest.mock import patch
from requests.auth import HTTPBasicAuth

# change these values if not given by arguments to the script
login = "username"
password = "password"
dyndns = "mydynamicdns.example.com"
url = "http://www.example.com/poweradmin"
verbose = 1

parser = argparse.ArgumentParser(description='Client for Poweradmin dynamic DNS')
parser.add_argument('-l', '--login', dest='login', help='Poweradmin user name')
parser.add_argument('-p', '--password', dest='password', help='Poweradmin user password')
parser.add_argument('-d', '--dyndns', dest='dyndns', help='Dynamic DNS name')
parser.add_argument('-u', '--url', dest='url', help='Poweradmin URL')
parser.add_argument('-v', '--verbose', dest='verbose', help='Output verbosity')
args = parser.parse_args()

if hasattr(args, "login"):
    login = args.login
if hasattr(args, "password"):
    password = args.password
if hasattr(args, "dyndns"):
    dyndns = args.dyndns
if hasattr(args, "poweradmin"):
    url = args.poweradmin
if hasattr(args, "verbose"):
    verbose = args.verbose

ip_lookup_url = url + "/addons/clientip.php"

ipv4 = ''
ipv6 = ''

orig_getaddrinfo = socket.getaddrinfo


def get_addr_info_ipv6(host, port, type=0, proto=0, flags=0):
    return orig_getaddrinfo(host=host, port=port, family=socket.AF_INET6, type=type, proto=proto, flags=flags)


def get_addr_info_ipv4(host, port, type=0, proto=0, flags=0):
    return orig_getaddrinfo(host=host, port=port, family=socket.AF_INET, type=type, proto=proto, flags=flags)


with patch('socket.getaddrinfo', side_effect=get_addr_info_ipv6):
    r = requests.get(ip_lookup_url)
    ipv6 = r.text

with patch('socket.getaddrinfo', side_effect=get_addr_info_ipv4):
    r = requests.get(ip_lookup_url)
    ipv4 = r.text

if verbose:
    print("Updating the IP address (" + ipv4 + ") now ...")
response = requests.get(
    url + "/dynamic_update.php?hostname=" + dyndns + "&myip=" + ipv4 + "&verbose=" + str(verbose),
    auth=HTTPBasicAuth(login, password))
if verbose:
    print("Status: " + response.text)

if verbose:
    print("Updating the IP address (" + ipv6 + ") now ...")
response = requests.get(
    url + "/dynamic_update.php?hostname=" + dyndns + "&myip=" + ipv6 + "&verbose=" + str(verbose),
    auth=HTTPBasicAuth(login, password))
if verbose:
    print("Status: " + response.text)
