<?php



use App\Http\Controllers\UploadController;
use Illuminate\Support\Facades\Route;

// SECURITY (Phase 0.2, 2026-10-25): these two routes are flat, standalone
// public routes — unlike the Phase 0 / Phase 0.1 defects, there is no
// surrounding guarded group for them to have "escaped"; they were registered
// with no authentication at all. Audit of UploadController@single/@multiple
// confirmed: the {folder} parameter IS already safely constrained (route
// regex '[A-Za-z0-9_-]+' here, plus a redundant controller-level preg_match
// of the same pattern) so there is no path-traversal defect; a MIME-type
// allowlist (images/video/pdf/doc, verified via getMimeType(), not the
// client-supplied Content-Type) and a 50MB max file size are also already
// enforced. The concrete, unresolved defect is authentication: these routes
// write to the public disk (storage/app/public, web-exposed once
// `storage:link` is run) with zero auth guard and zero rate/ownership
// accounting, and no working `admin`/`user` guard exists yet in
// config/auth.php. Disabling rather than inventing an ad hoc guard, per the
// same pattern as Phase 0/0.1.
// TODO(phase-2): re-enable once a real auth guard + per-route authorization
// exists; the underlying UploadController validation (folder allowlist regex,
// MIME allowlist, 50MB size cap) does not need to change to re-enable this.

// Route::post('upload/{folder}/single', [UploadController::class, 'single'])
//     ->where('folder', '[A-Za-z0-9_-]+');

// Route::post('upload/{folder}/multiple', [UploadController::class, 'multiple'])
//     ->where('folder', '[A-Za-z0-9_-]+');










