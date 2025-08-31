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

namespace Mautic\Plugin\System\Mautic\Extension;

// no direct access
// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die('Restricted access');
// phpcs:enable PSR1.Files.SideEffects

use Joomla\CMS\Application\CMSApplication;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Uri\Uri;
use Joomla\Event\DispatcherInterface;
use Joomla\Registry\Registry;
use Joomla\Utilities\ArrayHelper;
use Mautic\Plugin\System\Mautic\Helper\MauticApiHelper;

/**
 *
 * @package     Mautic-Joomla.Plugin
 * @subpackage  System.Mautic
 */
final class Mautic extends CMSPlugin
{
    /**
     * Application object
     *
     * @var    CMSApplication
     * @since  3.0.0
     */
    protected $app;

    /**
     * Affects constructor behavior. If true, language files will be loaded automatically.
     *
     * @var    boolean
     * @since  3.0.0
     */
    protected $autoloadLanguage = true;

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
     * MauticApiHelper
     *
     * @var Mautic\Plugin\System\Mautic\Helper\MauticApiHelper
     */
    protected $apiHelper;

    /**
     * Constructor.
     *
     * @param   DispatcherInterface       $dispatcher       The dispatcher
     * @param   array                     $config           An optional associative array of configuration settings
     *
     * @since   3.0.0
     */
    public function __construct(
        DispatcherInterface $dispatcher,
        array $config
    ) {
        parent::__construct($dispatcher, $config);

        // Define the logger.
        Log::addLogger(['text_file' => 'plg_system_mautic.php'], Log::ALL, ['plg_system_mautic']);
    }

    /**
     * This event is triggered before the framework creates the Head section of the Document.
     *
     * @return  void
     *
     * @since   3.0.0
     */
    public function onBeforeCompileHead()
    {
        // Check to make sure we are loading an HTML view and it's site
        if ($this->app->getDocument()->getType() !== 'html' || $this->app->getInput()->get('tmpl', '', 'cmd') === 'component' || !$this->app->isClient('site')) {
            return;
        }

        if (!PluginHelper::isEnabled('system', 'mautic')) {
            return;
        }

        $attrs = [];

        $user = $this->app->getIdentity();

        // Get info about the user if logged in
        if (!$user->guest) {
            $attrs['email'] = $user->email;

            $name = explode(' ', $user->name);

            if (isset($name[0])) {
                $attrs['firstname'] = $name[0];
            }

            $count       = \count($name);
            $lastNamePos = $count - 1;

            if ($lastNamePos !== 0 && isset($name[$lastNamePos])) {
                $attrs['lastname'] = $name[$lastNamePos];
            }
        }

        if ($basurl = trim($this->params->get('base_url', ' '), " \t\n\r\0\x0B/")) {
            $document = $this->app->getDocument();
            // Add plugin settings from the xml
            $document->addScriptOptions('plgmtc.baseurl', $basurl);
            if (!empty($attrs)) {
                $document->addScriptOptions('plgmtcOptions', $attrs);
            }

            /** @var Joomla\CMS\WebAsset\WebAssetManager $wa */
            $wa = $document->getWebAssetManager();
            $wa->registerAndUseScript('plg_system_mtc.base', 'plg_system_mautic/plg_system_mtc.js');
        }
    }

    /**
     * Insert form script to the content
     *
     * @param   string  $context The context of the content being passed to the plugin.
     * @param   object  $article The article object.  Note $article->text is also available
     * @param   object  $params  The article params
     * @param   integer $page    The 'page' number
     *
     * @return  void
     */
    public function onContentPrepare($context, &$article, &$params, $page = 0)
    {
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

        // Replace {mauticform with {mautic type="form"
        $article->text = str_replace('{mauticform', '{mautic type="form"', $article->text);

        preg_match_all($this->mauticRegex, $article->text, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $atts       = $this->parseShortcodeAtts($match[3]);
            $method     = 'do' . ucfirst(strtolower($atts['type'])) . 'Shortcode';
            $newContent = '';

            if (method_exists($this, $method)) {
                $newContent = \call_user_func([$this, $method], $atts, $match[5]);
            }

            $article->text = str_replace($match[0], $newContent, $article->text);
        }
    }

    /**
     * Do a find/replace for Mautic forms
     *
     * @param array $atts
     *
     * @return string
     */
    public function doFormShortcode($atts)
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
    public function doContentShortcode($atts, $content)
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
    public function doVideoShortcode($atts)
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
    public function doTagsShortcode($atts)
    {
        if (!$this->params->get('base_url', '')) {
            return '';
        }

        $currentUri = 'page_url=' . Uri::current();

        return '<img src="' . trim($this->params->get('base_url'), " \t\n\r\0\x0B/") . '/mtracking.gif?' . $currentUri . '&tags=' . $atts['tags'] . '" alt="mtc-tags" style="display:none;" />';
    }

