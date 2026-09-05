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
        if ($data === false) return false;

        // Store cookie entries in page metadata (runs at page save time)
        if ($mode == 'metadata') {
            if ($data['params']['cookies']) {
                if (!isset($renderer->meta['plugin_s3presigned_cf_cookies'])) {
                    $renderer->meta['plugin_s3presigned_cf_cookies'] = array();
                }
                $renderer->meta['plugin_s3presigned_cf_cookies'][] = array(
                    'domain' => $data['domain'],
                    'path'   => $data['path']
                );
            }
            return true;
        }

        if ($mode != 'xhtml') return false;

        // Disable caching since signed URLs expire
        $renderer->info['cache'] = false;

        $helper = $this->loadHelper('s3presigned');
        $filename = basename($data['path']);

        // If cookies mode, render unsigned URL (action plugin sets cookies)
        if ($data['params']['cookies']) {
            $url = $helper->cloudFrontUrl($data['domain'], $data['path']);
            $helper->renderOutput($renderer, $url, $filename, $data['title'], $data['align'], $data['params']);
            return true;
        }

        try {
            $url = $helper->signCloudFrontUrl($data['domain'], $data['path']);
            $helper->renderOutput($renderer, $url, $filename, $data['title'], $data['align'], $data['params']);
        } catch (Exception $e) {
            $renderer->doc .= '<span class="s3-error">Error: ' . hsc($e->getMessage()) . '</span>';
        }

        return true;
    }
}
