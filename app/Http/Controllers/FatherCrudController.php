<?php

namespace App\Http\Controllers;

use App\Http\Traits\GeneralTrait;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

abstract class FatherCrudController extends Controller
{
    use GeneralTrait;
    protected $key;
    protected $service;

    protected $createRequest;
    protected $updateRequest;

    public function __construct()
    {

        $this->setVariables();

    }

    protected abstract function setVariables(): void;

    public function allPaginated(Request $request)
    {

        try {

            return $this->apiResponse(
                $this->service::allPaginated($request)
            );

        } catch (\Exception $e) {
            return $this->handleException($e);
        }

    }

    public function pureAll(Request $request)
    {
        try {

            $data[Str::plural($this->key)] = $this->service::pureAll($request);
            return $this->apiResponse(
                $data
            );

        } catch (\Exception $e) {
            return $this->handleException($e);
        }
    }

    public function all(Request $request)
    {

        try {

            $data[Str::plural($this->key)] = $this->service::all($request);
            return $this->apiResponse(
                $data
            );

        } catch (\Exception $e) {
            return $this->handleException($e);
        }

    }

    public function show(Request $request)
    {

        try {

            $data[$this->key] = $this->service::show($request);
            return $this->apiResponse($data);

        } catch (\Exception $e) {
            return $this->handleException($e);
        }

    }


    public function store()
    {

        try {

            $request = app($this->createRequest);
            $data[$this->key] = $this->service::create($request);

            return $this->apiResponse($data);

        } catch (\Exception $e) {
            return $this->handleException($e);
        }

    }

    public function update()
    {

        try {

            $request = app($this->updateRequest);
            $data[$this->key] = $this->service::update($request);

            return $this->apiResponse($data);

        } catch (\Exception $e) {
            return $this->handleException($e);
        }

    }

    public function activate(Request $request)
    {

        try {

            $data["message"] = ($this->service::activate($request)) ? "activation done"
                : "there was an error with activate data";
            return $this->apiResponse($data);

        } catch (\Exception $e) {
            return $this->handleException($e);
        }

    }

    public function deactivate(Request $request)
    {

        try {

            $data["message"] = ($this->service::deactivate($request)) ? "deactivation done"
                : "there was an error with deactivate data";
            return $this->apiResponse($data);

        } catch (\Exception $e) {
            return $this->handleException($e);
        }

    }

    /**
     * Soft-delete (sets `deleted_at`), independent of `is_active`.
     */
    public function destroy(Request $request)
    {
        try {

            $data["message"] = ($this->service::delete($request)) ? "deletion done"
                : "there was an error with delete data";
            return $this->apiResponse($data);

        } catch (\Exception $e) {
            return $this->handleException($e);
        }
    }

    /**
     * Restore a soft-deleted record (clears `deleted_at`), independent of
     * `is_active`.
     *
     * Phase 1 (2026-10-25): new method, added to keep the controller layer
     * in sync with BasicCrudService::restore(). NOT wired to any route in
     * this phase (no permission system exists yet) — available but
     * unrouted, consistent with destroy()/forceDelete() in this controller.
     */
    public function restore(Request $request)
    {
        try {

            $data["message"] = ($this->service::restore($request)) ? "restore done"
                : "there was an error with restore data";
            return $this->apiResponse($data);

        } catch (\Exception $e) {
            return $this->handleException($e);
        }
    }

    /**
     * Permanently delete a record. SEPARATE, explicitly-named method (not
     * aliased to destroy()/delete()).
     *
     * Phase 1 (2026-10-25): new method, added to keep the controller layer
     * in sync with BasicCrudService::forceDelete(). NOT wired to any route
     * in this phase — no permission/authorization system exists yet to gate
     * a permanent-delete endpoint. Available but unrouted, consistent with
     * destroy() already being unrouted today.
     */
    public function forceDelete(Request $request)
    {
        try {

            $data["message"] = ($this->service::forceDelete($request)) ? "permanent deletion done"
                : "there was an error with permanent delete data";
            return $this->apiResponse($data);

        } catch (\Exception $e) {
            return $this->handleException($e);
        }
    }

    public function exportExcel(Request $request)
    {
        try {

            $data["file"] = $this->service::exportExcel($request);
            return $this->apiResponse($data);

        } catch (\Exception $e) {
            return $this->handleException($e);
        }
    }

    public function importExcel(Request $request)
    {
        try {

            $data["message"] = ($this->service::importExcel($request)) ? "import done"
                : "there was an error with import data";
            return $this->apiResponse($data);

        } catch (\Exception $e) {
            return $this->handleException($e);
        }
    }

    public function forAuth(Request $request)
    {
        try {
            $data[Str::plural($this->key)] = $this->service::forAuth($request);
            return $this->apiResponse($data);
        } catch (\Exception $e) {
            return $this->handleException($e);
        }
    }


}
