<?php

namespace Drupal\gone_extension\Controller;

use Drupal\system\Controller\Http4xxController;

/**
 * Class Http410Controller.
 *
 * HTTP Gone controller.
 *
 * @package Drupal\gone_extension
 */
class Http410Controller extends Http4xxController {

  /**
   * The default 410 content.
   *
   * @return array
   *   A render array containing the message to display for 410 pages.
   */
  public function on410() {
    return [
      '#markup' => $this->t('The requested page is gone.'),
    ];
  }
}