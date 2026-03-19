<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class FileDiskResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param  \Illuminate\Http\Request  $request
     */
    public function toArray($request): array
    {
        $credentials = json_decode((string) $this->credentials, true) ?: [];
        $maskedCredentials = collect($credentials)->map(function ($value) {
            if (is_string($value) && $value !== '') {
                return '********';
            }

            return $value;
        })->toArray();

        return [
            'id' => $this->id,
            'name' => $this->name,
            'type' => $this->type,
            'driver' => $this->driver,
            'set_as_default' => $this->set_as_default,
            'credentials' => $maskedCredentials,
            'company_id' => $this->company_id,
        ];
    }
}
