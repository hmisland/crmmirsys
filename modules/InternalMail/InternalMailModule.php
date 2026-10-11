<?php

declare(strict_types=1);

namespace Modules\InternalMail;

use App\Application\Services\TimelineService;
use App\Core\Container;
use App\Core\Middleware\AuthMiddleware;
use App\Core\Middleware\CsrfMiddleware;
use App\Core\Middleware\ModuleMiddleware;
use App\Core\Middleware\RbacMiddleware;
use App\Core\Request;
use App\Core\Router;
use App\Infrastructure\Authorization\AuthorizationEngine;
use App\Infrastructure\Mail\MailerInterface;
use App\Infrastructure\Modules\ModuleInterface;
use App\Infrastructure\Modules\ModuleManifest;
use App\Infrastructure\Repositories\ContactRepository;
use App\Infrastructure\Repositories\UserRepository;
use App\Infrastructure\Theme\TemplateEngine;
use Modules\InternalMail\Controllers\BroadcastController;
use Modules\InternalMail\Controllers\MailController;
use Modules\InternalMail\Services\BroadcastService;
use Modules\InternalMail\Services\MessageService;

final class InternalMailModule implements ModuleInterface
{
    private ?MailController $mailController = null;
    private ?BroadcastController $broadcastController = null;
    private ?MessageService $messageServiceInstance = null;

    public function manifest(): ModuleManifest
    {
        return ModuleManifest::fromFile(__DIR__ . '/module.json');
    }

    /** Exposed so Core (the Dashboard) can optionally read the user's own unread count without hard-depending on this module — same lazy pattern as Notification/Wallet/Sales. */
    public function messageService(): ?MessageService
    {
        return $this->messageServiceInstance;
    }

    public function register(Container $container): void
    {
        $authorization = $container->make(AuthorizationEngine::class);
        $users = new UserRepository();

        $messageService = new MessageService(
            new MessageRepository(),
            new MessageRecipientRepository(),
            new MessageAttachmentRepository(),
            $users,
            $authorization,
            $container->make(TimelineService::class),
            $container->make('app.storage_root') . '/mail-attachments'
        );
        $this->messageServiceInstance = $messageService;

        $broadcastService = new BroadcastService(
            $messageService,
            $users,
            new ContactRepository(),
            new ContactBroadcastRepository(),
            $container->make(MailerInterface::class),
            $authorization
        );

        $templates = $container->make(TemplateEngine::class);

        $this->mailController = new MailController($templates, $messageService, $users, $container);
        $this->broadcastController = new BroadcastController($templates, $broadcastService);
    }

    public function routes(Router $router): void
    {
        $auth = new AuthMiddleware();
        $module = new ModuleMiddleware('internal_mail');
        $mailController = $this->mailController;
        $broadcastController = $this->broadcastController;

        $router->group('/mail', [$auth, $module], function (Router $router) use ($mailController, $broadcastController) {
            $router->get('', fn (Request $r) => $mailController->inbox($r), [new RbacMiddleware('mail.view')]);
            $router->get('/sent', fn (Request $r) => $mailController->sent($r));
            $router->get('/drafts', fn (Request $r) => $mailController->drafts($r));
            $router->get('/trash', fn (Request $r) => $mailController->trash($r));
            $router->get('/compose', fn (Request $r) => $mailController->showCompose($r));
            $router->post('/compose', fn (Request $r) => $mailController->compose($r), [new CsrfMiddleware()]);

            $router->post('/inbox/bulk-delete', fn (Request $r) => $mailController->bulkTrash($r, 'inbox'), [new CsrfMiddleware()]);
            $router->post('/sent/bulk-delete', fn (Request $r) => $mailController->bulkTrash($r, 'sent'), [new CsrfMiddleware()]);
            $router->post('/drafts/bulk-delete', fn (Request $r) => $mailController->bulkTrash($r, 'drafts'), [new CsrfMiddleware()]);
            $router->get('/drafts/{id}/edit', fn (Request $r) => $mailController->showEditDraft($r));
            $router->post('/drafts/{id}', fn (Request $r) => $mailController->updateDraft($r), [new CsrfMiddleware()]);
            $router->post('/drafts/{id}/delete', fn (Request $r) => $mailController->deleteDraft($r), [new CsrfMiddleware()]);

            $router->get('/attachments/{id}', fn (Request $r) => $mailController->downloadAttachment($r));
            $router->post('/attachments/{id}/delete', fn (Request $r) => $mailController->deleteAttachment($r), [new CsrfMiddleware()]);

            $router->get('/thread/{id}', fn (Request $r) => $mailController->thread($r));
            $router->post('/thread/{id}/reply', fn (Request $r) => $mailController->reply($r), [new CsrfMiddleware()]);
            $router->get('/forward/{id}', fn (Request $r) => $mailController->showForward($r));
            $router->post('/forward/{id}', fn (Request $r) => $mailController->forward($r), [new CsrfMiddleware()]);

            $router->post('/inbox/{id}/delete', fn (Request $r) => $mailController->deleteFromInbox($r), [new CsrfMiddleware()]);
            $router->post('/inbox/{id}/restore', fn (Request $r) => $mailController->restoreToInbox($r), [new CsrfMiddleware()]);
            $router->post('/sent/{id}/delete', fn (Request $r) => $mailController->deleteFromSent($r), [new CsrfMiddleware()]);
            $router->post('/sent/{id}/restore', fn (Request $r) => $mailController->restoreToSent($r), [new CsrfMiddleware()]);

            $router->get('/broadcast', fn (Request $r) => $broadcastController->index($r), [new RbacMiddleware('mail.broadcast_team')]);
            $router->post('/broadcast/team', fn (Request $r) => $broadcastController->sendToTeam($r), [new CsrfMiddleware()]);
            $router->post('/broadcast/customers', fn (Request $r) => $broadcastController->sendToCustomers($r), [new CsrfMiddleware()]);
        });
    }

    public function migrationsPath(): string
    {
        return __DIR__ . '/Migrations';
    }
}
