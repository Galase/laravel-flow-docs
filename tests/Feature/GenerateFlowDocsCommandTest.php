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
        File::ensureDirectoryExists(app_path('Modules/Matriculas/Controllers'));
        File::ensureDirectoryExists(app_path('Modules/Matriculas/Services'));
        File::ensureDirectoryExists(database_path('migrations'));

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
    public function __construct(protected Aluno $alunoModel)
    {
    }

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

        File::put(app_path('Models/Turma.php'), <<<'PHP'
<?php

namespace App\Models;

class Turma
{
    public function alunos()
    {
        return $this->hasMany(Aluno::class, 'turma_id');
    }
}
PHP);

        File::put(app_path('Modules/Matriculas/Services/MatriculaService.php'), <<<'PHP'
<?php

namespace App\Modules\Matriculas\Services;

use App\Models\Aluno;

class MatriculaService
{
    public function listar()
    {
        return Aluno::join('turmas', 'turmas.id', '=', 'alunos.turma_id')->select('alunos.*')->get();
    }
}
PHP);

        File::put(app_path('Modules/Matriculas/Controllers/MatriculaController.php'), <<<'PHP'
<?php

namespace App\Modules\Matriculas\Controllers;

use App\Modules\Matriculas\Services\MatriculaService;

class MatriculaController
{
    public function __construct(protected MatriculaService $matriculas)
    {
    }
}
PHP);

        File::put(database_path('migrations/2024_01_01_000000_create_alunos_table.php'), <<<'PHP'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('alunos', function (Blueprint $table) {
            $table->id();
            $table->string('nome');
            $table->foreignId('turma_id')->constrained('turmas');
            $table->timestamps();
        });
    }
};
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

    public function test_command_generates_model_database_modular_and_injection_docs(): void
    {
        File::copy(__DIR__ . '/../../config/flow-docs.php', config_path('flow-docs.php'));

        config()->set('flow-docs.output_path', base_path('flow-docs-output'));
        config()->set('flow-docs.app_dir', app_path());
        config()->set('flow-docs.migration_dirs', [database_path('migrations')]);

        $this->artisan('flow-docs:generate --no-routes')
            ->assertExitCode(0);

        $root = File::get(base_path('flow-docs-output/index.html'));
        $model = File::get(base_path('flow-docs-output/models/models/App__Models__Turma.html'));
        $database = File::get(base_path('flow-docs-output/database/index.html'));
        $diagram = File::get(base_path('flow-docs-output/database/diagram.html'));
        $table = File::get(base_path('flow-docs-output/database/tables/alunos.html'));
        $controller = File::get(base_path('flow-docs-output/controllers/controllers/App__Modules__Matriculas__Controllers__MatriculaController.html'));
        $service = File::get(base_path('flow-docs-output/services/services/App__Modules__Matriculas__Services__MatriculaService.html'));

        $this->assertStringContainsString('Models', $root);
        $this->assertStringContainsString('Banco de dados', $root);
        $this->assertStringContainsString('Flow Docs', $root);
        $this->assertStringContainsString('hasMany', $model);
        $this->assertStringContainsString('Indice', $database);
        $this->assertStringContainsString('tables/alunos.html', $database);
        $this->assertStringContainsString('Diagrama do banco', $database);
        $this->assertStringContainsString('Diagrama do Banco', $diagram);
        $this->assertStringContainsString('diagramZoomIn', $diagram);
        $this->assertStringContainsString('diagramViewport', $diagram);
        $this->assertStringContainsString('diagram-edge', $diagram);
        $this->assertStringContainsString('alunos', $diagram);
        $this->assertStringContainsString('1:N turma_id -&gt; id', $diagram);
        $this->assertStringContainsString('Tabela do banco', $table);
        $this->assertStringContainsString('Foreign keys de saida', $table);
        $this->assertStringContainsString('turmas.id', $table);
        $this->assertStringContainsString('turmas.id', $service);
        $this->assertStringContainsString('MatriculaService $matriculas', $controller);
    }
}
