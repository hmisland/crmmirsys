<?php

declare(strict_types=1);

namespace Modules\Marketing\Segments\Conditions;

use Modules\Marketing\Segments\SqlParams;

/**
 * Conditions on related CRM data, each pinned to the organization:
 *  - group:     contacts.category_id (the existing contact groups);
 *  - tag:       taggables + tags of this organization;
 *  - company:   has a company / none;
 *  - interest:  contact_interests;
 *  - birth_month: month of contacts.date_of_birth;
 *  - opted_out: an entry in this organization's marketing suppression
 *               list for the channel (matched by contact, email, or the
 *               phone as stored — see docs, it's a targeting aid; the
 *               send-time opt-out check still uses normalized addresses).
 * Choice values (group / tag ids) must exist in the organization; the
 * SQL re-pins them to the organization anyway.
 */
final class RelationCondition extends BaseCondition
{
    /**
     * @param \Closure(): array<string, string>|null $choiceSource value => label, for the current organization
     */
    public function __construct(string $key, string $labelKey, string $categoryKey, private readonly ?\Closure $choiceSource = null)
    {
        parent::__construct($key, $labelKey, $categoryKey);
    }

    public function operators(): array
    {
        return match ($this->key) {
            'group' => ['in' => self::TYPE_CHOICE, 'not_in' => self::TYPE_CHOICE, 'empty' => self::TYPE_NONE],
            'tag' => ['has' => self::TYPE_CHOICE, 'not_has' => self::TYPE_CHOICE],
            'company' => ['not_empty' => self::TYPE_NONE, 'empty' => self::TYPE_NONE],
            'interest' => ['has' => self::TYPE_TEXT, 'contains' => self::TYPE_TEXT],
            'birth_month' => ['eq' => self::TYPE_CHOICE],
            'opted_out' => ['is' => self::TYPE_CHOICE, 'is_not' => self::TYPE_CHOICE],
            default => [],
        };
    }

    public function choices(): array
    {
        return match ($this->key) {
            'birth_month' => array_combine(array_map('strval', range(1, 12)), array_map(fn ($m) => 't:marketing.segment.month.' . $m, range(1, 12))),
            'opted_out' => ['sms' => 't:marketing.channel.sms', 'email' => 't:marketing.channel.email'],
            default => $this->choiceSource !== null ? ($this->choiceSource)() : [],
        };
    }

    public function toSql(string $operator, mixed $value, SqlParams $params): string
    {
        switch ($this->key) {
            case 'group':
                return match ($operator) {
                    'in' => 'c.category_id = ' . $params->bind((int) $value),
                    'not_in' => '(c.category_id IS NULL OR c.category_id <> ' . $params->bind((int) $value) . ')',
                    'empty' => 'c.category_id IS NULL',
                    default => throw new \LogicException('Unvalidated operator.'),
                };
            case 'tag':
                $exists = 'EXISTS (SELECT 1 FROM taggables tg JOIN tags t ON t.id = tg.tag_id'
                    . " WHERE tg.taggable_type = 'contact' AND tg.taggable_id = c.id AND tg.tag_id = " . $params->bind((int) $value)
                    . ' AND t.organization_id = ' . $params->org() . ')';

                return $operator === 'has' ? $exists : 'NOT ' . $exists;
            case 'company':
                return $operator === 'empty' ? 'c.company_id IS NULL' : 'c.company_id IS NOT NULL';
            case 'interest':
                $match = $operator === 'has' ? 'ci.interest = ' . $params->bind((string) $value) : 'ci.interest LIKE ' . $params->bind(self::like((string) $value));

                return 'EXISTS (SELECT 1 FROM contact_interests ci WHERE ci.contact_id = c.id AND ci.organization_id = ' . $params->org() . ' AND ' . $match . ')';
            case 'birth_month':
                return 'MONTH(c.date_of_birth) = ' . $params->bind((int) $value);
            case 'opted_out':
                $address = $value === 'email' ? 'LOWER(TRIM(c.email))' : 'c.phone';
                $exists = 'EXISTS (SELECT 1 FROM marketing_suppressions s WHERE s.organization_id = ' . $params->org()
                    . ' AND s.channel = ' . $params->bind((string) $value)
                    // Binary comparison: the two tables may use different collations (MySQL 8 vs MariaDB defaults);
                    // addresses are stored normalized, so exact bytes are what we want anyway.
                    . " AND (s.contact_id = c.id OR CAST(s.address AS BINARY) = CAST({$address} AS BINARY)))";

                return $operator === 'is' ? $exists : 'NOT ' . $exists;
        }

        throw new \LogicException('Unknown relation condition.');
    }
}
