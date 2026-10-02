<?php
/** @var array $leads */
/** @var string $q */
/** @var string $status */

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\t;
use function App\Infrastructure\Theme\badge;
use function App\Infrastructure\Theme\icon;

$statuses = ['new', 'contacted', 'qualified', 'unqualified', 'converted'];
?>
<section class="card">
    <h1><?= e(t('leads.title')) ?></h1>

    <form method="get" action="/leads" class="inline-form-row">
        <input type="text" name="q" value="<?= e($q) ?>" placeholder="<?= e(t('leads.search_placeholder')) ?>">
        <select aria-label="<?= e(t('leads.status')) ?>" name="status">
            <option value=""><?= e(t('leads.all_statuses')) ?></option>
            <?php foreach ($statuses as $s): ?>
                <option value="<?= e($s) ?>" <?= $status === $s ? 'selected' : '' ?>><?= e(t('leads.status.' . $s)) ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn"><?= icon('search') ?><?= e(t('common.search')) ?></button>
        <a href="/leads/create" class="btn btn--primary"><?= icon('plus') ?><?= e(t('leads.new')) ?></a>
    </form>

    <div class="table-responsive">
    <table class="table">
        <thead><tr><th><?= e(t('common.name')) ?></th><th><?= e(t('common.company')) ?></th><th><?= e(t('leads.status')) ?></th><th><?= e(t('leads.score')) ?></th></tr></thead>
        <tbody>
        <?php foreach ($leads as $lead): ?>
            <tr>
                <td><a href="/leads/<?= e($lead['id']) ?>"><?= e(trim($lead['first_name'] . ' ' . ($lead['last_name'] ?? ''))) ?></a></td>
                <td><?= e($lead['company_name'] ?? '—') ?></td>
                <td><?= badge(t('leads.status.' . $lead['status']), $lead['status']) ?></td>
                <td><?= e($lead['score']) ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if ($leads === []): ?>
            <tr><td colspan="4" class="muted"><?= e(t('leads.empty')) ?></td></tr>
        <?php endif; ?>
        </tbody>
    </table>
    </div>
</section>
