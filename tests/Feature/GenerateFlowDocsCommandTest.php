<?php

declare(strict_types=1);

namespace Galase\FlowDocs\Tests\Feature;

use Galase\FlowDocs\Tests\TestCase;
use Illuminate\Support\Facades\File;

class GenerateFlowDocsCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        File::delete(config_path('flow-docs.php'));
        File::deleteDirectory(base_path('flow-docs-output'));

        File::ensureDirectoryExists(app_path('Models'));
        File::ensureDirectoryExists(app_path('Services'));
        File::ensureDirectoryExists(app_path('Http/Controllers'));

        File::put(app_path('Models/Aluno.php'), <<<'PHP'
<?php

namespace App\Models;

class Aluno
{
    public static function where($field, $value)
    {
        return new static();
    }

    public function first()
    {
        return $this;
    }
}
PHP);

        File::put(app_path('Services/AlunoService.php'), <<<'PHP'
<?php

namespace App\Services;

use App\Models\Aluno;

class AlunoService
{
    private function buscaAluno($id)
    {
        return Aluno::where('id', $id)->first();
    }

    public function montaPayloadLytex($id)
    {
        $aluno = $this->buscaAluno($id);
        $payload = ['aluno' => $aluno, 'gateway' => 'Lytex'];

        return $payload;
    }
}
PHP);
    }

    public function test_command_requires_published_config(): void
    {
        $this->artisan('flow-docs:generate --no-routes')
            ->expectsOutput('Flow Docs config file not found.')
            ->expectsOutput('Run: php artisan vendor:publish --tag=flow-docs-config')
            ->assertExitCode(1);
    }

    public function test_command_generates_service_docs(): void
    {
        File::copy(__DIR__ . '/../../config/flow-docs.php', config_path('flow-docs.php'));

        config()->set('flow-docs.output_path', base_path('flow-docs-output'));
        config()->set('flow-docs.app_dir', app_path());

        $this->artisan('flow-docs:generate --services --no-routes')
            ->assertExitCode(0);

        $html = File::get(base_path('flow-docs-output/services/services/App__Services__AlunoService.html'));

        $this->assertStringContainsString('Metodo montaPayloadLytex constroi o payload para o gateway Lytex', $html);
        $this->assertStringContainsString('$aluno', $html);
        $this->assertStringContainsString('Aluno', $html);
    }
}
