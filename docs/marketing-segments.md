# Marketing Phase 2 — Segments & Audiences

Reusable audiences for Marketing campaigns. Builds on Marketing 1–3 and
3.1 (`docs/marketing-module.md`, `docs/marketing-audit-phase1-3.md`).

## 1. Segment types

| | Static | Dynamic |
|---|---|---|
| Stored | members in `marketing_segment_contacts` | rules (JSON) in `marketing_segments.rules` |
| Changes | only when a user adds/removes members | recalculated on every preview / use — never refreshed by hand |
| Deleted contact | removed automatically (FK `ON DELETE CASCADE`) | simply no longer matches |
| Who manages | `marketing.manage_segments` **and** `contacts.view` (members are contacts) | `marketing.manage_segments`; rules on name/email/phone also need `contacts.view` |

Both belong to exactly one organization and are reusable across campaigns.

## 2. Filter engine

```
Rules = {match: all|any, groups: [{match: all|any, conditions: [{field, op, value}]}]}
       (all = AND, any = OR — e.g. (A AND B) OR (C AND D))

SegmentConditionRegistry ── providers ── ContactConditions (built-in)
        │                              └─ (future) WooCommerce provider
SegmentRules::validate()  allowlist: field key → its operators → normalized value
SegmentRules::compile()   SQL predicate on contacts "c", values ONLY as bound parameters
```

Built-in conditions (only data that exists in the CRM schema):

| Field | Operators |
|---|---|
| first name, last name, email, mobile (*personal — needs `contacts.view`*) | contains, does not contain, is, is not, starts with, is empty, is set |
| city, province, country, source, job title | same text operators |
| status (active/inactive), gender | is, is not (+ empty for gender) |
| contact created, date of birth | before, after, on, last N days, more than N days ago (+ empty/set for birth date) |
| birthday month | is (January–December) |
| contact group (existing contact categories) | is in, is not in, is empty |
| tag (existing tags) | has, does not have |
| company | is set / is empty |
| interest (existing `contact_interests`) | has, contains |
| marketing opt-out (SMS / email) | opted out of, not opted out of |

