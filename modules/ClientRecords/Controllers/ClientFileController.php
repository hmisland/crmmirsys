<?php

declare(strict_types=1);

namespace Modules\ClientRecords\Controllers;

use App\Application\Controllers\BaseController;
use App\Core\Exceptions\ValidationException;
use App\Core\Request;
use App\Core\Response;
use App\Infrastructure\Security\CurrentUser;
use App\Infrastructure\Security\Session;
use App\Infrastructure\Theme\TemplateEngine;
use Modules\ClientRecords\Services\ClientFileService;
use Modules\ClientRecords\Services\ClientRecordService;

use function App\Infrastructure\Theme\t;

/**
 * Upload / view / delete of a record's media and documents. Viewing is
 * the only way these files leave the server: every request re-checks
 * the record (tenant + view), the file's ownership, and view_sensitive
 * inside ClientFileService::forDownload().
 */
final class ClientFileController extends BaseController
{
    /** Only these are ever rendered inline; anything else is a download. */
    private const INLINE_TYPES = ['image/png', 'image/jpeg', 'image/gif', 'image/webp'];

    public function __construct(
        TemplateEngine $templates,
        private readonly ClientRecordService $records,
        private readonly ClientFileService $files,
    ) {
        parent::__construct($templates);
    }

    public function upload(Request $request): Response
    {
        $user = CurrentUser::user();
        $record = $this->records->find($user, (int) $request->attribute('id'));
        $kind = $request->input('kind') === 'media' ? 'media' : 'document';

        try {
            $this->files->upload($user, $record, $kind, $request->file('file'), $request->all());
            Session::setFlash('success', t('client_records.flash.file_uploaded'));
        } catch (ValidationException $e) {
            Session::setFlash('error', t((string) (array_values($e->errors)[0][0] ?? 'client_records.validation.file_required')));
        }

        return $this->redirect('/client-records/' . (int) $record['id'] . '?tab=' . ($kind === 'media' ? 'media' : 'documents'));
    }

    public function show(Request $request): Response
    {
        $user = CurrentUser::user();
        $record = $this->records->find($user, (int) $request->attribute('id'));
        $download = $this->files->forDownload($user, $record, (int) $request->attribute('fileId'));

        $mime = (string) $download['attachment']['mime_type'];
        $inline = in_array($mime, self::INLINE_TYPES, true) && $request->query('download') === null;
        $filename = (string) $download['attachment']['filename'];
        $asciiName = (string) preg_replace('/[^\x20-\x7E]|["\\\\]/', '_', $filename);

        return Response::html((string) file_get_contents($download['path']))
            ->withHeader('Content-Type', $inline ? $mime : 'application/octet-stream')
            ->withHeader('Content-Disposition', ($inline ? 'inline' : 'attachment') . '; filename="' . $asciiName . '"; filename*=UTF-8\'\'' . rawurlencode($filename))
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Cache-Control', 'private, no-store')
            ->withHeader('Content-Security-Policy', "default-src 'none'; img-src 'self'; style-src 'unsafe-inline'");
    }

    public function delete(Request $request): Response
    {
        $user = CurrentUser::user();
        $record = $this->records->find($user, (int) $request->attribute('id'));
        $tab = $request->input('kind') === 'media' ? 'media' : 'documents';

        $this->files->delete($user, $record, (int) $request->attribute('fileId'));
        Session::setFlash('success', t('client_records.flash.file_deleted'));

        return $this->redirect('/client-records/' . (int) $record['id'] . '?tab=' . $tab);
    }
}
