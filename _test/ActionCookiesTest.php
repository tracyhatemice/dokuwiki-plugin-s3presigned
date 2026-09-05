<?php

namespace dokuwiki\plugin\s3presigned\test;

use DokuWikiTest;
use Doku_Event;

/**
 * Pins the one real user-facing guarantee action.php makes: a broken
 * CloudFront key must never stop a page from rendering, and a page with no
 * cookie entries in its metadata must be a no-op.
 *
 * @group plugin_s3presigned
 * @group plugins
 */
class ActionCookiesTest extends DokuWikiTest
{
    protected $pluginsEnabled = ['s3presigned'];

    public function setUp(): void
    {
        parent::setUp();

        $pem = '';
        $resource = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        openssl_pkey_export($resource, $pem);

        global $conf;
        $conf['plugin']['s3presigned'] = [
            'aws_region' => 'us-east-1',
            'aws_access_key' => 'AKIAIOSFODNN7EXAMPLE',
            'aws_secret_key' => 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY',
            'url_expiration' => 3600,
            'cf_key_pair_id' => 'APKAIOSFODNN7EXAMPLE',
            'cf_private_key_file' => '',
            'cf_private_key_pem' => $pem,
            'cf_url_expiration' => 3600,
            'cf_cookie_domain' => '',
            'cf_cookie_path' => '/',
        ];

        global $DOKU_PLUGINS;
        unset($DOKU_PLUGINS['helper']['s3presigned']);
    }

    /**
     * @return Doku_Event a trivial TPL_ACT_RENDER event; handleCookies() never
     *                     inspects it, only $ID and page metadata
     */
    protected function tplActRenderEvent()
    {
        $data = 'show';
        return new Doku_Event('TPL_ACT_RENDER', $data);
    }

    public function testBrokenKeyDoesNotBreakPageRendering()
    {
        $id = 's3presigned_action_cookies_test';
        saveWikiText($id, '{{cf://d111abcdef8.cloudfront.net/videos/*?cookies}}', 'test setup');

        // force metadata to be rendered and saved to disk now, so the plain
        // p_get_metadata() call inside handleCookies() can read it back from
        // cache without needing to render the page itself
        $entries = p_get_metadata($id, 'plugin_s3presigned_cf_cookies', METADATA_RENDER_UNLIMITED);
        $this->assertNotEmpty($entries, 'test setup: page must actually carry a cf:// cookie entry');

        // break the CloudFront key AFTER metadata is warmed, and drop the
        // cached helper instance so it reloads against the broken config
        global $conf;
        $conf['plugin']['s3presigned']['cf_private_key_pem'] = '';
        global $DOKU_PLUGINS;
        unset($DOKU_PLUGINS['helper']['s3presigned']);

        global $ID;
        $ID = $id;

        $action = plugin_load('action', 's3presigned', true);
        $event = $this->tplActRenderEvent();

        ob_start();
        try {
            $action->handleCookies($event, null);
        } catch (\Throwable $e) {
            ob_end_clean();
            $this->fail('handleCookies() must swallow a misconfigured key, but threw: ' . $e->getMessage());
        }
        $output = ob_get_clean();

        $this->assertSame('', $output, 'a misconfigured key must not break page rendering, and must not emit output');
    }

    public function testNoCookieMetadataIsANoOp()
    {
        global $ID;
        $ID = 's3presigned_action_no_cookies_test'; // never saved: carries no metadata at all

        global $DOKU_PLUGINS;
        unset($DOKU_PLUGINS['helper']['s3presigned']);

        $action = plugin_load('action', 's3presigned', true);
        $event = $this->tplActRenderEvent();

        ob_start();
        $action->handleCookies($event, null);
        $output = ob_get_clean();

        $this->assertSame('', $output);
        // with no cookie entries, handleCookies() returns before ever calling
        // loadHelper(), so the helper cache stays untouched
        $this->assertArrayNotHasKey(
            's3presigned',
            $DOKU_PLUGINS['helper'] ?? [],
            'handleCookies() must return before loading the helper when there is nothing to do'
        );
    }
}
