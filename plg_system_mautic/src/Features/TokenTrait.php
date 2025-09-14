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


// no direct access
\defined('_JEXEC') or die('Restricted access');

use Joomla\CMS\Event\Model;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\CMS\Uri\Uri;
use Joomla\Database\DatabaseAwareTrait;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;
use Joomla\Http\Exception\UnexpectedResponseException;
use Joomla\Registry\Registry;
use Joomla\Utilities\ArrayHelper;
use Mautic\Plugin\System\Mautic\Features\OAuth2Client;

/**
 * Feature: Token Handling
 *
 * @since   3.0.0
 */
trait TokenTrait
{

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
        $context   = $event->getContext();
        $extension = $event->getItem();

        if ($context !== 'com_plugins.plugin' || $extension->element !== $this->_name) {
            return;
        }

        $newParams = new Registry($extension->get('params'));
        $tokenData = $newParams->get('token', null);

        if (
            $tokenData && (!isset($this->params) || ($this->params->get('public_key', '') !== $newParams->get('public_key', '')
            || $this->params->get('secret_key', '') !== $newParams->get('secret_key', '')))
        ) {
            $tokenData = ArrayHelper::fromObject($newParams->get('token', []));
            foreach ($tokenData as $key => &$data) {
                $data = "";
            }
            $newParams->token = ArrayHelper::toObject(['token' => $tokenData]);
            $extension->set('params', $newParams->toString());
        }
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
        $context  = $event->getContext();
        $item     = $event->getItem();
        $data     = $event->getData();

        if ($context !== 'com_plugins.plugin' || $item->element !== $this->_name) {
            return;
        }

        if (\is_null($item)) {
            return;
        }

        $app = $this->getApp();

        //get gentoken value and check
        if ($app->getInput()->get('gentoken', null, 'int')) {
            $isRoot = $app->getIdentity()->authorise('core.admin');
            if ($isRoot) {
                if (
                    !\array_key_exists('public_key', $data['params']) || !$data['params']['public_key'] ||
                    !\array_key_exists('secret_key', $data['params']) || !$data['params']['secret_key']
                ) {
                    $app->enqueueMessage(Text::_('PLG_SYSTEM_MAUTIC_AUTH_MISSING_DATA_ERROR'), 'warning');
                    // $this->log('Client-id and/or client-secret missing.', Log::ERROR); @todo
                    return;
                }

                // @todo try/catch? or test
                $this->OAuth2Authenticate();

            } else {
                $app->enqueueMessage(Text::_('PLG_SYSTEM_MAUTIC_ERROR_ONLY_ADMIN_CAN_AUTHORIZE'), 'warning');
                // $this->log('Only admins can authorise Mautic API connections.', Log::ERROR); @todo
            }
        }
    }

    /**
     * OAuth2 Authentication routine.
     *
     * @since 3.0.0
     * @throws Exception
     */
    protected function OAuth2Authenticate()
    {

        $baseUrl = $this->getBaseUrl();
        $app     = $this->getApplication();
        $params  = $this->getParams();

        if (!$baseUrl) {
            // $this->log('Base URL missing.', Log::ERROR); @todo
            \throwException(new \Exception(Text::_('PLG_SYSTEM_MAUTIC_AUTH_MISSING_DATA_ERROR')));
            return;
        }

        $redirect = Uri::root() . 'index.php?option=com_ajax&plugin=' . $this->_name . '&format=raw';

        $options  = [
            'redirecturi'  => $redirect,
            'clientid'     => $params->get('public_key', 1),
            'clientsecret' => $params->get('secret_key', ''),
            'tokenurl'     => $baseUrl . '/oauth/v2/token',
            'authurl'      => $baseUrl . '/oauth/v2/authorize',
        ];

        $token = $params->get('token', '');
        $token = ($token) ? ArrayHelper::fromObject($token) : '';

        if ($token && \array_key_exists('refresh_token', $token) && $token['refresh_token']) {
            $options['userefresh'] = true;
        } elseif (!$params->get('code', false)) {
            $options['sendheaders'] = true;
        }

        $client = new OAuth2Client($options, null, $app->getInput(), $app);

        if (\array_key_exists('userefresh', $options) && $options['userefresh']) {
            // Workaround @see https://github.com/joomla-framework/oauth2/pull/20
            // cannot be lowercase @see libraries/vendor/joomla/oauth2/src/Client.php line 410
            $response = $client->refreshToken($token['refresh_token']);
        } else {
            // Workaround @see https://github.com/joomla-framework/oauth2/pull/20
            // cannot be lowercase @see libraries/vendor/joomla/oauth2/src/Client.php line 117
            $response = $client->authenticate();
        }

        if ($response instanceof UnexpectedResponseException) {
            Factory::getApplication()->enqueueMessage('PLG_SISMOSAPPOINTMENT_AUTH_ERROR', 'error');
            return;
        }

        if ($response) {
            $this->saveToken($response);
        }
    }

    /**
     * Save the access token to the database.
     *
     * @param array|object $response The response from the token endpoint.
     *
     * @since 3.0.0
     */
    private function saveToken(array|object $response)
    {
        $plugin = PluginHelper::getPlugin($this->_type, $this->_name);
        if (!isset($plugin->id)) {
            // $this->log('No plugin id found to save the token.', Log::ERROR); @todo
            return;
        }
        $this->getParams()->set('token', (object) $response);
        $this->getParams()->set('code', '');
        $params = json_encode($this->getParams(), JSON_UNESCAPED_SLASHES);

        $db = $this->getDB();
        try {
            $query = $db->getQuery(true);

            $query->update($db->quoteName('#__extensions'))
                ->set($db->quoteName('params') . ' = :params')
                ->where($db->quoteName('extension_id') . ' = :extid')
                ->bind(':params', $params, ParameterType::STRING)
                ->bind(':extid', $plugin->id, ParameterType::INTEGER);

            $db->setQuery($query);

            $db->execute();
        } catch (\Exception $e) {
            // $this->log(Text::sprintf('Error while saving the oAuth token for mautic to db:\n %s', $e->getMessage() . ' -Response: ' . (\is_array($response) ? print_r($response, true) : $response)), Log::ERROR); @todo
            Factory::getApplication()->enque->setError($e->getMessage());
        }
    }

    /**
     * Get the database connection.
     */
    private function getDB() {
        return $this instanceof DatabaseAwareTrait ? $this->getDatabase() : Factory::getContainer()->get(DatabaseInterface::class);
    }

    /**
     * Get the application instance.
     */
    private function getApp() {
        return $this->app ?? Factory::getApplication();
    }

    /**
     * Get the parameters.
     */
    private function getParams() {
        return $this->params ?? new Registry();
    }

    /**
     * Get the baseUrl for OAuth2
     */
    private function getBaseUrl() {
        $baseUrl = $this->getParams()->get('base_url', '');
        return $baseUrl ? trim($baseUrl, " \t\n\r\0\x0B/") : '';
    }
}
