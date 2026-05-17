<?php

namespace App\Services\ProposalTemplates;

use App\Http\Requests\ProposalTemplate\StoreTrainingProposalRequest;
use App\Http\Requests\ProposalTemplate\UpdateTrainingProposalRequest;
use Illuminate\Http\Request;

class TrainingProposalTemplateService
{
    private function trainingProposalTemplateCrudService(): TrainingProposalTemplateCrudService
    {
        return app(TrainingProposalTemplateCrudService::class);
    }

    private function trainingProposalTemplatePdfService(): TrainingProposalTemplatePdfService
    {
        return app(TrainingProposalTemplatePdfService::class);
    }

    public function indexTraining(Request $request)
    {
        return $this->trainingProposalTemplateCrudService()->indexTraining($request);
    }

    public function storeTraining(StoreTrainingProposalRequest $request)
    {
        return $this->trainingProposalTemplateCrudService()->storeTraining($request);
    }

    public function updateTraining(UpdateTrainingProposalRequest $request, int $id)
    {
        return $this->trainingProposalTemplateCrudService()->updateTraining($request, $id);
    }

    public function destroyTraining(Request $request, int $id)
    {
        return $this->trainingProposalTemplateCrudService()->destroyTraining($request, $id);
    }

    public function pdfTraining(Request $request, int $id)
    {
        return $this->trainingProposalTemplatePdfService()->pdfTraining($request, $id);
    }
}
