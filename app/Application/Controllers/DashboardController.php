<?php

declare(strict_types=1);

namespace App\Application\Controllers;

use App\Core\Container;
use App\Core\Exceptions\AuthorizationException;
use App\Core\Request;
use App\Core\Response;
use App\Infrastructure\Authorization\AuthorizationEngine;
use App\Infrastructure\Modules\ModuleAccessResolver;
use App\Infrastructure\Repositories\CompanyRepository;
use App\Infrastructure\Repositories\ContactRepository;
use App\Infrastructure\Repositories\CurrencyRepository;
use App\Infrastructure\Repositories\ModuleRepository;
use App\Infrastructure\Repositories\OrganizationModuleRepository;
use App\Infrastructure\Repositories\UserModuleOverrideRepository;
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
    private ?ModuleAccessResolver $moduleAccess = null;

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
            // Visitors see the public landing page (public/index.html,
            // with its own login / sign-up buttons); logged-in users get
            // the dashboard at the same address.
            $landing = dirname(__DIR__, 3) . '/public/index.html';

            return is_file($landing) ? Response::html((string) file_get_contents($landing)) : $this->redirect('/login');
        }

        if ($user['organization_id'] === null) {
            // Super Admin has no organization — nothing on this
            // dashboard (contacts/wallet/tasks/mail) is meaningful for
            // them, the same reasoning already applied to their
            // Profile page. Their real landing area is the platform
            // admin list.
            return $this->redirect('/admin/organizations');
        }

        $authorization = $this->container->make(AuthorizationEngine::class);

        return $this->viewWithLayout('dashboard', 'layout', [
            'contactsCount' => $authorization->can($user, 'contacts.view') ? $this->contacts->countAll() : null,
            'companiesCount' => $authorization->can($user, 'companies.view') ? $this->companies->countAll() : null,
            'wallet' => $this->walletWidget($user),
            'tasks' => $this->tasksWidget($user),
            'unreadMailCount' => $this->unreadMailCount($user),
            'openTicketsCount' => $this->openTicketsCount($user),
            'productsCount' => $this->financeCount($user, 'products.view', 'Modules\\Finance\\Services\\ProductService'),
            'proformasCount' => $this->financeCount($user, 'quotes.view', 'Modules\\Finance\\Services\\ProformaService'),
            'invoicesCount' => $this->financeCount($user, 'invoices.view', 'Modules\\Finance\\Services\\InvoiceService'),
            'topFollowUps' => $this->topFollowUps($user),
        ]);
    }

    /** @return array{balance: string, currency_code: string, currency_symbol: string}|null null when Wallet isn't available to this user */
    private function walletWidget(array $user): ?array
    {
        $walletSettingsClass = 'Modules\\Wallet\\Services\\WalletSettingsService';

        if (!$this->container->has($walletSettingsClass)) {
            return null;
        }

        return $this->optionalTile($user, 'wallet', 'wallet.view', 'Modules\\Wallet\\Services\\WalletService', function ($walletService) use ($user, $walletSettingsClass) {
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
        });
    }

    /** @return array{overdue: array, due_today: array, upcoming: array}|null null when Sales isn't available to this user */
    private function tasksWidget(array $user): ?array
    {
        return $this->optionalTile($user, 'sales', 'tasks.view', 'Modules\\Sales\\Services\\TaskService', function ($taskService) use ($user) {
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
        });
    }

    private function unreadMailCount(array $user): ?int
    {
        return $this->optionalTile($user, 'internal_mail', 'mail.view', 'Modules\\InternalMail\\Services\\MessageService',
            fn ($messageService) => $messageService->unreadCount($user));
    }

    /** "Needing a response" = assigned to this user, still open/pending. */
    private function openTicketsCount(array $user): ?int
    {
        return $this->optionalTile($user, 'support', 'tickets.view', 'Modules\\Support\\Services\\TicketService',
            fn ($ticketService) => count($ticketService->openAssignedToUser($user)));
    }

    private function topFollowUps(array $user): ?array
    {
        return $this->optionalTile($user, 'followups', 'followups.view', 'Modules\\Followups\\Services\\FollowUpService',
            fn ($followUpService) => $followUpService->topForDashboard($user, 5));
    }

    /**
     * Shared by the three Finance count tiles. Each service's own
     * list() already applies the correct OWN/ORGANIZATION scope, so
     * an org_admin naturally gets the organization-wide total and a
     * member gets their own — no separate "is this an admin" check
     * needed here.
     */
    private function financeCount(array $user, string $permission, string $serviceClass): ?int
    {
        return $this->optionalTile($user, 'finance', $permission, $serviceClass, fn ($service) => count($service->list($user)));
    }

    /**
     * Every optional tile degrades quietly to null (the template omits
     * it) when its module isn't installed, isn't available to this user
     * (same chain ModuleMiddleware enforces — otherwise the tile would
     * link to a page that 404s), or the user lacks the view permission
     * of the page the tile links to (checked up front, since some
     * services — wallet, mail — don't check it themselves). A dashboard
     * widget must never error the whole page; services keep throwing
     * AuthorizationException as before, it's only caught here as a
     * safety net. Nothing about RBAC itself changes.
     */
    private function optionalTile(array $user, string $moduleKey, string $permission, string $serviceClass, callable $read): mixed
    {
        if (!$this->container->has($serviceClass)) {
            return null;
        }

        if (!$this->container->make(AuthorizationEngine::class)->can($user, $permission)) {
            return null;
        }

        $this->moduleAccess ??= new ModuleAccessResolver(
            new ModuleRepository(),
            new OrganizationModuleRepository(),
            new UserModuleOverrideRepository(),
        );

        if (!$this->moduleAccess->resolvedForUser($moduleKey, $user)) {
            return null;
        }

        try {
            return $read($this->container->make($serviceClass));
        } catch (AuthorizationException) {
            return null;
        }
    }
}
