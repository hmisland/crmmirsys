<?php

declare(strict_types=1);

namespace Modules\Knowledge\Controllers;

use App\Application\Controllers\BaseController;
use App\Core\Exceptions\NotFoundException;
use App\Core\Exceptions\ValidationException;
use App\Core\Request;
use App\Core\Response;
use App\Infrastructure\Pagination\Pagination;
use App\Infrastructure\Security\CurrentUser;
use App\Infrastructure\Security\Session;
use App\Infrastructure\Theme\TemplateEngine;
use Modules\Knowledge\Services\FileLibraryService;

use function App\Infrastructure\Theme\t;

final class FileLibraryController extends BaseController
{
    public function __construct(TemplateEngine $templates, private readonly FileLibraryService $files)
    {
        parent::__construct($templates);
    }

    public function index(Request $request): Response
    {
        $actingUser = CurrentUser::user();
        // ?tag= is the pre-folders parameter name; old links keep working.
        $search = trim((string) $request->query('q', (string) $request->query('tag', '')));
        $folderId = $this->folderParam($request->query('folder'));
        $view = $request->query('view') === 'grid' ? 'grid' : 'list';

        $browse = $this->files->browse($actingUser, $search !== '' ? null : $folderId, $search);
        [$documents, $pagination] = Pagination::slice($browse['documents'], $request);
        $canManage = $this->files->canManage($actingUser);

        return $this->viewWithLayout('files/index', 'layout', [
            'folder' => $browse['folder'],
            'breadcrumb' => $browse['breadcrumb'],
            'folders' => $browse['folders'],
            'documents' => $documents,
            'folderOptions' => $browse['folderOptions'],
            'pagination' => $pagination,
            'search' => $search,
            'view' => $view,
            'canManage' => $canManage,
            'inboxCount' => $canManage ? $this->files->inboxCount($actingUser) : 0,
            'organizationId' => (int) ($actingUser['organization_id'] ?? 0),
        ]);
    }

    public function upload(Request $request): Response
    {
        $file = $request->file('file');
        $folderId = $this->folderParam($request->input('folder_id'));

        try {
            if ($file === null) {
                throw new ValidationException(['file' => ['files.validation.file_required']]);
            }

            $tags = array_filter(array_map('trim', (array) $request->input('tags', [])), fn ($t) => $t !== '');

            $this->files->upload(CurrentUser::user(), $file, (string) $request->input('description', ''), $tags, $folderId, (string) $request->input('title', ''));
            Session::setFlash('success', t('files.flash.uploaded'));
        } catch (ValidationException $e) {
            $this->flashError($e);
        }

        return $this->redirect($this->folderUrl($folderId, $request));
    }

    public function download(Request $request): Response
    {
        $document = $this->files->find(CurrentUser::user(), (int) $request->attribute('id'));

        return $this->fileResponse($document, false);
    }

    /** Images and PDFs open in the browser; any other type is still sent as a download. */
    public function open(Request $request): Response
    {
        $document = $this->files->find(CurrentUser::user(), (int) $request->attribute('id'));

        return $this->fileResponse($document, in_array($document['mime_type'], FileLibraryService::INLINE_TYPES, true));
    }

    public function thumb(Request $request): Response
    {
        $path = $this->files->thumbnailPath(CurrentUser::user(), (int) $request->attribute('id'));
        if ($path === null) {
            throw new NotFoundException();
        }

        return Response::html((string) file_get_contents($path))
            ->withHeader('Content-Type', 'image/jpeg')
            ->withHeader('Content-Disposition', 'inline')
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Cache-Control', 'private, max-age=86400')
            ->withHeader('Content-Security-Policy', "default-src 'none'");
    }

    public function delete(Request $request): Response
    {
        $this->files->delete(CurrentUser::user(), (int) $request->attribute('id'));
        Session::setFlash('success', t('files.flash.deleted'));

        return $this->redirect($this->folderUrl($this->folderParam($request->input('return_folder')), $request));
    }

    public function move(Request $request): Response
    {
        $target = $this->folderParam($request->input('folder_id'));
        $this->files->moveDocument(CurrentUser::user(), (int) $request->attribute('id'), $target);
        Session::setFlash('success', t('files.flash.moved'));

        return $this->redirect($this->folderUrl($this->folderParam($request->input('return_folder')), $request));
    }

