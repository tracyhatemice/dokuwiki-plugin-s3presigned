<?php
$meta['aws_region'] = array('string');
$meta['aws_access_key'] = array('string');
$meta['aws_secret_key'] = array('password');
$meta['url_expiration'] = array('numeric');

// CloudFront settings
$meta['cf_key_pair_id']      = array('string');
$meta['cf_private_key_file'] = array('string');
$meta['cf_private_key_pem']  = array('password');
$meta['cf_url_expiration']   = array('numeric');
$meta['cf_cookie_domain']    = array('string');
$meta['cf_cookie_path']      = array('string');
