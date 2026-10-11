<?php

declare(strict_types=1);

namespace App\Application\Services;

use App\Core\Exceptions\AuthorizationException;
use App\Core\Exceptions\ValidationException;
use App\Infrastructure\Authorization\AuthorizationEngine;
use App\Infrastructure\Authorization\Permission;
use App\Infrastructure\Repositories\CustomFieldDefinitionRepository;
use App\Infrastructure\Repositories\CustomFieldValueRepository;
use App\Infrastructure\Validation\Validator;

/**
 * Per Phase 0 §45: defining a new field never touches the database
 * schema — it's a new row in custom_field_definitions. Values are
 * always stored as text (custom_field_values.value) regardless of
 * field_type; field_type governs validation/rendering, not storage.
 */
final class CustomFieldService
{
    private const VALID_TYPES = ['text', 'number', 'date', 'boolean', 'select', 'multiselect', 'email', 'phone', 'url'];

    public function __construct(
        private readonly CustomFieldDefinitionRepository $definitions,
        private readonly CustomFieldValueRepository $values,
        private readonly AuthorizationEngine $authorization,
    ) {
    }

    public function definitionsFor(string $entityType): array
    {
        return $this->definitions->forEntityType($entityType);
    }

    public function valuesFor(string $entityType, int $entityId): array
    {
        return $this->values->forEntity($entityType, $entityId);
    }

    public function defineField(array $actingUser, string $entityType, string $label, string $fieldType, array $options = []): array
    {
        if (!$this->authorization->can($actingUser, Permission::CUSTOM_FIELDS_MANAGE)) {
            throw new AuthorizationException();
        }

        $validator = Validator::make(
            ['label' => $label, 'field_type' => $fieldType],
            ['label' => 'required|min:1']
        );

        if ($validator->fails() || !in_array($fieldType, self::VALID_TYPES, true)) {
            $errors = $validator->errors();
            if (!in_array($fieldType, self::VALID_TYPES, true)) {
                $errors['field_type'] = ['Invalid field type.'];
            }
            throw new ValidationException($errors);
        }

        $fieldKey = $this->slugify($label);

        if ($this->definitions->findByKey($entityType, $fieldKey) !== null) {
            throw new ValidationException(['label' => ['A field with this name already exists.']]);
        }

        $id = $this->definitions->create([
            'entity_type' => $entityType,
            'field_key' => $fieldKey,
            'label' => trim($label),
            'field_type' => $fieldType,
            'options' => in_array($fieldType, ['select', 'multiselect'], true) ? json_encode($options) : null,
        ]);

        return $this->definitions->findById($id);
    }

    public function deleteField(array $actingUser, int $definitionId): void
    {
        if (!$this->authorization->can($actingUser, Permission::CUSTOM_FIELDS_MANAGE)) {
            throw new AuthorizationException();
        }

        $this->definitions->deleteDefinition($definitionId);
    }

    /**
     * Removes a definition only when no record holds a value for it
     * any more — used by the CSV-import rollback for fields the import
     * itself created. Returns whether it was removed.
     */
    public function deleteDefinitionIfUnused(int $definitionId): bool
    {
        $definition = $this->definitions->findById($definitionId);

        if ($definition === null) {
            return false;
        }

        if ($this->values->countForField($definition['entity_type'], $definition['field_key']) > 0) {
            return false;
        }

        $this->definitions->deleteDefinition($definitionId);

        return true;
    }

    /**
     * @param array<string, mixed> $values field_key => raw submitted value
     */
    public function saveValues(string $entityType, int $entityId, array $values): void
    {
        $definitionKeys = array_column($this->definitions->forEntityType($entityType), 'field_key');

        foreach ($values as $key => $value) {
            // Only ever writes a value for a field that's actually
            // defined for this entity type — a stray/guessed key in
            // the submitted form data is silently ignored, not stored.
            if (!in_array($key, $definitionKeys, true)) {
                continue;
            }

            $stored = is_array($value) ? json_encode($value) : (string) $value;
            $this->values->setValue($entityType, $entityId, $key, $stored === '' ? null : $stored);
        }
    }

    private function slugify(string $label): string
    {
        $slug = strtolower(trim((string) preg_replace('/[^a-zA-Z0-9]+/', '_', $label), '_'));

        // Labels with no Latin letters/digits (e.g. Persian/Arabic) would
        // all collapse to the same key — derive a stable one per label.
        return $slug !== '' ? $slug : 'field_' . substr(md5(trim($label)), 0, 8);
    }
}
