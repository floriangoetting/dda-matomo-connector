<?php

namespace DDA\MatomoConnector\Auth;

use DDA\MatomoConnector\Http\Request;
use DDA\MatomoConnector\Support\Config;

final class HmacAuthenticator
{
    public function __construct(private Config $config)
    {
    }

    public function authenticate(Request $request): void
    {
        $expectedConnectorId = $this->config->string('connector_id');
        $sharedSecret = $this->config->string('shared_secret');
        if ($expectedConnectorId === '' || $sharedSecret === '') {
            throw new \RuntimeException('Connector authentication is not configured.');
        }

        $connectorId = $request->header('X-DDA-Connector-Id');
        $timestamp = $request->header('X-DDA-Timestamp');
        $nonce = $request->header('X-DDA-Nonce');
        $signature = $request->header('X-DDA-Signature');

        if ($connectorId === '' || $timestamp === '' || $nonce === '' || $signature === '') {
            throw new \RuntimeException('Missing connector authentication headers.');
        }
        if (!hash_equals($expectedConnectorId, $connectorId)) {
            throw new \RuntimeException('Invalid connector id.');
        }

        $requestTime = strtotime($timestamp);
        if ($requestTime === false) {
            throw new \RuntimeException('Invalid connector timestamp.');
        }

        $tolerance = $this->config->int('request_tolerance_seconds', 300);
        if (abs(time() - $requestTime) > $tolerance) {
            throw new \RuntimeException('Connector request timestamp is outside the allowed window.');
        }

        $canonicalRequest = implode("\n", [
            $request->method(),
            $request->pathWithQuery(),
            $timestamp,
            $nonce,
            hash('sha256', $request->body()),
        ]);
        $expectedSignature = base64_encode(hash_hmac('sha256', $canonicalRequest, $sharedSecret, true));

        if (!hash_equals($expectedSignature, $signature)) {
            throw new \RuntimeException('Invalid connector signature.');
        }

        $nonceStorePath = $this->config->string('nonce_store_path');
        if ($nonceStorePath !== '') {
            $nonceTtl = $this->config->int('nonce_store_ttl_seconds', $tolerance);
            (new FileNonceStore($nonceStorePath, max(1, $nonceTtl)))->remember($connectorId, $nonce, $requestTime);
        }
    }
}
