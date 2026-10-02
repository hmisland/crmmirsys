<?php

declare(strict_types=1);

namespace App\Application\Controllers;

use App\Core\Container;
use App\Core\Request;
use App\Core\Response;
use App\Infrastructure\Repositories\CompanyRepository;
use App\Infrastructure\Repositories\ContactRepository;
use App\Infrastructure\Repositories\CurrencyRepository;
use App\Infrastructure\Security\CurrentUser;
use App\Infrastructure\Theme\TemplateEngine;

/**
 * The real landing page, replacing the Phase 1 "Foundation is running"
 * scaffold that was still being served at `/` up until now. Reads
 * Wallet/Sales(Tasks)/Internal Mail data only through the same lazy,
 * non-hard-dependency container lookup already proven for
 * Notification's cross-module trigger — Core never references those
 * modules' classes directly outside this one soft-checked spot, and
 * each widget simply doesn't render if its module isn't installed.
 */
final class DashboardController extends BaseController
{
    public function __construct(
        TemplateEngine $templates,
        private readonly Container $container,
        private readonly ContactRepository $contacts,
        private readonly CompanyRepository $companies,
        private readonly CurrencyRepository $currencies,
    ) {
        parent::__construct($templates);
    }

    public function index(Request $request): Response
    {
        $user = CurrentUser::user();

        if ($user === null) {
            return $this->redirect('/login');
        }

        if ($user['organization_id'] === null) {
            // Super Admin has no organization — nothing on this
            // dashboard (contacts/wallet/tasks/mail) is meaningful for
            // them, the same reasoning already applied to their
            // Profile page. Their real landing area is the platform
            // admin list.
            return $this->redirect('/admin/organizations');
        }

        return $this->viewWithLayout('dashboard', 'layout', [
            'contactsCount' => $this->contacts->countAll(),
            'companiesCount' => $this->companies->countAll(),
            'wallet' => $this->walletWidget($user),
            'tasks' => $this->tasksWidget($user),
            'unreadMailCount' => $this->unreadMailCount($user),
            'openTicketsCount' => $this->openTicketsCount($user),
            'productsCount' => $this->financeCount($user, 'Modules\\Finance\\Services\\ProductService'),
            'proformasCount' => $this->financeCount($user, 'Modules\\Finance\\Services\\ProformaService'),
            'invoicesCount' => $this->financeCount($user, 'Modules\\Finance\\Services\\InvoiceService'),
            'topFollowUps' => $this->topFollowUps($user),
        ]);
    }

    /** @return array{balance: string, currency_code: string, currency_symbol: string}|null null when Wallet isn't installed */
    private function walletWidget(array $user): ?array
    {
        $walletServiceClass = 'Modules\\Wallet\\Services\\WalletService';
        $walletSettingsClass = 'Modules\\Wallet\\Services\\WalletSettingsService';

        if (!$this->container->has($walletServiceClass) || !$this->container->has($walletSettingsClass)) {
            return null;
        }

        $walletService = $this->container->make($walletServiceClass);
        $walletSettings = $this->container->make($walletSettingsClass);

        $organizationId = (int) $user['organization_id'];
        $currencyId = (int) $walletSettings->effectiveTopupLimits($organizationId)['currency_id'];
        $wallet = $walletService->getOrCreateWallet($organizationId, (int) $user['id'], $currencyId);
        $currency = $this->currencies->findById($currencyId);

        return [
            'balance' => (string) $wallet['balance'],
            'currency_code' => (string) ($currency['code'] ?? ''),
            'currency_symbol' => (string) ($currency['symbol'] ?? ''),
        ];
    }

    /** @return array{overdue: array, due_today: array, upcoming: array}|null null when Sales isn't installed */
    private function tasksWidget(array $user): ?array
    {
        $taskServiceClass = 'Modules\\Sales\\Services\\TaskService';

        if (!$this->container->has($taskServiceClass)) {
            return null;
        }

        $taskService = $this->container->make($taskServiceClass);
        $rows = $taskService->upcomingForUser((int) $user['id']);

        $today = date('Y-m-d');
        $overdue = [];
        $dueToday = [];
        $upcoming = [];

        foreach ($rows as $row) {
            $dueDate = $row['due_at'] !== null ? substr((string) $row['due_at'], 0, 10) : null;

            if ($dueDate !== null && $dueDate < $today) {
                $overdue[] = $row;
            } elseif ($dueDate === $today) {
                $dueToday[] = $row;
            } else {
                $upcoming[] = $row;
            }
        }

        return ['overdue' => $overdue, 'due_today' => $dueToday, 'upcoming' => $upcoming];
    }

    private function unreadMailCount(array $user): ?int
    {
        $messageServiceClass = 'Modules\\InternalMail\\Services\\MessageService';

        if (!$this->container->has($messageServiceClass)) {
            return null;
        }

        return $this->container->make($messageServiceClass)->unreadCount($user);
    }

    /** null when Support isn't installed. "Needing a response" = assigned to this user, still open/pending. */
    private function openTicketsCount(array $user): ?int
    {
        $ticketServiceClass = 'Modules\\Support\\Services\\TicketService';

        if (!$this->container->has($ticketServiceClass)) {
            return null;
        }

        return count($this->container->make($ticketServiceClass)->openAssignedToUser($user));
    }

    /** null when Followups isn't installed — same degrade-quietly pattern as every other optional tile. */
    private function topFollowUps(array $user): ?array
    {
        $followUpServiceClass = 'Modules\\Followups\\Services\\FollowUpService';

        if (!$this->container->has($followUpServiceClass)) {
            return null;
        }

        return $this->container->make($followUpServiceClass)->topForDashboard($user, 5);
    }

    /**
     * Shared by the three Finance count tiles. Each service's own
     * list() already applies the correct OWN/ORGANIZATION scope, so
     * an org_admin naturally gets the organization-wide total and a
     * member gets their own — no separate "is this an admin" check
     * needed here.
     */
    private function financeCount(array $user, string $serviceClass): ?int
    {
        if (!$this->container->has($serviceClass)) {
            return null;
        }

        return count($this->container->make($serviceClass)->list($user));
    }
}
