<?php
/**
 * DokuWiki Plugin s3presigned (Syntax Component)
 *
 * @license GPL 2 http://www.gnu.org/licenses/gpl-2.0.html
 * @author  tracyhatemice
 */

// must be run within Dokuwiki
if (!defined('DOKU_INC')) die();

class syntax_plugin_s3presigned extends DokuWiki_Syntax_Plugin {

    public function getType() {
        return 'substition';
    }

    public function getPType() {
        return 'normal';
    }

    public function getSort() {
        return 155;
    }

    public function connectTo($mode) {
        // Match {{s3://bucket_name/path/to/file}} with optional title and parameters
        // Supports: {{ s3://... }}, {{s3://...|title}}, {{s3://...?params|title}}
        // The [^}]+ allows everything except }, and we handle spaces for alignment
        $this->Lexer->addSpecialPattern('\{\{ ?s3://[^}]+ ?\}\}', $mode, 'plugin_s3presigned');
    }

    public function handle($match, $state, $pos, Doku_Handler $handler) {
        // Remove {{ and }}
        $content = substr($match, 2, -2);

        // Check alignment based on spaces (DokuWiki style)
        // Space after {{ = right indicator, space before | (or }}) = left indicator
        // {{ s3://path |title}} = center, {{s3://path |title}} = left, {{ s3://path|title}} = right
        $hasLeftSpace = (substr($content, 0, 1) === ' ');

        // Check for space before | or at end (before }})
        $pipePos = strpos($content, '|');
        if ($pipePos !== false) {
            $hasRightSpace = (substr($content, $pipePos - 1, 1) === ' ');
        } else {
            $hasRightSpace = (substr($content, -1) === ' ');
        }

        $align = 'none';
        if ($hasLeftSpace && $hasRightSpace) {
            $align = 'center';
        } elseif ($hasLeftSpace) {
            $align = 'right';
        } elseif ($hasRightSpace) {
            $align = 'left';
        }

        // Trim and remove s3:// prefix
        $content = trim($content);
        $content = substr($content, 5); // Remove s3://

        // Parse title (after |)
        $title = null;
        $pipePos = strpos($content, '|');
        if ($pipePos !== false) {
            $title = trim(substr($content, $pipePos + 1));
            $content = trim(substr($content, 0, $pipePos));
        }

        // Parse parameters (after ?)
        $params = array();
        $questionPos = strpos($content, '?');
        if ($questionPos !== false) {
            $paramStr = substr($content, $questionPos + 1);
            $content = substr($content, 0, $questionPos);
            $params = $this->parseParams($paramStr);
        }

        // Split by first slash: bucket/object-path
        $slashPos = strpos($content, '/');
        if ($slashPos === false) {
            return false;
        }

        $bucket = trim(substr($content, 0, $slashPos));
        $object = trim(substr($content, $slashPos + 1));

        return array(
            'bucket' => $bucket,
            'object' => $object,
            'title'  => $title,
            'align'  => $align,
            'params' => $params
        );
    }