**Safety.** The request can only pick a field key and an operator key from
the allowlist; anything else is rejected (`marketing.segment.error.*`).
Stored rules are re-checked at compile time — an unknown field compiles to
`1 = 0` (matches nothing). Values are always bound parameters; LIKE
patterns escape `%`, `_` and `\`. Limits: 10 groups, 20 conditions per
group, 50 in total. Choice values (groups, tags) must exist in the
organization, and the SQL pins them to it again.

**Not available (no existing source of truth):** a separate marketing
*consent* field does not exist in the CRM — consent is expressed through
the opt-out list. The opt-out filter matches by contact id, by email, or
by the phone **as stored on the contact**; a manual opt-out entered in a
different phone format may not be matched by the *filter* (the send-time
opt-out check, which uses normalized numbers, still applies).

## 3. Extension point (future WooCommerce)

A data provider implements `Modules\Marketing\Segments\SegmentConditionProvider`
(returns `SegmentCondition` objects: key, label, category, operators with
value types, choices, `toSql()`), and registers it with the
`SegmentConditionRegistry` service (exposed by `MarketingModule::services()`).
Its conditions then appear in the rule builder and go through the same
validation and compilation. Conditions must pin every subquery to
`$params->org()`. Nothing purchase-related exists yet — no tables, no fake data.

## 4. Campaign integration

- New audience type **Saved segment** (`audience_type = 'segment'`), using the
  existing path: `AudienceRepository` → `AudienceResolver` → approval / worker.
  There is no second queue or recipient-expansion system.
- **Review:** the matching contacts (an *estimate*) and the eligible count now
  (after opt-outs, invalid/missing addresses and duplicates) are shown, with
  a note that the final count is fixed at approval.
- **Approval:** the segment is re-resolved against current data; the
  confirmed count must still match (a change between preview and approval is
  refused); 3.1 rules apply unchanged — contacts.view masking, transactional
  only for individually selected contacts (so never for a segment),
  entitlement, organization ownership.
- **Snapshot:** at approval the campaign stores `audience_snapshot`
  (dynamic: the validated rules + `as_of` (approval time, database clock) +
  `max_contact_id`; static: segment id + approval time from the database
  clock). Background preparation of a large audience (3.1 F5:
  `prepare_cursor`, chunks, cron/page ticks) expands exactly that snapshot,
  so editing or deleting the segment afterwards does not change the campaign;
  "last N days" is evaluated as of approval, and contacts created after
  approval are not added. Limitation (by design, no stored membership): a
  contact whose own data changes between approval and its chunk being
  prepared (e.g. city edited) is evaluated with the new data. Snapshots
  stored before this audit (without `as_of`) still resolve on the live clock.
- **Stored rules that no longer validate** (a field removed, a tampered row)
  compile to "matches nobody" and leave no stray bound parameters; a removed
  group/tag id still compiles (bound, organization-pinned).
- **Time:** every relative date and window is computed on the MySQL clock,
  because `created_at` is stamped by MySQL and PHP's time zone may differ
  (locally PHP is UTC, MySQL `SYSTEM` = Asia/Tehran). Keep MySQL's
  `time_zone` stable on a server: changing it shifts how existing DATETIME
  values compare with `NOW()`.
- Duplicate sends/billing: unchanged 3.1 protections (UNIQUE per campaign
  address, claim locks, Kavenegar `localid`, `billed_units`); repeated
  approval is refused by the status transition.

## 5. Deleting a segment

Soft delete (`deleted_at`). The segment disappears from lists and pickers
and returns 404; a **draft** campaign that uses it can no longer be
approved (`marketing.error.segment_missing`); a campaign already approved
keeps working from its snapshot (static members are kept because the
segment row is not physically deleted). Campaign and delivery history is
never deleted.

## 6. Permissions

| Key | Default | Purpose |
|---|---|---|
| `marketing.manage_segments` (new, migration 0012) | org_admin only | create, edit, delete segments; static members |
| `marketing.view` | (unchanged) | list, view, counts, use in campaigns |
| `contacts.view` | (unchanged) | contact rows in previews, static member management, personal-field rules; without it the values of name/email/phone rules show as `•••` and such a segment cannot be edited (it can still be used and deleted) |

Module entitlement: every route is behind `ModuleMiddleware('marketing')` and
`RequireOrganizationMiddleware`; services re-check permissions.

## 7. Database (additive)

| Migration | Change | Rollback |
|---|---|---|
| Marketing `0009` | `marketing_segments` (+ index `organization_id, deleted_at, name`; FKs org/owner/updated_by) | drop table |
| Marketing `0010` | `marketing_segment_contacts` (PK segment+contact; FKs cascade) | drop table |
| Marketing `0011` | `marketing_campaigns.audience_snapshot` | drop column |
| Marketing `0012` | grant `marketing.manage_segments` to org_admin | delete the grant |

## 8. Performance

- Dynamic members are never stored; counting and previews are one `COUNT`
  and one `LIMIT/OFFSET` query (25 per page).
- Static member counts on the list page: one grouped query for the page.
- Member search for static segments is paginated server-side (no 2,000-contact
  picker — this addresses audit F9 for segments; the campaign form's own
  "selected contacts" picker is unchanged).
- Large audiences reuse 3.1 background preparation (default above 2,000).
- Text conditions use `LIKE` on unindexed columns (`city`, `email`, …): fine
  for tens of thousands of contacts per organization; very large datasets
  may need indexes later.

## WooCommerce purchase conditions (Phase C)

Registered by the WooCommerce module on `SegmentConditionRegistry` (only when Marketing is installed), listed
under "Shop purchases" in the rule builder. Membership is resolved from the synchronized `woo_*` tables by the
same engine (preview, approval snapshot, background expansion) — nothing is copied or pre-aggregated; each
condition is a subquery correlated to the contact through `woo_customers.contact_id`, pinned to the
organization.

| Field | Operators | Value |
|---|---|---|
| Has purchased (`woo_purchased`) | yes / no | — |
| Number of purchases (`woo_order_count`) | at least / at most / exactly | 1–3650 |
| Total purchase amount (`woo_total_spent`) | at least / at most | amount (≥ 0, 2 decimals) |
| Purchased product (`woo_product`) | has / has not | a product of a connected shop |
| Has an order with status (`woo_order_status`) | has / has not | a WooCommerce status |
| Last / first purchase (`woo_last_order`, `woo_first_order`) | in the last N days / more than N days ago / before / after | days or a date |

* **A purchase** is an order with status *completed* or *processing*. Pending payment, on-hold, failed, cancelled,
  refunded and drafts never count toward purchased / number / total / product / first / last; "order status"
  matches any status explicitly. Totals add the stored order totals (one currency per shop).
* **Shop scope:** every condition except product has a "Where" choice — all shops, or one shop. A contact who
  buys in several shops is evaluated per selected shop. Stored as `"scope"` next to field/op/value
  (`ScopedSegmentCondition`); validated against the organization's shops when saved; a removed shop matches
  nothing; anything non-numeric compiles to "no match".
* Dates use the organization's day (order times are stored UTC); "last N days" follows the frozen approval time
  of a campaign snapshot.
* **Not available:** product *category* — WooCommerce order line items carry no categories and the CRM does not
  store the shop catalog; adding it would need a product/category sync first.
* Engine changes (backward compatible): value type `amount`; optional `ScopedSegmentCondition`; the builder
  shows a scope select for such fields.

