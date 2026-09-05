<?php

namespace dokuwiki\plugin\s3presigned\test;

use DokuWikiTest;
use ReflectionMethod;

/**
 * Pins the one real user-facing guarantee action.php makes: a broken
 * CloudFront key must never stop a page from rendering.
 *
 * handleCookies() itself cannot be exercised here: its headers_sent() guard
 * is the very first statement, and headers_sent() is already true in this
 * process before any test method runs (the DokuWiki fork's own test
 * bootstrap emits output at load time - see the "Warning: Cannot modify
 * header information" lines printed by every run of this suite). So these
 * tests reach past that guard with reflection and exercise
 * sendCookiesForEntries() directly, which is where the dedup-and-send logic
 * actually lives.
 *
 * @group plugin_s3presigned
 * @group plugins
 */
class ActionCookiesTest extends DokuWikiTest
{
    protected $pluginsEnabled = ['s3presigned'];

    public function setUp(): void
    {
        parent::setUp();

        $pem = '';
        $resource = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        openssl_pkey_export($resource, $pem);

        global $conf;
        $conf['plugin']['s3presigned'] = [
            'aws_region' => 'us-east-1',
            'aws_access_key' => 'AKIAIOSFODNN7EXAMPLE',
            'aws_secret_key' => 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY',
            'url_expiration' => 3600,
            'cf_key_pair_id' => 'APKAIOSFODNN7EXAMPLE',
            'cf_private_key_file' => '',
            'cf_private_key_pem' => $pem,
            'cf_url_expiration' => 3600,
            'cf_cookie_domain' => '',
            'cf_cookie_path' => '/',
        ];

        global $DOKU_PLUGINS;
        unset($DOKU_PLUGINS['helper']['s3presigned']);
    }

    protected function sendCookiesForEntries(array $entries)
    {
        $action = plugin_load('action', 's3presigned', true);
        $method = new ReflectionMethod($action, 'sendCookiesForEntries');
        $method->setAccessible(true);
        $method->invoke($action, $entries);
    }

    public function testBrokenKeyIsSwallowed()
    {
        global $conf;
        $conf['plugin']['s3presigned']['cf_private_key_pem'] = '';
        global $DOKU_PLUGINS;
        unset($DOKU_PLUGINS['helper']['s3presigned']);

        try {
            $this->sendCookiesForEntries([
                ['domain' => 'd111abcdef8.cloudfront.net', 'path' => 'videos/*'],
            ]);
        } catch (\Throwable $e) {
            $this->fail('a misconfigured key must not break page rendering, but threw: ' . $e->getMessage());
        }

        // no exception escaped - that is the entire assertion
        $this->addToAssertionCount(1);
    }

    public function testValidConfigCompletesOverMultipleDistinctDomains()
    {
        // headers_sent() is already true in this process, so
        // sendCloudFrontCookies() returns false without emitting anything;
        // this cannot assert how many sends happened, only that the dedup
        // loop runs to completion across several domains without error
        try {
            $this->sendCookiesForEntries([
                ['domain' => 'd111abcdef8.cloudfront.net', 'path' => 'videos/*'],
                ['domain' => 'd222abcdef8.cloudfront.net', 'path' => 'docs/*'],
                ['domain' => 'd111abcdef8.cloudfront.net', 'path' => 'videos/*'], // duplicate domain
            ]);
        } catch (\Throwable $e) {
            $this->fail('valid config must complete without error, but threw: ' . $e->getMessage());
        }

        $this->addToAssertionCount(1);
    }
}
