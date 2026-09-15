<?php

namespace App\Http\Controllers;

use App\Models\Task;
use Illuminate\Http\Request;

class TaskController extends Controller
{
    public function index()
    {
        $tasks = Task::query()->with('user:id,name')->latest('id')->limit(50)->get()->map(fn (Task $t) => [
            'id' => $t->id,
            'title' => $t->title,
            'status' => $t->status,
            'user' => $t->user?->name,
            'created' => $t->created_at->diffForHumans(),
            'duration' => $t->started_at && $t->finished_at ? $t->started_at->diffInSeconds($t->finished_at).'s' : null,
        ]);

        return response()->json([
            'data' => $tasks,
            'running' => Task::query()->whereIn('status', ['queued', 'running'])->count(),
        ]);
    }

    /** Incremental log polling: ?offset=<bytes already received>. */
    public function show(Request $request, Task $task)
    {
        $offset = max(0, (int) $request->query('offset', 0));
        $chunk = $task->output($offset);

        return response()->json([
            'id' => $task->id,
            'title' => $task->title,
            'status' => $task->status,
            'exit_code' => $task->exit_code,
            'output' => mb_convert_encoding($chunk, 'UTF-8', 'UTF-8'),
            'offset' => $offset + strlen($chunk),
            'finished' => $task->isFinished(),
        ]);
    }
}
