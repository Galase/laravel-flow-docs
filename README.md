# Laravel Flow Docs

Static HTML flow documentation for Laravel applications.

The package analyzes PHP source code without executing business rules. It maps controllers, services/actions/use cases, methods, route bindings, inferred models, internal calls, and line-level flow notes into presentation-friendly HTML.

## Installation

```bash
composer require galase/laravel-flow-docs --dev
php artisan vendor:publish --tag=flow-docs-config
php artisan flow-docs:generate
```

Open:

```text
public/docs/flow/index.html
```

## Command

```bash
php artisan flow-docs:generate
php artisan flow-docs:generate --services
php artisan flow-docs:generate --controllers
php artisan flow-docs:generate --output=public/docs/flow
php artisan flow-docs:generate --no-routes
```

The command requires `config/flow-docs.php` to exist in the host Laravel application. If it has not been published, it exits with:

```text
Run: php artisan vendor:publish --tag=flow-docs-config
```

## Configuration

Published config:

```php
return [
    'output_path' => public_path('docs/flow'),
    'app_dir' => app_path(),
    'project_name' => config('app.name', 'Laravel'),
    'controller_namespaces' => ['App\\Http\\Controllers'],
    'service_namespaces' => ['App\\Services', 'App\\Http\\Services', 'App\\Actions', 'App\\Domain'],
    'model_namespaces' => ['App\\Models', 'App'],
    'named_gateways' => ['Lytex', 'PagarMe', 'Stripe', 'PayPal'],
];
```

## Static Analysis

The analyzer detects models from direct queries, typed returns, direct returns, and internal method returns:

```php
private function aluno($id)
{
    return Aluno::where('id', $id)->first();
}

public function fluxo($id)
{
    $aluno = $this->aluno($id);
}
```

In this case, `$aluno` is documented as `Aluno`, inferred from `$this->aluno()`.

## Limits

This package is intentionally conservative. It does not execute application code and may not infer types hidden behind containers, repositories without return types, interfaces, magic methods, or highly dynamic calls.
