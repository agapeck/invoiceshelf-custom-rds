<?php

namespace App\Http\Resources\Customer;

use Illuminate\Http\Resources\Json\JsonResource;

class TransactionResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array|\Illuminate\Contracts\Support\Arrayable|\JsonSerializable
     */
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'transaction_id' => $this->transaction_id,
            'type' => $this->type,
            'status' => $this->status,
            'transaction_date' => $this->transaction_date,
            'invoice_id' => $this->invoice_id,
            'invoice' => $this->whenLoaded('invoice', function () {
                return new InvoiceResource($this->invoice);
            }),
            'company' => $this->whenLoaded('company', function () {
                return new CompanyResource($this->company);
            }),
        ];
    }
}
