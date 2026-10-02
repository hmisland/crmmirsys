<?php

declare(strict_types=1);

namespace Modules\Sales\Controllers;

use App\Application\Controllers\BaseController;
use App\Core\Exceptions\ValidationException;
use App\Core\Request;
use App\Core\Response;
use App\Infrastructure\Security\CurrentUser;
use App\Infrastructure\Security\Session;
use App\Infrastructure\Theme\TemplateEngine;
use Modules\Sales\Services\PipelineService;

final class PipelineController extends BaseController
{
    public function __construct(TemplateEngine $templates, private readonly PipelineService $pipelines)
    {
        parent::__construct($templates);
    }

    public function index(Request $request): Response
    {
        $pipeline = $this->pipelines->ensureDefaultPipeline();

        return $this->viewWithLayout('sales/pipeline-settings', 'layout', [
            'pipelines' => $this->pipelines->all(),
            'pipeline' => $pipeline,
            'stages' => $this->pipelines->stagesFor((int) $pipeline['id']),
        ]);
    }

    public function addStage(Request $request): Response
    {
        $pipelineId = (int) $request->input('pipeline_id');

        try {
            $this->pipelines->addStage(
                CurrentUser::user(),
                $pipelineId,
                (string) $request->input('name', ''),
                (int) $request->input('probability', 0),
                $request->input('is_won') === '1',
                $request->input('is_lost') === '1'
            );
            Session::setFlash('success', 'Stage added.');
        } catch (ValidationException $e) {
            Session::setFlash('error', array_values($e->errors)[0][0] ?? 'Could not add stage.');
        }

        return $this->redirect('/sales/pipeline');
    }
}
