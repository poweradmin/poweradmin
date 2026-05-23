#!/usr/bin/env python3

import argparse
import ipaddress
import socket
from unittest.mock import patch

import requests
from requests.auth import HTTPBasicAuth

# Defaults used when no command-line argument is supplied. Edit for unattended runs.
DEFAULTS = {
    "login": "username",
    "password": "password",
    "dyndns": "mydynamicdns.example.com",
    "url": "http://www.example.com/poweradmin",
}

parser = argparse.ArgumentParser(description='Client for Poweradmin dynamic DNS')
parser.add_argument('-l', '--login', help='Poweradmin user name')
parser.add_argument('-p', '--password', help='Poweradmin user password')
parser.add_argument('-d', '--dyndns', help='Dynamic DNS name (FQDN)')
parser.add_argument('-u', '--url', help='Poweradmin base URL')
parser.add_argument('-v', '--verbose', action='store_true', help='Print progress messages')
args = parser.parse_args()

login = args.login or DEFAULTS["login"]
password = args.password or DEFAULTS["password"]
dyndns = args.dyndns or DEFAULTS["dyndns"]
url = args.url or DEFAULTS["url"]
verbose = args.verbose

ip_lookup_url = url + "/addons/clientip.php"

orig_getaddrinfo = socket.getaddrinfo


def _getaddrinfo_family(family):
    def _resolver(host, port, type=0, proto=0, flags=0):
        return orig_getaddrinfo(host=host, port=port, family=family, type=type, proto=proto, flags=flags)
    return _resolver


def _discover_ip(family):
    try:
        with patch('socket.getaddrinfo', side_effect=_getaddrinfo_family(family)):
            response = requests.get(ip_lookup_url, timeout=10)
        response.raise_for_status()
    except requests.RequestException:
        return ''
    candidate = response.text.strip()
    try:
        ipaddress.ip_address(candidate)
    except ValueError:
        return ''
    return candidate


ipv4 = _discover_ip(socket.AF_INET)
ipv6 = _discover_ip(socket.AF_INET6)

if not ipv4 and not ipv6:
    print("Error: Could not discover any public IP address via " + ip_lookup_url)
    raise SystemExit(1)


def _push_update(ip):
    if verbose:
        print("Updating the IP address (" + ip + ") now ...")
    verbose_flag = "1" if verbose else "0"
    response = requests.get(
        url + "/dynamic_update.php?hostname=" + dyndns + "&myip=" + ip + "&verbose=" + verbose_flag,
        auth=HTTPBasicAuth(login, password),
        timeout=10,
    )
    if verbose:
        print("Status: " + response.text.strip())


if ipv4:
    _push_update(ipv4)
if ipv6:
    _push_update(ipv6)
