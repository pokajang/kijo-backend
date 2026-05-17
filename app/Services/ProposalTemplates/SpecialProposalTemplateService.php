<?php

namespace App\Services\ProposalTemplates;

use App\Http\Requests\ProposalTemplate\StoreSpecialProposalRequest;
use App\Http\Requests\ProposalTemplate\UpdateSpecialProposalRequest;
use Illuminate\Http\Request;

class SpecialProposalTemplateService
{
    private function specialProposalTemplateCrudService(): SpecialProposalTemplateCrudService
    {
        return app(SpecialProposalTemplateCrudService::class);
    }

    private function specialProposalTemplatePdfService(): SpecialProposalTemplatePdfService
    {
        return app(SpecialProposalTemplatePdfService::class);
    }

    public function indexSpecial(Request $request)
    {
        return $this->specialProposalTemplateCrudService()->indexSpecial($request);
    }

    public function storeSpecial(StoreSpecialProposalRequest $request)
    {
        return $this->specialProposalTemplateCrudService()->storeSpecial($request);
    }

    public function updateSpecial(UpdateSpecialProposalRequest $request, int $id)
    {
        return $this->specialProposalTemplateCrudService()->updateSpecial($request, $id);
    }

    public function destroySpecial(Request $request, int $id)
    {
        return $this->specialProposalTemplateCrudService()->destroySpecial($request, $id);
    }

    public function pdfSpecial(Request $request, int $id)
    {
        return $this->specialProposalTemplatePdfService()->pdfSpecial($request, $id);
    }

    public function listSpecial(Request $request)
    {
        return $this->specialProposalTemplateCrudService()->listSpecial($request);
    }
}
