<?php

namespace dokuwiki\plugin\s3presigned\test;

use DokuWikiTest;

/**
 * Proves DokuWiki discovers this plugin under its own name inside the Docker
 * test environment. If this fails, the mount path or the plugin name is wrong
 * and every other test in this suite is meaningless.
 *
 * @group plugin_s3presigned
 * @group plugins
 */
class PluginDiscoveryTest extends DokuWikiTest
{
    protected $pluginsEnabled = ['s3presigned'];

    public function testHelperIsLoadable()
    {
        $helper = plugin_load('helper', 's3presigned');

        $this->assertInstanceOf(\helper_plugin_s3presigned::class, $helper);
    }

    public function testSyntaxComponentsAreLoadable()
    {
        $this->assertInstanceOf(
            \syntax_plugin_s3presigned::class,
            plugin_load('syntax', 's3presigned')
        );
        $this->assertInstanceOf(
            \syntax_plugin_s3presigned_cloudfront::class,
            plugin_load('syntax', 's3presigned_cloudfront')
        );
    }

    public function testActionComponentIsLoadable()
    {
        $this->assertInstanceOf(
            \action_plugin_s3presigned::class,
            plugin_load('action', 's3presigned')
        );
    }
}
