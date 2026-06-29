<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Exceptions\DailyLimitExceededException;
use App\Models\TechAccount;
use App\Models\TechAccountUsage;
use App\Services\AccountService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

test('getAccountForAsk returns account with minimum usage', function () {
    // Create accounts
    $account1 = TechAccount::factory()->create([
        'status' => 'active',
        'pool_type' => 'ask',
    ]);
    $account2 = TechAccount::factory()->create([
        'status' => 'active',
        'pool_type' => 'ask',
    ]);

    // Account1 has usage of 2 today
    TechAccountUsage::create([
        'tech_account_id' => $account1->id,
        'date' => today(),
        'count' => 2,
    ]);

    // Account2 has no usage (or 0)
    // So account2 should be selected

    $service = new AccountService;
    $selected = $service->getAccountForAsk();

    $this->assertEquals($account2->id, $selected->id);
});

test('getAccountForAsk throws exception when no accounts available', function () {
    // Don't create any active accounts
    $service = new AccountService;

    $this->expectException(DailyLimitExceededException::class);
    $service->getAccountForAsk();
});

test('getAccountForAsk throws exception when all accounts exceed limit', function () {
    // Create account with tier limit of 0 or 1
    $account = TechAccount::factory()->create([
        'status' => 'active',
        'pool_type' => 'ask',
    ]);

    // Set usage to exceed limit
    TechAccountUsage::create([
        'tech_account_id' => $account->id,
        'date' => today(),
        'count' => 100, // Exceeds any reasonable limit
    ]);

    $service = new AccountService;

    $this->expectException(DailyLimitExceededException::class);
    $service->getAccountForAsk();
});

test('getAccountForAsk selects account with least usage', function () {
    $account1 = TechAccount::factory()->create([
        'status' => 'active',
        'pool_type' => 'ask',
    ]);
    $account2 = TechAccount::factory()->create([
        'status' => 'active',
        'pool_type' => 'ask',
    ]);
    $account3 = TechAccount::factory()->create([
        'status' => 'active',
        'pool_type' => 'ask',
    ]);

    // Account1: 5 usage
    TechAccountUsage::create([
        'tech_account_id' => $account1->id,
        'date' => today(),
        'count' => 5,
    ]);

    // Account2: 2 usage (should be selected)
    TechAccountUsage::create([
        'tech_account_id' => $account2->id,
        'date' => today(),
        'count' => 2,
    ]);

    // Account3: 10 usage
    TechAccountUsage::create([
        'tech_account_id' => $account3->id,
        'date' => today(),
        'count' => 10,
    ]);

    $service = new AccountService;
    $selected = $service->getAccountForAsk();

    $this->assertEquals($account2->id, $selected->id);
});
