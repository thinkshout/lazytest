<?php

namespace Drupal\lazytest;

use Drupal\Core\Logger\RfcLogLevel;
use Drupal\Core\Url;
use Drupal\lazytest\Plugin\URLProviderManager;
use GuzzleHttp\Pool;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use Symfony\Component\Console\Output\ConsoleOutput;

class LazyTestService {

  protected $urlProviderManager;

  public function __construct(URLProviderManager $urlProviderManager) {
    $this->urlProviderManager = $urlProviderManager;
  }

  public function getAllURLs() {
    $output = new ConsoleOutput();
    $output->writeln("Creating list of urls");
    $allURLs = [];
    $definitions = $this->urlProviderManager->getDefinitions();
    foreach ($definitions as $definition) {
//      if ($definition["id"] == 'menu_url_provider') {
        $instance = $this->urlProviderManager->createInstance($definition['id']);
        $allURLs = array_merge($allURLs, $instance->getURLs());
//      }
    }
    return $allURLs;
  }

  public function checkURLs($urls) {

    // We want to get fresh versions of pages.
    drupal_flush_all_caches();

    $output = new ConsoleOutput();

    $messages = [];

    $output->writeln("checking " . count($urls) . " urls");

    // Override url for debugging.
//    $urls = [];
//    $urls[] = "";

    $client = new Client([
      'base_uri' => 'http://web.lvhn.localhost/',
      'verify' => false, // Ignore SSL certificate errors
      'defaults' => [
        'headers' => [
          'Connection' => 'keep-alive',
        ],
      ],
    ]);
    $jar = new CookieJar;

    // Create a one-time login link for user 1
    $user = \Drupal\user\Entity\User::load(1);
    $timestamp = \Drupal::time()->getRequestTime();
    $link = Url::fromRoute(
      'user.reset.login',
      [
        'uid' => $user->id(),
        'timestamp' => $timestamp,
        'hash' => user_pass_rehash($user, $timestamp),
      ],
    )->toString();

    // Use the one-time login link and get the cookies.
    $client->request('GET', $link, ['cookies' => $jar]);
    $cookies = $jar->toArray();

    // Extract the session cookie name and value
    $session_cookie = '';
    foreach ($cookies as $cookie) {
      if (strpos($cookie['Name'], 'SESS') === 0) {
        $session_cookie = $cookie['Name'] . '=' . $cookie['Value'];
        break;
      }
    }

    $startTimestamp = time();

    $promises = function () use ($urls, $client, $session_cookie) {
      foreach ($urls as $url) {
        yield function() use ($client, $url, $session_cookie) {
          return $client->requestAsync('HEAD', $url["url"], [
            'headers' => [
              'Cookie' => $session_cookie,
            ],
            'timeout' => 120,
            'allow_redirects' => [
              'max' => 20, // follow up to x redirects
              'strict' => false, // use strict RFC compliant redirects
              'referer' => true, // add a Referer header
              'protocols' => ['http', 'https'], // restrict redirects to 'http' and 'https'
            ],
          ]);
        };
      }
    };

    $pool = new Pool($client, $promises(), [
      'concurrency' => 8,
      'fulfilled' => function ($response, $index) use ($output, $urls, $startTimestamp, &$messages) {
        $code = $response->getStatusCode();
        // Not sure why this is sometimes unknown since we always start with a url.
        // Seems to only happen with successful items.
        $url = $urls[$index]["url"] ?? 'unknown';
        $source = $urls[$index]["source"] ?? 'unknown';
        $log_messages = $this->getLogMessages($url, $startTimestamp);
        if (!empty($log_messages) || $code >= 500) {
          $message = [
            'source' => $source,
            'code' => $code,
            'url' => $url,
            'message' => $log_messages,
          ];
          $messages[] = $message;
          $output->writeln($message);
        }
        else {
          // Success
          $output->writeln("$code;$url");
        }
      },
      'rejected' => function ($reason, $index) use ($output, $urls, $startTimestamp, &$messages) {
        $code = $reason->getCode();
        $url = (string) $reason->getRequest()->getUri();
        $source = $urls[$index]["source"] ?? 'unknown';
        $log_messages = $this->getLogMessages($url, $startTimestamp);
        $message = [
          'source' => $source,
          'code' => $code,
          'url' => $url,
          'message' => $log_messages,
        ];
        $messages[] = $message;
        $output->writeln($message);
      },
    ]);

    // Initiate the transfers and create a promise
    $promise = $pool->promise();

    // Force the pool of requests to complete.
    $promise->wait();

    // End the session when you're done
    \Drupal::service('session_manager')->destroy();

  }

  public function getLogMessages($url, $startTimestamp) {
    $query = \Drupal::database()->select('watchdog', 'w');
    $query->fields('w', ['message', 'variables', 'type', 'location']);
    $query->condition('w.timestamp', $startTimestamp, '>=');
    $query->condition('w.location', $url, '=');
    $query->condition('w.severity', RfcLogLevel::WARNING, '>=');
    $query->orderBy('w.wid', 'DESC');
    $result = $query->execute()->fetchAll();
    $log_messages = [];
    foreach ($result as $record) {
      $message = str_replace('@backtrace_string', '', $record->message);
      $message_compiled = $record->type . '-' . (string) t($message, unserialize($record->variables));
      $message_compiled = strip_tags($message_compiled);
      $log_messages[] = $message_compiled;
    }

    return implode("|", $log_messages);
  }

}
