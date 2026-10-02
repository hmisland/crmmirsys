<?php

declare(strict_types=1);

namespace Modules\Finance\Controllers;

use App\Application\Controllers\BaseController;
use App\Core\Exceptions\ValidationException;
use App\Core\Request;
use App\Core\Response;
use App\Infrastructure\Repositories\CountryRepository;
use App\Infrastructure\Repositories\CurrencyRepository;
use App\Infrastructure\Repositories\TaxJurisdictionRepository;
use App\Infrastructure\Security\CurrentUser;
use App\Infrastructure\Security\Session;
use App\Infrastructure\Theme\TemplateEngine;
use Modules\Finance\Services\FinancialProfileService;
use Modules\Finance\TaxProfileRepository;

final class FinanceSettingsController extends BaseController
{
    public function __construct(
        TemplateEngine $templates,
        private readonly FinancialProfileService $profiles,
        private readonly CountryRepository $countries,
        private readonly TaxJurisdictionRepository $jurisdictions,
        private readonly CurrencyRepository $currencies,
        private readonly TaxProfileRepository $taxProfiles,
    ) {
        parent::__construct($templates);
    }

    public function show(Request $request): Response
    {
        return $this->viewWithLayout('finance/settings', 'layout', [
            'profile' => $this->profiles->get(CurrentUser::user()),
            'countries' => $this->countries->all(),
            'jurisdictions' => $this->jurisdictions->all(),
            'currencies' => $this->currencies->all(),
            'taxProfiles' => $this->taxProfiles->availableFor((int) CurrentUser::user()['organization_id']),
            'errors' => [],
        ]);
    }

    public function update(Request $request): Response
    {
        try {
            $this->profiles->update(CurrentUser::user(), $request->all());
            Session::setFlash('success', 'Financial profile updated.');
        } catch (ValidationException $e) {
            return $this->viewWithLayout('finance/settings', 'layout', [
                'profile' => array_merge($this->profiles->get(CurrentUser::user()), $request->all()),
                'countries' => $this->countries->all(),
                'jurisdictions' => $this->jurisdictions->all(),
                'currencies' => $this->currencies->all(),
                'taxProfiles' => $this->taxProfiles->availableFor((int) CurrentUser::user()['organization_id']),
                'errors' => $e->errors,
            ], 422);
        }

        return $this->redirect('/finance/settings');
    }
}
