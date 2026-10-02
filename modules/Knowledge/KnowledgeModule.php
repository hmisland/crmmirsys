<?php

declare(strict_types=1);

namespace Modules\Knowledge;

use App\Application\Services\TagService;
use App\Core\Container;
use App\Core\Middleware\AuthMiddleware;
use App\Core\Middleware\CsrfMiddleware;
use App\Core\Middleware\ModuleMiddleware;
use App\Core\Middleware\RbacMiddleware;
use App\Core\Request;
use App\Core\Router;
use App\Infrastructure\Authorization\AuthorizationEngine;
use App\Infrastructure\Modules\ModuleInterface;
use App\Infrastructure\Modules\ModuleManifest;
use App\Infrastructure\Repositories\UserRepository;
use App\Infrastructure\Theme\TemplateEngine;
use Modules\Knowledge\Controllers\FileLibraryController;
use Modules\Knowledge\Controllers\KbController;
use Modules\Knowledge\Services\FileLibraryService;
use Modules\Knowledge\Services\KbService;

final class KnowledgeModule implements ModuleInterface
{
    private ?KbController $kbController = null;
    private ?FileLibraryController $filesController = null;

    public function manifest(): ModuleManifest
    {
        return ModuleManifest::fromFile(__DIR__ . '/module.json');
    }

    public function register(Container $container): void
    {
        $authorization = $container->make(AuthorizationEngine::class);
        $tagService = $container->make(TagService::class);

        $kbService = new KbService(new KbArticleRepository(), new KbCategoryRepository(), $authorization);

        $filesService = new FileLibraryService(
            new FileLibraryDocumentRepository(),
            new FileLibraryUploaderRepository(),
            $tagService,
            new UserRepository(),
            $authorization,
            $container->make('app.storage_root') . '/file-library'
        );

        $templates = $container->make(TemplateEngine::class);

        $this->kbController = new KbController($templates, $kbService);
        $this->filesController = new FileLibraryController($templates, $filesService);
    }

    public function routes(Router $router): void
    {
        $auth = new AuthMiddleware();
        $module = new ModuleMiddleware('knowledge');
        $kbController = $this->kbController;
        $filesController = $this->filesController;

        $router->group('/kb', [$auth, $module], function (Router $router) use ($kbController) {
            $router->get('/articles', fn (Request $r) => $kbController->index($r), [new RbacMiddleware('kb.view')]);
            $router->get('/articles/create', fn (Request $r) => $kbController->showCreate($r));
            $router->post('/articles', fn (Request $r) => $kbController->create($r), [new CsrfMiddleware()]);
            $router->get('/articles/{id}', fn (Request $r) => $kbController->show($r));
            $router->get('/articles/{id}/edit', fn (Request $r) => $kbController->showEdit($r));
            $router->post('/articles/{id}', fn (Request $r) => $kbController->update($r), [new CsrfMiddleware()]);
            $router->post('/articles/{id}/delete', fn (Request $r) => $kbController->delete($r), [new CsrfMiddleware()]);

            $router->get('/categories', fn (Request $r) => $kbController->categoriesPage($r), [new RbacMiddleware('kb.manage')]);
            $router->post('/categories', fn (Request $r) => $kbController->createCategory($r), [new CsrfMiddleware()]);
            $router->post('/categories/{id}/delete', fn (Request $r) => $kbController->deleteCategory($r), [new CsrfMiddleware()]);
        });

        $router->group('/files', [$auth, $module], function (Router $router) use ($filesController) {
            $router->get('', fn (Request $r) => $filesController->index($r), [new RbacMiddleware('files.view')]);
            $router->post('/upload', fn (Request $r) => $filesController->upload($r), [new CsrfMiddleware()]);
            $router->get('/{id}/download', fn (Request $r) => $filesController->download($r));
            $router->post('/{id}/delete', fn (Request $r) => $filesController->delete($r), [new CsrfMiddleware()]);

            $router->get('/uploaders', fn (Request $r) => $filesController->uploaders($r), [new RbacMiddleware('files.manage_uploaders')]);
            $router->post('/uploaders/grant', fn (Request $r) => $filesController->grantUploader($r), [new CsrfMiddleware()]);
            $router->post('/uploaders/{userId}/revoke', fn (Request $r) => $filesController->revokeUploader($r), [new CsrfMiddleware()]);
        });
    }

    public function migrationsPath(): string
    {
        return __DIR__ . '/Migrations';
    }
}