    public function createFolder(Request $request): Response
    {
        $parentId = $this->folderParam($request->input('parent_id'));

        try {
            $id = $this->files->createFolder(CurrentUser::user(), $parentId, (string) $request->input('name', ''));
            Session::setFlash('success', t('files.flash.folder_created'));

            return $this->redirect($this->folderUrl($id, $request));
        } catch (ValidationException $e) {
            $this->flashError($e);

            return $this->redirect($this->folderUrl($parentId, $request));
        }
    }

    public function renameFolder(Request $request): Response
    {
        $id = (int) $request->attribute('id');

        try {
            $this->files->renameFolder(CurrentUser::user(), $id, (string) $request->input('name', ''));
            Session::setFlash('success', t('files.flash.folder_renamed'));
        } catch (ValidationException $e) {
            $this->flashError($e);
        }

        return $this->redirect($this->folderUrl($id, $request));
    }

    public function deleteFolder(Request $request): Response
    {
        $id = (int) $request->attribute('id');

        try {
            $folder = $this->files->deleteFolder(CurrentUser::user(), $id);
            Session::setFlash('success', t('files.flash.folder_deleted'));

            return $this->redirect($this->folderUrl($this->folderParam($folder['parent_id']), $request));
        } catch (ValidationException $e) {
            $this->flashError($e);

            return $this->redirect($this->folderUrl($id, $request));
        }
    }

    public function importInbox(Request $request): Response
    {
        $result = $this->files->importInbox(CurrentUser::user());

        Session::setFlash('success', t('files.flash.inbox_imported', ['count' => $result['imported']])
            . ($result['remaining'] > 0 ? ' ' . t('files.flash.inbox_remaining', ['count' => $result['remaining']]) : ''));
        if ($result['skipped'] !== []) {
            $shown = array_slice($result['skipped'], 0, 10);
            Session::setFlash('error', t('files.flash.inbox_skipped', ['count' => count($result['skipped'])])
                . ' ' . implode(', ', $shown) . (count($result['skipped']) > 10 ? ' …' : ''));
        }

        return $this->redirect('/files');
    }

    public function uploaders(Request $request): Response
    {
        $data = $this->files->uploaderCandidates(CurrentUser::user());

        return $this->viewWithLayout('files/uploaders', 'layout', [
            'users' => $data['all'],
            'grantedUserIds' => $data['grantedUserIds'],
        ]);
    }

    public function grantUploader(Request $request): Response
    {
        $this->files->grantUploader(CurrentUser::user(), (int) $request->input('user_id', 0));
        Session::setFlash('success', 'Upload access granted.');

        return $this->redirect('/files/uploaders');
    }

    public function revokeUploader(Request $request): Response
    {
        $this->files->revokeUploader(CurrentUser::user(), (int) $request->attribute('userId'));
        Session::setFlash('success', 'Upload access removed.');

        return $this->redirect('/files/uploaders');
    }

    private function fileResponse(array $document, bool $inline): Response
    {
        $path = $this->files->absolutePath($document);
        if (!is_file($path)) {
            throw new NotFoundException();
        }

        $filename = (string) $document['original_filename'];
        $asciiName = (string) preg_replace('/[^\x20-\x7E]|["\\\\]/', '_', $filename);
        $response = Response::html((string) file_get_contents($path))
            ->withHeader('Content-Type', $inline ? (string) $document['mime_type'] : 'application/octet-stream')
            ->withHeader('Content-Disposition', ($inline ? 'inline' : 'attachment') . '; filename="' . $asciiName . '"; filename*=UTF-8\'\'' . rawurlencode($filename))
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Cache-Control', 'private, no-store');

        // Inline images can't run script in our origin; PDFs open in the browser's own viewer
        // (a CSP here would break Chrome's viewer), and nosniff pins both to their real type.
        if ($inline && in_array($document['mime_type'], FileLibraryService::IMAGE_TYPES, true)) {
            $response = $response->withHeader('Content-Security-Policy', "default-src 'none'; img-src 'self'; style-src 'unsafe-inline'");
        }

        return $response;
    }

    private function flashError(ValidationException $e): void
    {
        Session::setFlash('error', t((string) (array_values($e->errors)[0][0] ?? 'files.validation.upload_failed')));
    }

    private function folderParam(mixed $value): ?int
    {
        $id = (int) $value;

        return $id > 0 ? $id : null;
    }

    /** Back to a folder, keeping the list/grid choice the form was posted from. */
    private function folderUrl(?int $folderId, Request $request): string
    {
        $query = [];
        if ($folderId !== null) {
            $query['folder'] = $folderId;
        }
        if ($request->input('view') === 'grid') {
            $query['view'] = 'grid';
        }

        return '/files' . ($query !== [] ? '?' . http_build_query($query) : '');
    }
}
