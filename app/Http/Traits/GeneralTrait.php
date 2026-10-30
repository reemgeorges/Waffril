<?php

namespace App\Http\Traits;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\ValidationException;
use Illuminate\Auth\AuthenticationException;
use Spatie\Permission\Exceptions\UnauthorizedException as SpatieUnauthorized;
use App\Exceptions\Otp\OtpException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;

trait GeneralTrait
{
    public function apiResponse($data = null, bool $status = true, $error = null, $statusCode = 200)
    {
        $array = [
            'data' => $data,
            'status' => $status ,
            'error' => $error,
            'statusCode' => $statusCode
        ];
        return response($array, $statusCode);
    }

    public function unAuthorizeResponse($message='Unauthorize')
    {
        return $this->apiResponse(null, 0, $message, 401);
    }

    public function notFoundResponse($more)
    {
        return $this->apiResponse(null, 0, $more, 404);
    }

    public function requiredField($message)
    {
        return $this->apiResponse(null, false, $message, 400);
    }

    public function internalServer($message)
    {
        return $this->apiResponse(null, false,$message, 500);
    }

    public function forbiddenResponse($message = "Forbidden")
    {
        return $this->apiResponse(null, false, $message, 403);
    }

    public function handleException(\Throwable $e)
    {
        if ($e instanceof ModelNotFoundException) {
            $modelName = class_basename($e->getModel());
            return $this->notFoundResponse("$modelName not found");

        } elseif ($e instanceof ValidationException) {
            $errors = $e->validator->errors();
            return response()->json([
                'data' => null,
                'status' => false,
                'error' => $errors->first(),
                'errors' => $errors->toArray(),
                'statusCode' => 422,
            ], 422);

        } elseif ($e instanceof AuthenticationException) {
            return $this->unAuthorizeResponse(__('messages.unauthenticated'));

        } elseif ($e instanceof SpatieUnauthorized) {
            return $this->forbiddenResponse(__('messages.forbidden_role_or_permission'));

        } elseif ($e instanceof AuthorizationException) {
            return $this->forbiddenResponse($e->getMessage() ?: __('messages.forbidden'));

        } elseif ($e instanceof HttpResponseException) {
            return $e->getResponse();

        } elseif ($e instanceof OtpException) {
            // Phase 3A (2026-10-25): cooldown/rate-limit/misconfigured-channel
            // errors from App\Services\Auth\OtpService, in the standard
            // envelope with the exception's own status code (429 for
            // throttling, 422/500 for configuration problems).
            return $this->apiResponse(null, false, $e->getMessage(), $e->getStatusCode());

        } elseif ($e instanceof QueryException) {
            return $this->handleQueryException($e);

        } elseif ($e instanceof ThrottleRequestsException) {
            // Phase 3C (2026-10-25) fix: Laravel's ThrottleRequestsException
            // (429) from the new `throttle:` middleware on the auth routes
            // was previously falling through to the generic else branch
            // below and coming back as a misleading 500. Deliberately
            // narrow to this one exception type rather than every
            // HttpExceptionInterface — a broader catch also intercepts
            // routing 404s (NotFoundHttpException), which ~30 pre-existing
            // tests across Phase 0/0.1/0.2 explicitly assert come back as
            // 500 via this same catch-all. Changing that is a real,
            // wide-blast-radius behavior change unrelated to what this
            // phase was asked to fix, so it is deliberately left alone.
            return $this->apiResponse(null, false, $e->getMessage() ?: 'Too many requests. Please try again later.', 429);

        } else {
            // SECURITY (Phase 0, 2026-10-25): never leak raw exception messages
            // (file paths, query fragments, stack details) to API clients in
            // production. Non-production environments keep the detailed
            // message to aid debugging.
            $message = app()->environment('production')
                ? 'Internal server error.'
                : $e->getMessage();

            return $this->apiResponse(null, false, $message, 500);
        }
    }

    protected function handleQueryException(QueryException $e)
    {
        $errorCode = (isset($e->errorInfo[1]))? $e->errorInfo[1] : 0;

        switch ($errorCode) {
            case 1062: // Duplicate entry
                return $this->requiredField(__('messages.db_duplicate_entry'));

            case 1451: // FK restricted
                return $this->requiredField(__('messages.db_fk_restricted'));

            case 1452: // FK violation
                return $this->requiredField(__('messages.db_fk_violation'));

            case 3819: // MySQL 8 CHECK constraint violated (WAFFRLI P0, 2026-10-30)
                return $this->requiredField(__('messages.db_check_violation'));

            default:
                // SECURITY (Phase 0.1, 2026-10-25): never leak raw SQL, bound
                // parameters, table/column names or driver-specific error
                // text (which can include credentials in some DSN-based
                // drivers) to API clients in production. Non-production
                // environments keep the detailed message to aid debugging,
                // matching the existing handleException() catch-all gate.
                $message = app()->environment('production')
                    ? 'A database error occurred. Please try again later.'
                    : 'Database error: ' . $e->getMessage();

                return $this->internalServer($message);
        }
    }
}
