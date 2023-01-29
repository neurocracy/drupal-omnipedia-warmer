<?php

declare(strict_types=1);

namespace Drupal\omnipedia_warmer\Plugin\warmer;

use Drupal\Core\Form\SubformStateInterface;
use Drupal\Core\Session\AccountSwitcherInterface;
use Drupal\Core\Utility\Error;
use Drupal\node\NodeStorageInterface;
use Drupal\omnipedia_core\Entity\Node;
use Drupal\user\UserInterface;
use Drupal\user\UserStorageInterface;
use Drupal\warmer\Plugin\WarmerPluginBase;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Promise\Utils;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The Omnipedia wiki node (CDN) cache warmer plug-in.
 *
 * @Warmer(
 *   id           = "omnipedia_wiki_node_cdn",
 *   label        = @Translation("Omnipedia: wiki pages (CDN)"),
 *   description  = @Translation("Executes HTTP requests to warm edge caches for anonymous users.")
 * )
 *
 * @todo Implement configurable HTTP headers?
 *
 * @see \Drupal\warmer\Plugin\WarmerInterface
 *   Documentation for public methods.
 *
 * @see \Drupal\warmer_cdn\Plugin\warmer\CdnWarmer
 *   Parts adapted from this plug-in shipped with the Warmer module.
 *
 * @see \Drupal\warmer_cdn\Plugin\warmer\UserInputParserTrait
 *   Can be used to parse and validate headers if/when we implement that.
 */
class WikiNodeCdnWarmer extends WarmerPluginBase {

  /**
   * An array of node IDs (nids) to warm, keyed by their revision IDs.
   *
   * @var array|null
   *   If this is null, that indicates that $this->getIdsToWarm() has not yet
   *   run. Once that runs once, this will be an array, either empty or
   *   populated with the results of the entity query.
   *
   * @see \Drupal\Core\Entity\Query\QueryInterface::execute()
   *   Uses the format returned by the entity query.
   */
  protected ?array $idsToWarm = null;

  /**
   * The Drupal account switcher service.
   *
   * @var \Drupal\Core\Session\AccountSwitcherInterface
   */
  protected AccountSwitcherInterface $accountSwitcher;

  /**
   * The Guzzle HTTP client.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected ClientInterface $httpClient;

  /**
   * Our logger channel.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected LoggerInterface $loggerChannel;

  /**
   * The Drupal node entity storage.
   *
   * @var \Drupal\node\NodeStorageInterface
   */
  protected NodeStorageInterface $nodeStorage;

  /**
   * The Drupal user entity storage.
   *
   * @var \Drupal\user\UserStorageInterface
   */
  protected UserStorageInterface $userStorage;

  /**
   * {@inheritdoc}
   */
  public static function create(
    ContainerInterface $container,
    array $configuration, $pluginId, $pluginDefinition
  ) {

    /** @var \Drupal\warmer\Plugin\WarmerInterface */
    $instance = parent::create(
      $container, $configuration, $pluginId, $pluginDefinition
    );

    $instance->setAddtionalDependencies(
      $container->get('account_switcher'),
      $container->get('http_client'),
      $container->get('logger.channel.omnipedia_warmer'),
      $container->get('entity_type.manager')->getStorage('node'),
      $container->get('entity_type.manager')->getStorage('user')
    );

    return $instance;

  }

