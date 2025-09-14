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
\defined('_JEXEC') or die('Restricted access');

use Joomla\CMS\Application\CMSApplication;
use Joomla\CMS\Event\Content\ContentPrepareEvent;
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
use Joomla\Event\SubscriberInterface;
use Joomla\CMS\Event\Model;
use Joomla\CMS\Event\Plugin\AjaxEvent;
use Joomla\Database\DatabaseAwareTrait;
use Mautic\Plugin\System\Mautic\Features\OAuth2Client;
use Mautic\Plugin\System\Mautic\Features\PrepareContentTrait;
use Mautic\Plugin\System\Mautic\Features\TokenTrait;

/**
 *
 * @package     Mautic-Joomla.Plugin
 * @subpackage  System.Mautic
 */
final class Mautic extends CMSPlugin implements SubscriberInterface
{
    use PrepareContentTrait, TokenTrait
    {
        PrepareContentTrait::onContentPrepare as protected onContentPrepare_PrepareContentTrait;
        TokenTrait::onExtensionBeforeSave as protected onExtensionBeforeSave_TokenTrait;
        TokenTrait::onExtensionAfterSave as protected onExtensionAfterSave_TokenTrait;
    }
    use DatabaseAwareTrait;

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
    public function __construct(DispatcherInterface $dispatcher, array $config = []) 
    {
        parent::__construct($dispatcher, $config);

        // Define the logger.
        Log::addLogger(['text_file' => 'plg_system_mautic.php'], Log::ALL, ['plg_system_mautic']);
    }

