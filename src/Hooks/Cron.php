<?php

declare(strict_types=1);

namespace Drupal\omnipedia_warmer\Hooks;

use Drupal\hux\Attribute\Hook;
use Drupal\hux\Attribute\ReplaceOriginalHook;

/**
 * Cron hook implementations.
 */
class Cron {

  #[ReplaceOriginalHook(hook: 'cron', moduleName: 'warmer')]
  #[Hook('cron')]
  /**
   * Empty cron hook to prevent the Warmer module re-enqueuing.
   *
   * We run a dedicated background task on Omnipedia that handles running
   * Warmer queues, but since we also run cron in parallel, we can end up with
   * an ever growing queue when it inevitably takes longer than the time
   * set for the Warmer plug-ins. Rather than increasing the Warmer plug-in
   * re-enqueue time an arbitrary amount, we just disable cron processing
   * altogether.
   *
   * @see https://www.drupal.org/project/warmer/issues/3273547
   *   Open issue to add the ability to disable cron re-enqueuing.
   *
   * @see https://www.drupal.org/project/hux/issues/3440302
   *   At the time of writing, Hux has a bug where it won't discover
   *   #[ReplaceOriginalHook()] if the class doesn't also use #[Hook()] and/or
   *   #[Alter()].
   *
   * @see \warmer_cron()
   *
   * @todo Contribute to the above issue and remove this hook replacement.
   */
  public function warmerCron(): void {
  }

}
