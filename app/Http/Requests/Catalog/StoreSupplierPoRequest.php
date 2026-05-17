<?php

namespace App\Http\Requests\Catalog;

use Illuminate\Foundation\Http\FormRequest;

class StoreSupplierPoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'project_id'                       => ['nullable', 'integer', 'min:1'],
            'supplier'                         => ['required', 'array'],
            'supplier.id'                      => ['nullable', 'integer', 'min:1'],
            'supplier.company_name'            => ['required', 'string', 'max:255'],
            'supplier.full_address'            => ['nullable', 'string', 'max:2000'],
            'supplier.contact_name'            => ['nullable', 'string', 'max:255'],
            'supplier.contact_number'          => ['nullable', 'string', 'max:100'],
            'items'                            => ['required', 'array', 'min:1'],
            'items.*.item_id'                  => ['nullable', 'integer', 'min:1'],
            'items.*.item_name'                => ['required', 'string', 'max:255'],
            'items.*.description'              => ['nullable', 'string', 'max:5000'],
            'items.*.unit'                     => ['nullable', 'string', 'max:50'],
            'items.*.quantity'                 => ['required', 'numeric', 'min:0'],
            'items.*.unit_price'               => ['required', 'numeric', 'min:0'],
            'items.*.line_total'               => ['required', 'numeric', 'min:0'],
            'discount'                         => ['nullable', 'numeric', 'min:0'],
            'delivery_charge'                  => ['nullable', 'numeric', 'min:0'],
            'sst_percent'                      => ['nullable', 'numeric', 'min:0', 'max:100'],
            'sst_amount'                       => ['nullable', 'numeric', 'min:0'],
            'grand_total'                      => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
