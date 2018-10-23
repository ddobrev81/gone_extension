<?php

namespace Drupal\gone_extension\EventSubscriber;

use Drupal\Core\Access\AccessManagerInterface;
use Drupal\Core\Cache\RefinableCacheableDependencyInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Routing\AccessAwareRouterInterface;
use Drupal\Core\Routing\RedirectDestinationInterface;
use Drupal\Core\Url;
use Drupal\Core\Utility\Error;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\GetResponseForExceptionEvent;
use Symfony\Component\HttpKernel\Exception\GoneHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Matcher\UrlMatcherInterface;

/**
 * Class GoneExtensionEventSubscriber.
 *
 * Subscribe to HTTP gone event.
 *
 * @package Drupal\gone_extension
 */
class GoneExtensionEventSubscriber implements EventSubscriberInterface {
  /**
   * The configuration factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The access manager.
   *
   * @var \Drupal\Core\Access\AccessManagerInterface
   */
  protected $accessManager;

  /**
   * The request stack.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  public $requestStack;

  /**
   * The HTTP kernel.
   *
   * @var \Symfony\Component\HttpKernel\HttpKernelInterface
   */
  protected $httpKernel;

  /**
   * The logger instance.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * The redirect destination service.
   *
   * @var \Drupal\Core\Routing\RedirectDestinationInterface
   */
  protected $redirectDestination;

  /**
   * A router implementation which does not check access.
   *
   * @var \Symfony\Component\Routing\Matcher\UrlMatcherInterface
   */
  protected $accessUnawareRouter;

  /**
   * Constructs a new CustomPageExceptionHtmlSubscriber.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The configuration factory.
   * @param \Symfony\Component\HttpKernel\HttpKernelInterface $http_kernel
   * @param \Psr\Log\LoggerInterface $logger
   * @param \Drupal\Core\Routing\RedirectDestinationInterface $redirect_destination
   * @param \Drupal\Core\Access\AccessManagerInterface $access_manager
   *   The access manager.
   * @param \Symfony\Component\HttpFoundation\RequestStack $request_stack
   */
  public function __construct(ConfigFactoryInterface $config_factory, HttpKernelInterface $http_kernel, LoggerInterface $logger, RedirectDestinationInterface $redirect_destination, UrlMatcherInterface $access_unaware_router, AccessManagerInterface $access_manager, RequestStack $request_stack) {
    $this->configFactory = $config_factory;
    $this->accessManager = $access_manager;
    $this->requestStack = $request_stack;
    $this->httpKernel = $http_kernel;
    $this->logger = $logger;
    $this->redirectDestination = $redirect_destination;
    $this->accessUnawareRouter = $access_unaware_router;
  }

  /**
   * Registers the methods in this class that should be listeners.
   *
   * @return array
   *   An array of event listener definitions.
   */
  public static function getSubscribedEvents() {
    $events[KernelEvents::EXCEPTION][] = ['onGoneException', 110];
    return $events;
  }

  /**
   * Ensures Fast 410 output returned upon GoneHttpException.
   *
   * @param \Symfony\Component\HttpKernel\Event\GetResponseForExceptionEvent $event
   *   The response for exception event.
   */
  public function onGoneException(GetResponseForExceptionEvent $event) {
    // Check to see if we will completely replace the Drupal 410 page.
    if ($event->getException() instanceof GoneHttpException) {
      $custom_410_path = $this->configFactory->get('system.site')
        ->get('page.410');
      if (!empty($custom_410_path)) {
        $status_code = Response::HTTP_GONE;
        $url = Url::fromUserInput($custom_410_path);
        if ($url->isRouted()) {
          $access_result = $this->accessManager->checkNamedRoute($url->getRouteName(), $url->getRouteParameters(), NULL, TRUE);
          $request = $event->getRequest();
          // Merge the custom path's route's access result's cacheability metadata
          // with the existing one (from the master request), otherwise create it.
          if (!$request->attributes->has(AccessAwareRouterInterface::ACCESS_RESULT)) {
            $request->attributes->set(AccessAwareRouterInterface::ACCESS_RESULT, $access_result);
          }
          else {
            $existing_access_result = $request->attributes->get(AccessAwareRouterInterface::ACCESS_RESULT);
            if ($existing_access_result instanceof RefinableCacheableDependencyInterface) {
              $existing_access_result->addCacheableDependency($access_result);
            }
          }
          // Only perform the subrequest if the custom path is actually accessible.
          if (!$access_result->isAllowed()) {
            return;
          }
        }
        $request = $event->getRequest();
        $exception = $event->getException();

        try {
          // Reuse the exact same request (so keep the same URL, keep the access
          // result, the exception, et cetera) but override the routing information.
          // This means that aside from routing, this is identical to the master
          // request. This allows us to generate a response that is executed on
          // behalf of the master request, i.e. for the original URL. This is what
          // allows us to e.g. generate a 404 response for the original URL; if we
          // would execute a subrequest with the 404 route's URL, then it'd be
          // generated for *that* URL, not the *original* URL.
          $sub_request = clone $request;

          // The routing to the 404 page should be done as GET request because it is
          // restricted to GET and POST requests only. Otherwise a DELETE request
          // would for example trigger a method not allowed exception.
          $request_context = clone ($this->accessUnawareRouter->getContext());
          $request_context->setMethod('GET');
          $this->accessUnawareRouter->setContext($request_context);

          $sub_request->attributes->add($this->accessUnawareRouter->match($url));

          // Add to query (GET) or request (POST) parameters:
          // - 'destination' (to ensure e.g. the login form in a 403 response
          //   redirects to the original URL)
          // - '_exception_statuscode'
          $parameters = $sub_request->isMethod('GET') ? $sub_request->query : $sub_request->request;
          $parameters->add($this->redirectDestination->getAsArray() + ['_exception_statuscode' => $status_code]);

          $response = $this->httpKernel->handle($sub_request, HttpKernelInterface::SUB_REQUEST);
          // Only 2xx responses should have their status code overridden; any
          // other status code should be passed on: redirects (3xx), error (5xx)…
          // @see https://www.drupal.org/node/2603788#comment-10504916
          if ($response->isSuccessful()) {
            $response->setStatusCode($status_code);
          }

          // Persist any special HTTP headers that were set on the exception.
          if ($exception instanceof HttpExceptionInterface) {
            $response->headers->add($exception->getHeaders());
          }

          $event->setResponse($response);
        }
        catch (\Exception $e) {
          // If an error happened in the subrequest we can't do much else. Instead,
          // just log it. The DefaultExceptionSubscriber will catch the original
          // exception and handle it normally.
          $error = Error::decodeException($e);
          $this->logger->log($error['severity_level'], '%type: @message in %function (line %line of %file).', $error);
        }
      }
    }
  }
}