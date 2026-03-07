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
        $helper = $this->loadHelper('s3presigned');
        $content = substr($match, 2, -2); // Remove {{ and }}
        $parsed = $helper->parseContent($content, 5); // 5 = strlen('s3://')
        if ($parsed === false) return false;

        return array(
            'bucket' => $parsed['first'],
            'object' => $parsed['path'],
            'title'  => $parsed['title'],
            'align'  => $parsed['align'],
            'params' => $parsed['params']
        );
    }

    public function render($mode, Doku_Renderer $renderer, $data) {
        if ($mode != 'xhtml') return false;
        if ($data === false) return false;

        // Disable caching for pages with S3 presigned URLs
        // since URLs expire after a set time
        $renderer->info['cache'] = false;

        $helper = $this->loadHelper('s3presigned');

        try {
            $url = $this->generatePresignedUrl($data['bucket'], $data['object']);
            $filename = basename($data['object']);
            $helper->renderOutput($renderer, $url, $filename, $data['title'], $data['align'], $data['params']);
        } catch (Exception $e) {
            $renderer->doc .= '<span class="s3-error">Error: ' . hsc($e->getMessage()) . '</span>';
        }

        return true;
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
