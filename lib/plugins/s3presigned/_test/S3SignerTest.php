<?php

namespace dokuwiki\plugin\s3presigned\test;

use dokuwiki\plugin\s3presigned\S3Signer;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * S3Signer holds no DokuWiki dependency, so this extends the plain PHPUnit
 * TestCase rather than DokuWikiTest. That the tests pass at all is itself
 * evidence the class stayed dependency-free.
 *
 * @group plugin_s3presigned
 * @group plugins
 */
class S3SignerTest extends TestCase
{
    protected const ACCESS_KEY = 'AKIAIOSFODNN7EXAMPLE';
    protected const SECRET_KEY = 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY';

    /** pinned so every signature in this file is reproducible */
    protected const NOW = 1772000000;

    /**
     * FROZEN ORACLE.
     *
     * A verbatim copy of syntax_plugin_s3presigned::generatePresignedUrl() as it
     * stood before the refactor, with the config lookups turned into arguments
     * and time() replaced by $timestamp.
     *
     * NEVER edit this to make a test pass. A divergence from S3Signer means the
     * refactor changed behaviour, which is precisely what this file exists to
     * detect.
     */
    protected function legacyPresign($bucket, $objectKey, $region, $accessKey, $secretKey, $expiration, $timestamp)
    {
        $datetime = gmdate('Ymd\THis\Z', $timestamp);
        $date = gmdate('Ymd', $timestamp);

        $host = "{$bucket}.s3.{$region}.amazonaws.com";
        $algorithm = 'AWS4-HMAC-SHA256';
        $credentialScope = "{$date}/{$region}/s3/aws4_request";
        $credential = "{$accessKey}/{$credentialScope}";

        $encodedObjectKey = implode('/', array_map('rawurlencode', explode('/', $objectKey)));
        $canonicalUri = '/' . ltrim($encodedObjectKey, '/');

        $queryParams = array(
            'X-Amz-Algorithm' => $algorithm,
            'X-Amz-Credential' => $credential,
            'X-Amz-Date' => $datetime,
            'X-Amz-Expires' => (string)$expiration,
            'X-Amz-SignedHeaders' => 'host'
        );

        ksort($queryParams);
        $canonicalQueryString = '';
        foreach ($queryParams as $key => $value) {
            if ($canonicalQueryString !== '') {
                $canonicalQueryString .= '&';
            }
            $canonicalQueryString .= rawurlencode($key) . '=' . rawurlencode($value);
        }

        $canonicalHeaders = "host:{$host}\n";
        $signedHeaders = 'host';

        $canonicalRequest = implode("\n", array(
            'GET',
            $canonicalUri,
            $canonicalQueryString,
            $canonicalHeaders,
            $signedHeaders,
            'UNSIGNED-PAYLOAD'
        ));

        $stringToSign = implode("\n", array(
            $algorithm,
            $datetime,
            $credentialScope,
            hash('sha256', $canonicalRequest)
        ));

        $kDate = hash_hmac('sha256', $date, 'AWS4' . $secretKey, true);
        $kRegion = hash_hmac('sha256', $region, $kDate, true);
        $kService = hash_hmac('sha256', 's3', $kRegion, true);
        $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);
        $signature = hash_hmac('sha256', $stringToSign, $kSigning);

