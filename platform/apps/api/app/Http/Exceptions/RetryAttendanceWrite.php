<?php

namespace App\Http\Exceptions;

use Illuminate\Database\DeadlockException;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** A deadlock aborts the tenant transaction before this expected conflict is sent to the client. */
final class RetryAttendanceWrite extends HttpException
{
    public function __construct(DeadlockException $previous)
    {
        parent::__construct(409, 'Някой друг отбелязва или променя графика в момента. Опитайте отново.', $previous);
    }

    public function render(): JsonResponse
    {
        return new JsonResponse(['message' => $this->getMessage(), 'retry' => true], 409);
    }
}
