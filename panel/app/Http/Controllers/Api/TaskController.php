<?php

namespace App\Http\Controllers\Api;

use App\Models\Task;
use Illuminate\Http\Request;

class TaskController extends ApiController
{
    public function index(Request $request)
    {
        $query = Task::query()->with('user:id,name')->latest('id');
        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }
        if ($type = $request->query('type')) {
            $query->where('type', $type);
        }

        return $this->page($query, $request, fn (Task $task) => [
            'id' => $task->id,
            'type' => $task->type,
            'title' => $task->title,
            'status' => $task->status,
            'exit_code' => $task->exit_code,
            'user' => $task->user?->name,
            'client_id' => $task->client_id,
            'started_at' => $task->started_at?->toIso8601String(),
            'finished_at' => $task->finished_at?->toIso8601String(),
            'created_at' => $task->created_at?->toIso8601String(),
        ]);
    }

    public function show(Request $request, Task $task)
    {
        $offset = max(0, (int) $request->query('offset', 0));
        $output = $task->output($offset);

        return $this->data([
            'id' => $task->id,
            'type' => $task->type,
            'title' => $task->title,
            'status' => $task->status,
            'exit_code' => $task->exit_code,
            'finished' => $task->isFinished(),
            'output' => mb_convert_encoding($output, 'UTF-8', 'UTF-8'),
            'offset' => $offset + strlen($output),
            'meta' => $task->meta,
            'started_at' => $task->started_at?->toIso8601String(),
            'finished_at' => $task->finished_at?->toIso8601String(),
        ]);
    }
}
