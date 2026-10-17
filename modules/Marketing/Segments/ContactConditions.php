<?php

declare(strict_types=1);

namespace Modules\Marketing\Segments;

use Modules\Marketing\AudienceRepository;
use Modules\Marketing\Segments\Conditions\ChoiceColumnCondition;
use Modules\Marketing\Segments\Conditions\DateColumnCondition;
use Modules\Marketing\Segments\Conditions\RelationCondition;
use Modules\Marketing\Segments\Conditions\TextColumnCondition;

/**
 * The built-in conditions: only data that already exists in the CRM
 * schema (contacts, groups, tags, companies, interests) plus Marketing's
 * own opt-out list. There is no separate consent field in the CRM, so
 * "consent" is expressed as "opted out / not opted out".
 */
final class ContactConditions implements SegmentConditionProvider
{
    public function __construct(private readonly AudienceRepository $audience)
    {
    }

    public function conditions(): array
    {
        $audience = $this->audience;

        return [
            new TextColumnCondition('first_name', 'marketing.segment.field.first_name', 'marketing.segment.cat.contact', 'first_name', true),
            new TextColumnCondition('last_name', 'marketing.segment.field.last_name', 'marketing.segment.cat.contact', 'last_name', true),
            new TextColumnCondition('email', 'marketing.segment.field.email', 'marketing.segment.cat.contact', 'email', true),
            new TextColumnCondition('phone', 'marketing.segment.field.phone', 'marketing.segment.cat.contact', 'phone', true),
            new ChoiceColumnCondition('status', 'marketing.segment.field.status', 'marketing.segment.cat.contact', 'status', [
                'active' => 't:marketing.segment.value.active', 'inactive' => 't:marketing.segment.value.inactive',
            ]),
            new ChoiceColumnCondition('gender', 'marketing.segment.field.gender', 'marketing.segment.cat.contact', 'gender', [
                'male' => 't:marketing.segment.value.male', 'female' => 't:marketing.segment.value.female',
            ], true),
            new TextColumnCondition('city', 'marketing.segment.field.city', 'marketing.segment.cat.location', 'city'),
            new TextColumnCondition('province', 'marketing.segment.field.province', 'marketing.segment.cat.location', 'province'),
            new TextColumnCondition('country', 'marketing.segment.field.country', 'marketing.segment.cat.location', 'country'),
            new TextColumnCondition('source', 'marketing.segment.field.source', 'marketing.segment.cat.contact', 'source'),
            new TextColumnCondition('position', 'marketing.segment.field.position', 'marketing.segment.cat.contact', 'position'),
            new DateColumnCondition('created_at', 'marketing.segment.field.created_at', 'marketing.segment.cat.dates', 'created_at', instant: true),
            new DateColumnCondition('date_of_birth', 'marketing.segment.field.date_of_birth', 'marketing.segment.cat.dates', 'date_of_birth', true, false),
            new RelationCondition('birth_month', 'marketing.segment.field.birth_month', 'marketing.segment.cat.dates'),
            new RelationCondition('group', 'marketing.segment.field.group', 'marketing.segment.cat.relations', fn () => array_column($audience->groups(), 'name', 'id')),
            new RelationCondition('tag', 'marketing.segment.field.tag', 'marketing.segment.cat.relations', fn () => array_column($audience->tags(), 'name', 'id')),
            new RelationCondition('company', 'marketing.segment.field.company', 'marketing.segment.cat.relations'),
            new RelationCondition('interest', 'marketing.segment.field.interest', 'marketing.segment.cat.relations'),
            new RelationCondition('opted_out', 'marketing.segment.field.opted_out', 'marketing.segment.cat.marketing'),
        ];
    }
}
