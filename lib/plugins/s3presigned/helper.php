<?php
/**
 * DokuWiki Plugin s3presigned (Helper Component)
 *
 * Shared rendering and parameter parsing for S3 and CloudFront syntax plugins.
 *
 * @license GPL 2 http://www.gnu.org/licenses/gpl-2.0.html
 * @author  tracyhatemice
 */

use dokuwiki\plugin\s3presigned\CloudFrontSigner;
use dokuwiki\plugin\s3presigned\S3Signer;

if (!defined('DOKU_INC')) die();

class helper_plugin_s3presigned extends DokuWiki_Plugin {

    /** Option keys accepted by signS3Url() */
    protected const S3_OPTIONS = ['region', 'access_key', 'secret_key', 'expires'];

    /** Option keys accepted by every CloudFront method */
    protected const CF_OPTIONS = ['key_pair_id', 'private_key', 'private_key_file', 'expires'];

    /** Option keys accepted only by the cookie methods */
    protected const CF_COOKIE_OPTIONS = ['cookie_domain', 'cookie_path', 'secure', 'httponly', 'samesite'];

    /** Mirrors the url_expiration/cf_url_expiration defaults in conf/default.php */
    protected const DEFAULT_EXPIRES = 3600;

    /**
     * Generate an AWS Signature V4 presigned GET URL for an S3 object.
     *
     * Intended for use by other plugins:
     *   $s3 = plugin_load('helper', 's3presigned');
     *   $url = $s3->signS3Url('my-bucket', 'docs/report.pdf');
     *
     * @param array $opts region, access_key, secret_key, expires; each falls
     *                    back to the plugin configuration when absent
     * @throws RuntimeException on unknown options or missing credentials
     */
    public function signS3Url($bucket, $objectKey, array $opts = [])
    {
        $this->rejectUnknownOptions($opts, self::S3_OPTIONS, 'signS3Url');

        $signer = new S3Signer(
            (string)($opts['region'] ?? $this->getConf('aws_region')),
            (string)($opts['access_key'] ?? $this->getConf('aws_access_key')),
            (string)($opts['secret_key'] ?? $this->getConf('aws_secret_key'))
        );

        return $signer->presign($bucket, $objectKey, $this->resolveExpires($opts, 'url_expiration'));
    }

    /**
     * Generate a CloudFront signed URL (canned policy, RSA-SHA1).
     *
     * @param array $opts key_pair_id, private_key, private_key_file, expires
     * @throws RuntimeException on unknown options or missing key material
     */
    public function signCloudFrontUrl($domain, $path, array $opts = [])
    {
        $this->rejectUnknownOptions($opts, self::CF_OPTIONS, 'signCloudFrontUrl');

        $expiresAt = time() + $this->resolveExpires($opts, 'cf_url_expiration');

        return $this->cloudFrontSigner($opts)->signUrl($domain, $path, $expiresAt);
    }

    /**
     * The unsigned CloudFront URL for an object, with each path segment encoded.
     *
     * Needed alongside the cookie methods: signed cookies carry the
     * authorisation, so the URL itself must stay bare. Uses no credentials and
     * therefore never throws for missing configuration.
     */
    public function cloudFrontUrl($domain, $path)
    {
        return CloudFrontSigner::url($domain, $path);
    }

