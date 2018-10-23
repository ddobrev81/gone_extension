<?php

namespace Drupal\gone_extension\Form;

use Drupal\Core\Form\FormStateInterface;
use Drupal\system\Form\SiteInformationForm;

/**
 * Class GoneExtensionSiteInformationForm.
 *
 * Extend the default system information form.
 *
 * @package Drupal\gone_extension
 */
class GoneExtensionSiteInformationForm extends SiteInformationForm {

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildForm($form, $form_state);

    $site_config = $this->config('system.site');

    $form['error_page']['site_410'] = [
      '#type' => 'textfield',
      '#title' => t('Default 410 (gone) page'),
      '#default_value' => $site_config->get('page.410'),
      '#size' => 40,
      '#description' => t('This page is displayed when the requested document is gone for the current user. Leave blank to display a generic "gone" page.'),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    // Get the normal paths of both error pages.
    if (!$form_state->isValueEmpty('site_410')) {
      $form_state->setValueForElement($form['error_page']['site_410'], $this->aliasManager->getPathByAlias($form_state->getValue('site_410')));
    }
    if (($value = $form_state->getValue('site_410')) && $value[0] !== '/') {
      $form_state->setErrorByName('site_410', $this->t("The path '%path' has to start with a slash.", ['%path' => $form_state->getValue('site_410')]));
    }
    // Validate 410 error path.
    if (!$form_state->isValueEmpty('site_410') && !$this->pathValidator->isValid($form_state->getValue('site_410'))) {
      $form_state->setErrorByName('site_410', $this->t("The path '%path' is either invalid or you do not have access to it.", ['%path' => $form_state->getValue('site_410')]));
    }

    parent::validateForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->configFactory->getEditable('gone_extension.site')
      ->set('410', $form_state->getValue('site_410'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}