<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Test as TestModel;
use App\Models\User;
use App\Services\Model\Category\CategoryService;
use App\Services\Model\Test\TestService;
use App\Services\Model\User\UserService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Phase 1 (2026-10-25): lifecycle semantics regression test.
 *
 * Before this phase, App\Services\Basic\BasicCrudService conflated three
 * distinct concepts:
 *   - activate()/deactivate() actually called restore()/delete() (soft
 *     delete toggling), there was no `is_active` column anywhere;
 *   - delete() called forceDelete() directly (an irreversible hard delete
 *     with no separate authorization gate).
 * It was also impossible to even exercise withTrashed()/restore()/delete()
 * against the real `users`/`tests`/`categories` tables, because none of
 * them had a `deleted_at` column despite BaseModel/BaseAuthModel
 * unconditionally `use SoftDeletes`.
 *
 * This test exercises the service layer directly (NOT via any route — per
 * this phase's instructions, no new route is registered to avoid any new
 * exposure risk) against the real sqlite `:memory:` test database, proving:
 *   - activate() sets is_active=true and never touches deleted_at;
 *   - deactivate() sets is_active=false and never touches deleted_at;
 *   - delete() soft-deletes (deleted_at set, excluded from default queries,
 *     reachable via withTrashed());
 *   - restore() clears deleted_at;
 *   - forceDelete() permanently removes the row AND is not reachable via any
 *     currently-registered route.
 */
class BasicCrudServiceLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private function idRequest(int $id): Request
    {
        return Request::create('/', 'POST', ['id' => $id]);
    }

    public function test_activate_sets_is_active_true_without_touching_deleted_at(): void
    {
        $user = User::factory()->create(['is_active' => false]);

        $service = new UserService;
        $result = $service->activate($this->idRequest($user->id));

        $this->assertTrue($result);

        $user->refresh();
        $this->assertTrue((bool) $user->is_active);
        $this->assertNull($user->deleted_at);
    }

    public function test_deactivate_sets_is_active_false_without_touching_deleted_at(): void
    {
        $category = Category::create([
            'name' => 'Electronics',
            'slug' => 'electronics',
            'image' => 'electronics.png',
            'description' => 'Electronics category',
            'is_active' => true,
        ]);

        $service = new CategoryService;
        $result = $service->deactivate($this->idRequest($category->id));

        $this->assertTrue($result);

        $category->refresh();
        $this->assertFalse((bool) $category->is_active);
        $this->assertNull($category->deleted_at);
    }

    public function test_delete_soft_deletes_record_without_touching_is_active(): void
    {
        $test = TestModel::create([
            'name' => 'Sample test',
            'description' => 'A sample test row',
            'image' => 'sample.png',
            'is_active' => true,
        ]);

        $service = new TestService;
        $result = $service->delete($this->idRequest($test->id));

        $this->assertTrue($result);

        // Excluded from default (non-trashed) queries.
        $this->assertNull(TestModel::find($test->id));

        // Still present when explicitly including trashed rows.
        $trashed = TestModel::withTrashed()->find($test->id);
        $this->assertNotNull($trashed);
        $this->assertNotNull($trashed->deleted_at);
        // is_active is untouched by a soft delete.
        $this->assertTrue((bool) $trashed->is_active);
    }

    public function test_restore_clears_deleted_at(): void
    {
        $category = Category::create([
            'name' => 'Books',
            'slug' => 'books',
            'image' => 'books.png',
            'description' => 'Books category',
        ]);
        $category->delete(); // soft delete
        $this->assertNotNull($category->refresh()->deleted_at);

        $service = new CategoryService;
        $result = $service->restore($this->idRequest($category->id));

        $this->assertTrue($result);

        $category->refresh();
        $this->assertNull($category->deleted_at);
        $this->assertNotNull(Category::find($category->id));
    }

    public function test_force_delete_permanently_removes_the_row(): void
    {
        $test = TestModel::create([
            'name' => 'Disposable test',
            'description' => 'Row meant to be permanently removed',
            'image' => 'disposable.png',
        ]);
        $id = $test->id;

        $service = new TestService;
        $result = $service->forceDelete($this->idRequest($id));

        $this->assertTrue($result);

        // Gone even from withTrashed() — a real permanent delete, not a
        // soft delete.
        $this->assertNull(TestModel::withTrashed()->find($id));
        $this->assertDatabaseMissing('tests', ['id' => $id]);
    }

    public function test_force_delete_is_not_reachable_via_any_currently_registered_route(): void
    {
        foreach (Route::getRoutes() as $route) {
            $action = $route->getActionName();

            $this->assertStringNotContainsStringIgnoringCase(
                '@forceDelete',
                (string) $action,
                "Route [{$route->uri()}] unexpectedly wires to a forceDelete() controller action; ".
                'forceDelete must remain unrouted until an authorization gate exists.'
            );
        }
    }

    public function test_restore_is_not_reachable_via_any_currently_registered_route(): void
    {
        // restore() was added as a new, separate concept in this phase; it
        // must not have been accidentally wired to a live route either,
        // since no route file in this phase registers one.
        foreach (Route::getRoutes() as $route) {
            $action = $route->getActionName();

            $this->assertStringNotContainsStringIgnoringCase(
                '@restore',
                (string) $action,
                "Route [{$route->uri()}] unexpectedly wires to a restore() controller action."
            );
        }
    }
}
