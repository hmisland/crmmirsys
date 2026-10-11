<?php

declare(strict_types=1);

namespace App\Application\Controllers;

use App\Application\Services\SearchService;
use App\Core\Container;
use App\Core\Exceptions\AuthorizationException;
use App\Core\Request;
use App\Core\Response;
use App\Infrastructure\Modules\ModuleAccessResolver;
use App\Infrastructure\Repositories\ModuleRepository;
use App\Infrastructure\Repositories\OrganizationModuleRepository;
use App\Infrastructure\Repositories\UserModuleOverrideRepository;
use App\Infrastructure\Security\CurrentUser;
use App\Infrastructure\Theme\TemplateEngine;

final class SearchController extends BaseController
{
    public function __construct(
        TemplateEngine $templates,
        private readonly SearchService $search,
        private readonly ?Container $container = null,
    ) {
        parent::__construct($templates);
    }

    public function index(Request $request): Response
    {
        $term = (string) $request->query('q', '');
        $results = $this->search->search(CurrentUser::user(), $term);
        $results['client_records'] = $this->clientRecordHits($term);

        return $this->viewWithLayout('crm/search', 'layout', [
            'q' => $term,
            'results' => $results,
        ]);
    }

    /**
     * Optional Client Records hits, via the same lazy has()/make()
     * pattern the Dashboard uses for optional modules. null = the module
     * isn't available to this user (the template hides the section);
     * the service applies client_records.view and its scope itself.
     */
    private function clientRecordHits(string $term): ?array
    {
        $serviceClass = \Modules\ClientRecords\Services\ClientRecordService::class;
        $user = CurrentUser::user();

        if ($this->container === null || !$this->container->has($serviceClass) || $user === null || $user['organization_id'] === null) {
            return null;
        }

        $enabled = (new ModuleAccessResolver(new ModuleRepository(), new OrganizationModuleRepository(), new UserModuleOverrideRepository()))
            ->resolvedForUser('client_records', $user);

        if (!$enabled) {
            return null;
        }

        try {
            return trim($term) === '' ? [] : $this->container->make($serviceClass)->list($user, ['q' => $term, 'limit' => 20]);
        } catch (AuthorizationException) {
            return null;
        }
    }
}
