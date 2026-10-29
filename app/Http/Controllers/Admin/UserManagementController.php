<?php

namespace App\Http\Controllers\Admin;

use App\Http\Traits\GeneralTrait;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Phase 3C (2026-10-25) — "wire relevant endpoint policies without
 * default grants."
 *
 * app/Policies/UserPolicy.php has existed since Phase 2B but was never
 * actually checked anywhere — no route called Gate::authorize() or
 * $this->authorize() against it, so it was dead weight. This is the first
 * real endpoint that enforces it. No admin is granted the "manage-user"
 * role by anything in this codebase (AuthModulesPermissionSeeder only
 * ever creates the role itself, never assigns it — see that seeder's doc
 * comment) — every one of these actions 403s for an Admin until a human
 * deliberately assigns that role to them, outside of any
 * generator/seeder/migration.
 *
 * Deliberately minimal: list/activate/deactivate only (the already-tested,
 * reversible lifecycle actions from Phase 1's BasicCrudService). create/
 * update/delete/restore/forceDelete are NOT exposed here — out of this
 * phase's scope, and forceDelete is denied unconditionally by the policy
 * regardless.
 */
class UserManagementController
{
    use GeneralTrait;

    public function all(Request $request)
    {
        try {
            Gate::authorize('viewAny', User::class);

            return $this->apiResponse(app('UserService')->allPaginated($request));
        } catch (\Exception $e) {
            return $this->handleException($e);
        }
    }

    public function activate(Request $request)
    {
        try {
            $target = User::findOrFail($request->input('id'));
            Gate::authorize('update', $target);

            app('UserService')->activate($request);

            return $this->apiResponse(['message' => __('messages.activated')]);
        } catch (\Exception $e) {
            return $this->handleException($e);
        }
    }

    public function deactivate(Request $request)
    {
        try {
            $target = User::findOrFail($request->input('id'));
            Gate::authorize('update', $target);

            app('UserService')->deactivate($request);

            return $this->apiResponse(['message' => __('messages.deactivated')]);
        } catch (\Exception $e) {
            return $this->handleException($e);
        }
    }
}
