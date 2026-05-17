<?php

namespace App\Services\ProposalTemplates;

use App\Http\Requests\ProposalTemplate\StoreTrainingProposalRequest;
use App\Http\Requests\ProposalTemplate\UpdateTrainingProposalRequest;
use App\Http\Requests\ProposalTemplate\StoreManpowerProposalRequest;
use App\Http\Requests\ProposalTemplate\UpdateManpowerProposalRequest;
use App\Http\Requests\ProposalTemplate\StoreIhProposalRequest;
use App\Http\Requests\ProposalTemplate\UpdateIhProposalRequest;
use App\Http\Requests\ProposalTemplate\StoreSpecialProposalRequest;
use App\Http\Requests\ProposalTemplate\UpdateSpecialProposalRequest;
use Illuminate\Http\Request;

class ProposalTemplateService
{
    private function trainingProposalTemplateService(): TrainingProposalTemplateService
    {
        return app(TrainingProposalTemplateService::class);
    }

    private function manpowerProposalTemplateService(): ManpowerProposalTemplateService
    {
        return app(ManpowerProposalTemplateService::class);
    }

    private function ihProposalTemplateService(): IhProposalTemplateService
    {
        return app(IhProposalTemplateService::class);
    }

    private function specialProposalTemplateService(): SpecialProposalTemplateService
    {
        return app(SpecialProposalTemplateService::class);
    }

    private function bmProposalTemplateService(): BmProposalTemplateService
    {
        return app(BmProposalTemplateService::class);
    }

    public function indexTraining(Request $request)
    {
        return $this->trainingProposalTemplateService()->indexTraining($request);
    }

    public function storeTraining(StoreTrainingProposalRequest $request)
    {
        return $this->trainingProposalTemplateService()->storeTraining($request);
    }

    public function updateTraining(UpdateTrainingProposalRequest $request, int $id)
    {
        return $this->trainingProposalTemplateService()->updateTraining($request, $id);
    }

    public function destroyTraining(Request $request, int $id)
    {
        return $this->trainingProposalTemplateService()->destroyTraining($request, $id);
    }

    public function pdfTraining(Request $request, int $id)
    {
        return $this->trainingProposalTemplateService()->pdfTraining($request, $id);
    }

    public function indexManpower(Request $request)
    {
        return $this->manpowerProposalTemplateService()->indexManpower($request);
    }

    public function storeManpower(StoreManpowerProposalRequest $request)
    {
        return $this->manpowerProposalTemplateService()->storeManpower($request);
    }

    public function updateManpower(UpdateManpowerProposalRequest $request, int $id)
    {
        return $this->manpowerProposalTemplateService()->updateManpower($request, $id);
    }

    public function destroyManpower(Request $request, int $id)
    {
        return $this->manpowerProposalTemplateService()->destroyManpower($request, $id);
    }

    public function pdfManpower(Request $request, int $id)
    {
        return $this->manpowerProposalTemplateService()->pdfManpower($request, $id);
    }

    public function listManpower(Request $request)
    {
        return $this->manpowerProposalTemplateService()->listManpower($request);
    }

    public function indexIh(Request $request)
    {
        return $this->ihProposalTemplateService()->indexIh($request);
    }

    public function storeIh(StoreIhProposalRequest $request)
    {
        return $this->ihProposalTemplateService()->storeIh($request);
    }

    public function updateIh(UpdateIhProposalRequest $request, int $id)
    {
        return $this->ihProposalTemplateService()->updateIh($request, $id);
    }

    public function destroyIh(Request $request, int $id)
    {
        return $this->ihProposalTemplateService()->destroyIh($request, $id);
    }

    public function pdfIh(Request $request, int $id)
    {
        return $this->ihProposalTemplateService()->pdfIh($request, $id);
    }

    public function listIh(Request $request)
    {
        return $this->ihProposalTemplateService()->listIh($request);
    }

    public function indexSpecial(Request $request)
    {
        return $this->specialProposalTemplateService()->indexSpecial($request);
    }

    public function storeSpecial(StoreSpecialProposalRequest $request)
    {
        return $this->specialProposalTemplateService()->storeSpecial($request);
    }

    public function updateSpecial(UpdateSpecialProposalRequest $request, int $id)
    {
        return $this->specialProposalTemplateService()->updateSpecial($request, $id);
    }

    public function destroySpecial(Request $request, int $id)
    {
        return $this->specialProposalTemplateService()->destroySpecial($request, $id);
    }

    public function pdfSpecial(Request $request, int $id)
    {
        return $this->specialProposalTemplateService()->pdfSpecial($request, $id);
    }

    public function listSpecial(Request $request)
    {
        return $this->specialProposalTemplateService()->listSpecial($request);
    }

    public function createBmCopy(Request $request, string $type, int $id)
    {
        return $this->bmProposalTemplateService()->createBmCopy($request, $type, $id);
    }
}