    /**
     * Parse DokuWiki-style image parameters
     * Supports: ?50, ?50x100, ?nolink, ?direct, ?linkonly, etc.
     */
    private function parseParams($paramStr) {
        $params = array(
            'width'    => null,
            'height'   => null,
            'nolink'   => false,
            'direct'   => false,
            'linkonly' => false,
            'cache'    => null
        );

        $parts = explode('&', $paramStr);
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === 'nolink') {
                $params['nolink'] = true;
            } elseif ($part === 'direct') {
                $params['direct'] = true;
            } elseif ($part === 'linkonly') {
                $params['linkonly'] = true;
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
     * Check if file is an image based on extension
     */
    private function isImage($filename) {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        return in_array($ext, array('png', 'jpg', 'jpeg', 'gif', 'webp', 'svg', 'bmp', 'ico'));
    }

    public function render($mode, Doku_Renderer $renderer, $data) {
        if ($mode != 'xhtml') return false;
        if ($data === false) return false;

        // Disable caching for pages with S3 presigned URLs
        // since URLs expire after a set time
        $renderer->info['cache'] = false;

        try {
            $url = $this->generatePresignedUrl($data['bucket'], $data['object']);
            $filename = basename($data['object']);
            $title = $data['title'] ?: $filename;
            $params = $data['params'];
            $align = $data['align'];
            $isImage = $this->isImage($filename);

            // linkonly: render as text link regardless of file type
            if ($params['linkonly']) {
                $renderer->doc .= '<a href="' . hsc($url) . '" class="s3-download" target="_blank">';
                $renderer->doc .= hsc($title);
                $renderer->doc .= '</a>';
                return true;
            }

            // Render images
            if ($isImage) {
                $this->renderImage($renderer, $url, $title, $align, $params);
            } else {
                // Render as download link for non-images
                $renderer->doc .= '<a href="' . hsc($url) . '" class="s3-download" target="_blank">';
                $renderer->doc .= hsc($title);
                $renderer->doc .= '</a>';
            }

        } catch (Exception $e) {
            $renderer->doc .= '<span class="s3-error">Error: ' . hsc($e->getMessage()) . '</span>';
        }

        return true;
    }

    /**
     * Render an image with alignment and sizing options
     */
    private function renderImage($renderer, $url, $alt, $align, $params) {
        // Determine image class based on alignment
        $imgClass = 'media';
        if ($align === 'center') {
            $imgClass = 'mediacenter';
        } elseif ($align === 'left') {
            $imgClass = 'medialeft';
        } elseif ($align === 'right') {
            $imgClass = 'mediaright';
        }

        // Build img tag attributes
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

        // Build attribute string
        $attrStr = '';
        foreach ($imgAttrs as $key => $value) {
            $attrStr .= ' ' . $key . '="' . hsc($value) . '"';
        }

        $img = '<img' . $attrStr . ' loading="lazy" />';

        // nolink: just the image, no link wrapper
        if ($params['nolink']) {
            $renderer->doc .= $img;
        }
        // default: wrap in link
        else {
            $renderer->doc .= '<a href="' . hsc($url) . '" class="media" target="_blank">' . $img . '</a>';
        }
    }

    private function generatePresignedUrl($bucket, $objectKey) {
        // Get configuration
        $region = $this->getConf('aws_region');
        $accessKey = $this->getConf('aws_access_key');
        $secretKey = $this->getConf('aws_secret_key');
        $expiration = $this->getConf('url_expiration') ?: 3600;

        if (empty($region) || empty($accessKey) || empty($secretKey)) {
            throw new Exception('AWS credentials not configured');
        }

        // Generate presigned URL using AWS Signature V4
        $timestamp = time();
        $datetime = gmdate('Ymd\THis\Z', $timestamp);
        $date = gmdate('Ymd', $timestamp);
        
        $host = "{$bucket}.s3.{$region}.amazonaws.com";
        $algorithm = 'AWS4-HMAC-SHA256';
        $credentialScope = "{$date}/{$region}/s3/aws4_request";
        $credential = "{$accessKey}/{$credentialScope}";
        
        // Properly encode the object key for the URI
        $encodedObjectKey = implode('/', array_map('rawurlencode', explode('/', $objectKey)));
        $canonicalUri = '/' . ltrim($encodedObjectKey, '/');
        
        // Build canonical query string
        $queryParams = array(
            'X-Amz-Algorithm' => $algorithm,
            'X-Amz-Credential' => $credential,
            'X-Amz-Date' => $datetime,
            'X-Amz-Expires' => (string)$expiration,
            'X-Amz-SignedHeaders' => 'host'
        );
        
        // Sort and encode query parameters
        ksort($queryParams);
        $canonicalQueryString = '';
        foreach ($queryParams as $key => $value) {
            if ($canonicalQueryString !== '') {
                $canonicalQueryString .= '&';
            }
            $canonicalQueryString .= rawurlencode($key) . '=' . rawurlencode($value);
        }
        
        // Canonical headers
        $canonicalHeaders = "host:{$host}\n";
        $signedHeaders = 'host';
        
        // Create canonical request
        $canonicalRequest = implode("\n", array(
            'GET',
            $canonicalUri,
            $canonicalQueryString,
            $canonicalHeaders,
            $signedHeaders,
            'UNSIGNED-PAYLOAD'
        ));
        
        // String to sign
        $stringToSign = implode("\n", array(
            $algorithm,
            $datetime,
            $credentialScope,
            hash('sha256', $canonicalRequest)
        ));
        
        // Calculate signature
        $kDate = hash_hmac('sha256', $date, 'AWS4' . $secretKey, true);
        $kRegion = hash_hmac('sha256', $region, $kDate, true);
        $kService = hash_hmac('sha256', 's3', $kRegion, true);
        $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);
        $signature = hash_hmac('sha256', $stringToSign, $kSigning);
        
        // Build final URL
        $presignedUrl = "https://{$host}{$canonicalUri}?{$canonicalQueryString}&X-Amz-Signature={$signature}";
        
        return $presignedUrl;
    }
}

