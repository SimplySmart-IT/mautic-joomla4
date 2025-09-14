<?php

/**
 * @package     Mautic-Joomla.Plugin
 * @subpackage  System.Token
 *
 * @author      Mautic
 * @copyright   (C) 2023 - 2025 Mautic
 * @license     GNU General Public License version 3 or later; see LICENSE.txt

 * @phpcs:disable PSR1.Classes.ClassDeclaration.MissingNamespace
 */

namespace Mautic\Plugin\System\Mautic\Field;

use DateTime;
use Joomla\CMS\Date\Date;
use Joomla\CMS\Factory;
use Joomla\CMS\Form\Field\SubformField;
use Joomla\CMS\Language\Text;
use SimpleXMLElement;

// phpcs:disable PSR1.Files.SideEffects
\defined('JPATH_PLATFORM') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * The Field to show, create and refresh a oauth token
 *
 * @since  2.0.0
 */
class TokenField extends SubformField
{
    /**
     * The form field type.
     * @var    string
     */
    protected $type = 'Token';

    /**
     * Method to attach a Form object to the field.
     *
     * @param   \SimpleXMLElement  $element  The SimpleXMLElement object representing the <field /> tag for the form field object.
     * @param   mixed              $value    The form field value to validate.
     * @param   string             $group    The field name group control value.
     *
     * @return  boolean  True on success.
     *
     * @since   2.0.0
     */
    public function setup(\SimpleXMLElement $element, $value, $group = null)
    {
        /**
         * When you have subforms which are not repeatable (i.e. a subform custom field with the
         * repeat attribute set to 0) you get an array here since the data comes from decoding the
         * JSON into an associative array, including the media subfield's data.
         *
         * However, this method expects an object or a string, not an array. Typecasting the array
         * to an object solves the data format discrepancy.
         */
        $value = \is_array($value) ? (object) $value : $value;

        /**
         * If the value is not a string, it is
         * most likely within a custom field of type subform
         * and the value is a stdClass with properties
         * access_token. So it is fine.
        */
        if (\is_string($value)) {
            json_decode($value);

            // Check if value is a valid JSON string.
            if ($value !== '' && json_last_error() !== JSON_ERROR_NONE) {
                $value = '';
            }
        } elseif (
            !\is_object($value)
            || !property_exists($value, 'access_token')
        ) {
            $value->access_token = "";
        }

        if (!parent::setup($element, $value, $group)) {
            $value = '';
        }

        // TODO show in Description $expires_datetime = (property_exists($value, 'expires') && $value->expires) ? (new Date('@' . $value->expires)) : '';

        $xml = <<<XML
<?xml version="1.0" encoding="utf-8"?>
<form>
	<fieldset
		name="token"
		label="PLG_SYSTEM_MAUTIC_TOKEN_LABEL"
		parentclass="form-grid"
	>
		<field
			name="access_token"
			type="password"
			default=""
			label="PLG_SYSTEM_MAUTIC_TOKEN_LABEL"
			description="PLG_SYSTEM_MAUTIC_TOKEN_DESC"
			autocomplete="off"
			readonly="true"
			filter="raw"
			parentclass="stack"
		/>

		<field
			name="refresh_token"
			type="password"
			default=""
			label="PLG_SYSTEM_MAUTIC_TOKEN_REFRESH_LABEL"
			autocomplete="off"
			readonly="true"
			filter="raw"
			parentclass="stack"
		/>

		<field
			name="token_type"
			type="text"
			label="PLG_SYSTEM_MAUTIC_TOKEN_TYPE_LABEL"
			readonly="true"
			parentclass="stack span-2-inline"
		/>

		<field
			name="expires"
			type="text"
			label="PLG_SYSTEM_MAUTIC_TOKEN_EXPIRES_LABEL"
			readonly="true"
			parentclass="stack span-2-inline"
		/>

		<field
			name="created"
			type="text"
			label="PLG_SYSTEM_MAUTIC_TOKEN_CREATED_LABEL"
			readonly="true"
			parentclass="stack span-2-inline"
		/>

	</fieldset>
</form>
XML;

        $this->formsource = $xml;

        return true;
    }

    /**
     * Method to get the field input markup.
     *
     * @return  string  The field input markup.
     *
     * @since   2.0.0
     */
    protected function getInput()
    {

        $form      = $this->form;
        $formData  = $form->getData()->toObject();
        $text      = (!empty($this->value->access_token)) ? 'PLG_SYSTEM_MAUTIC_TOKEN_REAUTHORIZE_ACTION' : 'PLG_SYSTEM_MAUTIC_TOKEN_ACTION';

        if ($formData && !empty($formData->params->public_key) && !empty($formData->params->secret_key)) {
            /** @var Joomla\CMS\WebAsset\WebAssetManager $wa */
            $wa = Factory::getApplication()->getDocument()->getWebAssetManager();
            $wa->registerAndUseScript('plg_system_mautic.token', 'plg_system_mautic/sismos_token.js');

            $input = '<div class="d-flex my-3">';
            $btn   = '<button type="submit" id ="genToken" class="btn btn-success me-3">' . Text::_($text) . '</button>';

            if (!empty($this->value->access_token)) {
                $btn .= '<button type="submit" id ="clearToken" class="btn btn-danger">' . Text::_('PLG_SYSTEM_MAUTIC_TOKEN_CLEAR_ACTION') . '</button>';
            }

            $input .= $btn;
            $input .= '</div>';
            $input .= '<input type="hidden" value="" name="gentoken" />';
            $subform = parent::getInput();
            $input .= str_replace('class="subform-wrapper"', 'class="subform-wrapper form-grid"', $subform);

            return $input;
        }

        return '<div class="alert alert-info">' . Text::_('PLG_SYSTEM_MAUTIC_AUTH_MISSING_DATA_ERROR') . '</div>';
    }
}
