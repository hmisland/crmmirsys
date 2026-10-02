<?php

declare(strict_types=1);

namespace Modules\Knowledge\Controllers;

use App\Application\Controllers\BaseController;
use App\Core\Exceptions\NotFoundException;
use App\Core\Exceptions\ValidationException;
use App\Core\Request;
use App\Core\Response;
use App\Infrastructure\Security\CurrentUser;
use App\Infrastructure\Security\Session;
use App\Infrastructure\Theme\TemplateEngine;
use Modules\Knowledge\Services\FileLibraryService;

final class FileLibraryController extends BaseController
{
    public function __construct(TemplateEngine $templates, private readonly FileLibraryService $files)
    {
        parent::__construct($templates);
    }

    public function index(Request $request): Response
    {
        $actingUser = CurrentUser::user();
        $tagSearch = (string) $request->query('tag', '');

        return $this->viewWithLayout('files/index', 'layout', [
            'documents' => $this->files->list($actingUser, $tagSearch !== '' ? $tagSearch : null),
            'tagSearch' => $tagSearch,
            'canManage' => $this->files->canManage($actingUser),
        ]);
    }

    public function upload(Request $request): Response
    {
        $file = $request->file('file');

        try {
            if ($file === null) {
                throw new ValidationException(['file' => ['Choose a file to upload.']]);
            }

            $tags = array_filter(array_map('trim', (array) $request->input('tags', [])), fn ($t) => $t !== '');

            $this->files->upload(CurrentUser::user(), $file, (string) $request->input('description', ''), $tags);
            Session::setFlash('success', 'File uploaded.');
        } catch (ValidationException $e) {
            Session::setFlash('error', array_values($e->errors)[0][0] ?? 'Could not upload file.');
        }

        return $this->redirect('/files');
    }

    public function download(Request $request): Response
    {
        $document = $this->files->find(CurrentUser::user(), (int) $request->attribute('id'));
        $path = $this->files->absolutePath($document);

        if (!is_file($path)) {
            throw new NotFoundException();
        }

        return Response::html((string) file_get_contents($path))
            ->withHeader('Content-Type', $document['mime_type'])
            ->withHeader('Content-Disposition', 'attachment; filename="' . str_replace('"', '', $document['original_filename']) . '"');
    }

    public function delete(Request $request): Response
    {
        $this->files->delete(CurrentUser::user(), (int) $request->attribute('id'));
        Session::setFlash('success', 'File deleted.');

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
}
