<?php

/**
 * Standalone validation tests for R2 disk integration
 * These tests don't require database access - run with: php tests/standalone_r2_test.php
 */

require __DIR__ . '/../vendor/autoload.php';

// Bootstrap minimal Laravel for validation
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Http\Requests\DiskEnvironmentRequest;
use Illuminate\Support\Facades\Validator;

class R2ValidationTests
{
    private int $passed = 0;
    private int $failed = 0;
    private array $failures = [];

    private function validateDiskRequest(array $data): \Illuminate\Validation\Validator
    {
        $request = new DiskEnvironmentRequest();
        $request->replace($data);
        return Validator::make($data, $request->rules());
    }

    private function assert(bool $condition, string $message): void
    {
        if ($condition) {
            $this->passed++;
            echo "  ✓ {$message}\n";
        } else {
            $this->failed++;
            $this->failures[] = $message;
            echo "  ✗ {$message}\n";
        }
    }

    public function testR2RequiresAllMandatoryFields(): void
    {
        echo "\n[TEST] R2 driver requires all mandatory fields\n";
        
        $data = [
            'driver' => 'r2',
            'name' => 'My R2 Disk',
            'credentials' => [],
        ];

        $validator = $this->validateDiskRequest($data);
        
        $this->assert($validator->fails(), 'Validation should fail with empty credentials');
        
        $errors = $validator->errors();
        $this->assert($errors->has('credentials.endpoint'), 'Should require endpoint');
        $this->assert($errors->has('credentials.key'), 'Should require key');
        $this->assert($errors->has('credentials.secret'), 'Should require secret');
        $this->assert($errors->has('credentials.region'), 'Should require region');
        $this->assert($errors->has('credentials.bucket'), 'Should require bucket');
    }

    public function testR2AcceptsValidCredentials(): void
    {
        echo "\n[TEST] R2 driver accepts valid credentials\n";
        
        $data = [
            'driver' => 'r2',
            'name' => 'My R2 Disk',
            'credentials' => [
                'endpoint' => 'https://abc123.r2.cloudflarestorage.com',
                'key' => 'AKIAIOSFODNN7EXAMPLE',
                'secret' => 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY',
                'region' => 'auto',
                'bucket' => 'my-backup-bucket',
                'root' => '/backups/',
            ],
        ];

        $validator = $this->validateDiskRequest($data);
        
        $this->assert(!$validator->fails(), 'Valid credentials should pass validation');
    }

    public function testR2AllowsNullableRoot(): void
    {
        echo "\n[TEST] R2 driver allows nullable root path\n";
        
        $data = [
            'driver' => 'r2',
            'name' => 'My R2 Disk',
            'credentials' => [
                'endpoint' => 'https://abc123.r2.cloudflarestorage.com',
                'key' => 'AKIAIOSFODNN7EXAMPLE',
                'secret' => 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY',
                'region' => 'auto',
                'bucket' => 'my-bucket',
                // root omitted
            ],
        ];

        $validator = $this->validateDiskRequest($data);
        
        $this->assert(!$validator->fails(), 'Should pass without root path');
    }

    public function testR2ValidatesEndpointUrl(): void
    {
        echo "\n[TEST] R2 driver validates endpoint is a valid URL\n";
        
        $data = [
            'driver' => 'r2',
            'name' => 'My R2 Disk',
            'credentials' => [
                'endpoint' => 'not-a-valid-url',
                'key' => 'AKIAIOSFODNN7EXAMPLE',
                'secret' => 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY',
                'region' => 'auto',
                'bucket' => 'my-bucket',
            ],
        ];

        $validator = $this->validateDiskRequest($data);
        
        $this->assert($validator->fails(), 'Invalid URL should fail');
        $this->assert($validator->errors()->has('credentials.endpoint'), 'Error should be on endpoint field');
    }

