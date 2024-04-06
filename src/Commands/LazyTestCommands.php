<?php

namespace Drupal\lazytest\Commands;

use Drupal\lazytest\LazyTestService;
use Drush\Commands\DrushCommands;
use Drupal\lazytest\Plugin\URLProviderManager;

class LazyTestCommands extends DrushCommands {

  protected $lazyTestService;
  protected $urlProviderManager;

  public function __construct(LazyTestService $lazyTestService, URLProviderManager $urlProviderManager) {
    $this->lazyTestService = $lazyTestService;
    $this->urlProviderManager = $urlProviderManager;
  }

  /**
   * @command lazytest:plugins
   * @aliases ltp
   */
  public function plugins() {
    $definitions = $this->urlProviderManager->getDefinitions();
    $ids = [];
    foreach ($definitions as $definition) {
      $ids[] = $definition['id'];
    }
    $ids_string = implode(', ', $ids);
    $this->output()->writeln($ids_string);
  }

  /**
   * @command lazytest:run
   * @aliases ltr
   * @option baseurl A base url to override the base url from Drupal.
   * @option urls A comma-separated list of URLs to check.
   * @option plugins A comma-separated list of URL Providers to use.
   * @option crawl Enable crawl mode.
   * @option crawldepth Set crawl depth.
   */
  public function run($options = ['baseurl' => NULL, 'urls' => NULL, 'plugins' => NULL, 'crawl' => FALSE, 'crawldepth' => 0]) {

    // Check if all options are NULL or not set
    if ($options['baseurl'] === NULL && $options['urls'] === NULL && $options['plugins'] === NULL && $options['crawl'] === FALSE && $options['crawldepth'] === 0) {

      $this->output()->writeln("\nUse 'drush lazytest:run' with any option to skip these choices and start the tests directly.");
      $this->output()->writeln("Options start with '--' followed by the options listed below in parentheses.");
      $this->output()->writeln("Example: drush lazytest:run --baseurl=https://www.drupal.org --urls=/,/about --crawl=1 --plugins=file,route");

      // Start asking the user for input
      // @todo: set baseurl to NULL.
      $options['baseurl'] = $this->io()->ask('(baseurl) override the base URL in case Drupal doesn\'t return the right one', NULL);
      $options['urls'] = $this->io()->ask('(urls) a comma-separated list of URLs to test', NULL);
      $options['crawl'] = $this->io()->confirm('(crawl) follow internal links to test additional pages', false);
      if ($options['crawl']) {
        $options['crawldepth'] = $this->io()->ask('(crawldepth) depth to follow internal links to', 1);
      }
      $availablePlugins = $this->urlProviderManager->getDefinitions();
      $pluginChoices = [];
      $pluginChoices["none"] = "None";
      $pluginChoices["all"] = "All";
      foreach ($availablePlugins as $plugin) {
        $pluginChoices[$plugin['id']] = $plugin['label']->render();
      }
      $options['plugins'] = $this->io()->choice('(plugins) Choose a plugin or when specifying options, a comma separated list.', $pluginChoices, 0);

    }

    $baseurl = $options["baseurl"];

    $urls = $this->lazyTestService->getAllURLs($options["urls"], $options["plugins"]);

    // Replace base url if provided
    if (!empty($baseurl)) {
      foreach ($urls as &$url) {
        if (empty($url)) {
          continue;
        }
        $parsedUrl = parse_url($url['url']);
        $parsedUrl['scheme'] = parse_url($baseurl, PHP_URL_SCHEME);
        $parsedUrl['host'] = parse_url($baseurl, PHP_URL_HOST);
        $url['url'] = $parsedUrl['scheme'] . '://' . $parsedUrl['host'] . ($parsedUrl['path'] ?? '');
      }
      unset($url);
    }
    else {
      $parsedUrl = parse_url(reset($urls)['url']);
      $baseurl = $parsedUrl['scheme'] . '://' . $parsedUrl['host'];
    }

    // Normalize all URLs before processing.
    foreach ($urls as &$url) {
      if (empty($url)) {
        continue;
      }
      $url['url'] = $this->lazyTestService->normalizeUrl($url['url']);
    }
    unset($url);

    // Filter out empty urls.
    $urls = array_filter($urls, function($item) {
      return !empty($item['url']);
    });

    // Filter out non-unique urls.
      array_multisort(array_column($urls, 'url'), SORT_ASC, $urls);
    $urls = array_intersect_key(
      $urls,
      array_unique(array_column($urls, 'url'))
    );

    // Filter out external urls.
    $current_scheme_and_host = \Drupal::request()->getSchemeAndHttpHost();
    $urls = array_filter($urls, function($item) use ($current_scheme_and_host, $baseurl) {
      $itemHost = parse_url($item['url'], PHP_URL_SCHEME) . '://' . parse_url($item['url'], PHP_URL_HOST);
      return $itemHost === $current_scheme_and_host || $itemHost === $baseurl;
    });

    // Filter out specific urls like logout.
    $urls = array_filter($urls, function($item) {
      return strpos($item['url'], '/user/logout') === false;
    });

    $this->lazyTestService->checkURLs($baseurl, $urls, $options["crawl"], $options["crawldepth"]);
  }

}
