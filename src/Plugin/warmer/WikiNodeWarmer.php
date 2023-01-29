<?php

declare(strict_types=1);

namespace Drupal\omnipedia_warmer\Plugin\warmer;

use Drupal\Component\Plugin\Exception\PluginException;
use Drupal\Core\Entity\EntityViewBuilderInterface;
use Drupal\Core\Form\SubformStateInterface;
use Drupal\Core\Render\RenderContext;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Session\AccountSwitcherInterface;
use Drupal\Core\Utility\Error;
use Drupal\node\NodeStorageInterface;
use Drupal\omnipedia_core\Entity\Node;
use Drupal\omnipedia_core\Entity\NodeInterface;
use Drupal\omnipedia_user\Service\PermissionHashesInterface;
use Drupal\omnipedia_user\Service\RepresentativeRenderUserInterface;
use Drupal\omnipedia_warmer\Service\WarmerInfoInterface;
use Drupal\user\RoleStorageInterface;
use Drupal\user\UserInterface;
use Drupal\user\UserStorageInterface;
use Drupal\warmer\Plugin\WarmerPluginBase;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The Omnipedia wiki node cache warmer plug-in.
 *
 * @Warmer(
 *   id           = "omnipedia_wiki_node",
 *   label        = @Translation("Omnipedia: wiki pages"),
 *   description  = @Translation("Warms the wiki page render cache by pre-rendering wiki pages for each set of permission hashes.")
 * )
 *
 * @see \Drupal\warmer\Plugin\WarmerInterface
 *   Documentation for public methods.
 *
 * @see \Drupal\warmer_entity\Plugin\warmer\EntityWarmer
 *   Example/reference of a warmer plug-in shipped with the Warmer module.
 *
 * @see \Drupal\warmer_cdn\Plugin\warmer\CdnWarmer
 *   Example/reference of a warmer plug-in shipped with the Warmer module.
 */
class WikiNodeWarmer extends WarmerPluginBase {

  /**
   * An array of IDs to warm.
   *
   * @var array|null
   *
   * @see \Drupal\omnipedia_warmer\Service\WarmerInfoInterface::getIdsToWarm()
   *   Describes format.
   */
  protected ?array $idsToWarm = null;

  /**
   * The Drupal account switcher service.
   *
   * @var \Drupal\Core\Session\AccountSwitcherInterface
   */
  protected AccountSwitcherInterface $accountSwitcher;

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
   * The Drupal node entity view builder.
   *
   * @var \Drupal\Core\Entity\EntityViewBuilderInterface
   */
  protected EntityViewBuilderInterface $nodeViewBuilder;

  /**
   * The Omnipedia permission hashes service.
   *
   * @var \Drupal\omnipedia_user\Service\PermissionHashesInterface
   */
  protected PermissionHashesInterface $permissionHashes;

  /**
   * The Drupal renderer service.
   *
   * @var \Drupal\Core\Render\RendererInterface
   */
  protected RendererInterface $renderer;

  /**
   * The Omnipedia representative render user service.
   *
   * @var \Drupal\omnipedia_user\Service\RepresentativeRenderUserInterface
   */
  protected RepresentativeRenderUserInterface $representativeRenderUser;

  /**
   * The Drupal user role entity storage.
   *
   * @var \Drupal\user\RoleStorageInterface
   */
  protected RoleStorageInterface $roleStorage;

  /**
   * The Drupal user entity storage.
   *
   * @var \Drupal\user\UserStorageInterface
   */
  protected UserStorageInterface $userStorage;

