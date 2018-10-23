<?php

namespace Drupal\gone_extension\EventSubscriber;

use Drupal\Core\Session\AccountInterface;
use Drupal\node\Entity\Node;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\FilterResponseEvent;
use Symfony\Component\HttpKernel\Exception\GoneHttpException;
use Symfony\Component\HttpKernel\KernelEvents;


/**
 * Class GoneExtensionResponseSubscriber.
 *
 * Subscribe drupal events.
 *
 * @package Drupal\gone_extension
 */
class GoneExtensionResponseSubscriber implements EventSubscriberInterface {

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $currentUser;

  /**
   * Constructs an ResponseSubscriber object.
   *
   * @param \Drupal\Core\Session\AccountInterface $current_user
   *   The current user.
   */
  public function __construct(AccountInterface $current_user) {
    $this->currentUser = $current_user;
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    $events[KernelEvents::RESPONSE][] = ['alterResponse'];
    return $events;
  }

  /**
   * Redirect if node is unpublished.
   *
   * @param FilterResponseEvent $event
   *   The route building event.
   */
  public function alterResponse(FilterResponseEvent $event) {
    if ($this->currentUser->isAnonymous()) {
      /** @var \Symfony\Component\HttpFoundation\Request $request */
      $request = $event->getRequest();
      $node = $request->attributes->get('node');
      if ($node instanceof Node && !$node->isPublished()) {
        $response = new RedirectResponse('/system/410');
        $event->setResponse($response);
      }
    }
  }
}