    /**
     * Build CloudFront signed cookies without sending them, so the caller
     * decides when and how.
     *
     * The resource may contain a wildcard, e.g. 'videos/*'.
     *
     * @param array $opts the signCloudFrontUrl() options plus cookie_domain,
     *                    cookie_path, secure, httponly, samesite
     * @return array{cookies: array<string,string>, options: array}
     * @throws RuntimeException on unknown options or missing key material
     */
    public function getCloudFrontCookies($domain, $resource, array $opts = [])
    {
        $this->rejectUnknownOptions(
            $opts,
            array_merge(self::CF_OPTIONS, self::CF_COOKIE_OPTIONS),
            'getCloudFrontCookies'
        );

        $expiresAt = time() + $this->resolveExpires($opts, 'cf_url_expiration');
        $cookies = $this->cloudFrontSigner($opts)->cookiePolicy($domain, $resource, $expiresAt);

        $options = [
            'expires'  => $expiresAt,
            'path'     => (string)($opts['cookie_path'] ?? $this->getConf('cf_cookie_path')) ?: '/',
            'secure'   => (bool)($opts['secure'] ?? true),
            'httponly' => (bool)($opts['httponly'] ?? true),
            'samesite' => (string)($opts['samesite'] ?? 'None'),
        ];

        $cookieDomain = (string)($opts['cookie_domain'] ?? $this->getConf('cf_cookie_domain'));
        if ($cookieDomain !== '') {
            $options['domain'] = $cookieDomain;
        }

        return ['cookies' => $cookies, 'options' => $options];
    }

    /**
     * Build and send CloudFront signed cookies.
     *
     * @return bool false if headers were already sent, true once all three
     *              cookies have been issued
     * @throws RuntimeException on unknown options, missing key material, or
     *                          RSA signing failure
     */
    public function sendCloudFrontCookies($domain, $resource, array $opts = [])
    {
        $signed = $this->getCloudFrontCookies($domain, $resource, $opts);

        if (headers_sent()) {
            return false;
        }

        $sent = true;
        foreach ($signed['cookies'] as $name => $value) {
            $sent = setcookie($name, $value, $signed['options']) && $sent;
        }

        return $sent;
    }

    /**
     * Describe the public API for the core info plugin, which renders this
     * with ~~INFO:helpermethods~~
     */
    public function getMethods()
    {
        return [
            [
                'name' => 'signS3Url',
                'desc' => 'Generate an AWS Signature V4 presigned GET URL for an S3 object. ' .
                    'Options: region, access_key, secret_key, expires (seconds).',
                'params' => [
                    'bucket' => 'string',
                    'objectKey' => 'string',
                    'options (optional)' => 'array',
                ],
                'return' => ['presigned URL' => 'string'],
            ],
            [
                'name' => 'signCloudFrontUrl',
                'desc' => 'Generate a CloudFront signed URL using a canned policy. ' .
                    'Options: key_pair_id, private_key, private_key_file, expires (seconds).',
                'params' => [
                    'domain' => 'string',
                    'path' => 'string',
                    'options (optional)' => 'array',
                ],
                'return' => ['signed URL' => 'string'],
            ],
            [
                'name' => 'cloudFrontUrl',
                'desc' => 'Build the unsigned CloudFront URL for an object, for use with signed cookies.',
                'params' => [
                    'domain' => 'string',
                    'path' => 'string',
                ],
                'return' => ['unsigned URL' => 'string'],
            ],
            [
                'name' => 'getCloudFrontCookies',
                'desc' => 'Build CloudFront signed cookies without sending them. ' .
                    'The resource may contain a wildcard. Returns the keys cookies and options.',
                'params' => [
                    'domain' => 'string',
                    'resource' => 'string',
                    'options (optional)' => 'array',
                ],
                'return' => ['cookies and cookie options' => 'array'],
            ],
            [
                'name' => 'sendCloudFrontCookies',
                'desc' => 'Build and send CloudFront signed cookies. False if headers were already sent.',
                'params' => [
                    'domain' => 'string',
                    'resource' => 'string',
                    'options (optional)' => 'array',
                ],
                'return' => ['sent' => 'bool'],
            ],
        ];
    }

    /**
     * Build a CloudFront signer from options merged over configuration
     */
    protected function cloudFrontSigner(array $opts)
    {
        $keyPairId = (string)($opts['key_pair_id'] ?? $this->getConf('cf_key_pair_id'));

        // Resolve the key only once a key pair id is present. PHP evaluates
        // constructor arguments before the constructor body, so resolving inline
        // would report a missing key ahead of a missing key pair id and reverse
        // the order these misconfigurations were reported before the refactor.
        $pem = $keyPairId === '' ? '' : $this->resolvePrivateKey($opts);

        return new CloudFrontSigner($keyPairId, $pem);
    }

