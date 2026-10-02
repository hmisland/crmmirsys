<?php

declare(strict_types=1);

namespace App\Application\Controllers;

use App\Application\Services\SearchService;
use App\Core\Request;
use App\Core\Response;
use App\Infrastructure\Security\CurrentUser;
use App\Infrastructure\Theme\TemplateEngine;

final class SearchController extends BaseController
{
    public function __construct(TemplateEngine $templates, private readonly SearchService $search)
    {
        parent::__construct($templates);
    }

    public function index(Request $request): Response
    {
        $term = (string) $request->query('q', '');
        $results = $this->search->search(CurrentUser::user(), $term);

        return $this->viewWithLayout('crm/search', 'layout', [
            'q' => $term,
            'results' => $results,
        ]);
    }
}
