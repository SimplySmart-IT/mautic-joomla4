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

        if ($context !== 'com_plugins.plugin' || $extension->element !== 'mautic') {
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

}
