<?php

/**
 * @package     Mautic-Joomla.Plugin
 * @subpackage  System.Mautic
 *
 * @author      Mautic
 * @copyright   Copyright (C) 2014 - 2023 Mautic All Rights Reserved.
 * @license     http://www.gnu.org/licenses/gpl-3.0.html GNU/GPL
 * @link        http://www.mautic.org
 */

namespace Mautic\Plugin\System\Mautic\Features;

use Joomla\CMS\Event\Content\ContentPrepareEvent;
use Joomla\CMS\Uri\Uri;

// no direct access
\defined('_JEXEC') or die('Restricted access');

/**
 * Feature: Content Prepare Handling
 *
 * @since   3.0.0
 */
trait PrepareContentTrait
{
    /**
     * Regex to capture all {mautic} tags in content
     *
     * @var string
     */
    protected $mauticRegex = '/\{(\{?)(mautic)(?![\w-])([^\}\/]*(?:\/(?!\})[^\}\/]*)*?)(?:(\/)\}|\}(?:([^\{]*+(?:\{(?!\/\2\})[^\{mautic]*+)*+)\{\/\2\})?)(\}?)/i';

    /**
     * Taken from WP get_shortcode_atts_regex
     *
     * @var string
     */
    protected $attsRegex   = '/([\w-]+)\s*=\s*"([^"]*)"(?:\s|$)|([\w-]+)\s*=\s*\'([^\']*)\'(?:\s|$)|([\w-]+)\s*=\s*([^\s\'"]+)(?:\s|$)|"([^"]*)"(?:\s|$)|(\S+)(?:\s|$)/';

    /**
     * Insert form script to the content
     *
     * @param   ContentPrepareEvent $event  The event instance.
     *
     * @return  void
     */
    public function onContentPrepare(ContentPrepareEvent $event)
    {
        $context = $event->getContext();
        $article     = $event->getItem();

        // Check to make sure we are loading an HTML view and there is a main component area and content is not being indexed
        if (
            $this->app->getDocument()->getType() !== 'html'
            || $this->app->getInput()->get('tmpl', '', 'cmd') === 'component'
            || !$this->app->isClient('site')
            || $context == 'com_finder.indexer'
        ) {
            return true;
        }

        // simple performance check to determine whether bot should process further
        if (strpos($article->text, '{mautic') === false) {
            return true;
        }

        $this->prepareContent($article->text);
    }

    /**
     * Prepare the content
     *
     * @param string $text
     * @return string
     *
     * @since 3.0.0
     */
    protected function prepareContent(string $text): string {
        // Replace {mauticform with {mautic type="form"
        $text = str_replace('{mauticform', '{mautic type="form"', $text);

        preg_match_all($this->mauticRegex, $text, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $atts       = $this->parseShortcodeAtts($match[3]);
            $method     = 'do' . ucfirst(strtolower($atts['type'])) . 'Shortcode';
            $newContent = '';

            if (method_exists($this, $method)) {
                $newContent = \call_user_func([$this, $method], $atts, $match[5]);
            }

            $text = str_replace($match[0], $newContent, $text);
        }

        return $text;
    }

    /**
     * Do a find/replace for Mautic forms
     *
     * @param array $atts
     *
     * @return string
     */
    protected function doFormShortcode($atts)
    {

        $id = isset($atts['id']) ? $atts['id'] : $atts[0];

        return '<script type="text/javascript" src="' . trim($this->params->get('base_url'), " \t\n\r\0\x0B/") . '/form/generate.js?id=' . $id . '"></script>';
    }

    /**
     * Do a find/replace for Mautic dynamic content
     *
     * @param array  $atts
     * @param string $content
     *
     * @return string
     */
    protected function doContentShortcode($atts, $content)
    {
        return '<div class="mautic-slot" data-slot-name="' . $atts['slot'] . '">' . $content . '</div>';
    }

