<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Http\Traits\GeneralTrait;
use App\Models\User;
use Illuminate\Http\Request;

/** WAFFRLI customer API (2026-11-03) — shared base: envelope + exception mapping. */
abstract class CustomerController extends Controller
{
    use GeneralTrait;

    protected function customer(Request $request): User
    {
        return $request->user('user');
    }

    /** Runs $callback and wraps its result in the standard envelope. */
    protected function respond(callable $callback)
    {
        try {
            return $this->apiResponse($callback());
        } catch (\Exception $e) {
            return $this->handleException($e);
        }
    }

    protected function paginated($paginator, string $key, callable $map): array
    {
        return [
            $key => $paginator->getCollection()->map($map)->values(),
            'pagination' => ['current_page' => $paginator->currentPage(), 'per_page' => $paginator->perPage(), 'total' => $paginator->total(), 'last_page' => $paginator->lastPage()],
        ];
    }

    protected function perPage(Request $request, int $default = 20): int
    {
        return min(max((int) $request->query('per_page', $default), 1), 50);
    }
}
