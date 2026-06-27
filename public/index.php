<?php

declare(strict_types=1);

use DDA\MatomoConnector\Auth\HmacAuthenticator;
use DDA\MatomoConnector\Http\JsonResponse;
use DDA\MatomoConnector\Http\Request;
use DDA\MatomoConnector\Matomo\MatomoCatalogService;
use DDA\MatomoConnector\Matomo\MatomoQueryService;
use DDA\MatomoConnector\Support\Config;
use DDA\MatomoConnector\Support\Version;
use DDA\MatomoConnector\Setup\SetupController;
use DDA\MatomoConnector\Update\UpdateController;
use DDA\MatomoConnector\Update\UpdateService;

spl_autoload_register(function (string $class): void {
    $prefix = 'DDA\\MatomoConnector\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relativeClass = substr($class, strlen($prefix));
    $path = dirname(__DIR__) . '/src/' . str_replace('\\', '/', $relativeClass) . '.php';
    if (is_file($path)) {
        require $path;
    }
});

$request = new Request();

try {
    $basePath = dirname(__DIR__);
    $configPath = $basePath . '/config.php';
    $requestPath = rtrim($request->path(), '/');
    $v1Position = strpos($requestPath, '/v1');
    $path = $v1Position === false ? $requestPath : substr($requestPath, $v1Position);
    $isSetupPath = $requestPath === '' || $requestPath === '/' || str_ends_with($requestPath, '/setup');
    $isAdminUpdatePath = $requestPath === '/admin/update' || str_ends_with($requestPath, '/admin/update');
    $method = $request->method();

    if (!is_file($configPath)) {
        if (($method === 'GET' || $method === 'POST') && ($isSetupPath || !str_starts_with($path, '/v1'))) {
            (new SetupController($basePath, $configPath))->handle($method);
            return;
        }
        if ($method === 'GET' && $path === '/v1/health') {
            JsonResponse::send([
                'status' => 'setup_required',
                'connector' => 'matomo-php',
                'version' => Version::CONNECTOR,
            ], 503);
            return;
        }
        JsonResponse::send(['message' => 'Connector setup is required.'], 503);
        return;
    }

    $config = Config::load($configPath);

    if ($method === 'GET' && $path === '/v1/health') {
        JsonResponse::send([
            'status' => 'ok',
            'connector' => 'matomo-php',
            'version' => Version::CONNECTOR,
        ]);
        return;
    }

    if (($method === 'GET' || $method === 'POST') && $isAdminUpdatePath) {
        (new UpdateController($config, $basePath))->handle($method);
        return;
    }

    (new HmacAuthenticator($config))->authenticate($request);

    if ($method === 'GET' && $path === '/v1/capabilities') {
        JsonResponse::send([
            'providerKey' => 'matomo_mysql',
            'connector' => 'matomo-php',
            'version' => Version::CONNECTOR,
            'supportsCatalog' => true,
            'supportsQuery' => true,
            'supportsSites' => true,
            'supportsUpdateCheck' => true,
            'supportsSelfUpdate' => $config->bool('allow_self_update', false),
            'supportsSqlTemplates' => false,
            'maxLimit' => 400,
        ]);
        return;
    }

    if ($method === 'GET' && $path === '/v1/update-check') {
        JsonResponse::send((new UpdateService($config, $basePath))->check());
        return;
    }

    if ($method === 'POST' && $path === '/v1/catalog') {
        $request->json();
        JsonResponse::send((new MatomoCatalogService($config))->loadCatalog());
        return;
    }

    if ($method === 'POST' && $path === '/v1/query') {
        JsonResponse::send((new MatomoQueryService($config))->execute($request->json()));
        return;
    }

    JsonResponse::send(['message' => 'Not found.'], 404);
} catch (InvalidArgumentException $error) {
    JsonResponse::send(['message' => $error->getMessage()], 400);
} catch (PDOException $error) {
    JsonResponse::send(['message' => 'Connector query failed.'], 400);
} catch (RuntimeException $error) {
    JsonResponse::send(['message' => $error->getMessage()], 403);
} catch (Throwable $error) {
    JsonResponse::send(['message' => 'Internal connector error.'], 500);
}