    /**
     * Do a find/replace for Mautic gated video
     *
     * @param array $atts
     *
     * @return string
     * 
     * @deprecated Will be removed without replacement as this feature is no longer supported and removed from Mautic 6.x 
     */
    protected function doVideoShortcode($atts)
    {
        $video_type = '';
        $atts       = $this->filterAtts([
            'gate-time' => 15,
            'form-id'   => '',
            'src'       => '',
            'width'     => 640,
            'height'    => 360,
        ], $atts);

        if (empty($atts['src'])) {
            return 'You must provide a video source. Add a src="URL" attribute to your shortcode. Replace URL with the source url for your video.';
        }

        if (empty($atts['form-id'])) {
            return 'You must provide a mautic form id. Add a form-id="#" attribute to your shortcode. Replace # with the id of the form you want to use.';
        }

        if (preg_match('/^.*((youtu.be)|(youtube.com))\/((v\/)|(\/u\/\w\/)|(embed\/)|(watch\?))?\??v?=?([^#\&\?]*).*/', $atts['src'])) {
            $video_type = 'youtube';
        }

        if (preg_match('/^.*(vimeo\.com\/)((channels\/[A-z]+\/)|(groups\/[A-z]+\/videos\/))?([0-9]+)/', $atts['src'])) {
            $video_type = 'vimeo';
        }

        if (strtolower(substr($atts['src'], -3)) === 'mp4') {
            $video_type = 'mp4';
        }

        if (empty($video_type)) {
            return 'Please use a supported video type. The supported types are youtube, vimeo, and MP4.';
        }

        return '<video height="' . $atts['height'] . '" width="' . $atts['width'] . '" data-form-id="' . $atts['form-id'] . '" data-gate-time="' . $atts['gate-time'] . '">' .
        '<source type="video/' . $video_type . '" src="' . $atts['src'] . '" /></video>';
    }

    /**
     * Do a find/replace for Mautic tags
     *
     * @param array  $atts
     *
     * @return string
     */
    protected function doTagsShortcode($atts)
    {
        if (!$this->params->get('base_url', '')) {
            return '';
        }

        $currentUri = 'page_url=' . Uri::current();

        return '<img src="' . trim($this->params->get('base_url'), " \t\n\r\0\x0B/") . '/mtracking.gif?' . $currentUri . '&tags=' . $atts['tags'] . '" alt="mtc-tags" style="display:none;" />';
    }

    /**
     * Taken from WP wp_parse_shortcode_atts
     *
     * @param $text
     *
     * @return array|string
     */
    private function parseShortcodeAtts($text)
    {
        $atts = [];
        $text = preg_replace("/[\x{00a0}\x{200b}]+/u", " ", $text);

        if (preg_match_all($this->attsRegex, $text, $match, PREG_SET_ORDER)) {
            foreach ($match as $m) {
                if (!empty($m[1])) {
                    $atts[strtolower($m[1])] = stripcslashes($m[2]);
                } elseif (!empty($m[3])) {
                    $atts[strtolower($m[3])] = stripcslashes($m[4]);
                } elseif (!empty($m[5])) {
                    $atts[strtolower($m[5])] = stripcslashes($m[6]);
                } elseif (isset($m[7]) && \strlen($m[7])) {
                    $atts[] = stripcslashes($m[7]);
                } elseif (isset($m[8])) {
                    $atts[] = stripcslashes($m[8]);
                }
            }
            // Reject any unclosed HTML elements
            foreach ($atts as &$value) {
                if (false !== strpos($value, '<')) {
                    if (1 !== preg_match('/^[^<]*+(?:<[^>]*+>[^<]*+)*+$/', $value)) {
                        $value = '';
                    }
                }
            }
        } else {
            $atts = ltrim($text);
        }

        return $atts;
    }

    /**
     * Taken fro WP wp_shortcode_atts
     *
     * @param array $pairs
     * @param array $atts
     *
     * @return array
     */
    private function filterAtts(array $pairs, array $atts)
    {
        $out = [];

        foreach ($pairs as $name => $default) {
            if (\array_key_exists($name, $atts)) {
                $out[$name] = $atts[$name];
            } else {
                $out[$name] = $default;
            }
        }

        return $out;
    }

}
