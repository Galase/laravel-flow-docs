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

        File::put(app_path('Models/Category.php'), <<<'PHP'
<?php

namespace App\Models;

class Category
{
    public static function where($field, $value)
    {
        return new static();
    }

    public function get()
    {
        return $this;
    }

    public function count()
    {
        return 1;
    }
}
PHP);

        File::put(app_path('Services/AlunoService.php'), <<<'PHP'
<?php

namespace App\Services;

use App\Models\Aluno;
use App\Models\Category;

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

    public function dashboard()
    {
        $count = Category::where('active', true)->get()->count();

        return [
            'news' => $this->newsService->getAllNews(),
            'count' => $count,
        ];
    }

    public function listarPaginado($query, $perPage)
    {
        $query->where('index', $filters['index']);

        return $query->where('active', true)->where('name', 'like', $this->search)->orderBy('id', 'desc')->paginate($perPage)->withQueryString();
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

        $this->assertStringContainsString('id="flowDocsThemeToggle"', $html);
        $this->assertStringContainsString("window.tailwind.config = { darkMode: 'class' }", $html);
        $this->assertStringContainsString('flow-docs-theme', $html);
        $this->assertStringContainsString('Método montaPayloadLytex constrói o payload para o gateway Lytex', $html);
        $this->assertStringContainsString('Código anotado', $html);
        $this->assertStringContainsString('code-dracula', $html);
        $this->assertStringContainsString('tok-variable', $html);
        $this->assertStringContainsString('tok-comment', $html);
        $this->assertStringContainsString('language-php', $html);
        $this->assertStringNotContainsString('```php', $html);
        $this->assertStringContainsString('Atribui a $aluno o retorno de $this-&gt;buscaAluno($id), executado na instância atual; o valor é tratado como Aluno pelas inferências.', $html);
        $this->assertStringContainsString('Atribui a $count o resultado de uma query de Category que filtra registros em que active seja true e conta o total encontrado; o valor é tratado como Category pelas inferências.', $html);
        $this->assertStringContainsString('Preenche o campo news com o retorno de $this-&gt;newsService-&gt;getAllNews(), acessando a propriedade newsService da instância atual.', $html);
        $this->assertStringContainsString('Aplica em $query filtro em que index seja $filters[&#039;index&#039;].', $html);
        $this->assertStringContainsString('Retorna $query filtrado em que active seja true e name corresponda a $this-&gt;search, ordenado por id desc, paginado por $perPage e com query string para quem chamou o método.', $html);
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

        foreach ([$root, $model, $database, $diagram, $table, $controller, $service] as $generatedHtml) {
            $this->assertStringContainsString('id="flowDocsThemeToggle"', $generatedHtml);
            $this->assertStringContainsString("window.tailwind.config = { darkMode: 'class' }", $generatedHtml);
            $this->assertStringContainsString('flow-docs-theme', $generatedHtml);
        }

        $this->assertStringContainsString('Models', $root);
        $this->assertStringContainsString('Banco de dados', $root);
        $this->assertStringContainsString('Flow Docs', $root);
        $this->assertStringContainsString('hasMany', $model);
        $this->assertStringContainsString('Índice', $database);
        $this->assertStringContainsString('tables/alunos.html', $database);
        $this->assertStringContainsString('Diagrama do banco', $database);
        $this->assertStringContainsString('Diagrama do Banco', $diagram);
        $this->assertStringContainsString('diagramZoomIn', $diagram);
        $this->assertStringContainsString('diagramViewport', $diagram);
        $this->assertStringContainsString('diagram-edge', $diagram);
        $this->assertStringContainsString('diagram-card', $diagram);
        $this->assertStringContainsString('data-from="alunos"', $diagram);
        $this->assertStringContainsString('data-to="turmas"', $diagram);
        $this->assertStringContainsString('diagram-card-dimmed', $diagram);
        $this->assertStringContainsString('diagram-edge-active', $diagram);
        $this->assertStringContainsString('html.dark .diagram-edge-label', $diagram);
        $this->assertStringContainsString('stroke:#020617', $diagram);
        $this->assertStringContainsString('edge.dataset.from === table || edge.dataset.to === table', $diagram);
        $this->assertStringContainsString('event.button !== 2', $diagram);
        $this->assertStringContainsString("viewport.classList.add('cursor-grabbing')", $diagram);
        $this->assertStringNotContainsString('cursor-grab touch-none', $diagram);
        $this->assertStringContainsString('absolute left-4 top-4 z-20', $diagram);
        $this->assertStringContainsString('bg-white/90', $diagram);
        $this->assertStringContainsString('list-disc', $diagram);
        $this->assertStringContainsString('Como navegar', $diagram);
        $this->assertStringContainsString('Arraste com o botão direito', $diagram);
        $this->assertStringContainsString('botão esquerdo para selecionar textos dos cards', $diagram);
        $this->assertStringContainsString('destacar suas conexões', $diagram);
        $this->assertStringContainsString('alunos', $diagram);
        $this->assertStringContainsString('1:N turma_id -&gt; id', $diagram);
        $this->assertStringContainsString('Tabela do banco', $table);
        $this->assertStringContainsString('Foreign keys de saída', $table);
        $this->assertStringContainsString('turmas.id', $table);
        $this->assertStringContainsString('turmas.id', $service);
        $this->assertStringContainsString('MatriculaService $matriculas', $controller);
    }

    public function test_command_generates_english_docs_when_language_is_configured(): void
    {
        File::copy(__DIR__ . '/../../config/flow-docs.php', config_path('flow-docs.php'));

        config()->set('flow-docs.output_path', base_path('flow-docs-output'));
        config()->set('flow-docs.app_dir', app_path());
        config()->set('flow-docs.language', 'en');

        $this->artisan('flow-docs:generate --services --no-routes')
            ->assertExitCode(0);

        $root = File::get(base_path('flow-docs-output/index.html'));
        $html = File::get(base_path('flow-docs-output/services/services/App__Services__AlunoService.html'));

        $this->assertStringContainsString('<html lang="en">', $html);
        $this->assertStringContainsString('Static documentation generated by galase/laravel-flow-docs.', $root);
        $this->assertStringContainsString('Service Documentation', $html);
        $this->assertStringContainsString('Annotated code', $html);
        $this->assertStringContainsString('Method montaPayloadLytex builds the payload for the Lytex gateway', $html);
        $this->assertStringContainsString('Assigns the return value of $this-&gt;buscaAluno($id), executed on the current instance to $aluno; the value is treated as Aluno by the inferences.', $html);
        $this->assertStringContainsString('Fills the news field with the return value of $this-&gt;newsService-&gt;getAllNews(), accessing the newsService property on the current instance.', $html);
        $this->assertStringContainsString('Applies to $query a filter where index is $filters[&#039;index&#039;].', $html);
        $this->assertStringContainsString('Returns $query filtered where active is true and name matches $this-&gt;search, ordered by id desc, paginated by $perPage and with query string to the caller.', $html);
    }

    public function test_command_generates_spanish_docs_when_language_is_configured(): void
    {
        File::copy(__DIR__ . '/../../config/flow-docs.php', config_path('flow-docs.php'));

        config()->set('flow-docs.output_path', base_path('flow-docs-output'));
        config()->set('flow-docs.app_dir', app_path());
        config()->set('flow-docs.language', 'es');

        $this->artisan('flow-docs:generate --services --no-routes')
            ->assertExitCode(0);

        $root = File::get(base_path('flow-docs-output/index.html'));
        $html = File::get(base_path('flow-docs-output/services/services/App__Services__AlunoService.html'));

        $this->assertStringContainsString('<html lang="es">', $html);
        $this->assertStringContainsString('Documentación estática generada por galase/laravel-flow-docs.', $root);
        $this->assertStringContainsString('Documentación por Servicio', $html);
        $this->assertStringContainsString('Código anotado', $html);
        $this->assertStringContainsString('Método montaPayloadLytex construye el payload para el gateway Lytex', $html);
        $this->assertStringContainsString('Asigna a $aluno el retorno de $this-&gt;buscaAluno($id), ejecutado en la instancia actual; el valor es tratado como Aluno por las inferencias.', $html);
        $this->assertStringContainsString('Rellena el campo news con el retorno de $this-&gt;newsService-&gt;getAllNews(), accediendo a la propiedad newsService de la instancia actual.', $html);
    }
}
