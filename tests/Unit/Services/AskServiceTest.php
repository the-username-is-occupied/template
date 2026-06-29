<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Domain\NotebookLM\NotebookLMService;
use App\Services\AccountService;
use App\Services\AskService;
use Mockery;
use Tests\TestCase as BaseTestCase;

uses(BaseTestCase::class);

beforeEach(function () {
    $this->notebookLMService = Mockery::mock(NotebookLMService::class);
    $this->accountService = Mockery::mock(AccountService::class);
    $this->askService = new AskService($this->notebookLMService, $this->accountService);
});

test('ask method exists and has correct signature', function () {
    $this->assertTrue(method_exists($this->askService, 'ask'));

    $reflection = new \ReflectionMethod($this->askService, 'ask');
    $parameters = $reflection->getParameters();

    $this->assertCount(3, $parameters);
    $this->assertEquals('notebook', $parameters[0]->getName());
    $this->assertEquals('question', $parameters[1]->getName());
    $this->assertEquals('chat', $parameters[2]->getName());
    $this->assertTrue($parameters[2]->allowsNull());
});

test('ask service can be instantiated', function () {
    $service = new AskService($this->notebookLMService, $this->accountService);
    expect($service)->toBeInstanceOf(AskService::class);
});

test('ask method requires notebook parameter', function () {
    $reflection = new \ReflectionMethod($this->askService, 'ask');
    $parameters = $reflection->getParameters();

    expect($parameters[0]->isDefaultValueAvailable())->toBeFalse();
});

test('ask method requires question parameter', function () {
    $reflection = new \ReflectionMethod($this->askService, 'ask');
    $parameters = $reflection->getParameters();

    expect($parameters[1]->isDefaultValueAvailable())->toBeFalse();
});

test('ask method has nullable chat parameter', function () {
    $reflection = new \ReflectionMethod($this->askService, 'ask');
    $parameters = $reflection->getParameters();

    expect($parameters[2]->allowsNull())->toBeTrue();
    expect($parameters[2]->isDefaultValueAvailable())->toBeTrue();
    expect($parameters[2]->getDefaultValue())->toBeNull();
});

test('service has correct dependencies', function () {
    $reflection = new \ReflectionClass($this->askService);
    $constructor = $reflection->getConstructor();
    $parameters = $constructor->getParameters();

    expect($parameters)->toHaveCount(2);
    expect($parameters[0]->getType()->getName())->toBe(NotebookLMService::class);
    expect($parameters[1]->getType()->getName())->toBe(AccountService::class);
});
