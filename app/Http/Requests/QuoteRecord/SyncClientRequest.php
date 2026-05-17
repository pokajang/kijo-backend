<?php

namespace App\Http\Requests\QuoteRecord;

use Illuminate\Foundation\Http\FormRequest;

class SyncClientRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'quote_id'                    => ['required', 'integer', 'min:1'],
            'pic_id'                      => ['nullable', 'integer', 'min:1'],
            'cascade'                     => ['nullable', 'array'],
            'cascade.delivery_orders'     => ['nullable', 'array'],
            'cascade.delivery_orders.*'   => ['integer', 'min:1'],
            'cascade.invoices'            => ['nullable', 'array'],
            'cascade.invoices.*'          => ['integer', 'min:1'],
            'cascade.receipts'            => ['nullable', 'array'],
            'cascade.receipts.*'          => ['integer', 'min:1'],
            'cascade.jd14'               => ['nullable', 'array'],
            'cascade.jd14.*'             => ['integer', 'min:1'],
        ];
    }
}
