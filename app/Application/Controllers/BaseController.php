<?php

declare(strict_types=1);

namespace App\Application\Controllers;

use App\Core\Response;
use App\Infrastructure\Theme\TemplateEngine;

/**
 * Per Phase 0 §55: Controller -> Service -> Repository -> Database.
 * Controllers here do request/response plumbing only — parse input,
 * call a Service, render a View Model. No SQL, no business rules.
 */
abstract class BaseController
{
    public function __construct(protected readonly TemplateEngine $templates)
    {
    }

    protected function view(string $name, array $data = [], int $status = 200): Response
    {
        return Response::html($this->templates->render($name, $data), $status);
    }

    protected function viewWithLayout(string $name, string $layout, array $data = [], int $status = 200): Response
    {
        return Response::html($this->templates->renderWithLayout($name, $layout, $data), $status);
    }

    protected function redirect(string $to): Response
    {
        return Response::redirect($to);
    }
}
