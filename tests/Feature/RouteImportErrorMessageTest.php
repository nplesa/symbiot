<?php

namespace Tests\Feature;

use App\Exceptions\UserFacingException;
use App\Livewire\Trasee\Index;
use App\Models\User;
use App\Services\RouteImportService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Tests\TestCase;

class RouteImportErrorMessageTest extends TestCase
{
    use RefreshDatabase;

    private function importWith(\Throwable $exception): \Livewire\Features\SupportTesting\Testable
    {
        $this->mock(RouteImportService::class)
            ->shouldReceive('import')
            ->andThrow($exception);

        return Livewire::actingAs(User::factory()->create())
            ->test(Index::class)
            ->set('name', 'Traseu')
            ->set('file', UploadedFile::fake()->createWithContent('traseu.gpx', '<gpx></gpx>'))
            ->call('importRoute');
    }

    public function test_user_facing_import_errors_are_shown(): void
    {
        $this->importWith(new UserFacingException('Arhiva KMZ nu poate fi deschisă.'))
            ->assertHasErrors(['file'])
            ->assertSee('Arhiva KMZ nu poate fi deschisă.');
    }

    public function test_internal_import_errors_are_replaced_with_a_generic_message(): void
    {
        $component = $this->importWith(new QueryException(
            'sqlite', 'insert into route_points (secret_column) values (?)', [1], new \Exception('no such column: secret_column')
        ));

        $component->assertHasErrors(['file'])
            ->assertSee('Fișierul de traseu nu a putut fi importat.')
            ->assertDontSee('secret_column');
    }
}
