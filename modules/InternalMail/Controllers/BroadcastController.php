<?php

declare(strict_types=1);

namespace Modules\InternalMail\Controllers;

use App\Application\Controllers\BaseController;
use App\Core\Exceptions\ValidationException;
use App\Core\Request;
use App\Core\Response;
use App\Infrastructure\Security\CurrentUser;
use App\Infrastructure\Security\Session;
use App\Infrastructure\Theme\TemplateEngine;
use Modules\InternalMail\Services\BroadcastService;

final class BroadcastController extends BaseController
{
    public function __construct(TemplateEngine $templates, private readonly BroadcastService $broadcast)
    {
        parent::__construct($templates);
    }

    public function index(Request $request): Response
    {
        return $this->viewWithLayout('mail/broadcast', 'layout', [
            'recentBroadcasts' => $this->broadcast->recentBroadcasts(),
        ]);
    }

    public function sendToTeam(Request $request): Response
    {
        try {
            $this->broadcast->broadcastToTeam(CurrentUser::user(), (string) $request->input('subject', ''), (string) $request->input('body', ''));
            Session::setFlash('success', 'Message sent to your whole team.');
        } catch (ValidationException $e) {
            Session::setFlash('error', array_values($e->errors)[0][0] ?? 'Could not send.');
        }

        return $this->redirect('/mail/broadcast');
    }

    public function sendToCustomers(Request $request): Response
    {
        try {
            $result = $this->broadcast->broadcastToCustomers(CurrentUser::user(), (string) $request->input('subject', ''), (string) $request->input('body', ''));
            $message = "Sent to {$result['recipient_count']} customer(s).";
            if ($result['failed_count'] > 0) {
                $message .= " {$result['failed_count']} failed to send.";
            }
            Session::setFlash('success', $message);
        } catch (ValidationException $e) {
            Session::setFlash('error', array_values($e->errors)[0][0] ?? 'Could not send.');
        }

        return $this->redirect('/mail/broadcast');
    }
}
