<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
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
use App\Services\Translation\TranslationService;
use App\Support\AppFilePaths;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use App\Services\ProposalTemplates\ProposalTemplateService;

class ProposalTemplateController extends Controller
{
    private function proposalTemplateService(): ProposalTemplateService
    {
        return app(ProposalTemplateService::class);
    }

    public function indexTraining(Request $request)
    {
        return $this->proposalTemplateService()->indexTraining($request);
    }

    public function storeTraining(StoreTrainingProposalRequest $request)
    {
        return $this->proposalTemplateService()->storeTraining($request);
    }

    public function updateTraining(UpdateTrainingProposalRequest $request, int $id)
    {
        return $this->proposalTemplateService()->updateTraining($request, $id);
    }

    public function destroyTraining(Request $request, int $id)
    {
        return $this->proposalTemplateService()->destroyTraining($request, $id);
    }

    public function pdfTraining(Request $request, int $id)
    {
        return $this->proposalTemplateService()->pdfTraining($request, $id);
    }

    public function indexManpower(Request $request)
    {
        return $this->proposalTemplateService()->indexManpower($request);
    }

    public function storeManpower(StoreManpowerProposalRequest $request)
    {
        return $this->proposalTemplateService()->storeManpower($request);
    }

    public function updateManpower(UpdateManpowerProposalRequest $request, int $id)
    {
        return $this->proposalTemplateService()->updateManpower($request, $id);
    }

    public function destroyManpower(Request $request, int $id)
    {
        return $this->proposalTemplateService()->destroyManpower($request, $id);
    }

    public function pdfManpower(Request $request, int $id)
    {
        return $this->proposalTemplateService()->pdfManpower($request, $id);
    }

    public function listManpower(Request $request)
    {
        return $this->proposalTemplateService()->listManpower($request);
    }

    public function indexIh(Request $request)
    {
        return $this->proposalTemplateService()->indexIh($request);
    }

    public function storeIh(StoreIhProposalRequest $request)
    {
        return $this->proposalTemplateService()->storeIh($request);
    }

    public function updateIh(UpdateIhProposalRequest $request, int $id)
    {
        return $this->proposalTemplateService()->updateIh($request, $id);
    }

    public function destroyIh(Request $request, int $id)
    {
        return $this->proposalTemplateService()->destroyIh($request, $id);
    }

    public function pdfIh(Request $request, int $id)
    {
        return $this->proposalTemplateService()->pdfIh($request, $id);
    }

    public function listIh(Request $request)
    {
        return $this->proposalTemplateService()->listIh($request);
    }

    public function indexSpecial(Request $request)
    {
        return $this->proposalTemplateService()->indexSpecial($request);
    }

    public function storeSpecial(StoreSpecialProposalRequest $request)
    {
        return $this->proposalTemplateService()->storeSpecial($request);
    }

    public function updateSpecial(UpdateSpecialProposalRequest $request, int $id)
    {
        return $this->proposalTemplateService()->updateSpecial($request, $id);
    }

    public function destroySpecial(Request $request, int $id)
    {
        return $this->proposalTemplateService()->destroySpecial($request, $id);
    }

    public function pdfSpecial(Request $request, int $id)
    {
        return $this->proposalTemplateService()->pdfSpecial($request, $id);
    }

    public function listSpecial(Request $request)
    {
        return $this->proposalTemplateService()->listSpecial($request);
    }

    public function createBmCopy(Request $request, string $type, int $id)
    {
        return $this->proposalTemplateService()->createBmCopy($request, $type, $id);
    }
}
