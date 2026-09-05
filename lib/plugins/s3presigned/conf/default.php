<?php
$conf['aws_region'] = 'us-east-1';
$conf['aws_access_key'] = '';
$conf['aws_secret_key'] = '';
$conf['url_expiration'] = 3600; // URL valid for 1 hour

// CloudFront settings
$conf['cf_key_pair_id']      = '';       // CloudFront Key Pair ID
$conf['cf_private_key_file'] = '';       // Path to PEM private key file on server
$conf['cf_private_key_pem']  = '';       // Direct-paste PEM private key (fallback if file not set)
$conf['cf_url_expiration']   = 3600;     // Signed URL/cookie expiration in seconds
$conf['cf_cookie_domain']    = '';       // Domain for signed cookies (e.g., .example.com)
$conf['cf_cookie_path']      = '/';      // Path scope for signed cookies
