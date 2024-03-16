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

    foreach ($vocabularies as $vocabulary) {
      $tids = \Drupal::entityTypeManager()->getStorage('taxonomy_term')->getQuery()
        ->accessCheck(FALSE)
        ->condition('status', 1)
        ->condition('vid', $vocabulary->id())
        ->range(0, 10)
        ->addTag('sort_by_random')
        ->execute();

      foreach ($tids as $tid) {
        $url_object = Url::fromRoute('entity.taxonomy_term.canonical', ['taxonomy_term' => $tid]);
        $url_object->setAbsolute();
        $urls[] = $url_object->toString();
      }
    }

    return $urls;
  }

}
