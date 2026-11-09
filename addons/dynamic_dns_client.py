#!/usr/bin/env python3

import argparse
import os
import requests
import socket
from unittest.mock import patch
from requests.auth import HTTPBasicAuth

# change these values if not given by arguments to the script
login = "username"
password = "password"
dyndns = "mydynamicdns.example.com"
url = "https://www.example.com/poweradmin"
# 0 keeps unattended (cron) runs silent, as they were before -v was honoured
verbose = 0

parser = argparse.ArgumentParser(description='Client for Poweradmin dynamic DNS')
parser.add_argument('-l', '--login', dest='login', help='Poweradmin user name')
parser.add_argument('-p', '--password', dest='password', help='Poweradmin user password (visible in the process list; prefer the POWERADMIN_DDNS_PASSWORD environment variable)')
parser.add_argument('-d', '--dyndns', dest='dyndns', help='Dynamic DNS name')
parser.add_argument('-u', '--url', dest='url', help='Poweradmin URL')
parser.add_argument('-v', '--verbose', dest='verbose', type=int, help='Output verbosity (1 or 0)')
args = parser.parse_args()

if args.login is not None:
    login = args.login
if args.password:
    password = args.password
elif os.environ.get("POWERADMIN_DDNS_PASSWORD"):
    password = os.environ["POWERADMIN_DDNS_PASSWORD"]
if args.dyndns is not None:
    dyndns = args.dyndns
if args.url is not None:
    url = args.url.rstrip('/')
if args.verbose is not None:
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
    url + "/dynamic_update.php?hostname=" + dyndns + "&myip6=" + ipv6 + "&verbose=" + str(verbose),
    auth=HTTPBasicAuth(login, password))
if verbose:
    print("Status: " + response.text)
