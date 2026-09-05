<?php
/**
 * DokuWiki Plugin s3presigned (CloudFront signer)
 *
 * @license GPL 2 http://www.gnu.org/licenses/gpl-2.0.html
 * @author  tracyhatemice
 */

namespace dokuwiki\plugin\s3presigned;

use RuntimeException;

/**
 * Signs CloudFront URLs and builds CloudFront signed-cookie policies.
 *
 * Deliberately free of DokuWiki dependencies. Reading the private key from a
 * file or from config is config resolution, so it belongs to
 * helper_plugin_s3presigned; this class receives a PEM string.
 */
class CloudFrontSigner
{
    protected $keyPairId;

    /** @var resource|\OpenSSLAsymmetricKey */
    protected $privateKey;

    public function __construct(string $keyPairId, string $privateKeyPem)
    {
        if (!function_exists('openssl_sign')) {
            throw new RuntimeException('OpenSSL extension is required for CloudFront signed URLs');
        }
        if ($keyPairId === '') {
            throw new RuntimeException('CloudFront Key Pair ID not configured');
        }
        if ($privateKeyPem === '') {
            throw new RuntimeException('CloudFront private key not configured (set file path or paste PEM)');
        }

        $key = openssl_pkey_get_private($privateKeyPem);
        if ($key === false) {
            throw new RuntimeException('Invalid RSA private key: ' . openssl_error_string());
        }

        $this->keyPairId = $keyPairId;
        $this->privateKey = $key;
    }

    /**
     * Sign one exact URL with a canned policy.
     *
     * @param int $expiresAt absolute unix timestamp; CloudFront's Expires is a deadline
     */
    public function signUrl(string $domain, string $path, int $expiresAt): string
    {
        $url = self::url($domain, $path);

        $policy = '{"Statement":[{"Resource":"' . $url . '","Condition":{"DateLessThan":{"AWS:EpochTime":' . $expiresAt . '}}}]}';
        $signature = $this->sign($policy);

        return $url
            . (strpos($url, '?') !== false ? '&' : '?')
            . 'Expires=' . $expiresAt
            . '&Signature=' . self::urlSafeBase64($signature)
            . '&Key-Pair-Id=' . $this->keyPairId;
    }

    /**
     * Build the three signed cookies for a resource, which may be a wildcard.
     *
     * A custom policy is required for cookies, and the resource is deliberately
     * NOT url-encoded: encoding would turn a wildcard such as videos/* into
     * videos/%2A and it would match nothing.
     *
     * @param int $expiresAt absolute unix timestamp
     * @return array{CloudFront-Policy: string, CloudFront-Signature: string, CloudFront-Key-Pair-Id: string}
     */
    public function cookiePolicy(string $domain, string $resource, int $expiresAt): array
    {
        $resourceUrl = "https://{$domain}/{$resource}";

        $policy = json_encode([
            'Statement' => [[
                'Resource' => $resourceUrl,
                'Condition' => [
                    'DateLessThan' => ['AWS:EpochTime' => $expiresAt],
                ],
            ]],
        ]);

        $signature = $this->sign($policy);

        return [
            'CloudFront-Policy' => self::urlSafeBase64($policy),
            'CloudFront-Signature' => self::urlSafeBase64($signature),
            'CloudFront-Key-Pair-Id' => $this->keyPairId,
        ];
    }

    /**
     * The unsigned URL for an object. Cookie-based access needs this: the
     * cookies carry the authorisation, so the URL itself stays bare.
     */
    public static function url(string $domain, string $path): string
    {
        return "https://{$domain}/" . ltrim(self::encodePath($path), '/');
    }

    /**
     * rawurlencode each path segment, leaving the separating slashes intact
     */
    public static function encodePath(string $path): string
    {
        return implode('/', array_map('rawurlencode', explode('/', $path)));
    }

    /**
     * CloudFront's url-safe base64, which is not RFC 4648: + becomes -,
     * / becomes ~, and = becomes _.
     */
    public static function urlSafeBase64(string $data): string
    {
        return strtr(base64_encode($data), '+/=', '-~_');
    }

    protected function sign(string $data): string
    {
        $signature = '';
        if (!openssl_sign($data, $signature, $this->privateKey, OPENSSL_ALGO_SHA1)) {
            throw new RuntimeException('RSA signing failed: ' . openssl_error_string());
        }

        return $signature;
    }
}