    /**
     * Clear all Data from token when keys change.
     * This method acts on table save, checks old data and clears the token data if the keys have changed.
     *
     * @param   string             $context      The context
     * @param   \Joomla\CMS\Table  $table        The table
     * @param   boolean            $isNew        Is new item
     * @param   mixed              $extension    The extension
     *
     * @return void
     *
     * @since 3.0.0
     */
    public function onExtensionBeforeSave($context, $table, $isNew, $extension = null): void
    {
        if ($context !== 'com_plugins.plugin' || $table->element !== 'mautic') {
            return;
        }

        $newParams = new Registry($table->get('params'));
        $tokenData = $newParams->get('token', null);

        if (
            $tokenData && (!isset($this->params) || ($this->params->get('public_key', '') !== $newParams->get('public_key', '')
            || $this->params->get('private_key', '') !== $newParams->get('private_key', '')))
        ) {
            $tokenData = ArrayHelper::fromObject($newParams->get('token', []));
            foreach ($tokenData as $key => &$data) {
                $data = "";
            }
            $newParams->set('token', ArrayHelper::toObject(['token' => $tokenData]));
            $table->set('params', $newParams->toString());
        }
    }

    /**
     * Generate a token for Mautic oAuth.
     * This method acts on table save, when a token doesn't already exist or a reset is required.
     *
     * @param   string             $context      The context
     * @param   \Joomla\CMS\Table  $table        The table
     * @param   boolean            $isNew        Is new item
     * @param   mixed              $extension    The extension
     *
     * @return void
     *
     * @since 3.0.0
     */
    public function onExtensionAfterSave($context, $table, $isNew, $extension = null): void
    {
        if ($context !== 'com_plugins.plugin' || $table->element !== 'mautic') {
            return;
        }

        if (\is_null($extension)) {
            return;
        }

        //get gentoken value and check
        if (Factory::getApplication()->getInput()->get('gentoken', null, 'int')) {
            $isRoot = $this->app->getIdentity()->authorise('core.admin');
            if ($isRoot) {
                if (
                    !\array_key_exists('public_key', $extension['params']) || !$extension['params']['public_key'] ||
                    !\array_key_exists('secret_key', $extension['params']) || !$extension['params']['secret_key']
                ) {
                    $this->app->enqueueMessage(Text::_('PLG_SYSTEM_MAUTIC_AUTH_MISSING_DATA_ERROR'), 'warning');
                    $this->log('Client-id and/or client-secret missing.', Log::ERROR);
                    return;
                }

                $this->apiHelper = new MauticApiHelper($table);

                $this->authorize(true); // TODO
            } else {
                $this->app->enqueueMessage(Text::_('PLG_SYSTEM_MAUTIC_ERROR_ONLY_ADMIN_CAN_AUTHORIZE'), 'warning');
                $this->log('Only admins can authorise Mautic API connections.', Log::ERROR);
            }
        }
    }

    /**
    * Mautic API call
    *
    * @since 3.0.0
    */
    public function onAfterRoute()
    {
        if (!Factory::getApplication()->isClient('administrator')) {
            return;
        }

        $isRoot = Factory::getApplication()->getIdentity()->authorise('core.admin');

        if (!Factory::getApplication()->getUserState('mauticapi.data.oauth_gentoken', 0)) {
            return;
        }

        if ($isRoot) {
            $input = Factory::getApplication()->getInput();
            if (
                ($input->get('oauth_token') && $input->get('oauth_verifier'))
                || ($input->get('state') && $input->get('code'))
            ) {
                $this->authorize($input->get('reauthorize', false, 'BOOLEAN')); // TODO
                $plugin = PluginHelper::getPlugin('system', 'mautic');
                $url    = Uri::root() . 'administrator/index.php?option=com_plugins&task=plugin.edit&extension_id=' . $plugin->id;
                Factory::getApplication()->redirect($url, (int) 303);
            }
        } else {
            Factory::getApplication()->enqueueMessage(Text::_('PLG_SYSTEM_MAUTIC_ERROR_ONLY_ADMIN_CAN_AUTHORIZE'), 'warning');
            $this->log('Only admins can authorise Mautic API connections.', Log::ERROR);
        }
    }

    /**
     * Create sanitized Mautic Base URL without the slash at the end.
     *
     *  @param \Joomla\CMS\Table\Table|null $table
     *
     * @return Mautic\Plugin\System\Mautic\Helper\MauticApiHelper
     */
    public function getMauticApiHelper($table = null)
    {
        if ($this->apiHelper) {
            return $this->apiHelper;
        }

        $this->apiHelper = new MauticApiHelper($table);

        return $this->apiHelper;
    }

