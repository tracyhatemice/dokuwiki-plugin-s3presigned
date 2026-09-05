<?php

namespace dokuwiki\plugin\s3presigned\test;

use DokuWikiTest;

/**
 * Pins the rendered output of both syntax components so moving the signing
 * logic into the helper can be shown to change nothing a reader would see.
 *
 * Written and passing BEFORE the components are rewired.
 *
 * @group plugin_s3presigned
 * @group plugins
 */
class RenderRegressionTest extends DokuWikiTest
{
    protected $pluginsEnabled = ['s3presigned'];

    protected const KEY_PAIR_ID = 'APKAIOSFODNN7EXAMPLE';

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
            'cf_key_pair_id' => self::KEY_PAIR_ID,
            'cf_private_key_file' => '',
            'cf_private_key_pem' => $pem,
            'cf_url_expiration' => 3600,
            'cf_cookie_domain' => '',
            'cf_cookie_path' => '/',
        ];

        // the components call loadHelper(), which returns the globally cached
        // instance; drop it so it reloads against the config set above
        global $DOKU_PLUGINS;
        unset($DOKU_PLUGINS['helper']['s3presigned']);
    }

    protected function render($text)
    {
        return p_render('xhtml', p_get_instructions($text), $info);
    }

    public function testS3ImageRendersAsALinkedImage()
    {
        $html = $this->render('{{s3://my-bucket/images/photo.jpg}}');

        $this->assertStringContainsString('<img', $html);
        $this->assertStringContainsString('class="media"', $html);
        $this->assertStringContainsString('loading="lazy"', $html);
        $this->assertStringContainsString('my-bucket.s3.us-east-1.amazonaws.com/images/photo.jpg', $html);
        $this->assertStringContainsString('X-Amz-Signature=', $html);
        $this->assertStringContainsString('alt="photo.jpg"', $html);
    }

    public function testS3DocumentRendersAsADownloadLink()
    {
        $html = $this->render('{{s3://my-bucket/docs/report.pdf|Download Report}}');

        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringContainsString('class="s3-download"', $html);
        $this->assertStringContainsString('target="_blank"', $html);
        $this->assertStringContainsString('Download Report', $html);
        $this->assertStringContainsString('X-Amz-Signature=', $html);
    }

    public function testS3ImageHonoursSizeAndAlignment()
    {
        $html = $this->render('{{ s3://my-bucket/images/photo.jpg?300x200 |Caption}}');

        $this->assertStringContainsString('class="mediacenter"', $html);
        $this->assertStringContainsString('width="300"', $html);
        $this->assertStringContainsString('height="200"', $html);
        $this->assertStringContainsString('alt="Caption"', $html);
    }

    public function testS3ImageHonoursNolink()
    {
        $html = $this->render('{{s3://my-bucket/images/photo.jpg?nolink}}');

        $this->assertStringContainsString('<img', $html);
        $this->assertStringNotContainsString('<a href', $html);
    }

    public function testS3ImageHonoursLinkonly()
    {
        $html = $this->render('{{s3://my-bucket/images/photo.jpg?linkonly|Photo}}');

        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringContainsString('class="s3-download"', $html);
    }

    public function testS3ReportsAMissingConfigurationInline()
    {
        global $conf;
        $conf['plugin']['s3presigned']['aws_secret_key'] = '';

        $html = $this->render('{{s3://my-bucket/images/photo.jpg}}');

        $this->assertStringContainsString('class="s3-error"', $html);
        $this->assertStringContainsString('AWS credentials not configured', $html);
    }

    public function testCloudFrontImageIsSigned()
    {
        $html = $this->render('{{cf://d111abcdef8.cloudfront.net/images/photo.jpg}}');

        $this->assertStringContainsString('<img', $html);
        $this->assertStringContainsString('https://d111abcdef8.cloudfront.net/images/photo.jpg?', $html);
        $this->assertStringContainsString('Expires=', $html);
        $this->assertStringContainsString('Signature=', $html);
        $this->assertStringContainsString('Key-Pair-Id=' . self::KEY_PAIR_ID, $html);
    }

    public function testCloudFrontCookiesModeRendersAnUnsignedUrl()
    {
        $html = $this->render('{{cf://d111abcdef8.cloudfront.net/videos/intro.mp4?cookies|Video}}');

        $this->assertStringContainsString('https://d111abcdef8.cloudfront.net/videos/intro.mp4', $html);
        $this->assertStringNotContainsString('Signature=', $html);
        $this->assertStringNotContainsString('Key-Pair-Id=', $html);
    }

    public function testCloudFrontReportsAMissingKeyInline()
    {
        global $conf;
        $conf['plugin']['s3presigned']['cf_key_pair_id'] = '';

        $html = $this->render('{{cf://d111abcdef8.cloudfront.net/images/photo.jpg}}');

        $this->assertStringContainsString('class="s3-error"', $html);
        $this->assertStringContainsString('CloudFront Key Pair ID not configured', $html);
    }

    /**
     * On a fresh install, all three CloudFront settings are empty. PHP
     * evaluates constructor arguments before entering the constructor, so a
     * naive helper that resolves the private key inline would report the
     * missing key ahead of the missing key pair id, reversing the order
     * these misconfigurations were reported before the refactor.
     * testCloudFrontReportsAMissingKeyInline() above leaves the PEM
     * configured and so never exercises that ordering; this clears all
     * three settings to catch it.
     */
    public function testCloudFrontReportsAMissingKeyPairIdBeforeAMissingKey()
    {
        global $conf;
        $conf['plugin']['s3presigned']['cf_key_pair_id'] = '';
        $conf['plugin']['s3presigned']['cf_private_key_file'] = '';
        $conf['plugin']['s3presigned']['cf_private_key_pem'] = '';

        $html = $this->render('{{cf://d111abcdef8.cloudfront.net/images/photo.jpg}}');

        $this->assertStringContainsString('class="s3-error"', $html);
        $this->assertStringContainsString('CloudFront Key Pair ID not configured', $html);
    }

    /**
     * Exercises the same path action.php uses: metadata is rendered for a real
     * saved page and read back with p_get_metadata(). Calling p_render() with
     * the metadata mode directly would not work, since that renderer returns an
     * empty string and its document_end() expects the page to exist on disk.
     */
    public function testCloudFrontCookiesModeRecordsPageMetadata()
    {
        $id = 's3presigned_cookies_test';
        saveWikiText($id, '{{cf://d111abcdef8.cloudfront.net/videos/*?cookies}}', 'test setup');

        $entries = p_get_metadata($id, 'plugin_s3presigned_cf_cookies', METADATA_RENDER_UNLIMITED);

        $this->assertSame(
            [['domain' => 'd111abcdef8.cloudfront.net', 'path' => 'videos/*']],
            $entries
        );
    }

    public function testPagesWithSignedUrlsAreNotCached()
    {
        p_render('xhtml', p_get_instructions('{{s3://my-bucket/images/photo.jpg}}'), $info);

        $this->assertFalse($info['cache'], 'signed URLs expire, so the page must not be cached');
    }

    public function testPagesWithCloudFrontSignedUrlsAreNotCached()
    {
        p_render('xhtml', p_get_instructions('{{cf://d111abcdef8.cloudfront.net/images/photo.jpg}}'), $info);

        $this->assertFalse($info['cache'], 'signed URLs expire, so the page must not be cached');
    }
}
