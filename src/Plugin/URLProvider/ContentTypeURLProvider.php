<?php

namespace Drupal\lazytest\Plugin\URLProvider;

use Drupal\Core\Url;
use Drupal\lazytest\Plugin\URLProviderBase;
use Drupal\node\NodeStorageInterface;

/**
 * Provides a 'Content Type' URLProvider.
 *
 * @URLProvider(
 *   id = "content_type_url_provider",
 *   label = @Translation("Content Type"),
 * )
 */
class ContentTypeURLProvider extends URLProviderBase {

  public function getURLs() {
    $urls = [];
    $nodeTypes = \Drupal::entityTypeManager()->getStorage('node_type')->loadMultiple();

    foreach ($nodeTypes as $nodeType) {
      $nids = \Drupal::entityTypeManager()->getStorage('node')->getQuery()
        ->accessCheck(FALSE)
        ->condition('status', 1)
        ->condition('type', $nodeType->id())
        ->range(0, 10)
        ->addTag('sort_by_random')
        ->execute();

      foreach ($nids as $nid) {
        $url_object = Url::fromRoute('entity.node.canonical', ['node' => $nid]);
        $url_object->setAbsolute();
        $urls[] = $url_object->toString();
      }
    }

    return $urls;
  }
}
