<?php

namespace dokuwiki\plugin\s3presigned\test;

use dokuwiki\plugin\s3presigned\CloudFrontSigner;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @group plugin_s3presigned
 * @group plugins
 */
class CloudFrontSignerTest extends TestCase
{
    protected const KEY_PAIR_ID = 'APKAIOSFODNN7EXAMPLE';
    protected const EXPIRES_AT = 1772003600;

    /** @var string PEM private key, generated per test so none is committed */
    protected $pem;

    /** @var string matching public key, for verifying signatures */
    protected $publicKey;

    public function setUp(): void
    {
        parent::setUp();

        $resource = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        $this->assertNotFalse($resource, 'could not generate an RSA key');

        openssl_pkey_export($resource, $this->pem);
        $this->publicKey = openssl_pkey_get_details($resource)['key'];
    }

    /**
     * FROZEN ORACLE.
     *
     * A verbatim copy of syntax_plugin_s3presigned_cloudfront::generateSignedUrl()
     * as it stood before the refactor, with config lookups turned into arguments
     * and the expiry passed in rather than computed.
     *
     * NEVER edit this to make a test pass.
     */
    protected function legacySignUrl($domain, $objectPath, $keyPairId, $pem, $expiration)
    {
        $privateKey = openssl_pkey_get_private($pem);

        $encodedPath = implode('/', array_map('rawurlencode', explode('/', $objectPath)));
        $url = "https://{$domain}/" . ltrim($encodedPath, '/');

        $policy = '{"Statement":[{"Resource":"' . $url . '","Condition":{"DateLessThan":{"AWS:EpochTime":' . $expiration . '}}}]}';

        $signature = '';
        openssl_sign($policy, $signature, $privateKey, OPENSSL_ALGO_SHA1);

        return $url
            . (strpos($url, '?') !== false ? '&' : '?')
            . 'Expires=' . $expiration
            . '&Signature=' . strtr(base64_encode($signature), '+/=', '-~_')
            . '&Key-Pair-Id=' . $keyPairId;
    }

    /**
     * FROZEN ORACLE.
     *
     * A verbatim copy of the policy construction inside
     * action_plugin_s3presigned::handleCookies() as it stood before the refactor.
     *
     * NEVER edit this to make a test pass.
     */
    protected function legacyCookiePolicy($domain, $path, $keyPairId, $pem, $expiration)
    {
        $privateKey = openssl_pkey_get_private($pem);

        $resourceUrl = "https://{$domain}/{$path}";

        $policy = json_encode(array(
            'Statement' => array(array(
                'Resource' => $resourceUrl,
                'Condition' => array(
                    'DateLessThan' => array('AWS:EpochTime' => $expiration)
                )
            ))
        ));

        $signature = '';
        openssl_sign($policy, $signature, $privateKey, OPENSSL_ALGO_SHA1);

        return array(
            'CloudFront-Policy' => strtr(base64_encode($policy), '+/=', '-~_'),
            'CloudFront-Signature' => strtr(base64_encode($signature), '+/=', '-~_'),
            'CloudFront-Key-Pair-Id' => $keyPairId,
        );
    }

    protected function signer()
    {
        return new CloudFrontSigner(self::KEY_PAIR_ID, $this->pem);
    }

    /** decode CloudFront's non-standard url-safe base64 */
    protected function decode($value)
    {
        return base64_decode(strtr($value, '-~_', '+/='));
    }

    public function providePaths()
    {
        return [
            'plain'         => ['d111abcdef8.cloudfront.net', 'images/photo.jpg'],
            'at root'       => ['d111abcdef8.cloudfront.net', 'photo.jpg'],
            'leading slash' => ['d111abcdef8.cloudfront.net', '/images/photo.jpg'],
            'spaces'        => ['d111abcdef8.cloudfront.net', 'docs/my report.pdf'],
            'unicode'       => ['cdn.example.com', "images/\u{30d5}\u{30a9}\u{30c8}.jpg"],
            'deep'          => ['cdn.example.com', 'a/b/c/d/e.mp4'],
        ];
    }

    /**
     * @dataProvider providePaths
     */
    public function testSignedUrlMatchesTheLegacyImplementation($domain, $path)
    {
        $this->assertSame(
            $this->legacySignUrl($domain, $path, self::KEY_PAIR_ID, $this->pem, self::EXPIRES_AT),
            $this->signer()->signUrl($domain, $path, self::EXPIRES_AT)
        );
    }

