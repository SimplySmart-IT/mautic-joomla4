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

use Joomla\Application\WebApplicationInterface;
use Joomla\CMS\Event\Content\ContentPrepareEvent;
use Joomla\CMS\Uri\Uri;
use Joomla\Http\Exception\UnexpectedResponseException;
use Joomla\Http\Http;
use Joomla\Input\Input;
use Joomla\OAuth2\Client;

// no direct access
\defined('_JEXEC') or die('Restricted access');

class OAuth2Client extends Client
{
    /**
     * Constructor.
     *
     * @param   array|\ArrayAccess       $options      OAuth2 Client options object
     * @param   Http                     $http         The HTTP client object
     * @param   Input                    $input        The input object
     * @param   WebApplicationInterface  $application  The application object
     *
     * @since   1.0
     */
    public function __construct($options = [], Http $http = null, Input $input = null, WebApplicationInterface $application = null)
    {
        parent::__construct($options, $http, $input, $application);
    }

    /**
     * Get the access token or redirect to the authentication URL.
     *
     * @return  array|boolean  The access token or false on failure
     *
     * @since   3.0.0
     * @throws  UnexpectedResponseException
     * @throws  \RuntimeException
     * @see use libraries/vendor/joomla/oauth2/src/Client.php class Joomla\OAuth2\Client when bug with code param is fixed
     */
    public function authenticate()
    {
        if ($data['code'] = $this->input->get('code', false, 'raw')) {
            $data = [
                'grant_type'    => 'authorization_code',
                'redirect_uri'  => $this->getOption('redirecturi'),
                'client_id'     => $this->getOption('clientid'),
                'client_secret' => $this->getOption('clientsecret'),
            ];

            $response = $this->http->post($this->getOption('tokenurl'), $data);

            if (!($response->code >= 200 && $response->code < 400)) {
                throw new UnexpectedResponseException(
                    $response,
                    sprintf(
                        'Error code %s received requesting access token: %s.',
                        $response->code,
                        $response->body
                    )
                );
            }

            if ($this->isJsonResponse($response)) {
                $token = array_merge(json_decode($response->body, true), ['created' => time()]);
            } else {
                parse_str($response->body, $token);
                $token = array_merge($token, ['created' => time()]);
            }

            $this->setToken($token);

            return $token;
        }

        if ($this->getOption('sendheaders')) {
            if (!($this->application instanceof WebApplicationInterface)) {
                throw new \RuntimeException(
                    \sprintf('A "%s" implementation is required to process authentication.', WebApplicationInterface::class)
                );
            }

            $this->application->redirect($this->createUrl());
        }

        return false;
    }

    /**
     * Refresh the access token instance.
     *
     * @param   string  $token  The refresh token
     *
     * @return  array  The new access token
     *
     * @since   3.0.0
     * @throws  UnexpectedResponseException
     * @throws  \RuntimeException
     * @see libraries/vendor/joomla/oauth2/src/Client.php
     * TODO use class Joomla\OAuth2\Client when bug with headers fixed
     */
    public function refreshToken($token = null)
    {
        if (!$this->getOption('userefresh')) {
            throw new \RuntimeException('Refresh token is not supported for this OAuth instance.');
        }

        if (!$token) {
            $token = $this->getToken();

            if (!array_key_exists('refresh_token', $token)) {
                throw new \RuntimeException('No refresh token is available.');
            }

            $token = $token['refresh_token'];
        }

        $data = [
            'grant_type'    => 'refresh_token',
            'refresh_token' => $token,
            'client_id'     => $this->getOption('clientid'),
            'client_secret' => $this->getOption('clientsecret'),
        ];

        $response = $this->http->post($this->getOption('tokenurl'), $data);

        if (!($response->code >= 200 || $response->code < 400)) {
            throw new UnexpectedResponseException(
                $response,
                sprintf(
                    'Error code %s received refreshing token: %s.',
                    $response->code,
                    $response->body
                )
            );
        }

        // if ($response->code === 400) {
        // @todo Exception
        //     $this->log(
        //         \sprintf('Invalid request received while refreshing token: \\n Statuscode: %s \\n Response: %s', $response->code, $response->body),
        //         Log::ERROR
        //     );
        // }

        if ($this->isJsonResponse($response)) {
            $token = array_merge(json_decode($response->body, true), ['created' => time()]);
        } else {
            parse_str($response->body, $token);
            $token = array_merge($token, ['created' => time()]);
        }

        $this->setToken($token);

        return $token;
    }

    /**
     * Tests if given response contains JSON header
     *
     * @param   \Joomla\Http\Response  $response  The response object
     *
     * @return  bool
     *
     * @since   3.0.0
     * @see https://github.com/joomla-framework/oauth2/pull/20
     */
    private function isJsonResponse(\Joomla\Http\Response $response): bool
    {
        foreach ($response->getHeader('Content-Type') as $value) {
            if (strpos($value, 'application/json') !== false) {
                return true;
            }
        }

        return false;
    }

}
