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
        if (!empty($route->getOption('parameters'))) {
          // Skip if we need parameters.
          continue;
        }
        if (!empty($route->getMethods()) && array_search("GET", $route->getMethods()) === FALSE) {
          // Skip if there's no GET method.
          continue;
        }
        if (strpos($route->getPath(), "/admin/flush") === 0) {
          // Skip flush urls.
          continue;
        }
        if ($route->hasRequirement('_csrf_token')) {
          // Skip routes that need a csrf token since those are usually for actions.
          continue;
        }
        if (isset($route->getDefaults()['_controller'])) {
          // Skip items that have certain return types.
          $controllerString = $route->getDefaults()['_controller'];
          if (count(explode('::', $controllerString)) >= 2) {
            [$className, $methodName] = explode('::', $controllerString);
            $method = new \ReflectionMethod($className, $methodName);
            $returnType = $method->getReturnType();
            if ($returnType instanceof \ReflectionNamedType && !$returnType->isBuiltin()) {
              $returnTypeName = $returnType->getName();
            } else {
              $returnTypeName = (string) $returnType;
            }
            if (in_array($returnTypeName, [
              "Symfony\Component\HttpFoundation\BinaryFileResponse",
              "Symfony\Component\HttpFoundation\RedirectResponse",
            ])) {
              continue;
            }
          }
          else {
            if (strpos($controllerString, "::") === FALSE && strpos($controllerString, ":") !== FALSE) {
              continue;
            }
          }
        }
        // Exclude certain urls.
        if (in_array($route->getPath(), [
          '/contextual/render',
          '/devel/events',
          '/admin/config/development/configuration/full/export-download',
          '/media/delete',
        ])) {
          continue;
        }

        $url_object = Url::fromRoute($route_name);
        $url_object->setAbsolute();
        $urls[] = $url_object->toString();
      } catch (\Exception $e) {
        // Not all routes will be able to be converted into URLs,
        // so we'll catch any exceptions and skip those routes.
      }
    }

    return $urls;
  }
}