    /**
     * Read the RSA private key, preferring a file over a pasted PEM
     *
     * @throws RuntimeException if a configured file is missing or no key is set
     */
    protected function resolvePrivateKey(array $opts)
    {
        $keyFile = (string)($opts['private_key_file'] ?? $this->getConf('cf_private_key_file'));
        if ($keyFile !== '') {
            if (!file_exists($keyFile)) {
                throw new RuntimeException('CloudFront private key file not found: ' . $keyFile);
            }
            return (string)file_get_contents($keyFile);
        }

        $pem = (string)($opts['private_key'] ?? $this->getConf('cf_private_key_pem'));
        if ($pem === '') {
            throw new RuntimeException('CloudFront private key not configured (set file path or paste PEM)');
        }

        // the config UI stores newlines as literal \n
        return str_replace('\\n', "\n", $pem);
    }

    /**
     * Resolve the lifetime in seconds, falling back to config then to one hour.
     *
     * Strict on an explicitly supplied option (a caller's mistake must fail at
     * the call site, not silently coerce to a default), lenient on the config
     * fallback (a site that has cleared the setting should still get a sane
     * default).
     *
     * @throws RuntimeException if an explicitly supplied expires is not a
     *                          positive integer
     */
    protected function resolveExpires(array $opts, $confKey)
    {
        if (array_key_exists('expires', $opts)) {
            $expires = filter_var($opts['expires'], FILTER_VALIDATE_INT);
            if ($expires === false || $expires <= 0) {
                throw new RuntimeException('Option "expires" must be a positive number of seconds');
            }

            return $expires;
        }

        $expires = (int)$this->getConf($confKey);

        return $expires > 0 ? $expires : self::DEFAULT_EXPIRES;
    }

    /**
     * Reject a misspelled option rather than silently falling back to config,
     * which would sign with the wrong credentials and only fail at the CDN
     *
     * @throws RuntimeException
     */
    protected function rejectUnknownOptions(array $opts, array $allowed, $method)
    {
        $unknown = array_diff(array_keys($opts), $allowed);
        if ($unknown) {
            throw new RuntimeException(
                'Unknown option "' . reset($unknown) . '" for ' . $method . '(); allowed: ' .
                implode(', ', $allowed)
            );
        }
    }

