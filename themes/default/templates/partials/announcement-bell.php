<?php
/**
 * Top-bar bell for organization admins: Super Admin announcements.
 * Turns red with a count while there are unseen messages.
 *
 * @var array $authUser
 * @var string $lang
 */

use App\Infrastructure\Localization\LocaleFormatter;
use App\Infrastructure\Repositories\AdminAnnouncementRepository;

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\t;
use function App\Infrastructure\Theme\icon;

try {
    $bellRepo = new AdminAnnouncementRepository();
    $showBell = $bellRepo->isOrgAdmin((int) $authUser['id']);
    $bellMessages = $showBell ? $bellRepo->inbox((int) $authUser['id'], 10) : [];
    $bellUnread = $showBell ? $bellRepo->unreadCount((int) $authUser['id']) : 0;
} catch (\PDOException) {
    $showBell = false; // tables not migrated yet: never break the page
}

if ($showBell):
    $fmt = new LocaleFormatter($lang);
    $returnPath = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/');
    ?>
    <details class="dropdown announcement-bell<?= $bellUnread > 0 ? ' announcement-bell--unread' : '' ?>">
        <summary class="btn btn--ghost btn--icon" aria-label="<?= e(t('announcements.bell_label', ['count' => $bellUnread])) ?>" title="<?= e(t('announcements.inbox_title')) ?>">
            <?= icon('bell') ?>
            <?php if ($bellUnread > 0): ?>
                <span class="announcement-bell__count" aria-hidden="true"><?= e($fmt->digits((string) min($bellUnread, 99))) ?></span>
            <?php endif; ?>
        </summary>
        <div class="dropdown__panel announcement-bell__panel">
            <div class="dropdown__header"><strong><?= e(t('announcements.inbox_title')) ?></strong></div>
            <?php if ($bellMessages === []): ?>
                <p class="muted announcement-bell__empty"><?= e(t('announcements.inbox_empty')) ?></p>
            <?php endif; ?>
            <?php foreach ($bellMessages as $m): ?>
                <?php include __DIR__ . '/announcement-item.php'; ?>
            <?php endforeach; ?>
            <a href="/announcements" class="dropdown__item"><?= icon('inbox') ?><?= e(t('announcements.view_all')) ?></a>
        </div>
    </details>
<?php endif; ?>
