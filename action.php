<?php
/**
 * DokuWiki Plugin s3presigned (Action Component)
 *
 * Sets CloudFront signed cookies for pages using {{cf://...?cookies}} syntax.
 *
 * @license GPL 2 http://www.gnu.org/licenses/gpl-2.0.html
 * @author  tracyhatemice
 */

if (!defined('DOKU_INC')) die();

class action_plugin_s3presigned extends DokuWiki_Action_Plugin {

    public function register(Doku_Event_Handler $controller) {
        $controller->register_hook('TPL_ACT_RENDER', 'BEFORE', $this, 'handleCookies');
    }

    /**
     * Set CloudFront signed cookies if the current page has cf:// cookie entries
     * in its metadata (stored by the syntax plugin during metadata render).
     */
    public function handleCookies(Doku_Event $event, $param) {
        global $ID;
        if (headers_sent()) return;

        $cookieEntries = p_get_metadata($ID, 'plugin_s3presigned_cf_cookies');
        if (empty($cookieEntries)) return;

        if (!function_exists('openssl_sign')) return;

        $keyPairId = $this->getConf('cf_key_pair_id');
        if (empty($keyPairId)) return;

        try {
            $privateKey = $this->loadPrivateKey();
        } catch (Exception $e) {
            return;
        }

        $expiration = time() + ($this->getConf('cf_url_expiration') ?: 3600);
        $cookieDomain = $this->getConf('cf_cookie_domain');
        $cookiePath = $this->getConf('cf_cookie_path') ?: '/';

        // Deduplicate by domain
        $domains = array();
        foreach ($cookieEntries as $entry) {
            $domains[$entry['domain']] = $entry;
        }

        foreach ($domains as $entry) {
            $resourceUrl = "https://{$entry['domain']}/{$entry['path']}";

            // Custom policy is required for cookies (supports wildcard paths)
            $policy = json_encode(array(
                'Statement' => array(array(
                    'Resource' => $resourceUrl,
                    'Condition' => array(
                        'DateLessThan' => array('AWS:EpochTime' => $expiration)
                    )
                ))
            ));

            $signature = '';
            if (!openssl_sign($policy, $signature, $privateKey, OPENSSL_ALGO_SHA1)) {
                continue;
            }

            $encodedPolicy = $this->urlSafeBase64($policy);
            $encodedSignature = $this->urlSafeBase64($signature);

            $cookieOpts = array(
                'expires'  => $expiration,
                'path'     => $cookiePath,
                'secure'   => true,
                'httponly'  => true,
                'samesite'  => 'None',
            );
            if (!empty($cookieDomain)) {
                $cookieOpts['domain'] = $cookieDomain;
            }

            setcookie('CloudFront-Policy', $encodedPolicy, $cookieOpts);
            setcookie('CloudFront-Signature', $encodedSignature, $cookieOpts);
            setcookie('CloudFront-Key-Pair-Id', $keyPairId, $cookieOpts);
        }
    }

    /**
     * Load the RSA private key from file or config
     */
    private function loadPrivateKey() {
        $keyFile = $this->getConf('cf_private_key_file');
        if (!empty($keyFile)) {
            if (!file_exists($keyFile)) {
                throw new Exception('CloudFront private key file not found');
            }
            $pem = file_get_contents($keyFile);
        } else {
            $pem = $this->getConf('cf_private_key_pem');
            if (!empty($pem)) {
                $pem = str_replace('\\n', "\n", $pem);
            }
        }

        if (empty($pem)) {
            throw new Exception('CloudFront private key not configured');
        }

        $key = openssl_pkey_get_private($pem);
        if ($key === false) {
            throw new Exception('Invalid RSA private key');
        }

        return $key;
    }

    /**
     * CloudFront URL-safe base64 encoding
     */
    private function urlSafeBase64($data) {
        return strtr(base64_encode($data), '+/=', '-~_');
    }
}
