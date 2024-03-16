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

    // Get oldest and newest.
    $sorts = [
      'DESC',
      'ASC',
    ];

    foreach ($nodeTypes as $nodeType) {

      foreach ($sorts as $sort) {
        $nids = \Drupal::entityTypeManager()->getStorage('node')->getQuery()
          ->accessCheck(FALSE)
          ->condition('status', 1)
          ->condition('type', $nodeType->id())
          ->sort('nid', $sort)
          ->range(0, 10)
          ->execute();

        foreach ($nids as $nid) {
          $url_object = Url::fromRoute('entity.node.canonical', ['node' => $nid]);
          $url_object->setAbsolute();
          $nodeTypeId = $nodeType->id();
          $urls[] = [
            'source' => "content-$nodeTypeId",
            'url' => $url_object->toString(),
          ];
        }
      }

    }

    return $urls;
  }
}
