<?php

namespace App\Http\Requests\Quote;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSpecialQuoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'client_id'                => ['required', 'integer'],
            'client_name'              => ['required', 'string', 'max:255'],
            'client_ssm'               => ['nullable', 'string', 'max:255'],
            'client_address'           => ['required', 'string', 'max:255'],
            'client_city'              => ['nullable', 'string', 'max:255'],
            'client_state'             => ['nullable', 'string', 'max:255'],
            'client_zip'               => ['nullable', 'string', 'max:255'],
            'pic_name'                 => ['required', 'string', 'max:2000'],
            'pic_email'                => ['required', 'string', 'max:2000'],
            'pic_phone'                => ['required', 'string', 'max:2000'],
            'pic_position'             => ['required', 'string', 'max:2000'],
            'sp_id'                    => ['nullable', 'integer'],
            'service_title'            => ['required', 'string', 'max:255'],
            'service_code'             => ['required', 'string', 'max:255'],
            'general_remarks'          => ['nullable', 'string', 'max:2000'],
            'discount'                 => ['nullable', 'numeric', 'min:0'],
            'price_exception_request_id' => ['nullable', 'integer', 'min:1'],
            'sst_percent'              => ['nullable', 'numeric', 'min:0'],
            'sst_amount'               => ['nullable', 'numeric', 'min:0'],
            'sub_total'                => ['nullable', 'numeric', 'min:0'],
            'grand_total'              => ['nullable', 'numeric', 'min:0'],
            'attach_proposal'          => ['nullable', 'boolean'],
            'proposal_language'        => ['nullable', 'in:en,ms-MY'],
            'line_items'               => ['required', 'array', 'min:1'],
            'line_items.*.title'       => ['nullable', 'string', 'max:255'],
            'line_items.*.item_name'   => ['required', 'string', 'max:255'],
            'line_items.*.description' => ['nullable', 'string', 'max:1000'],
            'line_items.*.unit'        => ['nullable', 'string', 'max:100'],
            'line_items.*.unit_price'  => ['required', 'numeric', 'min:0'],
            'line_items.*.quantity'    => ['required', 'numeric', 'min:0.01'],
            'line_items.*.line_total'  => ['nullable', 'numeric', 'min:0'],
            'line_items.*.total_price' => ['required', 'numeric', 'min:0'],
            'isRevision'               => ['nullable', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $items = $this->input('line_items', []);
        if (!is_array($items)) {
            return;
        }

        $normalized = array_map(function ($item) {
            if (!is_array($item)) {
                return $item;
            }

            $qty = isset($item['quantity']) ? (float) $item['quantity'] : 0.0;
            $unitPrice = isset($item['unit_price']) ? (float) $item['unit_price'] : 0.0;
            $lineTotal = $item['total_price'] ?? $item['line_total'] ?? ($qty * $unitPrice);

            return [
                'title' => $item['title'] ?? null,
                'item_name' => $item['item_name'] ?? $item['title'] ?? null,
                'description' => $item['description'] ?? null,
                'unit' => $item['unit'] ?? null,
                'quantity' => $item['quantity'] ?? null,
                'unit_price' => $item['unit_price'] ?? null,
                'line_total' => $item['line_total'] ?? $lineTotal,
                'total_price' => $lineTotal,
            ];
        }, $items);

        $this->merge(['line_items' => $normalized]);
    }
}
