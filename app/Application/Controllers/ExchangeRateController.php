<?php

declare(strict_types=1);

namespace App\Application\Controllers;

use App\Application\Services\ExchangeRateService;
use App\Core\Exceptions\AuthorizationException;
use App\Core\Exceptions\ValidationException;
use App\Core\Request;
use App\Core\Response;
use App\Infrastructure\Repositories\CurrencyRepository;
use App\Infrastructure\Security\CurrentUser;
use App\Infrastructure\Security\Session;
use App\Infrastructure\Theme\TemplateEngine;

final class ExchangeRateController extends BaseController
{
    public function __construct(
        TemplateEngine $templates,
        private readonly ExchangeRateService $rates,
        private readonly CurrencyRepository $currencies,
    ) {
        parent::__construct($templates);
    }

    public function index(Request $request): Response
    {
        $this->requireSuperAdmin();

        return $this->viewWithLayout('admin/exchange-rates', 'layout', [
            'rates' => $this->rates->currentSummary(),
            'currencies' => $this->currencies->all(),
        ]);
    }

    public function update(Request $request): Response
    {
        $this->requireSuperAdmin();

        try {
            $this->rates->setRate(
                CurrentUser::user(),
                (int) $request->input('source_currency_id', 0),
                (int) $request->input('destination_currency_id', 0),
                (string) $request->input('rate', '')
            );
            Session::setFlash('success', 'Exchange rate updated.');
        } catch (ValidationException $e) {
            Session::setFlash('error', array_values($e->errors)[0][0] ?? 'Could not update the rate.');
        }

        return $this->redirect('/admin/exchange-rates');
    }

    private function requireSuperAdmin(): void
    {
        $user = CurrentUser::user();

        if ($user === null || empty($user['is_super_admin'])) {
            throw new AuthorizationException();
        }
    }
}
