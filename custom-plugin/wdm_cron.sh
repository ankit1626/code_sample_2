#!/bin/bash

# Command 1: Create or clear the cookies file
rm cookies.txt
secret='your-secret-here'
page='0'
hmac=$(echo -n "$page" | openssl dgst -sha256 -hmac "$secret" | awk '{print $2}')
# Command 2: Use cURL to hit the URL, follow redirects, and manage sessions
curl -L --connect-timeout 0 --max-time 0 --max-redirs 5000 --cookie cookies.txt --cookie-jar cookies.txt "https://localhost/wp-json/ss/label-gen?lb=$hmac"
