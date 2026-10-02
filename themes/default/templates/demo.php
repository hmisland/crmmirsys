<?php
/** @var array $notes */

use function App\Infrastructure\Theme\e;
use App\Infrastructure\Security\Csrf;
?>
<section class="card">
    <h1>Demo module</h1>
    <p class="muted">
        This page only renders because the Demo module is installed, enabled platform-wide,
        and enabled for your organization — turn it off from the module manager and this page 404s.
    </p>

    <form method="post" action="/demo" class="inline-form-row">
        <?= Csrf::field() ?>
        <input type="text" name="body" placeholder="Write a note..." required>
        <button type="submit" class="btn btn--primary">Add</button>
    </form>

    <ul>
        <?php foreach ($notes as $note): ?>
            <li><?= e($note['body']) ?> <span class="muted">(<?= e($note['created_at']) ?>)</span></li>
        <?php endforeach; ?>
        <?php if ($notes === []): ?>
            <li class="muted">No notes yet.</li>
        <?php endif; ?>
    </ul>
</section>
