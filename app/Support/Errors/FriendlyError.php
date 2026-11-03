<?php

namespace App\Support\Errors;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * The words a member of staff sees when an action fails. Our own errors (classes in the App namespace)
 * carry messages written for staff and are shown as they are. Anything else — database errors, missing
 * records, framework errors — is replaced by a plain sentence, and the technical detail goes to the log.
 */
final class FriendlyError
{
    public static function message(Throwable $e): string
    {
        $status = $e instanceof HttpExceptionInterface ? $e->getStatusCode() : null;

        return match (true) {
            $e instanceof ModelNotFoundException, $status === 404 => __('errors.action.gone'),
            $e instanceof AuthorizationException, in_array($status, [400, 401, 403], true) => __('errors.action.forbidden'),
            self::isWrittenForStaff($e) => $e->getMessage(),
            default => self::logged($e),
        };
    }

    public static function isWrittenForStaff(Throwable $e): bool
    {
        return str_starts_with($e::class, 'App\\') && trim($e->getMessage()) !== '';
    }

    private static function logged(Throwable $e): string
    {
        report($e);

        return __('errors.action.failed');
    }
}
