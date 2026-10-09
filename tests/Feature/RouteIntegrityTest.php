<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

class RouteIntegrityTest extends TestCase
{
    public function test_registered_routes_have_unique_names_methods_and_real_controller_actions(): void
    {
        $names = [];
        $methods = [];
        foreach (Route::getRoutes() as $route) {
            if ($name = $route->getName()) {
                $this->assertNotContains($name, $names);
                $this->assertDoesNotMatchRegularExpression('/booking\.booking\.|admin\.admin\./', $name);
                $names[] = $name;
            }
            $this->assertStringNotContainsString('admin/admin/', $route->uri());
            foreach ($route->methods() as $method) {
                $key = $route->getDomain().'|'.$method.'|'.$route->uri();
                $this->assertNotContains($key, $methods);
                $methods[] = $key;
            }
            $action = $route->getActionName();
            if (str_starts_with($action, 'App\\') && str_contains($action, '@')) {
                [$controller, $method] = explode('@', $action);
                $this->assertTrue(method_exists($controller, $method), $action);
            }
        }
    }

    public function test_literal_route_references_resolve(): void
    {
        $files = Finder::create()->files()->in([app_path(), resource_path('views'), resource_path('js')])->name(['*.php', '*.js']);
        foreach ($files as $file) {
            // Request/FormRequest route() calls read bound parameters, not named URLs.
            preg_match_all('/(?<!->)(?<!Notification::)(?:route|signedRoute|temporarySignedRoute)\(\s*[\'"]([^\'"]+)[\'"]\s*[,)]/', $file->getContents(), $matches);
            foreach ($matches[1] as $name) {
                $this->assertTrue(Route::has($name), $file->getRelativePathname().': '.$name);
            }
        }
    }
}
