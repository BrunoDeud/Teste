<?php

use Illuminate\Support\Facades\Route;

Route::get('/', fn () => response()->json(['app' => 'Achados e Perdidos API', 'docs' => '/docs/api/openapi.yaml']));
Route::get('/docs/api/openapi.yaml', function () {
    $path = base_path('../docs/api/openapi.yaml');
    abort_unless(is_file($path), 404, 'Contrato OpenAPI não encontrado.');
    return response()->file($path, ['Content-Type' => 'application/yaml; charset=UTF-8']);
});
