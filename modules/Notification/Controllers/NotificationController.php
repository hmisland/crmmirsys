<?php

declare(strict_types=1);

namespace Modules\Notification\Controllers;

use App\Application\Controllers\BaseController;
use App\Core\Exceptions\ValidationException;
use App\Core\Request;
use App\Core\Response;
use App\Infrastructure\Config\Config;
use App\Infrastructure\Logging\LoggerInterface;
use App\Infrastructure\Security\CurrentUser;
use App\Infrastructure\Security\Session;
use App\Infrastructure\Theme\TemplateEngine;
use Modules\Notification\Services\NotificationCreditService;
use Modules\Notification\Services\NotificationDispatchService;
use Modules\Notification\Services\NotificationRecipientService;

final class NotificationController extends BaseController
{
    public function __construct(
        TemplateEngine $templates,
        private readonly NotificationRecipientService $recipients,
        private readonly NotificationCreditService $credits,
        private readonly NotificationDispatchService $dispatch,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct($templates);
    }

    public function showSettings(Request $request): Response
    {
        // This page's own UI now lives on /profile — kept only so old
        // bookmarks/links don't dead-end.
        return $this->redirect('/profile');
    }

    public function saveSettings(Request $request): Response
    {
        $this->recipients->save(
            (int) CurrentUser::id(),
            (string) $request->input('bale_chat_id', ''),
            (string) $request->input('telegram_chat_id', '')
        );
        Session::setFlash('success', 'Notification channels saved.');

        return $this->redirect('/profile');
    }

    public function testBale(Request $request): Response
    {
        $destination = trim((string) $request->input('bale_chat_id', ''));

        if ($destination === '') {
            Session::setFlash('error', 'Enter a Bale chat ID or @username first, then save, then test.');

            return $this->redirect('/profile');
        }

        $result = $this->dispatch->sendTestBale($destination, (string) Config::get('app.name', 'CRM'));

        if ($result->success) {
            $this->logger->info('Bale test message sent', ['destination' => $destination, 'user_id' => CurrentUser::id()]);
        } else {
            $this->logger->warning('Bale test message rejected', ['destination' => $destination, 'error' => $result->errorMessage, 'user_id' => CurrentUser::id()]);
        }

        Session::setFlash(
            $result->success ? 'success' : 'error',
            $result->success ? 'Test message sent — check Bale.' : ('Bale rejected it: ' . ($result->errorMessage ?? 'unknown error'))
        );

        return $this->redirect('/profile');
    }

    public function admin(Request $request): Response
    {
        $organizationId = (int) CurrentUser::user()['organization_id'];

        return $this->viewWithLayout('notification/admin', 'layout', [
            'balance' => $this->credits->balance($organizationId),
            'transactions' => $this->credits->recentTransactions(),
            'logs' => $this->dispatch->recentLogs(),
        ]);
    }

    public function charge(Request $request): Response
    {
        try {
            $this->credits->charge(CurrentUser::user(), (string) $request->input('amount', ''));
            Session::setFlash('success', 'Notification credit charged from your wallet.');
        } catch (ValidationException $e) {
            Session::setFlash('error', array_values($e->errors)[0][0] ?? 'Could not charge.');
        }

        return $this->redirect('/notifications/admin');
    }
}
