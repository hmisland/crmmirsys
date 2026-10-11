<?php

declare(strict_types=1);

namespace App\Application\Controllers;

use App\Application\Services\CustomFieldService;
use App\Core\Exceptions\ValidationException;
use App\Core\Request;
use App\Core\Response;
use App\Infrastructure\Modules\ModuleAccessResolver;
use App\Infrastructure\Repositories\ModuleRepository;
use App\Infrastructure\Repositories\OrganizationModuleRepository;
use App\Infrastructure\Repositories\UserModuleOverrideRepository;
use App\Infrastructure\Security\CurrentUser;
use App\Infrastructure\Security\Session;
use App\Infrastructure\Theme\TemplateEngine;

use function App\Infrastructure\Theme\t;

final class CustomFieldAdminController extends BaseController
{
    private const ENTITY_TYPES = ['contact' => 'Contacts', 'company' => 'Companies', 'lead' => 'Leads'];

    public function __construct(TemplateEngine $templates, private readonly CustomFieldService $customFields)
    {
        parent::__construct($templates);
    }

    public function index(Request $request): Response
    {
        $entityTypes = self::ENTITY_TYPES;

        // Client Records (optional module) keeps its industry-specific
        // intake fields here too — only offered when the module is
        // available to this organization.
        $clientRecordsEnabled = (new ModuleAccessResolver(new ModuleRepository(), new OrganizationModuleRepository(), new UserModuleOverrideRepository()))
            ->resolvedForUser('client_records', CurrentUser::user());
        if ($clientRecordsEnabled) {
            $entityTypes['client_record'] = t('client_records.title');
        }

        $definitions = [];
        foreach (array_keys($entityTypes) as $type) {
            $definitions[$type] = $this->customFields->definitionsFor($type);
        }

        return $this->viewWithLayout('org/custom-fields', 'layout', [
            'entityTypes' => $entityTypes,
            'definitions' => $definitions,
            'errors' => [],
        ]);
    }

    public function create(Request $request): Response
    {
        $entityType = (string) $request->input('entity_type', '');
        $options = array_filter(array_map('trim', explode("\n", (string) $request->input('options', ''))));

        try {
            $this->customFields->defineField(
                CurrentUser::user(),
                $entityType,
                (string) $request->input('label', ''),
                (string) $request->input('field_type', ''),
                array_values($options)
            );
            Session::setFlash('success', 'Custom field added.');
        } catch (ValidationException $e) {
            Session::setFlash('error', array_values($e->errors)[0][0] ?? 'Could not add field.');
        }

        return $this->redirect('/org/custom-fields');
    }

    public function delete(Request $request): Response
    {
        $this->customFields->deleteField(CurrentUser::user(), (int) $request->attribute('id'));
        Session::setFlash('success', 'Custom field removed.');

        return $this->redirect('/org/custom-fields');
    }
}
