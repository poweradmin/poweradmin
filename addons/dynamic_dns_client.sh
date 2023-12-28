#!/bin/bash

# Change these values
login='username'
password='password'
domain='mydynamicdns.example.com'
poweradmin_url='https://www.example.com/poweradmin'
ip_lookup_url="$poweradmin_url/addons/clientip.php"
verbose=1

validate_ip() {
    if [[ $1 =~ ^([0-9a-fA-F]{0,4}:){2}([0-9a-fA-F]{0,4}:){0,5}[0-9a-fA-F]{0,4}$|^([0-9]{1,3}\.){3}[0-9]{1,3}$ ]]; then
        return 0
    else
        return 1
    fi
}

ip_address=$(curl -s "$ip_lookup_url")
if [ $? -ne 0 ] || [ -z "$ip_address" ]; then
    echo "Error: Could not get your global IP address!"
    exit 1
fi

if ! validate_ip "$ip_address"; then
    echo "Error: Invalid global IP address! Check if Poweradmin url is correct"
    exit 1
fi

[ $verbose -eq 1 ] && echo "Updating the IP address ($ip_address) now ..."

auth_url=$(echo "$poweradmin_url" | sed "s#^http[s]*://#&$login:$password@#")

response=$(curl -s "$auth_url/dynamic_update.php?hostname=$domain&myip=$ip_address&verbose=$verbose")
if [ $? -ne 0 ] || [ -z "$response" ]; then
    echo "Error: Could not contact your poweradmin web server"
    exit 1
fi

[ $verbose -eq 1 ] && echo "Status: $response"
