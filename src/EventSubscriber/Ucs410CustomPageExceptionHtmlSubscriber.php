<?php

namespace Drupal\ucs_410_handle\EventSubscriber;

use Drupal\Core\EventSubscriber\CustomPageExceptionHtmlSubscriber;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\GetResponseForExceptionEvent;

/**
 * Exception subscriber for handling core custom HTML error pages.
 */
class Ucs410CustomPageExceptionHtmlSubscriber extends CustomPageExceptionHtmlSubscriber {

  /**
   * {@inheritdoc}
   */
  protected static function getPriority() {
    return 50;
  }

  /**
   * {@inheritdoc}
   */
  public function on403(GetResponseForExceptionEvent $event) {
    // Check if we hit our criteria for 410 error code return.
    if ($node = \Drupal::routeMatch()->getParameter('node')) {
      if ($node->getType() == 'employee' && !$node->isPublished()) {
        $config = \Drupal::service('config.factory')
          ->getEditable('ucs_410_handle.site');
        $custom_410_path = $config->get('410');
        if (!empty($custom_410_path)) {
          $this->makeSubrequestToCustomPath($event, $custom_410_path, Response::HTTP_GONE);
        }
      }
    }
    else {
      $custom_403_path = $this->configFactory->get('system.site')
        ->get('page.410');
      if (!empty($custom_403_path)) {
        $this->makeSubrequestToCustomPath($event, $custom_403_path, Response::HTTP_FORBIDDEN);
      }
    }


  }

}
