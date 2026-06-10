<?php

namespace Tests\Feature;

use App\Domain\NotebookLM\NotebookLMService;
use Database\Factories\NotebookDTOFactory;
use Tests\TestCase;

class NotebookLMTest extends TestCase
{
    /**
     * A basic feature test example.
     */
    public function test_fake_is_work(): void
    {
        $this->mock(NotebookLMService::class)
            ->shouldReceive('getNotebook')
            ->andReturn(NotebookDTOFactory::make(['title' => 'My Notebook']));

        $response = app(NotebookLMService::class)->getNotebook('', '');

        $this->assertEquals('My Notebook', $response->title);
    }
}