    /**
     * Get Table instance of this plugin
     *
     * @return JTableExtension
     */
    public function authorize($reauthorize = false)
    {
        $apiHelper      = $this->getMauticApiHelper();
        $auth           = $apiHelper->getMauticAuth($reauthorize);
        $lang           = $this->app->getLanguage();
        $table          = $apiHelper->getTable();

        $lang->load('plg_system_mautic', JPATH_ADMINISTRATOR);

        $this->log('Authorize method called.', Log::INFO);

        try {
            if ($auth->validateAccessToken()) {
                if ($auth->accessTokenUpdated()) {
                    $accessTokenData         = new Registry(['token' => array_merge($auth->getAccessTokenData(), ['created' => Factory::getDate()->toSql()])]);
                    $logTokenData            = clone $accessTokenData;
                    $logToken                = $logTokenData->get('token');
                    $logToken->access_token  = '**(hidden)**';
                    $logToken->refresh_token = '**(hidden)**';
                    $logTokenData->set('token', $logToken);
                    $this->log('authorize::accessTokenData: ' . var_export($logTokenData, true), Log::INFO);

                    $this->params->merge($accessTokenData);
                    $table->set('params', $this->params->toString());
                    $table->store();
                    $extraWord = $reauthorize ? 'PLG_SYSTEM_MAUTIC_REAUTHORIZED' : 'PLG_SYSTEM_MAUTIC_AUTHORIZED';
                    $this->app->enqueueMessage(Text::sprintf('PLG_SYSTEM_MAUTIC_REAUTHORIZE_SUCCESS', Text::_($extraWord)));
                } else {
                    $this->app->enqueueMessage(Text::_('PLG_SYSTEM_MAUTIC_REAUTHORIZE_NOT_NEEDED'));
                    $this->log('Mautic plugin does not need to authorise, it already is authorised.', Log::INFO);
                }
            }
        } catch (\Exception $e) {
            $this->app->enqueueMessage($e->getMessage(), 'error');
            $this->log($e->getMessage(), Log::ERROR);
        }

        $this->app->redirect(Route::_('index.php?option=com_plugins&view=plugin&layout=edit&extension_id=' . $table->get('extension_id'), false));
    }

    /**
     * Create new lead on Joomla user registration
     *
     * For debug is better to switch function to:
     * public function onUserBeforeSave($success, $isNew, $user)
     *
     * @param array     $user       array with user information
     * @param boolean   $isNew      whether the user is new
     * @param boolean   $success    whether the user was saved successfully
     * @param string    $msg        error message
     */
    public function onUserAfterSave($user, $isNew, $success, $msg = '')
    {
        $this->log('onUserAfterSave method called.', Log::INFO);
        $this->log('onUserAfterSave::isNew: ' . var_export($isNew, true), Log::INFO);
        $this->log('onUserAfterSave::success: ' . var_export($success, true), Log::INFO);
        $this->log('onUserAfterSave::send_registered: ' . var_export($this->params->get('send_registered'), true), Log::INFO);

        if ($isNew && $success && $this->params->get('send_registered') == 1) {
            $this->log('onUserAfterSave: Send the user to Mautic.', Log::INFO);

            try {
                $this->apiHelper = $this->getMauticApiHelper();
                $mauticBaseUrl   = $this->apiHelper->getMauticBaseUrl();
                /** @var \Mautic\Auth\OAuth $auth */
                $auth           = $this->apiHelper->getMauticAuth();
                // Check and refresh if needed
                $authIsValid    = $auth->validateAccessToken();
                if ($authIsValid && $auth->accessTokenUpdated()) {
                    $this->apiHelper->storeRefreshedToken($auth);
                }
                /** @var \Mautic\Api\Contacts $contactsapi */
                $mauticApi      = new \Mautic\MauticApi();
                $contactsapi    = $mauticApi->newApi("contacts", $auth, $mauticBaseUrl . '/api/');
                $ip             = $this->getUserIP();
                $name           = explode(' ', $user['name']);

                $mauticUser = [
                    'ipAddress' => $ip,
                    'firstname' => isset($name[0]) ? $name[0] : '',
                    'lastname'  => isset($name[1]) ? $name[1] : '',
                    'email'     => $user['email'],
                ];

                $this->log('onUserAfterSave::mauticUser: ' . var_export($mauticUser, true), Log::INFO);

                $result = $contactsapi->create($mauticUser);

                if (isset($result['error'])) {
                    $this->log('onUserAfterSave::leadApi::create - response: ' . $result['error']['code'] . ": " . $result['error']['message'], Log::ERROR);
                } elseif (!empty($result['contact']['id'])) {
                    $this->log('onUserAfterSave: Mautic lead was successfully created with ID ' . $result['lead']['id'], Log::INFO);
                } else {
                    $this->log('onUserAfterSave: Mautic lead was NOT successfully created. ' . var_export($result, true), Log::ERROR);
                }
            } catch (\Exception $e) {
                $this->log($e->getMessage(), Log::ERROR);
            }
        } else {
            $this->log('onUserAfterSave: Do not send the user to Mautic.', Log::INFO);
        }
    }

    /**
     * Try to guess the real user IP address
     *
     * @return  string
     */
    private function getUserIP()
    {
        return \Joomla\Utilities\IpHelper::getIp();
    }

    /**
     * Log helper function
     *
     * @return  string
     */
    private function log($msg, $type)
    {
        if ($this->params->get('log_on', 1)) {
            Log::add($msg, $type, 'plg_system_mautic');
        }
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
