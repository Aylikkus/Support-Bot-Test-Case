<?php

use App\Models\Operator;
use App\Models\SupportRequest;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;

it('redirects guests to the login form', function () {
    $request = SupportRequest::create(['user_id' => 1]);

    $this->get(route('home'))->assertRedirect(route('login'));
    $this->get(route('requests.show', $request))->assertRedirect(route('login'));
    $this->get(route('requests.stats'))->assertRedirect(route('login'));
    $this->post(route('requests.messages.store', $request), ['text' => 'x'])->assertRedirect(route('login'));
    $this->post(route('requests.close', $request))->assertRedirect(route('login'));
});

it('shows the login form to guests', function () {
    $this->get(route('login'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Auth/Login'));
});

it('logs an operator in with valid credentials', function () {
    Operator::factory()->create(['name' => 'anna', 'password' => 'secret']);

    $this->post(route('login.store'), ['name' => 'anna', 'password' => 'secret'])
        ->assertRedirect(route('home'));

    $this->assertAuthenticated();
    $this->get(route('home'))->assertOk();
});

it('rejects invalid credentials', function () {
    Operator::factory()->create(['name' => 'anna', 'password' => 'secret']);

    $this->post(route('login.store'), ['name' => 'anna', 'password' => 'wrong'])
        ->assertSessionHasErrors('name');

    $this->assertGuest();
});

it('logs out', function () {
    $this->actingAs(Operator::factory()->create())
        ->post(route('logout'))
        ->assertRedirect(route('login'));

    $this->assertGuest();
});

it('redirects logged-in operators away from the login form', function () {
    $this->actingAs(Operator::factory()->create())
        ->get(route('login'))
        ->assertRedirect(route('home'));
});

it('seeds admin:admin once and keeps a changed password', function () {
    $this->seed(DatabaseSeeder::class);

    $admin = Operator::where('name', 'admin')->firstOrFail();
    expect(Hash::check('admin', $admin->password))->toBeTrue()
        ->and($admin->password)->not->toBe('admin');

    $admin->update(['password' => 'changed']);
    $this->seed(DatabaseSeeder::class);

    expect(Operator::count())->toBe(1)
        ->and(Hash::check('changed', $admin->refresh()->password))->toBeTrue();
});
