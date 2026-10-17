<?php
// Child process for FinanceHelpTest: core migration 0078 runs its own transaction, so its
// idempotency is checked with autocommit on crm_local. Re-running up() must insert nothing and
// must not overwrite an article the Super Admin edited (one title is changed for the check and
// restored in finally). Prints one JSON object.
$root = dirname(__DIR__, 3);
require $root . '/app/autoload.php';
require $root . '/tests/lite/Assert.php';
require $root . '/tests/lite/Support/LocalApp.php';

$a = Tests\Lite\Support\LocalApp::boot();
$a->rollBack();
$pdo = $a->pdo;
$col = fn (string $sql) => $pdo->query($sql)->fetchColumn();
$state = fn () => $col("SELECT CONCAT((SELECT COUNT(*) FROM help_articles), '/', (SELECT COUNT(*) FROM help_article_translations), '/', (SELECT MAX(id) FROM help_articles))");
$out = [];
$titleBefore = $col("SELECT t.title FROM help_article_translations t JOIN help_articles a ON a.id = t.article_id WHERE a.slug = 'finance-reports' AND t.locale = 'en'");
try {
    if ($titleBefore === false) {
        $out['skip'] = 'migration 0078 not applied';
    } else {
        $pdo->exec("UPDATE help_article_translations t JOIN help_articles a ON a.id = t.article_id SET t.title = 'LiteTest edited title' WHERE a.slug = 'finance-reports' AND t.locale = 'en'");
        $before = $state();
        $migration = require $root . '/database/migrations/0078_seed_finance_help_articles.php';
        $migration->up($pdo);
        $out['unchanged'] = $state() === $before;
        $out['edit_kept'] = $col("SELECT t.title FROM help_article_translations t JOIN help_articles a ON a.id = t.article_id WHERE a.slug = 'finance-reports' AND t.locale = 'en'") === 'LiteTest edited title';
        $out['in_transaction_after'] = $pdo->inTransaction();
    }
} finally {
    if ($titleBefore !== false) {
        $pdo->prepare("UPDATE help_article_translations t JOIN help_articles a ON a.id = t.article_id SET t.title = :title WHERE a.slug = 'finance-reports' AND t.locale = 'en'")
            ->execute(['title' => $titleBefore]);
        $out['restored'] = $col("SELECT t.title FROM help_article_translations t JOIN help_articles a ON a.id = t.article_id WHERE a.slug = 'finance-reports' AND t.locale = 'en'") === $titleBefore;
    }
}
echo json_encode($out);
