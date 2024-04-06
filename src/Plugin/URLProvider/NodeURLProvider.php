<?php

namespace Drupal\lazytest\Plugin\URLProvider;

use Drupal\lazytest\Plugin\URLProviderBase;

/**
 * Provides a 'Node' URLProvider.
 *
 * @URLProvider(
 *   id = "node",
 *   label = @Translation("(node) 10 newest and 10 oldest published nodes from each Content Type."),
 * )
 */
class NodeURLProvider extends URLProviderBase {

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
