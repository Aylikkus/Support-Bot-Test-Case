<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'name' => config('app.name'),
            // Where the browser connects to Reverb (differs from the server-side REVERB_HOST inside Docker).
            'reverb' => [
                'key' => config('broadcasting.connections.reverb.key'),
                'host' => config('services.reverb_public.host'),
                'port' => config('services.reverb_public.port'),
                'scheme' => config('services.reverb_public.scheme'),
            ],
            'auth' => [
                'operator' => $request->user()?->only('operator_id', 'name'),
            ],
        ];
    }
}