    public function provideResources()
    {
        return [
            'wildcard'  => ['d111abcdef8.cloudfront.net', 'videos/*'],
            'directory' => ['d111abcdef8.cloudfront.net', 'gallery/'],
            'exact'     => ['cdn.example.com', 'docs/report.pdf'],
        ];
    }

    /**
     * @dataProvider provideResources
     */
    public function testCookiePolicyMatchesTheLegacyImplementation($domain, $resource)
    {
        $this->assertSame(
            $this->legacyCookiePolicy($domain, $resource, self::KEY_PAIR_ID, $this->pem, self::EXPIRES_AT),
            $this->signer()->cookiePolicy($domain, $resource, self::EXPIRES_AT)
        );
    }

    public function testSignedUrlSignatureVerifiesAgainstThePublicKey()
    {
        $url = $this->signer()->signUrl('cdn.example.com', 'a/b.jpg', self::EXPIRES_AT);

        // the canned policy signs the bare URL, before the query string is appended
        $signedUrl = 'https://cdn.example.com/a/b.jpg';
        $policy = '{"Statement":[{"Resource":"' . $signedUrl . '","Condition":{"DateLessThan":{"AWS:EpochTime":' . self::EXPIRES_AT . '}}}]}';

        parse_str(parse_url($url, PHP_URL_QUERY), $query);

        $this->assertSame(
            1,
            openssl_verify($policy, $this->decode($query['Signature']), $this->publicKey, OPENSSL_ALGO_SHA1)
        );
    }

    public function testCookieSignatureVerifiesAgainstThePublicKey()
    {
        $cookies = $this->signer()->cookiePolicy('cdn.example.com', 'videos/*', self::EXPIRES_AT);

        $this->assertSame(
            1,
            openssl_verify(
                $this->decode($cookies['CloudFront-Policy']),
                $this->decode($cookies['CloudFront-Signature']),
                $this->publicKey,
                OPENSSL_ALGO_SHA1
            )
        );
    }

    public function testEncodedValuesAvoidCharactersCloudFrontRejects()
    {
        $cookies = $this->signer()->cookiePolicy('cdn.example.com', 'videos/*', self::EXPIRES_AT);

        foreach (['CloudFront-Policy', 'CloudFront-Signature'] as $name) {
            $this->assertDoesNotMatchRegularExpression('~[+/=]~', $cookies[$name], $name);
        }
    }

    public function testCookiePolicyKeepsWildcardsUnencoded()
    {
        $cookies = $this->signer()->cookiePolicy('cdn.example.com', 'videos/*', self::EXPIRES_AT);
        $policy = json_decode($this->decode($cookies['CloudFront-Policy']), true);

        $this->assertSame('https://cdn.example.com/videos/*', $policy['Statement'][0]['Resource']);
        $this->assertSame(self::EXPIRES_AT, $policy['Statement'][0]['Condition']['DateLessThan']['AWS:EpochTime']);
    }

    public function testSignedUrlEncodesPathSegments()
    {
        $url = $this->signer()->signUrl('cdn.example.com', 'my docs/a b.pdf', self::EXPIRES_AT);

        $this->assertStringStartsWith('https://cdn.example.com/my%20docs/a%20b.pdf?', $url);
    }

    public function testSignedUrlCarriesExpiryAndKeyPairId()
    {
        $url = $this->signer()->signUrl('cdn.example.com', 'a.jpg', self::EXPIRES_AT);

        parse_str(parse_url($url, PHP_URL_QUERY), $query);

        $this->assertSame((string)self::EXPIRES_AT, $query['Expires']);
        $this->assertSame(self::KEY_PAIR_ID, $query['Key-Pair-Id']);
    }

    public function testUnsignedUrlHasNoQueryString()
    {
        $this->assertSame(
            'https://cdn.example.com/a/b%20c.jpg',
            CloudFrontSigner::url('cdn.example.com', 'a/b c.jpg')
        );
    }

    public function testEmptyKeyPairIdThrows()
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('CloudFront Key Pair ID not configured');

        new CloudFrontSigner('', $this->pem);
    }

    public function testEmptyPemThrows()
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('CloudFront private key not configured');

        new CloudFrontSigner(self::KEY_PAIR_ID, '');
    }

    public function testMalformedPemThrows()
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid RSA private key');

        new CloudFrontSigner(self::KEY_PAIR_ID, '-----BEGIN PRIVATE KEY----- nonsense -----END PRIVATE KEY-----');
    }
}
