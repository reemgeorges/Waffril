<?php

namespace Tests\Feature;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Phase 0.2 security fix regression test.
 *
 * routes/api/v1/general.php used to register `upload/{folder}/single` and
 * `upload/{folder}/multiple` as flat, standalone public routes (prefixed
 * `api/v1` with only the base `api` middleware group — see bootstrap/app.php)
 * with no authentication at all, writing to the public disk. Unlike the
 * Phase 0 / Phase 0.1 defects, there was no surrounding guarded group for
 * these to have escaped from; they were simply never gated.
 *
 * Audit of app/Http/Controllers/UploadController.php confirmed the
 * {folder} parameter was already safely constrained (route regex
 * '[A-Za-z0-9_-]+' plus a redundant controller-level preg_match of the same
 * pattern — no path-traversal defect), a MIME-type allowlist was already
 * enforced via getMimeType() (not the client-supplied Content-Type), and a
 * 50MB max file size was already enforced. The concrete, unresolved defect
 * was authentication: the routes had none, and no working admin/user guard
 * exists yet in config/auth.php. The routes are therefore disabled (commented
 * out in routes/api/v1/general.php) rather than wired to a non-existent guard
 * or a newly invented one.
 *
 * These tests prove:
 *  - the previously-public upload endpoints are no longer reachable at all
 *    (including via a path-traversal folder value, which already could not
 *    have escaped the intended directory even before this fix, since it is
 *    blocked by regex at the route level before the request ever reaches
 *    the controller);
 *  - no file is ever written to disk as a result of hitting these URIs,
 *    using Storage::fake() so the test never touches real storage/app.
 */
class UploadRoutesDisabledTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Never touch the real public disk; this fakes storage_path for the
        // duration of each test only.
        Storage::fake('public');
    }

    public static function disabledUploadRoutesProvider(): array
    {
        return [
            'single upload, normal folder' => ['/api/v1/upload/avatars/single'],
            'multiple upload, normal folder' => ['/api/v1/upload/avatars/multiple'],
        ];
    }

    /**
     * @dataProvider disabledUploadRoutesProvider
     */
    public function test_anonymous_upload_is_no_longer_reachable(string $uri): void
    {
        $response = $this->postJson($uri, []);

        // The route has been removed entirely, so Laravel's router throws a
        // routing NotFoundHttpException, which the app's pre-existing generic
        // exception handler converts into a 500 JSON envelope (same
        // 404-vs-500 pre-existing behavior documented in
        // PublicCrudRoutesDisabledTest / AdminTestCrudRouteDisabledTest).
        // What matters here is that the request never reaches the
        // controller/service layer and never returns a 2xx, and that no file
        // is written anywhere.
        $response->assertStatus(500);
        $response->assertJsonStructure(['data', 'status', 'error', 'statusCode']);
        $response->assertJsonPath('status', false);
        $response->assertJsonPath('data', null);

        Storage::disk('public')->assertDirectoryEmpty('/');
    }

    public static function pathTraversalFolderProvider(): array
    {
        return [
            'dot-dot-slash single' => ['/api/v1/upload/..%2F..%2Fetc/single'],
            'dot-dot-slash multiple' => ['/api/v1/upload/..%2F..%2Fetc/multiple'],
            'absolute-path-like single' => ['/api/v1/upload/etc-passwd/single'],
        ];
    }

    /**
     * @dataProvider pathTraversalFolderProvider
     */
    public function test_path_traversal_folder_value_cannot_reach_the_disabled_route(string $uri): void
    {
        $file = UploadedFile::fake()->image('evil.jpg');

        $response = $this->post($uri, ['file' => $file]);

        // Whether rejected by the (now-absent) route regex or by Laravel's
        // router finding no matching route at all, the outcome is the same:
        // never a 2xx, and nothing written to disk.
        $this->assertFalse($response->isSuccessful());
        $this->assertFalse($response->isRedirection());

        Storage::disk('public')->assertDirectoryEmpty('/');
    }

    public function test_malformed_upload_request_is_not_reachable_because_route_is_disabled(): void
    {
        // Oversized / wrong-extension payloads would, prior to this fix,
        // have been rejected by the route-reachable controller's own
        // validation (MIME allowlist, 50MB max). Since the route itself is
        // now disabled, there is no reachable validation code path left to
        // exercise — this test only needs to confirm the request still never
        // succeeds and never writes anything, regardless of payload shape.
        $oversized = UploadedFile::fake()->create('payload.exe', 60000, 'application/x-msdownload');

        $response = $this->post('/api/v1/upload/avatars/single', ['file' => $oversized]);

        $this->assertFalse($response->isSuccessful());
        Storage::disk('public')->assertDirectoryEmpty('/');
    }

    public function test_admin_ping_route_is_unaffected_by_this_fix(): void
    {
        // Sanity check: confirm this fix did not accidentally disable an
        // unrelated, intentionally-public route in the same file set.
        $response = $this->getJson('/api/v1/admin/ping');

        $response->assertStatus(200);
        $response->assertJson(['status' => 'ok']);
    }
}
