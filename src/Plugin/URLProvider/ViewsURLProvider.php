<?php

namespace Drupal\lazytest\Plugin\URLProvider;

use Drupal\Core\Url;
use Drupal\lazytest\Plugin\URLProviderBase;
use Drupal\node\NodeStorageInterface;

/**
 * Provides a 'Views' URLProvider.
 *
 * @URLProvider(
 *   id = "views",
 *   label = @Translation("(views) All published Views with a page display."),
 * )
 */
class ViewsURLProvider extends URLProviderBase {

  public function getURLs() {
    $urls = [];

    // Get the view entity storage.
    $view_storage = \Drupal::entityTypeManager()->getStorage('view');

    // Query for up to 10 view entities.
    $view_ids = $view_storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('status', 1)
      ->execute();

    // Load the view entities.
    $views = $view_storage->loadMultiple($view_ids);

    // Generate URLs for each view entity.
    foreach ($views as $view) {
      // Skip views that are part of Drupal core.
      $foo = $view->get('module');
      if ($view->get('module') !== 'views') {
        continue;
      }
      // We only want to generate URLs for views that have a page display.
      foreach ($view->get('display') as $display) {
        if ($display['display_plugin'] === 'page' && (!isset($display['display_options']['enabled']) || (isset($display['display_options']['enabled']) && $display['display_options']['enabled'] !== FALSE))) {
          // The 'path' of the page display will be used as the URL.
          try {
            $url_object = Url::fromRoute('view.' . $view->id() . '.' . $display['id']);
            $url_object->setAbsolute();
            $urls[] = [
              'source' => "views",
              'subsource' => $view->id(),
              'url' => $url_object->toString(),
            ];
          } catch (\Exception $e) {
            // If the route does not exist, ignore it.
          }
        }
      }
    }

    return $urls;
  }

}
