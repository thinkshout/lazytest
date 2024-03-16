<?php

namespace Drupal\lazytest\Plugin\URLProvider;

use Drupal\Core\Url;
use Drupal\lazytest\Plugin\URLProviderBase;
use Drupal\node\NodeStorageInterface;

/**
 * Provides a 'Views' URLProvider.
 *
 * @URLProvider(
 *   id = "views_url_provider",
 *   label = @Translation("Views"),
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
      ->range(0, 10)
      ->execute();

    // Load the view entities.
    $views = $view_storage->loadMultiple($view_ids);

    // Generate URLs for each view entity.
    foreach ($views as $view) {
      // We only want to generate URLs for views that have a page display.
      foreach ($view->get('display') as $display) {
        if ($display['display_plugin'] === 'page') {
          // The 'path' of the page display will be used as the URL.
          try {
            $url_object = Url::fromRoute('view.' . $view->id() . '.' . $display['id']);
            $url_object->setAbsolute();
            $urls[] = $url_object->toString();
          } catch (\Exception $e) {
            // If the route does not exist, ignore it.
          }
        }
      }
    }

    return $urls;
  }

}
