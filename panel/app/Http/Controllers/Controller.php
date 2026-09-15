<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Task;
use App\Services\ShellResult;
use Illuminate\Http\JsonResponse;

abstract class Controller
{
    protected function ok(string $message = 'Done', array $data = []): JsonResponse
    {
        return response()->json(['ok' => true, 'message' => $message] + $data);
    }

    protected function fail(string $message, int $status = 422, array $data = []): JsonResponse
    {
        return response()->json(['ok' => false, 'message' => $message] + $data, $status);
    }

    /** Convert a shell result into a JSON response and record activity on success. */
    protected function result(ShellResult $result, string $success, ?string $category = null, ?string $details = null): JsonResponse
    {
        if ($result->failed()) {
            return $this->fail($result->message() ?: 'Command failed (exit '.$result->exitCode.')');
        }
        if ($category) {
            ActivityLog::record($category, $success, $details);
        }

        return $this->ok($success, ['output' => $result->output]);
    }

    protected function task(Task $task, string $message = 'Task started'): JsonResponse
    {
        return $this->ok($message, ['task' => ['id' => $task->id, 'title' => $task->title, 'status' => $task->status]]);
    }

    protected function audit(string $category, string $action, ?string $details = null): void
    {
        ActivityLog::record($category, $action, $details);
    }
}
