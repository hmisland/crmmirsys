<?php

declare(strict_types=1);

namespace Modules\ClientRecords\Controllers\Api;

use App\Application\Controllers\Api\BaseApiController;
use App\Core\Request;
use App\Core\Response;
use App\Infrastructure\Security\CurrentUser;
use Modules\ClientRecords\Services\ClientFileService;
use Modules\ClientRecords\Services\ClientIntegrations;
use Modules\ClientRecords\Services\ClientRecordService;
use Modules\ClientRecords\Services\ClientTimelineService;
use Modules\ClientRecords\Services\ClientTreatmentService;

/**
 * /api/v1/client-records — same services, same checks as the web UI
 * (Phase 0 §28: two front doors, one service layer). Same session-cookie
 * auth as /api/v1/auth; every write needs `_csrf` (CsrfMiddleware), so
 * a cross-site page can't drive these endpoints with the user's cookie.
 * Errors come out of ErrorHandler in the standard `errors` envelope;
 * money is always a string, never a JSON number (Phase 0 §18f).
 */
final class ClientRecordApiController extends BaseApiController
{
    public function __construct(
        private readonly ClientRecordService $records,
        private readonly ClientTreatmentService $treatment,
        private readonly ClientFileService $files,
        private readonly ClientTimelineService $timeline,
        private readonly ClientIntegrations $integrations,
    ) {
    }

    public function index(Request $request): Response
    {
        $rows = $this->records->list(CurrentUser::user(), [
            'q' => (string) $request->query('q', ''),
            'status' => (string) $request->query('status', ''),
            'assigned_user_id' => (string) $request->query('assigned_user_id', ''),
        ]);

        return $this->data(array_map(fn (array $row) => [
            'id' => (int) $row['id'],
            'record_number' => $row['record_number'],
            'status' => $row['status'],
            'contact' => [
                'id' => (int) $row['contact_id'],
                'name' => trim($row['contact_first_name'] . ' ' . ($row['contact_last_name'] ?? '')),
                'phone' => $row['contact_phone'],
                'email' => $row['contact_email'],
            ],
            'assigned_user_id' => $row['assigned_user_id'],
            'last_visit_at' => $row['last_visit_at'],
            'next_visit_at' => $row['next_visit_at'],
            'updated_at' => $row['updated_at'],
        ], $rows), ['total' => count($rows)]);
    }

    public function show(Request $request): Response
    {
        $user = CurrentUser::user();
        $record = $this->records->find($user, (int) $request->attribute('id'));

        return $this->data($this->recordPayload($user, $record));
    }

    public function create(Request $request): Response
    {
        $user = CurrentUser::user();
        $record = $this->records->create($user, $request->all());

        return $this->created($this->recordPayload($user, $record));
    }

    public function update(Request $request): Response
    {
        $user = CurrentUser::user();
        $id = (int) $request->attribute('id');
        $record = $this->records->find($user, $id);

        // A partial update: unspecified fields keep their current value.
        $input = $request->all() + $this->records->presentable($user, $record) + ['custom' => []];
        $record = $this->records->update($user, $id, $input);

        return $this->data($this->recordPayload($user, $record));
    }

    public function services(Request $request): Response
    {
        $record = $this->records->find(CurrentUser::user(), (int) $request->attribute('id'));

        return $this->data($this->treatment->servicesFor($record));
    }

    public function createService(Request $request): Response
    {
        $user = CurrentUser::user();
        $record = $this->records->find($user, (int) $request->attribute('id'));

        return $this->created($this->treatment->createService($user, $record, $request->all()));
    }

    public function sessions(Request $request): Response
    {
        $user = CurrentUser::user();
        $record = $this->records->find($user, (int) $request->attribute('id'));

        return $this->data($this->treatment->sessionsFor($user, $record));
    }

    public function createSession(Request $request): Response
    {
        $user = CurrentUser::user();
        $record = $this->records->find($user, (int) $request->attribute('id'));
        $session = $this->treatment->createSession($user, $record, $request->all());

        return $this->created($this->treatment->findSession($user, $record, (int) $session['id']));
    }

