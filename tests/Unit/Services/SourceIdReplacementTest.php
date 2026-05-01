<?php

declare(strict_types=1);

use App\Models\Source;
use App\Services\SourceIdRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('test_multiple_source_ids_are_replaced_correctly', function (): void {
    $first = Source::factory()->create([
        'uuid' => '550e8400-e29b-41d4-a716-446655440000',
        'filename' => 'alpha.md',
        'original_name' => 'alpha.md',
    ]);

    $second = Source::factory()->create([
        'uuid' => '650e8400-e29b-41d4-a716-446655440001',
        'filename' => 'beta.txt',
        'original_name' => 'beta.txt',
    ]);

    $renderer = new SourceIdRenderer;

    $rendered = $renderer->render('See [SOURCE_ID:550e8400-e29b-41d4-a716-446655440000] and [SOURCE_ID:650e8400-e29b-41d4-a716-446655440001].');

    $html = (string) $rendered['html'];

    expect($html)->toContain('href="http://localhost/test-hipporag/sources/'.$first->id.'"')
        ->and($html)->toContain('>alpha.md</a>')
        ->and($html)->toContain('>beta.txt</a>')
        ->and($renderer->extractSourceUuids('x [SOURCE_ID:550e8400-e29b-41d4-a716-446655440000] y [SOURCE_ID:650e8400-e29b-41d4-a716-446655440001]'))->toBe([
            '550e8400-e29b-41d4-a716-446655440000',
            '650e8400-e29b-41d4-a716-446655440001',
        ]);
});
