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

        $this->sendCookiesForEntries($cookieEntries);
    }

    /**
     * Send CloudFront cookies for each distinct domain in the given metadata entries.
     *
     * Separated from handleCookies() so the emission path can be tested: that
     * method's headers_sent() guard is unreachable-past in a test process.
     */
    protected function sendCookiesForEntries(array $cookieEntries) {
        $helper = $this->loadHelper('s3presigned');
        if (!$helper) return;

        // Deduplicate by domain
        $domains = array();
        foreach ($cookieEntries as $entry) {
            $domains[$entry['domain']] = $entry;
        }

        foreach ($domains as $entry) {
            try {
                $helper->sendCloudFrontCookies($entry['domain'], $entry['path']);
            } catch (Exception $e) {
                // a misconfigured key must not break page rendering, and one
                // domain's failure must not suppress the others' cookies
                continue;
            }
        }
    }
}