  /**
   * Set additional dependencies.
   *
   * @param \Drupal\Core\Session\AccountSwitcherInterface $accountSwitcher
   *   The Drupal account switcher service.
   *
   * @param \GuzzleHttp\ClientInterface $httpClient
   *   The Guzzle HTTP client.
   *
   * @param \Psr\Log\LoggerInterface $loggerChannel
   *   Our logger channel.
   *
   * @param \Drupal\node\NodeStorageInterface $nodeStorage
   *   The Drupal node entity storage.
   *
   * @param \Drupal\user\UserStorageInterface $userStorage
   *   The Drupal user entity storage.
   */
  public function setAddtionalDependencies(
    AccountSwitcherInterface  $accountSwitcher,
    ClientInterface           $httpClient,
    LoggerInterface           $loggerChannel,
    NodeStorageInterface      $nodeStorage,
    UserStorageInterface      $userStorage
  ): void {

    $this->accountSwitcher  = $accountSwitcher;
    $this->httpClient       = $httpClient;
    $this->loggerChannel    = $loggerChannel;
    $this->nodeStorage      = $nodeStorage;
    $this->userStorage      = $userStorage;

  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {

    /** @var array */
    $config = parent::defaultConfiguration();

    // Reduce the batch size as some wiki nodes can take a few seconds.
    $config['batchSize'] = 5;

    $config['max_concurrent_requests'] = 10;

    return $config;

  }

  /**
   * {@inheritdoc}
   */
  public function addMoreConfigurationFormElements(
    array $form, SubformStateInterface $formState
  ) {

    /** @var array */
    $config = $this->getConfiguration();

    $form['max_concurrent_requests'] = [
      '#type'           => 'number',
      '#min'            => 1,
      '#step'           => 1,
      '#title'          => $this->t('Maximum number of concurrent HTTP requests'),
      '#description'    => $this->t(
        'The maximum number of concurrent requests to send in parallel. Setting this value too high may result denial-of-service protections being triggered at the host or reverse proxy level so care is advised.'
      ),
      '#default_value'  => $config['max_concurrent_requests'],
    ];

    $form['verify'] = [
      '#type'           => 'checkbox',
      '#title'          => $this->t('Enable HTTPS verification'),
      '#description'    => $this->t(
        'Enable HTTPS verification. It\'s recommended to keep this checked for security reasons and only intended for local testing with self-signed certificates.'
      ),
      '#default_value'  => $config['verify'] ?? true,
    ];

    return $form;

  }

  /**
   * Get the IDs to warm, building the array if not already built.
   *
   * @return array
   *
   * @see $this->idsToWarm
   */
  protected function getIdsToWarm(): array {

    if (\is_array($this->idsToWarm)) {
      return $this->idsToWarm;
    }

    /** @var \Drupal\user\UserInterface The anonymous user entity. */
    $anonymousUser = $this->userStorage->load(0);

    // Switch to the anonymous user so that the node entity query only returns
    // nodes anonymous users have access to.
    $this->accountSwitcher->switchTo($anonymousUser);

    // This builds and executes a \Drupal\Core\Entity\Query\QueryInterface to
    // get all available wiki node IDs (nids).
    /** @var array */
    $queryResult = ($this->nodeStorage->getQuery())
      ->condition('type', Node::getWikiNodeType())
      // This will limit results to only nodes that the user has access to.
      ->accessCheck(true)
      ->execute();

    // Switch back to the previous user, if any.
    $this->accountSwitcher->switchBack();

    $this->idsToWarm = $queryResult;

    return $this->idsToWarm;

  }

  /**
   * {@inheritdoc}
   */
  public function buildIdsBatch($cursor) {

    $ids = $this->getIdsToWarm();

    /** @var int|false */
    $cursorPosition = \is_null($cursor) ? -1 :
      // Get the integer offset given the current cursor. Note that we have to
      // use \array_values($ids) to be able to + 1 increment the offset in
      // the \array_slice(), since that array uses revision IDs as keys.
      \array_search($cursor, \array_values($ids));

    // If \array_search() returned false, bail returning an empty array.
    if ($cursorPosition === false) {
      return [];
    }

    return \array_slice($ids, $cursorPosition + 1, (int) $this->getBatchSize());

  }

  /**
   * {@inheritdoc}
   */
  public function loadMultiple(array $ids = []) {

    /** @var array */
    $items = [];

    foreach ($ids as $revisionId => $nid) {

      /** \Drupal\omnipedia_core\Entity\NodeInterface|null */
      $node = $this->nodeStorage->load($nid);

      if (!\is_object($node)) {
        continue;
      }

      $items[$nid] = $node;

    }

    return $items;

  }

  /**
   * {@inheritdoc}
   */
  public function warmMultiple(array $items = []) {

    $maxConcurrentRequests = (int) $this->getConfiguration()[
      'max_concurrent_requests'
    ];

    // Default to one request at a time.
    if ($maxConcurrentRequests <= 0) {
      $maxConcurrentRequests = 1;
    }

    // Not yet configurable.
    //
    // @see \Drupal\warmer_cdn\Plugin\warmer\UserInputParserTrait
    $headers = [];

    $verify = (bool) $this->getConfiguration()['verify'];

    /** @var \GuzzleHttp\Promise\PromiseInterface[] */
    $promises = [];

    /** @var integer The number of requests that were successfully sent and did not return an HTTP error code. */
    $count = 0;

    foreach ($items as $node) {

      // Fire off an async request to the node's canonical URL.
      $promises[] = $this->httpClient->requestAsync('GET',
        (string) $node->toUrl('canonical', ['absolute' => true])->toString(),
        ['headers' => $headers, 'verify' => $verify]

      )->then(function (ResponseInterface $response) use (&$count) {
          if ($response->getStatusCode() < 399) {
            $count++;
          }
        }, function (\Exception $exception) {

          // Log the exception.
          //
          // @see \watchdog_exception()
          //   We're replicating what this function does, but using the injected
          //   logger channel.
          $this->loggerChannel->error(
            '%type: @message in %function (line %line of %file).',
            Error::decodeException($exception)
          );

        });

      // Wait for all requests if max number is reached.
      if (count($promises) >= $maxConcurrentRequests) {

        Utils::all($promises)->wait();

        $promises = [];

      }

    }

    // Wait for remaining requests to complete, if any.
    if (!empty($promises)) {
      Utils::all($promises)->wait();
    }

    return $count;

  }

}
