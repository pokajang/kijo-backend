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
use App\Services\AuditLogService;
use App\Services\Translation\TranslationException;
use App\Support\AppFilePaths;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class SpecialProposalTemplateCrudService
{
    private function specialProposalTemplateReadService(): SpecialProposalTemplateReadService
    {
        return app(SpecialProposalTemplateReadService::class);
    }

    public function indexSpecial(Request $request)
    {
        return $this->specialProposalTemplateReadService()->indexSpecial($request);
    }

    public function listSpecial(Request $request)
    {
        return $this->specialProposalTemplateReadService()->listSpecial($request);
    }

    private function specialProposalTemplateMutationService(): SpecialProposalTemplateMutationService
    {
        return app(SpecialProposalTemplateMutationService::class);
    }

    public function storeSpecial(StoreSpecialProposalRequest $request)
    {
        return $this->specialProposalTemplateMutationService()->storeSpecial($request);
    }

    public function updateSpecial(UpdateSpecialProposalRequest $request, int $id)
    {
        return $this->specialProposalTemplateMutationService()->updateSpecial($request, $id);
    }

    public function destroySpecial(Request $request, int $id)
    {
        return $this->specialProposalTemplateMutationService()->destroySpecial($request, $id);
    }
}