    /**
     * Parse DokuWiki-style image parameters
     * Supports: ?50, ?50x100, ?nolink, ?direct, ?linkonly, ?cookies, etc.
     */
    public function parseParams($paramStr) {
        $params = $this->defaultParams();

        $parts = explode('&', $paramStr);
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === 'nolink') {
                $params['nolink'] = true;
            } elseif ($part === 'direct') {
                $params['direct'] = true;
            } elseif ($part === 'linkonly') {
                $params['linkonly'] = true;
            } elseif ($part === 'cookies') {
                $params['cookies'] = true;
            } elseif (preg_match('/^(\d+)(x(\d+))?$/', $part, $m)) {
                $params['width'] = (int)$m[1];
                if (isset($m[3])) {
                    $params['height'] = (int)$m[3];
                }
            } elseif (preg_match('/^(nocache|recache|cache)$/', $part)) {
                $params['cache'] = $part;
            }
        }

        return $params;
    }

    /**
     * Return default parameter array
     */
    public function defaultParams() {
        return array(
            'width'    => null,
            'height'   => null,
            'nolink'   => false,
            'direct'   => false,
            'linkonly' => false,
            'cookies'  => false,
            'cache'    => null
        );
    }

    /**
     * Check if file is an image based on extension
     */
    public function isImage($filename) {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        return in_array($ext, array('png', 'jpg', 'jpeg', 'gif', 'webp', 'svg', 'bmp', 'ico'));
    }

    /**
     * Parse alignment from the raw match content (between {{ and }})
     */
    public function parseAlignment($content, $pipePos) {
        $hasLeftSpace = (substr($content, 0, 1) === ' ');

        if ($pipePos !== false) {
            $hasRightSpace = (substr($content, $pipePos - 1, 1) === ' ');
        } else {
            $hasRightSpace = (substr($content, -1) === ' ');
        }

        if ($hasLeftSpace && $hasRightSpace) {
            return 'center';
        } elseif ($hasLeftSpace) {
            return 'right';
        } elseif ($hasRightSpace) {
            return 'left';
        }
        return 'none';
    }

    /**
     * Parse the content inside {{ ... }} after stripping the prefix (s3:// or cf://)
     *
     * @param string $rawContent Content between {{ and }} (before prefix stripping)
     * @param int $prefixLen Length of prefix to strip (5 for s3://, 5 for cf://)
     * @return array|false Parsed data or false on failure
     */
    public function parseContent($rawContent, $prefixLen) {
        $pipePos = strpos($rawContent, '|');
        $align = $this->parseAlignment($rawContent, $pipePos);

        $content = trim($rawContent);
        $content = substr($content, $prefixLen); // Remove prefix

        // Parse title (after |)
        $title = null;
        $pipePos = strpos($content, '|');
        if ($pipePos !== false) {
            $title = trim(substr($content, $pipePos + 1));
            $content = trim(substr($content, 0, $pipePos));
        }

        // Parse parameters (after ?)
        $params = $this->defaultParams();
        $questionPos = strpos($content, '?');
        if ($questionPos !== false) {
            $paramStr = substr($content, $questionPos + 1);
            $content = substr($content, 0, $questionPos);
            $params = $this->parseParams($paramStr);
        }

        // Split by first slash: domain-or-bucket / object-path
        $slashPos = strpos($content, '/');
        if ($slashPos === false) {
            return false;
        }

        $first = trim(substr($content, 0, $slashPos));
        $path = trim(substr($content, $slashPos + 1));

        return array(
            'first'  => $first,
            'path'   => $path,
            'title'  => $title,
            'align'  => $align,
            'params' => $params
        );
    }

    /**
     * Render output: linkonly, image, or download link
     */
    public function renderOutput($renderer, $url, $filename, $title, $align, $params) {
        $displayTitle = $title ?: $filename;

        if ($params['linkonly']) {
            $this->renderLink($renderer, $url, $displayTitle);
            return;
        }

        if ($this->isImage($filename)) {
            $this->renderImage($renderer, $url, $displayTitle, $align, $params);
        } else {
            $this->renderLink($renderer, $url, $displayTitle);
        }
    }

    /**
     * Render a download link
     */
    public function renderLink($renderer, $url, $title) {
        $renderer->doc .= '<a href="' . hsc($url) . '" class="s3-download" target="_blank">';
        $renderer->doc .= hsc($title);
        $renderer->doc .= '</a>';
    }

    /**
     * Render an image with alignment and sizing options
     */
    public function renderImage($renderer, $url, $alt, $align, $params) {
        $imgClass = 'media';
        if ($align === 'center') {
            $imgClass = 'mediacenter';
        } elseif ($align === 'left') {
            $imgClass = 'medialeft';
        } elseif ($align === 'right') {
            $imgClass = 'mediaright';
        }

        $imgAttrs = array(
            'src' => $url,
            'alt' => $alt,
            'class' => $imgClass
        );

        if ($params['width']) {
            $imgAttrs['width'] = $params['width'];
        }
        if ($params['height']) {
            $imgAttrs['height'] = $params['height'];
        }

        $attrStr = '';
        foreach ($imgAttrs as $key => $value) {
            $attrStr .= ' ' . $key . '="' . hsc($value) . '"';
        }

        $img = '<img' . $attrStr . ' loading="lazy" />';

        if ($params['nolink']) {
            $renderer->doc .= $img;
        } else {
            $renderer->doc .= '<a href="' . hsc($url) . '" class="media" target="_blank">' . $img . '</a>';
        }
    }
}
