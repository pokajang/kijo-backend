<?php

namespace App\Http\Requests\Quote;

use Illuminate\Foundation\Http\FormRequest;

class UpdateEquipmentQuoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'client_id'              => ['required', 'integer'],
            'client_name'            => ['required', 'string', 'max:255'],
            'client_ssm'             => ['nullable', 'string', 'max:255'],
            'client_address'         => ['required', 'string', 'max:255'],
            'client_city'            => ['nullable', 'string', 'max:255'],
            'client_state'           => ['nullable', 'string', 'max:255'],
            'client_zip'             => ['nullable', 'string', 'max:255'],
            'pic_name'               => ['required', 'string', 'max:2000'],
            'pic_email'              => ['required', 'string', 'max:2000'],
            'pic_phone'              => ['required', 'string', 'max:2000'],
            'pic_position'           => ['required', 'string', 'max:2000'],
            'items'                  => ['required', 'array', 'min:1'],
            'items.*.catalog_item_id'=> ['required', 'integer'],
            'items.*.item_id'        => ['nullable', 'integer'],
            'items.*.item_name'      => ['nullable', 'string', 'max:255'],
            'items.*.item_code'      => ['nullable', 'string', 'max:100'],
            'items.*.unit_price'     => ['required', 'numeric', 'min:0'],
            'items.*.quantity'       => ['required', 'numeric', 'min:0.01'],
            'items.*.marked_up_price'=> ['nullable', 'numeric', 'min:0'],
            'items.*.line_total'     => ['nullable', 'numeric', 'min:0'],
            'items.*.total_price'    => ['required', 'numeric', 'min:0'],
            'delivery_charge'        => ['nullable', 'numeric', 'min:0'],
            'misc_charge'            => ['nullable', 'numeric', 'min:0'],
            'discount'               => ['nullable', 'numeric', 'min:0'],
            'price_exception_request_id' => ['nullable', 'integer', 'min:1'],
            'sst_percent'            => ['nullable', 'numeric', 'min:0', 'max:100'],
            'isRevision'             => ['nullable', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $items = $this->input('items', []);
        if (!is_array($items)) {
            return;
        }

        $normalized = array_map(function ($item) {
            if (!is_array($item)) {
                return $item;
            }

            $catalogItemId = $item['catalog_item_id'] ?? $item['item_id'] ?? null;
            $qty = isset($item['quantity']) ? (float) $item['quantity'] : 0.0;
            $markedUp = isset($item['marked_up_price']) ? (float) $item['marked_up_price'] : null;
            $unitPrice = isset($item['unit_price']) ? (float) $item['unit_price'] : 0.0;
            $lineTotal = $item['total_price'] ?? $item['line_total'] ?? null;

            if ($lineTotal === null) {
                $lineTotal = $qty * ($markedUp ?? $unitPrice);
            }

            return [
                'catalog_item_id' => $catalogItemId,
                'item_id' => $catalogItemId,
                'item_name' => $item['item_name'] ?? null,
                'item_code' => $item['item_code'] ?? null,
                'quantity' => $item['quantity'] ?? null,
                'unit_price' => $item['unit_price'] ?? null,
                'marked_up_price' => $item['marked_up_price'] ?? null,
                'line_total' => $item['line_total'] ?? $lineTotal,
                'total_price' => $lineTotal,
            ];
        }, $items);

        $this->merge(['items' => $normalized]);
    }
}
