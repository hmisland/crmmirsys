<?php

declare(strict_types=1);

namespace Modules\Support;

use App\Application\Services\AttachmentService;
use App\Application\Services\TimelineService;
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
use App\Infrastructure\Repositories\ContactRepository;
use App\Infrastructure\Repositories\UserRepository;
use App\Infrastructure\Theme\TemplateEngine;
use Modules\Support\Controllers\TicketCategoryController;
use Modules\Support\Controllers\TicketController;
use Modules\Support\Services\TicketCategoryService;
use Modules\Support\Services\TicketReplyService;
use Modules\Support\Services\TicketService;

final class SupportModule implements ModuleInterface
{
    private ?TicketController $ticketController = null;
    private ?TicketCategoryController $categoryController = null;
    private ?TicketService $ticketServiceInstance = null;

    public function manifest(): ModuleManifest
    {
        return ModuleManifest::fromFile(__DIR__ . '/module.json');
    }

    /** Exposed so Core (the Dashboard) can optionally read the user's open tickets without hard-depending on this module — same lazy pattern as Notification/Wallet/Sales/Mail. */
    public function ticketService(): ?TicketService
    {
        return $this->ticketServiceInstance;
    }

    public function register(Container $container): void
    {
        $authorization = $container->make(AuthorizationEngine::class);

        $categoryService = new TicketCategoryService(new TicketCategoryRepository(), $authorization);
        $ticketService = new TicketService(new TicketRepository(), $container->make(TimelineService::class), $authorization);
        $this->ticketServiceInstance = $ticketService;
        $replyService = new TicketReplyService(new TicketReplyRepository(), $authorization);

        $templates = $container->make(TemplateEngine::class);

        $this->ticketController = new TicketController(
            $templates,
            $ticketService,
            $replyService,
            $categoryService,
            new ContactRepository(),
            new UserRepository(),
            $container->make(AttachmentService::class)
        );

        $this->categoryController = new TicketCategoryController($templates, $categoryService);
    }

    public function routes(Router $router): void
    {
        $auth = new AuthMiddleware();
        $module = new ModuleMiddleware('support');
        $ticketController = $this->ticketController;
        $categoryController = $this->categoryController;

        $router->group('/support/tickets', [$auth, $module], function (Router $router) use ($ticketController) {
            $router->get('', fn (Request $r) => $ticketController->index($r), [new RbacMiddleware('tickets.view')]);
            $router->get('/board', fn (Request $r) => $ticketController->board($r));
            $router->get('/create', fn (Request $r) => $ticketController->showCreate($r));
            $router->post('', fn (Request $r) => $ticketController->create($r), [new CsrfMiddleware()]);

            // Registered before /{id} — the wildcard would otherwise
            // greedily match these as if they were a numeric id (same
            // ordering lesson as Contacts' /export and /import).
            $router->get('/{id}', fn (Request $r) => $ticketController->show($r));
            $router->get('/{id}/edit', fn (Request $r) => $ticketController->showEdit($r));
            $router->post('/{id}', fn (Request $r) => $ticketController->update($r), [new CsrfMiddleware()]);
            $router->post('/{id}/status', fn (Request $r) => $ticketController->changeStatus($r), [new CsrfMiddleware()]);
            $router->post('/{id}/assign', fn (Request $r) => $ticketController->assign($r), [new CsrfMiddleware()]);
            $router->post('/{id}/delete', fn (Request $r) => $ticketController->delete($r), [new CsrfMiddleware()]);
            $router->post('/{id}/replies', fn (Request $r) => $ticketController->addReply($r), [new CsrfMiddleware()]);
            $router->post('/{id}/attachments', fn (Request $r) => $ticketController->uploadAttachment($r), [new CsrfMiddleware()]);
            $router->get('/{id}/attachments/{attachmentId}/download', fn (Request $r) => $ticketController->downloadAttachment($r));
            $router->post('/{id}/attachments/{attachmentId}/delete', fn (Request $r) => $ticketController->deleteAttachment($r), [new CsrfMiddleware()]);
        });

        $router->group('/support/categories', [$auth, $module], function (Router $router) use ($categoryController) {
            $router->get('', fn (Request $r) => $categoryController->index($r), [new RbacMiddleware('ticket_categories.manage')]);
            $router->post('', fn (Request $r) => $categoryController->create($r), [new CsrfMiddleware()]);
            $router->post('/{id}/delete', fn (Request $r) => $categoryController->delete($r), [new CsrfMiddleware()]);
        });
    }

    public function migrationsPath(): string
    {
        return __DIR__ . '/Migrations';
    }
}
