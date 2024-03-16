<?php

namespace Drupal\lazytest\Plugin\URLProvider;

use Drupal\Core\Url;
use Drupal\lazytest\Plugin\URLProviderBase;

/**
 * Provides a 'Taxonomy' URLProvider.
 *
 * @URLProvider(
 *   id = "taxonomy_url_provider",
 *   label = @Translation("Taxonomy"),
 * )
 */
class TaxonomyURLProvider extends URLProviderBase {

  protected $termStorage;

  public function getURLs() {
    $urls = [];
    $vocabularies = \Drupal::entityTypeManager()->getStorage('taxonomy_vocabulary')->loadMultiple();

    // Get oldest and newest.
    $sorts = [
      'DESC',
      'ASC',
    ];

    foreach ($vocabularies as $vocabulary) {

      foreach ($sorts as $sort) {

        $tids = \Drupal::entityTypeManager()->getStorage('taxonomy_term')->getQuery()
          ->accessCheck(FALSE)
          ->condition('status', 1)
          ->condition('vid', $vocabulary->id())
          ->sort('tid', $sort)
          ->range(0, 10)
          ->execute();

        foreach ($tids as $tid) {
          $url_object = Url::fromRoute('entity.taxonomy_term.canonical', ['taxonomy_term' => $tid]);
          $url_object->setAbsolute();
          $vocabularyId = $vocabulary->id();
          $urls[] = [
            'source' => "taxonomy-$vocabularyId",
            'url' => $url_object->toString(),
          ];
        }
      }

    }

    return $urls;
  }

}