    /**
     * Get the subscribed events for the plugin.
     *
     * @return string[]
     *
     * @since 3.0.0
     *
     * @throws Exception
     */
    public static function getSubscribedEvents(): array
    {
        // TODO map own events
        $app = Factory::getApplication();

        $mapping  = [];

        // Only allowed in the backend
        if ($app->isClient('administrator')) {
            $mapping['onExtensionBeforeSave'] = 'onExtensionBeforeSave';
            $mapping['onExtensionAfterSave']  = 'onExtensionAfterSave';
        } else {
            $mapping['onAjaxMautic']          = 'onAjaxMautic';
            $mapping['onBeforeCompileHead']   = 'onBeforeCompileHead';
            $mapping['onContentPrepare']      = 'onContentPrepare';
        }

        return $mapping;
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
     * @param   ContentPrepareEvent $event  The event instance.
     *
     * @return  void
     */
    public function onContentPrepare(ContentPrepareEvent $event)
    {
        $this->onContentPrepare_PrepareContentTrait($event);
    }

    /**
     * Ajax call for Mautic.
     *
     * @param   AjaxEvent  $event  The event object
     *
     * @return  void
     *
     * @since 3.0.0
     */
    public function onAjaxMautic(AjaxEvent $event)
    {
        $app = $event->getApplication();

        $code = $app->getInput()->get('code', false, 'raw');

        if (!$code) {
            return;
        }

        $plugin = PluginHelper::getPlugin('system', 'mautic');

        // Authenticate with OAuth2 - get access token - TokenTrait
        $this->OAuth2Authenticate();

        $url = Uri::root() . 'administrator/index.php?option=com_plugins&task=plugin.edit&extension_id=' . $plugin->id;

        $this->app->redirect($url, (int) 303);
    }

    /**
     * Clear all Data from token when keys change.
     * This method acts on table save, checks old data and clears the token data if the keys have changed.
     *
     * @param   Model\SaveEvent  $event The onExtensionBeforeSave event.
     *
     * @return void
     *
     * @since 3.0.0
     */
    public function onExtensionBeforeSave(Model\SaveEvent $event): void
    {
        $this->onExtensionBeforeSave_TokenTrait($event);
    }

    /**
     * Generate a token for Mautic oAuth.
     * This method acts on table save, when a token doesn't already exist or a reset is required.
     *
     * @param   Model\SaveEvent  $event The onExtensionBeforeSave event.
     *
     * @return void
     *
     * @since 3.0.0
     */
    public function onExtensionAfterSave(Model\SaveEvent $event): void
    {
        $this->onExtensionAfterSave_TokenTrait($event);
    }

    /**
     * Get Table instance of this plugin
     *
     * @return JTableExtension
     */
    // public function authorize($reauthorize = false)
    // {
    //     $apiHelper      = $this->getMauticApiHelper();
    //     $auth           = $apiHelper->getMauticAuth($reauthorize);
    //     $lang           = $this->app->getLanguage();
    //     $table          = $apiHelper->getTable();

    //     $lang->load('plg_system_mautic', JPATH_ADMINISTRATOR);

    //     $this->log('Authorize method called.', Log::INFO);

    //     try {
    //         if ($auth->validateAccessToken()) {
    //             if ($auth->accessTokenUpdated()) {
    //                 $accessTokenData         = new Registry(['token' => array_merge($auth->getAccessTokenData(), ['created' => Factory::getDate()->toSql()])]);
    //                 $logTokenData            = clone $accessTokenData;
    //                 $logToken                = $logTokenData->get('token');
    //                 $logToken->access_token  = '**(hidden)**';
    //                 $logToken->refresh_token = '**(hidden)**';
    //                 $logTokenData->set('token', $logToken);
    //                 $this->log('authorize::accessTokenData: ' . var_export($logTokenData, true), Log::INFO);

    //                 $this->params->merge($accessTokenData);
    //                 $table->set('params', $this->params->toString());
    //                 $table->store();
    //                 $extraWord = $reauthorize ? 'PLG_SYSTEM_MAUTIC_REAUTHORIZED' : 'PLG_SYSTEM_MAUTIC_AUTHORIZED';
    //                 $this->app->enqueueMessage(Text::sprintf('PLG_SYSTEM_MAUTIC_REAUTHORIZE_SUCCESS', Text::_($extraWord)));
    //             } else {
    //                 $this->app->enqueueMessage(Text::_('PLG_SYSTEM_MAUTIC_REAUTHORIZE_NOT_NEEDED'));
    //                 $this->log('Mautic plugin does not need to authorise, it already is authorised.', Log::INFO);
    //             }
    //         }
    //     } catch (\Exception $e) {
    //         $this->app->enqueueMessage($e->getMessage(), 'error');
    //         $this->log($e->getMessage(), Log::ERROR);
    //     }

    //     $this->app->redirect(Route::_('index.php?option=com_plugins&view=plugin&layout=edit&extension_id=' . $table->get('extension_id'), false));
    // }

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
    // public function onUserAfterSave($user, $isNew, $success, $msg = '')
    // {
    //     $this->log('onUserAfterSave method called.', Log::INFO);
    //     $this->log('onUserAfterSave::isNew: ' . var_export($isNew, true), Log::INFO);
    //     $this->log('onUserAfterSave::success: ' . var_export($success, true), Log::INFO);
    //     $this->log('onUserAfterSave::send_registered: ' . var_export($this->params->get('send_registered'), true), Log::INFO);

    //     if ($isNew && $success && $this->params->get('send_registered') == 1) {
    //         $this->log('onUserAfterSave: Send the user to Mautic.', Log::INFO);

    //         try {
    //             $this->apiHelper = $this->getMauticApiHelper();
    //             $mauticBaseUrl   = $this->apiHelper->getMauticBaseUrl();
    //             /** @var \Mautic\Auth\OAuth $auth */
    //             $auth           = $this->apiHelper->getMauticAuth();
    //             // Check and refresh if needed
    //             $authIsValid    = $auth->validateAccessToken();
    //             if ($authIsValid && $auth->accessTokenUpdated()) {
    //                 $this->apiHelper->storeRefreshedToken($auth);
    //             }
    //             /** @var \Mautic\Api\Contacts $contactsapi */
    //             $mauticApi      = new \Mautic\MauticApi();
    //             $contactsapi    = $mauticApi->newApi("contacts", $auth, $mauticBaseUrl . '/api/');
    //             $ip             = $this->getUserIP();
    //             $name           = explode(' ', $user['name']);

    //             $mauticUser = [
    //                 'ipAddress' => $ip,
    //                 'firstname' => isset($name[0]) ? $name[0] : '',
    //                 'lastname'  => isset($name[1]) ? $name[1] : '',
    //                 'email'     => $user['email'],
    //             ];

    //             $this->log('onUserAfterSave::mauticUser: ' . var_export($mauticUser, true), Log::INFO);

    //             $result = $contactsapi->create($mauticUser);

    //             if (isset($result['error'])) {
    //                 $this->log('onUserAfterSave::leadApi::create - response: ' . $result['error']['code'] . ": " . $result['error']['message'], Log::ERROR);
    //             } elseif (!empty($result['contact']['id'])) {
    //                 $this->log('onUserAfterSave: Mautic lead was successfully created with ID ' . $result['lead']['id'], Log::INFO);
    //             } else {
    //                 $this->log('onUserAfterSave: Mautic lead was NOT successfully created. ' . var_export($result, true), Log::ERROR);
    //             }
    //         } catch (\Exception $e) {
    //             $this->log($e->getMessage(), Log::ERROR);
    //         }
    //     } else {
    //         $this->log('onUserAfterSave: Do not send the user to Mautic.', Log::INFO);
    //     }
    // }

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
}
