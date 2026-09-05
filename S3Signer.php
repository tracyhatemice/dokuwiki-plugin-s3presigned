<?php
/**
 * DokuWiki Plugin s3presigned (S3 signer)
 *
 * @license GPL 2 http://www.gnu.org/licenses/gpl-2.0.html
 * @author  tracyhatemice
 */

namespace dokuwiki\plugin\s3presigned;

use RuntimeException;

/**
 * Builds AWS Signature Version 4 presigned GET URLs for S3 objects.
 *
 * Deliberately free of DokuWiki dependencies: config resolution belongs to
 * helper_plugin_s3presigned, so this class can be reasoned about and tested
 * on its own.
 */
class S3Signer
{
    protected $region;
    protected $accessKey;
    protected $secretKey;

    public function __construct(string $region, string $accessKey, string $secretKey)
    {
        if ($region === '' || $accessKey === '' || $secretKey === '') {
            throw new RuntimeException('AWS credentials not configured');
        }

        $this->region = $region;
        $this->accessKey = $accessKey;
        $this->secretKey = $secretKey;
    }

    /**
     * Presign a GET request for an object.
     *
     * @param int      $expires lifetime in seconds; X-Amz-Expires is a duration, not a deadline
     * @param int|null $now     unix timestamp to sign at, for reproducible tests
     */
    public function presign(string $bucket, string $objectKey, int $expires, ?int $now = null): string
    {
        if ($now === null) $now = time();

        $datetime = gmdate('Ymd\THis\Z', $now);
        $date = gmdate('Ymd', $now);

        $host = "{$bucket}.s3.{$this->region}.amazonaws.com";
        $algorithm = 'AWS4-HMAC-SHA256';
        $credentialScope = "{$date}/{$this->region}/s3/aws4_request";
        $credential = "{$this->accessKey}/{$credentialScope}";

        $canonicalUri = '/' . ltrim(self::encodePath($objectKey), '/');

        $queryParams = [
            'X-Amz-Algorithm' => $algorithm,
            'X-Amz-Credential' => $credential,
            'X-Amz-Date' => $datetime,
            'X-Amz-Expires' => (string)$expires,
            'X-Amz-SignedHeaders' => 'host',
        ];
        ksort($queryParams);

        $canonicalQueryString = '';
        foreach ($queryParams as $key => $value) {
            if ($canonicalQueryString !== '') $canonicalQueryString .= '&';
            $canonicalQueryString .= rawurlencode($key) . '=' . rawurlencode($value);
        }

        $canonicalRequest = implode("\n", [
            'GET',
            $canonicalUri,
            $canonicalQueryString,
            "host:{$host}\n",
            'host',
            'UNSIGNED-PAYLOAD',
        ]);

        $stringToSign = implode("\n", [
            $algorithm,
            $datetime,
            $credentialScope,
            hash('sha256', $canonicalRequest),
        ]);

        $signature = hash_hmac('sha256', $stringToSign, $this->signingKey($date));

        return "https://{$host}{$canonicalUri}?{$canonicalQueryString}&X-Amz-Signature={$signature}";
    }

    /**
     * rawurlencode each path segment, leaving the separating slashes intact
     */
    public static function encodePath(string $path): string
    {
        return implode('/', array_map('rawurlencode', explode('/', $path)));
    }

    /**
     * Derive the date/region/service scoped signing key
     */
    protected function signingKey(string $date): string
    {
        $kDate = hash_hmac('sha256', $date, 'AWS4' . $this->secretKey, true);
        $kRegion = hash_hmac('sha256', $this->region, $kDate, true);
        $kService = hash_hmac('sha256', 's3', $kRegion, true);

        return hash_hmac('sha256', 'aws4_request', $kService, true);
    }
}
