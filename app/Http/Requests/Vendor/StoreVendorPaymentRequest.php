<?php

namespace App\Http\Requests\Vendor;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Validator;

class StoreVendorPaymentRequest extends FormRequest
{
    private const STAFF_PROJECT_ROLES = ['leader', 'pic', 'owner', 'assistant', 'collaborator'];

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'vendor_id' => ['required', 'integer', 'min:1'],
            'project_id' => ['nullable', 'integer', 'min:1'],
            'payment_context' => ['required', 'string', 'max:100'],
            'payment_type' => ['required', 'string', 'max:100'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'method' => ['required', 'string', 'max:100'],
            'remarks' => ['nullable', 'string', 'max:2000'],
            'receipt' => ['nullable', 'file', 'mimes:pdf,jpeg,jpg,png', 'max:5120'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $context = strtolower(trim((string) $this->input('payment_context', '')));
            $vendorId = (int) $this->input('vendor_id', 0);

            if ($vendorId > 0 && Schema::hasTable('vendor_main_details')) {
                $vendorQuery = DB::table('vendor_main_details')->where('vendor_id', $vendorId);

                if (Schema::hasColumn('vendor_main_details', 'deleted_at')) {
                    $vendorQuery->whereNull('deleted_at');
                }

                if (Schema::hasColumn('vendor_main_details', 'status')) {
                    $vendorQuery->where(function ($query): void {
                        $query
                            ->whereNull('status')
                            ->orWhereRaw("LOWER(TRIM(COALESCE(status, ''))) = 'active'");
                    });
                }

                if (! $vendorQuery->exists()) {
                    $validator->errors()->add('vendor_id', 'Selected vendor is not active.');

                    return;
                }
            }

            if ($context !== 'project') {
                return;
            }

            $projectId = (int) $this->input('project_id', 0);

            if ($projectId < 1) {
                $validator->errors()->add('project_id', 'Project is required for project-related vendor payments.');

                return;
            }

            if ($vendorId < 1) {
                return;
            }

            $staffId = (int) $this->session()->get('staff_id', 0);
            $isLinkedProject = $staffId > 0 && DB::table('project_collaborators')
                ->where('project_id', $projectId)
                ->where('staff_id', $staffId)
                ->whereIn(DB::raw("LOWER(TRIM(COALESCE(project_role, '')))"), self::STAFF_PROJECT_ROLES)
                ->exists();

            if (! $isLinkedProject) {
                $validator->errors()->add('project_id', 'Selected project is not linked to your account.');

                return;
            }

            $isAssignedVendor = DB::table('project_vendors')
                ->where('project_id', $projectId)
                ->where('vendor_id', $vendorId)
                ->exists();

            if (! $isAssignedVendor) {
                $validator->errors()->add('vendor_id', 'Selected vendor is not assigned to this project.');
            }
        });
    }
}
