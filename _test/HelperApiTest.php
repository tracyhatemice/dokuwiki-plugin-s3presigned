<?php

namespace dokuwiki\plugin\s3presigned\test;

use DokuWikiTest;
use RuntimeException;

/**
 * Exercises the public API other plugins call.
 *
 * @group plugin_s3presigned
 * @group plugins
 */
class HelperApiTest extends DokuWikiTest
{
    protected $pluginsEnabled = ['s3presigned'];

    protected const ACCESS_KEY = 'AKIAIOSFODNN7EXAMPLE';
    protected const SECRET_KEY = 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY';
    protected const KEY_PAIR_ID = 'APKAIOSFODNN7EXAMPLE';

    /** @var string generated per test, so no key material is committed */
    protected $pem;

    public function setUp(): void
    {
        parent::setUp();

        $resource = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        openssl_pkey_export($resource, $this->pem);

        // must be set before any helper instance loads its config: loadConfig()
        // binds $this->conf by reference to this array
        global $conf;
        $conf['plugin']['s3presigned'] = [
            'aws_region' => 'us-east-1',
            'aws_access_key' => self::ACCESS_KEY,
            'aws_secret_key' => self::SECRET_KEY,
            'url_expiration' => 3600,
            'cf_key_pair_id' => self::KEY_PAIR_ID,
            'cf_private_key_file' => '',
            'cf_private_key_pem' => $this->pem,
            'cf_url_expiration' => 3600,
            'cf_cookie_domain' => '',
            'cf_cookie_path' => '/',
        ];
    }

    /**
     * Always a FRESH instance. plugin_load() caches in the global $DOKU_PLUGINS,
     * which DokuWikiTest::setUp() never clears even though it replaces $conf,
     * so a reused helper would read stale, detached configuration.
     *
     * @return \helper_plugin_s3presigned
     */
    protected function helper()
    {
        return plugin_load('helper', 's3presigned', true);
    }

