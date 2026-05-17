<?php

namespace App\Services\ProposalTemplates;

use App\Http\Requests\ProposalTemplate\StoreManpowerProposalRequest;
use App\Http\Requests\ProposalTemplate\UpdateManpowerProposalRequest;
use Illuminate\Http\Request;

class ManpowerProposalTemplateService
{
    private function manpowerProposalTemplateCrudService(): ManpowerProposalTemplateCrudService
    {
        return app(ManpowerProposalTemplateCrudService::class);
    }

    private function manpowerProposalTemplatePdfService(): ManpowerProposalTemplatePdfService
    {
        return app(ManpowerProposalTemplatePdfService::class);
    }

    public function indexManpower(Request $request)
    {
        return $this->manpowerProposalTemplateCrudService()->indexManpower($request);
    }

    public function storeManpower(StoreManpowerProposalRequest $request)
    {
        return $this->manpowerProposalTemplateCrudService()->storeManpower($request);
    }

    public function updateManpower(UpdateManpowerProposalRequest $request, int $id)
    {
        return $this->manpowerProposalTemplateCrudService()->updateManpower($request, $id);
    }

    public function destroyManpower(Request $request, int $id)
    {
        return $this->manpowerProposalTemplateCrudService()->destroyManpower($request, $id);
    }

    public function pdfManpower(Request $request, int $id)
    {
        return $this->manpowerProposalTemplatePdfService()->pdfManpower($request, $id);
    }

    public function listManpower(Request $request)
    {
        return $this->manpowerProposalTemplateCrudService()->listManpower($request);
    }
}
