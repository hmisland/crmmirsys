<?php
/**
 * "Created from contract" notice + hidden source fields on the proforma /
 * invoice form (docs/contract-finance-design.md). Included INSIDE the form.
 * $contractSource is re-derived on the server (ContractBillingService::
 * sourceNotice()); the hidden fields are re-checked again on submit.
 *
 * @var array{type?: string, id: int, label: string, url: ?string, no_contact: bool}|null $contractSource
 *      type 'installment' (Stage 2): the document is made from a contract installment
 *      type 'project' (Stage 3-C): the invoice is made from a project
 * @var callable $fieldError
 */

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\t;
use function App\Infrastructure\Theme\icon;
?>
<?php if ($fieldError('source')): ?>
    <div class="alert alert--error"><div class="alert__body"><?= e($fieldError('source')) ?></div></div>
<?php endif; ?>
<?php if (($contractSource ?? null) !== null): ?>
    <div class="alert alert--info"><div class="alert__body">
        <?= icon('file-text') ?> <?= e(t(match ($contractSource['type'] ?? 'contract') {
            'installment' => 'finance.installment.created_from',
            'project' => 'finance.project.created_from',
            default => 'finance.created_from_contract',
        })) ?>
        <?php if ($contractSource['url'] !== null): ?><a href="<?= e($contractSource['url']) ?>"><?= e($contractSource['label']) ?></a><?php else: ?><?= e($contractSource['label']) ?><?php endif; ?>
        <?php if ($contractSource['no_contact']): ?><div class="field-hint"><?= e(t('finance.contract_without_contact')) ?></div><?php endif; ?>
    </div></div>
    <input type="hidden" name="source_type" value="<?= e($contractSource['type'] ?? 'contract') ?>">
    <input type="hidden" name="source_id" value="<?= e($contractSource['id']) ?>">
<?php endif; ?>