  /**
   * The Omnipedia warmer info service.
   *
   * @var \Drupal\omnipedia_warmer\Service\WarmerInfoInterface
   */
  protected WarmerInfoInterface $warmerInfo;

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
      $container->get('logger.channel.omnipedia_warmer'),
      $container->get('entity_type.manager')->getStorage('node'),
      $container->get('entity_type.manager')->getViewBuilder('node'),
      $container->get('omnipedia_user.permission_hashes'),
      $container->get('renderer'),
      $container->get('omnipedia_user.representative_render_user'),
      $container->get('omnipedia.warmer_info'),
      $container->get('entity_type.manager')->getStorage('user_role'),
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
   * @param \Psr\Log\LoggerInterface $loggerChannel
   *   Our logger channel.
   *
   * @param \Drupal\node\NodeStorageInterface $nodeStorage
   *   The Drupal node entity storage.
   *
   * @param \Drupal\Core\Entity\EntityViewBuilderInterface $nodeViewBuilder
   *   The Drupal node entity view builder.
   *
   * @param \Drupal\omnipedia_user\Service\PermissionHashesInterface $permissionHashes
   *   The Omnipedia permission hashes service.
   *
   * @param \Drupal\Core\Render\RendererInterface $renderer
   *   The Drupal renderer service.
   *
   * @param \Drupal\omnipedia_user\Service\RepresentativeRenderUserInterface $representativeRenderUser
   *   The Omnipedia representative render user service.
   *
   * @param \Drupal\omnipedia_warmer\Service\WarmerInfoInterface $warmerInfo
   *   The Omnipedia warmer info service.
   *
   * @param \Drupal\user\RoleStorageInterface $roleStorage
   *   The Drupal user role entity storage.
   *
   * @param \Drupal\user\UserStorageInterface $userStorage
   *   The Drupal user entity storage.
   */
  public function setAddtionalDependencies(
    AccountSwitcherInterface          $accountSwitcher,
    LoggerInterface                   $loggerChannel,
    NodeStorageInterface              $nodeStorage,
    EntityViewBuilderInterface        $nodeViewBuilder,
    PermissionHashesInterface         $permissionHashes,
    RendererInterface                 $renderer,
    RepresentativeRenderUserInterface $representativeRenderUser,
    WarmerInfoInterface               $warmerInfo,
    RoleStorageInterface              $roleStorage,
    UserStorageInterface              $userStorage
  ): void {

    $this->accountSwitcher          = $accountSwitcher;
    $this->loggerChannel            = $loggerChannel;
    $this->nodeStorage              = $nodeStorage;
    $this->nodeViewBuilder          = $nodeViewBuilder;
    $this->renderer                 = $renderer;
    $this->representativeRenderUser = $representativeRenderUser;
    $this->roleStorage              = $roleStorage;
    $this->userStorage              = $userStorage;
    $this->warmerInfo               = $warmerInfo;

  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {

    /** @var array */
    $config = parent::defaultConfiguration();

    // Reduce the batch size as some wiki nodes can take a few seconds.
    $config['batchSize'] = 10;

    return $config;

  }

  /**
   * {@inheritdoc}
   */
  public function addMoreConfigurationFormElements(
    array $form, SubformStateInterface $formState
  ) {

    // We don't have any form elements to add.
    return $form;

  }

  /**
   * {@inheritdoc}
   */
  public function buildIdsBatch($cursor) {

    if (!\is_array($this->idsToWarm)) {
      $this->idsToWarm = $this->warmerInfo->getIdsToWarm();
    }

    /** @var int|false */
    $cursorPosition = \is_null($cursor) ? -1 :
      // Get the integer offset given the current cursor. Note that we have to
      // use \array_values($this->idsToWarm) to be able to + 1 increment the
      // offset in the \array_slice(), since that array uses string keys.
      \array_search($cursor, \array_values($this->idsToWarm));

    // If \array_search() returned false, bail returning an empty array.
    if ($cursorPosition === false) {
      return [];
    }

    return \array_slice(
      $this->idsToWarm, $cursorPosition + 1, (int) $this->getBatchSize()
    );

  }

  /**
   * {@inheritdoc}
   */
  public function loadMultiple(array $ids = []) {

    /** @var array */
    $items = [];

    foreach ($ids as $id => $info) {

      /** \Drupal\omnipedia_core\Entity\NodeInterface|null */
      $node = $this->nodeStorage->load($info['nid']);

      /** @var \Drupal\user\UserInterface|null */
      $renderUser = $this->representativeRenderUser->getUserToRenderAs(
        $info['roles'],
        function(UserInterface $user) use ($node) {
          return $node->access('view', $user);
        }
      );

      // Skip this item if we couldn't find a user to render it as.
      if (!\is_object($renderUser)) {
        continue;
      }

      $items[$id] = [
        'node'  => $node,
        'user'  => $renderUser,
      ];

    }

    return $items;

  }

  /**
   * {@inheritdoc}
   */
  public function warmMultiple(array $items = []) {

    /** @var integer */
    $count = 0;

    foreach ($items as $key => $item) {

      // Switch over to the provided user account for rendering.
      $this->accountSwitcher->switchTo($item['user']);

      // Attempt to build the changes render array, which will automatically
      // cache it if it isn't already cached, or will return it from cache.
      try {

        /** @var array */
        $renderArray = $this->nodeViewBuilder->view($item['node'], 'full');

        /** @var \Drupal\Core\Render\RenderContext */
        $renderContext = new RenderContext();

        // This renders the node, which should result in it being stored in the
        // render cache; because of that, we don't need to store anything
        // ourselves.
        $this->renderer->executeInRenderContext(
          $renderContext, function() use (&$renderArray) {
            return $this->renderer->render($renderArray);
          }
        );

      } catch (PluginException $exception) {

        // Log the exception.
        //
        // @see \watchdog_exception()
        //   We're replicating what this function does, but using the injected
        //   logger channel.
        $this->loggerChannel->error(
          '%type: @message in %function (line %line of %file).',
          Error::decodeException($exception)
        );

      }

      // Switch back to the current user.
      $this->accountSwitcher->switchBack();

      // Increment the counter if the render array isn't empty or null.
      if (!empty($renderArray)) {
        $count++;
      }

    }

    return $count;

  }

}
