<?php

namespace Drupal\lazytest;

use Drupal\Core\Url;
use Drupal\lazytest\Plugin\URLProviderManager;
use Drupal\Core\Logger\RfcLogLevel;
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
    $allURLs = [];
    $definitions = $this->urlProviderManager->getDefinitions();
    foreach ($definitions as $definition) {
      if ($definition["id"] == 'content_type_url_provider') {
        $instance = $this->urlProviderManager->createInstance($definition['id']);
        $allURLs = array_merge($allURLs, $instance->getURLs());
      }
    }
    return $allURLs;
  }

  public function checkURLs($urls) {

    drupal_flush_all_caches();

    $output = new ConsoleOutput();

    // override url for debugging.
//    $urls = [];
//    $urls[] = "";

    $client = new Client([
      'base_uri' => 'http://web.lvhn.localhost/',
      'verify' => false, // Ignore SSL certificate errors
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
          return $client->getAsync($url, [
            'headers' => [
              'Cookie' => $session_cookie,
            ],
            'timeout' => 600, // 10 minute timeout.
            'allow_redirects' => [
              'max' => 20, // follow up to 10 redirects
              'strict' => false, // use strict RFC compliant redirects
              'referer' => true, // add a Referer header
              'protocols' => ['http', 'https'], // restrict redirects to 'http' and 'https'
            ],
          ]);
        };
      }
    };

    $pool = new Pool($client, $promises(), [
      'concurrency' => 100,
      'fulfilled' => function ($response, $index) use ($output, $urls, $startTimestamp) {
        $code = $response->getStatusCode();
        $url = $urls[$index];
        $log_messages = $this->getLogMessages($url, $startTimestamp);
        if (!empty($log_messages) || $code >= 500) {
          $output->writeln("$code;$url;$log_messages");
        }
        else {
          // Success
          $output->writeln("$code;$url");
        }
      },
      'rejected' => function ($reason) use ($output, $startTimestamp) {
        $code = $reason->getCode();
        $url = (string) $reason->getRequest()->getUri();
        $log_messages = $this->getLogMessages($url, $startTimestamp);
        $output->writeln("$code;$url;$log_messages");
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
    $query->fields('w', ['message', 'variables', 'severity', 'type', 'timestamp']);
    $query->condition('w.timestamp', $startTimestamp, '>=');
    $query->condition('w.location', $url, '=');
    $query->orderBy('w.wid', 'DESC');
    $result = $query->execute()->fetchAll();

    $log_messages = [];
    foreach ($result as $record) {
//      if ($record->severity <= RfcLogLevel::WARNING) {
        $log_messages[] = (string) t($record->message, unserialize($record->variables));
//      }
    }

    return implode("|", $log_messages);
  }

}
