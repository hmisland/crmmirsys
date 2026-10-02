<?php

declare(strict_types=1);

namespace App\Application\Controllers;

use App\Application\Services\CustomFieldService;
use App\Core\Exceptions\ValidationException;
use App\Core\Request;
use App\Core\Response;
use App\Infrastructure\Security\CurrentUser;
use App\Infrastructure\Security\Session;
use App\Infrastructure\Theme\TemplateEngine;

final class CustomFieldAdminController extends BaseController
{
    private const ENTITY_TYPES = ['contact' => 'Contacts', 'company' => 'Companies', 'lead' => 'Leads'];

    public function __construct(TemplateEngine $templates, private readonly CustomFieldService $customFields)
    {
        parent::__construct($templates);
    }

    public function index(Request $request): Response
    {
        $definitions = [];
        foreach (array_keys(self::ENTITY_TYPES) as $type) {
            $definitions[$type] = $this->customFields->definitionsFor($type);
        }

        return $this->viewWithLayout('org/custom-fields', 'layout', [
            'entityTypes' => self::ENTITY_TYPES,
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
