<?php

declare(strict_types=1);

namespace Modules\Finance\Controllers;

use App\Application\Controllers\BaseController;
use App\Core\Request;
use App\Core\Response;
use App\Infrastructure\Localization\Locale;
use App\Infrastructure\Localization\LocaleFormatter;
use App\Infrastructure\Security\CurrentUser;
use App\Infrastructure\Theme\TemplateEngine;
use Modules\Finance\Services\ReportService;

final class ReportController extends BaseController
{
    public function __construct(
        TemplateEngine $templates,
        private readonly ReportService $reports,
    ) {
        parent::__construct($templates);
    }

    public function index(Request $request): Response
    {
        $startDate = (string) $request->query('start_date', date('Y-m-01'));
        $endDate = (string) $request->query('end_date', \App\Infrastructure\Time\OrganizationTimezone::shared()->today());

        $translator = Locale::get();

        return $this->viewWithLayout('finance/reports/index', 'layout', [
            'summary' => $this->reports->summary(CurrentUser::user(), $startDate, $endDate),
            'fmt' => new LocaleFormatter($translator !== null ? $translator->locale() : 'en'),
        ]);
    }
}
