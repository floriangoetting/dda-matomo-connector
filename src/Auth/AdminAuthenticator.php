<?php

namespace DDA\MatomoConnector\Auth;

use DDA\MatomoConnector\Support\Config;

final class AdminAuthenticator
{
    public function __construct(private Config $config)
    {
    }

    public function verify(string $token): bool
    {
        $tokenHash = $this->config->string('admin_token_hash');
        if ($tokenHash === '' || $token === '') {
            return false;
        }

        return password_verify($token, $tokenHash);
    }
}
