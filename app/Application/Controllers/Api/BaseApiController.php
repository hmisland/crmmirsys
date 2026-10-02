<?php

declare(strict_types=1);

namespace App\Application\Controllers\Api;

use App\Core\Response;

/**
 * Per Phase 0 §28: /api/v1 uses a consistent envelope and never
 * depends on HTML templates. This is the shape every module's API
 * controllers (from Phase 5 onward) build on.
 */
abstract class BaseApiController
{
    protected function data(mixed $data, array $meta = [], int $status = 200): Response
    {
        $payload = ['data' => $data];

        if ($meta !== []) {
            $payload['meta'] = $meta;
        }

        return Response::json($payload, $status);
    }

    /**
     * @param array{page?: int, perPage?: int, total?: int} $pagination
     */
    protected function paginated(array $items, array $pagination): Response
    {
        return $this->data($items, ['pagination' => $pagination]);
    }

    protected function created(mixed $data): Response
    {
        return $this->data($data, [], 201);
    }

    protected function noContent(): Response
    {
        return Response::json(null, 204);
    }
}
