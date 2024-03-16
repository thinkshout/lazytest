<?php

namespace Drupal\lazytest\Plugin\URLProvider;

use Drupal\Core\Url;
use Drupal\lazytest\Plugin\URLProviderBase;
use Drupal\node\NodeStorageInterface;

/**
 * Provides a 'Route' URLProvider.
 *
 * @URLProvider(
 *   id = "route_url_provider",
 *   label = @Translation("Route"),
 * )
 */
class RouteURLProvider extends URLProviderBase {

  public function getURLs() {
    $urls = [];
    $routes = \Drupal::service('router.route_provider')->getAllRoutes();

    foreach ($routes as $route_name => $route) {
      try {
        $url_object = Url::fromRoute($route_name);
        $url_object->setAbsolute();
        $urls[] = $url_object->toString();
      } catch (\Exception $e) {
        // Not all routes will be able to be converted into URLs,
        // so we'll catch any exceptions and skip those routes.
        // @todo find some ways to add parameter values.
      }
    }

    return $urls;
  }
}
