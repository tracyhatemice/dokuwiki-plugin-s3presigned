<?php
/**
 * DokuWiki Plugin s3presigned (CloudFront Syntax Component)
 *
 * Handles {{cf://distribution-domain/path}} syntax for CloudFront-signed URLs.
 *
 * @license GPL 2 http://www.gnu.org/licenses/gpl-2.0.html
 * @author  tracyhatemice
 */

if (!defined('DOKU_INC')) die();

class syntax_plugin_s3presigned_cloudfront extends DokuWiki_Syntax_Plugin {

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
        $this->Lexer->addSpecialPattern('\{\{ ?cf://[^}]+ ?\}\}', $mode, 'plugin_s3presigned_cloudfront');
    }

    public function handle($match, $state, $pos, Doku_Handler $handler) {
        $helper = $this->loadHelper('s3presigned');
        $content = substr($match, 2, -2); // Remove {{ and }}
        $parsed = $helper->parseContent($content, 5); // 5 = strlen('cf://')
        if ($parsed === false) return false;

        return array(
            'domain' => $parsed['first'],
            'path'   => $parsed['path'],
            'title'  => $parsed['title'],
            'align'  => $parsed['align'],
            'params' => $parsed['params']
        );
    }

    public function render($mode, Doku_Renderer $renderer, $data) {
        if ($mode != 'xhtml') return false;
        if ($data === false) return false;

        // Disable caching since signed URLs expire
        $renderer->info['cache'] = false;

        $helper = $this->loadHelper('s3presigned');

        // If cookies mode, store metadata for the action plugin and render unsigned URL
        if ($data['params']['cookies']) {
            $this->storeCookieMetadata($renderer, $data['domain'], $data['path']);
            $url = "https://{$data['domain']}/{$data['path']}";
            $filename = basename($data['path']);
            $helper->renderOutput($renderer, $url, $filename, $data['title'], $data['align'], $data['params']);
            return true;
        }

        try {
            $url = $this->generateSignedUrl($data['domain'], $data['path']);
            $filename = basename($data['path']);
            $helper->renderOutput($renderer, $url, $filename, $data['title'], $data['align'], $data['params']);
        } catch (Exception $e) {
            $renderer->doc .= '<span class="s3-error">Error: ' . hsc($e->getMessage()) . '</span>';
        }

        return true;
    }

    /**
     * Store cookie metadata so the action plugin can set signed cookies
     */
    private function storeCookieMetadata($renderer, $domain, $path) {
        global $ID;
        if (!isset($GLOBALS['s3presigned_cf_cookies'])) {
            $GLOBALS['s3presigned_cf_cookies'] = array();
        }
        $GLOBALS['s3presigned_cf_cookies'][] = array(
            'domain' => $domain,
            'path'   => $path
        );
    }

    /**
     * Generate a CloudFront signed URL using RSA-SHA1
     */
    private function generateSignedUrl($domain, $objectPath) {
        if (!function_exists('openssl_sign')) {
            throw new Exception('OpenSSL extension is required for CloudFront signed URLs');
        }

        $keyPairId = $this->getConf('cf_key_pair_id');
        if (empty($keyPairId)) {
            throw new Exception('CloudFront Key Pair ID not configured');
        }

        $privateKey = $this->loadPrivateKey();
        $expiration = time() + ($this->getConf('cf_url_expiration') ?: 3600);

        $encodedPath = implode('/', array_map('rawurlencode', explode('/', $objectPath)));
        $url = "https://{$domain}/" . ltrim($encodedPath, '/');

        // Build canned policy
        $policy = '{"Statement":[{"Resource":"' . $url . '","Condition":{"DateLessThan":{"AWS:EpochTime":' . $expiration . '}}}]}';

        $signature = $this->rsaSign($policy, $privateKey);

        return $url
            . (strpos($url, '?') !== false ? '&' : '?')
            . 'Expires=' . $expiration
            . '&Signature=' . $this->urlSafeBase64($signature)
            . '&Key-Pair-Id=' . $keyPairId;
    }

    /**
     * Load the RSA private key from file or config
     */
    private function loadPrivateKey() {
        $keyFile = $this->getConf('cf_private_key_file');
        if (!empty($keyFile)) {
            if (!file_exists($keyFile)) {
                throw new Exception('CloudFront private key file not found: ' . $keyFile);
            }
            $pem = file_get_contents($keyFile);
        } else {
            $pem = $this->getConf('cf_private_key_pem');
            if (!empty($pem)) {
                // Handle newlines stored as literal \n in config
                $pem = str_replace('\\n', "\n", $pem);
            }
        }

        if (empty($pem)) {
            throw new Exception('CloudFront private key not configured (set file path or paste PEM)');
        }

        $key = openssl_pkey_get_private($pem);
        if ($key === false) {
            throw new Exception('Invalid RSA private key: ' . openssl_error_string());
        }

        return $key;
    }

    /**
     * Sign data with RSA-SHA1
     */
    private function rsaSign($data, $privateKey) {
        $signature = '';
        if (!openssl_sign($data, $signature, $privateKey, OPENSSL_ALGO_SHA1)) {
            throw new Exception('RSA signing failed: ' . openssl_error_string());
        }
        return $signature;
    }

    /**
     * CloudFront URL-safe base64 encoding
     * Replaces + with -, = with _, / with ~
     */
    private function urlSafeBase64($data) {
        return strtr(base64_encode($data), '+/=', '-~_');
    }
}
