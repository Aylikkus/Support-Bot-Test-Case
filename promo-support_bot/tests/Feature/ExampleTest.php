<?php

use App\Models\Operator;

test('returns a successful response', function () {
    $response = $this->actingAs(Operator::factory()->create())->get(route('home'));

    $response->assertOk();
});