        return "https://{$host}{$canonicalUri}?{$canonicalQueryString}&X-Amz-Signature={$signature}";
    }

    public function provideObjects()
    {
        return [
            'plain key'      => ['my-bucket', 'docs/report.pdf', 'us-east-1', 3600],
            'key at root'    => ['my-bucket', 'report.pdf', 'us-east-1', 3600],
            'leading slash'  => ['my-bucket', '/docs/report.pdf', 'us-east-1', 3600],
            'spaces'         => ['my-bucket', 'docs/my report (final).pdf', 'us-west-2', 3600],
            'unicode'        => ['my-bucket', "images/\u{30d5}\u{30a9}\u{30c8}.jpg", 'eu-central-1', 900],
            'ampersand'      => ['my-bucket', 'docs/a&b=c.txt', 'us-east-1', 60],
            'deep path'      => ['other.bucket', 'a/b/c/d/e.txt', 'ap-southeast-1', 604800],
        ];
    }

    /**
     * @dataProvider provideObjects
     */
    public function testMatchesTheLegacyImplementation($bucket, $key, $region, $expires)
    {
        $signer = new S3Signer($region, self::ACCESS_KEY, self::SECRET_KEY);

        $this->assertSame(
            $this->legacyPresign($bucket, $key, $region, self::ACCESS_KEY, self::SECRET_KEY, $expires, self::NOW),
            $signer->presign($bucket, $key, $expires, self::NOW)
        );
    }

    public function testEncodesSegmentsButNotSeparatingSlashes()
    {
        $signer = new S3Signer('us-east-1', self::ACCESS_KEY, self::SECRET_KEY);
        $url = $signer->presign('my-bucket', 'my docs/a b.pdf', 3600, self::NOW);

        $this->assertStringContainsString('/my%20docs/a%20b.pdf?', $url);
        // only the path may be checked for %2F: X-Amz-Credential legitimately
        // contains an encoded slash inside the query string
        $this->assertStringNotContainsString('%2F', parse_url($url, PHP_URL_PATH));
    }

    public function testBuildsRegionalVirtualHostedUrl()
    {
        $signer = new S3Signer('eu-west-3', self::ACCESS_KEY, self::SECRET_KEY);
        $url = $signer->presign('my-bucket', 'a.txt', 3600, self::NOW);

        $this->assertStringStartsWith('https://my-bucket.s3.eu-west-3.amazonaws.com/a.txt?', $url);
    }

    public function testQueryParametersAreSorted()
    {
        $signer = new S3Signer('us-east-1', self::ACCESS_KEY, self::SECRET_KEY);
        $url = $signer->presign('my-bucket', 'a.txt', 3600, self::NOW);

        parse_str(parse_url($url, PHP_URL_QUERY), $query);
        $signed = array_keys($query);
        // X-Amz-Signature is appended after the canonical string, so drop it
        array_pop($signed);

        $sorted = $signed;
        sort($sorted);
        $this->assertSame($sorted, $signed);
    }

    public function testExpiresIsADurationNotATimestamp()
    {
        $signer = new S3Signer('us-east-1', self::ACCESS_KEY, self::SECRET_KEY);
        $url = $signer->presign('my-bucket', 'a.txt', 900, self::NOW);

        $this->assertStringContainsString('X-Amz-Expires=900', $url);
    }

    public function testSignatureIsSha256Hex()
    {
        $signer = new S3Signer('us-east-1', self::ACCESS_KEY, self::SECRET_KEY);
        $url = $signer->presign('my-bucket', 'a.txt', 3600, self::NOW);

        parse_str(parse_url($url, PHP_URL_QUERY), $query);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $query['X-Amz-Signature']);
    }

    /**
     * The oracle calls time() and presign() calls time() again internally
     * for the $now === null default, so the two calls can straddle a second
     * boundary. Accept either second to keep the test deterministic rather
     * than flaky.
     */
    public function testDefaultsToCurrentTime()
    {
        $signer = new S3Signer('us-east-1', self::ACCESS_KEY, self::SECRET_KEY);
        $t = time();
        $expectedAtT = $this->legacyPresign(
            'my-bucket', 'a.txt', 'us-east-1', self::ACCESS_KEY, self::SECRET_KEY, 3600, $t
        );
        $expectedAtTPlus1 = $this->legacyPresign(
            'my-bucket', 'a.txt', 'us-east-1', self::ACCESS_KEY, self::SECRET_KEY, 3600, $t + 1
        );

        $actual = $signer->presign('my-bucket', 'a.txt', 3600);

        $this->assertContains($actual, [$expectedAtT, $expectedAtTPlus1]);
    }

    public function provideMissingCredentials()
    {
        return [
            'no region'     => ['', self::ACCESS_KEY, self::SECRET_KEY],
            'no access key' => ['us-east-1', '', self::SECRET_KEY],
            'no secret key' => ['us-east-1', self::ACCESS_KEY, ''],
        ];
    }

    /**
     * @dataProvider provideMissingCredentials
     */
    public function testEmptyCredentialsThrow($region, $accessKey, $secretKey)
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('AWS credentials not configured');

        new S3Signer($region, $accessKey, $secretKey);
    }
}
