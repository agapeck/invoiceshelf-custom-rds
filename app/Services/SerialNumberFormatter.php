<?php

namespace App\Services;

use App\Models\CompanySetting;
use App\Models\Customer;
use Carbon\Carbon;

/**
 * SerialNumberFormatter
 */
class SerialNumberFormatter
{
    public const VALID_PLACEHOLDERS = ['CUSTOMER_SERIES', 'SEQUENCE', 'DATE_FORMAT', 'SERIES', 'RANDOM_SEQUENCE', 'DELIMITER', 'CUSTOMER_SEQUENCE'];

    private $model;

    private $ob;

    private $customer;

    private $company;

    /**
     * @var string
     */
    public $nextSequenceNumber;

    /**
     * @var string
     */
    public $nextCustomerSequenceNumber;

    /**
     * @return $this
     */
    public function setModel($model)
    {
        $this->model = $model;

        return $this;
    }

    public function setModelObject($id = null)
    {
        $query = $this->model::query();
        if ($this->company) {
            $query->where('company_id', $this->company);
        }
        $this->ob = $query->find($id);

        if ($this->ob && $this->ob->sequence_number) {
            $this->nextSequenceNumber = $this->ob->sequence_number;
        }

        if (isset($this->ob) && isset($this->ob->customer_sequence_number) && isset($this->customer) && $this->ob->customer_id == $this->customer->id) {
            $this->nextCustomerSequenceNumber = $this->ob->customer_sequence_number;
        }

        return $this;
    }

    /**
     * @return $this
     */
    public function setCompany($company)
    {
        $this->company = $company;

        return $this;
    }

    /**
     * @return $this
     */
    public function setCustomer($customer = null)
    {
        if ($customer === null) {
            $this->customer = null;
            return $this;
        }

        $query = Customer::query();
        if ($this->company) {
            $query->where('company_id', $this->company);
        }
        $this->customer = $query->find($customer);

        return $this;
    }

    /**
     * @return string
     */
    public function getNextNumber($data = null)
    {
        $modelName = strtolower(class_basename($this->model));
        $settingKey = $modelName.'_number_format';
        $companyId = $this->company;

        if (request()->has('format')) {
            $format = request()->get('format');
        } else {
            $format = CompanySetting::getSetting(
                $settingKey,
                $companyId
            );
        }
        $this->setNextNumbers();
        $supportsCollisionProgress = $this->formatSupportsCollisionProgress($format);

        $attempts = 0;
        do {
            $serialNumber = $this->generateSerialNumber(
                $format
            );

            $exists = $this->model::where('company_id', $companyId)
                ->where($modelName.'_number', $serialNumber)
                ->exists();

            if ($exists) {
                if (! $supportsCollisionProgress) {
                    throw new \RuntimeException('Document number format cannot resolve collisions. Add a dynamic sequence or random placeholder.');
                }

                $this->nextSequenceNumber++;
                $this->nextCustomerSequenceNumber++;
            }
            
            $attempts++;
        } while ($exists && $attempts < 100);

        return $serialNumber;
    }

    private function formatSupportsCollisionProgress(string $format): bool
    {
        $placeholders = self::getPlaceholders($format)->pluck('name')->all();

        return in_array('SEQUENCE', $placeholders, true)
            || in_array('CUSTOMER_SEQUENCE', $placeholders, true)
            || in_array('RANDOM_SEQUENCE', $placeholders, true);
    }

    public function setNextNumbers()
    {
        $this->nextSequenceNumber ?
            $this->nextSequenceNumber : $this->setNextSequenceNumber();

        $this->nextCustomerSequenceNumber ?
            $this->nextCustomerSequenceNumber : $this->setNextCustomerSequenceNumber();

        return $this;
    }

    /**
     * @return $this
     */
    public function setNextSequenceNumber()
    {
        $companyId = $this->company;

        $last = $this->model::orderBy('sequence_number', 'desc')
            ->where('company_id', $companyId)
            ->where('sequence_number', '<>', null)
            ->lockForUpdate()
            ->take(1)
            ->first();

        $this->nextSequenceNumber = ($last) ? $last->sequence_number + 1 : 1;

        return $this;
    }

    /**
     * @return self
     */
    public function setNextCustomerSequenceNumber()
    {
        $customer_id = ($this->customer) ? $this->customer->id : 1;

        $last = $this->model::orderBy('customer_sequence_number', 'desc')
            ->where('company_id', $this->company)
            ->where('customer_id', $customer_id)
            ->where('customer_sequence_number', '<>', null)
            ->lockForUpdate()
            ->take(1)
            ->first();

        $this->nextCustomerSequenceNumber = ($last) ? $last->customer_sequence_number + 1 : 1;

        return $this;
    }

    public static function getPlaceholders(string $format)
    {
        $regex = '/{{([A-Z_]{1,})(?::)?([a-zA-Z0-9_]{1,6}|.{1})?}}/';

        preg_match_all($regex, $format, $placeholders);
        array_shift($placeholders);
        $validPlaceholders = collect();

        /** @var array */
        $mappedPlaceholders = array_map(
            null,
            current($placeholders),
            end($placeholders)
        );

        foreach ($mappedPlaceholders as $placeholder) {
            $name = current($placeholder);
            $value = end($placeholder);

            if (in_array($name, self::VALID_PLACEHOLDERS)) {
                $validPlaceholders->push([
                    'name' => $name,
                    'value' => $value,
                ]);
            }
        }

        return $validPlaceholders;
    }

    /**
     * @return string
     */
    private function generateSerialNumber(string $format)
    {
        $serialNumber = '';

        $placeholders = self::getPlaceholders($format);

        foreach ($placeholders as $placeholder) {
            $name = $placeholder['name'];
            $value = $placeholder['value'];

            switch ($name) {
                case 'SEQUENCE':
                    $value = $value ? $value : 6;
                    $serialNumber .= str_pad($this->nextSequenceNumber, $value, 0, STR_PAD_LEFT);

                    break;
                case 'DATE_FORMAT':
                    $value = $value ? $value : 'Y';
                    $serialNumber .= Carbon::now()->format($value);

                    break;
                case 'RANDOM_SEQUENCE':
                    $value = $value ? $value : 6;
                    $serialNumber .= substr(bin2hex(random_bytes($value)), 0, $value);

                    break;
                case 'CUSTOMER_SERIES':
                    if (isset($this->customer)) {
                        $serialNumber .= $this->customer->prefix ?? 'CST';
                    } else {
                        $serialNumber .= 'CST';
                    }

                    break;
                case 'CUSTOMER_SEQUENCE':
                    $serialNumber .= str_pad($this->nextCustomerSequenceNumber, $value, 0, STR_PAD_LEFT);

                    break;
                default:
                    $serialNumber .= $value;
            }
        }

        return $serialNumber;
    }
}
