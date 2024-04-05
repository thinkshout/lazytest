<?php

namespace Drupal\lazytest\Plugin\URLProvider;

use Drupal\Core\Url;
use Drupal\lazytest\Plugin\URLProviderBase;

/**
 * Provides a 'Taxonomy' URLProvider.
 *
 * @URLProvider(
 *   id = "taxonomy",
 *   label = @Translation("(taxonomy) 10 newest and 10 oldest published terms from each Vocabulary."),
 * )
 */
class TaxonomyURLProvider extends URLProviderBase {

  protected $termStorage;

  public function getURLs() {
    return $this->loadEntitiesAndCreateURLs(
      'taxonomy_vocabulary',
      'taxonomy_term',
      [
        [
          'field' => 'vid',
          'value' => '#entity_bundle'
        ],
        [
          'field' => 'status',
          'value' => 1
        ]
      ],
      'tid',
      ['DESC', 'ASC',],
      10,
      'taxonomy',
      '#entity_bundle'
    );
  }
}
