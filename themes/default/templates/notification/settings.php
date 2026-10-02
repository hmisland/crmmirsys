<?php
/** @var array|null $destination */

use function App\Infrastructure\Theme\e;
use App\Infrastructure\Security\Csrf;
?>
<section class="card card--form" style="max-width:500px">
    <h1>Notification settings</h1>
    <p class="muted">
        If a colleague sends you a message and checks "notify," we'll try to reach you here — in addition to the
        message already being in your inbox.
    </p>

    <form method="post" action="/notifications/settings">
        <?= Csrf::field() ?>

        <label for="bale_chat_id">Bale chat ID</label>
        <input type="text" id="bale_chat_id" name="bale_chat_id" value="<?= e($destination['bale_chat_id'] ?? '') ?>">
        <div class="field-hint">
            Open Bale, start a chat with this organization's bot, then use a "chat ID" helper bot to find your
            numeric chat ID and paste it here. A phone number alone can't be used — Bale's bot API can only message
            someone who has already started a conversation with the bot.
        </div>

        <label for="phone_number">Phone number</label>
        <input type="text" id="phone_number" name="phone_number" value="<?= e($destination['phone_number'] ?? '') ?>">
        <div class="field-hint">For SMS, once an SMS provider is connected.</div>

        <button type="submit" class="btn btn--primary">Save</button>
    </form>

    <form method="post" action="/notifications/settings/test-bale" style="margin-top:0.75rem">
        <?= Csrf::field() ?>
        <input type="hidden" name="bale_chat_id" value="<?= e($destination['bale_chat_id'] ?? '') ?>">
        <button type="submit" class="btn">Send test Bale message</button>
        <div class="field-hint">Save your chat ID above first — this sends to whatever's currently saved.</div>
    </form>
</section>
