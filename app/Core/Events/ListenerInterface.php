<?php

declare(strict_types=1);

namespace App\Core\Events;

interface ListenerInterface
{
    public function handle(EventInterface $event): void;
}
