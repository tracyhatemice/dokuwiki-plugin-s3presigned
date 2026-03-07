<?php
/**
 * DokuWiki Plugin s3presigned (Helper Component)
 *
 * Shared rendering and parameter parsing for S3 and CloudFront syntax plugins.
 *
 * @license GPL 2 http://www.gnu.org/licenses/gpl-2.0.html
 * @author  tracyhatemice
 */

if (!defined('DOKU_INC')) die();

class helper_plugin_s3presigned extends DokuWiki_Plugin {

    /**
     * Parse DokuWiki-style image parameters
     * Supports: ?50, ?50x100, ?nolink, ?direct, ?linkonly, ?cookies, etc.
     */
    public function parseParams($paramStr) {
        $params = $this->defaultParams();

        $parts = explode('&', $paramStr);
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === 'nolink') {
                $params['nolink'] = true;
            } elseif ($part === 'direct') {
                $params['direct'] = true;
            } elseif ($part === 'linkonly') {
                $params['linkonly'] = true;
            } elseif ($part === 'cookies') {
                $params['cookies'] = true;
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
     * Return default parameter array
     */
    public function defaultParams() {
        return array(
            'width'    => null,
            'height'   => null,
            'nolink'   => false,
            'direct'   => false,
            'linkonly' => false,
            'cookies'  => false,
            'cache'    => null
        );
    }

    /**
     * Check if file is an image based on extension
     */
    public function isImage($filename) {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        return in_array($ext, array('png', 'jpg', 'jpeg', 'gif', 'webp', 'svg', 'bmp', 'ico'));
    }

    /**
     * Parse alignment from the raw match content (between {{ and }})
     */
    public function parseAlignment($content, $pipePos) {
        $hasLeftSpace = (substr($content, 0, 1) === ' ');

        if ($pipePos !== false) {
            $hasRightSpace = (substr($content, $pipePos - 1, 1) === ' ');
        } else {
            $hasRightSpace = (substr($content, -1) === ' ');
        }

        if ($hasLeftSpace && $hasRightSpace) {
            return 'center';
        } elseif ($hasLeftSpace) {
            return 'right';
        } elseif ($hasRightSpace) {
            return 'left';
        }
        return 'none';
    }

    /**
     * Parse the content inside {{ ... }} after stripping the prefix (s3:// or cf://)
     *
     * @param string $rawContent Content between {{ and }} (before prefix stripping)
     * @param int $prefixLen Length of prefix to strip (5 for s3://, 5 for cf://)
     * @return array|false Parsed data or false on failure
     */
    public function parseContent($rawContent, $prefixLen) {
        $pipePos = strpos($rawContent, '|');
        $align = $this->parseAlignment($rawContent, $pipePos);

        $content = trim($rawContent);
        $content = substr($content, $prefixLen); // Remove prefix

        // Parse title (after |)
        $title = null;
        $pipePos = strpos($content, '|');
        if ($pipePos !== false) {
            $title = trim(substr($content, $pipePos + 1));
            $content = trim(substr($content, 0, $pipePos));
        }

        // Parse parameters (after ?)
        $params = $this->defaultParams();
        $questionPos = strpos($content, '?');
        if ($questionPos !== false) {
            $paramStr = substr($content, $questionPos + 1);
            $content = substr($content, 0, $questionPos);
            $params = $this->parseParams($paramStr);
        }

        // Split by first slash: domain-or-bucket / object-path
        $slashPos = strpos($content, '/');
        if ($slashPos === false) {
            return false;
        }

        $first = trim(substr($content, 0, $slashPos));
        $path = trim(substr($content, $slashPos + 1));

        return array(
            'first'  => $first,
            'path'   => $path,
            'title'  => $title,
            'align'  => $align,
            'params' => $params
        );
    }

    /**
     * Render output: linkonly, image, or download link
     */
    public function renderOutput($renderer, $url, $filename, $title, $align, $params) {
        $displayTitle = $title ?: $filename;

        if ($params['linkonly']) {
            $this->renderLink($renderer, $url, $displayTitle);
            return;
        }

        if ($this->isImage($filename)) {
            $this->renderImage($renderer, $url, $displayTitle, $align, $params);
        } else {
            $this->renderLink($renderer, $url, $displayTitle);
        }
    }

    /**
     * Render a download link
     */
    public function renderLink($renderer, $url, $title) {
        $renderer->doc .= '<a href="' . hsc($url) . '" class="s3-download" target="_blank">';
        $renderer->doc .= hsc($title);
        $renderer->doc .= '</a>';
    }

    /**
     * Render an image with alignment and sizing options
     */
    public function renderImage($renderer, $url, $alt, $align, $params) {
        $imgClass = 'media';
        if ($align === 'center') {
            $imgClass = 'mediacenter';
        } elseif ($align === 'left') {
            $imgClass = 'medialeft';
        } elseif ($align === 'right') {
            $imgClass = 'mediaright';
        }

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

        $attrStr = '';
        foreach ($imgAttrs as $key => $value) {
            $attrStr .= ' ' . $key . '="' . hsc($value) . '"';
        }

        $img = '<img' . $attrStr . ' loading="lazy" />';

        if ($params['nolink']) {
            $renderer->doc .= $img;
        } else {
            $renderer->doc .= '<a href="' . hsc($url) . '" class="media" target="_blank">' . $img . '</a>';
        }
    }
}