/**
 * Configuration file: conf/default.php
 * Place this in: lib/plugins/s3presigned/conf/default.php
 */
/*
$conf['aws_region'] = 'us-east-1';
$conf['aws_access_key'] = '';
$conf['aws_secret_key'] = '';
$conf['url_expiration'] = 3600; // URL valid for 1 hour
*/

/**
 * Configuration metadata: conf/metadata.php
 * Place this in: lib/plugins/s3presigned/conf/metadata.php
 */
/*
$meta['aws_region'] = array('string');
$meta['aws_access_key'] = array('string');
$meta['aws_secret_key'] = array('password');
$meta['url_expiration'] = array('numeric');
*/

/**
 * Plugin info: plugin.info.txt
 * Place this in: lib/plugins/s3presigned/plugin.info.txt
 */
/*
base   s3presigned
author Your Name
email  your@email.com
date   2025-10-21
name   S3 Presigned URL Plugin
desc   Embed S3 files/images with presigned URLs using {{s3://bucket/path}} syntax
url    https://www.dokuwiki.org/plugin:s3presigned
*/

/**
 * INSTALLATION INSTRUCTIONS:
 * 
 * 1. Create plugin directory structure:
 *    lib/plugins/s3presigned/
 *    lib/plugins/s3presigned/syntax.php (main file above)
 *    lib/plugins/s3presigned/conf/default.php
 *    lib/plugins/s3presigned/conf/metadata.php
 *    lib/plugins/s3presigned/plugin.info.txt
 * 
 * 2. Configure AWS credentials in DokuWiki admin panel:
 *    - Go to Admin > Configuration Settings
 *    - Find "s3presigned" section
 *    - Set aws_region (e.g., us-east-1)
 *    - Set aws_access_key (your AWS access key)
 *    - Set aws_secret_key (your AWS secret key)
 *    - Set url_expiration (seconds, default 3600)
 * 
 * 3. Ensure your S3 bucket permissions allow GetObject for the IAM user
 * 
 * USAGE:
 *
 * Basic syntax:
 * {{s3://my-bucket/path/to/file.pdf}}              - Download link (shows filename)
 * {{s3://my-bucket/path/to/file.pdf|My Document}}  - Download link with custom text
 *
 * Images (auto-detected by extension: png, jpg, jpeg, gif, webp, svg, bmp, ico):
 * {{s3://my-bucket/images/photo.jpg}}              - Embedded image
 * {{s3://my-bucket/images/photo.jpg|Alt text}}     - Image with alt text
 *
 * Image sizing:
 * {{s3://my-bucket/images/photo.jpg?200}}          - Width 200px
 * {{s3://my-bucket/images/photo.jpg?200x150}}      - Width 200px, height 150px
 *
 * Image alignment (space before | determines alignment):
 * {{s3://my-bucket/images/photo.jpg |Caption}}     - Left aligned (space before |)
 * {{ s3://my-bucket/images/photo.jpg|Caption}}     - Right aligned (space after {{)
 * {{ s3://my-bucket/images/photo.jpg |Caption}}    - Centered (both spaces)
 *
 * Image options:
 * {{s3://my-bucket/images/photo.jpg?nolink}}       - Image without clickable link
 * {{s3://my-bucket/images/photo.jpg?direct}}       - Direct link to image
 * {{s3://my-bucket/images/photo.jpg?linkonly}}     - Show as text link, not image
 *
 * Combined parameters (use & to separate):
 * {{s3://my-bucket/images/photo.jpg?200&nolink}}              - 200px width, no link
 * {{ s3://my-bucket/images/photo.jpg?300x200&nolink |Photo}}  - Centered, sized, no link, with alt
 *
 * The plugin will render a clickable link with a presigned URL that expires
 * after the configured duration.
 * 
 * IMPORTANT: Pages containing S3 syntax will NOT be cached to ensure users
 * always get fresh presigned URLs. This prevents expired link issues but may
 * slightly impact page load performance.
 * 
 * SECURITY NOTES:
 * - Store AWS credentials securely (use IAM user with minimal permissions)
 * - Consider using environment variables or AWS IAM roles instead of storing keys
 * - Set appropriate expiration times for presigned URLs
 * - Use bucket policies to restrict access as needed
 */
