<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ZoneSoftMachineBulkImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'client_id' => ['required', 'integer', 'exists:clients,id'],
            'payload' => ['required', 'array'],
            'payload.format' => ['required', Rule::in(['contacto-digital-zonesoft-import'])],
            'payload.version' => ['required', 'integer', Rule::in([1])],
            'payload.application' => ['required', 'array'],
            'payload.application.id' => ['required', 'string', 'max:64'],
            'payload.application.name' => ['required', 'string', 'max:255'],
            'payload.machines' => ['required', 'array', 'min:1', 'max:500'],
            'payload.machines.*' => ['required', 'array'],
            'payload.machines.*.zs_client_id' => ['required', 'string', 'max:64'],
            'payload.machines.*.license' => ['required', 'string', 'max:64'],
            'payload.machines.*.store_id' => ['required', 'integer', 'min:0'],
            'payload.machines.*.permissions' => ['nullable', 'string', 'max:255'],
        ];
    }
}
