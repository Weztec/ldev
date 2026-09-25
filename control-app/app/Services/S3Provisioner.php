<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class S3Provisioner
{
    public function provision(string $projectPath, string $name): void
    {

        $bucket = $name;

        $this->createBucket($bucket);

        (new EnvFileWriter)->update($projectPath, [
            'FILESYSTEM_DISK' => 's3',
            'AWS_ACCESS_KEY_ID' => config('ldev.s3.access_key'),
            'AWS_SECRET_ACCESS_KEY' => config('ldev.s3.secret_key'),
            'AWS_DEFAULT_REGION' => 'us-east-1',
            'AWS_BUCKET' => $bucket,
            'AWS_ENDPOINT' => config('ldev.s3.endpoint'),
            'AWS_USE_PATH_STYLE_ENDPOINT' => 'true',
        ]);
    }

    public function createBucket(string $bucket): void
    {
        if (!preg_match('/^[a-z0-9][a-z0-9-]{1,61}[a-z0-9]$/', $bucket)) {
            throw new \InvalidArgumentException("\"{$bucket}\" can't be used as an S3 bucket name (3-63 lowercase letters, digits and hyphens).");
        }

        $endpoint = rtrim(config('ldev.s3.endpoint'), '/');
        $host = parse_url($endpoint, PHP_URL_HOST) . (parse_url($endpoint, PHP_URL_PORT) ? ':' . parse_url($endpoint, PHP_URL_PORT) : '');
        $region = 'us-east-1';
        $amzDate = gmdate('Ymd\THis\Z');
        $date = substr($amzDate, 0, 8);
        $payloadHash = hash('sha256', '');
        $signedHeaders = 'host;x-amz-content-sha256;x-amz-date';

        $canonical = implode("\n", [
            'PUT',
            '/' . $bucket,
            '',
            "host:{$host}",
            "x-amz-content-sha256:{$payloadHash}",
            "x-amz-date:{$amzDate}",
            '',
            $signedHeaders,
            $payloadHash,
        ]);
        $scope = "{$date}/{$region}/s3/aws4_request";
        $stringToSign = implode("\n", ['AWS4-HMAC-SHA256', $amzDate, $scope, hash('sha256', $canonical)]);

        $key = hash_hmac('sha256', $date, 'AWS4' . config('ldev.s3.secret_key'), true);
        foreach ([$region, 's3', 'aws4_request'] as $part) {
            $key = hash_hmac('sha256', $part, $key, true);
        }
        $signature = hash_hmac('sha256', $stringToSign, $key);

        $response = Http::withHeaders([
            'x-amz-content-sha256' => $payloadHash,
            'x-amz-date' => $amzDate,
            'Authorization' => 'AWS4-HMAC-SHA256 Credential=' . config('ldev.s3.access_key') . "/{$scope}, SignedHeaders={$signedHeaders}, Signature={$signature}",
        ])->withBody('', '')->put("{$endpoint}/{$bucket}");

        if ($response->successful() || str_contains($response->body(), 'BucketAlreadyOwnedByYou')) {
            return;
        }

        throw new \RuntimeException("Could not create the S3 bucket \"{$bucket}\" (HTTP {$response->status()}): " . trim(strip_tags($response->body())));
    }
}