    /** File metadata only — the bytes are served by the web route, which applies the same checks. */
    public function files(Request $request): Response
    {
        $user = CurrentUser::user();
        $record = $this->records->find($user, (int) $request->attribute('id'));
        $kind = $request->query('kind') === 'document' ? 'document' : 'media';

        return $this->data(array_map(fn (array $f) => [
            'id' => (int) $f['id'],
            'kind' => $f['kind'],
            'category' => $f['category'],
            'filename' => $f['filename'],
            'mime_type' => $f['mime_type'],
            'size' => $f['size'],
            'taken_at' => $f['taken_at'],
            'service_record_id' => $f['service_record_id'],
            'session_id' => $f['session_id'],
            'notes' => $f['notes'],
            'url' => '/client-records/' . (int) $record['id'] . '/files/' . (int) $f['id'],
        ], $this->files->filesFor($user, $record, $kind)));
    }

    public function timeline(Request $request): Response
    {
        $user = CurrentUser::user();
        $record = $this->records->find($user, (int) $request->attribute('id'));

        return $this->data($this->timeline->build($user, $record));
    }

    public function followUps(Request $request): Response
    {
        $user = CurrentUser::user();
        $record = $this->records->find($user, (int) $request->attribute('id'));

        return $this->data($this->integrations->followUpsForContact($user, (int) $record['contact_id']));
    }

    /** Linked invoices + payments, read from Finance (empty if Finance or its permissions aren't available). */
    public function financials(Request $request): Response
    {
        $user = CurrentUser::user();
        $record = $this->records->find($user, (int) $request->attribute('id'));
        $invoices = $this->integrations->invoicesForContact($user, (int) $record['contact_id']);
        $payments = $this->integrations->paymentsForInvoices($user, $invoices);

        return $this->data(array_map(fn (array $i) => [
            'id' => (int) $i['id'],
            'invoice_number' => $i['invoice_number'],
            'status' => $i['status'],
            'issue_date' => $i['issue_date'],
            'currency' => $i['currency_code'],
            'grand_total' => (string) $i['grand_total'],
            'paid_total' => (string) $i['paid_total'],
            // Kept as before (grand − paid) for existing consumers (decision D2).
            'remaining' => bcsub((string) $i['grand_total'], (string) $i['paid_total'], 18),
            // Finance's outstanding: issued invoices only, grand − credited − paid
            // (finance remediation P2); "0" for drafts and cancelled invoices.
            'outstanding' => $i['status'] === 'issued'
                ? bcsub(bcsub((string) $i['grand_total'], (string) ($i['credited_total'] ?? '0'), 18), (string) $i['paid_total'], 18)
                : '0',
            'payments' => array_map(fn (array $p) => [
                'id' => (int) $p['id'],
                'amount' => (string) $p['amount'],
                'payment_date' => $p['payment_date'],
                'payment_method' => $p['payment_method'],
            ], $payments[(int) $i['id']] ?? []),
        ], $invoices));
    }

    private function recordPayload(array $user, array $record): array
    {
        $visible = $this->records->presentable($user, $record);
        $contact = $this->records->contactFor($record);

        return [
            'id' => (int) $visible['id'],
            'record_number' => $visible['record_number'],
            'status' => $visible['status'],
            'contact' => [
                'id' => (int) $contact['id'],
                'name' => trim($contact['first_name'] . ' ' . ($contact['last_name'] ?? '')),
                'phone' => $contact['phone'],
                'email' => $contact['email'],
                'gender' => $contact['gender'] ?? null,
                'date_of_birth' => $contact['date_of_birth'] ?? null,
                'source' => $contact['source'],
            ],
            'assigned_user_id' => $visible['assigned_user_id'],
            'important_notes' => $visible['important_notes'],
            'preferences' => $visible['preferences'],
            'sensitive' => $this->records->canViewSensitive($user, $record) ? [
                'sensitivities' => $visible['sensitivities'],
                'restrictions' => $visible['restrictions'],
                'observations' => $visible['observations'],
            ] : null,
            'custom_fields' => $this->records->customFieldValues((int) $visible['id']),
            'created_at' => $visible['created_at'],
            'updated_at' => $visible['updated_at'],
        ];
    }
}
