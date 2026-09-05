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
            $url = $helper->signS3Url($data['bucket'], $data['object']);
            $filename = basename($data['object']);
            $helper->renderOutput($renderer, $url, $filename, $data['title'], $data['align'], $data['params']);
        } catch (Exception $e) {
            $renderer->doc .= '<span class="s3-error">Error: ' . hsc($e->getMessage()) . '</span>';
        }

        return true;
    }
}