    public function testR2BucketNameValidation(): void
    {
        echo "\n[TEST] R2 bucket name validation\n";
        
        $validBuckets = ['my-bucket', 'backup-2024', 'a1b', 'test-long-bucket-name'];
        $invalidBuckets = ['My-Bucket', '-my-bucket', 'my-bucket-', 'ab', 'my bucket', 'my_bucket'];

        foreach ($validBuckets as $bucket) {
            $data = [
                'driver' => 'r2',
                'name' => 'Test',
                'credentials' => [
                    'endpoint' => 'https://abc.r2.cloudflarestorage.com',
                    'key' => 'key',
                    'secret' => 'secret',
                    'region' => 'auto',
                    'bucket' => $bucket,
                ],
            ];
            $validator = $this->validateDiskRequest($data);
            $this->assert(!$validator->fails(), "Bucket '$bucket' should be valid");
        }

        foreach ($invalidBuckets as $bucket) {
            $data = [
                'driver' => 'r2',
                'name' => 'Test',
                'credentials' => [
                    'endpoint' => 'https://abc.r2.cloudflarestorage.com',
                    'key' => 'key',
                    'secret' => 'secret',
                    'region' => 'auto',
                    'bucket' => $bucket,
                ],
            ];
            $validator = $this->validateDiskRequest($data);
            $this->assert($validator->fails(), "Bucket '$bucket' should be invalid");
        }
    }

    public function testS3CompatRequiresAllFields(): void
    {
        echo "\n[TEST] s3compat driver requires all mandatory fields (bug fix verification)\n";
        
        $data = [
            'driver' => 's3compat',
            'name' => 'My S3 Compatible Disk',
            'credentials' => [],
        ];

        $validator = $this->validateDiskRequest($data);
        
        $this->assert($validator->fails(), 'Validation should fail with empty credentials');
        
        $errors = $validator->errors();
        $this->assert($errors->has('credentials.endpoint'), 'Should require endpoint');
        $this->assert($errors->has('credentials.key'), 'Should require key');
        $this->assert($errors->has('credentials.secret'), 'Should require secret');
        $this->assert($errors->has('credentials.region'), 'Should require region');
        $this->assert($errors->has('credentials.bucket'), 'Should require bucket');
        $this->assert($errors->has('credentials.root'), 'Should require root');
    }

    public function testS3CompatValidatesEndpointUrl(): void
    {
        echo "\n[TEST] s3compat driver validates endpoint URL\n";
        
        $data = [
            'driver' => 's3compat',
            'name' => 'Test',
            'credentials' => [
                'endpoint' => 'not-a-url',
                'key' => 'key',
                'secret' => 'secret',
                'region' => 'us-east-1',
                'bucket' => 'bucket',
                'root' => '/',
            ],
        ];

        $validator = $this->validateDiskRequest($data);
        
        $this->assert($validator->fails(), 'Invalid URL should fail');
        $this->assert($validator->errors()->has('credentials.endpoint'), 'Error should be on endpoint');
    }

    public function testR2ConfigExists(): void
    {
        echo "\n[TEST] R2 disk is configured in filesystems config\n";
        
        $r2Config = config('filesystems.disks.r2');
        
        $this->assert($r2Config !== null, 'R2 config should exist');
        $this->assert($r2Config['driver'] === 's3', 'R2 should use s3 driver');
        $this->assert($r2Config['use_path_style_endpoint'] === false, 'R2 should use virtual-hosted style');
    }

    public function testR2ConfigHasAllKeys(): void
    {
        echo "\n[TEST] R2 disk config has all required keys\n";
        
        $r2Config = config('filesystems.disks.r2');
        $requiredKeys = ['driver', 'endpoint', 'use_path_style_endpoint', 'key', 'secret', 'region', 'bucket', 'root'];
        
        foreach ($requiredKeys as $key) {
            $this->assert(array_key_exists($key, $r2Config), "Config should have '$key' key");
        }
    }

    public function run(): int
    {
        echo "\n" . str_repeat("=", 60) . "\n";
        echo "R2 DISK INTEGRATION TESTS\n";
        echo str_repeat("=", 60) . "\n";

        $this->testR2RequiresAllMandatoryFields();
        $this->testR2AcceptsValidCredentials();
        $this->testR2AllowsNullableRoot();
        $this->testR2ValidatesEndpointUrl();
        $this->testR2BucketNameValidation();
        $this->testS3CompatRequiresAllFields();
        $this->testS3CompatValidatesEndpointUrl();
        $this->testR2ConfigExists();
        $this->testR2ConfigHasAllKeys();

        echo "\n" . str_repeat("=", 60) . "\n";
        echo "RESULTS: {$this->passed} passed, {$this->failed} failed\n";
        echo str_repeat("=", 60) . "\n";

        if ($this->failed > 0) {
            echo "\nFailed tests:\n";
            foreach ($this->failures as $failure) {
                echo "  - {$failure}\n";
            }
        }

        return $this->failed > 0 ? 1 : 0;
    }
}

$tests = new R2ValidationTests();
exit($tests->run());
