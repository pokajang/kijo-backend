<?php

namespace App\Services\ProposalTemplates;

use App\Http\Requests\ProposalTemplate\StoreIhProposalRequest;
use App\Http\Requests\ProposalTemplate\UpdateIhProposalRequest;
use Illuminate\Http\Request;

class IhProposalTemplateService
{
    private function ihProposalTemplateCrudService(): IhProposalTemplateCrudService
    {
        return app(IhProposalTemplateCrudService::class);
    }

    private function ihProposalTemplatePdfService(): IhProposalTemplatePdfService
    {
        return app(IhProposalTemplatePdfService::class);
    }

    public function indexIh(Request $request)
    {
        return $this->ihProposalTemplateCrudService()->indexIh($request);
    }

    public function storeIh(StoreIhProposalRequest $request)
    {
        return $this->ihProposalTemplateCrudService()->storeIh($request);
    }

    public function updateIh(UpdateIhProposalRequest $request, int $id)
    {
        return $this->ihProposalTemplateCrudService()->updateIh($request, $id);
    }

    public function destroyIh(Request $request, int $id)
    {
        return $this->ihProposalTemplateCrudService()->destroyIh($request, $id);
    }

    public function pdfIh(Request $request, int $id)
    {
        return $this->ihProposalTemplatePdfService()->pdfIh($request, $id);
    }

    public function listIh(Request $request)
    {
        return $this->ihProposalTemplateCrudService()->listIh($request);
    }
}
