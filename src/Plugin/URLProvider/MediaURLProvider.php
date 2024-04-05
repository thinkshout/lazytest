<?php

namespace Drupal\lazytest\Plugin\URLProvider;

use Drupal\Core\Url;
use Drupal\lazytest\Plugin\URLProviderBase;
use Drupal\node\NodeStorageInterface;

/**
 * Provides a 'Media' URLProvider.
 *
 * @URLProvider(
 *   id = "media",
 *   label = @Translation("(media) 10 newest and 10 oldest published entities from each Media Type."),
 * )
 */
class MediaURLProvider extends URLProviderBase {

  public function getURLs() {
    return $this->loadEntitiesAndCreateURLs(
      'media_type',
      'media',
      [
        [
          'field' => 'bundle',
          'value' => '#entity_bundle'
        ],
        [
          'field' => 'status',
          'value' => 1
        ]
      ],
      'mid',
      ['DESC', 'ASC',],
      10,
      'media',
      '#entity_bundle'
    );
  }
}