    public function testSignS3UrlUsesConfiguredCredentials()
    {
        $url = $this->helper()->signS3Url('my-bucket', 'docs/report.pdf');

        $this->assertStringStartsWith(
            'https://my-bucket.s3.us-east-1.amazonaws.com/docs/report.pdf?',
            $url
        );
        parse_str(parse_url($url, PHP_URL_QUERY), $query);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $query['X-Amz-Signature']);
        $this->assertStringStartsWith(self::ACCESS_KEY . '/', $query['X-Amz-Credential']);
        $this->assertSame('3600', $query['X-Amz-Expires']);
    }

    public function testSignS3UrlOptionsOverrideConfig()
    {
        $url = $this->helper()->signS3Url('other-bucket', 'a.txt', [
            'region' => 'ap-southeast-1',
            'access_key' => 'AKIAOVERRIDEEXAMPLE',
            'secret_key' => 'overridesecretexamplekey',
            'expires' => 900,
        ]);

        $this->assertStringStartsWith('https://other-bucket.s3.ap-southeast-1.amazonaws.com/a.txt?', $url);
        parse_str(parse_url($url, PHP_URL_QUERY), $query);
        $this->assertStringStartsWith('AKIAOVERRIDEEXAMPLE/', $query['X-Amz-Credential']);
        $this->assertSame('900', $query['X-Amz-Expires']);
    }

    public function testSignS3UrlWithoutCredentialsThrows()
    {
        global $conf;
        $conf['plugin']['s3presigned']['aws_secret_key'] = '';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('AWS credentials not configured');

        $this->helper()->signS3Url('my-bucket', 'a.txt');
    }

    public function testSignCloudFrontUrlUsesConfiguredKey()
    {
        $url = $this->helper()->signCloudFrontUrl('cdn.example.com', 'videos/a.mp4');

        $this->assertStringStartsWith('https://cdn.example.com/videos/a.mp4?', $url);
        parse_str(parse_url($url, PHP_URL_QUERY), $query);
        $this->assertSame(self::KEY_PAIR_ID, $query['Key-Pair-Id']);
        $this->assertNotEmpty($query['Signature']);
    }

    public function testSignCloudFrontUrlExpiryIsAnAbsoluteDeadline()
    {
        $before = time();
        $url = $this->helper()->signCloudFrontUrl('cdn.example.com', 'a.mp4', ['expires' => 900]);
        $after = time();

        parse_str(parse_url($url, PHP_URL_QUERY), $query);

        $this->assertGreaterThanOrEqual($before + 900, (int)$query['Expires']);
        $this->assertLessThanOrEqual($after + 900, (int)$query['Expires']);
    }

    public function testSignCloudFrontUrlAcceptsAnInlinePem()
    {
        $url = $this->helper()->signCloudFrontUrl('cdn.example.com', 'a.mp4', [
            'key_pair_id' => 'APKAOVERRIDEEXAMPLE',
            'private_key' => $this->pem,
        ]);

        parse_str(parse_url($url, PHP_URL_QUERY), $query);
        $this->assertSame('APKAOVERRIDEEXAMPLE', $query['Key-Pair-Id']);
    }

    public function testSignCloudFrontUrlReadsAPrivateKeyFile()
    {
        $path = tempnam(sys_get_temp_dir(), 'cfkey');
        file_put_contents($path, $this->pem);

        try {
            $url = $this->helper()->signCloudFrontUrl('cdn.example.com', 'a.mp4', [
                'private_key_file' => $path,
            ]);
            $this->assertStringContainsString('Signature=', $url);
        } finally {
            unlink($path);
        }
    }

    public function testMissingPrivateKeyFileThrows()
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('CloudFront private key file not found: /nonexistent/key.pem');

        $this->helper()->signCloudFrontUrl('cdn.example.com', 'a.mp4', [
            'private_key_file' => '/nonexistent/key.pem',
        ]);
    }

    public function testUnconfiguredPrivateKeyThrows()
    {
        global $conf;
        $conf['plugin']['s3presigned']['cf_private_key_pem'] = '';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('CloudFront private key not configured');

        $this->helper()->signCloudFrontUrl('cdn.example.com', 'a.mp4');
    }

    public function testEscapedNewlinesInAConfiguredPemAreRestored()
    {
        global $conf;
        // the config UI stores a pasted PEM with literal backslash-n
        $conf['plugin']['s3presigned']['cf_private_key_pem'] = str_replace("\n", '\\n', $this->pem);

        $url = $this->helper()->signCloudFrontUrl('cdn.example.com', 'a.mp4');

        $this->assertStringContainsString('Signature=', $url);
    }

    public function testCloudFrontUrlIsUnsignedAndNeedsNoCredentials()
    {
        global $conf;
        $conf['plugin']['s3presigned']['cf_key_pair_id'] = '';
        $conf['plugin']['s3presigned']['cf_private_key_pem'] = '';

        $url = $this->helper()->cloudFrontUrl('cdn.example.com', 'videos/a b.mp4');

        $this->assertSame('https://cdn.example.com/videos/a%20b.mp4', $url);
    }

    public function testGetCloudFrontCookiesReturnsCookiesAndOptions()
    {
        $signed = $this->helper()->getCloudFrontCookies('cdn.example.com', 'videos/*');

        $this->assertSame(
            ['CloudFront-Policy', 'CloudFront-Signature', 'CloudFront-Key-Pair-Id'],
            array_keys($signed['cookies'])
        );
        $this->assertSame(self::KEY_PAIR_ID, $signed['cookies']['CloudFront-Key-Pair-Id']);

        $this->assertSame('/', $signed['options']['path']);
        $this->assertTrue($signed['options']['secure']);
        $this->assertTrue($signed['options']['httponly']);
        $this->assertSame('None', $signed['options']['samesite']);
    }

    public function testCookieExpiryReflectsTheRequestedDuration()
    {
        $before = time();
        $signed = $this->helper()->getCloudFrontCookies('cdn.example.com', 'videos/*', ['expires' => 120]);
        $after = time();

        $this->assertGreaterThanOrEqual($before + 120, $signed['options']['expires']);
        $this->assertLessThanOrEqual($after + 120, $signed['options']['expires']);
    }

    public function testCookieDomainIsOmittedWhenNotConfigured()
    {
        $signed = $this->helper()->getCloudFrontCookies('cdn.example.com', 'videos/*');

        $this->assertArrayNotHasKey('domain', $signed['options']);
    }

    public function testCookieDomainAndPathComeFromConfig()
    {
        global $conf;
        $conf['plugin']['s3presigned']['cf_cookie_domain'] = '.example.com';
        $conf['plugin']['s3presigned']['cf_cookie_path'] = '/media/';

        $signed = $this->helper()->getCloudFrontCookies('cdn.example.com', 'videos/*');

        $this->assertSame('.example.com', $signed['options']['domain']);
        $this->assertSame('/media/', $signed['options']['path']);
    }

    public function testCookieOptionsCanBeOverriddenPerCall()
    {
        $signed = $this->helper()->getCloudFrontCookies('cdn.example.com', 'videos/*', [
            'cookie_domain' => '.override.test',
            'cookie_path' => '/v/',
            'samesite' => 'Lax',
            'secure' => false,
            'httponly' => false,
        ]);

        $this->assertSame('.override.test', $signed['options']['domain']);
        $this->assertSame('/v/', $signed['options']['path']);
        $this->assertSame('Lax', $signed['options']['samesite']);
        $this->assertFalse($signed['options']['secure']);
        $this->assertFalse($signed['options']['httponly']);
    }

    public function testSendCloudFrontCookiesReportsWhetherItCouldSend()
    {
        // Whether headers are already sent depends on how PHPUnit is buffering
        // output, so assert the guard's contract rather than a fixed value.
        $result = $this->helper()->sendCloudFrontCookies('cdn.example.com', 'videos/*');

        $this->assertSame(!headers_sent(), $result);
    }

    public function provideUnknownOptionCalls()
    {
        return [
            'signS3Url'             => ['signS3Url', ['my-bucket', 'a.txt']],
            'signCloudFrontUrl'     => ['signCloudFrontUrl', ['cdn.example.com', 'a.mp4']],
            'getCloudFrontCookies'  => ['getCloudFrontCookies', ['cdn.example.com', 'videos/*']],
            'sendCloudFrontCookies' => ['sendCloudFrontCookies', ['cdn.example.com', 'videos/*']],
        ];
    }

    /**
     * A typo must not fall through to config and sign with the wrong
     * credentials, which would only fail later at the CDN. This must hold
     * for sendCloudFrontCookies() regardless of header state: validation
     * happens before the headers_sent() guard, not after.
     *
     * @dataProvider provideUnknownOptionCalls
     */
    public function testUnknownOptionThrows($method, $args)
    {
        $args[] = ['no_such_option' => 'x'];

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('no_such_option');

        $this->helper()->$method(...$args);
    }

    public function testCookieOptionsAreRejectedByTheUrlMethods()
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('cookie_path');

        $this->helper()->signCloudFrontUrl('cdn.example.com', 'a.mp4', ['cookie_path' => '/']);
    }

    public function provideInvalidExpiresValues()
    {
        return [
            'zero' => [0],
            'negative' => [-1],
            'non-numeric string' => ['abc'],
        ];
    }

    /**
     * An explicitly supplied expires must fail at the call site rather than
     * silently coercing to the 3600-second default, which could also mask a
     * site's deliberately configured non-default expiration.
     *
     * @dataProvider provideInvalidExpiresValues
     */
    public function testInvalidExpiresThrows($value)
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('expires');

        $this->helper()->signS3Url('my-bucket', 'a.txt', ['expires' => $value]);
    }

    public function testOmittingExpiresStillFallsBackToConfig()
    {
        global $conf;
        $conf['plugin']['s3presigned']['url_expiration'] = 1800;

        $url = $this->helper()->signS3Url('my-bucket', 'a.txt');

        parse_str(parse_url($url, PHP_URL_QUERY), $query);
        $this->assertSame('1800', $query['X-Amz-Expires']);
    }

    public function testGetMethodsDescribesEveryPublicApiMethod()
    {
        $methods = $this->helper()->getMethods();

        $names = array_column($methods, 'name');
        sort($names);

        $this->assertSame([
            'cloudFrontUrl',
            'getCloudFrontCookies',
            'sendCloudFrontCookies',
            'signCloudFrontUrl',
            'signS3Url',
        ], $names);

        // the core info plugin reads all four keys unconditionally
        foreach ($methods as $method) {
            foreach (['name', 'desc', 'params', 'return'] as $key) {
                $this->assertArrayHasKey($key, $method, $method['name'] ?? '?');
            }
            $this->assertNotEmpty($method['desc'], $method['name']);
            $this->assertIsArray($method['params'], $method['name']);
            $this->assertIsArray($method['return'], $method['name']);
        }
    }
}
