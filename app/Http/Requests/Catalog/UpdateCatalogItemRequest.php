<?php

namespace App\Http\Requests\Catalog;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCatalogItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'item_name'       => ['required', 'string', 'max:255'],
            'category_id'     => ['required', 'string', 'max:50'],
            'description'     => ['nullable', 'string', 'max:5000'],
            'unit'            => ['nullable', 'string', 'max:50'],
            'supplier_name'   => ['nullable', 'string', 'max:255'],
            'supplier_price'  => ['nullable', 'numeric', 'min:0'],
            'price_date'      => ['nullable', 'date'],
            'entry_remarks'   => ['nullable', 'string', 'max:5000'],
            'remarks'         => ['nullable', 'string', 'max:5000'],
            'remove_brochure' => ['nullable', 'in:0,1,true,false'],
            'new_brochure'    => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:10240'],
            'image'           => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:10240'],
        ];
    }
}
