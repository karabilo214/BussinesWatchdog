<?php

namespace Tests\Feature\Contracts;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class OpenApiRoutesTest extends TestCase
{
    public function test_every_api_route_is_in_the_contract_and_every_contract_operation_exists(): void
    {
        $contract = base_path('../../contracts/openapi.yaml');

        if (! is_file($contract)) {
            $this->markTestSkipped('Repository contracts directory is not mounted.');
        }

        $documented = [];
        $path = null;

        foreach (file($contract, FILE_IGNORE_NEW_LINES) as $line) {
            if (preg_match('#^  (/\S+):$#', $line, $match) === 1) {
                $path = $match[1];
            } elseif (preg_match('/^\S/', $line) === 1) {
                $path = null;
            } elseif ($path !== null && preg_match('/^    (get|post|put|patch|delete):$/', $line, $match) === 1) {
                $documented[] = strtoupper($match[1]).' '.$this->normalize($path);
            }
        }

        $implemented = [];

        foreach (Route::getRoutes() as $route) {
            $uri = '/'.ltrim($route->uri(), '/');

            if (! str_starts_with($uri, '/api/') && ! str_starts_with($uri, '/internal/')) {
                continue;
            }

            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $implemented[] = $method.' '.$this->normalize($uri);
            }
        }

        sort($documented);
        sort($implemented);

        $this->assertSame([], array_values(array_diff($implemented, $documented)), 'Routes missing from contracts/openapi.yaml');
        $this->assertSame([], array_values(array_diff($documented, $implemented)), 'Contract operations without a route');
    }

    public function test_the_spec_copy_of_the_contract_is_identical(): void
    {
        $contract = base_path('../../contracts/openapi.yaml');
        $spec = base_path('../../spec/contracts/openapi.yaml');

        if (! is_file($contract) || ! is_file($spec)) {
            $this->markTestSkipped('Repository contracts directory is not mounted.');
        }

        $this->assertFileEquals($contract, $spec);
    }

    private function normalize(string $path): string
    {
        return (string) preg_replace('/\{[^}]+\}/', '{}', $path);
    }
}
