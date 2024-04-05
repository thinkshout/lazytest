<?php

namespace Drupal\lazytest\Plugin\URLProvider;

use Drupal\Core\Url;
use Drupal\lazytest\Plugin\URLProviderBase;

/**
 * Provides a 'Content Type' URLProvider.
 *
 * @URLProvider(
 *   id = "content_type",
 *   label = @Translation("(content_type) 10 newest and 10 oldest published nodes from each Content Type."),
 * )
 */
class ContentTypeURLProvider extends URLProviderBase {

  /**
   * {@inheritdoc}
   */
  public function getURLs() {
    return $this->loadEntitiesAndCreateURLs(
      'node_type',
      'node',
      [
        [
          'field' => 'type',
          'value' => '#entity_bundle'
        ],
        [
          'field' => 'status',
          'value' => 1
        ]
      ],
      'nid',
      ['DESC', 'ASC',],
      10,
      'node',
      '#entity_bundle'
    );
  }
}
