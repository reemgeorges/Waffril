<?php

namespace App\Http\Traits;

use App\Exceptions\BusinessRuleException;
use App\Exceptions\Otp\OtpException;
use App\Http\Middleware\SetRequestLocale;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Exceptions\UnauthorizedException as SpatieUnauthorized;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

trait GeneralTrait
{
    public function apiResponse($data = null, bool $status = true, $error = null, $statusCode = 200)
    {
        $array = [
            'data' => $data,
            'status' => $status,
            'error' => $error,
            'statusCode' => $statusCode,
        ];

        return response($array, $statusCode);
    }

    public function unAuthorizeResponse($message = 'Unauthorize')
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
        return $this->apiResponse(null, false, $message, 500);
    }

    public function forbiddenResponse($message = 'Forbidden')
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

        } elseif ($e instanceof BusinessRuleException) {
            // WAFFRLI (2026-10-31): domain-rule refusal from a reusable
            // service; its message is written to be client-safe.
            return $this->apiResponse(null, false, $e->getMessage(), $e->getStatus());

        } elseif ($e instanceof QueryException) {
            return $this->handleQueryException($e);

        } elseif ($e instanceof ThrottleRequestsException) {
            // Phase 3C (2026-10-25): Laravel's ThrottleRequestsException (429)
            // from the `throttle:` middleware on the auth routes.
            return $this->apiResponse(null, false, $e->getMessage() ?: 'Too many requests. Please try again later.', 429);

        } elseif ($e instanceof HttpExceptionInterface) {
            // WAFFRLI (2026-11-05): HTTP exceptions keep their own status in
            // the standard envelope. Previously they fell through to the
            // catch-all below, so an UNKNOWN API ROUTE (routing
            // NotFoundHttpException) answered 500 instead of 404. Routing
            // messages are replaced by a localized one (no path echo);
            // messages given explicitly to abort() are kept.
            $status = $e->getStatusCode();
            if (request()) {
                // Unmatched routes never run the locale middleware.
                SetRequestLocale::applyFrom(request());
            }
            $routing = $e->getMessage() === '' || str_starts_with($e->getMessage(), 'The route ') || str_contains($e->getMessage(), 'method is not supported');
            $message = match (true) {
                $routing && $e instanceof NotFoundHttpException => __('messages.http.not_found'),
                $routing && $e instanceof MethodNotAllowedHttpException => __('messages.http.method_not_allowed'),
                $e->getMessage() !== '' => $e->getMessage(),
                default => __('messages.http.error'),
            };

            return response()->json(['data' => null, 'status' => false, 'error' => $message, 'statusCode' => $status], $status, $e->getHeaders());

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
        $errorCode = (isset($e->errorInfo[1])) ? $e->errorInfo[1] : 0;

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
                    : 'Database error: '.$e->getMessage();

                return $this->internalServer($message);
        }
    }
}
