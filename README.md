# Laravel Flow Docs

Static HTML flow documentation for Laravel applications.

The package analyzes PHP source code without executing business rules. It maps controllers, services/actions/use cases, models, migrations, foreign keys, inferred joins, dependency injection, route bindings, internal calls, and line-level flow notes into presentation-friendly HTML.

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

Database diagram:

```text
public/docs/flow/database/diagram.html
```

The generated pages include a top navigation bar and index sections. The database diagram supports zoom controls, reset, Ctrl + scroll zoom, and drag-to-pan navigation.
Each table detected from migrations also gets its own page under `public/docs/flow/database/tables/`.

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
    'migration_dirs' => [database_path('migrations')],
    'project_name' => config('app.name', 'Laravel'),
    'language' => 'pt_BR', // Supported now: pt_BR, en, es. Catalogs live in Support/I18n/Languages.
    'controller_namespaces' => ['App\\Http\\Controllers'],
    'service_namespaces' => ['App\\Services', 'App\\Http\\Services', 'App\\Actions', 'App\\Domain'],
    'model_namespaces' => ['App\\Models', 'App'],
    'named_gateways' => ['Lytex', 'PagarMe', 'Stripe', 'PayPal'],
];
```

## Static Analysis

The analyzer detects:

- controllers by configured namespace, `*Controller` suffix, and `Controllers/` folders, including modular monolith paths;
- services by configured namespace, service/action/use-case suffixes, and `Services/`, `Actions/`, `UseCases/`, `Domain/`, or `Application/` folders;
- dependency injection in constructors and typed method parameters, including promoted properties such as `protected Algo $algo`;
- models from configured namespaces, `Models/` folders, Eloquent inheritance, direct queries, typed returns, direct returns, and internal method returns;
- database tables, columns and foreign keys from migrations;
- Eloquent relations declared in models;
- joins used in code through query builder chains.

Example model inference:

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
