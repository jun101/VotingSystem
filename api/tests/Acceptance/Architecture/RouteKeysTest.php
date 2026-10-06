<?php

/*
 * NFR-SEC-08 — a record named in a URL is always found by its uuid, never by its
 * numeric key. Checked on every registered route, so a new route is covered the day it
 * is added.
 */

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Reflector;

it('binds every model in a route by its uuid [NFR-SEC-08]', function () {
    foreach (Route::getRoutes() as $route) {
        $label = implode('|', $route->methods()).' '.$route->uri();

        foreach ($route->signatureParameters(['subClass' => Model::class]) as $parameter) {
            $class = Reflector::getParameterClassName($parameter);

            expect((new $class)->getRouteKeyName())
                ->toBe('uuid', "{$label}: {$class} is bound by its numeric key");
        }

        foreach ($route->bindingFields() as $name => $field) {
            expect($field)->toBe('uuid', "{$label}: parameter {$name} is bound by '{$field}'");
        }
    }

    expect(true)->toBeTrue();
});

it('has no route parameter limited to digits [NFR-SEC-08]', function () {
    foreach (Route::getRoutes() as $route) {
        foreach ($route->wheres as $name => $pattern) {
            expect(preg_match('/^(\[0-9\]|\\\\d)[+*]?$/', $pattern))
                ->toBe(0, $route->uri().": parameter {$name} accepts only digits");
        }
    }

    expect(true)->toBeTrue();
});
